<?php
// $Id: proxy.php,v 1.0 2010/08/28 16:42:55 ike Exp $

/**
 * @file
 * Simply proxy a SOLR select request if the user is logged in. That's it.
 */

/* OLD CODE NEED TO GO HIGHER NOW TO SUPPORT SESSIONS IN MEMCACHE. SEE LINE 40.
// Find the potential sid
$sids = array();
foreach(array_keys($_COOKIE) as $key){
	if (preg_match('/^SESS\w+$/', $key)){
		$sids[] = $_COOKIE[$key];
	}
}
if(empty($sids)){
	print("Can not proxy: NO SESSION");
	exit(0);
}

// Bootstrap ONLY to the Database
include_once './includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_DATABASE);

// Validate sid
$addr[] = $_SERVER['REMOTE_ADDR'];
$qarray = array_merge($addr, $sids); 
$sql  = "SELECT uid from {sessions} where uid != 0 AND hostname = '%s' AND sid IN (";
$sql .= implode(',', array_fill(0, count($sids), "'%s'"));
$sql .= ")";
$result = db_result(db_query($sql, $qarray));
if (!$result){
	print("Can not proxy: INVALID SESSION");
	exit(0);
}
END OLD CODE */

// Bootstrap ONLY to the SESSION layer (need to go here to support session in memcache)
// HA memcache config requires bootstrap one layer higher so we can use sess_user_load()
include_once './includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_LATE_PAGE_CACHE);
global $user;

if (!$user || $user->uid < 1){
	header('Location: /');
	exit(0);
}

// Good to go with the session
$pattern = '/^.*request=(.+)$/';
$full_uri = $_SERVER['REQUEST_URI'];
$proxy_url = array();
preg_match($pattern, $full_uri, $proxy_url);

// We can use the parse_url check to only proxy to specific sites
if(!empty($proxy_url)){
	$this_url = parse_url($proxy_url[1]);
} else {
	$this_url = FALSE;
}
$sql_host = "SELECT value FROM {variable} WHERE name = 'apachesolr_host'";
$sql_port = "SELECT value FROM {variable} WHERE name = 'apachesolr_port'";
$sql_path = "SELECT value FROM {variable} WHERE name = 'apachesolr_path'";
if(!$this_url || ($this_url['host'] != unserialize(db_result(db_query($sql_host)))) || ($this_url['port'] != unserialize(db_result(db_query($sql_port)))) || ($this_url['path'] != unserialize(db_result(db_query($sql_path))) . '/select')){
	header('Location: /');
	exit(0);
}

/*
$group_url = $proxy_url[1];
if($user->uid != 1){
	$group_check = 'im_users_groups_nids:(0';
	
	$tbl = 'content_field_users_group_users';
	if(db_result(db_query("SHOW TABLES LIKE '%s'", $tbl))){
		$result = db_query("SELECT DISTINCT nid FROM %s WHERE field_users_group_users_value=%d", $tbl, $user->uid);
		while($row = db_fetch_object($result)){
			$group_check .= ' OR ' . $row->nid;
		}
	}
	$group_check .= ')';
	
	$replacements = 0;
	$group_url = preg_replace('/im_users_groups_nids%3A(%28|\().*(%29|\))/', rawurlencode($group_check), $proxy_url[1], -1, $replacements);
	if(!$replacements){
		header('Location: /');
		exit(0);
	}
	
	 //$fp = fopen('/tmp/url', 'a');
	 //fputs($fp, $proxy_url[1]."\n");
	 //fputs($fp, $group_url."\n");
	 //fclose($fp);
}


// Now make the response. Can't use drupal_http_request() since it doesn't exist...
$response = ozmo_http_request($group_url);
*/
$response = ozmo_http_request($proxy_url[1]);
$data = $response -> data;
print ($data);


/*
 * Literally a renamed copy of drupal_http_request()
 */
