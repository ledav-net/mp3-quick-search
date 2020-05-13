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
define('C_DIR_TRASH', '.Trash');	/* Trash directory. Where the 'deleted' files are moved. */

define('C_LOG_FILE',   '../logs/search.log');	/* Log file path/name */
define('C_TXT_GENRES', 'search-genres.txt');	/* Text file with id|text for the category list */

define('C_BIN_MP3INFO', '/usr/bin/mp3info');			/* mp3info tool path/name (MP3Info >= 0.8.5a) */
define('C_BIN_CUTMP3',  '/usr/bin/cutmp3');			/* cutmp3 tool path/name (cutmp3 >= 3.0.1) */
define('C_BIN_MP3GAIN', '/usr/bin/mp3gain');			/* mp3gain tool path/name (mp3gain >= 1.6.2) */
define('C_BIN_MP3STRIP','/usr/local/bin/mp3stripv2tag');	/* mp3stripv2tag tool path/name */
define('C_BIN_FIND',	'/usr/bin/find');			/* find tool path/name */

define('C_PUBLIC_URL',      'http://www.example.com/mp3');	/* Public url to access the mp3 (for winamp playlist) */
define('C_DIR_AUDIOPLAYER', '../tools/audio-player');		/* Where the audio-player is located */
define('C_MAX_ROWS',        50);				/* Default number of rows to show per page */

$selfScript = $_SERVER['PHP_SELF'];

/* Fix UTF-8 filename problems with system() & escapeshellarg() funcs... */
setlocale(LC_CTYPE, "en_US.UTF-8");

/*  User access checks
 */
require_once('auth.class.php');
$user = new Auth();

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

	/* Javascript & characters problem in filenames, workaround: */
	$in = array("%",    "#",    ",",    "&",  "+",  "\$", "\\");
	$ou = array("%2525","%2523","%252C","%26","%2B","%24","%5C");
	$file = str_replace($in, $ou, $_GET['play']);

	?><html>
	<head>
	<link type="text/css" rel="stylesheet" href="search.css">
	<script type="text/javascript" src="<?=C_DIR_AUDIOPLAYER?>/audio-player.js"></script>
	<script type="text/javascript">
		AudioPlayer.setup("<?=C_DIR_AUDIOPLAYER?>/player.swf", {
			width: 480,
			autostart: "yes",
			animation: "no",
			remaining: "no",
			buffer: 10,
			transparentpagebg: "yes",
			lefticon:     "0xffffff",
			righticon:    "0xffffff",
			tracker:      "0x8599e6",
			track:        "0xcad0db",
			loader:       "0x383636",
			border:       "0x383636",
			rightbg:      "0x0d1d94",
			rightbghover: "0x2d3db4",
			leftbg:       "0x0d1d94",
			bg:           "0x345aba",
			initialvolume: 80 });
	</script>
	</head>
	<body>
	<center><table border=0 align=center><tr><td><p id="audioplayer_1">Loading player ...</p></td></tr></table></center>
	<script type="text/javascript">
		AudioPlayer.embed("audioplayer_1", {
			soundFile: "<?=$file?>"});
	</script>
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
	<frameset rows="33,*" border=1>
	  <frame name=play   marginwidth=0 marginheight=0 scrolling=auto src="?fs=1&amp;play=<?=urlencode($_GET['play']);?>">
	  <frame name=search marginwidth=0 marginheight=0 scrolling=auto src="?fs=2&amp;<?=$queryUrl.$rsb->getUrlParams('&amp;')?>#POS<?=$_GET['pos']?>">
	</frameset>
	</html>
	<?
	exit;
}

