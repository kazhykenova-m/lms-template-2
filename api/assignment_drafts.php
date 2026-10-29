<?php
require_once __DIR__.'/api_errors.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/access.php';
require_once __DIR__.'/hard_skill_attempts.php';
session_name(SESSION_NAME); session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) {http_response_code(401); exit;}
try {
ensureLmsSchema();
if (!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)) {http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Метод не поддерживается']);exit;}
session_write_close();
$body=json_decode(file_get_contents('php://input'),true) ?: [];
$id=(int)($_GET['test_id'] ?? $body['test_id'] ?? 0);
$test=Database::find('SELECT * FROM tests WHERE id=?',[$id]);
if (!$test || lmsResultContentType($test,[])!=='task') {http_response_code(404);echo json_encode(['ok'=>false,'error'=>'ТЗ не найдено или имеет неверный тип. Проверьте content_type задания'],JSON_UNESCAPED_UNICODE);exit;}
lmsRequireLearningMaterial($test);
$u=lmsActor(); $pdo=Database::get(); $pdo->beginTransaction();
$a=Database::find('SELECT * FROM skill_level_attempts WHERE user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? AND is_current=1 ORDER BY id DESC LIMIT 1 FOR UPDATE',[$u['id'],$test['skill_level_id'],(int)$u['dept_id'],(int)$u['role_id']]);
if ($a && $_SERVER['REQUEST_METHOD']==='POST' && (int)($body['attempt_id'] ?? 0)!==(int)$a['id']) {$pdo->rollBack();http_response_code(409);echo json_encode(['ok'=>false,'error'=>'Попытка изменилась. Откройте текущий уровень'],JSON_UNESCAPED_UNICODE);exit;}
if (!$a || !in_array($id,array_map('intval',json_decode($a['assignment_ids'],true) ?: []),true)) {$pdo->rollBack();lmsDeny();}
$locked=$a['status']!=='active' || strtotime($a['expires_at'])<=time() || Database::find('SELECT id FROM test_results WHERE user_id=? AND test_id=? AND is_current=1',[$u['id'],$id]);
if ($_SERVER['REQUEST_METHOD']==='POST' && !$locked) {
    if (!is_string($body['comment'] ?? null)) {$pdo->rollBack();http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Некорректный текст черновика'],JSON_UNESCAPED_UNICODE);exit;}
    $text=$body['comment'];
    if (strlen($text)>200000) {$pdo->rollBack();http_response_code(413);echo json_encode(['ok'=>false,'error'=>'Ответ слишком длинный (максимум 200 КБ). Прикрепите его файлом'],JSON_UNESCAPED_UNICODE);exit;}
    Database::query('INSERT INTO assignment_drafts (attempt_id,test_id,comment) VALUES (?,?,?) ON DUPLICATE KEY UPDATE comment=VALUES(comment),updated_at=NOW()',[$a['id'],$id,$text]);
}
if ($_SERVER['REQUEST_METHOD']==='POST' && $locked) {$pdo->rollBack();http_response_code(409);echo json_encode(['ok'=>false,'error'=>'Попытка завершена или ответ уже отправлен'],JSON_UNESCAPED_UNICODE);exit;}
$d=Database::find('SELECT comment FROM assignment_drafts WHERE attempt_id=? AND test_id=?',[$a['id'],$id]);
$pdo->commit();
ensureTestResultsPassedColumn();
if ($_SERVER['REQUEST_METHOD']==='GET') freezeExpiredAssignmentDrafts((int)$u['id']);
echo json_encode(['ok'=>true,'locked'=>(bool)$locked,'comment'=>$d['comment'] ?? ''],JSON_UNESCAPED_UNICODE);

} catch(Throwable $e) {
    if(isset($pdo) && $pdo->inTransaction())$pdo->rollBack();
    error_log('[assignment_drafts] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Не удалось сохранить черновик. Проверьте миграцию БД и журнал ошибок PHP'],JSON_UNESCAPED_UNICODE);
}
