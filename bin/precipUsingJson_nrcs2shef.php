#!/bin/php
<?php
//use NRCS web services to grab tipping bucket data from snotels. 
//see https://www.wcc.nrcs.usda/web_service/awdb_web_service_landing.htm
//SNTL = Snotel and SCAN
//First grab stationlist from NRCS specifying stations that are snotels, in AK/BC/YT (just AK) 
//then grab any new data by comparing to mostRecentTP.json (which has dates/times of most recent data
//then write to tippingBucketShef.txt


//getInstantaneous only for snotel or scan data
//grab all the obs in their native timezone, then convert to UTC at end before printing em out?
//create json of most recent times... 

date_default_timezone_set('UTC');
$today = date('Y-m-d H:i:s'); //2020-mm-dd
$yymmddhhii = date('ymdHi');
$yesterday = date('Y-m-d H:i:s',strtotime('1 day ago'));
//$monthAgo = date('Y-m-d H:i:s',strtotime('1 month ago'));
//create client object
$soapclient = new SoapClient('http://www.wcc.nrcs.usda.gov/awdbWebService/services?WSDL', array('connection_timeout'=>120, 'exceptions' => 0));

//SNTL is snotel... only have SNTL for AK... there is SNOW for AK and BC... not sure what there is for YT
$getStnsParams = array('stateCds' => array('AK','BC','YT'), 'networkCds' =>'SNTL', 'logicalAnd' => true, 'elementCd' => 'PREC', 'ordinals'=>'2'); //this gives the station triplets of the stations that are SNTL, and have precipitation...
$stnResp = $soapclient->getStations($getStnsParams);
$getMetaParams = array('stationTriplets' => $stnResp->return);
$metaDataMultiple = $soapclient->getStationMetadataMultiple($getMetaParams);
$stnObjects = array();
//throwing out stations that don't have ShefId... pretty sure this is only Kodiak, which was discontinued in the 80s
for($i=0;$i<count($stnResp->return);$i++){
 $stnTriplet = $stnResp->return[$i]; //local vars bc i don't remember how efficient php is
 $metaData = $metaDataMultiple->return[$i];
 if ($metaData->stationTriplet == $stnTriplet){ //sanity check 
  if(!(empty($metaData->shefId)))
   $stnObj = (object)['stationTriplet' => $stnTriplet, 'timeZone' => $metaData->stationDataTimeZone, 'shefId' => $metaData->shefId, 'name' => $metaData->name];
  else
   print($stnTriplet." ".$metaData->name." has no shefId.\n");
 }
 else{print("houston we have a problem; failure with ".$stnTriplet." .\n");}
 $stnObjects[] = $stnObj;
}

//see if a most recent dates file exists, if not this is the first time you're running script
if (!(is_dir('../etc')))
 mkdir('../etc');
if (!(file_exists('../etc/mostRecentTP.json')))
 $json = null;
else{
 $jsonFile = file_get_contents('../etc/mostRecentTP.json');
 $json = json_decode($jsonFile);
}

//set up variables for json
$mostRecent = new stdClass();
$mostRecent->type = "FeatureCollection";
$mostRecent->features = array();

if(!(is_dir('../data')))
 mkdir('../data');

$shefData = fopen('../data/tippingBucketShef.txt','w');
fwrite($shefData,"245...is this supposed to be a random number?\nSRAK58 PACR ".substr($yymmddhhii,4,6)."\nRR3ACR\n");
fwrite($shefData,"Here's a comment about something...\n");

$yymmddhhii = date('ymdHi');
foreach($stnObjects as $stn){
 if($json){
  foreach($json->features as $thing){
   if($thing->properties->stationTriplet == $stn->stationTriplet){
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
//ordinal 2 for tipping bucket
 $precReq = array('stationTriplets'=>$stn->stationTriplet, 'elementCd' => 'PREC', 'ordinal' => 2, 'beginDate' => $yesterdayLocal, 'endDate' => $todayLocal, 'insertOrUpdateBeginDate' => $revisedBeginDateLocal, 'filter' => 'ALL', 'unitSystem'=>'ENGLISH');
 $precResp = $soapclient->getInstantaneousDataInsertedOrUpdatedSince($precReq);
 print_r($stn->stationTriplet." ");
 if (isset($precResp->return->values)){
  foreach($precResp->return->values as $ob){
   if (isset($ob->value)){
  //convert to Z time
    $shefDate = date('Y-m-d H:i:s',strtotime('-'.$diffFromUtc.' hours',strtotime($ob->time)));
    $shefDateFormatted = date('ymdHi',strtotime($shefDate));
    //make sure this is actually new data... nrcs web service seems to give extra hours
    if (strtotime($shefDate) > strtotime($revisedBeginDate)){
     $shefString = ".A ".$stn->shefId." ".substr($shefDateFormatted,0,6)." Z DH".substr($shefDateFormatted,6,4)."/DC".$yymmddhhii."/PCIR2 ".$ob->value."\n";
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
 $point->properties->stationTriplet = $stn->stationTriplet;
 $point->properties->shefId = $stn->shefId;
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


file_put_contents('../etc/mostRecentTP.json',json_encode($mostRecent));
exit("done with script\n");

?>
