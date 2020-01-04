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

define('RSB_WINDOW_SIZE',    50);
define('RSB_CACHE_LIFETIME', 60);

class ResultSetBrowser {

	public $pge;			/* Requested page (1..x) */
	public $win;			/* Size of the page (window) */
	public $count;			/* Number of entries (lines) processed or in the cache file */

	private $cacheId;		/* Unique id of this result set */
	private $cacheFile;		/* Cache file to use */
	private $cacheHandle;		/* Handle of the open cache file */
	private $cacheLifeTime; 	/* Life time of the cache file */
	private $cacheCreated;  	/* Timestamp when this cache was created */

	private $navBar;		/* Navigation bar */
	private $resultSet;		/* Result set window to show */

	private $startLn;		/* Calculated start of the result set window (line number) */
	private $endLn;			/* Calculated end ... */

	function __construct($rsbwin = RSB_WINDOW_SIZE, $key = "", $lifeTime = RSB_CACHE_LIFETIME) {
		$this->pge = isset($_REQUEST['rsbpge']) ? $_REQUEST['rsbpge'] : 1;
		$this->win = isset($_REQUEST['rsbwin']) ? $_REQUEST['rsbwin'] : $rsbwin;
		$this->setIndex();
 		if ( ! empty($key) ) $this->setCache($key, $lifeTime);
	}

	function __destruct() {
		if ( $this->cacheHandle ) fclose($this->cacheHandle);
	}

	function setIndex($page = 0) {
		if ( $page ) $this->pge = $page;
		$this->startLn = (($this->pge - 1) * $this->win) + 1;
		$this->endLn = $this->startLn + $this->win;
	}

	function setCache($key, $lifeTime) {
		$this->cacheId   = md5($key);
		$this->cacheFile = "/tmp/ResultSetBrowser-".$this->cacheId.".cache";
		$this->cacheLifeTime = $lifeTime;
		$this->resultSet = array();
		if ( ($this->cacheHandle = @fopen($this->cacheFile, "r")) ) {
			list($this->count, $this->cacheCreated) = explode(";", trim(fgets($this->cacheHandle)));
			if ( (time() - $this->cacheCreated) >= $this->cacheLifeTime ) {
				$this->dropResult();
			}else{
				if ( $this->endLn > $this->count )
					$this->endLn = $this->count;

				for ( $i = 1 ; $i < $this->startLn ; $i++ )
					fgets($this->cacheHandle); // Skip the lines until the start of the requested page.
				
				for ( ; $i < $this->endLn ; $i++ )
					$this->resultSet[] = base64_decode(trim(fgets($this->cacheHandle)));
			}
		}else{
			$this->count =
			$this->cacheCreated = 0;
		}
	}

	function openResult() {
		if ( ! empty($this->cacheId) ) {
			if ( $this->cacheHandle ) fclose($this->cacheHandle);
			if ( ! ($this->cacheHandle = @fopen($this->cacheFile, "w+r")) ) die("Unable to create cache file !");
			@chmod($this->cacheFile, 0600);
			ftruncate($this->cacheHandle, 0);
			fwrite($this->cacheHandle, "0;0                                          \n");
		}
		$this->count = 0;
		$this->resultSet = array();
	}

	function closeResult() {
		if ( ! empty($this->cacheId) ) {
			$this->cacheCreated = time();
			fseek($this->cacheHandle, 0);
			fwrite($this->cacheHandle, $this->count.';'.$this->cacheCreated);
			fflush($this->cacheHandle);
			fgets($this->cacheHandle); // Now, the file offset is on the first line of the result set
		}
		reset($this->resultSet);
	}

	function dropResult() {
		if ( $this->cacheHandle ) fclose($this->cacheHandle);
		if ( $this->cacheFile )   @unlink($this->cacheFile); // Test in case it is called when the cache is not used
		$this->count =
		$this->cacheCreated = 0;
		$this->cacheHandle = NULL;
		$this->resultSet = array();
	}

	function add($str) {
		$this->count++;

		if ( $this->cacheId )
			fwrite($this->cacheHandle, base64_encode($str)."\n");

		if ( $this->count >= $this->startLn
		&&   $this->count <  $this->endLn   )
			$this->resultSet[] = $str;
	}

	function get() {
		$r = each($this->resultSet);
		return $r ? $r[1] : FALSE;
	}
	
	function getFull() {
		return $this->cacheHandle ? base64_decode(trim(fgets($this->cacheHandle))) : FALSE;
	}

	function isEmpty() {
		return ! $this->count;
	}

	function getUrlParams($sep = "") {
//		if ( ceil($this->count / $this->win) < 2 ) return "";
		return $sep.'rsbpge='.$this->pge.'&rsbwin='.$this->win;
	}

	function getNav($args = array()) {
		if ( $this->navBar ) return $this->navBar;

		$show_all     = false;
		$prev_next    = true;
		$prev_text    = '&laquo;';
		$next_text    = '&raquo;';
		$end_size     = 2;
		$mid_size     = 2;

		extract($args);

		$current      = $this->pge;
		$total        = ceil($this->count / $this->win);
		$add_fragment = '&rsbwin='.$this->win.($add_url ? '&'.$add_url : '');

		if ( $total < 2 ) return; /* Show nothing if not paged */

		$current  = (int) $current;
		$end_size = 0  < (int) $end_size ? (int) $end_size : 1; // Out of bounds?  Make it the default.
		$mid_size = 0 <= (int) $mid_size ? (int) $mid_size : 2;
		$r = '';
		$page_links = array();
		$n = 0;
		$dots = false;

		$page_links[] = '<span class="pagenav">';

		if ( $prev_next && $current && 1 < $current ) {
			$link = "?rsbpge=".($current-1).$add_fragment;
			$page_links[] = '<a class="pagenav prev" href="'.$link.'">'.$prev_text.'</a>';
		}

		for ( $n = 1; $n <= $total; $n++ ) {
			if ( $n == $current ) {
				$page_links[] = '<span class="pagenav current">'.$n.'</span>';
				$dots = true;
			}else{
				if ( $show_all || ( $n <= $end_size || ( $current && $n >= $current - $mid_size && $n <= $current + $mid_size ) || $n > $total - $end_size ) ) {
					$link = "?rsbpge=$n".$add_fragment;
					$page_links[] = '<a class="pagenav numbers" href="'.$link.'">'.$n.'</a>';
					$dots = true;
				}else if ( $dots && ! $show_all ) {
					$page_links[] = '<span class="pagenav dots">&hellip;</span>';
					$dots = false;
				}
			}
		}

		if ( $prev_next && $current && ( $current < $total || -1 == $total ) ) {
			$link = "?rsbpge=".($current+1).$add_fragment;
			$page_links[] = '<a class="pagenav next" href="' . $link . '">' . $next_text . '</a>';
		}

		$page_links[] = '</span>';
		
		return $this->navBar = join("&nbsp;", $page_links);
	}
}
