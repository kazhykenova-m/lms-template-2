<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/hard_skill_attempts.php';
session_name(SESSION_NAME);
session_start();
header('Content-Type: application/json; charset=utf-8');
function reply(array $data, int $status=200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
if (empty($_SESSION['user_id']) || !lmsStaff()) lmsDeny();
if ($_SERVER['REQUEST_METHOD']!=='POST') reply(['ok'=>false,'error'=>'Только POST'],405);
ensureLmsSchema();
$body=json_decode(file_get_contents('php://input'),true) ?: [];
$userId=(int)($body['user_id'] ?? 0);
$attemptId=(int)($body['attempt_id'] ?? 0);
$testId=(int)($body['test_id'] ?? 0);
$minutes=filter_var($body['minutes'] ?? 60,FILTER_VALIDATE_INT);
if ($minutes===false || $minutes<1 || $minutes>10080) reply(['ok'=>false,'error'=>'Укажите время от 1 до 10080 минут'],400);
lmsRequireEmployee($userId);
ensureTestResultsPassedColumn();
freezeExpiredAssignmentDrafts($userId);
$pdo=Database::get();
try {
    $pdo->beginTransaction();
    $employee=Database::find('SELECT * FROM users WHERE id=? FOR UPDATE',[$userId]);
    $attempt=Database::find('SELECT * FROM skill_level_attempts WHERE id=? AND user_id=? FOR UPDATE',[$attemptId,$userId]);
    $test=Database::find('SELECT * FROM tests WHERE id=?',[$testId]);
    $latest=$attempt ? Database::find('SELECT id FROM skill_level_attempts WHERE user_id=? AND skill_level_id=? AND is_current=1 ORDER BY id DESC LIMIT 1 FOR UPDATE',[$userId,$attempt['skill_level_id']]) : null;
    $eligible=$attempt && $test && $latest && (int)$latest['id']===$attemptId
        && (int)$attempt['is_current']===1 && strtotime($attempt['expires_at'])<=time()
        && (int)$attempt['dept_id']===(int)$employee['dept_id'] && (int)$attempt['role_id']===(int)$employee['role_id']
        && $test['content_type']==='task' && !empty($test['is_active'])
        && (int)$test['skill_level_id']===(int)$attempt['skill_level_id']
        && in_array($testId,array_map('intval',json_decode($attempt['assignment_ids'],true) ?: []),true);
    if (!$eligible) {
        $pdo->rollBack();reply(['ok'=>false,'error'=>'Можно открыть только ТЗ из последней истёкшей попытки сотрудника'],409);
    }
    if (Database::find('SELECT id FROM test_results WHERE user_id=? AND test_id=? AND is_current=1 LIMIT 1',[$userId,$testId])) {
        $pdo->rollBack();reply(['ok'=>false,'error'=>'Ответ на это ТЗ уже сохранён. Он доступен для проверки'],409);
    }
    // Preserve the old attempt and the quiz results; issue only the unanswered task.
    Database::update('skill_level_attempts',['is_current'=>0,'status'=>'expired'],'id=?',[$attemptId]);
    $newId=Database::insert('skill_level_attempts',[
        'user_id'=>$userId,'skill_level_id'=>$attempt['skill_level_id'],
        'dept_id'=>$attempt['dept_id'],'role_id'=>$attempt['role_id'],
        'question_ids'=>'[]','assignment_ids'=>json_encode([$testId]),
        'started_at'=>date('Y-m-d H:i:s'),'expires_at'=>date('Y-m-d H:i:s',time()+$minutes*60),
        'status'=>'active','is_current'=>1,
    ]);
    $pdo->commit();reply(['ok'=>true,'attempt_id'=>$newId]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[reopen_assignment] '.$e->getMessage());
    reply(['ok'=>false,'error'=>'Не удалось повторно открыть ТЗ'],500);
}
