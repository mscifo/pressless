<?php
error_reporting(0);

// in case wordpress crashes, we want to know why
function shutdown() {
    global $_RENDERABLE;
    global $_RESPONSE;

    if (($error = error_get_last())) {
        // since this function will be called for every request, 
        // we don't want to print errors for E_NOTICE/E_WARNING
        if ($error['type'] == E_NOTICE || $error['type'] == E_WARNING) return;
        fwrite(STDERR, 'php error: ' . json_encode($error));

        // if we haven't gotten to a point where output is generated,
        // assume a crash and instead render error
        if (!$_RENDERABLE || $error['type'] === E_ERROR) return print(json_encode([
            'statusCode' => 500,
            'body' => '<h1>An error occurred!</h1><pre>' . json_encode($error) . '</pre>',
            'headers' => array('Content-Type' => 'text/html')
        ]));
    }
}
register_shutdown_function('shutdown');

require_once 'aws.phar';

// create helper functions
function debug($v) { fwrite(STDERR, $v."\n"); }
function getremainingtime($v) { return fread(fopen('php://fd/3', 'r+'), 64); }
function render($code, $headers = array(), $body = '') { global $_RESPONSE; $_RESPONSE['statusCode'] = $code; $_RESPONSE['headers'] = array_merge($headers, $_RESPONSE['headers']); print $body; }
function obsafe_print_r($var, $level = 0) {
    $tabs = "\t"; 
    for ($i = 1; $i <= $level; $i++) { $tabs .= "\t"; }
    if (is_array($var)) {
        $title = "Array";
    } elseif (is_object($var)) {
        $title = get_class($var)." Object";
    }
    $output = $title . "\n\n";
    foreach($var as $key => $value) {
        if (is_array($value) || is_object($value)) {
            $level++;
            $value = obsafe_print_r($value, $level);
            $level--;
        }
        $output .= $tabs . "[" . $key . "] => " . $value . "\n";
    }
    return $output;
}

// initialize globals
$_RENDERABLE = false;
$_RESPONSE = array('statusCode' => 200, 'body' => '', 'headers' => array());
$_COOKIECOUNT = 0;
$_SESSION = array('id' => uniqid("", true), 'name' => 'pressless_session');

// import pressless environment variables
if (!getenv('PRESSLESS_S3_WEBSITE_BUCKET') || !getenv('PRESSLESS_S3_LOGGING_BUCKET')) {
    debug('Missing pressless environment variables');
    trigger_error('Missing pressless environment variables', E_USER_WARNING);
} else {
    define('PRESSLESS_S3_WEBSITE_BUCKET', getenv('PRESSLESS_S3_WEBSITE_BUCKET'));
    define('PRESSLESS_S3_LOGGING_BUCKET', getenv('PRESSLESS_S3_LOGGING_BUCKET'));
    define('PRESSLESS_DOMAIN', getenv('PRESSLESS_DOMAIN'));

    if (substr_count(PRESSLESS_S3_WEBSITE_BUCKET, '.') > 1) {
        $rootDomainBucketParts = explode('.', PRESSLESS_S3_WEBSITE_BUCKET);
        while (count($rootDomainBucketParts) > 2) { array_shift($rootDomainBucketParts); }
        define('PRESSLESS_S3_WEBSITE_ROOT_BUCKET', implode('.', $rootDomainBucketParts));
    }
}

// hardcode wordpress URLs
define('WP_HOME', 'https://'.PRESSLESS_DOMAIN);
define('WP_SITEURL', 'https://'.PRESSLESS_DOMAIN);

// disable wordpress plugin/theme installer/editor
define('DISALLOW_FILE_MODS', true);

