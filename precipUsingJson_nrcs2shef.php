#!/usr/bin/php
<?php

//SNTL = Snotel and SCAN
//getInstantaneous only for snotel or scan data
//grab all the obs in their native timezone, then convert to UTC at end before printing em out?
//create json of most recent times... 
$stnDates = array();

if (!(file_exists("mostRecentTP.json")))
 $json = null;
else{
 $jsonFile = file_get_contents('mostRecentTP.json');
 $json = json_decode($jsonFile);
}
 
date_default_timezone_set('UTC');
$today = date('Y-m-d H:i:s'); //2020-mm-dd
$yymmddhhii = date('ymdHi');
$yesterday = date('Y-m-d H:i:s',strtotime('1 day ago'));
//$monthAgo = date('Y-m-d H:i:s',strtotime('1 month ago'));
//create client object
$soapclient = new SoapClient('http://www.wcc.nrcs.usda.gov/awdbWebService/services?WSDL', array('connection_timeout'=>120, 'exceptions' => 0));

//SNTL is snotel... only have SNTL for AK... there is SNOW for AK and BC... not sure what there is for YT
$getStnsParams = array('stateCds' => array('AK','BC','YT'), 'networkCds' =>'SNTL', 'logicalAnd' => true, 'elementCd' => 'PREC' ); //this gives the station triplets of the stations that are SNTL, and have precipitation...
$stnResp = $soapclient->getStations($getStnsParams);
$getMetaParams = array('stationTriplets' => $stnResp->return);
$metaDataMultiple = $soapclient->getStationMetadataMultiple($getMetaParams);
$stnObjects = array();
//throwing out stations that don't have ShefId... pretty sure this is only Kodiak, which was discontinued in the 80s
for($i=0;$i<count($stnResp->return);$i++){
 $stnTriplet = $stnResp->return[$i]; //local vars bc i don't remember how efficient php is
 $metaData = $metaDataMultiple->return[$i];
 if ($metaData->stationTriplet == $stnTriplet){ //sanity check 
  if(isset($metaData->shefId))
   $stnObj = (object)['stationTriplet' => $stnTriplet, 'timeZone' => $metaData->stationDataTimeZone, 'shefId' => $metaData->shefId, 'name' => $metaData->name];
  else
   print($stnTriplet." ".$metaData->name." has no shefId.\n");
 }
 else{print("houston we have a problem; failure with ".$stnTriplet." .\n");}
 $stnObjects[] = $stnObj;
}


$mostRecent = new stdClass();
$mostRecent->type = "FeatureCollection";
$mostRecent->features = array();

$shefData = fopen('tippingBucketShef.txt','w');
foreach($stnObjects as $stn){
 if($json){
  foreach($json->features as $thing){
   if($thing->properties->stnTriplet == $stn->stationTriplet){
    $revisedBeginDate = $thing->properties->date; //get most recent date we have data
    if ($revisedBeginDate == null){
     $revisedBeginDate = $yesterday;
    }
   }
  }
 }
 else
  $revisedBeginDate = $yesterday;
 
 //convert dates to local standard time
 $diffFromUtc = (float)$stn->timeZone;
 $revisedBeginDateLocal = date('Y-m-d H:i:s',strtotime('+'.$diffFromUtc.' hours',strtotime($revisedBeginDate)));
 $todayLocal = date('Y-m-d H:i:s',strtotime('+'.$diffFromUtc.' hours',strtotime($today)));
 $yesterdayLocal = date('Y-m-d H:i:s',strtotime('+'.$diffFromUtc.' hours',strtotime($yesterday)));

//beginDate is the earliest date to get data for... insertOrUpdateBeginDate will set it to a later date.
// Set beginDate to yesterday 
 $precReq = array('stationTriplets'=>$stn->stationTriplet, 'elementCd' => 'PREC', 'ordinal' => 2, 'beginDate' => $yesterdayLocal, 'endDate' => $todayLocal, 'insertOrUpdateBeginDate' => $revisedBeginDateLocal, 'filter' => 'ALL', 'unitSystem'=>'ENGLISH');
 $precResp = $soapclient->getInstantaneousDataInsertedOrUpdatedSince($precReq);
 print_r($stn->stationTriplet." ");
 if (isset($precResp->return->values)){
  foreach($precResp->return->values as $ob){
   if (isset($ob->value)){
  //convert to Z time
    //$shefDate = date('ymdHi',strtotime('-'.$diffFromUtc.' hours',strtotime($ob->time)));
    $shefDate = date('Y-m-d H:i:s',strtotime('-'.$diffFromUtc.' hours',strtotime($ob->time)));
    $shefDateFormatted = date('ymdHi',strtotime($shefDate));
    //make sure this is actually new data... nrcs web service seems to give extra hours
    if (strtotime($shefDate) > strtotime($revisedBeginDate)){
     $shefString = ".A ".$stn->shefId." ".substr($shefDateFormatted,0,6)." Z DH".substr($shefDate,6,4)."/DC".$yymmddhhii."/PCIR2 ".$ob->value."\n";
     fwrite($shefData, $shefString);
    }
    else {
     print("nope...".strtotime($shefDate)." is not greater than ".strtotime($revisedBeginDate)."\n");
    }
   }
   else {
    print("not set...");
   }
  }
 }
 
 $point = new stdClass();
 $point->type = "Feature";
 $point->properties = new stdClass();
 $point->properties->name = $stn->name;
 $point->properties->stnTriplet = $stn->stationTriplet;
 $endTime = null;
 if(isset($precResp->return->values)){ //make sure we grab the last time with a value
  $i=count($precResp->return->values)-1;
  $foundValue = false;
  while(!($foundValue) && $i>-1){
   if(isset($precResp->return->values[$i]->value)){
    $foundValue = true;
    $endTime = $precResp->return->values[$i]->time;
   }
   $i--;
  }
  if(!($foundValue))
   $point->properties->date = null;
  else
   $point->properties->date = date('Y-m-d H:i:s',strtotime('-'.$diffFromUtc.' hours',strtotime($endTime)));
 }
 else
  $point->properties->date = null;
 $mostRecent->features[] = $point;
 print_r("the last time is ".$point->properties->date."\n");
 //make sure we don't replace a valid time in mostRecent with null... will need to compare epoch time of last ob grabbed with time in mostRecent json 
}

fclose($shefData);


file_put_contents("mostRecentTP.json",json_encode($mostRecent));
exit("done with script\n");

?>
