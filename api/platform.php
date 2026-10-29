<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/access.php';
session_name(SESSION_NAME);session_start();
header('Content-Type: application/json; charset=utf-8');
$defaults=['name'=>'Forma LMS','tagline'=>'Знания, которые помогают расти','welcome'=>'Обучение, практика и достижения вашей команды в одном пространстве.','color'=>'#635bff','background'=>'#f5f6fa','logo'=>'','support_email'=>'','tests_title'=>'Мои обучения','results_title'=>'Мои результаты'];
try {
 $stored=Database::find('SELECT settings_json FROM platform_settings WHERE id=1');
 $settings=array_merge($defaults,json_decode($stored['settings_json']??'{}',true) ?: []);
 if ($_SERVER['REQUEST_METHOD']==='GET') {echo json_encode(['ok'=>true,'settings'=>$settings],JSON_UNESCAPED_UNICODE);exit;}
 if ($_SERVER['REQUEST_METHOD']!=='POST'||empty(lmsActor()['is_admin'])) lmsDeny();
 $body=json_decode(file_get_contents('php://input'),true) ?: [];
 foreach($defaults as $key=>$default) {
  if(!array_key_exists($key,$body))continue;
  if(!is_string($body[$key]))throw new InvalidArgumentException('Некорректное поле');
  $v=trim($body[$key]);$limit=$key==='logo'?700000:($key==='welcome'?2000:150);
  if(strlen($v)>$limit)throw new InvalidArgumentException('Слишком длинное значение');
  if(in_array($key,['color','background'],true)&&!preg_match('/^#[0-9a-f]{6}$/i',$v))throw new InvalidArgumentException('Цвет должен быть в формате #RRGGBB');
  if($key==='logo' && $v!=='' && !preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#D',$v))throw new InvalidArgumentException('Логотип: PNG, JPEG или WebP');
  if($key==='support_email' && $v!=='' && !filter_var($v,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Некорректный email поддержки');
  if($key==='name' && $v==='')throw new InvalidArgumentException('Укажите название');
  $settings[$key]=$v;
 }
 Database::query('UPDATE platform_settings SET settings_json=? WHERE id=1',[json_encode($settings,JSON_UNESCAPED_UNICODE)]);
 echo json_encode(['ok'=>true,'settings'=>$settings],JSON_UNESCAPED_UNICODE);
} catch(InvalidArgumentException $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){http_response_code(503);error_log($e->getMessage());echo json_encode(['ok'=>false,'error'=>'Платформа не настроена. Импортируйте db/schema.sql и проверьте config.local.php'],JSON_UNESCAPED_UNICODE);}