// override header function so we can catch/process headers, instead of wordpress outputting them directly (and then possibly exiting)
override_function('header', '$string', 'global $_RESPONSE;$parts = explode(": ", $string); if (is_array($parts) && count($parts) >= 2) { $_RESPONSE["headers"][$parts[0]] = $parts[1]; } else if (strpos($string, "HTTP/1.0 ") == 0) { $code = explode(" ", $string); if (is_array($code) && count($code) >= 2) { $_RESPONSE["statusCode"] = intval($code[1]); } } return null;');
rename_function("__overridden__", '__overridden__header');
// override setcookie function so we can capture the resulting header and modify the Set-Cookie header name to allow for multiple cookies to be set, which we process using binary case iteration in handler.js
override_function('setcookie', '', 'global $_RESPONSE;global $_COOKIECOUNT;$args = func_get_args();$_RESPONSE["headers"]["X-Set-Cookie-".++$_COOKIECOUNT] = rawurlencode($args[0]) . "=" . rawurlencode($args[1]) . (empty($args[2]) ? "" : "; expires=" . gmdate("D, d-M-Y H:i:s", $args[2]) . " GMT") . (empty($args[3]) ? "" : "; path=" . $args[3]) . (empty($args[4]) ? "" : "; domain=" . $args[4]) . (empty($args[5]) ? "" : "; secure" . $args[5]) . (empty($args[6]) ? "" : "; HttpOnly" . $args[6]); return null;');
rename_function("__overridden__", '__overridden__setcookie');
// override session functions since pressless doesn't include the session extension
override_function('session_id', '', 'global $_SESSION;$args = func_get_args();$old_id = $_SESSION["id"];if ($args[0]) $_SESSION["id"] = $args[0];return $old_id;');
rename_function("__overridden__", '__overridden__session_id');
override_function('session_start', '', 'return true;');
rename_function("__overridden__", '__overridden__session_start');
override_function('session_cache_limiter', '', 'return "nocache";');
rename_function("__overridden__", '__overridden__session_cache_limiter');
override_function('session_name', '', 'global $_SESSION;$args = func_get_args();$old_name = $_SESSION["name"];if ($args[0]) $_SESSION["name"] = $args[0];return $old_name;');
rename_function("__overridden__", '__overridden__session_name');
override_function('session_get_cookie_params', '', 'return;');
rename_function("__overridden__", '__overridden__session_get_cookie_params');
override_function('session_set_cookie_params', '', 'return;');
rename_function("__overridden__", '__overridden__session_set_cookie_params');
override_function('session_write_close', '', 'global $_SESSION;$_SESSION = array();;return;');
rename_function("__overridden__", '__overridden__session_write_close');
override_function('session_regenerate_id', '', 'global $_SESSION;$_SESSION["id"] = uniqid("", true);return true;');
rename_function("__overridden__", '__overridden__session_regenerate_id');
$_SESSION = [];

// override file functions to force an s3 path for writes since lambda filesystem is readonly
// @see http://docs.aws.amazon.com/aws-sdk-php/v3/guide/service/s3-stream-wrapper.html
$s3Client = \Aws\S3\S3Client::factory([
    'region' => 'us-east-1', 
    'credentials' => [
        'key'    => getenv('AWS_ACCESS_KEY_ID'),
        'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
        'token'  => getenv('AWS_SESSION_TOKEN')
    ]
]);
$s3Client->registerStreamWrapper();

