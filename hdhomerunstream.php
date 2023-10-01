<?php
# live_path is configured as a ramdisk
$live_path = "/var/www/html/live";
# todo: why do I need two path's?
$channel_path = "/var/www/html/channel";
# todo: select free tuner instead of hard coding
$tuner="tuner3";

$HDHRID = shell_exec("/usr/bin/sudo /usr/bin/hdhomerun_config discover | cut -d ' ' -f3");
$HDHRID = str_replace("\n", '', $HDHRID);
$response = shell_exec("/usr/bin/sudo /usr/bin/mkdir -p ".$channel_path.";");
$response = shell_exec("/usr/bin/sudo /usr/bin/chown apache:apache ".$channel_path.";");
$relative_path = shell_exec("/usr/bin/sudo /usr/bin/realpath --relative-to=$channel_path $live_path");
$relative_path = str_replace("\n", '', $relative_path);
$hostname="localhost";

$settings = array(
                "high1080" =>   array("height" => 1080, "width" => 1920, "vbitrate" => 6000, "abitrate" => 128),
                "normal1080" => array("height" => 1080, "width" => 1920, "vbitrate" => 4000, "abitrate" => 128),
                "low1080" =>    array("height" => 1080, "width" => 1920, "vbitrate" => 2000, "abitrate" => 128),
                "high720" =>    array("height" =>  720, "width" => 1280, "vbitrate" => 5000, "abitrate" => 128),
                "normal720" =>  array("height" =>  720, "width" => 1280, "vbitrate" => 2000, "abitrate" => 128),
                "low720" =>     array("height" =>  720, "width" => 1280, "vbitrate" => 1000, "abitrate" =>  64),
                "high480" =>    array("height" =>  480, "width" =>  854, "vbitrate" => 1500, "abitrate" => 128),
                "normal480" =>  array("height" =>  480, "width" =>  854, "vbitrate" =>  800, "abitrate" =>  64),
                "low480" =>     array("height" =>  480, "width" =>  854, "vbitrate" =>  200, "abitrate" =>  48),
);
$keys = array_keys($settings);

function http_request($method, $endpoint, $rest) {

    global $hostname;
    $params = array("http" => array(
        "method" => $method,
        "content" => $rest
    ));

    $context = stream_context_create($params);

    $fp = @fopen("http://$hostname:6544/$endpoint", "rb", false, $context);

    if (!$fp) {
        echo "fopen() failed\n";
        throw new Exception("fopen() error: $endpoint, $php_errormsg");
    }

    $xml_response = @stream_get_contents($fp);

    if ($xml_response === false) {
        echo("xml_response failed");
        throw new Exception("Read Error: $endpoint, $php_errormsg");
    }

    return $xml_response;
}

function parse_xml_response($xml_response, $pattern) {
    $value = "none";
    $xml = new XmlReader();
    $xml->xml($xml_response);

    while($xml->read()) {
        if ($xml->nodeType == XMLReader::ELEMENT && $xml->name == $pattern) {
            $value = new SimpleXMLElement($xml->readOuterXML());
            break;
        }
    }
    return $value;
}

function get_videomultiplexlist() {
    #http://$hostname:6544/Channel/GetVideoMultiplexList?SourceID=1&StartIndex=0&Count=100

    $xml_response = http_request("GET" ,"Channel/GetVideoMultiplexList",
                                 "SourceID=1&StartIndex=0&Count=100");
    $xml = simplexml_load_string($xml_response, "SimpleXMLElement", LIBXML_NOCDATA);
    $json = json_encode($xml);

    return json_decode($json,TRUE);
}

function get_channel_info_list ($VideoMultiplexList) {
   #http://$hostname:6544/Channel/GetChannelInfoList?SourceID=0&StartIndex=0&Count=100&OnlyVisible=false&Details=true

    $xml_response = http_request("GET" ,"Channel/GetChannelInfoList",
                             "SourceID=0&StartIndex=0&Count=100&OnlyVisible=false&Details=true");

    $xml = simplexml_load_string($xml_response, "SimpleXMLElement", LIBXML_NOCDATA);
    $json = json_encode($xml);
    $array = json_decode($json,TRUE);
    $channels = array();

    foreach ($array['ChannelInfos'] as $ChannelInfo => $info_array) {
        foreach ($info_array as $info_array_key => $info_array_value) {
            $new_info_array = array();
            foreach ($info_array_value as $key => $value) {
                if ($key == "CallSign")
                {
                    // remove spaces and forward slash from key
                    $channel_key = str_replace([' ', '/'],'', $value);
                }
                if ($key == "ServiceId")
                {
                    $new_info_array += ['ServiceId' => $value];
                }
                if ($key == "MplexId")
                {
                    foreach ($VideoMultiplexList['VideoMultiplexes'] as $VideoMultiplex => $multiplex_array) {
                        foreach ($multiplex_array as $multiplex_key => $multiplex_value) {
                            if ($multiplex_value['MplexId'] == $value)
                            {
                                $new_info_array += ['Frequency' => $multiplex_value['Frequency']/1000000];
                                break;
                            }
                        }
                    }
                }
            }
            $channel = [$channel_key => $new_info_array];
            $channels += $channel;
            unset($channel);
        }
    }

    return $channels;
}

