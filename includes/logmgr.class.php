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

define('LOG_ODEFAULT', 0);	/* Default options value	*/
define('LOG_OSYNC',    1);	/* Sync at each add()		*/

define('LOG_EMERG',    0);	/* Emergecy: System is unusable. Complete restart/checks must be done.  */
define('LOG_ALERT',    1);	/* Alert:    Process can't continue working. Manual action must be done.*/
define('LOG_CRIT',     2);	/* Crit:     Process was entered in an unknown state.                   */
define('LOG_ERR',      3);	/* Error:    Error is returned from function, etc...                    */
define('LOG_WARNING',  4);	/* Warning:  Message have to be checked further...                      */
define('LOG_NOTICE',   5);	/* Notice:   Message could be important/interresting to know.           */
define('LOG_INFO',     6);	/* Info:     Message is symply informational...                         */
define('LOG_DEBUG',    7);	/* Debug:    Message is for debugging informations only.                */

class LogMgr {
	public $logFile;
	public $progName;
	public $options;
	public $context;
	public $severity;

	private $sync;
	private $openFile;
	private $headerLast;

	function __construct($lf, $pn, $opts = 0, $ctx = "") {
		$this->logFile   = $lf;
		$this->progName  = $pn;
		$this->setSeverity("I");
		$this->setContext($ctx);
		$this->setOptions($opts);
		$this->reopen();
	}

	function __destruct() {
		@fclose($this->openFile);
	}

	function setSeverity($v) { $this->severity = $v; }
	function setContext($v)  { $this->context  = $v ? "[".$v."] " : ""; }

	function setOptions($opts) {
		$this->options = $opts;
		$this->sync    = ($opts & LOG_OPT_SYNC);
	}

	function reopen() {
		if ( $this->openFile ) fclose($this->openFile);
		$inf = @stat($this->logFile);
		if ( ! (($inf === false) || ($inf['size'] == 0)) ) {
			$this->headerLast = date('Ymd', $inf['mtime']);
		}
		$this->openFile = @fopen($this->logFile, "a+t");
	}

	function addHeader() {
		fputs($this->openFile, date("- l, jS \of F Y ").$this->progName."\n");
		$this->headerLast = date('Ymd');
	}

	function add($msg) {
		if ( $this->headerLast != date('Ymd') ) $this->addHeader();
		fputs($this->openFile, $this->severity.date(" H:i:s ").$this->context.$msg."\n");
		if ( $this->sync ) fflush($this->openFile);
	}

	function flush() {
		fflush($this->openFile);
	}
}
?>
