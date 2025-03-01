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

ini_set("include_path","../includes" );

define('C_DIR_BAD',   'corrupt');	/* Corrupted directory. Where the mp3 are moved when set corrupted. */
define('C_DIR_NEW',   'new');		/* New directory. Where the files are uploaded. */
define('C_DIR_OK',    'new.ok');	/* OK directory. Where the accepted files are moved. */
define('C_DIR_NOK',   'new.nok');	/* NoK directory. Where the not accepted files are moved. */
define('C_DIR_TRASH', 'trashed');	/* Trash directory. Where the 'deleted' files are moved. */

define('C_LOG_FILE',   '../logs/search.log');	/* Log file path/name */
define('C_TXT_GENRES', 'search-genres.txt');	/* Text file with id|text for the category list */

define('C_BIN_MP3INFO',    '/usr/local/bin/mp3info');	/* mp3info tool path/name (MP3Info >= 0.8.5a) */
define('C_BIN_CUTMP3',     '/usr/local/bin/cutmp3');	/* cutmp3 tool path/name (cutmp3 >= 3.0.1) */
define('C_BIN_MP3GAIN',    '/usr/local/bin/mp3gain');	/* mp3gain tool path/name (mp3gain >= 1.6.2) */
define('C_BIN_ID3CONVERT', '/usr/bin/id3convert');	/* id3convert tool path/name (id3lib >= 3.8.3) */
define('C_BIN_FIND',       '/usr/bin/find');		/* find tool path/name */

define('C_PUBLIC_URL',	'https://mp3.example.com');	/* Public url to access the mp3 (for winamp playlist) */
define('C_MAX_ROWS',	50);				/* Default number of rows to show per page */

$selfScript = $_SERVER['PHP_SELF'];

/* Fix UTF-8 filename problems with system() & escapeshellarg() funcs... */
setlocale(LC_CTYPE, "en_US.UTF-8");

/* Disable the browser caching */
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: no-cache');

/*  User access checks
 */
require_once('auth.class.php');
$user = new Auth();
//$user = new Auth($useApacheAuth=true); // Use this if you are using apache authentication with htpasswd etc...

if ( ! $user->isMember('authenticated') ) {
	echo "Sorry, you are not authorized to use this tool !";
	exit;
}
$priviledgedUser = $user->isMember('mp3admin');

/*  Playlists handling
 */
if ( $priviledgedUser ) {
	require_once('playlist.class.php');
	$PLAYLISTS = array(
		array('eventname' => 'pldefault', 'title' => 'Default', 'playlist' => new PlayList('../playlists/playlist.lst')),
	);
}

/* Frameset handling
 */
$frameUrl = "";
if ( isset($_GET['fs']) ) {
	$frameUrl    = "fs=".$_GET['fs'];
	$frameMain   = ($_GET['fs'] == "0");
	$framePlay   = ($_GET['fs'] == "1");
	$frameSearch = ($_GET['fs'] == "2");
}

if ( $framePlay ) {
	/****************/
	/* PLAYER FRAME */
	/****************/
	?><html>
	<head>
	<link type="text/css" rel="stylesheet" href="search.css">
	</head>
	<body>
	<center>
	<table width="90%" height="90%" border=0 cellspacing=0>
		<tr><td align=center valign=center>
			<b><?
			$t = preg_split('(/| +- +)', $_GET['play']);
			echo "<a href='?fs=2&word=&dir=".urlencode($t[0])."' target=search>".$t[0]."</a>/";
			echo "<a href='?fs=2&word=".urlencode($t[1])."' target=search>".$t[1]."</a>";
			for ( $i=2 ; $i < count($t) ; $i++ ) echo ' - '.$t[$i];
			?></b>
		</td></tr>
		<tr><td align=center valign=center>
			<audio style="width: 50%" controls preload=none autoplay>
				<source src="<?=$_GET['play'].($priviledgedUser ? '?'.$_SERVER['REQUEST_TIME'] : '');/* Avoid caching for the admin */?>" type="audio/mpeg">
			</audio>
		</td></tr>
	</table>
	</center>
	</body>
	</html><?
	exit;
}

/* Query handling
 */
$queryUrl = "";
if ( isset($_GET['dir']) )  $queryUrl.="&dir=".  urlencode($_GET['dir']);
if ( isset($_GET['word']) ) $queryUrl.="&word=". urlencode($_GET['word']);
if ( isset($_GET['last']) ) $queryUrl.="&last=". urlencode($_GET['last']);
$queryUrl = substr($queryUrl, 1); /* drop the first & */

require_once('resultsetbrowser.class.php'); 
$rsb = new ResultSetBrowser(C_MAX_ROWS);