function ozmo_http_request($url, $headers = array(), $method = 'GET', $data = NULL, $retry = 3) {
  $db_prefix = FALSE;

  $result = new stdClass();

  // Parse the URL and make sure we can handle the schema.
  $uri = parse_url($url);

  if ($uri == FALSE) {
    $result->error = 'unable to parse URL';
    $result->code = -1001;
    return $result;
  }

  if (!isset($uri['scheme'])) {
    $result->error = 'missing schema';
    $result->code = -1002;
    return $result;
  }

  switch ($uri['scheme']) {
    case 'http':
    case 'feed':
      $port = isset($uri['port']) ? $uri['port'] : 80;
      $host = $uri['host'] . ($port != 80 ? ':' . $port : '');
      $fp = @fsockopen($uri['host'], $port, $errno, $errstr, 15);
      break;
    case 'https':
      // Note: Only works for PHP 4.3 compiled with OpenSSL.
      $port = isset($uri['port']) ? $uri['port'] : 443;
      $host = $uri['host'] . ($port != 443 ? ':' . $port : '');
      $fp = @fsockopen('ssl://' . $uri['host'], $port, $errno, $errstr, 20);
      break;
    default:
      $result->error = 'invalid schema ' . $uri['scheme'];
      $result->code = -1003;
      return $result;
  }

  // Make sure the socket opened properly.
  if (!$fp) {
    // When a network error occurs, we use a negative number so it does not
    // clash with the HTTP status codes.
    $result->code = -$errno;
    $result->error = trim($errstr);

    // Mark that this request failed. This will trigger a check of the web
    // server's ability to make outgoing HTTP requests the next time that
    // requirements checking is performed.
    // @see system_requirements()
    variable_set('drupal_http_request_fails', TRUE);

    return $result;
  }

  // Construct the path to act on.
  $path = isset($uri['path']) ? $uri['path'] : '/';
  if (isset($uri['query'])) {
    $path .= '?' . $uri['query'];
  }

  // Create HTTP request.
  $defaults = array(
    // RFC 2616: "non-standard ports MUST, default ports MAY be included".
    // We don't add the port to prevent from breaking rewrite rules checking the
    // host that do not take into account the port number.
    'Host' => "Host: $host", 
    'User-Agent' => 'User-Agent: Drupal (+http://drupal.org/)',
  );

  // Only add Content-Length if we actually have any content or if it is a POST
  // or PUT request. Some non-standard servers get confused by Content-Length in
  // at least HEAD/GET requests, and Squid always requires Content-Length in
  // POST/PUT requests.
  $content_length = strlen($data);
  if ($content_length > 0 || $method == 'POST' || $method == 'PUT') {
    $defaults['Content-Length'] = 'Content-Length: ' . $content_length;
  }

  // If the server url has a user then attempt to use basic authentication
  if (isset($uri['user'])) {
    $defaults['Authorization'] = 'Authorization: Basic ' . base64_encode($uri['user'] . (!empty($uri['pass']) ? ":" . $uri['pass'] : ''));
  }

  // If the database prefix is being used by SimpleTest to run the tests in a copied
  // database then set the user-agent header to the database prefix so that any
  // calls to other Drupal pages will run the SimpleTest prefixed database. The
  // user-agent is used to ensure that multiple testing sessions running at the
  // same time won't interfere with each other as they would if the database
  // prefix were stored statically in a file or database variable.
  if (is_string($db_prefix) && preg_match("/^simpletest\d+$/", $db_prefix, $matches)) {
    $defaults['User-Agent'] = 'User-Agent: ' . $matches[0];
  }

  foreach ($headers as $header => $value) {
    $defaults[$header] = $header . ': ' . $value;
  }

  $request = $method . ' ' . $path . " HTTP/1.0\r\n";
  $request .= implode("\r\n", $defaults);
  $request .= "\r\n\r\n";
  $request .= $data;

  $result->request = $request;

  fwrite($fp, $request);

  // Fetch response.
  $response = '';
  while (!feof($fp) && $chunk = fread($fp, 1024)) {
    $response .= $chunk;
  }
  fclose($fp);

  // Parse response.
  list($split, $result->data) = explode("\r\n\r\n", $response, 2);
  $split = preg_split("/\r\n|\n|\r/", $split);

  list($protocol, $code, $status_message) = explode(' ', trim(array_shift($split)), 3);
  $result->protocol = $protocol;
  $result->status_message = $status_message;

  $result->headers = array();

  // Parse headers.
  while ($line = trim(array_shift($split))) {
    list($header, $value) = explode(':', $line, 2);
    if (isset($result->headers[$header]) && $header == 'Set-Cookie') {
      // RFC 2109: the Set-Cookie response header comprises the token Set-
      // Cookie:, followed by a comma-separated list of one or more cookies.
      $result->headers[$header] .= ',' . trim($value);
    }
    else {
      $result->headers[$header] = trim($value);
    }
  }

  $responses = array(
    100 => 'Continue', 
    101 => 'Switching Protocols', 
    200 => 'OK', 
    201 => 'Created', 
    202 => 'Accepted', 
    203 => 'Non-Authoritative Information', 
    204 => 'No Content', 
    205 => 'Reset Content', 
    206 => 'Partial Content', 
    300 => 'Multiple Choices', 
    301 => 'Moved Permanently', 
    302 => 'Found', 
    303 => 'See Other', 
    304 => 'Not Modified', 
    305 => 'Use Proxy', 
    307 => 'Temporary Redirect', 
    400 => 'Bad Request', 
    401 => 'Unauthorized', 
    402 => 'Payment Required', 
    403 => 'Forbidden', 
    404 => 'Not Found', 
    405 => 'Method Not Allowed', 
    406 => 'Not Acceptable', 
    407 => 'Proxy Authentication Required', 
    408 => 'Request Time-out', 
    409 => 'Conflict', 
    410 => 'Gone', 
    411 => 'Length Required', 
    412 => 'Precondition Failed', 
    413 => 'Request Entity Too Large', 
    414 => 'Request-URI Too Large', 
    415 => 'Unsupported Media Type', 
    416 => 'Requested range not satisfiable', 
    417 => 'Expectation Failed', 
    500 => 'Internal Server Error', 
    501 => 'Not Implemented', 
    502 => 'Bad Gateway', 
    503 => 'Service Unavailable', 
    504 => 'Gateway Time-out', 
    505 => 'HTTP Version not supported',
  );
  // RFC 2616 states that all unknown HTTP codes must be treated the same as the
  // base code in their class.
  if (!isset($responses[$code])) {
    $code = floor($code / 100) * 100;
  }

  switch ($code) {
    case 200: // OK
    case 304: // Not modified
      break;
    case 301: // Moved permanently
    case 302: // Moved temporarily
    case 307: // Moved temporarily
      $location = $result->headers['Location'];

      if ($retry) {
        $result = drupal_http_request($result->headers['Location'], $headers, $method, $data, --$retry);
        $result->redirect_code = $result->code;
      }
      $result->redirect_url = $location;

      break;
    default:
      $result->error = $status_message;
  }

  $result->code = $code;
  return $result;
}

?>

