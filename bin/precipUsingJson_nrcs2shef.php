#!/bin/php

<?php

//use NRCS web services to grab tipping bucket data from snotels. 
//see https://www.wcc.nrcs.usda/web_service/awdb_web_service_landing.htm
//SNTL = Snotel and SCAN
//First grab stationlist from NRCS specifying stations that are snotels, in AK/BC/YT (just AK) 
//then grab any new data by comparing to mostRecentTP.json (which has dates/times of most recent data
//then write to tippingBucketShef.txt


//grab all the obs in their native timezone, then convert to UTC at end before printing em out?
//create json of most recent times... 

//so script can run anywhere 
chdir(__DIR__);
chdir("../");
define("APP_DIR",getcwd());
define("DATA_DIR",APP_DIR."/data/");
define("ETC_DIR",APP_DIR."/etc/");
define("LOG_DIR",APP_DIR."/log/");
date_default_timezone_set('UTC');
$today = date('Y-m-d H:i:s'); //2020-mm-dd
$yymmddhhii = date('ymdHi');
$yesterday = date('Y-m-d H:i:s',strtotime('1 day ago'));
$newData = false; //flag to determine whether to send new file
//create client object
$soapclient = new SoapClient('http://www.wcc.nrcs.usda.gov/awdbWebService/services?WSDL', array('connection_timeout'=>120, 'exceptions' => 0));

//SNTL is snotel... only have SNTL for AK... there is SNOW for AK and BC... not sure what there is for YT
//SNTLT is snolite. I think the only one is 1264: horsepasture -MM
$getStnsParams = array('stateCds' => array('AK','BC','YT'), 'networkCds' => array('SNTL','SNTLT'), 'logicalAnd' => true, 'elementCd' => 'PREC', 'ordinals'=>'2'); //this gives the station triplets of the stations that are SNTL, and have precipitation...
$stnResp = $soapclient->getStations($getStnsParams);
if(is_soap_fault($stnResp)){
 print("error connecting to server for getStations. Retrying...\n");
 sleep(1);
 $stnResp = $soapclient->getStations($getStnsParams);
 if(is_soap_fault($stnResp)){
  print("two errors in a row getStations. Exiting.\n");
  exit;
 }
}
$getMetaParams = array('stationTriplets' => $stnResp->return);
$metaDataMultiple = $soapclient->getStationMetadataMultiple($getMetaParams);
if(is_soap_fault($metaDataMultiple)){
 print("error connecting to server for getStationMetadataMultiple. Retrying...\n");
 sleep(1);
 $metaDataMultiple = $soapclient->getStationMetadataMultiple($getMetaParams);
 if(is_soap_fault($metaDataMultiple)){
  print("two errors in a row getStationMetaDataMultiple. Exiting.\n");
  exit;
 }
}
$stnObjects = array();
//throwing out stations that don't have ShefId... pretty sure this is only Kodiak, which was discontinued in the 80s
for($i=0;$i<count($stnResp->return);$i++){
 $stnTriplet = $stnResp->return[$i]; //local vars bc i don't remember how efficient php is
 $metaData = $metaDataMultiple->return[$i];
 if ($metaData->stationTriplet == $stnTriplet){ //sanity check 
  if(!(empty($metaData->shefId)))
   $stnObj = (object)['stationTriplet' => $stnTriplet, 'timeZone' => $metaData->stationDataTimeZone, 'shefId' => $metaData->shefId, 'name' => $metaData->name];
  else
   print($stnTriplet." ".$metaData->name." has no shefId.");
 }
 else{print("houston we have a problem; failure with ".$stnTriplet." .\n");}
 $stnObjects[] = $stnObj;
}

//see if a most recent dates file exists, if not this is the first time you're running script
if (!(file_exists(ETC_DIR.'mostRecentTP.json')))
 $json = null;
else{
 $jsonFile = file_get_contents(ETC_DIR.'mostRecentTP.json');
 $json = json_decode($jsonFile);
}

//set up variables for json
$mostRecent = new stdClass();
$mostRecent->type = "FeatureCollection";
$mostRecent->features = array();


