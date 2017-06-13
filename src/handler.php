<?php
error_reporting(0);
function debug($v) { fwrite(fopen('php://stderr', 'w'), $v."\n"); }
function render($code, $headers = array(), $body = '') { $_RESPONSE['statusCode'] = $code; $_RESPONSE['headers'] = array_merge($headers, $_RESPONSE['headers']); print $body; }
//$fd = fopen('php://fd/3', 'r+');  // for getremainingtime, currently unused

// initialize base response array
$_RESPONSE = array('statusCode' => 200, 'body' => '', 'headers' => array());
$_COOKIECOUNT = 0;

// override header function so we can catch/process headers, instead of wordpress outputting them directly (and then possibly exiting)
override_function('header', '$string', 'global $_RESPONSE;$parts = explode(": ", $string); if (is_array($parts) && count($parts) >= 2) { $_RESPONSE["headers"][$parts[0]] = $parts[1]; } else if (strpos($string, "HTTP/1.0 ") == 0) { $code = explode(" ", $string); if (is_array($code) && count($code) >= 2) { $_RESPONSE["statusCode"] = intval($code[1]); } } return null;');
rename_function("__overridden__", '__overridden__header');
// override setcookie function so we can capture the resulting header and modify the Set-Cookie header name to allow for multiple cookies to be set, which we process using binary case iteration in handler.js
override_function('setcookie', '', 'global $_RESPONSE;global $_COOKIECOUNT;$args = func_get_args();$_RESPONSE["headers"]["X-Set-Cookie-".++$_COOKIECOUNT] = rawurlencode($args[0]) . "=" . rawurlencode($args[1]) . (empty($args[2]) ? "" : "; expires=" . gmdate("D, d-M-Y H:i:s", $args[2]) . " GMT") . (empty($args[3]) ? "" : "; path=" . $args[3]) . (empty($args[4]) ? "" : "; domain=" . $args[4]) . (empty($args[5]) ? "" : "; secure" . $args[5]) . (empty($args[6]) ? "" : "; HttpOnly" . $args[6]); return null;');
rename_function("__overridden__", '__overridden__setcookie');
// possibly needed by some older plugins
override_function('mysql_real_escape_string', '$string', 'return mysqli_real_escape_string($string);');
rename_function("__overridden__", '__overridden__mysql_real_escape_string');

// Get event data and context object
$event = json_decode($argv[1], true) ?: [];
$context = json_decode($argv[2], true) ?: [];
$apiMode = $event['pressless_api_only'] ?: false;
$wpDir = file_exists('web') ? 'web' : 'wordpress';

// populate needed $_SERVER superglobal values
$_SERVER['SERVER_PROTOCOL'] = 'HTTPS';
$_SERVER['DOCUMENT_ROOT'] = '/var/task/' . $wpDir; // lambda specific!
$_SERVER['HTTP_HOST'] = $event['headers']['Host'] ?: 'localhost';
$_SERVER['SERVER_NAME'] = $event['headers']['Host'] ?: 'localhost';
$_SERVER['REQUEST_METHOD'] = $event['httpMethod'] ?: 'GET';
$_SERVER['REQUEST_URI'] = $event['path'] ?: '/';
$_SERVER['HTTP_X_FORWARDED_FOR'] = $event['headers']['X-Forwarded-For'];
$_SERVER['HTTP_CLIENT_IP'] = $_SERVER['REMOTE_ADDR'] = $event['requestContext']['identity']['sourceIp'];

// populate $_GET, $_POST, $_COOKIE superglobals
if (!isset($event['queryStringParameters']) || !is_array($event['queryStringParameters'])) $event['queryStringParameters'] = array();
foreach ($event['queryStringParameters'] as $k => $v) {
    if (strpos($k, '[]') > 0) {
        // weird wordpress handling of array-like query string parameters
        $properKey = str_replace('[]', '', $k);
        $_GET[$properKey] = isset($_GET[$properKey]) ? $_GET[$properKey] . $v : $v;
    } else {
        $_GET[$k] = is_numeric($v) ? (int)$v : $v;
    }
}
debug('GET: ' . print_r($_GET, true));
// ensure $_SERVER['REQUEST_URI'] has the query string if query string parameters exist
$_SERVER['REQUEST_URI'] .= empty($_GET) ? '' : '?'.http_build_query($_GET);
//$_SERVER['QUERY_STRING'] .= empty($_GET) ? '' : http_build_query($_GET);

