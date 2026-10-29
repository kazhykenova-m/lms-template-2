<?php
require __DIR__.'/core.php';
try {
 $action=$_GET['action']??'state';$write=$_SERVER['REQUEST_METHOD']==='POST';
 if($write){if(!hash_equals($_SESSION['csrf']??'',$_SERVER['HTTP_X_CSRF_TOKEN']??'') || empty($_SESSION['csrf']))fail('Обновите страницу и повторите действие',403);$raw=file_get_contents('php://input');if(strlen($raw)>3000000)fail('Слишком большой запрос');$b=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($b))fail('Ожидается JSON объект');}
 if($action==='state'){
  $_SESSION['csrf']??=bin2hex(random_bytes(32));$u=one('SELECT id,name,email,role,admin FROM users WHERE id=?',[$_SESSION['uid']??0]);$settings=one('SELECT * FROM settings');$setup=!one('SELECT id FROM users LIMIT 1');$data=['ok'=>true,'csrf'=>$_SESSION['csrf'],'setup'=>$setup,'user'=>$u,'brand'=>$settings];
  if($u){expireAttempts();$data['courses']=all('SELECT id,title,description,minutes,pass_score FROM courses ORDER BY id DESC');$data['assignments']=$u['admin']?all('SELECT a.*,u.name,u.role,c.title,c.pass_score FROM assignments a JOIN users u ON u.id=a.user_id JOIN courses c ON c.id=a.course_id ORDER BY a.id DESC'):all('SELECT a.id,a.course_id,a.status,a.published,CASE WHEN a.published=1 THEN a.score ELSE NULL END AS score,c.title,c.description,c.minutes,c.pass_score FROM assignments a JOIN courses c ON c.id=a.course_id WHERE a.user_id=? ORDER BY a.id DESC',[$u['id']]);if($u['admin'])$data['users']=all('SELECT id,name,email,role,admin FROM users ORDER BY name');}out($data);
 }
 if(!$write && $action!=='attempt')fail('Только POST',405);
 if($action==='setup'){$db->beginTransaction();if(one('SELECT id FROM users LIMIT 1'))fail('Платформа уже установлена',409);$b['admin']=true;addUser($b);$_SESSION['uid']=(int)$db->lastInsertId();$db->commit();session_regenerate_id(true);out(['ok'=>true]);}
 if($action==='login'){$_SESSION['login_tries']=($_SESSION['login_tries']??0)+1;if($_SESSION['login_tries']>10 && time()-($_SESSION['last_login']??0)<60)fail('Повторите вход через минуту',429);$_SESSION['last_login']=time();$u=one('SELECT * FROM users WHERE email=?',[textValue($b['email']??'',200)]);if(!$u||!password_verify($b['password']??'',$u['password']))fail('Неверный email или пароль',401);session_regenerate_id(true);$_SESSION['uid']=$u['id'];$_SESSION['login_tries']=0;out(['ok'=>true]);}
 $u=actor();
 if($action==='logout'){$_SESSION=[];session_regenerate_id(true);out(['ok'=>true]);}
 if($action==='brand'){staff();$name=textValue($b['name']??'',80);$color=$b['color']??'';$logo=$b['logo']??'';if(!$name||!is_string($color)||!preg_match('/^#[0-9a-f]{6}$/i',$color)||!is_string($logo)||strlen($logo)>700000)fail('Проверьте название, цвет и логотип');if($logo!==''&&!preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#D',$logo))fail('Логотип должен быть PNG, JPEG или WebP');query('UPDATE settings SET name=?,color=?,logo=? WHERE id=1',[$name,$color,$logo]);out(['ok'=>true]);}
 if($action==='user'){staff();addUser($b);out(['ok'=>true]);}
 if($action==='import_users'){staff();$rows=$b['users']??[];if(!is_array($rows)||!count($rows)||count($rows)>500)fail('Импортируйте от 1 до 500 сотрудников');$db->beginTransaction();foreach($rows as $row){if(!is_array($row))fail('Некорректная строка');$row['admin']=false;addUser($row);}$db->commit();out(['ok'=>true]);}
 if($action==='course'){staff();$values=courseData($b);query('INSERT INTO courses(title,description,material,questions,pass_score,minutes) VALUES(?,?,?,?,?,?)',$values);out(['ok'=>true]);}
 if($action==='assign'){staff();$course=(int)($b['course_id']??0);if(!one('SELECT id FROM courses WHERE id=?',[$course]))fail('Выберите обучение');$ids=$b['user_ids']??[];if(!is_array($ids)||!$ids)fail('Выберите сотрудников');$db->beginTransaction();foreach(array_unique(array_map('intval',$ids)) as $id){if(!one('SELECT id FROM users WHERE id=?',[$id]))fail('Сотрудник не найден');query('INSERT OR IGNORE INTO assignments(user_id,course_id) VALUES(?,?)',[$id,$course]);}$db->commit();out(['ok'=>true]);}
 if($action==='publish'){staff();$id=(int)($b['id']??0);$a=one('SELECT * FROM assignments WHERE id=?',[$id]);if(!$a||!in_array($a['status'],['completed','expired'],true))fail('Сначала сотрудник должен завершить попытку');query('UPDATE assignments SET published=1 WHERE id=?',[$id]);out(['ok'=>true]);}
 if(in_array($action,['attempt','start','submit'],true)){
  $id=(int)($_GET['id']??$b['id']??0);$db->beginTransaction();expireAttempts();$a=one('SELECT a.*,c.title,c.material,c.questions,c.minutes FROM assignments a JOIN courses c ON c.id=a.course_id WHERE a.id=? AND a.user_id=?',[$id,$u['id']]);if(!$a)fail('Назначение не найдено',404);
  if($action==='start' && $a['status']==='assigned'){query("UPDATE assignments SET status='active',started_at=? WHERE id=?",[time(),$id]);$a['status']='active';$a['started_at']=time();}
  if(!in_array($a['status'],['assigned','active'],true))fail('Попытка завершена. Повторное прохождение недоступно',409);
  $questions=json_decode($a['questions'],true);
  if($action==='submit'){
   if($a['status']!=='active')fail('Сначала начните тест');$answers=$b['answers']??[];if(!is_array($answers)||count($answers)!==count($questions))fail('Ответьте на все вопросы');$correct=0;foreach($questions as $i=>$q){$answer=$answers[$i]??null;if(!is_int($answer)||!isset($q['options'][$answer]))fail('Ответьте на все вопросы');if($answer===$q['correct'])$correct++;}$score=(int)round(100*$correct/count($questions));query("UPDATE assignments SET status='completed',finished_at=?,answers=?,score=? WHERE id=?",[time(),json_encode($answers),$score,$id]);$db->commit();out(['ok'=>true]);
  }
  $db->commit();foreach($questions as &$q)unset($q['correct']);unset($q);out(['ok'=>true,'id'=>$id,'title'=>$a['title'],'material'=>$a['material'],'status'=>$a['status'],'questions'=>$a['status']==='active'?$questions:[],'remaining'=>$a['status']==='active'?max(0,$a['started_at']+$a['minutes']*60-time()):null]);
 }
 fail('Действие не найдено',404);
} catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log($e->getMessage());out(['ok'=>false,'error'=>'Не удалось выполнить действие. Проверьте данные и повторите.'],500);}
