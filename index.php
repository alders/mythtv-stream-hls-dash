<?php
define('MARK_CUT_START',1);
define('MARK_CUT_END',0);

$hls_path = "/var/www/html/hls";
$program_path = "/home/mythtv";

$dbserver = "localhost";
$dbuser = "mythtv";
// Read password from file
$lines = file($program_path."/mythdb.txt");
$dbpass = trim($lines[0]);
$dbname = "mythconverg";

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

function get_key_second_adaptive_bitrate(array $array, $keyindex)
{
    // precondition: at least 4 keys in the settings array
    // return the second bitrate quality for adaptive bitrate
    // the default quality offset in the settings array is three
    // the offset for the lowest three bitrates defined in the settings array
    // is either two, one or minus one (quality is higher than requested by user)
    $keys = array_keys($array);
    $count = count($array);

    if ($count == $keyindex + 1)
    {
        // return second lowest quality (offset minus one)
        // note: in this case the quality returned is higher than selected by the user
        return $keys[$keyindex - 1];
    }
    else if ($count == $keyindex + 2 || $count == $keyindex + 3)
    {
        // return lowest quality in $array (offset two or one)
        return $keys[$count - 1];
    }
    else
    {
        // return lower quality in $array (offset three)
        return $keys[$keyindex + 3];
    }
}

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
    if (!array_key_exists($_REQUEST["quality"], $settings))
    {
        throw new InvalidArgumentException('Invalid quality');
    }
}
if (isset($_REQUEST["clippedlength"]))
{
    if (!ctype_digit($_REQUEST["clippedlength"]))
    {
        throw new InvalidArgumentException('Invalid clippedlength');
    }
}
if (isset($_REQUEST["length"]))
{
    if (!ctype_digit($_REQUEST["length"]))
    {
        throw new InvalidArgumentException('Invalid length');
    }
}

