Hi Michelle
#!/usr/bin/php
<?php
// At start of script

date_default_timezone_set('UTC');
$time_start = microtime(true); 
$today = date("Y-m-d");
$yesterday = date('Y-m-d', strtotime('1 day ago'));
$lastWeek = date('Y-m-d', strtotime('7 days ago'));
$lastMonth = date('Y-m-d', strtotime('1 month ago'));
$beginMonth = substr($lastMonth,5,2);
echo $lastMonth."\n";
$unixDateNow = date_format(new DateTime(), 'U') * 1000;

//Create GeoJSON
$sweData = new stdClass();
$sweData->type = "FeatureCollection";
$sweData->features = array();
//WTEQV is SWE Avg

//Fetch list of station triplets for AK SnowTel stations reporting SWE
$client = new SoapClient("http://www.wcc.nrcs.usda.gov/awdbWebService/services?WSDL");
$opts = new \StdClass;
$opts->stateCds = 'AK';
$opts->elementCds = 'WTEQ';
$opts->networkCds = "SNTL";
$opts->countyNames = 'Anchorage';
$opts->minElevation = '2100.00';
$opts->maxElevation = '3000.00';
$opts->logicalAnd = 'true';
$result = $client->getStations($opts);
$arr = $result->return;
//$opts->networkCds = "SCAN";
//$result = $client->getStations($opts);
//$arr1 = $result->return;
//$arr = array_merge($arr,$arr1);
//$arr = $result->return;

print_r($arr);
$count = 0;   //////////////////////////////////////////////Counter for testing to limit calls

//go through list of stations and retrieve SWE and Avg SWE - compute %
foreach($arr as $siteTriplet){
	
	//build swe query
	$getDataOpts = new \StdClass;
	$getDataOpts->stationTriplets = $siteTriplet;
	$getDataOpts->elementCd = 'WTEQ';
	$getDataOpts->ordinal = 1;
	$getDataOpts->duration = 'DAILY';
	$getDataOpts->getFlags = 'true';
	$getDataOpts->beginDate = $lastMonth;
	$getDataOpts->endDate = $today;
	//fetch swe
	try {
		$getDataRes = $client->getData($getDataOpts);
	} catch (exception $e) {
		print $e."\n";
	}
	//copy results to object
	$getDataArr = $getDataRes->return;
	
	//build swe avg query
	$getAvgDataOpts = new \StdClass;
	$getAvgDataOpts->stationTriplets = $siteTriplet;
	$getAvgDataOpts->elementCd = 'WTEQ';
	$getAvgDataOpts->ordinal = 1;
	$getAvgDataOpts->duration = 'DAILY';
	$getAvgDataOpts->getFlags = 'true';
	
	//Web service breaks when going around the end of year (12 - 01) so in Jan, just get Jan data
	if ($beginMonth == 12){
		$getAvgDataOpts->beginMonth = "01";
		$getAvgDataOpts->beginDay = "01";
	}else{
		$getAvgDataOpts->beginMonth = substr($lastMonth,5,2);
		$getAvgDataOpts->beginDay = substr($lastMonth,8,2);
	}
	$getAvgDataOpts->endMonth = substr($today,5,2);
	$getAvgDataOpts->endDay = substr($today,8,2);
	//fetch averages
	try {
		$getAvgDataRes = $client->getAveragesData($getAvgDataOpts);
  } catch (exception $e) {
		print $e."\n";
	}
	//copy results
	$getAvgDataArr = $getAvgDataRes->return;
	
	//if we had the end of year problem we need to pad the avg array
	//TODO: just get the dec data and jam it in here
	if ($beginMonth == 12){
		if (isset($getAvgDataArr->values) && isset($getDataArr->values)) {
			$avgNum = count($getAvgDataArr->values)."\n";
			$valNum = count($getDataArr->values)."\n";
			$diff = $valNum - $avgNum;
			//pad with null values
			for ($i = 1; $i <= $diff; $i++) {
				array_unshift($getAvgDataArr->values,NULL);
			}
		}
	}
	
	
	if (isset($getDataArr->values)) {
		//print_r($getDataArr->values);
		$getStnMetaOpts = new \StdClass;
		$getStnMetaOpts->stationTriplet = $siteTriplet;
		$getStnMetaRes = $client->getStationMetadata($getStnMetaOpts);
		$getStnMetaArr = $getStnMetaRes->return;
                print_r($getStnMetaArr);
		//echo $getStnMetaArr->shefId." ";
		//echo $getStnMetaArr->name."\n";
		$point = new stdClass();
		$point->type = "Feature";
		$point->geometry = new stdClass();
		$point->geometry->type = "Point";
		$point->geometry->coordinates = array( $getStnMetaArr->longitude, $getStnMetaArr->latitude);
		$point->metadata = new stdClass();
		$point->metadata->agency = "NWS";
		$point->metadata->created = $unixDateNow;
		$point->properties = new stdClass();
		$point->properties->name = $getStnMetaArr->name;
		$point->properties->elev = $getStnMetaArr->elevation;
		$point->properties->lid = $getStnMetaArr->shefId;
		$point->properties->datatype = "sw";
		$point->properties->dataname = "Snow Water Equivalent";
		$point->properties->dataunit = "inches";
		$point->properties->data = array();
		$workingDate = $getDataOpts->beginDate;
		if (is_array($getDataArr->values) || $getDataArr->values instanceof Traversable){
			foreach ($getDataArr->values as $key => $val){
				if (isset($getAvgDataArr->values)) {
					$avg = $getAvgDataArr->values[$key];
					if ($avg == NULL){
						$pct = NULL;
					}else{
						$pct = sprintf("%01.0f",$val / $avg * 100);
					}				
					$point->properties->avgIncluded = 1;
					$point->properties->data[] = array( date_format(new DateTime($workingDate),'U') * 1000, floatval($val));
          print("workingDate is ".$workingDate."\n");
          print("other date thing is ".date_format(new DateTime($workingDate),'U')."\n");
          print_r($point->properties->data."\n");
					$point->properties->pct[] = array( date_format(new DateTime($workingDate),'U') * 1000, floatval($pct));
					$point->properties->avg[] = array( date_format(new DateTime($workingDate),'U') * 1000, floatval($avg));
           
				}else{
					$point->properties->avgIncluded = 0;
					$point->properties->data[] = array( date_format(new DateTime($workingDate),'U') * 1000 ,floatval($val));
				}
				$workingDate = date('Y-m-d', strtotime($workingDate."+1 day"));
			}
		}else{
      print("woohoo skipping stuff");
			continue;
		}
		
		$sweData->features[] = $point;
	}
	$count++;
	//if ($count > 4){break;}
}
//print_r($sweData);
file_put_contents("cms_publicdata+nrcs_sweMichelle.json", json_encode($sweData));
//exec("/usr/bin/rsync -vzrt cms_publicdata+nrcs_swe.json 10.251.3.37::nids_incoming_aprfc");
//echo 'Total execution time in seconds: ' . (microtime(true) - $time_start)."\n";
?>
