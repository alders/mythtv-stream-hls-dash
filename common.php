<?php

class Common {

    public static function http_request($method, $endpoint, $rest)
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

    public static function get_storagegroup_dirs($StorageGroup, $HostName)
    {
        // http://localhost:6544/Myth/GetStorageGroupDirs
        // NOTE: Only a selected subset of the values available is returned here

        if (empty($StorageGroup) || empty($HostName)) {
            throw new InvalidArgumentException("StorageGroup and HostName cannot be empty");
        }

        $url = "Myth/GetStorageGroupDirs";
        $query = "";
        $xml_response = Common::http_request("GET", $url, $query);

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

    public static function generateStreamOptions($settings, $qualities, $nb_renditions, $audiolanguagefound)
    {
        $stream_number = 0;
        $seen_abitrates = [];
        $options = '';

        // Build audio options
        foreach ($qualities as $i => $quality) {
            $current_abitrate = $settings[$quality]["abitrate"];
            if (!in_array($current_abitrate, $seen_abitrates)) {
                foreach ($audiolanguagefound[0] as $language) {
                    $options .= "a:".$stream_number++.",";
                    $seen_abitrates[] = $current_abitrate; // Track seen bitrates
                    if (isset($_REQUEST["removecut"]) and $_REQUEST["removecut"]==="on" and isset($_REQUEST["checkbox_subtitles"]))
                    {
                        // NOTE: could not find a way, in one processing step without transcoding, to include video, all audio files and additionally one subtitle stream.
                        // In other words, the option to include subtitles will reduce the number of audio streams to one.
                        break;
                    }
                }
            }
        }

        // Build video options
        for ($i = 0; $i < $nb_renditions; $i++) {
            $options .= "v:".$i.($i === $nb_renditions - 1 ? "" : ",");
        }

        return rtrim($options, ','); // Remove trailing comma
    }

    public static function generateMediaStreamOptions($settings, $qualities, $nb_renditions, $audiolanguagefound)
    {
        $audio_stream_number = 0;
        $default = "yes";
        $options = '';

        // Build audio options
        $seen_abitrates = [];
        foreach ($qualities as $i => $quality) {
            $current_abitrate = $settings[$quality]["abitrate"];

            if (!in_array($current_abitrate, $seen_abitrates)) {
                foreach ($audiolanguagefound[0] as $language) {
                    $options .= "a:".$audio_stream_number.",agroup:aac,language:".$language[1]."-".$audio_stream_number.",name:".$language[0]['name']."-aac-".$audio_stream_number++."-".$current_abitrate."k,default:".$default." ";
                    $default = "no"; // Set default to "no" after the first audio stream
                    $seen_abitrates[] = $current_abitrate; // Track seen bitrates
                    if (isset($_REQUEST["removecut"]) and $_REQUEST["removecut"]==="on" and isset($_REQUEST["checkbox_subtitles"]))
                    {
                        // NOTE: could not find a way, in one processing step without transcoding, to include video, all audio files and additionally one subtitle stream.
                        // In other words, the option to include subtitles will reduce the number of audio streams to one.
                        break;
                    }
                }
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

    public static function getFileInfo($path, $filename)
    {
        $file = $path . "/" . $filename . "/state.txt";
        if (file_exists($file)) {
            $content = json_decode(file_get_contents($file), TRUE);
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

    public static function remove_duplicates($array)
    {
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

    public static function get_program_status($StartTime, $ChanId, $TitleFilter)
    {
        if (empty($StartTime) || empty($ChanId) || empty($TitleFilter)) {
            throw new InvalidArgumentException("StartTime, ChanId and TitleFilter cannot be empty");
        }

        $url = "Guide/GetProgramList";
        $query = "StartTime=$StartTime&ChanId=$ChanId&TitleFilter=" . urlencode($TitleFilter);
        $xml_response = Common::http_request("GET", $url, $query);

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

        // Return status unknown -16 if not found
        return "-16";
    }

    public static function get_recorded($StartTime, $ChanId)
    {
        // http://localhost:6544/Dvr/GetRecorded?StartTime=2023-07-08T18:55&ChanId=11500
        // NOTE: Only a selected subset of the values available is returned here

        if (empty($StartTime) || empty($ChanId)) {
            throw new InvalidArgumentException("StartTime and ChanId cannot be empty");
        }

        $url = "Dvr/GetRecorded";
        $query = "StartTime=$StartTime&ChanId=$ChanId";
        $xml_response = Common::http_request("GET", $url, $query);

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

    public static function get_video($Id)
    {
        // http://localhost:6544/Video/GetVideo?Id=7135

        if (empty($Id)) {
            throw new InvalidArgumentException("Id cannot be empty");
        }

        $url = "Video/GetVideo";
        $query = "Id=$Id";
        $xml_response = Common::http_request('GET', $url, $query);

        $xml = simplexml_load_string($xml_response, "SimpleXMLElement", LIBXML_NOCDATA);
        if ($xml  === false) {
            return array();
        }
        $array = json_decode(json_encode($xml), TRUE);

        $video = array(
            'Id' => $array['Id'] ?? '',
            'Title' => $array['Title'] ?? '',
            'SubTitle' => $array['SubTitle'] ?? '',
            'FileName' => $array['FileName'] ?? '',
            'HostName' => $array['HostName'] ?? '',
        );

        return $video;
    }

    public static function get_recorded_markup($RecordedId)
    {
        // http://localhost:6544/Dvr/GetRecordedMarkup?RecordedId=6474
        // NOTE: Only a selected subset of the values available is returned here

        if (empty($RecordedId)) {
            throw new InvalidArgumentException("RecordedId cannot be empty");
        }

        $url = "Dvr/GetRecordedMarkup";
        $query = "RecordedId=$RecordedId";
        $xml_response = Common::http_request("GET", $url, $query);

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

    public static function getMediaInfo($file, $webuser)
    {
        // Use ffprobe to get stream-information in JSON-formaat
        $command = "/usr/bin/ffprobe -v error -show_streams -show_format -of json \"$file\"";
        $output = shell_exec($command);

        if ($output === null) {
            die("Fout bij het uitvoeren van ffprobe");
        }

        // Parse de JSON-output
        $info = json_decode($output, TRUE);

        if (!isset($info['streams']) || !isset($info['format'])) {
            die("Ongeldige JSON-output van ffprobe");
        }

        $videoWidth = null;
        $videoHeight = null;
        $frameRate = null;
        foreach ($info['streams'] as $stream) {
            if ($stream['codec_type'] == 'video') {
                $videoWidth = $stream['width'];
                $videoHeight = $stream['height'];
                $frameRate = $stream['r_frame_rate'];
                break;
            }
        }

        $duration = $info['format']['duration'];

        $subtitleInfo = [];
        foreach ($info['streams'] as $stream) {
            if ($stream['codec_type'] == 'subtitle') {
                $codecName = $stream['codec_name'];
                if (in_array($codecName, ['ass', 'mov_text', 'srt','subrip'])) {
                    $language = isset($stream['tags']['language']) ? $stream['tags']['language'] : 'unknown';
                    $subtitleInfo[] = [
                        'index' => $stream['index'],
                        'codec_name' => $codecName,
                        'language' => $language
                    ];
                }
            }
        }

        $audioInfo = [];
        foreach ($info['streams'] as $stream) {
            if ($stream['codec_type'] == 'audio') {
                $language = isset($stream['tags']['language']) ? $stream['tags']['language'] : 'unknown';
                $audioInfo[] = [
                    'index' => $stream['index'],
                    'codec_name' => $stream['codec_name'],
                    'language' => $language
                ];
            }
        }

        return [
            'videoWidth' => $videoWidth,
            'videoHeight' => $videoHeight,
            'frameRate' => $frameRate,
            'duration' => $duration,
            'subtitleInfo' => $subtitleInfo,
            'audioInfo' => $audioInfo
        ];
    }

    public static function isNewAudioRendition($settings, $qualities, $i) {
        $current_abitrate = $settings[$qualities[$i]]["abitrate"];
        for ($j = 0; $j < $i; $j++) {
            if ($settings[$qualities[$j]]["abitrate"] === $current_abitrate) {
                return false;
            }
        }
        return true;
    }

    public static function addLanguageToAudioRendition($fp, $master_file, &$linenumber, $audiolanguagefound, $abitrate, &$audio_stream_number) {
	    for ($k = 0; $k < sizeOf($audiolanguagefound[0]); $k++) {
            fwrite($fp, " \
    /usr/bin/sudo -u{$GLOBALS['webuser']} /usr/bin/sed -i -E '".$linenumber."s/$/,ROLE=\"{$audio_stream_number}\",LANGUAGE=\"{$audiolanguagefound[0][$k][1]}-{$abitrate}k_{$audio_stream_number}\"/' ".$master_file.";");
            fwrite($fp, " \
    /usr/bin/sudo -u{$GLOBALS['webuser']} /usr/bin/sed -i -E '".$linenumber."s/NAME=[^,]*/NAME=\"{$audiolanguagefound[0][$k][0]['name']} $audio_stream_number \({$abitrate}k\)\"/' ".$master_file.";");
            fwrite($fp, " \
    /usr/bin/sudo -u{$GLOBALS['webuser']} /usr/bin/sed -i -E '".$linenumber."s/LANGUAGE=[^,]*,//' ".$master_file.";");
            $linenumber++;
            $audio_stream_number++;
            if (isset($_REQUEST["removecut"]) and $_REQUEST["removecut"]==="on" and isset($_REQUEST["checkbox_subtitles"]))
            {
                // NOTE: could not find a way, in one processing step without transcoding, to include video, all audio files and additionally one subtitle stream.
                // In other words, the option to use a cutlist and subtitles will reduce the number of audio streams to one.
                break;
            }
        }
    }
}
?>
