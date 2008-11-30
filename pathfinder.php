<?php
require_once('EvePathfinder.class.php');

$origin = $_GET['origin'];
$target = $_GET['target'];
//$security = (float)$_GET['security'];

function show_result($res) {
	$first = true;
	echo '<table border="1">';
	foreach($res as $row) {
		if ($first) {
			echo '<tr>';
			foreach(array_keys($row) as $col) {
				echo '<th>'.$col.'</th>';
			}
			echo '</tr>';
			$first = false;
		}

		echo '<tr>';
		foreach($row as $val) {
			echo '<td>'.$val.'</td>';
		}
		echo '</tr>';
	}
	echo '</table>';
}

$start = microtime(true);
$pathfinder = new EvePathfinder('eve_pathfinder');
$mid = microtime(true);
$res = $pathfinder->lookup($origin, $target);
$end = microtime(true);
if (is_null($res)) {
	printf("Not found in %01.2fms (index loading: %01.2fms; lookup: %01.2fms)", ($end - $start) * 1000, ($mid - $start) * 1000, ($end - $mid) * 1000);
	exit;
}

$display_res = array();

foreach($res as $hop) {
	$hop_at = $hop['hop'];
	$hop_info = $pathfinder->systemInfo($hop_at);
	$display_res[] = $hop + $hop_info;
}

show_result($display_res);
printf("Lookup in %01.2fms using %d kB of RAM (index loading: %01.2fms; lookup: %01.2fms)", ($end - $start) * 1000, memory_get_peak_usage()/1024, ($mid - $start) * 1000, ($end - $mid) * 1000);

