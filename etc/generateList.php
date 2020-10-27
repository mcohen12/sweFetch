#!/usr/bin/php

<?php

$jsonFile = file_get_contents('mostRecentTP.json');
$json = json_decode($jsonFile);

$stationList = fopen('stationList.txt','w');
foreach($json->features as $station)
 fwrite($stationList, $station->properties->shefId."\n");

fclose($stationList);

?>
