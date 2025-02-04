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

	private	$pl;		// array of audio files [0] = path (relative to $$this->mp3dir), [1] = filename
	private $pending;	// If pending changes need to be saved on disk
	private $autoUpd;	// If true, update() is called at object's destroy time...
	private $mp3dir;	// The directory of 'search.php' who is supposed to be in the root of the mp3 tree.

	function __construct($pl, $load = TRUE, $autoUpdate = TRUE) {
		$this->playlist = $pl;
		$this->count    = 0;
		$this->pending  = FALSE;
		$this->autoUpd  = $autoUpdate;
		$this->mp3dir   = dirname($_SERVER['SCRIPT_FILENAME']);
		if ( $load ) $this->load();
	}
	function __destruct() {
		if ( $this->autoUpd )
			$this->update();
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
		fclose($list);
		$this->sort();
	}

	function first() {
		return reset($this->pl);
	}

	function next() {
		return next($this->pl);
	}

	function current() {
	        return current($this->pl);
        }

	function outputm3uheader($filename) {
		header('Content-type: audio/x-mpegurl');
		header('Content-disposition: attachment; filename="'.$filename.'.m3u"');
	}

	function outputm3u($baseurl) {
		foreach ( $this->pl as $song )
			if ( ! empty($song) )
				echo $baseurl.'/'.$song[0].'/'.str_replace('%2F','/',rawurlencode($song[1]))."\n";
	}

	function output($root = FALSE) {
		if ( $root === FALSE ) $root = $this->mp3dir;
		foreach ( $this->pl as $song )
			if ( ! empty($song) )
				echo $root.'/'.$song[0].'/'.$song[1]."\n";
	}

	function exist($file, $dir = "") {
		foreach ( $this->pl as $id => $song ) {
			if ( $dir && strcmp($song[0], $dir) != 0 )
				continue;
			if ( strcmp($song[1], $file) == 0 )
				return $id;
		}
		return FALSE;
	}

	function add($file, $dir) {
		if ( $this->exist($file, $dir) === FALSE
		&&   file_exists($this->mp3dir.'/'.$dir.'/'.$file) ) {
			$this->pl[] = array($dir, $file);
			$this->count++;
			$this->sort();
			return $this->pending = TRUE;
		}
		return FALSE;
	}

	function remid($id) {
		$this->pl[$id] = array();
		$this->count--;
		return $this->pending = TRUE;
	}

	function rem($file, $dir) {
		$id = $this->exist($file, $dir);
		if ( $id !== FALSE )
			return $this->remid($id);
		return FALSE;
	}

	function chgid($id, $oldFile, $newDir, $newFile = "") {
		if ( ! $newFile ) $newFile = $oldFile; else $sort = TRUE;
		if ( file_exists($this->mp3dir.'/'.$newDir.'/'.$newFile) ) {
			$this->pl[$id] = array($newDir, $newFile);
			if ( $sort ) $this->sort();
			return $this->pending = TRUE;
		}
		return FALSE;
	}

	function chg($oldFile, $newDir, $newFile = "") {
		if ( ($id = $this->exist($oldFile)) !== FALSE )
			return $this->chgid($id, $oldFile, $newDir, $newFile);
	        return FALSE;
	}

	function findFileSubdir($song) {
		$f = @popen('find '.$this->mp3dir.' -not -path "*/.*" -type f -name '.escapeshellarg($song), 'r');
		while ( ($line = @fgets($f)) )
			$r = basename(dirname($line));
		fclose($f);
		return empty($r) ? FALSE : $r;
	}

	function fixlist() {
		$pending = FALSE;
		foreach ( $this->pl as $id => $song ) {
			if ( empty($song) )
				continue;
			if ( ! file_exists($this->mp3dir.'/'.$song[0].'/'.$song[1]) ) {
				$newdir = $this->findFileSubdir($song[1]);
				if ( $newdir )
					$pending = $this->chgid($id, $song[1], $newdir) || $pending;
				else	$pending = $this->remid($id) || $pending;
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
				fprintf($list, $this->mp3dir.'/'.$song[0].'/'.$song[1]."\n");
		fclose($list);
		$this->pending = FALSE;
		return TRUE;
	}
}
?>
