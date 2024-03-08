<?php

/**
 * Configure hdhomerunstream.php
 *
 *
 */
$webroot = "/var/www/html";
# NOTE: configure live_path as a ramdisk in /etc/fstab
$live_path = "$webroot/live";
$channel_path = "$webroot/channel";
// // NOTE: ISO 639-2/B language codes, the first match of the subtitle language preference is used
// $sublangpref = array(
//     "dut" => array("name" => "Dutch",        "ISO" => "dut"),
//     "dum" => array("name" => "dum",          "ISO" => "dum"),
//     "eng" => array("name" => "English",      "ISO" => "eng"),
//     "ger" => array("name" => "German",       "ISO" => "ger"),
// );
$language = "dut";
$languagename = "Dutch";
# TODO: select free tuner instead of hard coding
$tuner="tuner3";
$ffmpeg="/usr/bin/ffmpeg";

$webuser = "apache";

// Different hw acceleration options
// NOTE: only "h264" and "nohwaccel" have been tested and are known to work
$hwaccels = array(
    "h264"      => array("encoder" => "h264_vaapi", "decoder" => "h264_vaapi", "scale" => "format=nv12|vaapi,hwupload,scale_vaapi", "hwaccel" => "-hwaccel vaapi -vaapi_device /dev/dri/renderD128"),
    "h265"      => array("encoder" => "hevc_vaapi", "decoder" => "hevc_vaapi", "scale" => "scale_vaapi", "hwaccel" => "-hwaccel vaapi -vaapi_device /dev/dri/renderD128 -hwaccel_output_format vaapi"),
    "qsv"       => array("encoder" => "h264_qsv",   "decoder" => "h264_qsv",   "scale" => "scale_qsv",   "hwaccel" => "-hwaccel qsv  -qsv_device -hwaccel_device /dev/dri/renderD128 -c:v h264_qsv"),
    "nvenc"     => array("encoder" => "h264_nvenc", "decoder" => "h264_nvenc", "scale" => "scale",       "hwaccel" => "-hwaccel cuda -vaapi_device /dev/dri/renderD128 -hwaccel_output_format nvenc"),
    "nohwaccel" => array("encoder" => "libx264",    "decoder" => "libx264",    "scale" => "scale",       "hwaccel" => ""),
);

// Ladder from which the user may choose, Aspect ratio 16:9
// https://medium.com/@peer5/creating-a-production-ready-multi-bitrate-hls-vod-stream-dff1e2f1612c
$settings = array(
                "high1440" =>   array("height" => 1440, "width" => 2560, "vbitrate" => 8000, "abitrate" => 192),
                "normal1440" => array("height" => 1440, "width" => 2560, "vbitrate" => 6000, "abitrate" => 192),
                "low1440" =>    array("height" => 1440, "width" => 2560, "vbitrate" => 4000, "abitrate" => 192),
                "high1080" =>   array("height" => 1080, "width" => 1920, "vbitrate" => 5300, "abitrate" => 192),
                "normal1080" => array("height" => 1080, "width" => 1920, "vbitrate" => 4900, "abitrate" => 192),
                "low1080" =>    array("height" => 1080, "width" => 1920, "vbitrate" => 4500, "abitrate" => 192),
                "high720" =>    array("height" =>  720, "width" => 1280, "vbitrate" => 3200, "abitrate" => 128),
                "normal720" =>  array("height" =>  720, "width" => 1280, "vbitrate" => 2850, "abitrate" => 128),
                "low720" =>     array("height" =>  720, "width" => 1280, "vbitrate" => 2500, "abitrate" =>  64),
                "high480" =>    array("height" =>  480, "width" =>  854, "vbitrate" => 1600, "abitrate" => 128),
                "normal480" =>  array("height" =>  480, "width" =>  854, "vbitrate" => 1425, "abitrate" =>  64),
                "low480" =>     array("height" =>  480, "width" =>  854, "vbitrate" => 1250, "abitrate" =>  48),
                "high360" =>    array("height" =>  360, "width" =>  640, "vbitrate" =>  900, "abitrate" => 128),
                "normal360" =>  array("height" =>  360, "width" =>  640, "vbitrate" =>  800, "abitrate" =>  64),
                "low360" =>     array("height" =>  360, "width" =>  640, "vbitrate" =>  700, "abitrate" =>  48),
                "high240" =>    array("height" =>  240, "width" =>  426, "vbitrate" =>  600, "abitrate" => 128),
                "normal240" =>  array("height" =>  240, "width" =>  426, "vbitrate" =>  500, "abitrate" =>  64),
                "low240" =>     array("height" =>  240, "width" =>  426, "vbitrate" =>  400, "abitrate" =>  48),
);
/*********** DO NOT CHANGE ANYTHING BELOW THIS LINE UNLESS YOU KNOW WHAT YOU ARE DOING **********/

