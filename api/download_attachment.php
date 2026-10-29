<?php
require_once __DIR__.'/../config.php';
session_name(SESSION_NAME);
session_start();
if (empty($_SESSION['user_id'])) {http_response_code(401);exit;}
session_write_close();
$name=(string)($_GET['file'] ?? '');
$root=realpath(__DIR__.'/../storage/uploads');
$path=$root ? realpath($root.'/'.$name) : false;
if (!$root || !$path || $name!==basename($name) || dirname($path)!==$root || !is_file($path) || strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='bin') {
    http_response_code(404);exit;
}
$original=str_replace(["\r","\n",'/',"\\"], '_', (string)($_GET['name'] ?? 'attachment.bin'));
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header("Content-Disposition: attachment; filename=\"attachment.bin\"; filename*=UTF-8''".rawurlencode($original));
header('Content-Length: '.filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