header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: no-cache');

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
		for ( $i=0 ; isset($_FILES['upload']['name'][$i]) ; $i++ ) {
			$uplFileType     = $_FILES['upload']['type'][$i];
			$uplFileName     = preg_replace('/(\.mp3)+$/i','',ltrim($_FILES['upload']['name'][$i],'.')).".mp3";
			$uplFileNameDest = C_DIR_NEW."/".$uplFileName;
			if ( file_exists($uplFileNameDest) ) {
				$showMsg[] = array("msg_error", "<i>$uplFileName</i> was already uploaded ...");
				$log->add("*WARNING* file '$uplFileName' already exist !");
			}else
			if ( @move_uploaded_file($_FILES['upload']['tmp_name'][$i], $uplFileNameDest) ) {
				@chmod($uplFileNameDest, 0666);
				@system(C_BIN_MP3GAIN.' -q -c -r -p '.escapeshellarg($uplFileNameDest).' >> '.$log->logFile.' 2>&1');
				$showMsg[] = array("msg_success", "<i>$uplFileName</i> well received. Thanks !");
				$log->add("Uploaded '$uplFileName'");
				$rsb->dropResult();
			}else{
				$showMsg[] = array("msg_error", "<i>$uplFileName</i> internal error ...");
				$log->add("*ERROR* trying to move uploaded file '$uplFileName'");
			}
		}
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
		} 
	}else
	if ( isset($_POST['btntrash']) ) {
		if ( fmove($filePath, $fileName, C_DIR_TRASH, "TRASH") ) {
			$log->add("Setting TRASH .... '$fileName' ($filePath)");
			$showMsg[] = array("msg_success", "<i>$fileName</i> is trashed !");
		}
	}else
	if ( isset($_POST['btnrename']) ) {
		$fileNameTo=trim($_POST['fileto']).".mp3";
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
				$fileName = $fileNameTo;
				$reloadParent = true;
				$rsb->dropResult();
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
			" -O ".escapeshellarg("$filePath/$fileName")."-cropped.mp3".
			" > /dev/null 2>> ".$log->logFile);
		$showMsg[] = array("msg_success", "<i>$fileName-cropped.mp3</i> created !");
		$reloadParent = true;
		$rsb->dropResult();
	}else
	if ( isset($_POST['btnupdatetag']) ) {
		$log->add("Updating tag v1 of '$fileName' ($filePath) ...");
		system(C_BIN_MP3STRIP.' '.escapeshellarg("$filePath/$fileName").' &> /dev/null');
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
		system(C_BIN_MP3INFO.' -d -- '.escapeshellarg("$filePath/$fileName").' >> '.$log->logFile.' 2>&1');
		$showMsg[] = array("msg_success", "Tag v1 stripped !");
	}else
	if ( isset($_POST['btnstripv2tag']) ) {
		$log->add("Stripping v2 tag from '$fileName' ($filePath)");
		system(C_BIN_MP3STRIP.' '.escapeshellarg("$filePath/$fileName").' &> /dev/null');
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

$searchCmd=C_BIN_FIND." $searchDir \\( -wholename './.*' -prune \\) -o \\( $searchStr \\) -iname '*.mp3' -type f -printf '%f|%k|%h\\n' 2>/dev/null | sort -f";

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
	$PL['playlist']->cleanup(); // Remove not found mp3 (file moved/renamed)
	$PL['playlist']->update();  // Update the playlist if needed
}

/***************************************************** GET INFOS WINDOW *********************************************************/

