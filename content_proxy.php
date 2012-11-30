<?php
// $Id: content_proxy.php,v 1.0 2011/10/28 16:42:55 ike Exp $

/**
 * @file
 * Simply proxy a resource (image/doc...) request if the user is logged in. That's it.
 */


// Bootstrap ONLY to the SESSION layer (need to go here to support session in memcache)
// HA memcache config requires bootstrap one layer higher so we can use sess_user_load()
include_once './includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_LATE_PAGE_CACHE);
global $user;
if (!$user || $user->uid < 1){
	//header('Location: /');
	header("Status: 403 Access Denied");
	exit(0);
}

// Good to go with the session
$this_file = isset($_GET['cp']) ? $_GET['cp'] : FALSE;

if(!$this_file || !is_readable($this_file)){
	header("Status: 404 Not Found");
	exit(0);
} else {
	$finfo = finfo_open(FILEINFO_MIME, '/usr/share/file/magic');
	header('X-Accel-Redirect: /protexted/' . $this_file);
	header('Content-Type: ' . finfo_file($finfo, $this_file));
	//header('Content-Length: ' . filesize($this_file));
	//echo file_get_contents($this_file);
	//fbug($this_file);
}

function fbug($var){
	$filename = '/tmp/fbug';
	
	if (!$fh = fopen($filename, 'a')){
		return;
	}
	ubug($var, FALSE, $fh);
}

function ubug($var, $die = FALSE, $handle=FALSE){
	
	$return = $handle ? sprintf(date('r')."\n%s\n", print_r($var, TRUE)) : sprintf('<div><pre style="background-color: #fff;">%s</pre></div>', htmlentities(print_r($var, TRUE)));
	
	if($handle){
		fwrite($handle, $return);
		return;
	}
	$x = $die ? die($return) : print($return);
}