function s3_func_get_args($args) { return array_map(function ($v) { if (!is_string($v)) return $v; $nv = strpos($v, 'wp-content/uploads/') > 0 ? 's3://' . PRESSLESS_S3_WEBSITE_BUCKET . preg_replace('/^.*?\/wp-content\/uploads/', '/wp-content/uploads', $v) : $v; if ($v != $nv) debug('s3_func_get_args: ' . $v . ' => ' . $nv); return $nv; }, $args); }
rename_function('file_get_contents', '__alias__file_get_contents');
override_function('file_get_contents', '', 'return call_user_func_array("__alias__file_get_contents", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__file_get_contents');
rename_function('fopen', '__alias__fopen');
override_function('fopen', '', 'return call_user_func_array("__alias__fopen", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__fopen');
rename_function('file_put_contents', '__alias__file_put_contents');
override_function('file_put_contents', '', 'return call_user_func_array("__alias__file_put_contents", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__file_put_contents');
rename_function('copy', '__alias__copy');
override_function('copy', '', 'return call_user_func_array("__alias__copy", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__copy');
rename_function('rename', '__alias__rename');
override_function('rename', '', 'return call_user_func_array("__alias__rename", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__rename');
rename_function('unlink', '__alias__unlink');
override_function('unlink', '', 'return call_user_func_array("__alias__unlink", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__unlink');
rename_function('mkdir', '__alias__mkdir');
override_function('mkdir', '', 'return call_user_func_array("__alias__mkdir", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__mkdir');
rename_function('rmdir', '__alias__rmdir');
override_function('rmdir', '', 'return call_user_func_array("__alias__rmdir", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__rmdir');
rename_function('filesize', '__alias__filesize');
override_function('filesize', '', 'return call_user_func_array("__alias__filesize", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__filesize');
rename_function('is_file', '__alias__is_file');
override_function('is_file', '', 'return call_user_func_array("__alias__is_file", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__is_file');
rename_function('file_exists', '__alias__file_exists');
override_function('file_exists', '', 'return call_user_func_array("__alias__file_exists", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__file_exists');
rename_function('filetype', '__alias__filetype');
override_function('filetype', '', 'return call_user_func_array("__alias__filetype", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__filetype');
rename_function('file', '__alias__file');
override_function('file', '', 'return call_user_func_array("__alias__file", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__file');
rename_function('filemtime', '__alias__filemtime');
override_function('filemtime', '', 'return call_user_func_array("__alias__filemtime", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__filemtime');
rename_function('is_dir', '__alias__is_dir');
override_function('is_dir', '', 'return call_user_func_array("__alias__is_dir", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__is_dir');
rename_function('opendir', '__alias__opendir');
override_function('opendir', '', 'return call_user_func_array("__alias__opendir", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__opendir');
rename_function('readdir', '__alias__readdir');
override_function('readdir', '', 'return call_user_func_array("__alias__readdir", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__readdir');
rename_function('rewinddir', '__alias__rewinddir');
override_function('rewinddir', '', 'return call_user_func_array("__alias__rewinddir", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__rewinddir');
rename_function('closedir', '__alias__closedir');
override_function('closedir', '', 'return call_user_func_array("__alias__closedir", s3_func_get_args(func_get_args()));');
rename_function("__overridden__", '__overridden__closedir');

// Get event data and context object
$event = json_decode($argv[1], true) ?: [];
$context = json_decode($argv[2], true) ?: [];
$apiMode = $event['pressless_api_only'] ?: false;
$wpDir = file_exists('web') ? 'web' : 'wordpress';

// detect cacheability of request
$cacheable = false;
if ($event['httpMethod'] == 'GET'
        && !isset($event['headers']['X-Bypass-Cache'])
        && substr($event['path'], strlen($event['path']) - 4) !== '.php'  // don't cache php files
        && strpos($event['path'], '/wp-admin/') === false) {  // don't cache wordpress admin urls
    $cacheable = true;
}

// populate needed $_SERVER superglobal values
$_SERVER['DOCUMENT_ROOT'] = '/var/task/' . $wpDir; // lambda specific!
$_SERVER['SERVER_PROTOCOL'] = $cacheable ? 'HTTP' : 'HTTPS';
$_SERVER['SERVER_PORT'] = $cacheable ? 80 : 443;
$_SERVER['HTTPS'] = $cacheable ? null : 'on';
$_SERVER['HTTP_HOST'] = $cacheable ? PRESSLESS_S3_WEBSITE_BUCKET : PRESSLESS_DOMAIN;
$_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];
$_SERVER['REQUEST_METHOD'] = $event['httpMethod'] ?: 'GET';
$_SERVER['REQUEST_URI'] = $event['path'] ?: '/';
$_SERVER['HTTP_X_FORWARDED_FOR'] = $event['headers']['X-Forwarded-For'];
$_SERVER['HTTP_CLIENT_IP'] = $_SERVER['REMOTE_ADDR'] = $event['requestContext']['identity']['sourceIp'];
debug('SERVER: ' . var_export($_SERVER, true));

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
if ($event['httpMethod'] == 'POST' && ($event['path'] == '/xmlrpc.php' || $event['headers']['Content-Type'] == 'text/xml' || $event['headers']['Content-Type'] == 'application/xml' || $event['headers']['Content-Type'] == 'application/json' || $event['headers']['Content-Type'] == 'application/x-www-form-urlencoded')) {
    $HTTP_RAW_POST_DATA = str_replace(["\n", "\r"], '', $event['body']);
} else {
    parse_str($event['body'], $_POST);
}
$_POST = array_map(function ($v) { return is_numeric($v) ? (int)$v : $v; }, $_POST);
debug('HTTP_RAW_POST_DATA: ' . $HTTP_RAW_POST_DATA);
debug('POST: ' . var_export($_POST, true));

