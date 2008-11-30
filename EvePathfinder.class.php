<?php

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

// TODO: implement functions to add a level 1 link, or remove a link. Those
// functions would run link propagation only for this link, making
// creation/removal of level 1 links a really fast operation (ie. without need
// to rebuild the whole db)

// TODO: provide a function to count hops to a destination without actually
// doing the lookup. This is already available via getNextStep(), but a func
// returning an int directly would be better (destinationWeight ?)

// Counting steps to a destination is a O(1) operation
// Building the path to a destination is a O(n) operation

class EvePathfinder {
	private $prefix;
	private $fp_main;
	private $fp_idx;
	private $readwrite;

	private $index_pos = array();
	private $index_seq = array();

	const SECURITY_MAX = 2000000000;

	function __construct($prefix, $readwrite = false, &$systems = NULL) {
		$this->prefix = $prefix;
		$this->readwrite = $readwrite;
		if ($readwrite) {
			if (!file_exists($prefix.'.dat')) touch($prefix.'.dat');
			if (!file_exists($prefix.'.idx')) touch($prefix.'.idx');
			$this->fp_main = fopen($prefix.'.dat', 'r+b');
			$this->fp_idx = fopen($prefix.'.idx', 'r+b');
		} else {
			$this->fp_main = fopen($prefix.'.dat', 'rb');
			$this->fp_idx = fopen($prefix.'.idx', 'rb');
		}

		if (!$this->fp_main) throw new Exception('Failed opening main file');
		if (!$this->fp_idx) throw new Exception('Failed opening index file');

		$this->readIndexFile();
		if (($readwrite) && (!is_null($systems))) $this->addMissingSystems($systems);
	}

	function __destruct() {
		fclose($this->fp_main);
		fclose($this->fp_idx);
	}

	protected function readIndexFile(&$systems = NULL) {
		$this->index_pos = array();
		$this->index_seq = array();

		fseek($this->fp_idx, 0, SEEK_SET);
		while(!feof($this->fp_idx)) {
			$idx_data = @unpack('Vsystem_id/Vpos', fread($this->fp_idx, 8));
			if (!$idx_data) break;
			$this->index_pos[$idx_data['system_id']] = $idx_data['pos'];
			$this->index_seq[$idx_data['system_id']] = count($this->index_seq);
		}
	}

	protected function addMissingSystems(&$systems) {
		if (!$this->readwrite) throw new Exception('Not available in read/only mode!');
		$total_count = count($systems);

		// now, locate systems missing in our main file!
		foreach($systems as $system_id => $data) {
			if (isset($this->index_pos[$system_id])) continue;
			//echo 'Adding system '.$data['solarSystemName'].' to file...'."\r";
			fseek($this->fp_main, 0, SEEK_END);
			
			// store in our index
			$this->index_pos[$system_id] = ftell($this->fp_main);
			$this->index_seq[$system_id] = count($this->index_seq); // will increment
	
			// write system
			fwrite($this->fp_idx, pack('VV', $system_id, ftell($this->fp_main)));
	
			// write base data
			fwrite($this->fp_main, pack('VVVC', $system_id, ($data['security']+1)*1000000000, $total_count, strlen($data['solarSystemName'])) . $data['solarSystemName']);
			// write placeholder for system's links
			fwrite($this->fp_main, str_repeat("\x00", $total_count*9));
		}
	}

	public function createLink($from, $to, $next, $hops, $security) {
		if (!$this->readwrite) throw new Exception('Not available in read/only mode!');
		if ($from == $to) return false;

		if (!isset($this->index_pos[$from])) throw new Exception('"From" system not found');
		fseek($this->fp_main, $this->index_pos[$from]);

		$info = unpack('Vsystem_id/Vsecurity/Vsystem_count/Cnamelen', fread($this->fp_main, 13));
		if ($info['system_id'] != $from) throw new Exception('Corrupted data file!');

		if (!isset($this->index_seq[$to])) throw new Exception('"To" system not found');
		$to_seq = $this->index_seq[$to];
		if ($to_seq >= $info['system_count']) throw new Exception('Data file needs repack before being able to accept this link!');

		//$name = fread($this->fp_main, $info['namelen']);
		//var_dump($name);

		// compute location for this link
		$loc = $this->index_pos[$from] + 13 + $info['namelen'] + (9 * $to_seq);

		fseek($this->fp_main, $loc, SEEK_SET);
		$info = unpack('Vnext_hop/Chopscount/Vsecurity', fread($this->fp_main, 9));
		if ($info['next_hop'] > 0) {
			if ($info['hopscount'] <= $hops) return false; // don't care about more/equally expensive links!
		}

		// write link!
		fseek($this->fp_main, $loc, SEEK_SET);
		fwrite($this->fp_main, pack('VCV', $next, $hops, $security));
		return true;
	}

