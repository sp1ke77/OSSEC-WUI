<?php
/*
 * Copyright (c) 2019 António 'Tó' Godinho <to@isec.pt>.
 * This program is free software; Distributed under the terms of the GNU GPL v3.
 */


## Note - I know that a substring of a timestamp isn't that clean, but this is built for high peformance on a huge table

# var $where is set in the file that calls me


## Work out how granular the graph times should be.  All graphs could be hour granular,but for a 6 month, this is stupidly more CPU intensive
if (isset($sqlto) && $sqlto != "") {
    $timediff = $sqlto - $sqlfrom;
} else {
    $timediff = $sqlfrom;
}
if ($timediff <= 0) {
    # Oops.
    $substrsize = 6;
    $zeros = "0000";
} elseif ($timediff < 60 && $timediff > 0) {
    $substrsize = 9;
    $zeros = "0";
} elseif ($timediff < 1000) {
    $substrsize = 8;
    $zeros = "00";
} elseif ($timediff < 150000) {
    $substrsize = 7;
    $zeros = "000";
} else {
    $substrsize = 6;
    $zeros = "0000";
}

$keyprepend = "";

# Depending on how you want the graph broken down... 
if (isset($_GET['breakdown']) && $_GET['breakdown'] == 'level') {

    # breakdown by level

    $keyprepend = "Level ";
    $querychart = "SELECT concat(substring(alert.timestamp, 1, $substrsize), '$zeros') as res_time, count(alert.id) as res_cnt, signature.level as res_value
		FROM alert, location, signature " . $wherecategory_tables . "
		WHERE 1=1
		AND alert.location_id=location.id
		AND alert.rule_id=signature.rule_id
		" . $where . "
		" . $wherecategory_and . "
		GROUP BY substring(alert.timestamp, 1, $substrsize), signature.level
		ORDER BY substring(alert.timestamp, 1, $substrsize), signature.level";

} elseif ((isset($_GET['breakdown']) && $_GET['breakdown'] == 'rule_id') || (isset($_GET['source']) && strlen($_GET['source']) > 0)) {
    # breakdown is set to source OR a source has been chosen

    $keyprepend = "Rule ";
    $querychart = "SELECT concat(substring(alert.timestamp, 1, $substrsize), '$zeros') as res_time, count(alert.id) as res_cnt, alert.rule_id as res_value
		FROM alert, location, signature " . $wherecategory_tables . "
		WHERE 1=1
		AND alert.location_id=location.id
		AND alert.rule_id=signature.rule_id
		" . $where . "
		" . $wherecategory_and . "
		GROUP BY substring(alert.timestamp, 1, $substrsize), alert.rule_id
		ORDER BY substring(alert.timestamp, 1, $substrsize), alert.rule_id";
} else {
    # Default - i.e. if not chosen, or if set to 'source'
    $querychart = "SELECT concat(substring(alert.timestamp, 1, $substrsize), '$zeros') as res_time, count(alert.id) as res_cnt, SUBSTRING_INDEX(location.name, ' ', 1) as res_value
		FROM alert, location, signature " . $wherecategory_tables . "
		WHERE 1=1
		AND alert.location_id=location.id
		AND alert.rule_id=signature.rule_id
		" . $where . "
		" . $wherecategory_and . "
		GROUP BY substring(alert.timestamp, 1, $substrsize), SUBSTRING_INDEX(location.name, ' ', 1)
		ORDER BY substring(alert.timestamp, 1, $substrsize), SUBSTRING_INDEX(location.name, ' ', 1)";

}

$stmt = $pdo->prepare($querychart);
$stmt->execute();

$tmpdate = "";
$timegrouping = array();
$arraylocations = array();
$arraylocationsunique = array();

$mainstring = "var chartData = [
	";

$anydata = 0;