if ( $frameMain ) {
	/************************/
	/* FRAMESET MAIN WINDOW */
	/************************/
	?>
	<html>
	<title>MP3 Quick Search</title>
	<frameset rows="70,*" border=1>
	  <frame name=play   marginwidth=0 marginheight=0 scrolling=auto src="?fs=1&amp;play=<?=urlencode($_GET['play']);?>">
	  <frame name=search marginwidth=0 marginheight=0 scrolling=auto src="?fs=2&amp;<?=$queryUrl.$rsb->getUrlParams('&amp;')?>#POS<?=$_GET['pos']?>">
	</frameset>
	</html>
	<?
	exit;
}
require_once('logmgr.class.php');
$log = new LogMgr(C_LOG_FILE, 'MP3 Quick Search', LOG_OSYNC, $user->login);

$fileName = isset($_REQUEST['file']) ? $_REQUEST['file'] : "";
$filePath = isset($_REQUEST['path']) ? $_REQUEST['path'] : "";

$reloadParent = false; /* True if the parent page need to be reloaded (info window) */

/**
 *  Handling of button pressed ...
 */

function fmove($fp, $fn, $dir, $action) {
	global $rsb, $log, $showMsg;
	$dfn = $fn;
	$i   = 1;
	while ( file_exists("$dir/$dfn") )
		$dfn = substr($fn,0,-4). '-'. $i++. '.mp3'; // Try to make the name unique
	if ( ! @rename("$fp/$fn", "$dir/$dfn") ) {
		$log->add("Error while setting '$fn' to $action !");
		$showMsg[] = array("msg_error", "Error while moving file <i>$fn</i> to <b>$dir</b> !");
		return FALSE;
	}
	$rsb->dropResult();
	return TRUE;
}

