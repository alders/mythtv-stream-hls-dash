<?php

/**
 * Configure index.php
 *
 *
 */
define('MARK_CUT_START',1);
define('MARK_CUT_END',0);

$hlsdir = "hls";
$livedir = "live";
$voddir = "vod";
$webroot = "/var/www/html";
$hls_path = "$webroot/$hlsdir";
$live_path = "$webroot/$livedir";
$vod_path = "$webroot/$voddir";
// NOTE: ISO 639-2/B language codes, the first match of the subtitle language preference is used
$sublangpref = array(
    "dut" => array("name" => "Dutch"),
    "dum" => array("name" => "Middle Dutch"),
    "eng" => array("name" => "English"),
    "ger" => array("name" => "German"),
);

$ffmpeg="/usr/bin/ffmpeg";

$webuser = "apache";

$xml = simplexml_load_file("/home/mythtv/.mythtv/config.xml");
$yourserver = $xml->Database->Host;

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
$resolutions = array(
    "1440" => array("height" => 1440, "width" => 2560, "abitrate" => 192),
    "1080" => array("height" => 1080, "width" => 1920, "abitrate" => 192),
    "720"  => array("height" => 720,  "width" => 1280, "abitrate" => 128),
    "480"  => array("height" => 480,  "width" => 854,  "abitrate" => array("high" => 128, "normal" => 64, "low" => 48)),
    "360"  => array("height" => 360,  "width" => 640,  "abitrate" => array("high" => 128, "normal" => 64, "low" => 48)),
    "240"  => array("height" => 240,  "width" => 426,  "abitrate" => array("high" => 128, "normal" => 64, "low" => 48)),
);

$bitrates = array(
    "1440" => array("high" => 8000, "normal" => 6000, "low" => 4000),
    "1080" => array("high" => 5300, "normal" => 4900, "low" => 4500),
    "720"  => array("high" => 3200, "normal" => 2850, "low" => 2500),
    "480"  => array("high" => 1600, "normal" => 1425, "low" => 1250),
    "360"  => array("high" => 900,  "normal" => 800,  "low" => 700),
    "240"  => array("high" => 600,  "normal" => 500,  "low" => 400),
);

$settings = array();
foreach ($resolutions as $resolution => $resolutionSettings) {
    foreach ($bitrates[$resolution] as $quality => $vbitrate) {
        $abitrate = is_array($resolutionSettings["abitrate"]) ? $resolutionSettings["abitrate"][$quality] : $resolutionSettings["abitrate"];
        $settings[$quality . $resolution] = array(
            "height" => $resolutionSettings["height"],
            "width" => $resolutionSettings["width"],
            "vbitrate" => $vbitrate,
            "abitrate" => $abitrate,
        );
    }
}

/*********** DO NOT CHANGE ANYTHING BELOW THIS LINE UNLESS YOU KNOW WHAT YOU ARE DOING **********/

$keys = array_keys($settings);

if (isset($_REQUEST["filename"]))
{
    $parts = explode("_", $_REQUEST["filename"]);
    if (count($parts) != 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]))
    {
        throw new InvalidArgumentException('Invalid filename');
    }
}
if (isset($_REQUEST["quality"]))
{
    if (!array_key_exists($_REQUEST["quality"][0], $settings))
    {
        throw new InvalidArgumentException('Invalid quality');
    }
}
if (isset($_REQUEST["clippedlength"]))
{
    if (!is_numeric($_REQUEST["clippedlength"]))
    {
        throw new InvalidArgumentException('Invalid clippedlength');
    }
}
if (isset($_REQUEST["length"]))
{
    if (!is_numeric($_REQUEST["length"]))
    {
        throw new InvalidArgumentException('Invalid length');
    }
}

