<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/access.php';
session_name(SESSION_NAME);session_start();header('Content-Type: application/json; charset=utf-8');
if(empty($_SESSION['user_id'])||!lmsStaff())lmsDeny();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
function importText($value,int $limit=2000): string {if(!is_string($value)||mb_strlen($value)>$limit)throw new InvalidArgumentException('Некорректное текстовое поле');return trim($value);}
try {
 $raw=file_get_contents('php://input');if(strlen($raw)>4000000)throw new InvalidArgumentException('Максимум 4 МБ');
 $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new InvalidArgumentException('Ожидается JSON');
 $pdo=Database::get();$pdo->beginTransaction();$count=0;
 if(($_GET['kind']??'')==='users'){
  $rows=$data['users']??[];if(!is_array($rows)||!$rows||count($rows)>500)throw new InvalidArgumentException('От 1 до 500 пользователей за импорт');
  foreach($rows as $row){$first=importText($row['first_name']??'',100);$last=importText($row['last_name']??'',100);$email=strtolower(importText($row['email']??'',190));$password=importText($row['password']??'',200);
   if(!$first||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<10)throw new InvalidArgumentException('Проверьте имя, email и пароль от 10 символов');
   if(Database::find('SELECT id FROM users WHERE email=?',[$email]))throw new InvalidArgumentException('Email уже существует: '.$email);
   Database::insert('users',['first_name'=>$first,'last_name'=>$last,'email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'dept_id'=>1,'role_id'=>1,'is_admin'=>0,'is_manager'=>0,'visible_skill_level_ids'=>'[]','coins'=>0]);$count++;
  }
 }else{
  $title=importText($data['title']??'',255);$minutes=filter_var($data['duration_minutes']??60,FILTER_VALIDATE_INT);
  $tests=$data['tests']??[];if(!$title||$minutes===false||$minutes<1||$minutes>1440||!is_array($tests)||!$tests||count($tests)>30)throw new InvalidArgumentException('Укажите название, время 1–1440 минут и от 1 до 30 тестов/заданий');
  $slug='course_'.bin2hex(random_bytes(8));$levelId=Database::insert('skill_levels',['title'=>$title,'slug'=>$slug,'description'=>importText($data['description']??'',10000),'duration_minutes'=>$minutes,'questions_count'=>10,'assignments_count'=>1,'coins_required'=>0,'is_active'=>1,'sort'=>1]);
  foreach($tests as $test){$type=$test['content_type']??'quiz';$name=importText($test['title']??'',255);$quota=filter_var($test['questions_to_issue']??10,FILTER_VALIDATE_INT);
   if(!$name||!in_array($type,['quiz','task'],true)||$quota===false||$quota<1||$quota>1000)throw new InvalidArgumentException('Проверьте название и тип теста/задания');
   $testId=Database::insert('tests',['title'=>$name,'description'=>importText($test['description']??'',100000),'content_type'=>$type,'dept_id'=>1,'role_id'=>1,'skill_level_id'=>$levelId,'coins_reward'=>0,'is_active'=>1,'questions_to_issue'=>$quota]);
   $questions=$test['questions']??[];if(!is_array($questions)||count($questions)>300||($type==='quiz'&&!$questions))throw new InvalidArgumentException('В тесте должно быть 1–300 вопросов');
   foreach($questions as $i=>$q){$text=importText($q['question_text']??'',10000);$qt=$q['type']??'single';$opts=$q['options']??[];
    if(!$text||!in_array($qt,['single','multi','open'],true)||!is_array($opts))throw new InvalidArgumentException('Проверьте текст и тип вопроса');
    $correct=0;foreach($opts as $opt){if(!importText($opt['option_text']??'',5000))throw new InvalidArgumentException('Пустой вариант');if(!in_array($opt['is_correct']??0,[0,1],true))throw new InvalidArgumentException('is_correct: 0 или 1');$correct+=(int)($opt['is_correct']??0);}
    if($qt!=='open'&&(count($opts)<2||count($opts)>20||($qt==='single'&&$correct!==1)||($qt==='multi'&&$correct<1)))throw new InvalidArgumentException('Отметьте правильные ответы');
    $qid=Database::insert('questions',['test_id'=>$testId,'sort'=>$i+1,'type'=>$qt,'question_text'=>$text,'allow_own_answer'=>!empty($q['allow_own_answer'])?1:0]);
    if($qt!=='open')foreach($opts as $opt)Database::insert('question_options',['question_id'=>$qid,'option_text'=>importText($opt['option_text'],5000),'is_correct'=>(int)($opt['is_correct']??0)]);
   }$count++;
  }
 }
 $pdo->commit();echo json_encode(['ok'=>true,'count'=>$count],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){Database::rollbackActive();http_response_code($e instanceof InvalidArgumentException||$e instanceof JsonException?400:500);error_log($e->getMessage());echo json_encode(['ok'=>false,'error'=>$e instanceof InvalidArgumentException?$e->getMessage():'Импорт не выполнен. Проверьте формат файла.'],JSON_UNESCAPED_UNICODE);}