$file_list = scandir($hls_path);
$file_list[] = $_REQUEST["filename"];
$query_parts = array();
$ids = array();
for ($i = 0; $i < count($file_list); $i++)
{
    $fn = explode(".", $file_list[$i])[0];
    if (array_search($fn, $ids) === false)
    {
        $ids[] = $fn;
        preg_match_all('/^(\d*)_(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $fn, $filedetails);
        if (isset($filedetails[1][0])){
           $chanid=$filedetails[1][0];
           if ($chanid)
           {
              $year=$filedetails[2][0];
              $month=$filedetails[3][0];
              $day=$filedetails[4][0];
              $hour=$filedetails[5][0];
              $minute=$filedetails[6][0];
              $second=$filedetails[7][0];
              $starttime="$year-$month-$day $hour:$minute:$second";
              $query_parts[] = "(chanid=".$chanid." and starttime=\"".$starttime."\")";
           }
        }
    }
}

// todo: when the user does removes the file in mythtv, the name in the dropdown list is Unknown
$query_parts_string=implode(" OR ", $query_parts);
$dbconn=mysqli_connect($dbserver,$dbuser,$dbpass);
mysqli_select_db($dbconn,$dbname);
$getnames = sprintf("select title,subtitle,chanid,starttime,basename from recorded where %s;",
                    $query_parts_string);
$result=mysqli_query($dbconn,$getnames);
$names = array();
$extension = "";
$video_path = "";
while ($row = mysqli_fetch_assoc($result))
{
    $starttime = str_replace(":", "", str_replace(" ", "", str_replace("-", "", $row['starttime'])));
    $names[$row['chanid']."_".$starttime] = $row['title'].($row['subtitle'] ? " - ".$row['subtitle'] : "");
    if ($_REQUEST["filename"] == pathinfo($row['basename'], PATHINFO_FILENAME))
    {
        $extension = pathinfo($row['basename'], PATHINFO_EXTENSION);
        $get_storage_dirs = sprintf("select dirname from storagegroup where groupname=\"Default\"");
        $q=mysqli_query($dbconn,$get_storage_dirs);
        while ($row_q = mysqli_fetch_assoc($q))
        {
            if (file_exists($row_q["dirname"]."/".$_REQUEST["filename"].".$extension"))
            {
                $video_path= $row_q["dirname"];
            }
        }
    }
}

$done = array();
$select_box = "<form><select onChange=\"window.location.href='index.php?filename='+this.value;\">";
$file_list = array_reverse($file_list);
for ($i = 0; $i < count($file_list); $i++)
{
    $fn = explode(".", $file_list[$i])[0];
    if (array_search($fn, $done) === false)
    {
        $done[] = $fn;
        preg_match_all('/^(\d*)_(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $fn, $filedetails);

        if (isset($filedetails[1][0]))
        {
           $chanid=$filedetails[1][0];
           if ($chanid)
           {
               $year=$filedetails[2][0];
               $month=$filedetails[3][0];
               $day=$filedetails[4][0];
               $select_box .= "<option value=\"".$fn."\">".(array_key_exists($fn, $names)?$names[$fn]:"Unknown Title")." (".$month."/".$day."/".$year.")</option>";
           }
        }
    }
}
$select_box .= "</select></form>";

if (file_exists($video_path."/".$_REQUEST["filename"].".$extension") ||
    file_exists ($hls_path."/../vod/".$_REQUEST["filename"]."/master_vod.m3u8") ||
    file_exists ($hls_path."/../live/".$_REQUEST["filename"]."/master_live.m3u8") ||
    file_exists ($hls_path."/".$_REQUEST["filename"]."/".$_REQUEST["filename"].".mp4") ||
    file_exists ($hls_path."/".$_REQUEST["filename"]."/master_event.m3u8"))
{
    $filename = $_REQUEST["filename"];
    if (isset($_REQUEST['action']) && $_REQUEST["action"] == "delete")
    {
        // delete hls and vod files
        if (file_exists($hls_path."/".$filename))
        {
            // Shut down all screen sessions
            // $response = shell_exec('/usr/bin/sudo /usr/bin/screen -S '.$filename."_remux  -X quit");
            // $response = shell_exec('/usr/bin/sudo /usr/bin/screen -S '.$filename."_encode -X quit");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -ls ".$filename."_remux  | /usr/bin/grep -E '\s+[0-9]+.' | /usr/bin/awk '{print $1}' - | while read s; do /usr/bin/sudo /usr/bin/screen -XS \$s quit; done");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -ls ".$filename."_encode  | /usr/bin/grep -E '\s+[0-9]+.' | /usr/bin/awk '{print $1}' - | while read s; do /usr/bin/sudo /usr/bin/screen -XS \$s quit; done");
            // kill dead screens
            $response = shell_exec('/usr/bin/sudo /usr/bin/screen -wipe');
            // // Delete files
            array_map('unlink', glob($hls_path."/".$filename."/*.log*"));
            array_map('unlink', glob($hls_path."/".$filename."/*.sh*"));
            array_map('unlink', glob($hls_path."/".$filename."/*.m4s*"));
            array_map('unlink', glob($hls_path."/".$filename."/".$filename.".mp4"));
            array_map('unlink', glob($hls_path."/".$filename."/video.mp4"));
            array_map('unlink', glob($hls_path."/".$filename."/init_*.mp4"));
            array_map('unlink', glob($hls_path."/".$filename."/*.txt*"));
            array_map('unlink', glob($hls_path."/".$filename."/*.vtt"));
            array_map('unlink', glob($hls_path."/".$filename."/sub.m3u8"));
            array_map('unlink', glob($hls_path."/".$filename."/master_event.m3u8"));
            array_map('unlink', glob($hls_path."/".$filename."/stream_event_*.m3u8"));
            array_map('unlink', glob($hls_path."/../vod/".$filename."/*.mpd"));
            array_map('unlink', glob($hls_path."/../vod/".$filename."/*.vtt"));
            array_map('unlink', glob($hls_path."/../vod/".$filename."/sub.m3u8"));
            array_map('unlink', glob($hls_path."/../vod/".$filename."/manifest_vod*.mp4*"));
            array_map('unlink', glob($hls_path."/../vod/".$filename."/master_vod.m3u8"));
            array_map('unlink', glob($hls_path."/../vod/".$filename."/media_*.m3u8"));
            array_map('unlink', glob($hls_path."/../live/".$filename."/*.mp4"));
            array_map('unlink', glob($hls_path."/../live/".$filename."/*.m4s"));
            array_map('unlink', glob($hls_path."/../live/".$filename."/*.vtt"));
            array_map('unlink', glob($hls_path."/../live/".$filename."/sub.m3u8"));
            array_map('unlink', glob($hls_path."/../live/".$filename."/stream_live_*.m3u8"));
            array_map('unlink', glob($hls_path."/../live/".$filename."/manifest_live*.mp4*"));
            array_map('unlink', glob($hls_path."/../live/".$filename."/master_live.m3u8"));
            //array_map('unlink', glob($hls_path."/../live/".$filename."/media_*.m3u8"));
            rmdir($hls_path."/".$filename);
            if (is_dir($hls_path."/../live/".$filename))
            {
                rmdir($hls_path."/../live/".$filename);
            }
            if (is_dir($hls_path."/../vod/".$filename) && $extension != "mp4")
            {
               rmdir($hls_path."/../vod/".$filename);
            }
         }
        if (file_exists($hls_path."/".$filename.".mp4"))
        {
            array_map('unlink', glob($hls_path."/".$filename.".mp4"));
        }
        echo "<html><head><title>Video Deleted</title></head><body>".$select_box."<h2>Video Deleted</h2></html>";
    }
    else if (isset($_REQUEST['action']) && $_REQUEST["action"] == "clean")
    {
        // todo: when the user does not press clean after live streaming, the data in the ramdisk may be cleared without clearing the meta data in the hls folder.
        // clean HLS files only, if available VOD files remain on disk and can still be played
        if (file_exists($hls_path."/".$filename))
        {
            // Shut down all screen sessions
            // $response = shell_exec('/usr/bin/sudo /usr/bin/screen -S '.$filename."_remux  -X quit");
            // $response = shell_exec('/usr/bin/sudo /usr/bin/screen -S '.$filename."_encode -X quit");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -ls ".$filename."_encode  | /usr/bin/grep -E '\s+[0-9]+\.' | /usr/bin/awk '{print $1}' - | while read s; do /usr/bin/sudo /usr/bin/screen -XS \$s quit; done");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -ls ".$filename."_remux  | /usr/bin/grep -E '\s+[0-9]+\.' | /usr/bin/awk '{print $1}' - | while read s; do /usr/bin/sudo /usr/bin/screen -XS \$s quit; done");
            // // Delete files
            array_map('unlink', glob($hls_path."/".$filename."/*.log*"));
            array_map('unlink', glob($hls_path."/".$filename."/*.sh*"));
            array_map('unlink', glob($hls_path."/".$filename."/*.vtt"));
            array_map('unlink', glob($hls_path."/".$filename."/*.m4s*"));
            array_map('unlink', glob($hls_path."/".$filename."/init_*.mp4"));
            array_map('unlink', glob($hls_path."/".$filename."/video.mp4"));
            array_map('unlink', glob($hls_path."/".$filename."/sub.m3u8"));
            array_map('unlink', glob($hls_path."/".$filename."/master_event.m3u8"));
            array_map('unlink', glob($hls_path."/".$filename."/stream_event_*.m3u8"));
        }
        if (file_exists($hls_path."/".$filename.".mp4"))
        {
            array_map('unlink', glob($hls_path."/".$filename.".mp4"));
        }
        header("Location: /dash/index.php?filename=".$filename);
    }
    else if (isset($_REQUEST['action']) && $_REQUEST["action"] == "status")
    {
        $status = array();
        if (file_exists($hls_path."/".$filename."/status.txt"))
        {
            $status["status"] = file($hls_path."/".$filename."/status.txt");
        }
        if (file_exists($hls_path."/".$filename."/video.mp4"))
        {
            $status["remuxBytesDone"] = filesize($hls_path."/".$filename."/video.mp4");
            $status["remuxBytesTotal"] = filesize($video_path."/".$filename.".".$extension);
        }
        if (file_exists($hls_path."/../hls/".$filename."/progress-log.txt"))
        {
            // todo: instead of reading the file again one could use $status["status"]
            $config = file_get_contents("".$hls_path."/".$filename."/status.txt");
            if (!preg_match("/encode finish success/", $config, $matches))
            {
                // at this point the value of $_REQUEST["framerate"] and $_REQUEST["length"] is not available, moreover
                // if $_REQUEST["removecut"]==on remux is required potentially change the framerate
                // at this point the value of $_REQUEST["removecut"] is not available, but can be derived from status.txt
                if (preg_match("/remux finish success/", $config, $matches) && $extension != "avi")
                {
                    // thus $_REQUEST["removecut"]==on
                    $mediainfo = shell_exec("/usr/bin/mediainfo ".$hls_path."/".$filename."/video.mp4");
                    preg_match_all('/Frame rate[ ]*: (\d*\.?\d*) FPS/',$mediainfo,$ratedetails);

                    $link = file_get_contents($hls_path."/".$filename."/cutlist.txt");
                    preg_match_all('/inpoint\s*(\b[0-9]{2,}[.]?[0-9]{1,})/', $link, $incontent, PREG_PATTERN_ORDER);
                    preg_match_all('/outpoint\s*(\b[0-9]{2,}[.]?[0-9]{1,})/', $link, $outcontent, PREG_PATTERN_ORDER);
                    $sum = 0;
                    foreach($outcontent[1] as $k => $v){
                        $sum += $v - $incontent[1][$k];
                    }
                    $status["presentationDuration"] = (int) $sum;
                }
                else
                {
                    // thus $_REQUEST["removecut"]==off or avi in which case the cutlist will be empty
                    $mediainfo = shell_exec("/usr/bin/mediainfo ".$video_path."/".$filename.".$extension");
                    preg_match_all('/Frame rate[ ]*: (\d*\.?\d*) FPS/',$mediainfo,$ratedetails);

                    preg_match_all('/Duration[ ]*:( (\d*) h)?( (\d*) min)?( (\d*) s)?/',$mediainfo,$durationdetails);
                    $length = 0;

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
                    $status["presentationDuration"] = (int) $length;
                }

                if(isset($ratedetails[1][0])) {
                    $framerate = ((double)  $ratedetails[1][0]);
                }
                // todo: would be nice to replace these shell commands bij php
                $frameNumber = shell_exec("/usr/bin/sudo -uapache /usr/bin/tail -n 13 ".$hls_path."/../hls/".$filename."/progress-log.txt | sudo -uapache /usr/bin/sed -n '/^frame=/p' | sudo -uapache sed -n 's/frame=//p'");
                $status["available"] = $frameNumber / $framerate;
            }
        }
        else if ($extension ==  "mp4")
        {
            $status["available"] = 100;
        }
        else
        {
            $status["available"] = -1;
        }
        echo json_encode($status);
    }
    else if (isset($_REQUEST["do"]))
    {
        // Encode
        if ($extension == "mp4")
        {
            // todo: would be nice to replace these shell commands bij php
            $result = shell_exec("/usr/bin/sudo -uapache /usr/bin/ln -s ".$video_path."/".$filename.".".$extension." /var/www/html/hls/");
        }
        else if (!file_exists($hls_path."/../vod/".$filename."/master_vod.m3u8") &&
                 !file_exists($hls_path."/".$filename."/".$filename.".mp4") &&
                 !file_exists($hls_path."/".$filename."/video.mp4") &&
                 !file_exists($hls_path."/".$filename."/master_event.m3u8") &&
                 !file_exists($hls_path."/../live/".$filename."/master_live.m3u8"))
        {
            $mustencode = false;
            $fileinput = "";
            if ($extension == "avi")
            {
                $fileinput = "-i ".$hls_path."/".$filename."/video.mp4";
                $length = (int) $_REQUEST["length"];
                $cut = "uncut";
                $mustencode = true;
            }
            else if ($_REQUEST["removecut"]=="on" and $_REQUEST["cutcount"] > 0)
            {
                $fileinput = "-f concat -async 1 -safe 0 -i ".$hls_path."/".$filename."/cutlist.txt";
                $length = (int) $_REQUEST["clippedlength"];
                $cut = "cut";
                $mustencode = true;
            }
            else
            {
                $fileinput = "-i ".$video_path."/".$filename.".".$extension;
                $length = (int) $_REQUEST["length"];
                $cut = "uncut";
            }
            # Write encode script (just for cleanup, if no encode necessary)
            $fp = fopen($hls_path."/".$filename."/encode.sh", "w");
            fwrite($fp, "cd ".$hls_path."/".$filename."\n");

            $VODDIR = "vod";
            $BASE = $filename;
            $HLSDIR = "hls";
            //$STARTTIME= " -ss 00:03:00 -to 00:07:00 ";
            $STARTTIME= "";
            # Start remux if necessary
            if ($mustencode)
            {
                // Launch remux to mp4 container
                $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_remux -dm /usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: remux start > ".$hls_path."/".$filename."/status.txt ; /usr/bin/sudo -uapache /usr/bin/ffmpeg -y -hwaccel vaapi -vaapi_device /dev/dri/renderD128 -hwaccel_output_format vaapi -txt_format text -txt_page 888 -fix_sub_duration -i ".$video_path."/".$filename.".$extension -acodec copy -vcodec copy -scodec mov_text ".$hls_path."/".$filename."/video.mp4 && /usr/bin/echo `date`: remux finish success >> ".$hls_path."/".$filename."/status.txt || /usr/bin/echo `date`: remux finish failed >> ".$hls_path."/".$filename."/status.txt'");
                fwrite($fp, "while [ ! \"`/usr/bin/cat ".$hls_path."/".$filename."/status.txt | /usr/bin/grep 'remux finish success'`\" ] ; do sleep 1; done\n");
            }
            if (isset($_REQUEST["checkbox_subtitles"]))
            {
                // todo: subtitles does not work with a live recording, check if code from hdhomerunstream.php would help here
                $create_live_dir = "";
                $create_vod_dir = "";
                // todo: think about this, hls dir contains the meta data, should always exist
                $create_hls_dir = "/usr/bin/sudo -uapache /usr/bin/mkdir -p /var/www/html/".$HLSDIR."/".$BASE.";";
                $option_live = "/dev/null";
                $option_hls  = "/dev/null";
                $option_vod  = "/dev/null";
                $option_mp4  = "/dev/null";
                if (isset($_REQUEST["hls_playlist_type"]))
                {
                    $hls_playlist_type = $_REQUEST["hls_playlist_type"][0];
                    if ($hls_playlist_type == "live")
                    {
                        // todo: find out whether mythtv is still recording as of v34.0 RecStatus can be used, see https://www.mythtv.org/wiki/Recording_Status
                        $create_live_dir = "/usr/bin/sudo -uapache /usr/bin/mkdir -p /var/www/html/live/".$BASE.";";
                        $option_live = "[f=segment:segment_time=6:segment_list_size=0:segment_format=webvtt:segment_list=../live/".$BASE."/sub.m3u8]../live/".$BASE."/sub-%d.vtt";
                    }
                    if ($hls_playlist_type == "event")
                    {
                        $option_hls = "[f=segment:segment_time=6:segment_list_size=0:segment_format=webvtt:segment_list=".$BASE."/sub.m3u8]".$BASE."/sub-%d.vtt";
                    }
                }
                if (isset($_REQUEST["vod"]))
                {
                    $create_vod_dir = "/usr/bin/sudo -uapache /usr/bin/mkdir -p /var/www/html/".$VODDIR."/".$BASE.";";
                    $option_vod = "[f=segment:segment_time=6:segment_list_size=0:segment_format=webvtt:segment_list=../".$VODDIR."/".$BASE."/sub.m3u8]../".$VODDIR."/".$BASE."/sub-%d.vtt";
                }
                if (isset($_REQUEST["mp4"]))
                {
                    $option_mp4 = "[select=\'s:0,sgroup:subtitle\']".$BASE."/subtitles.vtt";
                }
                // Due to bugs in ffmpeg subtitles need to be processed first (cannot be done in a singe tee together with audio and video scaling).
                fwrite($fp, "/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: subtitle_extract start >> ".$hls_path."/".$filename."/status.txt'; ".$create_live_dir." ".$create_vod_dir." ".$create_hls_dir." cd /var/www/html/".$HLSDIR."/; /usr/bin/sudo -uapache /usr/bin/ffmpeg -fix_sub_duration -hwaccel vaapi -vaapi_device /dev/dri/renderD128 -hwaccel_output_format vaapi -txt_format text -txt_page 888 ".$fileinput." -map 0:s:0 -c:s webvtt ".$STARTTIME." -f tee \"".$option_live."|".$option_hls."|".$option_mp4."|".$option_vod."\" 2>>/tmp/ffmpeg-subtitle-extract-".$HLSDIR."-".$BASE.".log && /usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: subtitle_extract success >> ".$hls_path."/".$filename."/status.txt' || /usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: subtitle_extract failed >> ".$hls_path."/".$filename."/status.txt'\n");
                fwrite($fp, "while [ ! \"`/usr/bin/cat ".$hls_path."/".$filename."/status.txt | /usr/bin/grep 'subtitle_extract success'`\" ] ; do sleep 1; done\n");
                if (isset($_REQUEST["hls_playlist_type"]))
                {
                    // post processing hls_playlist_type live and event
                    $hls_playlist_type = $_REQUEST["hls_playlist_type"][0];
                    if ($hls_playlist_type == "live")
                    {
                        $dir = "/var/www/html/live/".$BASE."/master_live.m3u8";
                        // This command need to be delayed, till the master file is created by ffmpeg!!!
                        fwrite($fp, "(while [ ! -f \"".$dir."\" ] ; do /usr/bin/inotifywait -e close_write --include \"master_".$hls_playlist_type.".m3u8\" /var/www/html/".$HLSDIR."/../live/".$BASE."; done;/usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\\1\\n#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subtitles\",NAME=\"Dutch\",DEFAULT=YES,FORCED=NO,AUTOSELECT=YES,URI=\"sub.m3u8\",LANGUAGE=\"dut\"/' ".$dir."; /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-STREAM.*)/\\1,SUBTITLES=\"subtitles\"/' ".$dir."; /usr/bin/sudo -uapache /usr/bin/sed -e :a -e '\$d;N;2,6ba' -e 'P;D' -i ".$dir.";) & ");
                    }
                    else
                    {
                        // thus hls_playlist_type == "event"
                        $dir = "/var/www/html/".$HLSDIR."/".$BASE."/master_event.m3u8";
                        // This command need to be delayed, till the master file is created by ffmpeg!!!
                        fwrite($fp, "(while [ ! -f \"".$dir."\" ] ; do /usr/bin/inotifywait -e close_write --include \"master_".$hls_playlist_type.".m3u8\" /var/www/html/".$HLSDIR."/".$BASE."; done;/usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\\1\\n#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subtitles\",NAME=\"Dutch\",DEFAULT=YES,FORCED=NO,AUTOSELECT=YES,URI=\"sub.m3u8\",LANGUAGE=\"dut\"/' ".$dir."; /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-STREAM.*)/\\1,SUBTITLES=\"subtitles\"/'  ".$dir."; /usr/bin/sudo -uapache /usr/bin/sed -e :a -e '\$d;N;2,6ba' -e 'P;D' -i ".$dir.";) & ");
                    }
                }
            }
            if (isset($_REQUEST["vod"]))
            {
                $dir = "/var/www/html/".$VODDIR."/".$BASE."/master_vod.m3u8";
                // correct for ffmpeg bug: $option_vod uses -metadata:s:a:0 language=dut (note: there can be only one language per adaptation_sets), for master_vod.m3u8 the metadata language is ignored
                // todo: make language configurable
                // This command need to be delayed, till the master file is created by ffmpeg!!!
                fwrite($fp, "(while [ ! -f \"".$dir."\" ] ; do /usr/bin/inotifywait -e close_write --include \"master_vod.m3u8\" /var/www/html/".$VODDIR."/".$BASE."; done; /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"group_A1\")/\\1,LANGUAGE=\"dut\"/' ".$dir.";) & ");
            }
            $lowbitrate = get_key_second_adaptive_bitrate($settings, array_search($_REQUEST["quality"], $keys));
            $read_rate = "";
            $create_live_dir = "";
            // todo: think about this hls dir contains meta data, thus should always exist
            $create_hls_dir = "/usr/bin/sudo -uapache /usr/bin/mkdir -p /var/www/html/".$HLSDIR."/".$BASE.";";
            $option_live = "/dev/null";
            $option_hls  = "/dev/null";
            $option_mp4  = "/dev/null";
            if (isset($_REQUEST["hls_playlist_type"]))
            {
                $hls_playlist_type = $_REQUEST["hls_playlist_type"][0];
                // todo: limit checkbox selection
                // todo: make live an option
                if ($hls_playlist_type == "live")
                {
                    // todo: make language configurable
                    // read input at native frame rate
                    $read_rate = "-re";
                    $create_live_dir = "/usr/bin/sudo -uapache /usr/bin/mkdir -p /var/www/html/".$HLSDIR."/../live/".$BASE.";";
                    $option_live = "[select=\'a:0,a:1,v:0,v:1\':f=hls:hls_time=6:hls_list_size=10:hls_flags=+independent_segments+iframes_only+delete_segments:hls_segment_type=fmp4:var_stream_map=\'v:0,agroup:aac,language:dut,name:".$settings[$_REQUEST["quality"]]["height"]."p v:1,agroup:aac,language:dut,name:".$settings[$lowbitrate]["height"]."p a:0,agroup:aac,language:dut,name:aac_1_96k a:1,agroup:aac,language:dut,name:aac_2_".$settings[$_REQUEST["quality"]]["abitrate"]."K\':master_pl_name=master_live.m3u8:hls_segment_filename=../live/".$BASE."/stream_live_%v_data%02d.m4s]../live/".$BASE."/stream_live_%v.m3u8";
                }
                else if ($hls_playlist_type == "event")
                {
                    $option_hls = "[select=\'a:0,a:1,v:0,v:1\':f=hls:hls_time=6:hls_playlist_type=event:hls_flags=+independent_segments+iframes_only:hls_segment_type=fmp4:var_stream_map=\'v:0,agroup:aac,language:dut,name:".$settings[$_REQUEST["quality"]]["height"]."p v:1,agroup:aac,language:dut,name:".$settings[$lowbitrate]["height"]."p a:0,agroup:aac,language:dut,name:aac_1_96k a:1,agroup:aac,language:dut,name:aac_2_".$settings[$_REQUEST["quality"]]["abitrate"]."K\':master_pl_name=master_event.m3u8:hls_segment_filename=".$BASE."/stream_event_%v_data%02d.m4s]".$BASE."/stream_event_%v.m3u8";
                }
            }
            $create_vod_dir = "";
            $option_vod = "/dev/null";
            if (isset($_REQUEST["vod"]))
            {
                $create_vod_dir = "/usr/bin/sudo -uapache /usr/bin/mkdir -p /var/www/html/".$VODDIR."/".$BASE.";";
                $option_vod = "[select=\'a:0,a:1,v:0,v:1\':f=dash:seg_duration=6:hls_playlist=true:single_file=true:adaptation_sets=\'id=0,streams=0,1 id=1,streams=2,3\':media_seg_name=\'stream_vod_\$RepresentationID\$-\$Number%05d\$.\$ext\$\':hls_master_name=master_vod.m3u8]../".$VODDIR."/".$BASE."/manifest_vod.mpd";
            }
            //$sub = "-map -0:4?";
            if(isset($_REQUEST["mp4"]))
            {
                // todo: think about this hls dir contains meta data, thus should always exist
                $option_mp4 = "[select=\'v:0,a:1\':f=mp4:movflags=+faststart]".$BASE."/".$BASE.".mp4";
                // todo: do NOT understand why this does NOT work? It does WORK when no complex filter is used?
                // if (isset($_REQUEST["checkbox_subtitles"]) && $hls_playlist_type == "live")
                // {
                //     $option_mp4 .= "|[select=\'s:0,sgroup:subtitle\']".$BASE."/subtitles.vtt";
                //     $sub = "-map 0:s:0? -c:s webvtt";
                // }
            }
            if ($extension == "avi")
            {
                // no hwaccel supported for avi
                $hwaccel = "";
                $scale = "scale";
                $library = "libx264";
            }
            else
            {
                // hwaccel supported encode
                $hwaccel = "-hwaccel vaapi -vaapi_device /dev/dri/renderD128 -hwaccel_output_format vaapi";
                $scale = "scale_vaapi";
                $library = "h264_vaapi";
            }
            // todo: make second audio parameter configurable as well, its a constant now 96K, requires some thinking
            // ffmpeg bug: $option_vod uses -metadata:s:a:0 language=dut (note: there can be only one language per adaptation_sets), for master_vod.m3u8 the metadata language is ignored
            fwrite($fp, "/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode start >> ".$hls_path."/".$filename."/status.txt'; ".$create_vod_dir." ".$create_live_dir." ".$create_hls_dir." cd /var/www/html/".$HLSDIR."/; /usr/bin/sudo -uapache /usr/bin/ffmpeg ".$hwaccel." ".$STARTTIME." ".$read_rate." ".$fileinput." -live_start_index 0 -progress ".$BASE."/progress-log.txt -force_key_frames \"expr:gte(t,n_forced*2)\" -filter_complex \"[0:v]split=2[v1][v2];[v1]".$scale."=w=".$settings[$_REQUEST["quality"]]["width"].":h=".$settings[$_REQUEST["quality"]]["height"]."[v1out];[v2]".$scale."=w=".$settings[$lowbitrate]["width"].":h=".$settings[$lowbitrate]["height"]."[v2out]\" -map [v1out] -c:v:0 ".$library." -b:v:0 ".$settings[$_REQUEST["quality"]]["vbitrate"]."K -maxrate:v:0 ".$settings[$_REQUEST["quality"]]["vbitrate"]."K -minrate:v:0 ".$settings[$_REQUEST["quality"]]["vbitrate"]."K -bufsize:v:0 ".$settings[$_REQUEST["quality"]]["vbitrate"]."K -preset veryfast -g 25 -keyint_min 25 -sc_threshold 0 -flags +global_header -map [v2out] -c:v:1 ".$library." -b:v:1 ".$settings[$lowbitrate]["vbitrate"]."K -maxrate:v:1 ".$settings[$lowbitrate]["vbitrate"]."K -minrate:v:1 ".$settings[$lowbitrate]["vbitrate"]."K -bufsize:v:1 ".$settings[$lowbitrate]["vbitrate"]."K -preset veryfast -g 25 -keyint_min 25 -sc_threshold 0 -flags +global_header -map a:0 -ac 2 -c:a:0 aac -b:a:0 96k -metadata:s:a:0 language=dut -map a:0 -ac 2 -c:a:1 aac -b:a:1 ".$settings[$_REQUEST["quality"]]["abitrate"]."K -metadata:s:a:1 language=dut -map -0:4? -map -0:5? -map -0:6? -map -0:7? -map -0:8? -map -0:9? -f tee \"".$option_vod."|".$option_mp4."|".$option_live."|".$option_hls."\" 2>>/tmp/ffmpeg-".$HLSDIR."-".$BASE.".log && /usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode finish success >> ".$hls_path."/".$filename."/status.txt' || /usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode finish failed >> ".$hls_path."/".$filename."/status.txt'\n");
            if (isset($_REQUEST["checkbox_subtitles"]))
            {
                if (isset($_REQUEST["vod"]))
                {
                    fwrite($fp, "cd /var/www/html/".$VODDIR."/".$BASE."; /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\\1\\n#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subtitles\",NAME=\"Dutch\",DEFAULT=YES,FORCED=NO,AUTOSELECT=YES,URI=\"sub.m3u8\",LANGUAGE=\"dut\"/' master_vod.m3u8; /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-STREAM.*)/\\1,SUBTITLES=\"subtitles\"/' master_vod.m3u8\n");
                }
                if (isset($_REQUEST["hls_playlist_type"]))
                {
                    $hls_playlist_type = $_REQUEST["hls_playlist_type"][0];
                    if ($hls_playlist_type == "event")
                    {
                        fwrite($fp, "cd /var/www/html/".$HLSDIR."/".$BASE."; /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\\1\\n#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subtitles\",NAME=\"Dutch\",DEFAULT=YES,FORCED=NO,AUTOSELECT=YES,URI=\"sub.m3u8\",LANGUAGE=\"dut\"/' master_event.m3u8; /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-STREAM.*)/\\1,SUBTITLES=\"subtitles\"/' master_event.m3u8\n");
                    }
                    if ($hls_playlist_type == "live")
                    {
                        fwrite($fp, "cd /var/www/html/".$HLSDIR."/../live/".$BASE."; /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\\1\\n#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subtitles\",NAME=\"Dutch\",DEFAULT=YES,FORCED=NO,AUTOSELECT=YES,URI=\"sub.m3u8\",LANGUAGE=\"dut\"/' master_live.m3u8; /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-STREAM.*)/\\1,SUBTITLES=\"subtitles\"/' master_live.m3u8\n");
                    }
                }
                if (isset($_REQUEST["mp4"]))
                {
                    fwrite($fp, "while [ ! \"`/usr/bin/cat ".$hls_path."/".$filename."/status.txt | /usr/bin/grep 'encode finish success'`\" ] ; do sleep 1; done\n");
                    fwrite($fp, "cd /var/www/html/".$HLSDIR."/".$BASE."; /usr/bin/sudo -uapache /usr/bin/ffmpeg -i ".$BASE.".mp4 -i subtitles.vtt -c:s mov_text -metadata:s:s:0 language=dut -disposition:s:0 default -c:v copy -c:a copy ".$BASE.".tmp.mp4;/usr/bin/mv -f ".$BASE.".tmp.mp4 ".$BASE.".mp4\n");
                }
            }
            if ($mustencode)
            {
                fwrite($fp, "while [ ! \"`/usr/bin/cat ".$hls_path."/".$filename."/status.txt | /usr/bin/grep 'encode finish success'`\" ] ; do sleep 1; done\n");
                fwrite($fp, "/usr/bin/sudo /usr/bin/rm /var/www/html/".$HLSDIR."/".$BASE."/video.mp4\n");
            }
            //fwrite($fp, "sleep 3 && /usr/bin/sudo /usr/bin/screen -S ".$filename."_encode -X quit\n");
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
        <html>
        <!--   todo: HLS or DASH video player title or change to livestreaming? -->
        <head><title>HLS Video Player</title>
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
        var filename = "<?php echo $filename; ?>";
        var playerInitDone = false;
        var currentStatus = "";
        var message_string = "";
        var extension = "<?php echo $extension; ?>";
        var fileExists = checkFileExists("../vod/<?php echo $filename; ?>/manifest_vod.mpd");

        navigator.sayswho= (function(){
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

        // todo: add extra check if transcoding is finished
        if (fileExists && navigator.sayswho.match(/\bEdge\/(\d+)/)) {
            // Play DASH on Windows Edge browser
            manifestUri = '../vod/<?php echo $filename; ?>/manifest_vod.mpd';
            message_string = "VOD Video Available";
        } else if (fileExists) {
            // Transcoding is done, play VOD when available
            manifestUri = '../vod/<?php echo $filename; ?>/master_vod.m3u8';
            message_string = "VOD Video Available";
        } else if (extension == "mp4") {
            // No encoding required, just play available mp4
            manifestUri = '../hls/<?php echo $filename; ?>.mp4';
        } else if (checkFileExists("../hls/<?php echo $filename; ?>/master_event.m3u8")) {
            // Play HLS event stream
            manifestUri = '../hls/<?php echo $filename; ?>/master_event.m3u8';
            message_string = "HLS Video Available";
        } else if (checkFileExists("../live/<?php echo $filename; ?>/master_live.m3u8")) {
            // Play live stream
            manifestUri = '../live/<?php echo $filename; ?>/master_live.m3u8';
            message_string = "LIVE Video Available";
        } else if (checkFileExists("../hls/<?php echo $filename; ?>/<?php echo $filename; ?>.mp4")) {
            // Play MP4 stream
            manifestUri = '../hls/<?php echo $filename; ?>/<?php echo $filename; ?>.mp4';
            message_string = "MP4 Video Available";
        } else {
            // todo: if there are no files yet, guess it will be live, this needs to improve...
            manifestUri = '../live/<?php echo $filename; ?>/master_live.m3u8';
            message_string = "LIVE Video Available";
        }

        // todo: add wait to initPlayer until video is available

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
                if (extension == "mp4")
                {
                    message = "mp4 Video Available";
                    if (!playerInitDone)
                    {
                        playerInitDone = true;
                        initPlayer();
                    }
                }
                else if (status["available"] >= 0 && currentStatus.indexOf("encode start") >= 0 && currentStatus.indexOf("encode finish success") < 0)
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
                    // todo: 20 seconds is just an empirical guess
                    if (!playerInitDone && Math.ceil(status["available"] >= 20))
                    {
                        playerInitDone = true;
                        initPlayer();
                    }
                }
                else if (currentStatus.indexOf("encode finish success") >= 0)
                {
                    message = message_string;
                    if (!playerInitDone)
                    {
                        playerInitDone = true;
                        initPlayer();
                    }
                }
                else if (status["remuxBytesDone"])
                {
                    message = "Remuxing Video "+(Math.ceil(status["remuxBytesDone"] / status["remuxBytesTotal"] * 20)*5).toString()+"%";
                }
            }
            document.getElementById("statusbutton").value = message;
        }

        function checkStatus()
        {
            var oReq = new XMLHttpRequest();
            var newHandle = function(event) { handle(event, myArgument); };
            oReq.addEventListener("load", checkStatusListener, {once: true});
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
        <?php echo $select_box; ?>
        <table cellspacing="10"><tr><td>
        <form action="index.php" method="GET" onSubmit="return confirm('Are you sure you want to delete the video file?');">
        <input type="hidden" name="filename" value="<?php echo $filename; ?>">
        <input type="hidden" name="action" value="delete">
        <input type="submit" value="Delete Video Files">
        </form></td><td>
        <?php
              if (file_exists($hls_path."/../vod/".$filename."/master_vod.m3u8") && file_exists($hls_path."/".$filename."/master_event.m3u8"))
              {
                  if (file_exists("".$hls_path."/".$filename."/status.txt"))
                  {
                     $config = file_get_contents("".$hls_path."/".$filename."/status.txt");
                     if (preg_match("/encode finish success/", $config, $matches))
                     {
        ?>
                         <form action="index.php" method="GET">
                         <input type="hidden" name="filename" value="<?php echo $filename; ?>">
                         <input type="hidden" name="action" value="clean">
                         <!-- <input type="hidden" name="action" value="restart"> -->
                         <input type="submit" value="Cleanup Video Files">
                         </form></td><td valign="top">
        <?php
                      }
                  }
              }
        ?>
        <form>
        <input type="button" onClick="showStatus();" id="statusbutton" value="Loading...">
        </form>
        </td><td valign="top">
                          <span id="mp4link"></span>
        </td></tr>
<?php
        echo "<tr><td>";
        echo "<a href=\"http://192.168.1.29/shutdownlock.php\">Shutdown Lock</a>\n";
        if (file_exists($video_path."/".$filename.".mp4"))
        {
            echo "</td><td>";
            echo "<a href=\"http://192.168.1.29/hls/".$filename.".mp4\" download>Video link</a>\n";
        }
        if (file_exists($hls_path."/".$filename."/master_event.m3u8"))
        {
            echo "</td><td>";
            echo "<a href=\"http://192.168.1.29/hls/".$filename."/master_event.m3u8\">HLS event</a>\n";
        }
        if (file_exists($hls_path."/../live/".$filename."/master_live.m3u8"))
        {
            echo "</td><td>";
            echo "<a href=\"http://192.168.1.29/live/".$filename."/master_live.m3u8\">LIVE event</a>\n";
        }
        if (file_exists("".$hls_path."/".$filename."/status.txt"))
        {
            $config = file_get_contents("".$hls_path."/".$filename."/status.txt");
            if (preg_match("/encode finish success/", $config, $matches))
            {
                if (file_exists($hls_path."/../vod/".$filename."/master_vod.m3u8"))
                {
                    echo "</td><td>";
                    echo "<a href=\"http://192.168.1.29/vod/".$filename."/master_vod.m3u8\">VOD</a>\n";
                }
                if (file_exists($hls_path."/".$filename."/".$filename.".mp4"))
                {
                    echo "</td><td>";
                    echo "<a href=\"http://192.168.1.29/hls/".$filename."/".$filename.".mp4\" download>Download mp4</a>\n";
                }
            }
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
        if (file_exists($video_path."/".$_REQUEST["filename"].".$extension"))
        {
            if (!file_exists($hls_path."/".$filename))
            {
                mkdir($hls_path."/".$filename);
            }
            // Get mediainfo
            $mediainfo = shell_exec("/usr/bin/mediainfo ".$video_path."/".$filename.".$extension");
            preg_match_all('/Duration[ ]*:( (\d*) h)?( (\d*) min)?( (\d*) s)?/',$mediainfo,$durationdetails);
            $length = 0;
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
            $videoheight = ((int) str_replace(" ", "", $heightdetails[1][0]));
            preg_match_all('/Frame rate[ ]*: (\d*\.?\d*) FPS/',$mediainfo,$ratedetails);
            if(isset($ratedetails[1][0])) {
               $framerate = ((double) $ratedetails[1][0]);
            }
            // Fetch any cut marks
            preg_match_all('/^(\d*)_(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/',$filename,$filedetails);

            $chanid=$filedetails[1][0];
            $year=$filedetails[2][0];
            $month=$filedetails[3][0];
            $day=$filedetails[4][0];
            $hour=$filedetails[5][0];
            $minute=$filedetails[6][0];
            $second=$filedetails[7][0];
            $starttime="$year-$month-$day $hour:$minute:$second";

            $dbconn=mysqli_connect($dbserver,$dbuser,$dbpass);
            mysqli_select_db($dbconn,$dbname);
            $sqlselect="select * from recordedmarkup where (chanid=$chanid and starttime='$starttime' and (type=".MARK_CUT_START." or type=".MARK_CUT_END.")) order by mark;";
            $result=mysqli_query($dbconn,$sqlselect);
            $fp = fopen($hls_path."/".$filename."/cutlist.txt", "w");
            fprintf($fp, "ffconcat version 1.0\n");
            $firstrow = true;
            $midsegment = false;
            $startsegment = 0;
            $clippedlength = 0;
            $cutcount = 0;
            while ($row = mysqli_fetch_assoc($result))
            {
                $cutcount++;
                $mark = (double) $row['mark'];
                $mark = ($mark / $framerate);
                if ($row['type']==MARK_CUT_START)
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
                else if ($row['type']==MARK_CUT_END)
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
            }
            if ($midsegment)
            {
                fprintf($fp, "outpoint %0.2f\n", $length);
                $clippedlength += (($length - $startsegment) > 0 ? ($length - $startsegment) : 0) + 1;
            }
            fclose($fp);
        }
            ?>
            <html>
            <head><title>Select Video Settings</title></head>
            <body>
<?php echo $select_box; ?>
            <form action="index.php" method="GET">
            <input type="hidden" name="filename" value="<?php echo $filename; ?>">
<?php
            if (file_exists($hls_path."/../vod/".$filename."/master_vod.m3u8") ||
                file_exists($hls_path."/".$filename."/master_event.m3u8") ||
                file_exists($video_path."/".$filename.".mp4") ||
                file_exists($hls_path."/".$filename."/".$filename.".mp4") ||
                file_exists($hls_path."/../live/".$filename."/master_live.m3u8"))
        {
            // ready for streaming
            ?>
            <br>
            <input type="submit" name="do" value="Watch Video">
            <?php
        }
        else
        {
            // still need to encode
            ?>
            <h2>Select the settings appropriate for your connection:</h2>
            <label for="quality">Quality: </label><select name="quality">
            <?php
                foreach ($settings as $setting => $settingset)
                {
                    // todo: remove hack adding 300, need to be even higher?
                    if ($settingset["height"] <= $videoheight + 300)
                    {
                        echo "<option value=\"".$setting."\"".((strpos($setting, "high") !== false && ($videoheight<720 && $settingset["height"]==720 || $settingset["height"]==1080))?" selected=\"selected\"":"").
                                ">".preg_replace('/[0-9]+/', '', ucfirst($setting))." Quality ".$settingset["height"]."p".
                                (file_exists($video_path."/".$filename.".mp4")?" (mp4 Available)":"").
                                (file_exists($hls_path."/".$filename."/".$filename.".mp4")?" (MP4 Available)":"").
                                (file_exists($hls_path."/../live/".$filename."/master_live.m3u8")?" (LIVE Available)":"").
                                (file_exists($hls_path."/../vod/".$filename."/master_vod.m3u8")?" (VOD Available)":"")."</option>\n";
                    }
                }
            ?>
            </select>
            <br>
            <select name="removecut"><option value="on">Cut Commercials (<?php echo $cutcount/2; ?> found)</option><option value="off" selected="selected">Leave Uncut</option></select><br>
            <?php
               $mediainfo = shell_exec("/usr/bin/mediainfo \"--Output=Text;%Format%\" ".$video_path."/".$filename.".$extension");
               if (preg_match('/(Teletext)/',$mediainfo,$subtitle) || (preg_match('/(Subtitle)/',$mediainfo,$subtitle)))
               {
            ?>
                   <input type="checkbox" action="" name="checkbox_subtitles" id="agree" value="yes">
                   <label for="agree">Subtitles</label>
                   <br>
            <?php
               }
            ?>
            <input type="hidden" name="height" value="<?php echo $videoheight; ?>">
            <input type="hidden" name="framerate" value="<?php echo $framerate; ?>">
            <input type="hidden" name="length" value="<?php echo $length; ?>">
            <input type="hidden" name="clippedlength" value="<?php echo (int)$clippedlength; ?>">
            <input type="hidden" name="cutcount" value="<?php echo (int)$cutcount/2; ?>">
            <input type="checkbox" name="hls_playlist_type[]" value="live" onclick="return KeepCount()" checked='checked' id="option"><label for="option"> live</label>
            <input type="checkbox" name="hls_playlist_type[]" value="event" onclick="return KeepCount()" id="option"><label for="option"> event</label><br>
            <input type="checkbox" name="vod" id="option"><label for="option"> vod</label><br>
            <input type="checkbox" name="mp4" id="option"><label for="option"> mp4</label><br>
            <input type="submit" name="do" value="Encode Video">
            <script type="text/javascript">
               function KeepCount()  {
                 var elements = document.getElementsByName("hls_playlist_type[]");
                 var total = 0;
                 for(var i in elements) {
                   var element = elements[i];
                   if(element.checked) total++;
                   if(total>1) {
                       alert("Pick one of the two: live or event");
                       element.checked = false;
                       return false;
                   }
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
    echo "No such file:\n";
    $filename = $_REQUEST["filename"];
    echo $video_path."/".$_REQUEST["filename"].".$extension";
    if (file_exists($video_path."".$_REQUEST["filename"].".$extension"))
    {
        echo " file exists";
    }
    else
    {
        echo " file does not exist or permission as apache user is denied";
    }
}
?>