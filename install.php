<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/db/Database.php';
session_name(SESSION_NAME);session_start();
$error='';$ready=false;
try {
 if(Database::find('SELECT id FROM users LIMIT 1')) {http_response_code(403);exit('Платформа уже установлена. Вход: /');}
 $_SESSION['install_csrf']??=bin2hex(random_bytes(32));
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['install_csrf'],$_POST['csrf']??''))throw new RuntimeException('Обновите страницу');
  $expected=getenv('LMS_INSTALL_KEY');
  if(!$expected || !hash_equals($expected,$_POST['key']??''))throw new RuntimeException('Укажите ключ LMS_INSTALL_KEY, установленный на сервере');
  $email=strtolower(trim($_POST['email']??''));$password=$_POST['password']??'';$name=trim($_POST['name']??'');
  if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<10)throw new RuntimeException('Укажите имя, корректный email и пароль от 10 символов');
  $pdo=Database::get();$pdo->beginTransaction();
  // The shared compatibility group serializes concurrent initial installations.
  Database::find('SELECT id FROM departments WHERE id=1 FOR UPDATE');
  if(Database::find('SELECT id FROM users LIMIT 1'))throw new RuntimeException('Платформа уже установлена');
  Database::insert('users',['email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'first_name'=>$name,'last_name'=>'','dept_id'=>1,'role_id'=>1,'is_admin'=>1,'is_manager'=>0,'coins'=>0]);
  $pdo->commit();$ready=true;
 }
} catch(Throwable $e){Database::rollbackActive();$error=$e instanceof PDOException?'Сначала настройте config.local.php и импортируйте db/schema.sql':$e->getMessage();}
?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Установка LMS</title><style>body{font:16px/1.6 system-ui;background:#f5f6fa;color:#202536}main{max-width:440px;margin:8vh auto;background:white;padding:35px;border-radius:20px}input,button{font:inherit;padding:12px;box-sizing:border-box;width:100%;margin-bottom:15px;border:1px solid #dde0eb;border-radius:8px}button{background:#635bff;color:white}label{display:block;font-size:13px}.error{color:#a22}</style><main><h1>Ваша платформа обучения</h1><?php if($ready): ?><p>Администратор создан.</p><a href="/admin/">Перейти в админку →</a><?php else: ?><p>Создайте первого администратора.</p><p class="error"><?= htmlspecialchars($error) ?></p><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['install_csrf']??'')?>"><label>Ключ установки</label><input name="key" type="password" required><label>Имя</label><input name="name" required><label>Email</label><input name="email" type="email" required><label>Пароль · от 10 символов</label><input name="password" type="password" minlength="10" required><button>Создать администратора</button></form><?php endif ?></main></html>
