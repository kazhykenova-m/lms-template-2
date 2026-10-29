<?php
// Router for PHP's development server; do not expose storage or source files.
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/');
if ($path==='/' || $path==='/index.php') {require __DIR__.'/index.php';return true;}
if ($path==='/api.php') {require __DIR__.'/api.php';return true;}
if (in_array($path,['/assets/app.js','/assets/style.css'],true)) return false;
http_response_code(404);echo 'Not found';return true;
