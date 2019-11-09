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

class Auth {
	protected $authFile;
	protected $authGroupFile;
	protected $authenticated = false;

	public $login;
	public $groups = array();

	function Auth($useApacheAuth = false, $user = NULL, $authF = "../auth/passwd", $authGF = "../auth/group") {

		if ( ! $useApacheAuth ) {
			/* Not using apache for authentication. Do it ourself... */
			if ( empty($_SERVER['PHP_AUTH_USER']) ) {
				$this->authenticate("Protected content");
				exit;
			}
			$this->authFile = $authF;
			$this->authGroupFile = $authGF;

			$f = fopen($this->authFile, 'r');
			while ( ($l = fgets($f)) ) {
				list($user, $pass) = preg_split('/:/', $l);
				if ( $user == $_SERVER['PHP_AUTH_USER'] ) {
					/* BUG: Should at least use an encrypted method (digest ?) ... */
					if ( trim($pass) == $_SERVER['PHP_AUTH_PW'] ) {
						$this->authenticated = true;
						$this->login = $user;
					}
					break;
				}
			}
			fclose($f);

			if ( ! $this->authenticated ) {
				$this->authenticate("Protected content");
				exit;
			}
		}
		/* If using apache auth config, consider the user is authenticated. */
		$this->authenticated = true;
		$f = fopen($this->authGroupFile, 'r');
		while ( ($l = fgets($f)) )
			if ( $l[0] != '#' && strpos($l, $user) )
				$this->groups[] = substr($l, 0, strpos($l, ":"));
		fclose($f);
	}

	function authenticate($text="") {
		header('WWW-Authenticate: Basic realm="'.utf8_decode($text).'"');
		header('HTTP/1.0 401 Unauthorized');
		echo 'Access denied';
		exit;
	}

	function isMember($group) {
		if ( ! $this->authenticated ) return false;
		return in_array($group, $this->groups);
	}
}
?>