$VideoMultiplexList = get_videomultiplexlist();
$channels = get_channel_info_list($VideoMultiplexList);

if (isset($_REQUEST["channel"]))
{
    if (!array_key_exists($_REQUEST["channel"], $channels))
    {
        throw new InvalidArgumentException('Invalid channel');
    }
}

if (isset($_REQUEST["quality"]))
{
    if (!array_key_exists($_REQUEST["quality"], $settings))
    {
        throw new InvalidArgumentException('Invalid quality');
    }
}

$select_box = "<form action=\"hdhomerunstream.php\" method=\"GET\">";
$select_box .= "<label for=\"quality\">Quality: </label><select name=\"quality\">";
foreach ($settings as $setting => $settingset)
{
    $select_box .= "<option value=\"".$setting."\"".((strpos($setting, "high720") !== false)?" selected=\"selected\"":"").
                           ">".preg_replace('/[0-9]+/', '', ucfirst($setting))." Quality ".$settingset["height"]."p".
                           "</option>\n";
}
$select_box .= "</form></select>";
$select_box .= "<form action=\"hdhomerunstream.php\" method=\"GET\">";
$select_box .= "<label for=\"channel\">Channel: </label>";
$select_box .= "<select name=\"channel\">";
foreach($channels as $key => $value) {
    // try to recover original channel name: keep abbreviations and separate channel number
    $regex = '/((?<=[a-z])(?=[A-Z])|(?=[A-Z][a-z]))/';
    $string = preg_replace( $regex, ' $1', $key );
    preg_match('/[0-9]+/', $string, $number);
    if (isset($number[0]) && is_numeric($number[0]))
    {
       $select_box .= "<option value=\"$key\">".preg_replace('/[0-9]+/', '', ucfirst($string))." ".$number[0]."</option>";
    }
    else {
       $select_box .= "<option value=\"$key\">".preg_replace('/[0-9]+/', '', ucfirst($string))."</option>";
    }
}
$select_box .= "</select><input type=\"submit\" name=\"do\" value=\"Watch TV\"></form>";

