<?php
// Router for PHP's built-in server: serve existing files directly, everything else through Craft.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = '/app/web' . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = '/app/web/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require '/app/web/index.php';