if ( isset($_POST['btncorrupt']) ) {
	if ( fmove($filePath, $fileName, C_DIR_BAD, "CORRUPT") ) {
		$log->add("Setting CORRUPTED '$fileName' ($filePath)");
		$showMsg[] = array("msg_success", "<i>$fileName</i> is marked as corrupted. Thank you for your help !");
	}
}else
if ( isset($_POST['btnupload']) ) {
	if ( $_FILES['upload']['error'][0] ) {
		$showMsg[] = array("msg_error", "No file received... Please try again...");
		$log->add('Tried to upload something ...');
	}else{
		$_ok=0;
		$_err=0;
		$_dup=0;
		for ( $i=0 ; isset($_FILES['upload']['name'][$i]) ; $i++ ) {
			$uplFileType     = $_FILES['upload']['type'][$i];
			$uplFileName     = preg_replace('/(\.mp3)+$/i','',ltrim($_FILES['upload']['name'][$i],'.')).".mp3";
			$uplFileName     = mb_convert_encoding($uplFileName, "UTF-8");
			$uplFileNameDest = C_DIR_NEW."/".$uplFileName;
			if ( file_exists($uplFileNameDest) ) {
				$showMsg[] = array("msg_error", "<i>$uplFileName</i> was already uploaded ...");
				$log->add("*WARNING* file '$uplFileName' already exist !");
				$_dup++;
			}else
			if ( @move_uploaded_file($_FILES['upload']['tmp_name'][$i], $uplFileNameDest) ) {
				@chmod($uplFileNameDest, 0666);
				@system(C_BIN_MP3GAIN.' -q -c -r -p '.escapeshellarg($uplFileNameDest).' >> '.$log->logFile.' 2>&1');
				$showMsg[] = array("msg_success", "<i>$uplFileName</i> ...");
				$log->add("Uploaded '$uplFileName'");
				$rsb->dropResult();
				$_ok++;
			}else{
				$showMsg[] = array("msg_error", "<i>$uplFileName</i> internal error ...");
				$log->add("*ERROR* trying to move uploaded file '$uplFileName'");
				$_err++;
			}
		}
		if ( $_ok )
			$showMsg[] = array("msg_info", "<b>$_ok</b> file(s) well received. Thanks !");
		if ( $_dup )
			$showMsg[] = array("msg_notice", "<b>$_dup</b> where already sent ..");
		if ( $_err )
			$showMsg[] = array("msg_error", "<b>$_err</b> errors encountered... Please double check !");
	}
}else
if ( isset($_POST['btndown']) ) {
	$fileSize=filesize($filePath."/".$fileName);
	header('Content-Type: application/octet-stream');
	header('Content-Description: File Transfer');
	header('Content-Transfer-Encoding: binary');
	header('Content-Disposition: attachment; filename="'.$fileName.'"; size='.$fileSize);
	header('Content-Length: '. $fileSize);
	readfile($filePath."/".$fileName);
	$log->add("Downloaded $fileName");
	exit;
}else
if ( $priviledgedUser ) {
 	if ( isset($_GET['showlog']) ) {
		header('Content-type: text/plain');
		readfile($log->logFile);
		exit;
	}

	if ( isset($_POST['btnok']) ) {
		if ( fmove($filePath, $fileName, C_DIR_OK, "OK") ) {
			$log->add("Setting OK! ..... '$fileName' ($filePath)");
			$showMsg[] = array("msg_success", "<i>$fileName</i> is marked as good [<b style=\"color:green;\">OK</b>] !");
			foreach ( $PLAYLISTS as $PL )
				$PL['playlist']->chg($fileName, C_DIR_OK);
		}
	}else
	if ( isset($_POST['btnnok']) ) {
		if ( fmove($filePath, $fileName, C_DIR_NOK, "NoK") ) {
			$log->add("Setting NoK! ..... '$fileName' ($filePath)");
			$showMsg[] = array("msg_success", "<i>$fileName</i> is marked as not good [<b style=\"color:red;\">NoK</b>] !");
			foreach ( $PLAYLISTS as $PL )
				$PL['playlist']->rem($fileName, $filePath);
		}
	}else
	if ( isset($_POST['btntrash']) ) {
		if ( fmove($filePath, $fileName, C_DIR_TRASH, "TRASH") ) {
			$log->add("Setting TRASH .... '$fileName' ($filePath)");
			$showMsg[] = array("msg_success", "<i>$fileName</i> is trashed !");
			foreach ( $PLAYLISTS as $PL )
				$PL['playlist']->rem($fileName, $filePath);
		}
	}else
	if ( isset($_POST['btnrename']) ) {
		$fileNameTo=trim($_POST['fileto']).".mp3";
		$fileNameTo=mb_convert_encoding($fileNameTo, "UTF-8");
		if ( $fileName == $fileNameTo ) {
			$showMsg[] = array("msg_error", "Same name ! So, nothing to do :-)");
		}else
		if ( file_exists("$filePath/$fileNameTo") ) {
			$showMsg[] = array("msg_error", "The destination file already exist !");
		}else{
			if ( ! @rename("$filePath/$fileName", "$filePath/$fileNameTo") ) {
				$log->add("Error while renaming '$fileName' to '$fileNameTo' ($filePath) !");
				$showMsg[] = array("msg_error", "Error while renaming !");
			}else{
				$log->add("Renaming '$fileName' to '$fileNameTo' ($filePath)");
				$showMsg[] = array("msg_success", "Mp3 renamed !");
				$reloadParent = true;
				$rsb->dropResult();
				foreach ( $PLAYLISTS as $PL )
					$PL['playlist']->chg($fileName, $filePath, $fileNameTo);
				$fileName = $fileNameTo;
			}
		}
	}else
	if ( isset($_POST['btncrop']) ) {
		$log->add("Cropping '$fileName' from ".$_POST['begin']." to ".$_POST['end']." ($filePath) ...");
		@unlink("$filePath/$fileName-cropped.mp3");
		system(C_BIN_CUTMP3." -c -q".
			" -a ".escapeshellarg($_POST['begin']).
			" -b ".escapeshellarg($_POST['end']).
			" -i ".escapeshellarg("$filePath/$fileName").
			" -O ".escapeshellarg("$filePath/$fileName-cropped.mp3").
			" > /dev/null 2>> ".$log->logFile, $rc);
		if ( $rc == 0 ) {
			if ( $_POST['btncrop'] == "Crop" ) {
				@unlink("$filePath/$fileName") &&
				@rename("$filePath/$fileName-cropped.mp3", "$filePath/$fileName");
				$showMsg[] = array("msg_success", "<i>$fileName</i> cropped !");
			}
			else	$showMsg[] = array("msg_success", "<i>$fileName-cropped.mp3</i> created !");
			$reloadParent = true;
			$rsb->dropResult();
		}
		else	$showMsg[] = array("msg_error", "<i>$fileName</i> cannot be cropped. Error $rc !");
	}else
	if ( isset($_POST['btnupdatetag']) ) {
		$log->add("Updating tag v1 of '$fileName' ($filePath) ...");
		@system(C_BIN_ID3CONVERT.' -s '.escapeshellarg("$filePath/$fileName").' &>/dev/null');
		if ( $_POST['genrevalue'] == '' || $_POST['genrevalue'] == '255' ) $_POST['genrevalue'] = '12' /* Other */;
		system(C_BIN_MP3INFO.
		        " -a ".escapeshellarg($_POST['artist']).
		        " -c ".escapeshellarg($_POST['comment']).
		        " -g ".escapeshellarg($_POST['genrevalue']).
		        " -l ".escapeshellarg($_POST['album']).
		        " -n ".escapeshellarg($_POST['track']).
		        " -t ".escapeshellarg($_POST['title']).
		        " -y ".escapeshellarg($_POST['year']).
		        " -- ".escapeshellarg("$filePath/$fileName").
		        " >> ".$log->logFile." 2>&1");
		$showMsg[] = array("msg_success", "Tag v1 updated (and v2 stripped) !");
	}else
	if ( isset($_POST['btnstripv1tag']) ) {
		$log->add("Stripping v1 tag from '$fileName' ($filePath)");
		@system(C_BIN_ID3CONVERT.' -1 -s '.escapeshellarg("$filePath/$fileName").' &>/dev/null');
		$showMsg[] = array("msg_success", "Tag v1 stripped !");
	}else
	if ( isset($_POST['btnstripv2tag']) ) {
		$log->add("Stripping v2 tag from '$fileName' ($filePath)");
		@system(C_BIN_ID3CONVERT.' -2 -s '.escapeshellarg("$filePath/$fileName").' &>/dev/null');
		$showMsg[] = array("msg_success", "Tag v2 stripped !");
	}
}

