<?php


$__version__  = '3.1.2';
$__hostsdeny__ = array(); // $__hostsdeny__ = array('.youtube.com', '.youku.com');
$__content_type__ = 'image/gif';
$__timeout__ = 20;
$__content__ = '';


function message_html($title, $banner, $detail) {
    $error = <<<MESSAGE_STRING
<html><head>
<meta http-equiv="content-type" content="text/html;charset=utf-8">
<title>${title}</title>
<style><!--
body {font-family: arial,sans-serif}
div.nav {margin-top: 1ex}
div.nav A {font-size: 10pt; font-family: arial,sans-serif}
span.nav {font-size: 10pt; font-family: arial,sans-serif; font-weight: bold}
div.nav A,span.big {font-size: 12pt; color: #0000cc}
div.nav A {font-size: 10pt; color: black}
A.l:link {color: #6f6f6f}
A.u:link {color: green}
//--></style>

</head>
<body text=#000000 bgcolor=#ffffff>
<table border=0 cellpadding=2 cellspacing=0 width=100%>
<tr><td bgcolor=#3366cc><font face=arial,sans-serif color=#ffffff><b>Error</b></td></tr>
<tr><td>&nbsp;</td></tr></table>
<blockquote>
<H1>${banner}</H1>
${detail}

<p>
</blockquote>
<table width=100% cellpadding=0 cellspacing=0><tr><td bgcolor=#3366cc><img alt="" width=1 height=4></td></tr></table>
</body></html>
MESSAGE_STRING;
    return $error;
}


function decode_request($data) {
    list($headers_length) = array_values(unpack('n', substr($data, 0, 2)));
    $headers_data = substr($data, 2, $headers_length);

    list($body_length) = array_values(unpack('N', substr($data, 2+intval($headers_length), 4)));
    $body = substr($data, 2+intval($headers_length)+4, $body_length);

    $lines = explode("\r\n", $headers_data);

    $request_line_items = explode(" ", array_shift($lines));
    $method = $request_line_items[0];
    $url = $request_line_items[1];

    $headers = array();

    foreach ($lines as $line) {
        if (!$line)
            continue;
        $pair = explode(':', $line, 2);
        $key  = $pair[0];
        $value = trim($pair[1]);
        if ($key) {
            $key = join('-', array_map('ucfirst', explode('-', $key)));
            $headers[$key] = $value;
        }
    }
    return array($method, $url, $headers, $body);
}


function echo_content($content) {
    global $__password__, $__content_type__;
    if ($__content_type__ == 'image/gif') {
        echo $content ^ str_repeat($__password__[0], strlen($content));
    } else {
        echo $content;
    }
}


function curl_header_function($ch, $header) {
    global $__content__, $__content_type__;
    error_log("header:".$header);

    $pos = strpos($header, ':');
    if ($pos == false) {
        $__content__ .= $header;
        error_log("header none");
    } else {
        $key = join('-', array_map('ucfirst', explode('-', substr($header, 0, $pos))));
        $__content__ .= $key . substr($header, $pos);
        error_log("header content:".$__content__);
    }
    return strlen($header);
}


function curl_write_function($ch, $content) {
    error_log("content:".$content);
    global $__content__;
    if ($__content__) {
        error_log("echo content:".$__content__);
        // for debug
        // echo_content("HTTP/1.0 200 OK\r\nContent-Type: text/plain\r\n\r\n");
        echo_content($__content__);
        $__content__ = '';
    }
    echo_content($content);
    return strlen($content);
}


function post() {
    list($method, $url, $headers, $body) = @decode_request(@file_get_contents('php://input'));
    error_log("method:".$method." url:".$url." body len:".strlen($body));
    if ($body) {
        $headers['Content-Length'] = strval(strlen($body));
    }

    $header_array = array();
    foreach ($headers as $key => $value) {
        $header_array[] = join('-', array_map('ucfirst', explode('-', $key))).': '.$value;
    }

    $timeout = $GLOBALS['__timeout__'];

    $curl_opt = array();

    switch (strtoupper($method)) {
        case 'HEAD':
            $curl_opt[CURLOPT_NOBODY] = true;
            break;
        case 'GET':
            break;
        case 'POST':
            $curl_opt[CURLOPT_POST] = true;
            $curl_opt[CURLOPT_POSTFIELDS] = $body;
            break;
        case 'PUT':
        case 'DELETE':
            $curl_opt[CURLOPT_CUSTOMREQUEST] = $method;
            $curl_opt[CURLOPT_POSTFIELDS] = $body;
            break;
        default:
            echo_content("HTTP/1.0 502\r\n\r\n" . message_html('502 Urlfetch Error', 'Invalid Method: ' . $method,  $url));
            exit(-1);
    }

    $curl_opt[CURLOPT_HTTPHEADER] = $header_array;
    $curl_opt[CURLOPT_RETURNTRANSFER] = true;
    $curl_opt[CURLOPT_BINARYTRANSFER] = true;

    $curl_opt[CURLOPT_HEADER]         = false;
    $curl_opt[CURLOPT_HEADERFUNCTION] = 'curl_header_function';
    $curl_opt[CURLOPT_WRITEFUNCTION]  = 'curl_write_function';

    $curl_opt[CURLOPT_FAILONERROR]    = false;
    $curl_opt[CURLOPT_FOLLOWLOCATION] = false;

    $curl_opt[CURLOPT_CONNECTTIMEOUT] = $timeout;
    $curl_opt[CURLOPT_TIMEOUT]        = $timeout;

    $curl_opt[CURLOPT_SSL_VERIFYPEER] = false;
    $curl_opt[CURLOPT_SSL_VERIFYHOST] = false;

    $ch = curl_init($url);
    curl_setopt_array($ch, $curl_opt);
    $ret = curl_exec($ch);
    $errno = curl_errno($ch);
    if ($GLOBALS['__content__']) {
        echo_content($GLOBALS['__content__']);
    } else if ($errno) {
        error_log("curl errorno:".$errorno);
        if (!headers_sent()) {
            header('Content-Type: ' . $__content_type__);
        }
        $content = "HTTP/1.0 502\r\n\r\n" . message_html('502 Urlfetch Error', "PHP Urlfetch Error curl($errno)",  curl_error($ch));
        echo_content($content);
    }
    curl_close($ch);
}

function get() {
    echo "OK.";
}


function main() {
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        post();
    } else {
        get();
    }
}

main();
