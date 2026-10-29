<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/result_publications.php';
require_once __DIR__.'/hard_skill_attempts.php';
session_name(SESSION_NAME);session_start();
header('Content-Type: application/json; charset=utf-8');
function respond(array $data,int $code=200): void {http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE);exit;}
if (empty($_SESSION['user_id'])) respond(['ok'=>false],401);
ensureLmsSchema();ensureTestResultsPassedColumn();
$action=$_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='queue') {
    if(!lmsStaff())lmsDeny();
    $users=Database::findAll('SELECT id,first_name,last_name FROM users WHERE is_admin=0 AND '.(!empty(lmsActor()['is_admin'])?'1=1':'dept_id='.(int)lmsActor()['dept_id']).' ORDER BY last_name,first_name');
    foreach($users as &$u){freezeExpiredAssignmentDrafts((int)$u['id']);$rows=publicationRows((int)$u['id']);$u['hard_skill_attempts_count']=(int)(Database::find('SELECT COUNT(*) AS n FROM skill_level_attempts WHERE user_id=? AND is_current=1 AND '.(!empty(lmsActor()['is_admin'])?'1=1':'dept_id='.(int)lmsActor()['dept_id']),[(int)$u['id']])['n'] ?? 0);$u['summary']=array_map(fn($r)=>array_intersect_key($r,array_flip(['category','kind','id','title','description','content_type','score','max_score','passed','completed_at','ready','comment','answers'])),$rows);$u['count']=count($rows);$u['pending']=count(array_filter($rows,fn($r)=>!$r['ready']));$last=lmsCurrentPublications((int)$u['id'])[0] ?? null;$u['published_at']=$last['published_at'] ?? null;}unset($u);
    respond(['ok'=>true,'users'=>$users]);
}
if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='publish') {
    if(!lmsStaff())lmsDeny();
    $body=json_decode(file_get_contents('php://input'),true) ?: [];$id=(int)($body['user_id'] ?? 0);lmsRequireEmployee($id);
    freezeExpiredAssignmentDrafts($id);
    $pdo=Database::get();$pdo->beginTransaction();
    try {
        Database::find('SELECT id FROM users WHERE id=? FOR UPDATE',[$id]);
        foreach(['skill_level_results','test_results','lesson_test_results'] as $table) Database::findAll("SELECT id FROM `$table` WHERE user_id=? FOR UPDATE",[$id]);
        $rows=publicationRows($id);
        if(!$rows || array_filter($rows,fn($r)=>!$r['ready'])){$pdo->rollBack();respond(['ok'=>false,'error'=>'Сначала проверьте все открытые вопросы и ТЗ'],409);}
        $active=Database::find("SELECT id FROM skill_level_attempts WHERE user_id=? AND is_current=1 AND status='active' AND expires_at>NOW() LIMIT 1",[$id]);
        if($active){$pdo->rollBack();respond(['ok'=>false,'error'=>'Сотрудник ещё проходит уровень. Дождитесь завершения'],409);}
        Database::insert('result_publications',['user_id'=>$id,'published_by'=>(int)$_SESSION['user_id'],'results_json'=>json_encode($rows,JSON_UNESCAPED_UNICODE)]);
        $pdo->commit();respond(['ok'=>true]);
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->getMessage());respond(['ok'=>false,'error'=>'Не удалось опубликовать результаты'],500);}
}
if($_SERVER['REQUEST_METHOD']==='GET' && !$action){
    $rows=lmsCurrentPublications((int)$_SESSION['user_id']);
    foreach($rows as &$r){$r['results']=array_values(array_filter(categorizePublishedResults(json_decode($r['results_json'],true) ?: []),fn($item)=>!(($item['content_type'] ?? '')==='quiz' && !empty($item['comment']))));unset($r['results_json']);}unset($r);
    respond(['ok'=>true,'publications'=>$rows]);
}
respond(['ok'=>false],405);