/**
 *  Building the find query in 'searchStr'
 */
$searchStr = "";
$searchDir = "";

if ( ! empty($_GET['dir']) ) {
	$searchDir = escapeshellarg("./".trim($_GET['dir']));
}

if ( isset($_GET['word']) ) {
	if ( $_GET['word'][0] == '/' ) { /* regex */
		$searchStr .= '-regextype posix-egrep ';
		if ( $_GET['word'][1] == '!' ) { /* not */
			$e = substr($_GET['word'], 2);
			$searchStr .= '-not ';
		}
		else	$e = substr($_GET['word'], 1);
		$searchStr .= '-regex '. escapeshellarg("\./+(.*)/+($e).*");
	}else{
		$masks = preg_split("/ +/", trim($_GET['word']));
		foreach ( $masks as $e ) $searchStr .= ' -iname '. escapeshellarg('*'.$e.'*');
	}
}

if ( ! empty($_GET['last']) ) {
	$searchStr .= ' -mtime '. escapeshellarg("-".$_GET['last']);
}

$searchCmd=C_BIN_FIND." $searchDir \\( -path './.*' -prune \\) -o \\( $searchStr \\) -iname '*.mp3' -type f -printf '%f|%s|%h\\n' 2>/dev/null | sort -f";

/**
 *  m3u playlist generation
 */
if ( isset($_GET['genwinamplist']) ) {
	header('Content-type: audio/x-mpegurl');
	header('Content-disposition: attachment; filename="playlist.m3u"');
	$search = popen($searchCmd, "r");
	while ( ($read = fgets($search, 1024)) ) {
		list($f, $s, $l) = explode('|', $read);
		$l = chop($l);
		echo C_PUBLIC_URL. "/$l/". str_replace('%2F', '/', rawurlencode($f)). "\n";
	}
	pclose($search);
	exit;
}

/**
 *  playlists management
 */
if ( $priviledgedUser ) foreach ( $PLAYLISTS as $PL ) {
	if ( isset($_POST[$PL['eventname']]) ) {
		if ( $_POST[$PL['eventname']] == 'on' )
			$PL['playlist']->add($_POST['file'], $_POST['path']);
		else	$PL['playlist']->rem($_POST['file'], $_POST['path']);
	}
	$PL['playlist']->fixlist(); // Fix mp3 refs that moved or was deleted / renamed from outside
}

/***************************************************** GET INFOS WINDOW *********************************************************/