while ($rowchart = $stmt->fetch(PDO::FETCH_ASSOC)) {
    # XXX Compile a list of all hosts, maybe a better way to do this than have an array the size of the alert table
    $locationname = preg_replace($glb_hostnamereplace, "", $rowchart['res_value']);
    array_push($arraylocations, $locationname);


    # for the first run, this needs setting
    if ($anydata == 0) {
        $anydata = 1;
        $tmpdate = $rowchart['res_time'];
    }

    # This alert is a new time 'group'...
    if ($tmpdate != $rowchart['res_time']) {
        # ...so what we have compiled needs to go to 'mainstring' (remember to use tmpdate, not the latest row time)
        $mainstring .= '	{"date": "' . date("Y", $tmpdate) . '-' . date("m", $tmpdate) . '-' . date("j", $tmpdate) . ', ' . date("G", $tmpdate) . ':' . date("i", $tmpdate) . '",';

        foreach ($timegrouping as $key => $val) {
            #append this location to array
            $mainstring .= "'" . htmlspecialchars($key) . "': " . $val . ", ";
        }

        $mainstring = substr($mainstring, 0, -2);
        $mainstring .= "},
	";

        # clear the array we have used to collect counts for a specific time 'group'
        unset($timegrouping);

        # reset the working time 'group' so the next if will be fired and we start collecting for the next time 'group'
        $tmpdate = $rowchart['res_time'];
    }

    # Oh look, this alert matches the time 'group' we are collecting for.
    if ($rowchart['res_time'] == $tmpdate) {
        $timegrouping[$locationname] = $rowchart['res_cnt'];

    }
}


#if(strlen($mainstring)>0){
if ($anydata == 1) {
    # only run this last bit if we have any info at all.. if not let the graph be empty
    # We have to run this cycle one more time to process the last row
    $mainstring .= '	{"date": "' . date("Y", $tmpdate) . '-' . date("m", $tmpdate) . '-' . date("j", $tmpdate) . ', ' . date("G", $tmpdate) . ':' . date("i", $tmpdate) . '",';
    foreach ($timegrouping as $key => $val) {
        #append this location to array
        $mainstring .= "'" . $key . "': " . $val . ", ";
    }
    $mainstring = substr($mainstring, 0, -2);
    $mainstring .= "},
		";
}

# If no end date, presume now, make graph end at today instead of auto scaling, so add a value for today (-1 due to the javascript way of counting months
if (strlen($inputto) == 0) {
    $mainstring .= "{\"date\": \"" . date("Y-n-j, G:i") . "\", 'now': 1},  ";
} else {
    $mainstring .= "{\"date\": \"" . date("Y-n-j, G:i", $lastgraphplot) . "\", 'now': 1},  ";
}

# tidy up the last concatanator comma, append a nice closing bracket, and dump what we have collected
$mainstring = substr($mainstring, 0, -3);
$mainstring .= "
	];";
//echo $mainstring;


## Right now to define graphs line dynamically from the location array
## As these have to go to a different place in the JS... and I cba to run this file twice, so just drop it in a var
$arraylocationsunique = array_unique($arraylocations);
asort($arraylocationsunique);



$series = "//SERIES";
foreach ($arraylocationsunique as $i => $location) {
    $series .= '	// Series ' . $i . '
			var series' . $i . ' = chart.series.push(new am4charts.LineSeries());
                        series' . $i . '.dataFields.valueY = "' . $keyprepend . $location . '";
                        series' . $i . '.dataFields.dateX = "date";
                        series' . $i . '.name = "' . $keyprepend . $location . '";
                        series' . $i . '.strokeWidth = 2;
                        series' . $i . '.tensionX = 0.8; //smoothen between 0 & 1
                        series' . $i . '.yAxis = valueAxis;
                        series' . $i . '.bullets.push(new am4charts.CircleBullet());
                        series' . $i . '.minBulletDistance = 25;
                        series' . $i . '.tooltipText = "{name}: [bold]{valueY}[/]";
                        series' . $i . '.tooltip.background.fillOpacity = 0.6;
                        //valueAxis.renderer.line.stroke = series' . $i . '.stroke;
                        //valueAxis.renderer.labels.template.fill = series' . $i . '.stroke;
	';
}
?>