if (!isset($event['headers']['Cookie'])) $event['headers']['Cookie'] = '';
parse_str(str_replace('; ', '&', $event['headers']['Cookie']), $_COOKIE);
foreach ($_COOKIE as $k => $v) {
    // remove wordpress login cookie if request is cacheable since we don't want the wordpress
    // admin bar included in the cached output
    if ($cacheable && strpos($k, 'wordpress_logged_in_') === 0) {
        unset($_COOKIE[$k]);
    }
}
debug('COOKIE: ' . print_r($_COOKIE, true));

// capture all output
function buffer($buffer) {
    global $_RESPONSE;
    global $cacheable;
    global $event;
    global $s3Client;

    // we need to fix references to load-scripts.php and load-styles.php, since they split the 
    // comma separated styles/scripts into multiple 'load[]' query parameters and only the last 
    // instance is passed in the event by ApiGateway
    $newBuffer = preg_replace('/(?<!(?:c=0|ltr))&amp;load%5B%5D=/', '', $buffer);
    $newBuffer = preg_replace('/load-scripts.php\?c=\d([^&])/', 'load-scripts.php?c=1&load%5B%5D=$1', $newBuffer);
    if (!empty($newBuffer)) $buffer = $newBuffer;

    // allow bypassing of cacher for success responses, in case we want to do an initial crawl or specifically hit origin
    if ($cacheable && intval($_RESPONSE['statusCode']) >= 200 && intval($_RESPONSE['statusCode']) < 300) {
        // default expiration for cached files
        $expires = strtotime('365 days');

        if (isset($_RESPONSE['headers']['X-Binary']) && $_RESPONSE['headers']['X-Binary'] == 'true') {
            // decode binary before writing to cache since it was base64 encoded for ApiGateway support
            debug('base64 decode binary file ' . $event['path']);
            $cacheBuffer = base64_decode($buffer);
        } else {
            // change pressless domain to website bucket domain so links in cached buffer use domain of cache, not origin
            $cacheBuffer = str_replace(PRESSLESS_DOMAIN, PRESSLESS_S3_WEBSITE_BUCKET, $buffer);

            // switch https -> http for website bucket urls in cached buffer, since website bucket doesn't support https
            $cacheBuffer = str_replace('https://' . PRESSLESS_S3_WEBSITE_BUCKET, 'http://' . PRESSLESS_S3_WEBSITE_BUCKET, $cacheBuffer);

            if (strpos($_RESPONSE['headers']['Content-Type'], 'text/html') !== false) {
                // support for standard wordpress search
                // since we can't store s3 objects with a '?' in the name without it being url encoded, 
                // switch to path based search which we can store in s3
                if (isset($_GET['s'])) {
                    $event['path'] .= (strcmp(substr($event['path'], strlen($event['path']) - 1), '/') === 0) ? 'search/' . urlencode($_GET['s']) . '/' : '/search/' . urlencode($_GET['s']) . '/';
                    $expires = strtotime('1 day'); // make search results expire faster
                }

                // add some javascript to buffer to change form posts to PRESSLESS_DOMAIN as needed, since S3 website can't respond to or redirect POST requests
                $cacheBuffer .= "<script>for (var i=0;i<document.getElementsByTagName('form').length;i++) {document.getElementsByTagName('form')[i].action = document.getElementsByTagName('form')[i].action.replace('http://".PRESSLESS_S3_WEBSITE_BUCKET."', 'https://".PRESSLESS_DOMAIN."');};</script>";
                $cacheBuffer .= "<script>try{wpcf7.apiSettings.root = 'https://".PRESSLESS_DOMAIN."/wp-json/';}catch(e){}</script>";
            }
        }
 
        if (!is_dir('s3://' . PRESSLESS_S3_LOGGING_BUCKET)) {
            debug('Creating s3://' . PRESSLESS_S3_LOGGING_BUCKET . ' logging bucket');
            try {
                $result = $s3Client->createBucket(['ACL' => 'private', 'Bucket' => PRESSLESS_S3_LOGGING_BUCKET]);
                $result = $s3Client->putBucketAcl([
                    'Bucket' => PRESSLESS_S3_LOGGING_BUCKET,
                    'GrantReadACP' => 'uri=http://acs.amazonaws.com/groups/s3/LogDelivery',
                    'GrantWrite' => 'uri=http://acs.amazonaws.com/groups/s3/LogDelivery'
                ]);
            } catch (Aws\S3\Exception\S3Exception $e) {
               debug('Error creating s3://' . PRESSLESS_S3_LOGGING_BUCKET . ' logging bucket: ' . $e->getMessage());
            }
        }

        if (!is_dir('s3://' . PRESSLESS_S3_WEBSITE_BUCKET)) {
            debug('Creating s3://' . PRESSLESS_S3_WEBSITE_BUCKET . ' bucket');
            try {
                $result = $s3Client->createBucket(['ACL' => 'public-read', 'Bucket' => PRESSLESS_S3_WEBSITE_BUCKET]);
                debug('Setting s3://' . PRESSLESS_S3_WEBSITE_BUCKET . ' website policy');
                $result = $s3Client->putBucketWebsite([
                    'Bucket' => PRESSLESS_S3_WEBSITE_BUCKET,
                    'IndexDocument' => [
                        'Suffix' => 'index.html'
                    ],
                    'RoutingRules' => [
                        [
                            'Condition' => [
                                'HttpErrorCodeReturnedEquals' => '404'
                            ],
                            'Redirect' => [
                                'HostName' => PRESSLESS_DOMAIN,
                                'HttpRedirectCode' => '307',
                                'Protocol' => 'https'
                            ]
                        ]
                    ]
                ]);
                debug('Setting s3://' . PRESSLESS_S3_WEBSITE_BUCKET . ' CORS policy');
                $result = $s3Client->putBucketCors([
                    'Bucket' => PRESSLESS_S3_WEBSITE_BUCKET,
                    'CORSRules' => [
                        [
                            'AllowedHeaders' => ['*'],
                            'AllowedMethods' => ['GET','POST'],
                            'AllowedOrigins' => ['*'],
                            'MaxAgeSeconds' => 3000,
                        ]
                    ],
                ]);    
                debug('Setting s3://' . PRESSLESS_S3_WEBSITE_BUCKET . ' lifecycle policy');
                $result = $s3Client->putBucketLifecycleConfiguration([
                    'Bucket' => PRESSLESS_S3_WEBSITE_BUCKET,
                    'Rules' => [
                        [
                            'Expiration' => [
                                'Days' => 365
                            ],
                            'Status' => 'Enabled'
                        ]
                    ]
                ]);
                debug('Setting s3://' . PRESSLESS_S3_WEBSITE_BUCKET . ' logging policy');
                $result = $s3Client->putBucketLogging([
                    'Bucket' => PRESSLESS_S3_WEBSITE_BUCKET,
                    'LoggingEnabled' => [
                        'TargetBucket' => PRESSLESS_S3_LOGGING_BUCKET
                    ]
                ]);
            } catch (Aws\S3\Exception\S3Exception $e) {
                debug('Error creating s3://' . PRESSLESS_S3_WEBSITE_BUCKET . ' bucket: ' . $e->getMessage());
            }
        }

        if (!is_dir('s3://' . PRESSLESS_S3_WEBSITE_ROOT_BUCKET)) {
            debug('Creating s3://' . PRESSLESS_S3_WEBSITE_ROOT_BUCKET . ' bucket');
            try {                
                // make sure we create bucket for root domain
                $result = $s3Client->createBucket(['ACL' => 'public-read', 'Bucket' => PRESSLESS_S3_WEBSITE_ROOT_BUCKET]);
                debug('Setting s3://' . PRESSLESS_S3_WEBSITE_ROOT_BUCKET . ' website policy');
                $result = $s3Client->putBucketWebsite([
                    'Bucket' => PRESSLESS_S3_WEBSITE_ROOT_BUCKET,
                    'RedirectAllRequestsTo' => [
                        'HostName' => PRESSLESS_S3_WEBSITE_BUCKET
                    ]
                ]);     
            } catch (Aws\S3\Exception\S3Exception $e) {
                debug('Error creating s3://' . PRESSLESS_S3_WEBSITE_ROOT_BUCKET . ' bucket: ' . $e->getMessage());
            }
        }

        // append 'index.html' for directories since we obviously can't store buffer as a directory name
        $s3Key = (strcmp(substr($event['path'], strlen($event['path']) - 1), '/') === 0) ? $event['path'] . 'index.html' : $event['path'];

        debug('Writing buffer to s3://' . PRESSLESS_S3_WEBSITE_BUCKET . $s3Key);
        $stream = fopen('s3://' . PRESSLESS_S3_WEBSITE_BUCKET . $s3Key, 'w', false, stream_context_create(['s3' => ['ACL' => 'public-read', 'Expires' => $expires]]));       
        $bytesWritten = fwrite($stream, $cacheBuffer);
        fclose($stream);
        if ($bytesWritten === false || strlen($cacheBuffer) != $bytesWritten) {
            debug('Failed writing buffer to s3://' . PRESSLESS_S3_WEBSITE_BUCKET . $s3Key . ".  Wrote $bytesWritten of " . strlen($cacheBuffer) . ' bytes.');
        } else if ($event['httpMethod'] == 'GET') {
            debug('Waiting until s3://' . PRESSLESS_S3_WEBSITE_BUCKET . $s3Key . ' exists...');
            sleep(1);
            while (!file_exists('s3://' . PRESSLESS_S3_WEBSITE_BUCKET . $s3Key)) {
                debug('Waiting until s3://' . PRESSLESS_S3_WEBSITE_BUCKET . $s3Key . ' exists...');
                sleep(1);
            }
            debug('Redirecting to http://' . PRESSLESS_S3_WEBSITE_BUCKET . $event['path']);
            return json_encode([
                'statusCode' => 307,
                'body' => '',
                'headers' => array('Location' => 'http://' . PRESSLESS_S3_WEBSITE_BUCKET . $event['path'])
            ]);
        }
    }

    return json_encode([
        'statusCode' => intval($_RESPONSE['statusCode']) ?: 200,
        'body' => $buffer,
        'headers' => !empty($_RESPONSE['headers']) ? $_RESPONSE['headers'] : array()
    ]);
}

