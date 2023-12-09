<html>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<header>
<title>Control Shutdown Lock</title>
<?php

$yourserver="localhost";

function http_request($method, $endpoint, $rest) {

    global $yourserver;
    $params = array("http" => array(
        "method" => $method,
        "content" => $rest
    ));

    $context = stream_context_create($params);

    // prior to v34 use port 6544
    $fp = @fopen("http://$yourserver:6550/$endpoint", "rb", false, $context);

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

function adjust_lock($increment) {
    $xml_response = http_request("GET" ,"Myth/GetSetting",
                                 "Key=MythShutdownLock&HostName=_GLOBAL_");
    $sd_lock_value = parse_xml_response($xml_response, "String");
    if ($sd_lock_value == "none") {
        echo "Error: Unable to get the shutdown lock count.";
        return;
    }

    $sd_lock_value = $sd_lock_value + $increment;
    if($sd_lock_value < 0) {
        $sd_lock_value = 0;
    }
    // POST on yourserver NULL or - All Hosts - in mythweb
    $xml_response = http_request("POST", "Myth/PutSetting",
                                 "Key=MythShutdownLock&Value=$sd_lock_value");
    $sd_lock_bool = parse_xml_response($xml_response, "bool");

    if ($sd_lock_bool == "false") {
         echo "Error: Unable to set the shutdown lock count.";
    }
}
?>
</header>
<body>
MythTV Master Backend Shutdown Controler.<br><br>
<?php

    echo "<form method='post' action='shutdownlock.php'>";
    echo "    <input type='submit' name='increment_lock' value='Increment'/>";
    echo "</form>";
    echo "<form method='post' action='shutdownlock.php'>";
    echo "    <input type='submit' name='decrement_lock' value='Decrement'/>";
    echo "</form>";

    if(isset($_POST["increment_lock"])) {
        adjust_lock(1);
    }
    if(isset($_POST["decrement_lock"])) {
        adjust_lock(-1);
    }

    $xml_response = http_request("GET" ,"Myth/GetSetting",
                             "Key=MythShutdownLock&HostName=_GLOBAL_");
    $sd_lock_value = parse_xml_response($xml_response, "String");
    if ($sd_lock_value == "none")
    {
       echo "Error: Unable to get the shutdown lock count.";
       return;
    }
    else
    {
       echo "Current value is: $sd_lock_value";
    }
?>
</body>
</html>