if (!isset($event['body'])) $event['body'] = '';
$event['body'] = preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $event['body']) ? base64_decode($event['body']) : $event['body'];  // detect if post body is base64-encoded, and decode
parse_str($event['body'], $_POST);
$_POST = array_map(function ($v) { return is_numeric($v) ? (int)$v : $v; }, $_POST);
debug('POST: ' . var_export($_POST, true));

if (!isset($event['headers']['Cookie'])) $event['headers']['Cookie'] = '';
parse_str(str_replace('; ', '&', $event['headers']['Cookie']), $_COOKIE);
debug('COOKIE: ' . print_r($_COOKIE, true));

// capture all output
function buffer($buffer) {
    global $_RESPONSE;

    // we need to fix references to load-scripts.php and load-styles.php, since they split the 
    // comma separated styles/scripts into multiple 'load[]' query parameters and only the last 
    // instance is passed in the event by ApiGateway
    $newBuffer = preg_replace('/(?<!(?:c=0|ltr))&amp;load%5B%5D=/', '', $buffer);
    if (!empty($newBuffer)) $buffer = $newBuffer;

    return json_encode([
        'statusCode' => intval($_RESPONSE['statusCode']) ?: 200,
        'body' => $buffer,
        'headers' => $_RESPONSE['headers'] ?: array()
    ]);
}

// in case wordpress crashes, we want to know why
function shutdown() {
    if (($error = error_get_last())) {
        // since this function will be called for every request, 
        // we don't want to print errors for E_NOTICE/E_WARNING
        if ($error['type'] == E_NOTICE || $error['type'] == E_WARNING) return;
        fwrite(fopen('php://stderr', 'w'), 'php error: ' . json_encode($error));
    }
}
register_shutdown_function('shutdown');

debug('event: ' . $argv[1]);

try {
    // serve static files
    debug('path is ' . $event['path']);
    $path_parts = pathinfo($event['path']);   
    if ($event['path'] != '/' && in_array($path_parts['extension'], array('html','htm','css','txt','csv','scss','json','xml','ico','js','gif','jpg','jpeg','png','pdf','otf','ttf','woff','eot','svg','zip'))) {
        $file = (strpos($event['path'], '/tmp/') === 0) ? $event['path'] : $wpDir . $event['path'];
        if (is_readable($file)) {
            debug('serving static file ' . $file); 
            $isBase64 = false;
            $fileType = mime_content_type($file);
            $fileContents = file_get_contents($file);

            // convert binary data to base64
            if (strpos($fileType, 'text/') === false && strpos($fileType, 'application/json') === false && strpos($fileType, 'xml') === false) {
                debug('base64 enconding file ' . $file . ' of type ' . $fileType);
                $fileContents = base64_encode($fileContents);
                $isBase64 = true;
            }

            if ($path_parts['extension'] == 'svg') $fileType = 'image/svg+xml';
            if ($path_parts['extension'] == 'css') $fileType = 'text/css';
            if ($path_parts['extension'] == 'js') $fileType = 'text/javascript';
            if ($path_parts['extension'] == 'json') $fileType = 'application/json';
            if ($path_parts['extension'] == 'xml') $fileType = 'application/xml';
            if ($path_parts['extension'] == 'html' || $path_parts['extension'] == 'htm') $fileType = 'text/html';

            debug('static file headers ' . print_r($_RESPONSE['headers'], true));
            return render(200, array('Content-Type' => $fileType, 'X-Binary' => ($isBase64?'true':'false')), $fileContents);
        } else {
            debug('unable to read static file ' . $file); 
            return render(404);
        }
    }

    ob_start('buffer');

    if ($apiMode) {
        debug('api-only mode');
        require_once $wpDir . '/wp-config.php';
        // this might unintentionally bypass auth checks
        rest_get_server()->serve_request($_SERVER['REQUEST_URI']);     
    } else if ($event['path'] != '/' && is_file($wpDir . $event['path'])) {
        debug('specific non static file requested');
        $_SERVER['PHP_SELF'] = $event['path'];
        require_once $wpDir . $event['path'];
    } else if ($event['path'] != '/' && is_dir($wpDir . $event['path'])) {
        $indexFile = strpos(strrev($event['path']), '/') === 0 ? 'index.php' : '/index.php'; 
        debug('specific non static directory requested, loading wordpress' . $event['path'] . $indexFile);
        require_once $wpDir . $event['path'] . $indexFile;
    } else {
        debug('full wordpress mode');
        require_once $wpDir . '/index.php';
    }
} catch (Exception $e) {
    return render(503, array('Content-Type' => 'text/html'), $e->getMessage() . $e->getTraceAsString());
} finally {
    ob_end_flush();
}