debug('event: ' . $argv[1]);

try {
    $_RENDERABLE = true;
    ob_start('buffer');

    // serve static files
    debug('path is ' . $event['path']);
    $path_parts = pathinfo($event['path']);   
    if ($event['path'] != '/' && in_array($path_parts['extension'], array('html','htm','css','txt','csv','scss','json','xml','ico','js','gif','jpg','jpeg','png','pdf','otf','ttf','woff','eot','svg','zip'))) {
        $file = (strpos($event['path'], '/tmp/') === 0) ? $event['path'] : $wpDir . $event['path'];
        if (is_readable($file)) {
            debug('serving static file ' . $file); 
            $isBase64 = false;
            $fileType = mime_content_type($file);
            $fileContents = strpos($file, 'wp-content/uploads/') > 0 ? __alias__file_get_contents($file) : file_get_contents($file); // don't get file contents from s3 if serving uploaded file

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

            return render(200, array('Content-Type' => $fileType, 'X-Binary' => ($isBase64?'true':'false')), $fileContents);
        } else {
            debug('unable to read static file ' . $file); 
            return render(404);
        }
    }

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
        chdir($wpDir . $event['path']);
        $indexFile = strpos(strrev($event['path']), '/') === 0 ? 'index.php' : '/index.php';
        debug('specific non static directory requested, loading ' . $wpDir . $event['path'] . $indexFile);
        require_once $indexFile;
    } else if ($event['path'] != '/' && is_dir($wpDir . explode('/', $event['path'])[0])) {
        chdir($wpDir);
        $parts = explode('/', $event['path']);
        foreach ($parts as $i => $part) {
            if (is_dir(getcwd() . '/' . $part)) {
                chdir(getcwd() . '/' . $part);
                unset($parts[$i]);
            }
        }
        
        // custom crap :(
        if (file_exists('route.php')) {
            debug('specific non static directory with custom route requested, loading ' . getcwd() . '/route.php?uri=' . implode('/', $parts) . '/');
            $_GET['uri'] = implode('/', $parts) . '/';
            require_once 'route.php';
        } else {
            debug('specific non static directory with extra path requested, loading ' . getcwd() . '/index.php');
            require_once 'index.php'; 
        }
    } else {
        debug('full wordpress mode');
        chdir($wpDir);
        require_once 'index.php';
    }
} catch (Exception $e) {
    return render(500, array('Content-Type' => 'text/html'), $e->getMessage() . $e->getTraceAsString());
}