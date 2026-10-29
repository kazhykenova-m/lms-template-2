<?php
require_once __DIR__.'/api_errors.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/access.php';
session_name(SESSION_NAME);
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) { http_response_code(401); exit; }
ensureLmsSchema();
if (!lmsStaff()) lmsDeny();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$table = ['level'=>'skill_level_results','test'=>'test_results','unused'=>'lesson_test_results'][$body['kind'] ?? ''] ?? '';
if (!$table) { http_response_code(400); exit; }
$id = (int)($body['id'] ?? 0);
$pdo = Database::get();
$pdo->beginTransaction();
$result = Database::find("SELECT * FROM `$table` WHERE id=? FOR UPDATE", [$id]);
if (!$result) { $pdo->rollBack(); http_response_code(404); echo json_encode(['ok'=>false]); exit; }
lmsRequireEmployee((int)$result['user_id']);
if (empty(lmsActor()['is_admin'])) {
    if ($table === 'skill_level_results') lmsRequireDepartment($result['dept_id']);
    elseif ($table === 'lesson_test_results') {
        $lesson = Database::find('SELECT dept_id FROM lessons WHERE id=?', [$result['lesson_id']]);
        if (!empty($lesson['dept_id'])) lmsRequireDepartment($lesson['dept_id']);
    } else {
        $test = Database::find('SELECT dept_id FROM tests WHERE id=?', [$result['test_id']]);
        if (!empty($test['dept_id'])) lmsRequireDepartment($test['dept_id']);
    }
}

$answers = json_decode($result['answers_json'], true) ?: [];
$qid = (string)(int)($body['question_id'] ?? 0);
if ($table==='skill_level_results' && !isset($body['question_id'])) {
    if(!is_bool($body['accepted'] ?? null)) {$pdo->rollBack();http_response_code(400);exit;}
    $answers['verdict']=['type'=>'level','accepted'=>$body['accepted'],'reviewed_by'=>(int)$_SESSION['user_id'],'reviewed_at'=>date('Y-m-d H:i:s')];
    Database::update($table,['passed'=>$body['accepted']?1:0,'review_status'=>'reviewed','answers_json'=>json_encode($answers,JSON_UNESCAPED_UNICODE)],'id=?',[$id]);
    $pdo->commit();echo json_encode(['ok'=>true,'passed'=>$body['accepted'],'review_status'=>'reviewed']);exit;
}

if (($answers['verdict']['type'] ?? '')==='level') unset($answers['verdict']);
$isTask=false;
if ($table==='test_results') {
    $test=Database::find('SELECT * FROM tests WHERE id=?',[$result['test_id']]);
    $isTask=lmsResultContentType($test ?: [],$answers)==='task';
}
if (!is_bool($body['accepted'] ?? null) || (!$isTask && ($answers[$qid]['type'] ?? '') !== 'open')) {
    $pdo->rollBack(); http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Некорректный ответ']); exit;
}
$key=$isTask ? 'verdict' : $qid;
if ($isTask) $answers[$key]=['type'=>'task'];
$answers[$key]['accepted']=$body['accepted'];
$answers[$key]['reviewed_by']=(int)$_SESSION['user_id'];
$answers[$key]['reviewed_at']=date('Y-m-d H:i:s');
$pending=false; $score=0; $max=0;
foreach ($answers as $answer) {
    if (!is_array($answer) || !isset($answer['type'])) continue;
    $max++;
    if (in_array($answer['type'],['open','task'],true)) {
        if (!array_key_exists('accepted',$answer)) $pending=true;
        elseif ($answer['accepted']) $score++;
    } elseif (!empty($answer['is_correct'])) $score++;
}
if ($isTask) {$pending=false;$score=$body['accepted']?1:0;$max=1;}
$data=['answers_json'=>json_encode($answers,JSON_UNESCAPED_UNICODE),'review_status'=>$pending?'pending':'reviewed'];
if (!$pending) {
    $data['score']=$score; $data['max_score']=$max;
    $data['passed']=($max>0 && ((defined('TEST_REQUIRE_100') ? TEST_REQUIRE_100 : true) ? $score===$max : $score>0)) ? 1 : 0;
}
Database::update($table, $data, 'id=?', [$id]);
if (!$pending && $table !== 'skill_level_results') {
    $passed = !empty($data['passed']);
    if ($table === 'test_results') {
        $test = Database::find('SELECT * FROM tests WHERE id=?', [$result['test_id']]);

    }
    Database::update($table, ['passed'=>$passed?1:0], 'id=?', [$id]);
}

$pdo->commit();
echo json_encode(['ok'=>true,'review_status'=>$data['review_status'],'passed'=>$data['passed'] ?? 0,'score'=>$score,'max_score'=>$max], JSON_UNESCAPED_UNICODE);
