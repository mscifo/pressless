<?php
error_reporting( E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_ERROR | E_PARSE | E_USER_ERROR | E_USER_WARNING | E_RECOVERABLE_ERROR );
function debug($v) { fwrite(fopen('php://stderr', 'w'), $v."\n"); }
//$fd = fopen('php://fd/3', 'r+');  // for getremainingtime

// override header function so we can catch/process headers, instead of wordpress outputting them directly (and then possibly exiting)
$_RESPONSE = array('statusCode' => 200, 'body' => '', 'headers' => array());
override_function('header', '$string', 'global $_RESPONSE;$parts = explode(": ", $string); if (is_array($parts) && count($parts) >= 2) { $_RESPONSE["headers"][$parts[0]] = $parts[1]; } else if (strpos($string, "HTTP/1.0 ") == 0) { $code = explode(" ", $string); if (is_array($code) && count($code) >= 2) { $_RESPONSE["statusCode"] = intval($code[1]); } } return null;');
//override_function('mysql_real_escape_string', '$string', 'return mysqli_real_escape_string($string);');

// Get event data and context object
$event = json_decode($argv[1], true) ?: [];
$context = json_decode($argv[2], true) ?: [];
$apiMode = $event['pressless_api_only'] ?: false;
$evolutionMode = $event['pressless_evolution'] ?: false;

$_SERVER['SERVER_PROTOCOL'] = 'HTTPS';
$_SERVER["DOCUMENT_ROOT"] = '/var/task/wordpress'; // lambda specific!
$_SERVER['HTTP_HOST'] = $event['headers']['Host'] ?: 'localhost';
$_SERVER['SERVER_NAME'] = $event['headers']['Host'] ?: 'localhost';
$_SERVER['REQUEST_METHOD'] = $event['httpMethod'] ?: 'GET';
$_SERVER['REQUEST_URI'] = $event['path'] ?: '/';
$_SERVER['HTTP_X_FORWARDED_FOR'] = $event['headers']['X-Forwarded-For'];
$_SERVER['HTTP_CLIENT_IP'] = $_SERVER['REMOTE_ADDR'] = $event['requestContext']['identity']['sourceIp'];
 
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

if (!isset($event['body'])) $event['body'] = '';
$event['body'] = preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $event['body']) ? base64_decode($event['body']) : $event['body'];  // detect if post body is base64-encoded, and decode
parse_str($event['body'], $_POST);
$_POST = array_map(function ($v) { return is_numeric($v) ? (int)$v : $v; }, $_POST);
debug('POST: ' . var_export($_POST, true));
 
// in case wordpress crashes/exits, we don't want to lose any output, which 
// we'll use in the shutdown function
function buffer($buffer) {
    global $_RESPONSE;

    // how do we tell if this function was called by shutdown or by ob_end_flush()??
    //$_RESPONSE['body'] .= $buffer;
    $_RESPONSE['body'] = $buffer;

    debug('buffer response: ' . json_encode($_RESPONSE));

    return '';
}

// in case wordpress crashes, we want to know why and properly redirect
function shutdown() {
    global $_RESPONSE;

    // flush buffer, so buffer() can add buffer to $_RESPONSE
    // in case ob_end_flush() wasn't called before exiting
    ob_end_flush();

    debug('shutdown response: ' . json_encode($_RESPONSE));
    if (($error = error_get_last())) {
        // since this function will be called for every request, 
        // we don't want to print errors and redirects for E_NOTICE/E_WARNING
        if ($error['type'] == E_NOTICE || $error['type'] == E_WARNING) {
            if (isset($_RESPONSE['statusCode'])) print json_encode($_RESPONSE);
            return;
        }

        debug('php error: ' . json_encode($error));
    }

    // if we got a redirect header before the shutdown call, use it
    if (isset($_RESPONSE['statusCode'])) return print(json_encode($_RESPONSE));

    // otherwise, redirect to 404
    print json_encode([
        'statusCode' => 302,
        'body' => '',
        'headers' => array(
            'Location' => '/404'
        )
    ]);
}
register_shutdown_function('shutdown');

debug('event: ' . $argv[1]);

try {
    // serve static files
    debug('path is ' . $event['path']);
    $path_parts = pathinfo($event['path']);   
    if ($event['path'] != '/' && ((strpos($event['path'], '/wp-content/') === 0 && $path_parts['extension'] != 'php') || in_array($path_parts['extension'], array('html','htm','css','txt','csv','scss','json','xml','ico','js','gif','jpg','jpeg','png','pdf','otf','ttf','woff','eot','svg')))) {
        $file = 'wordpress' . $event['path'];
        if (is_readable($file)) {
            debug('serving static file ' . $file); 
            $isBase64 = false;
            $fileType = mime_content_type($file);
            $fileContents = file_get_contents($file);

            // convert binary data to base64
            if (strpos($fileType, 'text/') === false && strpos($fileType, 'application/json') === false) {
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
            return print(json_encode([
                'statusCode' => 200,
                'body' => $fileContents,
                'headers' => array_merge(array('Content-Type' => $fileType, 'X-Binary' => ($isBase64?'true':'false')), $_RESPONSE['headers'])
            ]));
        } else {
            debug('unable to read static file ' . $file); 
            return print(json_encode([
                'statusCode' => 404,
                'body' => ''
            ]));
        }
    }

    debug('checking for evolution');
    // if grep Evolution wp-config.php then copy Evolution and ansible file
    $wpConfig = file_get_contents('wordpress/wp-config.php');
    if (strpos($wpConfig, 'Evolution.php') !== false) {
        debug('found evolution');
        $evolutionMode = true;
    }

    ob_start('buffer');

    if ($apiMode) {
        debug('api-only mode');
        require_once 'wordpress/wp-config.php';
        // this might unintentionally bypass auth checks
        rest_get_server()->serve_request($_SERVER['REQUEST_URI']);     
    } else if ($_SERVER['REQUEST_URI'] != '/' && is_file('wordpress' . $_SERVER['REQUEST_URI'])) {
        debug('specific non static file requested');
        $_SERVER['PHP_SELF'] = $event['path'];
        require_once 'wordpress' . $_SERVER['REQUEST_URI'];
    } else if ($_SERVER['REQUEST_URI'] != '/' && is_dir('wordpress' . $_SERVER['REQUEST_URI'])) {
        $indexFile = strpos(strrev($_SERVER['REQUEST_URI']), '/') === 0 ? 'index.php' : '/index.php'; 
        debug('specific non static directory requested, loading wordpress' . $_SERVER['REQUEST_URI'] . $indexFile);
        require_once 'wordpress' . $_SERVER['REQUEST_URI'] . $indexFile;        
    } else {
        debug('full wordpress mode');
        //require_once 'wordpress/index.php';
        require_once 'wordpress/wp-config.php';
        define('WP_USE_THEMES', true);
        $wp_did_header = true;
        wp();
        if ($evolutionMode) {
            require_once 'wordpress/wp/wp-includes/template-loader.php';
        } else {
            require_once 'wordpress/wp-includes/template-loader.php';
        }
    }

    $response = ob_get_contents();
    ob_end_clean();

    // send data back to shim
    print json_encode([
        'statusCode' => intval($_RESPONSE['statusCode']) ?: 200,
        'body' => $response,
        'headers' => $_RESPONSE['headers'] ?: array()
    ]);
} catch (Exception $e) {
    print json_encode([
        'statusCode' => 503,
        'body' => $e->getMessage() . $e->getTraceAsString(),
        'headers' => array('Content-Type' => 'text/html')
    ]);
} finally {
    fclose($stderr); 
}