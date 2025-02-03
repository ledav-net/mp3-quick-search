<?
/*
 * MP3 Quick Search v1.0.0
 *
 * Copyright 2005-2019 by David De Grave <david@ledav.net>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 *
 */

class PlayList {
	public	$playlist;	// Full path and file name of the playlist
	public  $count;		// Total number of entries

	private	$pl;		// array of audio files [0] = path (relative to $PL_MP3DIR), [1] = filename
	private $pending;	// If pending changes need to be saved on disk

	function __construct($pl, $load = TRUE) {
		$this->playlist = $pl;
		$this->count    = 0;
		$this->pending  = FALSE;
		if ( $load ) $this->load();
	}

	static function myCompare($_a, $_b) {
        	return strcasecmp($_a[1], $_b[1]);
	}
	function sort() {
		uasort($this->pl, array('self', 'myCompare'));
	}

	function load() {
		$this->count = 0;
		$this->pl = array();
		$list = fopen($this->playlist, "a+");
		if ( flock($list, LOCK_SH) !== TRUE ) {
			echo("ERROR LOCKING ".$this->playlist." in SH mode !");
			exit(1);
		}
		while ( $s = trim(@fgets($list)) ) {
			$this->pl[] = array(basename(dirname($s)), basename($s));
			$this->count++;
		}
		flock($list, LOCK_UN);
		fclose($list);
		$this->sort();
	}

	function first() {
		reset($this->pl);
		return current($this->pl);
	}

	function next() {
		return next($this->pl);
	}

	function outputm3uheader($filename) {
		header('Content-type: audio/x-mpegurl');
		header('Content-disposition: attachment; filename="'.$filename.'.m3u"');
	}

	function outputm3u($baseurl) {
		foreach ( $this->pl as $song ) {
			echo $baseurl.'/'.$song[0].'/'.str_replace('%2F','/',rawurlencode($song[1]))."\n";
		}
	}

	function exist($file, $dir = "") {
		foreach ( $this->pl as $pf ) {
			if ( $dir[0] && strcmp($pf[0], $dir) != 0 )
				continue;
			if     ( strcmp($pf[1], $file) == 0 )
				return TRUE;
		}
		return FALSE;	
	}

	function add($file, $dir) {
		if ( ! $this->exist($file, $dir) && file_exists($dir.'/'.$file) ) {
			$this->pl[] = array($dir, $file);
			$this->count++;
			$this->pending = TRUE;
			$this->sort();
			return TRUE;
		}	
		return FALSE;
	}

	function remid($id) {
		$this->pl[$id] = array();
		$this->count--;
		$this->pending = TRUE;
		return TRUE;
	}

	function rem($file, $dir) {
		foreach ( $this->pl as $id => $song ) {
			if ( strcmp($song[0], $dir  ) == 0
			&&   strcmp($song[1], $file ) == 0 ) {
				return $this->remid($id);
			}
		}
		return FALSE;
	}

	function cleanup() {
		$pending = FALSE;
		foreach ( $this->pl as $id => $song ) {
			if ( ! file_exists($song[0].'/'.$song[1]) ) {
				$pending = $this->remid($id);
			}
		}
		return $pending;
	}

	function update() {
		if ( ! $this->pending )
			return FALSE;
		$list = fopen($this->playlist, "wt");
		if ( flock($list, LOCK_EX) !== TRUE ) {
			echo("ERROR LOCKING ".$this->playlist." in EX mode !");
			exit(2);
		}
		foreach ( $this->pl as $song )
			if ( ! empty($song) )
				fprintf($list, $song[0].'/'.$song[1]."\n");
		fflush($list);
		flock($list, LOCK_UN);
		fclose($list);
		$this->pending = FALSE;
		return TRUE;
	}
}
?>
