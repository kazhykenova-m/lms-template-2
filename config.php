<?php
// Each customer supplies their own configuration; no production credentials included.
$local=__DIR__.'/config.local.php';
if (is_file($local)) require $local;
foreach (['DB_HOST'=>'localhost','DB_NAME'=>'lms_template','DB_USER'=>'','DB_PASS'=>'','DB_CHARSET'=>'utf8mb4','SITE_NAME'=>'Forma LMS','SESSION_NAME'=>'forma_lms_session','MAIL_FROM_EMAIL'=>'','MAIL_FROM_NAME'=>'Forma LMS','MANAGER_NOTIFY_EMAIL'=>'','MERCH_API_URL'=>'','MERCH_API_TOKEN'=>'','MERCH_LMS_KEY'=>''] as $key=>$default) {
 if (!defined($key)) define($key,getenv($key)!==false?getenv($key):$default);
}
ini_set('display_errors','0');
error_reporting(E_ALL);
date_default_timezone_set(getenv('LMS_TIMEZONE') ?: 'Asia/Almaty');
if (session_status()===PHP_SESSION_NONE) session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off']);
