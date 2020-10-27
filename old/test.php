#!/bin/php

<?php

$myDogNames = array("Maddie", "Gracie", "Aya");
$myDogs = array();

for($i=0;$i<count($myDogNames);$i++){
 $aDog = (object)['Name' => $myDogNames[$i]];
 $myDogs[] = $aDog;
}

foreach($myDogs as $dog){
 $dog->sound = "woof";
}

$myDogs[0]->sound = "whine";

print_r($myDogs);

?>