if ( isset($_GET['getinfos']) ) {?>
<html>
<head>
<title>MP3 Info</title>
<link type="text/css" rel="stylesheet" href="search.css">
<script type="text/javascript">  
function keyPressed(e) {
	switch ( e.keyCode ) {
		case 27: self.close();
	}
	return true;
}
function resizeMe() {
	tab = document.getElementById("mainTable");
	window.resizeTo(tab.offsetWidth+20, tab.offsetHeight+80);
<?/*
	console.log("w="+tab.offsetWidth+" h="+tab.offsetHeight+" l="+tab.offsetLeft+" r="+tab.offsetRight);
*/?>
}
</script>
</head><?
$rp=rawurlencode($filePath);
$rf=rawurlencode($fileName);
?>
<body onLoad="resizeMe();<? if ( $reloadParent ) echo 'setTimeout(self.opener.location.replace(self.opener.location.search),200);';?>" width="100%" height="100%" onKeyPress="return keyPressed(event)">
	<table id="mainTable" align=center style="font-family:monospace;font-size:13px;float:none" cellspacing=0>
	<tr><td rowspan=2><a href='<?="?getinfos=1&path=$rp&file=$rf"?>'><img src="search-info-icon-64x64.png" alt="refresh" title="refresh"/></a></td>
	<td colspan=3><?
	if ( isset($showMsg) ) {?>
	<table class="msg_table" width="100%"><?
		foreach ( $showMsg as $msg ) {?>
		<tr align=center class="<?=$msg[0]?>"><td><?=$msg[1]?></td></tr><?
		}?>
	</table><?
	}else{?>
		&nbsp;<?
	}?>
	</td></tr>
	<tr><td valign=bottom colspan=3><a title="permalink" href="<?=C_PUBLIC_URL.'/'.$rp.'/'.$rf?>"><?=$fileName?></a></td></tr><?
	if ( $priviledgedUser ) {
		$fileNameNoExt = preg_replace('/(\.mp3)+$/i','',$fileName);?>
	<form method=post>
	<input type=hidden name=path   value="<?=$filePath?>">
	<input type=hidden name=file   value="<?=$fileName?>">
	<tr><td colspan=4>&nbsp;</td></tr>
	<tr><td colspan=4><input type=text   name=fileto value="<?=$fileNameNoExt?>" size=65><b class='input'>.mp3</b>&nbsp;<input type=submit name=btnrename value=Rename></td></tr>
	<tr><td colspan=4>&nbsp;</td></tr>
	</form><?
	}else{?>
	<tr><td colspan=4>&nbsp;</td></tr><?
	}
	$filePathEscaped = escapeshellarg($filePath);
	$fileNameEscaped = escapeshellarg($fileName);

	$mp3stat = stat($filePath.'/'.$fileName);

	$mp3info =
		explode("\t",
		exec(C_BIN_MP3INFO." -F -r m -x -p ".
		"'%F\\t%t\\t%n\\t%a\\t%l\\t%y\\t%c\\t%G\\t%g\\t%v\\t%L\\t%r\\t%Q\\t%o\\t%e\\t%E\\t%C\\t%O\\t%p\\t%m\\t%02s\\t%k\\t%u\\t%b' ".
		"-- $filePathEscaped/$fileNameEscaped 2> /dev/null"));

	$isMp3InfosPresent = empty($mp3info[7]);

	if ( $isMp3InfosPresent )
		$mp3info[7] = "12"; // Select 'Other' by default

	if ( $priviledgedUser ) {?>
	<form method=post>
	<input type=hidden name=path value="<?=$filePath?>">
	<input type=hidden name=file value="<?=$fileName?>"><?
		$mp3infohtml['title']      = '<input type=text name=title   value="'.$mp3info[1].'" size=40 maxlength=30>';
		$mp3infohtml['track']      = '<input type=text name=track   value="'.$mp3info[2].'" size=2  maxlength=2>';
		$mp3infohtml['artist']     = '<input type=text name=artist  value="'.$mp3info[3].'" size=40 maxlength=30>';
		$mp3infohtml['album']      = '<input type=text name=album   value="'.$mp3info[4].'" size=40 maxlength=30>';
		$mp3infohtml['year']       = '<input type=text name=year    value="'.$mp3info[5].'" size=4  maxlength=4>';
		$mp3infohtml['comment']    = '<input type=text name=comment value="'.$mp3info[6].'" size=40 maxlength=30>';
		$mp3infohtml['genrevalue'] = '<select name=genrevalue class=input value="'.$mp3info[7].'">';
		$f = fopen(C_TXT_GENRES, "r");
		while ( ($r = fgets($f, 64)) ) {
			if ( $r[0] == '#' ) continue;
			list($id, $name) = explode('|', chop($r));
			$mp3infohtml['genrevalue'] .= '<option value='.$id.(($mp3info[7] == $id) ? ' selected':'').">$name</option>";
		}
		fclose($f);
		$mp3infohtml['genrevalue'] .= '</select>';
	}else{
		$mp3infohtml['title']      = &$mp3info[1];
		$mp3infohtml['track']      = &$mp3info[2];
		$mp3infohtml['artist']     = &$mp3info[3];
		$mp3infohtml['album']      = &$mp3info[4];
		$mp3infohtml['year']       = &$mp3info[5];
		$mp3infohtml['comment']    = &$mp3info[6];
		$mp3infohtml['genrevalue'] = &$mp3info[7];
		$f = fopen(C_TXT_GENRES, "r");
		while ( ($r = fgets($f, 64)) ) {
			if ( $r[0] == '#' ) continue;
			list($id, $name) = explode('|', chop($r));
			if ( $mp3info[7] == $id ) {
				$mp3infohtml['genrevalue'] = $name;
				break;
			}
		}
		fclose($f);
	}?>
	<tr><td width="18%" valign=top>File:</td><td colspan=3><?=$mp3info[0]?></td></tr>
	<tr><td>Title:</td><td width="45%"><?=$mp3infohtml['title']?></td><td width="10%">Track:</td><td><?=$mp3infohtml['track']?></td></tr>
	<tr><td>Artist:</td><td colspan=3><?=$mp3infohtml['artist']?></td></tr>
	<tr><td>Album:</td><td><?=$mp3infohtml['album']?></td><td>Year:</td><td><?=$mp3infohtml['year']?></td></tr>
	<tr><td>Comment:</td><td><?=$mp3infohtml['comment']?></td><td>Genre:</td><td NOWRAP><?=$mp3infohtml['genrevalue']?></td></tr>
	<tr><td colspan=4 align=center><?=$isMp3InfosPresent ? '<i style="color:grey">no tag v1 is set</i>' : '&nbsp;'?></td></tr><?
	if ( $priviledgedUser ) {?>
	<tr><td colspan=4 align=center>
		<input type=submit name="btnupdatetag"  value="Update v1 tag">
		<input type=submit name="btnstripv1tag" value="Strip v1 tag">
		<input type=submit name="btnstripv2tag" value="Strip v2 tag"></td></tr>
	</form><?
	}?>
	<tr><td colspan=4>&nbsp;</td></tr>
	<tr><td NOWRAP>Media Type:</td><td colspan=3>MPEG <?=substr($mp3info[9],0,3)?> Layer <?=$mp3info[10]?></td></tr>
	<tr><td>Audio:</td><td colspan=3><?=$mp3info[11]?> KB/s, <?=$mp3info[12]?> Hz, <?=$mp3info[13]?></td></tr>
	<tr><td>Frames:</td><td colspan=3><?=$mp3info[22]?><? if ( $mp3info[23] > 0 ) echo ' good, <b style="color:red">'.$mp3info[23].' bad</b>'; ?></td></tr>
	<tr><td>Emphasis:</td><td colspan=3><?=$mp3info[14]?></td></tr>
	<tr><td>CRC:</td><td colspan=3><?=$mp3info[15]?></td></tr>
	<tr><td>Copyright:</td><td colspan=3><?=$mp3info[16]?></td></tr>
	<tr><td>Original:</td><td colspan=3><?=$mp3info[17]?></td></tr>
	<tr><td>Padding:</td><td colspan=3><?=$mp3info[18]?></td></tr>
	<tr><td>Length:</td><td colspan=3><?=$mp3info[19]?>:<?=$mp3info[20]?></td></tr>
	<tr><td>Access</td><td colspan=3><?=date('d M Y H:i:s',$mp3stat['atime'])?></td></tr>
	<tr><td>Modified</td><td colspan=3><?=date('d M Y H:i:s',$mp3stat['mtime'])?></td></tr>
	<tr><td>Changed</td><td colspan=3><?=date('d M Y H:i:s',$mp3stat['ctime'])?></td></tr>
	<tr><td>Size</td><td colspan=3><?=number_format($mp3info[21],0,'.','.')?> Kb (<?=number_format($mp3stat['size'],0,'.','.')?> bytes)</td></tr><?
	if ( $priviledgedUser ) {?>
	<form method=post>
	<input type=hidden name=path value="<?=$filePath?>">
	<input type=hidden name=file value="<?=$fileName?>">
	<tr><td colspan=4>&nbsp;</td></tr>
	<tr><td colspan=4 nowrap>
		<input type=submit name=btncrop value="Crop"> (or
		<input type=submit name=btncrop value="Create"> a new) mp3 from
		<input type=text   name=begin   value="0:00.00" size=7 maxlength=8> to
		<input type=text   name=end     value="<?=$mp3info[19]?>:<?=$mp3info[20]?>.00" size=7 maxlength=8>
		(mm:ss.cc)</td></tr>
	</form><?
	}?>
	<tr><td colspan=4>&nbsp;</td></tr>
	<tr><td colspan=4 align=center><input type="button" value="     close     " onClick="self.close();"></td></tr>
	</table>
</body>
</html><?
	exit;
}
/*************************************************** SEARCH WINDOW/FRAME ********************************************************/
?>
<html>
<head>
<title>MP3 Quick Search</title>
<link type="text/css" rel="stylesheet" href="search.css">
<script type="text/javascript">
function loadit() {
	imgbox=document.getElementById("loadingimg");
	imgbox.style.display="block";
	imgbox.style.visibility="visible";
	return true;
}
function Sure(action, text) {
	return confirm("Are you sure to set the following file to " + action + " ?\n\n" + text);
}
function getInfos(path, file) {
	width=800;
	height=<?= $priviledgedUser ? 705 : 495 ?>;
	x=(screen.width - width) / 2;
	y=(screen.height - height) / 4;
	p1="?getinfos=1&path="+path+"&file="+file;
	p2="";
	p3="screenX="+x+",left="+x+",screenY="+y+",top="+y+",location=no,menubar=no,toolbar=no,status=no,scrollbars=no,resizable=yes,width="+width+",height="+height;
	window.open(p1,p2,p3,false);
	return false;
}
function SubmitCheckBox(obj) {
	obj.value   = obj.checked ? "on" : "off";
	obj.checked = true;
	r = obj.form.submit();
	obj.disabled = true;
	return r;
}
</script>
</head>
<body>
<a href="/" target="_top"   title="Back">[back]</a>
<a href="." target="_blank" title="Open a new quick search page">[*]</a>
<a href="?genwinamplist=1&amp;<?=$queryUrl?>" title="Generate a playlist with this result set (m3u)">[playlist]</a>
<? if ( $priviledgedUser ) { ?>
<a href="?showlog=1"    target="_blank">[view search log]</a>
<? } ?>
<? if ( $frameSearch ) { ?>
<a href="?<?=$queryUrl.$rsb->getUrlParams('&amp;')?>" target="_top"><b style="color:black;">[close top frame]</b></a>
<? } ?>
<h1><? if ( $priviledgedUser ) { ?><i style="color:#cc0000;">Advanced</i> <? } ?>MP3 quick search</h1>
<table border=0>
<tr>
<form method=get name=searchform action="<?=$selfScript?>">
 <? if ( $frameSearch ) { ?><input type=hidden name=fs value=2><? } ?>
 <td align=right nowrap><?
 ?><input type=text name=word value="<?=isset($_GET['word']) ? $_GET['word'] : ''?>" size=50 maxlength=128 onFocus="select();" title="Type here some words to search for. Start the input by a / to use 'Regular Expressions' (/! for non-matching)"><?
 ?><select name="dir" title="Limit the search to the specified folder"><?
 $dirs=popen(C_BIN_FIND.' -path "./.*" -prune -or -maxdepth 1 -type d -printf "%P\n" | sort','r');
 $dirSelected = isset($_GET['dir']) ? $_GET['dir'] : "";
 while ( $d = fgets($dirs) ) {
 	$d=trim($d);
 	?><option value="<?=$d?>"<? if ($dirSelected == $d) echo ' selected'; ?>><?=$d?></option><?
 }
 pclose($dirs);
 unset($dirs, $d);
 ?></select><?
 ?><input type=text name=last value="<?=isset($_GET['last']) ? $_GET['last'] : '' ?>" size=3 maxlength=3 title="Limit the search to the specified days back (modified or added songs)"></td>
 <td><input type=submit value=Search target="_top" title="start searching the specified word(s) in the specified folder(s)"></td>