$HDHRID = shell_exec("/usr/bin/sudo /usr/bin/hdhomerun_config discover | cut -d ' ' -f3");
$HDHRID = str_replace("\n", '', $HDHRID);
$response = shell_exec("/usr/bin/sudo /usr/bin/mkdir -p ".$channel_path.";");
$response = shell_exec("/usr/bin/sudo /usr/bin/chown ".$webuser.":".$webuser." ".$channel_path.";");
$relative_path = shell_exec("/usr/bin/sudo /usr/bin/realpath --relative-to=$channel_path $live_path");
$relative_path = str_replace("\n", '', $relative_path);
$keys = array_keys($settings);

function http_request($method, $endpoint, $rest) {

    $params = array("http" => array(
        "method" => $method,
        "header" => 'Content-type: application/x-www-form-urlencoded; charset=uft-8'
    ));

    $context = stream_context_create($params);

    if (($xml_response = file_get_contents("http://localhost:6544/$endpoint?$rest", false, $context)) === false) {
        $error = error_get_last();
        echo "HTTP request failed. Error was: " . $error['message'];
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
    // http://localhost:6544/Channel/GetVideoMultiplexList?SourceID=1&StartIndex=0&Count=100

    $xml_response = http_request("GET" ,"Channel/GetVideoMultiplexList",
                                 "SourceID=1&StartIndex=0&Count=100");
    $xml = simplexml_load_string($xml_response, "SimpleXMLElement", LIBXML_NOCDATA);
    $json = json_encode($xml);

    return json_decode($json,TRUE);
}

function get_channel_info_list ($VideoMultiplexList) {
    #http://localhost:6544/Channel/GetChannelInfoList?SourceID=0&StartIndex=0&Count=100&OnlyVisible=false&Details=true

    $xml_response = http_request("GET" ,"Channel/GetChannelInfoList",
                                 "SourceID=0&StartIndex=0&Count=100&OnlyVisible=false&Details=true");

    $xml = simplexml_load_string($xml_response, "SimpleXMLElement", LIBXML_NOCDATA);
    $json = json_encode($xml);
    $array = json_decode($json,TRUE);
    $channels = array();

    foreach ($array['ChannelInfos'] as $ChannelInfo) {
        foreach ($ChannelInfo as $info_value) {
            $new_info_array = array();
            foreach ($info_value as $key => $value) {
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
                    foreach ($VideoMultiplexList['VideoMultiplexes'] as $VideoMultiplex) {
                        foreach ($VideoMultiplex as $multiplex_value) {
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
    if (!array_key_exists($_REQUEST["quality"][0], $settings))
    {
        throw new InvalidArgumentException('Invalid quality');
    }
}

$select_box = "<div style=\"float:left;width:90%;\"><form action=\"hdhomerunstream.php\" method=\"GET\">";
$select_box .= "<label for=\"quality\">Adaptive Bitrate Streaming (ABR): </label><select class=\"select\" name=\"quality[]\" size=4 multiple required>";
$select_box .= "<option value=\"\" disabled hidden>-- Use Ctrl-Click, Command-Click and Shift-Click to compose ABR --</option>";
foreach ($settings as $setting => $settingset)
{
    $select_box .= "<option value=\"".$setting."\"".((strpos($setting, "high480") !== false)?" selected":"").
                           ">".preg_replace('/[0-9]+/', '', ucfirst($setting))." Quality ".$settingset["height"]."p".
                           "</option>\n";
}
$select_box .= "</form></select></div><br>";
$select_box .= "<div style=\"float:left;width:90%;\"><form action=\"hdhomerunstream.php\" method=\"GET\">";
$select_box .= "<label for=\"hwaccel\">HW acceleration: </label><select class=\"select\" name=\"hw\" required>";
$select_box .= "<option value=\"\" disabled hidden>-- Please choose your HW Acceleration --</option>";
foreach ($hwaccels as $hwaccel => $hwaccelset)
{
    $select_box .= "<option value=\"".$hwaccel."\"".((strpos($hwaccel, "h264") !== false)?" selected=\"selected\"":"").
                ">".$hwaccelset["encoder"]."".
                "</option>\n";
}
$select_box .= "</form></select></div><br>";

$select_box .= "<div style=\"float:left;width:90%;\"><form action=\"hdhomerunstream.php\" method=\"GET\">";
$select_box .= "<label for=\"channel\">TV Channel: </label>";
$select_box .= "<select name=\"channel\" required>";
$select_box .= "<option value=\"\" disabled hidden>-- Please choose a Channel --</option>";
$select_first = "selected";
foreach($channels as $key => $value) {
    // try to recover original channel name: keep abbreviations and separate channel number
    $regex = '/((?<=[a-z])(?=[A-Z])|(?=[A-Z][a-z]))/';
    $string = preg_replace( $regex, ' $1', $key );
    preg_match('/[0-9]+/', $string, $number);
    if (isset($number[0]) && is_numeric($number[0]))
    {
        $select_box .= "<option value=\"$key\" ".$select_first.">".preg_replace('/[0-9]+/', '', ucfirst($string))." ".$number[0]."</option>";
    }
    else {
        $select_box .= "<option value=\"$key\" ".$select_first.">".preg_replace('/[0-9]+/', '', ucfirst($string))."</option>";
    }
    $select_first = "";
}
$select_box .= "</select></div><br><div style=\"float:left;width:90%;\"><input type=\"submit\" name=\"do\" value=\"Watch TV\"></div></form>";

if (isset($_REQUEST['action']) && $_REQUEST["action"] == "delete")
{
    // Shut down all screen sessions
    $response = shell_exec("/usr/bin/sudo /usr/bin/screen -ls ".$_REQUEST['channel']."_encode  | /usr/bin/grep -E '\s+[0-9]+.' | /usr/bin/awk '{print $1}' - | while read s; do /usr/bin/sudo /usr/bin/screen -XS \$s quit; done");
    // delete live files
    array_map('unlink', glob($channel_path."/".$_REQUEST['channel']."/*.txt"));
    array_map('unlink', glob($channel_path."/".$_REQUEST['channel']."/*.log"));
    array_map('unlink', glob($channel_path."/".$_REQUEST['channel']."/*.sh"));
    array_map('unlink', glob($live_path."/".$_REQUEST['channel']."/*.vtt"));
    array_map('unlink', glob($live_path."/".$_REQUEST['channel']."/*.m4s"));
    array_map('unlink', glob($live_path."/".$_REQUEST['channel']."/*.mp4"));
    array_map('unlink', glob($live_path."/".$_REQUEST['channel']."/*.m3u8"));
    rmdir($channel_path."/".$_REQUEST['channel']."/");
    if (is_dir($channel_path."/../live/".$_REQUEST['channel']."/"))
    {
        rmdir($channel_path."/../live/".$_REQUEST['channel']."/");
    }
    echo "<html><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"><head><title>Stopped Live Stream</title></head><body><h2>Stopped Live Stream</h2>".$select_box."</body></html>";
}
else if (isset($_REQUEST['action']) && $_REQUEST["action"] == "status")
{
    $channel = $_REQUEST['channel'];
    $status = array();
    if (file_exists($channel_path."/".$channel."/status.txt"))
    {
        $status["status"] = file($channel_path."/".$channel."/status.txt");
    }
    if (file_exists($live_path."/".$channel."/master_live.m3u8"))
    {
        $status["available"] = 100;
    }

    echo json_encode($status);
}
else if (isset($_REQUEST["do"]))
{
    $channel = $_REQUEST['channel'];
    if (!file_exists($channel_path."/".$channel."/"))
    {
        mkdir($channel_path."/".$channel."/");
        $fp = fopen($channel_path."/".$channel."/encode.sh", "w");
        fwrite($fp, "/usr/bin/sudo -u".$webuser." /usr/bin/hdhomerun_config ".$HDHRID." set /".$tuner."/channel auto:".$channels[$_REQUEST['channel']]['Frequency'].";\n");
        fwrite($fp, "/usr/bin/sudo -u".$webuser." /usr/bin/hdhomerun_config ".$HDHRID." set /".$tuner."/program ".$channels[$_REQUEST['channel']]['ServiceId'].";\n");
        fwrite($fp, "/usr/bin/sudo -u".$webuser." /usr/bin/hdhomerun_config ".$HDHRID." save /".$tuner." - | /usr/bin/sudo -u".$webuser." ".$ffmpeg." \
                                             -txt_format text -txt_page 888 \
                                             -i - -y \
                                             -c copy \
                                             -map 0:s:0 -frames:s 1 \
                                             -f null - -v 0 -hide_banner;\n");
        fwrite($fp, "subtitles=`echo $?`;\n");
        $nb_renditions = 0;
        for ($i=0; $i < count($settings); $i++)
        {
            if (isset($_REQUEST["quality"][$i]))
            {
                $nb_renditions++;
            }
        }
        fwrite($fp, "/usr/bin/sudo -u".$webuser." /usr/bin/bash -c '/usr/bin/echo `date`: encode start >> ".$channel_path."/".$channel."/status.txt';
/usr/bin/sudo -u".$webuser." /usr/bin/mkdir -p ".$channel_path.";
/usr/bin/sudo -u".$webuser." /usr/bin/mkdir -p ".$channel_path."/".$channel.";
/usr/bin/sudo -u".$webuser." /usr/bin/mkdir -p ".$live_path."/".$channel.";\n");
        fwrite($fp, "if [ \"\$subtitles\" -eq \"0\" ]; then\n");
        //
        // subtitles present
        //
        $master_file = "".$live_path."/".$channel."/master_live.m3u8";
        // This command is delayed until master_live.m3u8 is created by ffmpeg!!!
        fwrite($fp, "    cd ".$channel_path.";
       (while [ ! -f \"".$master_file."\" ] ; \
        do \
            /usr/bin/inotifywait -e close_write --include \"master_live.m3u8\" ".$live_path."/".$channel."; \
        done; \
                 /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\\1\\n#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subtitles\",NAME=\"".$languagename."\",DEFAULT=YES,FORCED=NO,AUTOSELECT=YES,URI=\"sub_0_vtt.m3u8\",LANGUAGE=\"".$language."\"/' ".$master_file."; \
                 /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+\,RESOLUTION.*)/\\1,SUBTITLES=\"subtitles\"/' ".$master_file.";  /usr/bin/sudo -u".$webuser." /usr/bin/sudo sed -r '/(#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+\,CODECS)/{N;d;}' -i ".$master_file.";) & \n");
        fwrite($fp, "fi\n");
        fwrite($fp, "/usr/bin/sudo -u".$webuser." /usr/bin/hdhomerun_config ".$HDHRID." save /".$tuner." - | /usr/bin/sudo -u".$webuser." ".$ffmpeg." \
                                         -fix_sub_duration \
                                         ".$hwaccels[$_REQUEST["hw"]]["hwaccel"]." \
                                         -txt_format text -txt_page 888 \
                                         -i - -y \
                                         -tune film \
                                         -live_start_index 0 \
                                         -force_key_frames \"expr:gte(t,n_forced*2)\" \\\n");
        fwrite($fp, "                                     -filter_complex \"[0:v]");
        for ($i=0; $i < $nb_renditions; $i++)
        {
            $vout = "v".$i;
            if ($i == 0)
            {
                if ($nb_renditions > 1)
                {
                    fwrite($fp, "split=$nb_renditions");
                    for ($j=0; $j < $nb_renditions; $j++)
                    {
                        $v = "v".$j;
                        fwrite($fp, "[".++$v."]");
                    }
                    fwrite($fp, ";[".++$vout."]");
                    fwrite($fp, "".$hwaccels[$_REQUEST["hw"]]["scale"]."=w=".$settings[$_REQUEST["quality"][$i]]["width"].":h=".$settings[$_REQUEST["quality"][$i]]["height"]."[".$vout."out]");
                }
                else
                {
                    fwrite($fp, "".$hwaccels[$_REQUEST["hw"]]["scale"]."=w=".$settings[$_REQUEST["quality"][$i]]["width"].":h=".$settings[$_REQUEST["quality"][$i]]["height"]."[".++$vout."out]");
                }
            }
            else
            {
                fwrite($fp, ";[".++$vout."]".$hwaccels[$_REQUEST["hw"]]["scale"]."=w=".$settings[$_REQUEST["quality"][$i]]["width"].":h=".$settings[$_REQUEST["quality"][$i]]["height"]."[".$vout."out]");
            }
        }
        fwrite($fp, "\" \\\n");
        for ($i=0; $i < $nb_renditions; $i++)
        {
            $vout = "v".$i;
            fwrite($fp, "                                         -map [".++$vout."out] -c:v:$i \
                                             ".$hwaccels[$_REQUEST["hw"]]["encoder"]." \
                                             -b:v:$i ".$settings[$_REQUEST["quality"][$i]]["vbitrate"]."k \
                                             -maxrate:v:$i ".$settings[$_REQUEST["quality"][$i]]["vbitrate"]."k \
                                             -bufsize:v:$i 1.5*".$settings[$_REQUEST["quality"][$i]]["vbitrate"]."k \
                                             -crf 23 \
                                             -preset veryfast \
                                             -g 48 \
                                             -keyint_min 48 \
                                             -sc_threshold 0 \
                                             -flags +global_header \\\n");
        }
        for ($i=0; $i < $nb_renditions; $i++)
        {
            $bool_new_abitrate = true;
            $current_abitrate = $settings[$_REQUEST["quality"][$i]]["abitrate"];
            for ($j=0; $j < $i; $j++)
            {
                if ($settings[$_REQUEST["quality"][$j]]["abitrate"] == $current_abitrate)
                {
                    // seen abitrate before
                    $bool_new_abitrate = false;
                }
            }
            if ($bool_new_abitrate)
            {
                fwrite($fp, "                                         -map a:0 -c:a:".$i." aac -b:a:".$i." ".$current_abitrate."k \
                                             -metadata:s:a:".$i." language=".$language." \\\n");
            }
        }
        fwrite($fp, "                                         -map 0:s:0? -c:s webvtt \
                                         -f tee \
                                              \"[select=\'");
        for ($i=0; $i < $nb_renditions; $i++)
        {
            $bool_new_audio = true;
            $current_abitrate = $settings[$_REQUEST["quality"][$i]]["abitrate"];
            for ($j=0; $j < $i; $j++)
            {
                if ($settings[$_REQUEST["quality"][$j]]["abitrate"] == $current_abitrate)
                {
                    // seen abitrate before
                    $bool_new_audio = false;
                }
            }
            if ($bool_new_audio)
            {
                fwrite($fp, "a:".$i.",");
            }
        }
        for ($i=0; $i < $nb_renditions; $i++)
        {
            if ($i == $nb_renditions - 1)
            {
                fwrite($fp, "v:".$i."");
            }
            else
            {
                fwrite($fp, "v:".$i.",");
            }
        }
        fwrite($fp, "\': \
                                                f=hls: \
                                                hls_time=2: \
                                                hls_list_size=10: \
                                                hls_flags=+independent_segments+iframes_only+delete_segments: \
                                                hls_segment_type=fmp4: \
                                                var_stream_map=\'");
        for ($i=0; $i < $nb_renditions; $i++)
        {
            $bool_new_abitrate = true;
            $current_abitrate = $settings[$_REQUEST["quality"][$i]]["abitrate"];
            for ($j=0; $j < $i; $j++)
            {
                if ($settings[$_REQUEST["quality"][$j]]["abitrate"] == $current_abitrate)
                {
                    // seen abitrate before
                    $bool_new_abitrate = false;
                }
            }
            if ($bool_new_abitrate)
            {
                fwrite($fp, "a:".$i.",agroup:aac,language:".$language.",name:aac_".$i."_".$current_abitrate."k ");
            }
        }
        for ($i=0; $i < $nb_renditions; $i++)
        {
            fwrite($fp, "v:".$i.",agroup:aac,name:".$settings[$_REQUEST["quality"][$i]]["height"]."p_".$settings[$_REQUEST["quality"][$i]]["vbitrate"]."");
            if ($i < $nb_renditions - 1)
            {
                fwrite($fp, " ");
            }
        }
        fwrite($fp, "\\': \\\n                                                master_pl_name=master_live.m3u8: \
                                                hls_segment_filename=../live/".$channel."/stream_live_%v_data%02d.m4s]../live/".$channel."/stream_live_%v.m3u8 | \
                                               [select=\'v:0,s:0\': \
                                                strftime=1: \
                                                f=hls: \
                                                hls_flags=+independent_segments+delete_segments+program_date_time: \
                                                hls_time=2: \
                                                hls_list_size=10: \
                                                hls_segment_type=fmp4: \
                                                var_stream_map=\'v:0,s:0,sgroup:subtitle\': \
                                                hls_segment_filename=\'/dev/null\']../live/".$channel."/sub_%v.m3u8\" \
                                               2>>/tmp/ffmpeg-hdhomerunstream.log && \
                                                  /usr/bin/sudo -u".$webuser." /usr/bin/bash -c '/usr/bin/echo `date`: encode finish success >> ".$channel_path."/".$channel."/status.txt' || \
                                                  /usr/bin/sudo -u".$webuser." /usr/bin/bash -c '/usr/bin/echo `date`: encode finish failed >> ".$channel_path."/".$channel."/status.txt'\n");
        fwrite($fp, "sleep 1 && /usr/bin/sudo /usr/bin/screen -S ".$channel."_encode -X quit\n");
        fclose($fp);

        $response = shell_exec("/usr/bin/sudo /usr/bin/chmod a+x ".$channel_path."/".$channel."/encode.sh");
        $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$channel."_encode -dm /bin/bash");
        $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$channel."_encode -X eval 'chdir ".$channel_path."/".$channel."/'");
        $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$channel."_encode -X logfile '".$channel_path."/".$channel."/encode.log'");
        $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$channel."_encode -X log on");
        $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$channel."_encode -X stuff '".$channel_path."/".$channel."/encode.sh\n'");
    }
    ?>
        <!DOCTYPE html>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <html>
        <head><title>Live TV</title>
        <style>
        #liveButtonId { display: none;
        visibility: hidden; }
        </style>
        <!-- Load the Shaka Player library. -->
        <script src="../dist/shaka-player.compiled.js"></script>
        <!-- Shaka Player ui compiled library: -->
        <script src="../dist/shaka-player.ui.js"></script>
        <!-- Shaka Player ui compiled library default CSS: -->
        <link rel="stylesheet" type="text/css" href="../dist/controls.css">
        <script defer src="https://www.gstatic.com/cv/js/sender/v1/cast_sender.js"></script>
         <script>
        var manifestUri = '';
        var statusInterval = null;
        var playerInitDone = false;
        var currentStatus = "";
        manifestUri = "<?php echo $relative_path; ?>/<?php echo $channel; ?>/master_live.m3u8";

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

            if (checkFileExists("../live/<?php echo $channel; ?>/master_live.m3u8")) {
                message_string = "LIVE Available";
                // Show button to play live stream
                var liveButtonId = document.getElementById("liveButtonId");
                liveButtonId.style.display = 'block';
                liveButtonId.style.visibility = 'visible';
                liveButtonId.setAttribute('value',"<?php echo $channel; ?>");
                liveButtonId.setAttribute('onclick',"window.location.href='../live/<?php echo $channel; ?>/master_live.m3u8'");
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
        <table cellspacing="10">
          <tr>
            <td>
              <form action="hdhomerunstream.php" method="GET" onSubmit="return confirm('Are you sure you want to stop streaming?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="channel" value="<?php echo $_REQUEST['channel']; ?>">
                <input type="submit" value="Stop streaming">
              </form>
            </td>
            <td valign="top">
              <form>
                <input type="button" onClick="showStatus();" id="statusbutton" value="Loading...">
              </form>
            </td>
          </tr>
          <tr>
            <td>
              <a href='./shutdownlock.php' target='_blank' rel="noopener noreferrer"><button type="button">Shutdown Lock</button></a>
            </td>
            <td>
              <input type="button" style="display: none; visibility: hidden;" id="liveButtonId" value="LIVE" />
            </td>
          </tr>
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
    echo "<html><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"><head><title>Select quality and TV Channel</title></head><body><h2>Select TV Channel:</h2>";
    echo $select_box."</body></html>";

}
?>
