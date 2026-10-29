<?php
declare(strict_types=1);
ini_set('session.use_strict_mode','1');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off']);
session_start();
$db=new PDO('sqlite:'.(__DIR__.'/storage/platform.sqlite'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
$db->exec("CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY CHECK(id=1), name TEXT NOT NULL, color TEXT NOT NULL, logo TEXT NOT NULL DEFAULT '');
INSERT OR IGNORE INTO settings VALUES(1,'Forma LMS','#635bff','');
CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE COLLATE NOCASE, password TEXT NOT NULL, role TEXT NOT NULL, admin INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS courses (id INTEGER PRIMARY KEY, title TEXT NOT NULL, description TEXT NOT NULL, material TEXT NOT NULL, questions TEXT NOT NULL, pass_score INTEGER NOT NULL, minutes INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS assignments (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id), course_id INTEGER NOT NULL REFERENCES courses(id), status TEXT NOT NULL DEFAULT 'assigned', started_at INTEGER, finished_at INTEGER, answers TEXT, score INTEGER, published INTEGER NOT NULL DEFAULT 0, UNIQUE(user_id,course_id));");
function query(string $sql,array $args=[]): PDOStatement {global $db;$s=$db->prepare($sql);$s->execute($args);return $s;}
function one(string $sql,array $args=[]): ?array {return query($sql,$args)->fetch() ?: null;}
function all(string $sql,array $args=[]): array {return query($sql,$args)->fetchAll();}
function out(array $data,int $status=200): never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function fail(string $message,int $status=400): never {out(['ok'=>false,'error'=>$message],$status);}
function actor(): array {$u=one('SELECT id,name,email,role,admin FROM users WHERE id=?',[$_SESSION['uid']??0]);if(!$u)fail('Войдите в аккаунт',401);return $u;}
function staff(): array {$u=actor();if(!$u['admin'])fail('Недостаточно прав',403);return $u;}
function textValue(mixed $value,int $limit=10000): string {if(!is_string($value)||mb_strlen($value)>$limit)fail('Некорректный текст или превышена допустимая длина');return trim($value);}
function addUser(array $b): void {
 $name=textValue($b['name']??'',120);$email=strtolower(textValue($b['email']??'',200));$role=textValue($b['role']??'Сотрудник',80);$password=$b['password']??'';
 if(!$name||!$role||!filter_var($email,FILTER_VALIDATE_EMAIL)||!is_string($password)||strlen($password)<10||strlen($password)>200)fail('Укажите имя, роль, корректный email и пароль от 10 символов');
 if(one('SELECT id FROM users WHERE email=?',[$email]))fail('Email уже используется: '.$email);
 query('INSERT INTO users(name,email,password,role,admin) VALUES(?,?,?,?,?)',[$name,$email,password_hash($password,PASSWORD_DEFAULT),$role,!empty($b['admin'])?1:0]);
}
function courseData(array $b): array {
 $title=textValue($b['title']??'',180);$description=textValue($b['description']??'',2000);$material=textValue($b['material']??'',100000);$qs=$b['questions']??[];
 $pass=filter_var($b['pass_score']??80,FILTER_VALIDATE_INT);$minutes=filter_var($b['minutes']??30,FILTER_VALIDATE_INT);
 if(!$title||!is_array($qs)||!count($qs)||count($qs)>200||$pass===false||$pass<0||$pass>100||$minutes===false||$minutes<1||$minutes>1440)fail('Укажите название, 1–200 вопросов, порог 0–100% и время 1–1440 минут');
 $clean=[];foreach($qs as $q){if(!is_array($q))fail('Некорректный вопрос');$t=textValue($q['text']??'',3000);$options=$q['options']??[];$correct=$q['correct']??null;if(!$t||!is_array($options)||count($options)<2||count($options)>8||!is_int($correct)||!array_key_exists($correct,$options))fail('У вопроса должно быть 2–8 вариантов и индекс правильного ответа');$opts=[];foreach($options as $o){$o=textValue($o,1000);if(!$o)fail('Пустой вариант ответа');$opts[]=$o;}$clean[]=['text'=>$t,'options'=>$opts,'correct'=>$correct];}
 return [$title,$description,$material,json_encode($clean,JSON_UNESCAPED_UNICODE),$pass,$minutes];
}
function expireAttempts(): void {query("UPDATE assignments SET status='expired',finished_at=started_at+(SELECT minutes*60 FROM courses WHERE id=course_id),score=0,answers='[]' WHERE status='active' AND started_at+(SELECT minutes*60 FROM courses WHERE id=course_id)<=CAST(? AS INTEGER)",[time()]);}
