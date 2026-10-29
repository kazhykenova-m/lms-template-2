<?php
require_once __DIR__.'/../config.php';
session_name(SESSION_NAME);session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) {http_response_code(401);echo json_encode(['ok'=>false]);exit;}
if ($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}
echo json_encode(['ok'=>true,'departments'=>[['id'=>1,'slug'=>'workspace','name'=>'Общее пространство','roles'=>[['id'=>1,'name'=>'Пользователь']]]]],JSON_UNESCAPED_UNICODE);