	public function systemInfo($from, $links = false) {
		if (!isset($this->index_pos[$from])) throw new Exception('"From" system not found');
		fseek($this->fp_main, $this->index_pos[$from]);

		$info = unpack('Vsystem_id/Vsystem_security/Vsystem_count/Cnamelen', fread($this->fp_main, 13));
		if ($info['system_id'] != $from) throw new Exception('Corrupted data file!');

		$info['name'] = fread($this->fp_main, $info['namelen']);
		$info['system_security'] = $this->fromIntSecurity($info['system_security']);

		// TODO: read links if asked to

		return $info;
	}

	public function getNextStep($from, $to) {
		if ($from == $to) return NULL;

		if (!isset($this->index_pos[$from])) throw new Exception('"From" system not found');
		fseek($this->fp_main, $this->index_pos[$from]);

		$info = unpack('Vsystem_id/Vsecurity/Vsystem_count/Cnamelen', fread($this->fp_main, 13));
		if ($info['system_id'] != $from) throw new Exception('Corrupted data file!');

		if (!isset($this->index_seq[$to])) throw new Exception('"To" system not found');
		$to_seq = $this->index_seq[$to];
		if ($to_seq >= $info['system_count']) throw new Exception('Data file needs repack before being able to accept this link!');

		// compute location for this link
		$loc = $this->index_pos[$from] + 13 + $info['namelen'] + (9 * $to_seq);
		fseek($this->fp_main, $loc, SEEK_SET);
		return unpack('Vnext_hop/Chopscount/Vsecurity', fread($this->fp_main, 9));
	}

	public function lookup($from, $to) {
		$res = array();
		$pos = $from;

		$res[] = array(
			'hop' => $from,
			'hops_count' => 0,
			'security' => 1,
		);

		while(1) {
			$next = $this->getNextStep($pos, $to);
			if (is_null($next)) break;
			$next['security'] = $this->fromIntSecurity($next['security']);
			$res[] = array(
				'hop' => $next['next_hop'],
				'hops_count' => $next['hopscount']-1,
				'security' => $next['security'],
			);
			$pos = $next['next_hop'];
			if ($pos === 0) return NULL;
		}

		$res[0]['hops_count'] = count($res)-1;
		if (isset($res[1])) $res[0]['security'] = $res[1]['security'];

		return $res;
	}

	public function determineMoarLinks($level) {
		if (!$this->readwrite) throw new Exception('Not available in read/only mode!');
		// search all known solar systems for links with number of hops "$level-1", then advertise to all nodes with hops=1
		$count = count($this->index_pos);
		$i = 0;
		$found_count = 0;
//		$ppc = 0;
		foreach($this->index_pos as $system_id => $main_pos) {
//			$pc = round((++$i * 100 / $count), 1);
//			if ($pc != $ppc) {
//				printf("Fill: %01.1f%%\r", $pc);
//				$ppc = $pc;
//			}
			// get all links for this system
			$advertise = array(); // links to be advertised
			$listeners = array(); // links going to listen to our ads

			fseek($this->fp_main, $main_pos, SEEK_SET);
			$main_info = unpack('Vsystem_id/Vsecurity/Vsystem_count/Cnamelen', fread($this->fp_main, 13));
			if ($main_info['system_id'] != $system_id) throw new Exception('Corrupted data file!');
			if ($main_info['security'] < 0) throw new Exception('Corrupted system!');

			$base_loc = $main_pos + 13 + $main_info['namelen'];
			fseek($this->fp_main, $base_loc, SEEK_SET);
			$base_data = fread($this->fp_main, 9*($main_info['system_count']));

			foreach($this->index_seq as $target_system_id => $target_seq) {
				if ($target_seq > $main_info['system_count']) break;
				
				$t_info = unpack('Vnext_hop/Chopscount/Vsecurity', substr($base_data, (9*$target_seq), 9));
				$t_info['target_system'] = $target_system_id;

				if ($t_info['hopscount'] == 1) $listeners[] = $target_system_id;
				if ($t_info['hopscount'] == ($level-1)) $advertise[] = $t_info;
			}

			foreach($listeners as $prev_hop) {
				foreach($advertise as $next_hop_info) {
					//echo "New link: $prev_hop => ".$next_hop_info['target_system']." via $system_id in $level hops\n";
					if ($next_hop_info['security'] < 0) throw new Exception('that is not possible!');
					if ($this->createLink($prev_hop, $next_hop_info['target_system'], $system_id, $level, min($next_hop_info['security'], $main_info['security'])))
						$found_count++;
				}
			}
		}
		return $found_count;
	}

	protected function toIntSecurity($sec) {
		return ($sec + 1) * 1000000000;
	}

	protected function fromIntSecurity($sec) {
		return ($sec / 1000000000) - 1;
	}
}
