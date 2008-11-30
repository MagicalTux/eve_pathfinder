<?php
require_once('EvePathfinder.class.php');

$sql = new mysqli('localhost', 'evetest', 'ZJwGnHPYuxULWYeS', 'db_eve');

// fetch all solar systems
$req = 'SELECT `solarSystemID`, `solarSystemName`, `security` FROM `mapSolarSystems`';
$res = $sql->query($req);
$systems = array();
while($row = $res->fetch_assoc()) $systems[$row['solarSystemID']] = $row;

$pathfinder = new EvePathfinder('eve_pathfinder', true, $systems);

// build "one hop" links
$req = 'SELECT `fromSolarSystemID`, `toSolarSystemID` FROM `mapSolarSystemJumps`';
$res = $sql->query($req);
while($row = $res->fetch_assoc()) {
	$pathfinder->createLink($row['fromSolarSystemID'], $row['toSolarSystemID'], $row['toSolarSystemID'], 1, EvePathfinder::SECURITY_MAX);
}

// build links for hops 2~255
for($i = 2; $i < 256; $i++) {
	echo 'Searching moar links for level '.$i."\n";
	$start = microtime(true);
	$found = $pathfinder->determineMoarLinks($i);
	$end = microtime(true);

	printf("Found %d links in %01.1f secs\n", $found, $end - $start);

	if ($found == 0) break; // no more depth
}

/* File structure
 * 
 * solar system ID
 * solar system security (32bits int), (value+1)*1000000000
 * solar system name length (1 byte)
 * solar system name (variable)
 * number of links
 *  | next_hop (4 bytes)
 *  | number of hops (1 byte)
 *  | security (4 bytes)
 */