if ( isset($_GET['getinfos']) ) {?>
<html>
<head>
<title>MP3 Info</title>
<link type="text/css" rel="stylesheet" href="/mystyles/ledav.net-public.css">
<script type="text/javascript">  
function keyPressed(e) {
	switch ( e.keyCode ) {
		case 27: self.close();
	}
	return true;
}
function resizeMe() {
//	tab = document.getElementById("mainTable");
	//window.resizeTo(tab.width, tab.height);
//	alert("w="+tab.width+" h="+tab.height);
}
</script>
</head>
<body onLoad="resizeMe();" width="100%" height="100%" onKeyPress="return keyPressed(event)"<? if ( $reloadParent ) echo ' onBeforeUnload="self.opener.location.replace(self.opener.location.search)"';?>>
	<table id="mainTable" width="100%" align=left style="font-family:monospace;font-size:13px;float:none" cellspacing=0>
	<tr><td rowspan=2><a href=""><img src="/myicons/info-icon-4-64x64.png" alt="refresh" title="refresh"/></a></td><?
	$rp=rawurlencode($filePath);
        $rf=rawurlencode($fileName);?>
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
	<tr><td valign=bottom colspan=3><a title="permalink" href="/public/mp3/<?=$rp?>/<?=$rf?>"><?=$fileName?></a></td></tr><?
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

	$mp3info =
		explode("\t",
		exec(C_BIN_MP3INFO." -F -r m -x -p ".
		"'%F\\t%t\\t%n\\t%a\\t%l\\t%y\\t%c\\t%G\\t%g\\t%v\\t%L\\t%r\\t%Q\\t%o\\t%e\\t%E\\t%C\\t%O\\t%p\\t%m\\t%02s\\t%k\\t%u\\t%b' ".
		"-- $filePathEscaped/$fileNameEscaped 2> /dev/null"));

	if ( $priviledgedUser ) {?>
	<form method=post>
	<input type=hidden name=path value="<?=$filePath?>">
	<input type=hidden name=file value="<?=$fileName?>"><?
		$mp3infohtml['title']      = '<input type=text name=title      value="'.$mp3info[1].'" size=40 maxlength=30>';
		$mp3infohtml['track']      = '<input type=text name=track      value="'.$mp3info[2].'" size=2  maxlength=2>';
		$mp3infohtml['artist']     = '<input type=text name=artist     value="'.$mp3info[3].'" size=40 maxlength=30>';
		$mp3infohtml['album']      = '<input type=text name=album      value="'.$mp3info[4].'" size=40 maxlength=30>';
		$mp3infohtml['year']       = '<input type=text name=year       value="'.$mp3info[5].'" size=4  maxlength=4>';
		$mp3infohtml['comment']    = '<input type=text name=comment    value="'.$mp3info[6].'" size=40 maxlength=30>';
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
	}?>
	<tr><td width="18%" valign=top>File:</td><td colspan=3><?=$mp3info[0]?></td></tr>
	<tr><td>Title:</td><td width="45%"><?=$mp3infohtml['title']?></td><td width="10%">Track:</td><td><?=$mp3infohtml['track']?></td></tr>
	<tr><td>Artist:</td><td colspan=3><?=$mp3infohtml['artist']?></td></tr>
	<tr><td>Album:</td><td><?=$mp3infohtml['album']?></td><td>Year:</td><td><?=$mp3infohtml['year']?></td></tr>
	<tr><td>Comment:</td><td><?=$mp3infohtml['comment']?></td><td>Genre:</td><td NOWRAP><?=$mp3infohtml['genrevalue']?></td></tr>
	<tr><td colspan=4 align=center><?=$mp3info[7] == '' ? '<i style="color:grey">no tag v1 is set</i>' : '&nbsp;'?></td></tr><?
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
	<tr><td>Size</td><td colspan=3><?=$mp3info[21]?> Kb</td></tr><?
	if ( $priviledgedUser ) {?>
	<form method=post>
	<input type=hidden name=path value="<?=$filePath?>">
	<input type=hidden name=file value="<?=$fileName?>">
	<tr><td colspan=4>&nbsp;</td></tr>
	<tr><td colspan=4>
		<input type=submit name=btncrop value="Create"> a new mp3 cropped from
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
<link type="text/css" rel="stylesheet" href="/mystyles/ledav.net-public.css">
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
	height="<?= $priviledgedUser ? 705 : 495 ?>";
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
<a href="/public" target="_top"   title="Back to public">[back]</a>
<a href="."       target="_blank" title="Open a new quick search page">[*]</a>
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
 $dirs=popen(C_BIN_FIND.' -wholename "./.*" -prune -or -maxdepth 1 -type d -printf "%P\n" | sort','r');
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

		$l = substr($l, 2, strlen($l) - 3);
		$P = rawurlencode($l).'/'.rawurlencode($f);

		switch ( $l ) {
			case C_DIR_BAD: $className=($cfiles % 2) ? 'rowdarkorange':'rowlightorange'; break;
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
		}?>&nbsp;&nbsp;<a href="" onClick="return getInfos('<?=rawurlencode($l)?>','<?=rawurlencode($f)?>');" title="Show technical infos"><img style="vertical-align:-2px" src="/myicons/info-icon-2-13x13.png"></a></td>
		<td align=right><div class="<?=($s>10000) ? 'normalBigBold' : ''?>"><?=number_format($s,0,'.',' ')?> Kb</div></td>
		<td align=center><a class="<?=($l==C_DIR_NEW) ? 'surlined' : ''?>" href="<?=$l?>" title="Go to this directory" target=_main><?=$l?></a></td>
		<td nowrap><?
		?><input type=submit name=btndown    value=Down    title="Download this file"><?
		?><input type=submit name=btncorrupt value=Corrupt title="Set this file as corruped (move it to <?=C_DIR_BAD?>)" onClick="return Sure('CORRUPT',file.value);"<? if ($l == C_DIR_BAD) echo ' DISABLED'; ?>><?
		if ( $priviledgedUser ) {
			?><input type=submit name=btnok    value=OK    title="Set this file as OK (move it to <?=C_DIR_OK?>)" onClick="return Sure('OK',file.value);"<? if ($l == C_DIR_OK) echo ' DISABLED'; ?>><?
			?><input type=submit name=btntrash value=Trash title="Trash this file" onClick="return Sure('Trash',file.value);"><?
			foreach ( $PLAYLISTS as $PL ) {
				?><input type=checkbox name="<?=$PL['eventname']?>" title="<?=$PL['title']?>" onChange="return SubmitCheckBox(this);"<?
				if ( $PL['playlist']->exist($f, $l) ) echo ' checked'; ?>><?
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