if (isset($_REQUEST['action']) && $_REQUEST["action"] == "delete")
{
    // Shut down all screen sessions
    $response = shell_exec("/usr/bin/sudo /usr/bin/screen -ls ".$_REQUEST['channel']."_encode  | /usr/bin/grep -E '\s+[0-9]+.' | /usr/bin/awk '{print $1}' - | while read s; do /usr/bin/sudo /usr/bin/screen -XS \$s quit; done");
    // delete live files
    array_map('unlink', glob($live_path."/".$_REQUEST['channel']."/*.vtt"));
    array_map('unlink', glob($channel_path."/".$_REQUEST['channel']."/*.txt"));
    array_map('unlink', glob($channel_path."/".$_REQUEST['channel']."/*.log"));
    array_map('unlink', glob($channel_path."/".$_REQUEST['channel']."/*.sh"));
    array_map('unlink', glob($live_path."/".$_REQUEST['channel']."/*.m4s"));
    array_map('unlink', glob($live_path."/".$_REQUEST['channel']."/*.m3u8"));
    rmdir($channel_path."/".$_REQUEST['channel']."/");
    if (is_dir($channel_path."/../live/".$_REQUEST['channel']."/"))
    {
        rmdir($channel_path."/../live/".$_REQUEST['channel']."/");
    }
    echo "<html><head><title>Stopped Live Stream</title></head><body><h2>Stopped Live Stream</h2>".$select_box."</body></html>";
}
else if (isset($_REQUEST['action']) && $_REQUEST["action"] == "status")
{
    $channel = $_REQUEST['channel'];
    $status = array();
    if (file_exists($channel_path."/".$channel."/status.txt"))
    {
        $status["status"] = file($channel_path."/".$channel."/status.txt");
    }
    if (file_exists($live_path."/".$channel."/livestream.m3u8"))
    {
        $status["available"] = 100;
    }

    echo json_encode($status);
}
else if (isset($_REQUEST["do"]))
{
    $channel = $_REQUEST['channel'];
    # todo: if possible connect to a running screen encoding
    # todo: make filename variable to allow live streams of parallel channels livestream.m3u8
    # todo: take width and height into account
    # Write encode script (just for cleanup, if no encode necessary)
    if (!file_exists($channel_path."/".$channel."/"))
    {
        mkdir($channel_path."/".$channel."/");
    }
    $fp = fopen($channel_path."/".$channel."/encode.sh", "w");
    fwrite($fp, "cd ".$channel_path."/".$channel."/\n");
    fwrite($fp, "/usr/bin/sudo -uapache /usr/bin/hdhomerun_config ".$HDHRID." set /".$tuner."/channel auto:".$channels[$_REQUEST['channel']]['Frequency'].";\n");
    fwrite($fp, "/usr/bin/sudo -uapache /usr/bin/hdhomerun_config ".$HDHRID." set /".$tuner."/program ".$channels[$_REQUEST['channel']]['ServiceId'].";\n");
    fwrite($fp, "/usr/bin/sudo -uapache /usr/bin/hdhomerun_config ".$HDHRID." save /".$tuner." - | /usr/bin/sudo -uapache /usr/bin/ffmpeg -txt_format text -txt_page 888 -i - -y -c copy -map 0:s:0 -frames:s 1 -f null - -v 0 -hide_banner;\n");
    fwrite($fp, "subtitles=`echo $?`;\n");
    fwrite($fp, "if [ \"\$subtitles\" -eq \"0\" ]; then\n");
    // subtitles present
    fwrite($fp, "/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode start >> ".$channel_path."/".$channel."/status.txt'; /usr/bin/sudo -uapache /usr/bin/mkdir -p ".$channel_path."; /usr/bin/sudo -uapache /usr/bin/mkdir -p ".$channel_path."/".$channel."; /usr/bin/sudo -uapache /usr/bin/mkdir -p ".$live_path."/".$channel."; cd ".$channel_path."/; /usr/bin/sudo -uapache /usr/bin/hdhomerun_config ".$HDHRID." save /".$tuner." - | /usr/bin/sudo -uapache /usr/bin/ffmpeg -fix_sub_duration -hwaccel vaapi -vaapi_device /dev/dri/renderD128 -hwaccel_output_format vaapi -txt_format text -txt_page 888 -i - -y -live_start_index 0 -force_key_frames \"expr:gte(t,n_forced*2)\" -vf scale_vaapi=".$settings[$_REQUEST["quality"]]["width"].":".$settings[$_REQUEST["quality"]]["height"]." -c:v h264_vaapi -vprofile high -preset veryfast -b:v ".$settings[$_REQUEST["quality"]]["vbitrate"]."K -maxrate:v ".$settings[$_REQUEST["quality"]]["vbitrate"]."K  -minrate:v ".$settings[$_REQUEST["quality"]]["vbitrate"]."K -bufsize:v ".$settings[$_REQUEST["quality"]]["vbitrate"]."K -crf 22 -c:a aac -b:a ".$settings[$_REQUEST["quality"]]["abitrate"]."K -ac 2 -map 0:v:0 -map 0:a:0 -map 0:s:0 -f webvtt -f hls -hls_time 6 -hls_list_size 10 -hls_flags +delete_segments -var_stream_map \"v:0,a:0,agroup:audio".$settings[$_REQUEST["quality"]]["abitrate"].",language:dut,s:0,sgroup:subtitle\" -master_pl_name livestream.m3u8 -hls_segment_filename ".$relative_path."/".$channel."/stream_event_%v_data%02d.m4s ".$relative_path."/".$channel."/stream_event_%v.m3u8 2>>/tmp/ffmpeg-hdhomerunstream.log && /usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode finish success >> ".$channel_path."/".$channel."/status.txt' || /usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode finish failed >> ".$channel_path."/".$channel."/status.txt'\n");
    fwrite($fp, "else\n");
    fwrite($fp, "/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode start >> ".$channel_path."/".$channel."/status.txt'; /usr/bin/sudo -uapache /usr/bin/mkdir -p ".$channel_path."; /usr/bin/sudo -uapache /usr/bin/mkdir -p ".$channel_path."/".$channel."; /usr/bin/sudo -uapache /usr/bin/mkdir -p ".$live_path."/".$channel."; cd ".$channel_path."/; /usr/bin/sudo -uapache /usr/bin/hdhomerun_config ".$HDHRID." save /".$tuner." - | /usr/bin/sudo -uapache /usr/bin/ffmpeg -fix_sub_duration -hwaccel vaapi -vaapi_device /dev/dri/renderD128 -hwaccel_output_format vaapi -i - -y -live_start_index 0 -force_key_frames \"expr:gte(t,n_forced*2)\" -vf scale_vaapi=".$settings[$_REQUEST["quality"]]["width"].":".$settings[$_REQUEST["quality"]]["height"]." -c:v h264_vaapi -vprofile high -preset veryfast -b:v ".$settings[$_REQUEST["quality"]]["vbitrate"]."K -maxrate:v ".$settings[$_REQUEST["quality"]]["vbitrate"]."K  -minrate:v ".$settings[$_REQUEST["quality"]]["vbitrate"]."K -bufsize:v ".$settings[$_REQUEST["quality"]]["vbitrate"]."K -crf 22 -c:a aac -b:a ".$settings[$_REQUEST["quality"]]["abitrate"]."K -ac 2 -map 0:v:0 -map 0:a:0 -f hls -hls_time 6 -hls_list_size 10 -hls_flags +delete_segments -var_stream_map \"v:0,a:0,agroup:audio".$settings[$_REQUEST["quality"]]["abitrate"].",language:dut\" -master_pl_name livestream.m3u8 -hls_segment_filename ".$relative_path."/".$channel."/stream_event_%v_data%02d.m4s ".$relative_path."/".$channel."/stream_event_%v.m3u8 2>>/tmp/ffmpeg-hdhomerunstream.log && /usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode finish success >> ".$channel_path."/".$channel."/status.txt' || /usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode finish failed >> ".$channel_path."/".$channel."/status.txt'\n");
    fwrite($fp, "fi\n");
    fwrite($fp, "sleep 1 && /usr/bin/sudo /usr/bin/screen -S ".$channel."_encode -X quit\n");
    fclose($fp);

    $response = shell_exec("/usr/bin/sudo /usr/bin/chmod a+x ".$channel_path."/".$channel."/encode.sh");
    $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$channel."_encode -dm /bin/bash");
    $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$channel."_encode -X eval 'chdir ".$channel_path."/".$channel."/'");
    $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$channel."_encode -X logfile '".$channel_path."/".$channel."/encode.log'");
    $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$channel."_encode -X log on");
    $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$channel."_encode -X stuff '".$channel_path."/".$channel."/encode.sh\n'");
    ?>
        <!DOCTYPE html>
        <html>
        <head><title>Live TV</title>
        <!-- Load the Shaka Player library. -->
        <script src="shaka-player.compiled.js"></script>
        <!-- Shaka Player ui compiled library: -->
        <script src="shaka-player.ui.js"></script>
        <!-- Shaka Player ui compiled library default CSS: -->
        <link rel="stylesheet" type="text/css" href="controls.css">
        <script defer src="https://www.gstatic.com/cv/js/sender/v1/cast_sender.js"></script>
         <script>
        var manifestUri = '';
        var statusInterval = null;
        var playerInitDone = false;
        var currentStatus = "";
        manifestUri = "<?php echo $relative_path; ?>/<?php echo $channel; ?>/livestream.m3u8";

        function checkFileExists(url) {
            var xhr = new XMLHttpRequest();
            xhr.open('HEAD', url, false);
            xhr.send();
            return xhr.status == 200;
        }

        function showStatus()
        {
            alert(currentStatus);
        }

        function pad(num)
        {
            var str = "" + num;
            var pad = "00";
            return pad.substring(0, pad.length - str.length) + str;
        }

        function checkStatusListener()
        {
            console.log(this.responseText);
            var status = JSON.parse(this.responseText);
            var message = "";
            if (status["status"])
            {
                currentStatus = status["status"].join("");
                for (var i = 0; i < status["status"].length; i++)
                {
                    if (status["status"][i].indexOf("fail") >= 0)
                    {
                        message = "Failed to generate video ("+status["status"][i]+")";
                    }
                }
            }
            if (!message)
            {
                if (status["available"] >= 0 && currentStatus.indexOf("encode start") >= 0)
                {
                    message = "Live stream is ready";
                    if (!playerInitDone)
                    {
                        playerInitDone = true;
                        initPlayer();
                    }
                }
            }
            document.getElementById("statusbutton").value = message;
        }

        function checkStatus()
        {
            var oReq = new XMLHttpRequest();
            var newHandle = function(event) { handle(event, myArgument); };
            oReq.addEventListener("load", checkStatusListener, {once: true});
            oReq.open("GET", "hdhomerunstream.php?action=status&channel=<?php echo $channel; ?>");
            oReq.send();
        }

        function initApp() {
          // Install built-in polyfills to patch browser incompatibilities.
          shaka.polyfill.installAll();

          // Check to see if the browser supports the basic APIs Shaka needs.
          if (shaka.Player.isBrowserSupported()) {
            // Everything looks good!
            statusInterval = window.setInterval(function() { checkStatus(); }, 5000);
            checkStatus();
          } else {
            // This browser does not have the minimum set of APIs we need.
            console.error('Browser not supported!');
          }
        }

        // async does not work on Edge
        //async function initPlayer() {
        function initPlayer() {
            // When using the UI, the player is made automatically by the UI object.
            const video = document.getElementById('video');
            const ui = video['ui'];
            const controls = ui.getControls();
            const player = controls.getPlayer();

            player.configure('streaming.useNativeHlsOnSafari', true);

            // Attach player and ui to the window to make it easy to access in the JS console.
            window.player = player;
            window.ui = ui;
            ui.configure('castReceiverAppId', '930DEB06');

            // Listen for error events.
            player.addEventListener('error', onPlayerErrorEvent);
            controls.addEventListener('error', onUIErrorEvent);

            // Try to load a manifest.
            // This is an asynchronous process.
            try {
                // await does not work on Edge
                // await player.load(manifestUri);
                player.load(manifestUri);
                // This runs if the asynchronous load is successful.
                console.log('The video has now been loaded!');
                //         video.requestFullscreen().catch(err => {
                //                      console.log(err)
                //                             });
            } catch (error) {
                onPlayerError(error);
            }
        }

        function onCastStatusChanged(event) {
            const newCastStatus = event['newStatus'];
            // Handle cast status change
            console.log('The new cast status is: ' + newCastStatus);
        }

        function onPlayerErrorEvent(errorEvent) {
            // Extract the shaka.util.Error object from the event.
            onPlayerError(event.detail);
        }

        function onPlayerError(error) {
            // Handle player error
            console.error('Error code', error.code, 'object', error);
        }

        function onUIErrorEvent(errorEvent) {
            // Extract the shaka.util.Error object from the event.
            onPlayerError(event.detail);
        }

        function initFailed(errorEvent) {
            // Handle the failure to load; errorEvent.detail.reasonCode has a
            // shaka.ui.FailReasonCode describing why.
            console.error('Unable to load the UI library!');
        }

        function onCastStatusChanged(event) {
            const newCastStatus = event['newStatus'];
            // Handle cast status change
            console.log('The new cast status is: ' + newCastStatus);
        }

        document.addEventListener('DOMContentLoaded', initApp);
        // Listen to the custom shaka-ui-loaded event, to wait until the UI is loaded.
        document.addEventListener('shaka-ui-loaded', initPlayer);
        // Listen to the custom shaka-ui-load-failed event, in case Shaka Player fails
        // to load (e.g. due to lack of browser support).
        document.addEventListener('shaka-ui-load-failed', initFailed);
        </script>
        </head>
        <body>
        <table cellspacing="10"><tr><td>
        <form action="hdhomerunstream.php" method="GET" onSubmit="return confirm('Are you sure you want to stop streaming?');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="channel" value="<?php echo $_REQUEST['channel']; ?>">
        <input type="submit" value="Stop streaming">
        </form></td><td valign="top">
        <form>
        <input type="button" onClick="showStatus();" id="statusbutton" value="Loading...">
        </form>
        </td><td valign="top">
              <span id="mp4link"></span>
              </td></tr>
<?php
              echo "<tr><td>";
              echo "<a href=\"http://192.168.1.29/shutdownlock.php\">Shutdown Lock</a>\n";
        if (file_exists($live_path."/".$channel."/livestream.m3u8"))
        {
            echo "</td><td>";
            echo "<a href=\"http://192.168.1.29/live/".$channel."/livestream.m3u8\" download>Live Stream</a>\n";
        }
        echo "</td></tr>";
        ?>
            </table>
            <!-- The data-shaka-player-container tag will make the UI library place the controls in this div.
                  The data-shaka-player-cast-receiver-id tag allows you to provide a Cast Application ID that
                  the cast button will cast to; the value provided here is the sample cast receiver. -->
             <div data-shaka-player-container style="max-width:40em"
                  data-shaka-player-cast-receiver-id="930DEB06">
               <video autoplay data-shaka-player id="video" style="width:100%;height:100%">
                  Your browser does not support HTML5 video.
               </video>
               </div>
            </body>
            </html>
<?php
}
else
{
    echo "<html><head><title>Select quality and TV Channel</title></head><body><h2>Select TV Channel:</h2>";
    echo $select_box."</body></html>";

}
?>