$shefData = fopen(DATA_DIR.'tippingBucketShef.txt','w');
//fwrite($shefData,"SXAK58 PACR ".substr($yymmddhhii,4,6)."\nRR3ACR\n");
fwrite($shefData,"SRAK58 PACR ".substr($yymmddhhii,4,6)."\nACRRR3ACR\n");
fwrite($shefData,"DATA REPORT FROM CSV INGEST \n\n");
fwrite($shefData,":APRFC SNOTEL web service ingest from NRCS via local process\n");

$yymmddhhii = date('ymdHi');
foreach($stnObjects as $stn){
 $beginDate = $yesterday; //this is for the case that ob is brand new, and not in the most recent doc, or there is no json doc
 if($json){
  foreach($json->features as $thing){
   if($thing->properties->stationTriplet == $stn->stationTriplet){
    $beginDate = $thing->properties->date; //get most recent date we have data
    if ($beginDate == null){
     $beginDate = $yesterday;
    }
   }
  }
 }
 
 //convert dates to local standard time
 $diffFromUtc = (float)$stn->timeZone;
 $beginDateLocal = date('Y-m-d H:i',strtotime('+'.$diffFromUtc.' hours',strtotime($beginDate)));
 $todayLocal = date('Y-m-d H:i',strtotime('+'.$diffFromUtc.' hours',strtotime($today)));
 
//ordinal 2 for tipping bucket
 $precReq = array('stationTriplets' => $stn->stationTriplet, 'elementCd' => 'PREC', 'ordinal' => 2, 'beginDate' => $beginDateLocal, 'endDate' => $todayLocal);
 $precResp = $soapclient->getHourlyData($precReq);
 //print_r($stn->stationTriplet." ");
 if (isset($precResp->return->values)){
  if (!(is_array($precResp->return->values))){ //cast to array if only one returned
   $localObj = (object)['value' => $precResp->return->values->value, 'dateTime' => $precResp->return->values->dateTime];
   $precResp->return->values = array();
   array_push($precResp->return->values,$localObj);
  }
   
  foreach($precResp->return->values as $ob){
   if (isset($ob->value)){
    //convert to Z time
    $shefDate = date('Y-m-d H:i:s',strtotime('-'.$diffFromUtc.' hours',strtotime($ob->dateTime)));
    $shefDateFormatted = date('ymdHi',strtotime($shefDate));
    //make sure this is actually new data... nrcs web service seems to give extra hours
    if (strtotime($shefDate) > strtotime($beginDate)){
     $newData = true; //we have at least one new piece of data
     $shefString = ".A ".$stn->shefId." ".substr($shefDateFormatted,0,6)." Z DH".substr($shefDateFormatted,6,4)."/DC".$yymmddhhii."/PCIR3 ".$ob->value."\n";
     fwrite($shefData, $shefString);
    }
   }
  }
 }
 
 $point = new stdClass();
 $point->type = "Feature";
 $point->properties = new stdClass();
 $point->properties->name = $stn->name;
 $point->properties->stationTriplet = $stn->stationTriplet;
 $point->properties->shefId = $stn->shefId;
 $endTime = null;
 if(isset($precResp->return->values)){ //make sure we grab the last time with a value
  $i=count($precResp->return->values)-1;
  $foundValue = false;
  while(!($foundValue) && $i>-1){
   if(isset($precResp->return->values[$i]->value)){
    $foundValue = true;
    $endTime = $precResp->return->values[$i]->dateTime;
   }
   $i--;
  }
  if(!($foundValue)){
   $point->properties->date = $beginDate; //if we don't get a new value, just set date to date that was already in the json
   //$point->properties->date = null;
  }
  else
   $point->properties->date = date('Y-m-d H:i:s',strtotime('-'.$diffFromUtc.' hours',strtotime($endTime)));
 }
 else{ //so that we don't set date to null if there just wasn't any new data in this run
  $point->properties->date = $beginDate; 
  //$point->properties->date = null;
 }
 $mostRecent->features[] = $point;
 //make sure we don't replace a valid time in mostRecent with null... will need to compare epoch time of last ob grabbed with time in mostRecent json 
}

fclose($shefData);
$forAwips = '/usr/local/apps/scripts/bcj/hydroTools/TO_LDAD/tippingBucket_sheffile.txt';
$shefData = DATA_DIR.'tippingBucketShef.txt';

if($newData)
 copy($shefData,$forAwips);
//don't send over a blank file

file_put_contents(ETC_DIR.'mostRecentTP.json',json_encode($mostRecent));

?>
