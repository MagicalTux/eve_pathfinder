<?php

function show_timing($start, $caption) {
	$end = microtime(true);
	$diff = $end - $start;

	printf("%s: %01.2fms\n", $caption, $diff * 1000);
}

require_once('EvePathfinder.class.php');

$start = microtime(true);
$pathfinder = new EvePathfinder('eve_pathfinder');
show_timing($start, 'Loading index file');

$start = microtime(true);
var_dump($pathfinder->lookup(30000001, 30000005));
show_timing($start, 'Lookup with one level');

$start = microtime(true);
$res = $pathfinder->lookup(30003860, 30005035);
show_timing($start, 'Lookup with moar levels');
foreach($res as $hop) {
	$hop_at = $hop['hop'];
	$hop_info = $pathfinder->systemInfo($hop_at);
	$hop_name = $hop_info['name'];
	echo "Hop at $hop_name [$hop_at]\n";
}