function http_request($method, $endpoint, $rest)
{
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

function get_recorded($StartTime, $ChanId)
{
    // http://localhost:6544/Dvr/GetRecorded?StartTime=2023-07-08T18:55&ChanId=11500
    // NOTE: Only a selected subset of the values available is returned here

    if (empty($StartTime) || empty($ChanId)) {
        throw new InvalidArgumentException("StartTime and ChanId cannot be empty");
    }

    $url = "Dvr/GetRecorded";
    $query = "StartTime=$StartTime&ChanId=$ChanId";
    $xml_response = http_request("GET", $url, $query);

    if ($xml_response === false) {
        return array(); // or throw an exception
    }

    $xml = simplexml_load_string($xml_response, "SimpleXMLElement", LIBXML_NOCDATA);
    if ($xml === false) {
        return array(); // or throw an exception
    }

    $array = json_decode(json_encode($xml), TRUE);

    $recorded = array(
        'FileName' => $array['FileName'] ?? '',
        'HostName' => $array['HostName'] ?? '',
        'Title'    => $array['Title'] ?? '',
        'SubTitle' => $array['SubTitle'] ?? '',
    );

    if (isset($array['Recording']['RecordedId'])) {
        $recorded = array_merge($recorded, array(
            'RecordedId'   => $array['Recording']['RecordedId'],
            'StorageGroup' => $array['Recording']['StorageGroup'],
            'Status'       => $array['Recording']['Status'],
        ));
    }

    return $recorded;
}

function get_program_status($StartTime, $ChanId, $TitleFilter)
{
    if (empty($StartTime) || empty($ChanId) || empty($TitleFilter)) {
        throw new InvalidArgumentException("StartTime, ChanId and TitleFilter cannot be empty");
    }

    $url = "Guide/GetProgramList";
    $query = "StartTime=$StartTime&ChanId=$ChanId&TitleFilter=" . urlencode($TitleFilter);
    $xml_response = http_request("GET", $url, $query);

    if ($xml_response === false) {
        return array(); // or throw an exception
    }

    $xml = simplexml_load_string($xml_response, "SimpleXMLElement", LIBXML_NOCDATA);

    if ($xml === false) {
        return array(); // or throw an exception
    }

    // Use the adjusted XPath query
    $xpath = $xml->xpath("//Program[Channel/ChanId='$ChanId' and Title='$TitleFilter']/Recording/Status");

    // Check if the XPath returned any results
    if (!empty($xpath)) {
        // Return the status found
        return (string)$xpath[0];
    }

    // Return status unknown if not found
    return "-16";
}

function get_recorded_markup($RecordedId)
{
    // http://localhost:6544/Dvr/GetRecordedMarkup?RecordedId=6474
    // NOTE: Only a selected subset of the values available is returned here

    if (empty($RecordedId)) {
        throw new InvalidArgumentException("RecordedId cannot be empty");
    }

    $url = "Dvr/GetRecordedMarkup";
    $query = "RecordedId=$RecordedId";
    $xml_response = http_request("GET", $url, $query);

    if ($xml_response === false) {
        return array(); // or throw an exception
    }

    $xml = simplexml_load_string($xml_response, "SimpleXMLElement", LIBXML_NOCDATA);
    if ($xml === false) {
        return array(); // or throw an exception
    }

    $array = json_decode(json_encode($xml), TRUE);

    $recorded_markup_cut = array();
    $count_cut = 1;
    foreach ($array['Mark'] as $Markup) {
        foreach ($Markup as $key2 => $Type) {
            if (isset($Type['Type'])) {
                switch ($Type['Type']) {
                    case "CUT_START":
                        $recorded_markup_cut['CUT_START_' . $count_cut++] = $Type['Frame'];
                        break;
                    case "CUT_END":
                        $recorded_markup_cut['CUT_END_' . $count_cut++] = $Type['Frame'];
                        break;
                }
            }
        }
    }

    return $recorded_markup_cut;
}

function get_storagegroup_dirs($StorageGroup, $HostName)
{
    // http://localhost:6544/Myth/GetStorageGroupDirs
    // NOTE: Only a selected subset of the values available is returned here

    if (empty($StorageGroup) || empty($HostName)) {
        throw new InvalidArgumentException("StorageGroup and HostName cannot be empty");
    }

    $url = "Myth/GetStorageGroupDirs";
    $query = "";
    $xml_response = http_request("GET", $url, $query);

    if ($xml_response === false) {
        throw new RuntimeException("Failed to retrieve storage group directories");
    }

    $xml = simplexml_load_string($xml_response, "SimpleXMLElement", LIBXML_NOCDATA);
    if ($xml === false) {
        throw new RuntimeException("Failed to parse XML response");
    }

    $array = json_decode(json_encode($xml), TRUE);

    if (!isset($array['StorageGroupDirs'])) {
        throw new RuntimeException("Invalid XML response: StorageGroupDirs not found");
    }

    $storagegroup_dirs = array();
    $count_dir_name = 1;
    foreach ($array['StorageGroupDirs'] as $StorageGroupDir) {
        foreach ($StorageGroupDir as $item) {
            if (isset($item['HostName'], $item['GroupName'], $item['DirName']) &&
                $item['HostName'] == $HostName &&
                $item['GroupName'] == $StorageGroup) {
                $storagegroup_dirs['DirName' . $count_dir_name++] = $item['DirName'];
            }
        }
    }

    return $storagegroup_dirs;
}

function generateStreamOptions($settings, $qualities, $nb_renditions, $isAudio = true) {
    $stream_number = 0;
    $seen_abitrates = [];
    $options = '';

    if ($isAudio) {
        // Build audio options
        foreach ($qualities as $i => $quality) {
            $current_abitrate = $settings[$quality]["abitrate"];
            if (!in_array($current_abitrate, $seen_abitrates)) {
                $options .= "a:".$stream_number++.",";
                $seen_abitrates[] = $current_abitrate; // Add to seen bitrates
            }
        }
    }

    // Build video options
    for ($i = 0; $i < $nb_renditions; $i++) {
        $options .= "v:".$i.($i === $nb_renditions - 1 ? "" : ",");
    }

    return rtrim($options, ','); // Remove trailing comma
}

function generateMediaStreamOptions($settings, $qualities, $nb_renditions, $language) {
    $audio_stream_number = 0;
    $default = "yes";
    $options = '';

    // Build audio options
    $seen_abitrates = [];
    foreach ($qualities as $i => $quality) {
        $current_abitrate = $settings[$quality]["abitrate"];
        if (!in_array($current_abitrate, $seen_abitrates)) {
            $options .= "a:".$audio_stream_number.",agroup:aac,language:".$language."-".$audio_stream_number."_".$current_abitrate.",name:aac_".$audio_stream_number++."_".$current_abitrate."k,default:".$default." ";
            $default = "no"; // Set default to "no" after the first audio stream
            $seen_abitrates[] = $current_abitrate; // Track seen bitrates
        }
    }

    // Build video options
    for ($i = 0; $i < $nb_renditions; $i++) {
        $options .= "v:".$i.",agroup:aac,name:".$settings[$qualities[$i]]["height"]."p_".$settings[$qualities[$i]]["vbitrate"];
        if ($i < $nb_renditions - 1) {
            $options .= " ";
        }
    }

    return trim($options); // Return the final options string
}

function getFileInfo($hls_path, $filename) {
    $file = $hls_path . "/" . $filename . "/state.txt";
    if (file_exists($file)) {
        $content = json_decode(file_get_contents($file), true);
        $title = isset($content["title"]) ? $content["title"] : "Unknown";
        $subtitle = isset($content["subtitle"]) ? $content["subtitle"] : "";
    } else {
        $title = "Unknown";
        $subtitle = "";
    }

    return [
        'title' => $title,
        'subtitle' => $subtitle,
        'extension' => "Unknown",
        'dirname' => "Unknown"
    ];
}

function remove_duplicates($array) {
    $result = array();
    foreach ($array as $key => $value) {
        if (!isset($result[$value])) {
            $result[$value] = $key;
        }
    }
    $unique_array = array();
    foreach ($result as $value => $key) {
        $unique_array[$key] = $value;
    }
    return $unique_array;
}

$file_list = scandir($hls_path);
$file_list[] = $_REQUEST["filename"];

$ids = array();
$names = array();
foreach ($file_list as $file) {
    $fn = pathinfo($file, PATHINFO_FILENAME);
    preg_match('/^(\d*)_(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $fn, $filedetails);
    if (isset($filedetails[1])) {
        $chanid = $filedetails[1];
        if ($chanid) {
            $starttime = sprintf("%04d-%02d-%02d %02d:%02d:%02d", $filedetails[2], $filedetails[3], $filedetails[4], $filedetails[5], $filedetails[6], $filedetails[7]);
            $datetime = new DateTime($starttime);
            $recorded = get_recorded($datetime->format(DateTime::ATOM), $chanid);
            $title = '';
            $subtitle = '';
            $extension = '';
            $dirname = '';
            $title_subtitle = '';

            if (!empty($recorded['Title'])) {
                // File is available in MythTV
                $title = $recorded['Title'];
                $subtitle = $recorded['SubTitle'];
                if ($_REQUEST["filename"] === pathinfo($recorded['FileName'], PATHINFO_FILENAME)) {
                    $storagegroup_dirs = get_storagegroup_dirs($recorded['StorageGroup'], $recorded['HostName']);
                    foreach ($storagegroup_dirs as $storagegroup) {
                        if (file_exists($storagegroup . "/" . $recorded['FileName'])) {
                            $extension = pathinfo($recorded['FileName'], PATHINFO_EXTENSION);
                            $dirname = $storagegroup;
                            $title_subtitle = $title . ($subtitle ? " - $subtitle" : "");
                            break;
                        }
                    }
                }
            } else {
                // File is not available in MythTV
                $fileInfo = getFileInfo($hls_path, $fn);
                $title = $fileInfo['title'];
                $subtitle = $fileInfo['subtitle'];
                if ($_REQUEST["filename"] === $fn) {
                    $extension = $fileInfo['extension'];
                    $dirname = $fileInfo['dirname'];
                    $title_subtitle = $title . ($subtitle ? " - $subtitle" : "");
                }
            }

            if (empty($title_subtitle)) {
                $title_subtitle = $title . ($subtitle ? " - $subtitle" : "");
            }

            $names[$fn] = $title . ($subtitle ? " - $subtitle" : "");
        }
    }
}

$file_list = remove_duplicates($file_list);

$select_box = "<form><select onChange=\"window.location.href='index.php?filename='+this.value;\">\n";
$current_value = $_GET['filename'] ?? '';
foreach ($file_list as $file) {
    $fn = pathinfo($file, PATHINFO_FILENAME);
    preg_match_all('/^(\d*)_(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $fn, $filedetails);

    if (isset($filedetails[1][0])) {
        $chanid = $filedetails[1][0];
        $year = $filedetails[2][0];
        $month = $filedetails[3][0];
        $day = $filedetails[4][0];
        $selected = $current_value == $fn ? 'selected' : '';
        if (array_key_exists($fn, $names)) {
            // File is available in MythTV thus title is known
            $select_box .= "            <option value=\"".$fn."\" $selected>".$names[$fn]." (".$month."/".$day."/".$year.")</option>\n";
        } else {
            // File is not available in MythTV attempt to retrieve title from 'state.txt'
            $fileInfo = getFileInfo($hls_path, $fn);
            if (!empty($fileInfo['subtitle'])) {
                $locsubtitle = "_{$fileInfo['subtitle']}";
            } else {
                $locsubtitle = '';
            }
            if ($fileInfo) {
                $select_box .= "<option value=\"$fn\" $selected>{$fileInfo['title']}{$locsubtitle} ($month/$day/$year)</option>\n";
            } else {
                $select_box .= "<option value=\"$fn\" $selected>Unknown Title ($month/$day/$year)</option>\n";
            }
        }
    }
}
$select_box .= "          </select></form>\n";

$hw_box = "<br>";
$hw_box .= "<label for=\"hwaccel\">HW acceleration: </label><select class=\"select\" name=\"hw\" required>";
$hw_box .= "<option value=\"\" disabled hidden>-- Please choose your HW Acceleration --</option>";
     foreach ($hwaccels as $hwaccel => $hwaccelset)
     {
         $hw_box .= "            <option value=\"".$hwaccel."\"".(($hwaccel === "h264")?" selected=\"selected\"":"").
                                ">".$hwaccel."".
                                "</option>\n";
     }
$hw_box .= "</select>";

if (strpos($title_subtitle, ' - ') !== false) {
    list($title, $subtitle) = explode(' - ',  $title_subtitle);
    $title = trim($title);
    $subtitle = trim($subtitle);
} else {
    $title = trim($title_subtitle);
    $subtitle = '';
}

$filename = $_REQUEST["filename"];

$paths_to_check = array(
    $dirname."/".$filename.".$extension",
    $vod_path."/".$filename."/master_vod.m3u8",
    $hls_path."/".$filename."/master_event.m3u8",
    $hls_path."/".$filename."/".$filename.".mp4",
    $hls_path."/".$filename."/".$filename." - ".$title_subtitle.".mp4",
    $live_path."/".$filename."/master_live.m3u8"
);

$file_exists = false;
foreach ($paths_to_check as $path) {
    if (file_exists($path)) {
        $file_exists = true;
        break;
    }
}

if ($file_exists)
{
    if (isset($_REQUEST['action']) && $_REQUEST["action"] === "delete")
    {
        // delete hls and vod files
        if (file_exists($hls_path."/".$filename))
        {
            // Shut down all and kill dead screens
            $commands = array(
                "/usr/bin/sudo /usr/bin/screen -ls {$filename}_remux  | /usr/bin/grep -E '\s+[0-9]+.' | /usr/bin/awk '{print $1}' - | while read s; do /usr/bin/sudo /usr/bin/screen -XS \$s quit; done",
                "/usr/bin/sudo /usr/bin/screen -ls {$filename}_encode | /usr/bin/grep -E '\s+[0-9]+.' | /usr/bin/awk '{print $1}' - | while read s; do /usr/bin/sudo /usr/bin/screen -XS \$s quit; done",
                "/usr/bin/sudo /usr/bin/sudo /usr/bin/screen -wipe"
            );

            foreach ($commands as $command) {
                shell_exec($command);
            }
            // // Delete files
            $paths_to_delete = array(
                $hls_path."/".$filename."/*.log*",
                $hls_path."/".$filename."/*.sh*",
                $hls_path."/".$filename."/*.m4s*",
                $hls_path."/".$filename."/".$filename." - ".$title_subtitle.".mp4",
                $hls_path."/".$filename."/video.mp4",
                $hls_path."/".$filename."/init*.mp4",
                $hls_path."/".$filename."/*.mp4",
                $hls_path."/".$filename."/*.txt*",
                $hls_path."/".$filename."/*.vtt",
                $hls_path."/".$filename."/sub.m3u8",
                $hls_path."/".$filename."/sub_0.m3u8",
                $hls_path."/".$filename."/sub_0_vtt.m3u8",
                $hls_path."/".$filename."/master_event.m3u8",
                $hls_path."/".$filename."/stream_event_*.m3u8",
                $vod_path."/".$filename."/*.mpd",
                $vod_path."/".$filename."/init.mp4",
                $vod_path."/".$filename."/*.vtt",
                $vod_path."/".$filename."/sub_0.m3u8",
                $vod_path."/".$filename."/sub_0_vtt.m3u8",
                $vod_path."/".$filename."/sub.m3u8",
                $vod_path."/".$filename."/manifest_vod*.mp4*",
                $vod_path."/".$filename."/master_vod.m3u8",
                $vod_path."/".$filename."/media_*.m3u8",
                $live_path."/".$filename."/*.mp4",
                $live_path."/".$filename."/*.m4s",
                $live_path."/".$filename."/*.vtt",
                $live_path."/".$filename."/sub_0.m3u8",
                $live_path."/".$filename."/sub_0_vtt.m3u8",
                $live_path."/".$filename."/sub.m3u8",
                $live_path."/".$filename."/stream_live_*.m3u8",
                $live_path."/".$filename."/manifest_live*.mp4*",
                $live_path."/".$filename."/master_live.m3u8",
            );

            foreach ($paths_to_delete as $path) {
                array_map('unlink', glob($path));
            }

            rmdir($hls_path."/".$filename);

            if (is_dir($live_path."/".$filename)) {
                rmdir($live_path."/".$filename);
            }

            if (is_dir($vod_path."/".$filename) && $extension != "mp4") {
                rmdir($vod_path."/".$filename);
            }
        }
        if (file_exists($hls_path."/".$filename.".mp4")) {
            unlink($hls_path."/".$filename.".mp4");
        }
        echo "<html><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"><head><title>Video Deleted</title></head><body>".$select_box."<h2>Video Deleted</h2></html>";
    }
    else if (isset($_REQUEST['action']) && $_REQUEST["action"] === "clean")
    {
        // If both HLS and VOD files are available, delete the HLS files only.
        // The VOD files remain on disk and can still be played.
        if (file_exists($hls_path."/".$filename))
        {
            // Shut down all screens
            $commands = array(
                "/usr/bin/sudo /usr/bin/screen -ls {$filename}_encode | /usr/bin/grep -E '\s+[0-9]+\.' | /usr/bin/awk '{print $1}' - | while read s; do /usr/bin/sudo /usr/bin/screen -XS \$s quit; done",
                "/usr/bin/sudo /usr/bin/screen -ls {$filename}_remux  | /usr/bin/grep -E '\s+[0-9]+\.' | /usr/bin/awk '{print $1}' - | while read s; do /usr/bin/sudo /usr/bin/screen -XS \$s quit; done",
                "/usr/bin/sudo /usr/bin/screen -wipe"
            );

            foreach ($commands as $command) {
                shell_exec($command);
            }

            // Delete files
            $files_to_delete = array(
                "*.log*",
                "*.sh*",
                "*.vtt",
                "*.m4s*",
                "init_*.mp4",
                "video.mp4",
                "sub.m3u8",
                "master_event.m3u8",
                "stream_event_*.m3u8"
            );

            foreach ($files_to_delete as $file) {
                array_map('unlink', glob($hls_path."/".$filename."/".$file));
            }
        }
        if (file_exists($hls_path."/".$filename.".mp4"))
        {
            unlink($hls_path."/".$filename.".mp4");
        }
        header("Location: /mythtv-stream-hls-dash/index.php?filename=".$filename);
    }
    else if (isset($_REQUEST['action']) && $_REQUEST["action"] === "status")
    {
        $status = array();
        if (file_exists($hls_path."/".$filename."/status.txt"))
        {
            $status["status"] = file($hls_path."/".$filename."/status.txt");
        }
        if (file_exists($hls_path."/".$filename."/video.mp4"))
        {
            $status["remuxBytesDone"] = filesize($hls_path."/".$filename."/video.mp4");
            $status["remuxBytesTotal"] = filesize($dirname."/".$filename.".".$extension);
        }
        if (file_exists($hls_path."/".$filename."/progress-log.txt"))
        {
            $file = $hls_path."/".$filename."/state.txt";
            $content = json_decode(file_get_contents($file), TRUE);
            $framerate = $content["framerate"] ?? null;
            $length = $content["length"] ?? null;
            // TODO: would be nice to replace these shell commands with php
            // TODO: adapt number 23 into a search from the end of the file, it may go wrong in case of may renditions no progress number is shown.
            $frameNumber = shell_exec("/usr/bin/sudo -u".$webuser." /usr/bin/tail -n 23 ".$hls_path."/".$filename."/progress-log.txt | sudo -u".$webuser." /usr/bin/sed -n '/^frame=/p' | sudo -u".$webuser." sed -n 's/frame=//p'");
            if (isset($content["removecut"]) && $content["removecut"] === "on")
            {
                $status["presentationDuration"] = (int) $content["clippedlength"];
            }
            else {
                $status["presentationDuration"] = (int) $length;
            }
            $status["available"] = $frameNumber / $framerate;
        }
        else if ($extension ===  "mp4")
        {
            $status["available"] = 100;
        }
        else
        {
            $status["available"] = -1;
        }
        echo json_encode($status, JSON_PRETTY_PRINT);
    }
    else if (isset($_REQUEST["do"]))
    {
        $required_files = array(
            $vod_path."/".$filename."/master_vod.m3u8",
            $hls_path."/".$filename."/".$filename." - ".$title_subtitle.".mp4",
            $hls_path."/".$filename.".mp4",
            $hls_path."/".$filename."/master_event.m3u8",
            $live_path."/".$filename."/master_live.m3u8"
        );

        // Encode
        if ($extension === "mp4" && !file_exists($hls_path."/".$filename.".".$extension))
        {
            symlink($dirname."/".$filename.".".$extension, $hls_path."/".$filename.".".$extension);
        }
        else if (!array_reduce($required_files, function ($carry, $file) {
               return $carry || file_exists($file);
             }, false))
        {
            $file = $hls_path."/".$filename."/state.txt";
            $content = json_decode(file_get_contents($file), TRUE);

            $framerate = $content["framerate"] ?? null;
            $length = $content["length"] ?? null;
            $height = $content["height"] ?? null;
            $language = $content["language"] ?? null;
            $languagename = $content["languagename"] ?? null;
            $stream = $content["stream"] ?? null;

            $mustencode = false;
            $fileinput = "";

            // Fetch any cut marks
            preg_match_all('/^(\d*)_(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/',$filename,$filedetails);

            $recordingDetails = array(
                "chanid" => $filedetails[1][0],
                "year" => $filedetails[2][0],
                "month" => $filedetails[3][0],
                "day" => $filedetails[4][0],
                "hour" => $filedetails[5][0],
                "minute" => $filedetails[6][0],
                "second" => $filedetails[7][0],
            );

            $starttime = sprintf("%s-%s-%s %s:%s:%s", $recordingDetails["year"], $recordingDetails["month"], $recordingDetails["day"], $recordingDetails["hour"], $recordingDetails["minute"], $recordingDetails["second"]);

            $datetime = new DateTime($starttime);
            $currentDateTime = new DateTime();
            $interval = $currentDateTime->diff($datetime);
            if ($interval->h < 12 && $interval->days == 0) {
                // The given DateTime is within the last 12 hours, recordings don't take this long
                $program_status = get_program_status(date("Y-m-d\TH:i:s\Z",strtotime($starttime)), $recordingDetails["chanid"], $title);
            }
            else
            {
                // Set the status on unknown without checking
                $program_status = "-16";
            }
            if (isset($program_status) && $program_status === "-2")
            {
                // Still recording read input at native frame rate
                $read_rate = "-re";
            }
            else
            {
                $read_rate = "";
            }

            if ($extension === "avi")
            {
                $fileinput = "-i ".$hls_path."/".$filename."/video.mp4";
                $cut = "uncut";
                $mustencode = true;
            }
            else if (ISSET($_REQUEST["removecut"]) and $_REQUEST["removecut"]==="on" and $_REQUEST["cutcount"] > 0)
            {
                $fileinput = "-f concat -async 1 -safe 0 -i ".$hls_path."/".$filename."/cutlist.txt";
                $cut = "cut";
                $mustencode = true;
                $content = json_decode(file_get_contents($file), TRUE);
                $content["removecut"] = "on";
                file_put_contents($file, json_encode($content, JSON_PRETTY_PRINT));
            }
            else
            {
                $fileinput = "-i \"".$dirname."/".$filename.".".$extension."\"";
                $cut = "uncut";
            }
            # Write encode script (just for cleanup, if no encode necessary)
            $fp = fopen($hls_path."/".$filename."/encode.sh", "w");
            fwrite($fp, "cd ".$hls_path."/".$filename."\n");
            if ($mustencode)
            {
                // Transmuxing from one container/format to mp4 – without re-encoding:
                $remuxCommand = "/usr/bin/sudo /usr/bin/screen -S {$filename}_remux -dm /usr/bin/sudo -u{$webuser} /usr/bin/bash -c '/usr/bin/echo `date`: remux start > {$hls_path}/{$filename}/status.txt;
/usr/bin/sudo -u{$webuser} {$ffmpeg} \
  -y \
  {$hwaccels[$_REQUEST["hw"]]["hwaccel"]} \
  -txt_format text -txt_page 888 \
  -fix_sub_duration \
  -i \"{$dirname}/{$filename}.{$extension}\" \
  -c copy \\";
                if (isset($_REQUEST["checkbox_subtitles"]))
                {
                    $remuxCommand = $remuxCommand . "
  -c:s mov_text \\";
                }
                $remuxCommand = $remuxCommand . "
{$hls_path}/{$filename}/video.mp4 && \
/usr/bin/echo `date`: remux finish success >> {$hls_path}/{$filename}/status.txt || \
/usr/bin/echo `date`: remux finish failed >> {$hls_path}/{$filename}/status.txt'";

                $waitCommand = "while [ ! \"`/usr/bin/cat {$hls_path}/{$filename}/status.txt | /usr/bin/grep 'remux finish success'`\" ] ; \
do \
    sleep 1; \
done";

                fwrite($fp, $remuxCommand . "\n");
                fwrite($fp, $waitCommand . "\n");
            }
            $hls_playlist_type = "";
            if (isset($_REQUEST["hls_playlist_type"]))
            {
                $hls_playlist_type = $_REQUEST["hls_playlist_type"][0];
            }
            else
            {
                $hls_playlist_type = "undefined";
            }

            if (isset($program_status['Status']) && $program_status['Status'] === "-2")
            {
                // Still recording read input at native frame rate
                $read_rate = "-re";
            }

            // NOTE: this hls dir contains meta data, thus should always exist
            $create_hls_dir  = "/usr/bin/sudo -u".$webuser." /usr/bin/mkdir -p ".$hls_path."/".$filename.";";
            $create_live_dir = "";
            $create_vod_dir  = "";
            $option_hls  = "/dev/null";
            $option_live = "/dev/null";
            $option_vod  = "/dev/null";
            $option_mp4  = "/dev/null";
            $sub_mapping = "";
            $sub_format  = "";
            $nb_renditions = 0;
            for ($i=0; $i < count($settings); $i++)
            {
                if (isset($_REQUEST["quality"][$i]))
                {
                    $nb_renditions++;
                }
            }
            if (isset($_REQUEST["checkbox_subtitles"]))
            {
                $sub_mapping = "-map 0:s:".$stream." -c:s webvtt -metadata:s:s:0 language=".$language."";
                $sub_format = "-txt_format text -txt_page 888";
            }
            if ($hls_playlist_type === "live")
            {
                $read_rate = "-re";
                $create_live_dir = "/usr/bin/sudo -u".$webuser." /usr/bin/mkdir -p ".$live_path."/".$filename.";";
                $option_live  = "[select=\'";
                $option_live .= generateStreamOptions($settings, $_REQUEST["quality"], $nb_renditions, true);
                $option_live  .= "\': \
          f=hls: \
          hls_time=2: \
          hls_list_size=10: \
          hls_flags=+independent_segments+iframes_only+delete_segments: \
          hls_segment_type=fmp4: \
          var_stream_map=\'";
                $option_live .= generateMediaStreamOptions($settings, $_REQUEST["quality"], $nb_renditions, $language);
                $option_live .= "\\': \\\n          master_pl_name=master_live.m3u8: \
          hls_segment_filename=../$livedir/$filename/stream_live_%v_data%02d.m4s]../$livedir/$filename/stream_live_%v.m3u8";
                if (isset($_REQUEST["checkbox_subtitles"]))
                {
                    // hls_segment_filename is written to /dev/null since the m4s output is not required, video is just used to sync the subtitle segments
                    // as of now it looks like this trick can only used for one subtitle...
                    $option_live .= "| \
         [select=\'v:0,s:0\': \
          strftime=1: \
          f=hls: \
          hls_flags=+independent_segments+delete_segments+program_date_time: \
          hls_time=2: \
          hls_list_size=10: \
          hls_segment_type=fmp4: \
          var_stream_map=\'v:0,s:0,sgroup:subtitle\': \
          hls_segment_filename=\'/dev/null\']../$livedir/$filename/sub_%v.m3u8";
                    $master_file = "$live_path/$filename/master_live.m3u8";
                    // This command is delayed until master_live.m3u8 is created by FFmpeg!!!
                    // NOTE: Add subtitles
                    fwrite($fp, "(while [ ! -f \"".$master_file."\" ] ;
 do
        /usr/bin/inotifywait -e close_write --include \"master_".$hls_playlist_type.".m3u8\" ".$live_path."/".$filename.";
 done;
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\\1\\n#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subtitles\",NAME=\"".$languagename."\",DEFAULT=YES,FORCED=NO,AUTOSELECT=YES,URI=\"sub_0_vtt.m3u8\",LANGUAGE=\"".$language."\"/' ".$master_file.";
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-STREAM.*)/\\1,SUBTITLES=\"subtitles\"/' ".$master_file.";
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -r '/(#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+\,CODECS)/{N;d;}' -i ".$master_file.";) & \n");
                } else
                {
                    $master_file = "$live_path/$filename/master_live.m3u8";
                    // This command is delayed until master_live.m3u8 is created by FFmpeg!!!
                    fwrite($fp, "(while [ ! -f \"".$master_file."\" ] ;
 do
        /usr/bin/inotifywait -e close_write --include \"master_".$hls_playlist_type.".m3u8\" ".$live_path."/".$filename.";
 done;
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+\,.*)/\\1,CLOSED-CAPTIONS=NONE/' ".$master_file.";) & \n");

                }
            }
            if ($hls_playlist_type === "event")
            {
                $option_hls  = "[select=\'";
                $option_hls .= generateStreamOptions($settings, $_REQUEST["quality"], $nb_renditions, true);
                $option_hls  .= "\': \
          f=hls: \
          hls_time=2: \
          hls_playlist_type=event: \
          hls_flags=+independent_segments+iframes_only: \
          hls_segment_type=fmp4: \
          var_stream_map=\'";
                $option_hls .= generateMediaStreamOptions($settings, $_REQUEST["quality"], $nb_renditions, $language);
                $option_hls .= "\\': \
          master_pl_name=master_event.m3u8: \
          hls_segment_filename=$filename/stream_event_%v_data%02d.m4s]$filename/stream_event_%v.m3u8";
                if (isset($_REQUEST["checkbox_subtitles"]))
                {
                    // hls_segment_filename is written to /dev/null since the m4s output is not required, video is just used to sync the subtitle segments
                    // as of now it looks like this trick can only used for one subtitle...
                    $option_hls .= "| \
         [select=\'v:0,s:0\': \
          strftime=1: \
          f=hls: \
          hls_flags=+independent_segments+program_date_time: \
          hls_time=2: \
          hls_playlist_type=event: \
          hls_segment_type=fmp4: \
          var_stream_map=\'v:0,s:0,sgroup:subtitle\': \
          hls_segment_filename=\'/dev/null\']$filename/sub_%v.m3u8";
                    $master_file = "$hls_path/$filename/master_event.m3u8";
                    // This command is delayed until master_event.m3u8 is created by FFmpeg!!!
                    // NOTE: Start playing the video at the beginning.
                    // NOTE: Add subtitles
                    fwrite($fp, "(while [ ! -f \"".$master_file."\" ] ;
 do
        /usr/bin/inotifywait -e close_write --include \"master_".$hls_playlist_type.".m3u8\"  ".$hls_path."/".$filename.";
 done;
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\\1\\n#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subtitles\",NAME=\"".$languagename."\",DEFAULT=YES,FORCED=NO,AUTOSELECT=YES,URI=\"sub_0_vtt.m3u8\",LANGUAGE=\"".$language."\"/' ".$master_file.";
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\\1\\n#EXT-X-START:TIME-OFFSET=0/' ".$master_file.";
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-STREAM.*)/\\1,SUBTITLES=\"subtitles\"/'  ".$master_file.";
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -r '/(#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+\,CODECS)/{N;d;}' -i ".$master_file.";) & \n");
                }
                else
                {
                    $master_file = "$hls_path/$filename/master_event.m3u8";
                    // This command is delayed until master_event.m3u8 is created by FFmpeg!!!
                    // NOTE: Start playing the video at the beginning.
                    fwrite($fp, "(while [ ! -f \"".$master_file."\" ] ;
 do
        /usr/bin/inotifywait -e close_write --include \"master_".$hls_playlist_type.".m3u8\" ".$hls_path."/".$filename.";
 done;
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+\,.*)/\\1,CLOSED-CAPTIONS=NONE/' ".$master_file.";
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\\1\\n#EXT-X-START:TIME-OFFSET=0/' ".$master_file.";) & \n");

                }
            }
            if (isset($_REQUEST["vod"]))
            {
                $create_vod_dir = "/usr/bin/sudo -u".$webuser." /usr/bin/mkdir -p ".$vod_path."/".$filename.";";
                $option_vod  = "[select=\'";
                $option_vod .= generateStreamOptions($settings, $_REQUEST["quality"], $nb_renditions, true);
                $option_vod  .= "\': \
          f=dash: \
          seg_duration=2: \
          hls_playlist=true: \
          single_file=true: \
          adaptation_sets=\'id=0,streams=a id=1,streams=v\' : \
          media_seg_name=\'stream_vod_\$RepresentationID\$-\$Number%05d\$.\$ext\$\': \
          hls_master_name=master_vod.m3u8]../".$voddir."/".$filename."/manifest_vod.mpd";
                $master_file = "".$vod_path."/".$filename."/master_vod.m3u8";
                if (isset($_REQUEST["checkbox_subtitles"]))
                {
                    // hls event is used here to segment the subtitles, adding subtitle "streams" to dash is not implemented in FFmpeg
                    // hls_segment_filename is written to /dev/null since the m4s output is not required, video is just used to sync the subtitle segments
                    // as of now it looks like this trick can only used for one subtitle...
                    $option_vod .= "| \
         [select=\'v:0,s:0\': \
          strftime=1: \
          hls_flags=+independent_segments+iframes_only: \
          hls_time=2: \
          hls_playlist_type=event: \
          hls_segment_type=fmp4: \
          var_stream_map=\'v:0,s:0,sgroup:subtitle\': \
          hls_segment_filename=\'/dev/null\']../$voddir/".$filename."/sub_%v.m3u8";
                    // NOTE: Start playing the video at the beginning.
                    // NOTE: Correct for FFmpeg bug?: even though $mapping uses -metadata:s:a:".$i." language=$language
                    // NOTE: the language setting is not written to the master_vod.m3u8 file.
                    // NOTE: The execution of this command is delayed, till the master file is created later in time by FFmpeg!!!
                    fwrite($fp, "(while [ ! -f \"".$master_file."\" ] ;
 do
        /usr/bin/inotifywait -e close_write --include \"master_vod.m3u8\" ".$vod_path."/".$filename.";
 done;
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\\1\\n#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subtitles\",NAME=\"".$languagename."\",DEFAULT=YES,FORCED=NO,AUTOSELECT=YES,URI=\"sub_0_vtt.m3u8\",LANGUAGE=\"".$language."\"/' ".$master_file.";
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\\1\\n#EXT-X-START:TIME-OFFSET=0/' ".$master_file.";
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-STREAM.*)/\\1,SUBTITLES=\"subtitles\"/' ".$master_file.";");
                    $audio_stream_number= 0;
                    $offset = 5;
                    for ($i=0; $i < $nb_renditions; $i++)
                    {
                        $bool_new_audio = true;
                        $current_abitrate = $settings[$_REQUEST["quality"][$i]]["abitrate"];
                        for ($j=0; $j < $i; $j++)
                        {
                            if ($settings[$_REQUEST["quality"][$j]]["abitrate"] === $current_abitrate)
                            {
                                // seen abitrate before
                                $bool_new_audio = false;
                            }
                        }
                        if ($bool_new_audio)
                        {
                            $linenumber = $offset + $audio_stream_number;
                            fwrite($fp, " \
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E '".$linenumber."s/(#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"group_A1\")/\\1,LANGUAGE=\"".$language."-".$audio_stream_number++."_".$current_abitrate."\"/' ".$master_file.";");
                        }
                    }
                    fwrite($fp, ") & \n");
                }
                else
                {
                    // NOTE: Start playing the video at the beginning.
                    // NOTE: Correct for FFmpeg bug?: even though $mapping uses -metadata:s:a:".$i." language=$language
                    // NOTE: the language setting is not written to the master_vod.m3u8 file.
                    // NOTE: The execution of this command is delayed, till the master file is created later in time by FFmpeg!!!
                    fwrite($fp, "(while [ ! -f \"".$master_file."\" ] ;
 do
        /usr/bin/inotifywait -e close_write --include \"master_vod.m3u8\" ".$vod_path."/".$filename.";
 done;
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+\,.*)/\\1,CLOSED-CAPTIONS=NONE/' ".$master_file.";
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\\1\\n#EXT-X-START:TIME-OFFSET=0/' ".$master_file.";");
                    $audio_stream_number= 0;
                    $offset = 4;
                    for ($i=0; $i < $nb_renditions; $i++)
                    {
                        $bool_new_audio = true;
                        $current_abitrate = $settings[$_REQUEST["quality"][$i]]["abitrate"];
                        for ($j=0; $j < $i; $j++)
                        {
                            if ($settings[$_REQUEST["quality"][$j]]["abitrate"] === $current_abitrate)
                            {
                                // seen abitrate before
                                $bool_new_audio = false;
                            }
                        }
                        if ($bool_new_audio)
                        {
                            $linenumber = $offset + $audio_stream_number;
                            fwrite($fp, " \
    /usr/bin/sudo -u".$webuser." /usr/bin/sed -i -E '".$linenumber."s/(#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"group_A1\")/\\1,LANGUAGE=\"".$language."-".$audio_stream_number++."_".$current_abitrate."\"/' ".$master_file.";");
                        }
                    }
                    fwrite($fp, ") & \n");
                }
            }
            if(isset($_REQUEST["mp4"]))
            {
                $option_mp4 = "[select=\'v:0,a:0\': \
          f=mp4: \
          movflags=+faststart]".$filename."/".$filename." - ".$title_subtitle.".mp4";
                if (isset($_REQUEST["checkbox_subtitles"]))
                {
                    $option_mp4 .= "| \
          [select=\'s:0\']".$filename."/subtitles.vtt";
                }
            }
            if ($extension === "avi")
            {
                // no hwaccel supported for avi
                $hwaccel = "";
                $scale = "scale";
                $library = "libx264";
            }
            else
            {
                // hwaccel supported encode
                $hwaccel = $hwaccels[$_REQUEST["hw"]]["hwaccel"];
                $scale =   $hwaccels[$_REQUEST["hw"]]["scale"];
                $library = $hwaccels[$_REQUEST["hw"]]["encoder"];
            }
            $filter_complex = "-filter_complex \"[0:v]";
            for ($i=0; $i < $nb_renditions; $i++)
            {
                $vout = "v".$i;
                if ($i === 0)
                {
                    if ($nb_renditions > 1)
                    {
                        $filter_complex .= "split=$nb_renditions";
                        for ($j=0; $j < $nb_renditions; $j++)
                        {
                            $v = "v".$j;
                            $filter_complex .= "[".++$v."]";
                        }
                        $filter_complex .= ";[".++$vout."]";
                        $filter_complex .= "".$scale."=w=".$settings[$_REQUEST["quality"][$i]]["width"].":h=".$settings[$_REQUEST["quality"][$i]]["height"]."[".$vout."out]";
                    }
                    else
                    {
                        $filter_complex .= "".$scale."=w=".$settings[$_REQUEST["quality"][$i]]["width"].":h=".$settings[$_REQUEST["quality"][$i]]["height"]."[".++$vout."out]";
                    }
                }
                else
                {
                    $filter_complex .= ";[".++$vout."]".$scale."=w=".$settings[$_REQUEST["quality"][$i]]["width"].":h=".$settings[$_REQUEST["quality"][$i]]["height"]."[".$vout."out]";
                }
            }
        $filter_complex .= "\" \\\n";
        for ($i=0; $i < $nb_renditions; $i++)
        {
            $vout = "v".$i;
            $filter_complex .= "    -map [".++$vout."out] -c:v:$i \
        ".$library." \
        -b:v:$i ".$settings[$_REQUEST["quality"][$i]]["vbitrate"]."k \
        -maxrate:v:$i ".$settings[$_REQUEST["quality"][$i]]["vbitrate"]."k \
        -bufsize:v:$i 1.5*".$settings[$_REQUEST["quality"][$i]]["vbitrate"]."k \
        -crf 23 \
        -preset veryfast \
        -g 48 \
        -keyint_min 48 \
        -sc_threshold 0 \
        -flags +global_header \\\n";
        }
        $mapping = "";
        $audio_stream_number = 0;
        for ($i=0; $i < $nb_renditions; $i++)
        {
            $bool_new_abitrate = true;
            $current_abitrate = $settings[$_REQUEST["quality"][$i]]["abitrate"];
            for ($j=0; $j < $i; $j++)
            {
                if ($settings[$_REQUEST["quality"][$j]]["abitrate"] === $current_abitrate)
                {
                    // seen abitrate before
                    $bool_new_abitrate = false;
                }
            }
            if ($bool_new_abitrate)
            {
                $mapping .= "    -map a:0 -c:a:".$audio_stream_number." aac -b:a:".$audio_stream_number." ".$current_abitrate."k -ac 2 \
        -metadata:s:a:".$audio_stream_number++." language=".$language." \\\n";
            }
        }
        fwrite($fp, "/usr/bin/sudo -u".$webuser." /usr/bin/bash -c '/usr/bin/echo `date`: encode start >> ".$hls_path."/".$filename."/status.txt';
".$create_vod_dir."
".$create_live_dir."
".$create_hls_dir."
cd ".$hls_path."/;
/usr/bin/sudo -u".$webuser." ".$ffmpeg." \
    -fix_sub_duration \
    ".$sub_format." \
    ".$hwaccel." \
    ".$read_rate." \
    ".$fileinput." \
    -progress ".$filename."/progress-log.txt \
    -live_start_index 0 \
    -tune film \
    -metadata title=\"".$title_subtitle."\" \
    -force_key_frames \"expr:gte(t,n_forced*2)\" \
    ".$filter_complex." \
".$mapping." \
    ".$sub_mapping." \
    -f tee \
        \"".$option_vod."| \
          ".$option_mp4."| \
          ".$option_live."| \
          ".$option_hls."\" \
2>>/tmp/ffmpeg-".$hlsdir."-".$filename.".log && \
/usr/bin/sudo -u".$webuser." /usr/bin/bash -c '/usr/bin/echo `date`: encode finish success >> ".$hls_path."/".$filename."/status.txt' || \
/usr/bin/sudo -u".$webuser." /usr/bin/bash -c '/usr/bin/echo `date`: encode finish failed >> ".$hls_path."/".$filename."/status.txt'\n");
            if (isset($_REQUEST["checkbox_subtitles"]) && isset($_REQUEST["mp4"]))
            {
                // post processing: add subtitles to mp4 file
                fwrite($fp, "while [ ! \"`/usr/bin/cat ".$hls_path."/".$filename."/status.txt | /usr/bin/grep 'encode finish success'`\" ] ;
do
    sleep 1;
done\n");
                fwrite($fp, "cd ".$hls_path."/".$filename.";
/usr/bin/sudo -u".$webuser." /usr/bin/bash -c '/usr/bin/echo `date`: subtitle_merge start >> ".$hls_path."/".$filename."/status.txt';
cd ".$hls_path."/".$filename.";
/usr/bin/sudo -u".$webuser." ".$ffmpeg." \
    -i \"".$filename." - ".$title_subtitle.".mp4\" \
    -i subtitles.vtt \
    -c:s mov_text -metadata:s:s:0 language=".$language." -disposition:s:0 default \
    -c:v copy \
    -c:a copy \
    \"".$filename." - ".$title_subtitle.".tmp.mp4\" \
2>>/tmp/ffmpeg-subtitle-merge-".$hlsdir."-".$filename.".log && \
/usr/bin/sudo -u".$webuser." /usr/bin/bash -c '/usr/bin/echo `date`: subtitle_merge success >> ".$hls_path."/".$filename."/status.txt' || \
/usr/bin/sudo -u".$webuser." /usr/bin/bash -c '/usr/bin/echo `date`: subtitle_merge failed >> ".$hls_path."/".$filename."/status.txt';
/usr/bin/sudo /usr/bin/mv -f \"".$filename." - ".$title_subtitle.".tmp.mp4\" \"".$filename." - ".$title_subtitle.".mp4\" \n");
       	    }
            if ($mustencode)
            {
                fwrite($fp, "while [ ! \"`/usr/bin/cat ".$hls_path."/".$filename."/status.txt | /usr/bin/grep 'encode finish success'`\" ] ;
do
    sleep 1;
done\n");
                fwrite($fp, "/usr/bin/sudo /usr/bin/rm ".$hls_path."/".$filename."/video.mp4\n");
            }
            fwrite($fp, "sleep 3 && /usr/bin/sudo /usr/bin/screen -ls ".$filename."_encode  | /usr/bin/grep -E '\s+[0-9]+.' | /usr/bin/awk '{print $1}' - | while read s; do /usr/bin/sudo /usr/bin/screen -XS \$s quit; done\n");
            fclose($fp);

            $response = shell_exec("/usr/bin/sudo /usr/bin/chmod a+x ".$hls_path."/".$filename."/encode.sh");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_encode -dm /bin/bash");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_encode -X eval 'chdir ".$hls_path."/".$filename."'");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_encode -X logfile '".$hls_path."/".$filename."/encode.log'");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_encode -X log on");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_encode -X stuff '".$hls_path."/".$filename."/encode.sh\n'");
        }
        ?>

        <!DOCTYPE html>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <html>
        <head><title>DASH and HLS fMP4 Video Player</title>
        <style>
          #liveButtonId { display: none;
                          visibility: hidden; }
          #dashVodButtonId { display:none;
                             visibility:hidden; }
          #hlsVodButtonId { display:none;
                            visibility:hidden; }
          #linkButtonId { display:none;
                          visibility:hidden; }
          #eventButtonId { display:none;
                           visibility:hidden; }
          #mp4ButtonId { display:none;
                         visibility:hidden; }
        </style>
        <!-- Load the Shaka Player library. -->
        <script src="../dist/shaka-player.compiled.js"></script>
        <!-- Shaka Player ui compiled library: -->
        <script src="../dist/shaka-player.ui.js"></script>
        <!-- Shaka Player ui compiled library default CSS: -->
        <link rel="stylesheet" type="text/css" href="../dist/controls.css">
        <script defer src="https://www.gstatic.com/cv/js/sender/v1/cast_sender.js"></script>
        <script>
          var manifestUri = "";
          var statusInterval = null;
          var filename = "<?php echo $filename; ?>";
          var playerInitDone = false;
          var currentStatus = "";
          var message_string = "";
          var extension = "<?php echo $extension; ?>";
          var continueChecking = true;
          let startTime = Date.now();
          const timeFrame = 60000; // 60 seconds
          let buttonsInitialized = false; // Flag to track button initialization

          navigator.sayswho = (function(){
              var ua= navigator.userAgent;
              var tem;
              var M= ua.match(/(opera|chrome|safari|firefox|msie|trident(?=\/))\/?\s*(\d+)/i) || [];
              if(/trident/i.test(M[1])){
                  tem= /\brv[ :]+(\d+)/g.exec(ua) || [];
                  return 'IE '+(tem[1] || '');
              }
              if(M[1]=== 'Chrome'){
                  tem= ua.match(/\b(OPR|Edge)\/(\d+)/);
                  if(tem!= null) return tem.slice(1).join(' ').replace('OPR', 'Opera');
              }
              M= M[2]? [M[1], M[2]]: [navigator.appName, navigator.appVersion, '-?'];
              if((tem= ua.match(/version\/(\d+)/i))!= null) M.splice(1, 1, tem[1]);
              return M.join(' ');
          })();

          function checkFileExists(url) {
              var xhr = new XMLHttpRequest();
              xhr.open('HEAD', url, false);
              xhr.send();
              return xhr.status === 200;
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
                          continueChecking = false;
                      }
                  }
              }
              if (!message)
              {
                  if (status["available"] >= 0 &&
                      currentStatus.indexOf("encode start") >= 0 &&
                      currentStatus.indexOf("encode finish success") < 0)
                  {
                      message = "Generating Video "+Math.ceil(status["available"] / status["presentationDuration"] * 100).toString()+"% - ";
                      var secs = Math.floor(status["available"]);
                      if (secs > 3600)
                      {
                          message = message + Math.floor(secs / 3600) + ":";
                          secs -= (Math.floor(secs / 3600) * 3600);
                      }
                      message = message + pad(Math.floor(secs / 60)) + ":";
                      secs -= (Math.floor(secs / 60) * 60);
                      message = message + pad(Math.floor(secs));

                      message = message + " available";
                      // NOTE: 24 seconds is equal to 6x segment size is just an empirical guess
                      if (!playerInitDone && Math.ceil(status["available"] > 24))
                      {
                          playerInitDone = initPlayer();
                      }
                  }
                  else if (status['available'] ==  '100')
                  {
                      message = "MP4 Video Available";
                      continueChecking = false;
                      if (!playerInitDone)
                      {
                          playerInitDone = initPlayer();
                      }
                  }
                  else if (currentStatus.indexOf("encode finish success") > 0)
                  {
                      message = message_string;
                      continueChecking = false;
                      if (!playerInitDone)
                      {
                          playerInitDone = initPlayer();
                      }
                  }
                  else if (status["remuxBytesDone"])
                  {
                      message = "Remuxing Video "+(Math.ceil(status["remuxBytesDone"] / status["remuxBytesTotal"] * 20)*5).toString()+"%";
                  }
                  else if (extension === "mp4")
                  {
                      message = message_string;
                      if (!playerInitDone)
                      {
                          playerInitDone = initPlayer();
                      }
                  }
                  else if (!playerInitDone)
                  {
                      message = message_string;
                  }
              }

              document.getElementById("statusbutton").value = message;

              // Function to check the status of files
              const checkFiles = () => {
                  // Array of file checks and corresponding actions
                  const fileChecks = [
                      {
                          check: () => checkFileExists("../<?php echo $voddir; ?>/<?php echo $filename; ?>/manifest_vod.mpd"),
                              buttonId: "dashVodButtonId",
                              action: () => activateButton("dashVodButtonId", "../<?php echo $voddir; ?>/<?php echo $filename; ?>/manifest_vod.mpd", "DASH VOD Available")
                              },
                      {
                          check: () => checkFileExists("../<?php echo $voddir; ?>/<?php echo $filename; ?>/master_vod.m3u8"),
                              buttonId: "hlsVodButtonId",
                              action: () => activateButton("hlsVodButtonId", "../<?php echo $voddir; ?>/<?php echo $filename; ?>/master_vod.m3u8", "HLS VOD Available")
                              },
                      {
                          check: () => extension === "mp4" && checkFileExists("../<?php echo $hlsdir; ?>/<?php echo $filename; ?>.mp4"),
                              buttonId: "linkButtonId",
                              action: () => {
                              var linkButtonId = document.getElementById("linkButtonId");
                              linkButtonId.style.display = 'block';
                              linkButtonId.style.visibility = 'visible';
                              linkButtonId.addEventListener("click", function() {
                                  var url = "http://<?php echo $yourserver; ?>/<?php echo $hlsdir ?>/<?php echo $filename; ?>.mp4";
                                  download(url);
                              }, false);
                              linkButtonId.value = "Linked MP4 Available"; // Set the button's message
                          }
                      },
                      {
                          check: () => checkFileExists("../<?php echo $hlsdir; ?>/<?php echo $filename; ?>/master_event.m3u8"),
                              buttonId: "eventButtonId",
                              action: () => {
                              activateButton("eventButtonId", "../<?php echo $hlsdir; ?>/<?php echo $filename; ?>/master_event.m3u8", "HLS Available");
                              deactivateButton("liveButtonId"); // Ensure mutual exclusivity
                          }
                      },
                      {
                          check: () => checkFileExists("../<?php echo $livedir; ?>/<?php echo $filename; ?>/master_live.m3u8"),
                              buttonId: "liveButtonId",
                              action: () => {
                              activateButton("liveButtonId", "../<?php echo $livedir; ?>/<?php echo $filename; ?>/master_live.m3u8", "LIVE Available");
                              deactivateButton("eventButtonId"); // Ensure mutual exclusivity
                          }
                      },
                      {
                          check: () => checkFileExists("../<?php echo $hlsdir; ?>/<?php echo $filename; ?>/master_event.m3u8") &&
                              checkFileExists("../<?php echo $voddir; ?>/<?php echo $filename; ?>/master_vod.m3u8") &&
                              currentStatus.indexOf("encode finish success") >= 0,
                              buttonId: "cleanupEventId",
                              action: () => activateButton("cleanupEventId", null, "Cleanup Event Available")
                      },
                      {
                          check: () => checkFileExists("../<?php echo $hlsdir; ?>/<?php echo $filename; ?>/<?php echo $filename; ?> - <?php echo rawurlencode($title_subtitle); ?>.mp4") &&
                              currentStatus.indexOf("encode finish success") >= 0,
                              buttonId: "mp4ButtonId",
                              action: () => {
                              var linkButtonId = document.getElementById("mp4ButtonId");
                              linkButtonId.style.display = 'block';
                              linkButtonId.style.visibility = 'visible';
                              linkButtonId.addEventListener("click", function() {
                                  var url = "http://<?php echo $yourserver; ?>/hls/<?php echo $filename; ?>/<?php echo $filename; ?> - <?php echo rawurlencode($title_subtitle); ?>.mp4";
                                  download(url);
                              }, false);
                              linkButtonId.value = "MP4 Video Available"; // Set the button's message
                          }
                      },
                  ];

                  // Check each file and execute corresponding actions
                  for (const fileCheck of fileChecks) {
                      if (fileCheck.check()) {
                          fileCheck.action(); // Execute the action to activate the button
                      }
                  }
              };

              // Function to activate a button
              const activateButton = (buttonId, url, message) => {
                  var button = document.getElementById(buttonId);
                  button.style.display = 'block';
                  button.style.visibility = 'visible';
                  if (url) {
                      button.setAttribute('onclick', `window.location.href='${url}'; this.setAttribute('download', '');`);
                  }
                  if (message) {
                      button.value = message; // Set the button's value or text to the message
                  }
              };

              // Function to deactivate a button
              const deactivateButton = (buttonId) => {
                  var button = document.getElementById(buttonId);
                  button.style.display = 'none';
                  button.style.visibility = 'hidden';
              };

              // Initialize buttons only once
              if (!buttonsInitialized) {
                  const buttonIds = [
                      "dashVodButtonId",
                      "hlsVodButtonId",
                      "linkButtonId",
                      "eventButtonId",
                      "liveButtonId",
                      "cleanupEventId",
                      "mp4ButtonId"
                  ];
                  buttonIds.forEach(deactivateButton); // Deactivate all buttons initially
                  buttonsInitialized = true; // Set the flag to true after initialization
              }

              // Check the elapsed time
              const elapsedTime = Date.now() - startTime;

              // Call the checkFiles function to perform the checks
              if (elapsedTime < timeFrame) {
                  // If within the first 60 seconds, check all files
                  checkFiles();
              } else {
                  // After 60 seconds, only check specific files
                  const limitedFileChecks = [
                      {
                          check: () => checkFileExists("../<?php echo $hlsdir; ?>/<?php echo $filename; ?>/master_event.m3u8") &&
                              checkFileExists("../<?php echo $voddir; ?>/<?php echo $filename; ?>/master_vod.m3u8") &&
                              currentStatus.indexOf("encode finish success") >= 0,
                              buttonId: "cleanupEventId",
                              action: () => activateButton("cleanupEventId", null, "Cleanup Event Available")
                      },
                      {
                          check: () => checkFileExists("../<?php echo $hlsdir; ?>/<?php echo $filename; ?>/<?php echo $filename; ?> - <?php echo rawurlencode($title_subtitle); ?>.mp4") &&
                              currentStatus.indexOf("encode finish success") >= 0,
                              buttonId: "mp4ButtonId",
                              action: () => {
                              var linkButtonId = document.getElementById("mp4ButtonId");
                              linkButtonId.style.display = 'block';
                              linkButtonId.style.visibility = 'visible';
                              linkButtonId.addEventListener("click", function() {
                                  var url = "http://<?php echo $yourserver; ?>/hls/<?php echo $filename; ?>/<?php echo $filename; ?> - <?php echo rawurlencode($title_subtitle); ?>.mp4";
                                  download(url);
                              }, false);
                              linkButtonId.value = "MP4 Video Available"; // Set the button's message
                          }
                      }
                  ];

                  // Check each limited file and execute corresponding actions
                  for (const fileCheck of limitedFileChecks) {
                      if (fileCheck.check()) {
                          fileCheck.action(); // Execute the action to activate the button
                      }
                  }
              }

              if (!continueChecking) {
                  clearInterval(statusInterval);
              }
          };

          function copyToClipboard(url) {
              window.prompt("Copy to clipboard: Ctrl+C, Enter", url);
          }

          function download(url) {
              //creating an invisible element
              var element = document.createElement('a');
              element.download = "";
              element.href = url;
              document.body.appendChild(element);
              element.click();
              document.body.removeChild(element);
              delete element;
          }

          function checkStatus()
          {
              var oReq = new XMLHttpRequest();
              var newHandle = function(event) { handle(event, myArgument); };
              oReq.addEventListener("load", checkStatusListener);
              oReq.open("GET", "index.php?filename="+filename+"&action=status");
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
            var fileExists = checkFileExists("../<?php echo $voddir; ?>/<?php echo $filename; ?>/manifest_vod.mpd");

            if (fileExists && navigator.sayswho.match(/\bEdge\/(\d+)/)) {
                // Play DASH on Windows Edge browser
                manifestUri = "../<?php echo $voddir; ?>/<?php echo $filename; ?>/manifest_vod.mpd";
            } else if (fileExists) {
                // Play VOD stream
                manifestUri = "../<?php echo $voddir; ?>/<?php echo $filename; ?>/master_vod.m3u8";
            } else if (checkFileExists("../<?php echo $hlsdir; ?>/<?php echo $filename; ?>/master_event.m3u8")) {
                // Play HLS event stream
                manifestUri = "../<?php echo $hlsdir; ?>/<?php echo $filename; ?>/master_event.m3u8";
            } else if (checkFileExists("../<?php echo $livedir; ?>/<?php echo $filename; ?>/master_live.m3u8")) {
                // Play live stream
                manifestUri = "../<?php echo $livedir; ?>/<?php echo $filename; ?>/master_live.m3u8";
            } else if (checkFileExists("../<?php echo $hlsdir; ?>/<?php echo $filename; ?>/<?php echo $filename; ?> - <?php echo rawurlencode($title_subtitle); ?>.mp4") &&
                       currentStatus.indexOf("encode finish success") >= 0) {
                // Play MP4 file
                manifestUri = "../<?php echo $hlsdir; ?>/<?php echo $filename; ?>/<?php echo $filename; ?> - <?php echo rawurlencode($title_subtitle); ?>.mp4";
            } else if (extension === "mp4") {
                // Play existing mp4, no encoding required
                manifestUri = "../<?php echo $hlsdir; ?>/<?php echo $filename; ?>.mp4";
            } else {
                    return false;
            }

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
            controls.addEventListener('caststatuschanged', onCastStatusChanged);

            basicKeyboardShortcuts();

            // Try to load a manifest.
            // This is an asynchronous process.
            try {
                // await does not work on Edge
                // await player.load(manifestUri);
                player.load(manifestUri);
                // This runs if the asynchronous load is successful.
                console.log('The video has now been loaded!');
                         video.requestFullscreen().catch(err => {
                                      console.log(err)
                                             });
            } catch (error) {
                onPlayerError(error);
            }

            return true;
          }

          function basicKeyboardShortcuts () {
            document.addEventListener('keydown', (e) => {
                    const videoContainer = document.querySelector('video');
                    let is_fullscreen = () => !!document.fullscreenElement
                              let audio_vol = video.volume;
                    if (e.key == 'f') {
                        if (is_fullscreen()) {
                            document.exitFullscreen();
                        } else {
                            videoContainer.requestFullscreen();
                        }
                        e.preventDefault();
                    }
                    else if (e.key == ' ') {
                        if (video.paused) {
                            video.play();
                        } else {
                            video.pause();
                        }
                        e.preventDefault();
                    }
                    else if (e.key == "ArrowUp") {
                        e.preventDefault();
                        if (audio_vol != 1) {
                            try {
                                video.volume = audio_vol + 0.05;
                            }
                            catch (err) {
                                video.volume = 1;
                            }
                        }
                    }
                    else if (e.key == "ArrowDown") {
                        e.preventDefault();
                        if (audio_vol != 0) {
                            try {
                                video.volume = audio_vol - 0.05;
                            }
                            catch (err) {
                                video.volume = 0;
                            }
                        }
                    }
                });
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

          document.addEventListener('DOMContentLoaded', initApp);
          // Listen to the custom shaka-ui-load-failed event, in case Shaka Player fails
          // to load (e.g. due to lack of browser support).
          document.addEventListener('shaka-ui-load-failed', initFailed);
        </script>
        </head>
        <body>
        <?php echo $select_box; ?>
        <table cellspacing="10">
          <tr>
            <td>
              <form action="index.php" method="GET" onSubmit="return confirm('Are you sure you want to delete the video file?');">
                <input type="hidden" name="filename" value="<?php echo $filename; ?>">
                <input type="hidden" name="action" value="delete">
                <input type="submit" value="Delete Video Files">
              </form>
            </td>
            <td valign="top">
              <form>
                <input type="button" onclick="showStatus();" id="statusbutton" value="Loading...">
              </form>
            </td>
            <td>
              <form action="index.php" method="GET" onSubmit="return confirm('Are you sure you want to delete the video file?');">
                <input type="hidden" name="filename" value="<?php echo $filename; ?>">
                <input type="hidden" name="action" value="clean">
                <input type="submit" style="display: none; visibility: hidden;" id="cleanupEventId" value="Cleanup Video Files">
              </form>
            </td>
          </tr>
        </table>
        <table cellspacing="10"
          <tr>
            <td>
              <a href='./shutdownlock.php' target='_blank' rel="noopener noreferrer"><button type="button">Shutdown Lock</button></a>
            </td>
            <td>
              <input type="button" style="display: none; visibility: hidden;" id="linkButtonId" value="Video link" />
            </td>
            <td>
              <input type="button" style="display: none; visibility: hidden;" id="eventButtonId" value="HLS event" />
            </td>
            <td>
              <input type="button" style="display: none; visibility: hidden;" id="liveButtonId" value="LIVE" />
            </td>
            <td>
              <input type="button" style="display: none; visibililty: hidden;" id="hlsVodButtonId" value="HLS VOD" />
            </td>
            <td>
              <input type="button" style="display: none; visibilily: hidden;" id="dashVodButtonId" value="DASH VOD" />
            </td>
            <td>
              <input type="button" style="display: none; visibilily: hidden;" id="mp4ButtonId" value="Download MP4" />
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
        if (file_exists($dirname."/".$filename.".$extension") && $extension !== "mp4")
        {
            if (!is_dir($hls_path."/".$filename))
            {
                mkdir($hls_path."/".$filename);
            }
            $file = $hls_path."/".$filename."/state.txt";
            $cutcount = 0;
            if (file_exists($file))
            {
                // (partial) state.txt available
                $content = json_decode(file_get_contents($file), TRUE);
                $framerate = $content["framerate"] ?? null;
                $length = $content["length"] ?? null;
                // Check if title and subtitle are defined
                if (!isset($content["title"])) {
                    if (isset($title)) {
                        $content["title"] = $title;
                    }
                    else {
                        $content["title"] = "Default Title";
                    }
                }
                if (!isset($content["subtitle"])) {
                    if (isset($subtitle)) {
                        $content["subtitle"] = $subtitle;
                    }
                    else {
                        $content["subtitle"] = "Default Subtitle";
                    }
                }
                $jsonContent = json_encode($content, JSON_PRETTY_PRINT);
                file_put_contents($file, $jsonContent);
            }
            else
            {
                // no state.txt available
                $length = 0;
                $framerate = 0;
                // Get mediainfo
                $mediainfo = shell_exec("/usr/bin/mediainfo \"--Output=General; Duration : %Duration/String%\r
Video; Width : %Width% pixels\r Height : %Height% pixels\r Frame rate : %FrameRate/String%\r
Text; Format : %Format% Sub : %Language/String%\r\n\" \"".$dirname."/".$filename.".$extension"."\"");
                preg_match_all('/Duration[ ]*:( (\d*) h)?( (\d*) min)?( (\d*) s)?/',$mediainfo,$durationdetails);
                if ($durationdetails[1][0])
                {
                    $length += ((int) $durationdetails[2][0]) * 3600;
                }
                if ($durationdetails[3][0])
                {
                    $length += ((int) $durationdetails[4][0]) * 60;
                }
                if ($durationdetails[5][0])
                {
                    $length += ((int) $durationdetails[6][0]);
                }
                preg_match_all('/Height[ ]*: (\d*[ ]?\d*) pixels/',$mediainfo,$heightdetails);
                $height = ((int) str_replace(" ", "", $heightdetails[1][0]));
                preg_match_all('/Frame rate[ ]*: (\d*\.?\d*) (.*?)FPS/',$mediainfo,$ratedetails);
                if(isset($ratedetails[1][0])) {
                    if ($ratedetails[1][0] == "") {
                        $framerate = (float)25.0;
                    } else {
                        $framerate = ((double) $ratedetails[1][0]);
                    }
                }
                $language = "";
                $languagename = "";
                $stream = "-1";
                preg_match_all('/Format[ ]*:[ ]+([a-zA-Z\-8]+|[a-zA-Z]+[ ][a-zA-Z]+)[ ]+Sub[ ]*: ([a-zA-Z]+)/',$mediainfo,$subtitlesfound);
                $formatsub = array();
                foreach ($subtitlesfound[1] as $key => $value){
                    $formatsub[] = array_merge((array)$subtitlesfound[2][$key], (array)$value);
                }
                foreach ($sublangpref as $prefkey => $prefvalue) {
                    foreach ($formatsub as $key => $value) {
                        if ($prefvalue['name'] == $value[0] && ($value[1] == "ASS" || $value[1] == "UTF-8" || $value[1] == "Teletext Subtitle"))
                        {
                            $language = $prefkey;
                            $languagename = $prefvalue["name"];
                            $stream = $key;
                            break 2;
                        }
                    }
                }
                if ($language === "")
                {
                    // if no language is found assume the first preferred language
                    $language = array_key_first($sublangpref);
                    $languagename = $sublangpref[array_key_first($sublangpref)]["name"];
                }
                $state = [
                    "framerate" => $framerate,
                    "length" => $length,
                    "height" => $height,
                    "language" => $language,
                    "languagename" => $languagename,
                    "stream" => $stream,
                    "title" => $title,
                    "subtitle" => $subtitle,
                ];

                // Fetch any cut marks
                preg_match('/^(\d*)_(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/',$filename,$filedetails);

                list($chanid, $year, $month, $day, $hour, $minute, $second) = array_slice($filedetails, 1);

                $starttime="$year-$month-$day $hour:$minute:$second";

                $fp = fopen($hls_path."/".$filename."/cutlist.txt", "w");
                fprintf($fp, "ffconcat version 1.0\n");
                $clippedlength = 0;
                $datetime = new DateTime($starttime);
                $currentDateTime = new DateTime();
                $interval = $currentDateTime->diff($datetime);
                if ($interval->h < 12 && $interval->days == 0) {
                    // The given DateTime is within the last 12 hours
                    $program_status = get_program_status(date("Y-m-d\TH:i:s\Z",strtotime($starttime)), $chanid, $title);
                }
                else
                {
                    // Set the status on unknown without checking, assumption recordings don't last more than 12 hours...
                    $program_status = "-16";
                }
                if ((isset($program_status) && $program_status !== "-2"))
                {
                    // Made sure we are not still recording
                    $recorded = get_recorded($datetime->format(DateTime::ATOM), $chanid);
                    $recorded_markup = get_recorded_markup($recorded['RecordedId']);

                    $firstrow = true;
                    $midsegment = false;
                    $startsegment = 0;
                    foreach ($recorded_markup as $markup => $value) {
                        $cutcount++;
                        $mark = (double)$value / $framerate;
                        if ($markup === 'CUT_START_' . $cutcount)
                        {
                            if ($firstrow && $mark > 1)
                            {
                                fprintf($fp, "file ".$hls_path."/".$filename."/video.mp4\n");
                                fprintf($fp, "inpoint 0\n");
                                fprintf($fp, "outpoint %0.2f\n", $mark);
                                $clippedlength = $mark + 1;
                            }
                            else if ($midsegment)
                            {
                                fprintf($fp, "outpoint %0.2f\n", $mark);
                                $midsegment = false;
                                $clippedlength += ($mark - $startsegment) + 1;
                            }
                        }
                        else if ($markup === 'CUT_END_' . $cutcount)
                        {
                            if ($length - $mark > 10)
                            {
                                fprintf($fp, "file ".$hls_path."/".$filename."/video.mp4\n");
                                fprintf($fp, "inpoint %0.2f\n", $mark);
                                $midsegment = true;
                                $startsegment = $mark;
                            }
                        }
                        $firstrow = false;
                        if ($midsegment)
                        {
                            fprintf($fp, "outpoint %0.2f\n", $length);
                            $clippedlength += (($length - $startsegment) > 0 ? ($length - $startsegment) : 0) + 1;
                        }
                    }
                }
                fclose($fp);
                if ($clippedlength >= 0)
                {
                    $state["clippedlength"] = $clippedlength;
                }
                $content = json_encode($state, JSON_PRETTY_PRINT);
                file_put_contents($file, $content);
            }
        }
            ?>
            <html>
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <head><title>Select Video Settings</title></head>
            <body>
<?php echo $select_box; ?>
            <form name="FC" action="index.php" method="GET">
            <input type="hidden" name="filename" value="<?php echo $filename; ?>">
<?php
        $paths_to_check = [
            $dirname . "/" . $filename . ".mp4",
            $vod_path . "/" . $filename . "/master_vod.m3u8",
            $hls_path . "/" . $filename . "/master_event.m3u8",
            $dirname . "/" . $filename . ".mp4",
            $hls_path . "/" . $filename . "/video.mp4",
            $hls_path . "/" . $filename . "/" . $filename . " - " . $title_subtitle . ".mp4",
            $live_path . "/" . $filename . "/master_live.m3u8"
        ];

        $file_found = false;
        foreach ($paths_to_check as $path) {
            if (file_exists($path)) {
                $file_found = true;
                break;
            }
        }

        if ($file_found)
        {
            // ready for streaming
            ?>
            <br>
            <input type="submit" name="do" value="Watch Video">
            <?php
        }
        else
        {
            // encoding required
            ?>
            <h2>Select the settings appropriate for your connection:</h2>
            <label for="quality">Adaptive Bitrate Streaming (ABR): </label>
            <select name="quality[]" size=4 multiple required>
            <option value="" disabled hidden>-- Use Ctrl-Click, Command-Click and Shift-Click to compose ABR --</option>
            <?php
                $content = json_decode(file_get_contents($file), TRUE);
                $height = $content["height"];
                $clippedlength = $content["clippedlength"];
                foreach ($settings as $setting => $settingset)
                {
                    // TODO: remove hack adding 300, need to be even higher?
                    if ($settingset["height"] <= $height + 300)
                    {
                        echo "            <option value=\"".$setting."\"".((strpos($setting, "high480") !== false)?" selected=\"selected\"":"").">".preg_replace('/[0-9]+/', '', ucfirst($setting))." Quality ".$settingset["height"]."p</option>\n";
                    }
                }
             ?>
             </select>
             <?php echo $hw_box; ?>
             <br>
            <?php
            if ($cutcount > 0)
            {
             ?>
                <select name="removecut"><option value="on">Cut Commercials (<?php echo $cutcount/2; ?> found)</option><option value="off" selected="selected">Leave Uncut</option></select>
            <?php
            }
            ?>
            <br>
            <?php
            $content = json_decode(file_get_contents($file), TRUE);
            $stream = $content["height"];
            if ($content["stream"] != -1)
            {
            ?>
                   <input type="checkbox" action="" checked='checked' name="checkbox_subtitles" id="agree" value="yes">
                   <label for="agree">Subtitles</label>
                   <br>
            <?php
            }
            ?>
            <input type="hidden" name="clippedlength" value="<?php echo (int)$clippedlength; ?>">
            <input type="hidden" name="cutcount" value="<?php echo (int)$cutcount/2; ?>">
            <input type="checkbox" name="hls_playlist_type[]" value="live" onclick="return KeepCount()" checked='checked' id="option"><label for="option"> live</label>
            <input type="checkbox" name="hls_playlist_type[]" value="event" onclick="return KeepCount()" id="option"><label for="option"> event</label><br>
            <input type="checkbox" name="vod" id="option"><label for="option">vod</label><br>
            <input type="checkbox" name="mp4" id="option"><label for="option">mp4</label><br>
            <input type="submit" name="do" onclick="return ValThisForm();" value="Encode Video">
            <script type="text/javascript">
               function KeepCount()  {
                 var elements = document.getElementsByName("hls_playlist_type[]");
                 var total = 0;
                 for(var i in elements) {
                   var element = elements[i];
                   if(element.checked) total++;
                   if(total>1) {
                       alert("Pick at maximum one of the two: live or event");
                       element.checked = false;
                       return false;
                   }
                 }
               }
               function ValThisForm() {
                   var elements = document.getElementsByName("hls_playlist_type[]");
                   var total = 0;
                   for(var i in elements) {
                       var element = elements[i];
                       if(element.checked) total++;
                   }
                   var chkd = total || document.FC.vod.checked || document.FC.mp4.checked
                   if (chkd === false) {
                      alert ("Pick at least one of the checkboxes: live, event, vod or mp4")
                      return false;
                  }
               }
          </script>
          <?php
        }
        ?>
</form>
        </body>
        </html>
        <?php
    }
}
else
{
    if (file_exists($dirname."".$filename.".$extension"))
    {
        echo "Video file exists, but is not supported.\n";
    }
    else
    {
        echo "File does not exist or permission as ".$webuser." user is denied.\n";
    }
}
?>