</form>
<form method=get action="<?=$selfScript?>">
<td valign=middle>
 <? if ( $frameSearch ) { ?><input type=hidden name=fs value=2><? } ?>
 <input type=submit value="Reset" title="reset the search criterias">
</td>
</form>
<form method=get action="<?=$selfScript?>">
<td>
 <? if ( $frameSearch ) { ?><input type=hidden name=fs value=2><? } ?>
 <input type=hidden name=dir  value="<?=C_DIR_NEW?>">
 <input type=hidden name=word value="">
 <input type=hidden name=last value="">
 <input type=submit value="Show New" title="show the mp3 waiting in the '<?=C_DIR_NEW?>' folder to be classified">
</td>
</form>
<form method=get action="<?=$selfScript?>">
<td>
 <? if ( $frameSearch ) { ?><input type=hidden name=fs value=2><? } ?>
 <input type=hidden name=dir  value="">
 <input type=hidden name=word value="">
 <input type=hidden name=last value="1">
 <input type=submit value="Show Today" title="show the last 24h mp3">
</td>
</form>
</tr>
<tr valign=middle>
<form enctype="multipart/form-data" method=post action="#">
 <? if ( $frameSearch ) { ?><input type=hidden name=fs value=2><? } ?>
 <td align=right><input type=file name="upload[]" size=40 multiple></td>
 <td colspan=3><input type=submit name=btnupload value="Upload" onclick="return loadit();"> (max 15 Mb/mp3)</td>
 <td valign=middle><img style="display: none; visibility: hidden;" id=loadingimg src="/myicons/loading06.gif" width="100%" height=10></td>
