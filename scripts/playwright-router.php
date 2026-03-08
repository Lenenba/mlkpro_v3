<?php

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$publicPath = __DIR__.'/../public';
$filePath = $publicPath.$uri;

if ($uri !== '/' && file_exists($filePath) && ! is_dir($filePath)) {
    return false;
}

require $publicPath.'/index.php';