</form>
</tr>
</table>
<br/>
<?
/*
 *  Show messages handling ...
 */
if ( isset($showMsg) ) {
	?><table class="msg_table"><?
	foreach ( $showMsg as $msg ) { ?><tr class="<?=$msg[0]?>"><td><?=$msg[1]?></td></tr><? }
	?></table><br><?
}
/* 
 * Searching ...
 */
if ( ! empty($searchStr) ) {

  if ( $rsb->isEmpty() ) {
  	$search = popen($searchCmd, "r");
  	$rsb->openResult();
	while ( ($read = fgets($search)) ) $rsb->add($read);
	pclose($search);
	$rsb->closeResult();
  }

  if ( ($read = $rsb->get()) !== FALSE ) {
  	?>
	<table border=0 cellspacing=0 cellpadding=0>
	<tr><td colspan=4 align=center><?=$rsb->getNav(array(
						"add_url"   => ($frameUrl ? $frameUrl.'&' : '').$queryUrl,
						"prev_text" => "<<<<<    ",
						"next_text" => "    >>>>>"));?></td></tr>
	<tr><td colspan=4 align=left><b><?=number_format($rsb->count,0,'.','.')?> mp3 found</b></td></tr>
	<tr><th align=left><a name='POS0'></a>File</th><th align=right  width=90 nowrap>Size</th><th align=center width=80 nowrap>Location</th><th align=center width=60 nowrap>Action</th></tr>
	<?
	$cfiles = 0;
	do {
		list($f, $s, $l) = explode('|', $read);

		$s = $s / 1024;
		$l = substr($l, 2, strlen($l) - 3); // Strip './' & '\n'
		$P = rawurlencode($l).'/'.rawurlencode($f);

		switch ( $l ) {
			case C_DIR_BAD: $className=($cfiles % 2) ? 'rowdarkorange':'rowlightorange'; break;
			case C_DIR_NOK: $className=($cfiles % 2) ? 'rowdarkred'   :'rowlightred';    break;
			case C_DIR_OK:  $className=($cfiles % 2) ? 'rowdarkgreen' :'rowlightgreen';  break;
			default:     	$className=($cfiles % 2) ? 'rowdark'      :'rowlight';
		}
		?>
		<tr valign=top class="<?=$className?>">
		<form method=post action="#POS<?=$cfiles?>">
		<input type=hidden name=path value="<?=$l?>">
		<input type=hidden name=file value="<?=$f?>">
		<td valign=middle><?
		if ( $frameSearch ) {
			?><a name="POS<?=$cfiles+1?>" href="?fs=1&amp;play=<?=$P?>" title="Play this file" target=play><?=$f?></a><?
		}else{
			?><a name="POS<?=$cfiles+1?>" href="?fs=0&amp;pos=<?=$cfiles+1?>&amp;play=<?=$P?>&amp;<?=$queryUrl.$rsb->getUrlParams('&amp;')?>" title="Play this file"><?=$f?></a><?
		}?>&nbsp;&nbsp;<a href="" onClick="return getInfos('<?=rawurlencode($l)?>','<?=rawurlencode($f)?>');" title="Show technical infos"><img style="vertical-align:-2px" src="search-info-icon-13x13.png"></a></td>
		<td align=right><div class="<?=($s>10000) ? 'normalBigBold' : ''?>"><?=number_format($s,0,'.',' ')?> Kb</div></td>
		<td align=center><a class="<?=($l==C_DIR_NEW) ? 'surlined' : ''?>" href="<?=$l?>" title="Go to this directory" target=_main><?=$l?></a></td>
		<td nowrap><?
		?><input type=submit name=btndown    value=Down    title="Download this file"><?
		?><input type=submit name=btncorrupt value=Corrupt title="Set this file as corruped (move it to <?=C_DIR_BAD?>)" onClick="return Sure('CORRUPT',file.value);"<? if ($l == C_DIR_BAD) echo ' DISABLED'; ?>><?
		if ( $priviledgedUser ) {
			?><input type=submit name=btnok    value=OK    title="Set this file as OK (move it to <?=C_DIR_OK?>)" onClick="return Sure('OK',file.value);"<? if ($l == C_DIR_OK) echo ' DISABLED'; ?>><?
			?><input type=submit name=btnnok   value=NoK   title="Set this file as NOT OK (move it to <?=C_DIR_NOK?>)" onClick="return Sure('NoK',file.value);"<? if ($l == C_DIR_NOK) echo ' DISABLED'; ?>><?
			?><input type=submit name=btntrash value=Trash title="Trash this file" onClick="return Sure('Trash',file.value);"><?
			foreach ( $PLAYLISTS as $PL ) {
				?><input type=checkbox name="<?=$PL['eventname']?>" title="<?=$PL['title']?>" onChange="return SubmitCheckBox(this);"<?
				if ( $PL['playlist']->exist($f, $l) !== FALSE ) echo ' checked'; ?>><?
			}
		}
		?></td>
		</form>
		</tr><?
		$cfiles++;
	}
	while ( ($read = $rsb->get()) !== FALSE );
	?>
	<tr><td colspan=4 align=center><?=$rsb->getNav();?></td></tr>
	</table><?
  }else{
	?><h3>No mp3 found</h3><?
  }
}
?>
<copyright>&copy; 2005 David De Grave (<a href="https://github.com/ledav-net/mp3-quick-search.git" target=_blank>source</a>)</copyright>
</body>
</html>
