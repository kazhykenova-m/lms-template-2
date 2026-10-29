<?php
require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/quiz_validation.php';
require_once __DIR__ . '/coins_bridge.php';
require_once __DIR__ . '/hard_skill_attempts.php';

session_name(SESSION_NAME);
session_start();
require_once __DIR__ . '/access.php';
ensureLmsSchema();
header('Content-Type: application/json; charset=utf-8');

function json(array $data, int $code = 200): void {
    http_response_code($code);
    global $test;
    $onboarding = !empty($test['onboarding_step_id']) && empty($test['skill_level_id']);
    echo json_encode($onboarding ? $data : lmsHideLearningGrades($data), JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) json(['ok' => false, 'error' => 'Не авторизован'], 401);
ensureTestResultsPassedColumn();
$userId  = (int) $_SESSION['user_id'];
freezeExpiredAssignmentDrafts($userId);
$isAdmin = lmsStaff();
$method  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? '';

$body = [];
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
    $body = array_merge($_POST, $body);
}


$actor = lmsActor();
$managerOnly = empty($actor['is_admin']) && !empty($actor['is_manager']);
if ($managerOnly) {
    if ($method === 'GET') {
        if (isset($_GET['dept_id'])) lmsRequireDepartment($_GET['dept_id']);
        if (isset($_GET['id'])) {
            $row = Database::find('SELECT dept_id FROM tests WHERE id=?', [(int)$_GET['id']]);
            lmsRequireDepartment($row['dept_id'] ?? 0);
        }
    } elseif ($action !== 'submit') {
        if (in_array($action, ['delete_question','update_question'], true)) {
            $row = Database::find('SELECT t.dept_id FROM questions q JOIN tests t ON t.id=q.test_id WHERE q.id=?', [(int)($_GET['id'] ?? $body['id'] ?? 0)]);
        } else {
            $id = (int)($_GET['id'] ?? $body['id'] ?? $body['test_id'] ?? 0);
            $row = $id ? Database::find('SELECT dept_id FROM tests WHERE id=?', [$id]) : null;
        }
        if ($row) lmsRequireDepartment($row['dept_id']);
        if (isset($body['dept_id']) || !$row) lmsRequireDepartment($body['dept_id'] ?? 0);
        if (!empty($body['role_id'])) {
            $role = Database::find('SELECT dept_id FROM roles WHERE id=?', [(int)$body['role_id']]);
            lmsRequireDepartment($role['dept_id'] ?? 0);
        }
    }
}

if (in_array($method,['POST','PUT'],true)) {
 $body['dept_id']=1;$body['role_id']=1;$body['coins_reward']=0;$body['onboarding_step_id']=null;
}
// ── GET: список тестов или один тест с вопросами ──────────────────
if ($method === 'GET' && !$action) {
    if (isset($_GET['id'])) {
        $test = Database::find('SELECT * FROM tests WHERE id = ?', [(int)$_GET['id']]);
        if (!$test) json(['ok' => false, 'error' => 'Тест не найден'], 404);
        lmsRequireLearningMaterial($test);

        $questions = Database::findAll(
            'SELECT * FROM questions WHERE test_id = ? ORDER BY sort',
            [$test['id']]
        );
        foreach ($questions as &$q) {
            if ($q['type'] !== 'open') {
                $q['options'] = Database::findAll(
                    'SELECT id, option_text, is_correct FROM question_options WHERE question_id = ? ORDER BY RAND()',
                    [$q['id']]
                );
            } else {
                $q['options'] = [];
            }
        }
        $test['questions'] = $questions;
        $test['last_result'] = Database::find(
            'SELECT * FROM test_results WHERE is_current=1 AND user_id = ? AND test_id = ? ORDER BY id DESC LIMIT 1',
            [$userId, $test['id']]
        );
        json(['ok' => true, 'test' => $test]);
    }

    // ── Админ: список заданий хардскиллов (content_type != quiz, привязаны к уровню) ──
    if (isset($_GET['assignments'])) {
        $staffScope = !empty(lmsActor()['is_admin']) ? '1=1' : 't.dept_id=' . (int)lmsActor()['dept_id'];
        if (!$isAdmin) json(['ok' => false, 'error' => 'Нет доступа'], 403);
        $tests = Database::findAll(
            "SELECT t.*,
                    CASE WHEN t.dept_id = 0 THEN 'Все отделы' ELSE d.name END as dept_name,
                    r.name as role_name,
                    sl.title as skill_level_title, sl.sort as skill_level_sort
             FROM tests t
             LEFT JOIN departments d ON d.id = t.dept_id
             LEFT JOIN roles r ON r.id = t.role_id
             LEFT JOIN skill_levels sl ON sl.id = t.skill_level_id
             WHERE t.content_type != 'quiz' AND t.skill_level_id IS NOT NULL
             AND ({$staffScope})
             ORDER BY sl.sort, t.id"
        );
        json(['ok' => true, 'tests' => $tests]);
    }

    $user   = Database::find('SELECT dept_id, role_id,onboarding_dept_id,onboarding_role_id FROM users WHERE id = ?', [$userId]);
    $deptId = $isAdmin ? (int)($_GET['dept_id'] ?? $user['dept_id'] ?? 0) : (int)($user['dept_id'] ?? 0);
    $roleId = $isAdmin ? (int)($_GET['role_id'] ?? 0) : (int)($user['role_id'] ?? 0);
    // Фильтрация:
    // onboarding=1  — только тесты привязанные к шагам онбординга
    // onboarding=0  — только хардскилл тесты (не привязанные к шагам)
    // step_id=N     — тест для конкретного шага онбординга
    $onboardingOnly = isset($_GET['onboarding']) ? (int)$_GET['onboarding'] : -1;
    $stepId = isset($_GET['step_id']) ? (int)$_GET['step_id'] : 0;

    if ($stepId > 0) {
        if (!$isAdmin) $roleId=(int)($user['onboarding_role_id'] ?? $user['role_id'] ?? 0);
        // Ищем тест привязанный к конкретному шагу
        $sql = 'SELECT t.*, d.name as dept_name, sl.title as skill_level_title FROM tests t
             JOIN departments d ON d.id = t.dept_id
             LEFT JOIN skill_levels sl ON sl.id = t.skill_level_id
             WHERE t.onboarding_step_id = ? AND t.is_active = 1';
        $params = [$stepId];
        if ($roleId > 0) { $sql .= ' AND (t.role_id = ? OR t.role_id IS NULL OR t.role_id = 0)'; $params[] = $roleId; }
        $sql .= ' ORDER BY t.id';
        $tests = Database::findAll($sql, $params);
    } else {
        // onboarding=0 → хардскиллы (step_id IS NULL или 0)
        // onboarding=1 → тесты шагов онбординга
        $whereExtra = $onboardingOnly === 1 ? ' AND t.onboarding_step_id > 0'
                    : ($onboardingOnly === 0 ? ' AND (t.onboarding_step_id IS NULL OR t.onboarding_step_id = 0)' : '');
                $sql = "SELECT t.*, 
                        CASE WHEN t.dept_id = 0 THEN 'Все отделы' ELSE d.name END as dept_name,
                        sl.title as skill_level_title 
         FROM tests t
         LEFT JOIN departments d ON d.id = t.dept_id
         LEFT JOIN skill_levels sl ON sl.id = t.skill_level_id
         WHERE (t.dept_id = ? OR t.dept_id = 0) 
             AND t.is_active = 1{$whereExtra}";
                $params = [$deptId];
                if ($roleId > 0) { $sql .= ' AND (t.role_id = ? OR t.role_id IS NULL OR t.role_id = 0)'; $params[] = $roleId; }
                $sql .= ' ORDER BY t.dept_id = 0 DESC, t.id';
                $tests = Database::findAll($sql, $params);
    }
    // Admin editor loads the complete list in three queries, rather than one request per test.
    if ($isAdmin && !empty($_GET['include_questions']) && $tests) {
        $ids=array_column($tests,'id');
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $questions=Database::findAll("SELECT * FROM questions WHERE test_id IN ($placeholders) ORDER BY test_id,sort,id",$ids);
        $options=[];
        foreach (Database::findAll("SELECT o.* FROM question_options o JOIN questions q ON q.id=o.question_id WHERE q.test_id IN ($placeholders) ORDER BY o.id",$ids) as $option) $options[$option['question_id']][]=$option;
        $byTest=[];
        foreach ($questions as $question) {
            $question['options']=$question['type']==='open' ? [] : ($options[$question['id']] ?? []);
            $byTest[$question['test_id']][]=$question;
        }
        foreach ($tests as &$item) $item['questions']=$byTest[$item['id']] ?? [];
        unset($item);
    }
    json(['ok' => true, 'tests' => $tests]);
}

// Validate the optional setting without silently truncating fractions.
if ($method === 'POST' && (!$action || $action === 'update') && array_key_exists('questions_to_issue', $body)) {
    $count = filter_var($body['questions_to_issue'], FILTER_VALIDATE_INT);
    if ($count === false || $count < 1 || $count > 1000) {
        json(['ok'=>false, 'error'=>'Количество вопросов должно быть целым числом от 1 до 1000'], 400);
    }
}

// Hard-skill quizzes must have a concrete audience and level.
function requireQuizAssociations($type, int $deptId, ?int $roleId, ?int $levelId, $stepId): void {
    if ($type !== 'quiz' || (int)$stepId > 0) return;
    if ($deptId < 1 || !$roleId || !$levelId) json(['ok'=>false,'error'=>'Для теста обязательны отдел, должность и уровень хардскилла'],400);
    if (!Database::find('SELECT id FROM departments WHERE id=?',[$deptId])) json(['ok'=>false,'error'=>'Отдел не найден'],400);
    if (!Database::find('SELECT id FROM roles WHERE id=? AND dept_id=?',[$roleId,$deptId])) json(['ok'=>false,'error'=>'Выберите должность выбранного отдела'],400);
    if (!Database::find('SELECT id FROM skill_levels WHERE id=?',[$levelId])) json(['ok'=>false,'error'=>'Уровень хардскилла не найден'],400);
}

// ── POST: создать тест ────────────────────────────────────────────
if ($method === 'POST' && !$action) {
    if (!$isAdmin) json(['ok' => false, 'error' => 'Нет доступа'], 403);

    $title  = trim($body['title'] ?? '');
    $deptId = (int)($body['dept_id'] ?? 0);
    $roleId = !empty($body['role_id']) ? (int)$body['role_id'] : null;
    if (!$title || $deptId < 0) {
    json(['ok' => false, 'error' => 'Название и отдел обязательны'], 400);
}
    $skillLevelId = !empty($body['skill_level_id']) ? (int)$body['skill_level_id'] : null;
    $contentType = $body['content_type'] ?? 'quiz';
    requireQuizAssociations($contentType,$deptId,$roleId,$skillLevelId,$body['onboarding_step_id'] ?? null);
    $id = Database::insert('tests', [
    'dept_id'            => $deptId,
    'role_id'            => $roleId,
    'title'              => $title,
    'description'        => trim($body['description'] ?? ''),
    'content_type'       => $contentType,
    'video_path'         => trim($body['video_path'] ?? ''),
    'coins_reward'       => (int)($body['coins_reward'] ?? 50),
    'skill_level_id'     => $skillLevelId,
    'questions_to_issue' => (int)($body['questions_to_issue'] ?? 10),
    'onboarding_step_id' => !empty($body['onboarding_step_id']) ? (int)$body['onboarding_step_id'] : null,
    'is_active'          => 1,
]);
    json(['ok' => true, 'id' => $id]);
}

// ── POST update — редактирование теста/задания ─────────────────────
if ($method === 'POST' && $action === 'update') {
    if (!$isAdmin) json(['ok' => false, 'error' => 'Нет доступа'], 403);

    $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
    if (!$id) json(['ok' => false, 'error' => 'id обязателен'], 400);

    $existing = Database::find('SELECT * FROM tests WHERE id = ?', [$id]);
    if (!$existing) json(['ok' => false, 'error' => 'Тест не найден'], 404);

    $title = trim($body['title'] ?? $existing['title']);
    if (!$title) json(['ok' => false, 'error' => 'Название обязательно'], 400);

    $newSkillLevelId = array_key_exists('skill_level_id', $body)
        ? (!empty($body['skill_level_id']) ? (int)$body['skill_level_id'] : null)
        : $existing['skill_level_id'];
    $newContentType = $body['content_type'] ?? $existing['content_type'];
    $newDeptId=(int)($body['dept_id'] ?? $existing['dept_id']);
    $newRoleId=array_key_exists('role_id',$body) ? (!empty($body['role_id']) ? (int)$body['role_id'] : null) : (!empty($existing['role_id']) ? (int)$existing['role_id'] : null);
    requireQuizAssociations($newContentType,$newDeptId,$newRoleId,$newSkillLevelId,$existing['onboarding_step_id'] ?? null);
    // Проверяем конфликт только если пользователь действительно меняет привязку
    // или превращает другой тип контента в quiz. Старые базы могут уже содержать
    // несколько тестов уровня; это не должно мешать изменить название/коины
    // у текущего теста.
    $associationChanged = (int)($existing['skill_level_id'] ?? 0) !== (int)$newSkillLevelId
        || ($existing['content_type'] ?? '') !== $newContentType;
    Database::update('tests', [
        'dept_id'        => isset($body['dept_id']) ? (int)$body['dept_id'] : $existing['dept_id'],
        'role_id'        => $newRoleId,
        'title'          => $title,
        'description'    => trim($body['description'] ?? $existing['description']),
        'content_type'   => $newContentType,
        'video_path'     => trim($body['video_path'] ?? $existing['video_path']),
        'coins_reward'   => isset($body['coins_reward']) ? (int)$body['coins_reward'] : $existing['coins_reward'],
        'skill_level_id' => $newSkillLevelId,
        'questions_to_issue' => (int)($body['questions_to_issue'] ?? $existing['questions_to_issue'] ?? 10),
        'is_active'      => isset($body['is_active']) ? (int)$body['is_active'] : $existing['is_active'],
    ], 'id = ?', [$id]);

    json(['ok' => true, 'id' => $id]);
}

// ── POST add_question ─────────────────────────────────────────────
if ($method === 'POST' && $action === 'add_question') {
    if (!$isAdmin) json(['ok' => false, 'error' => 'Нет доступа'], 403);

    $testId = (int)($body['test_id'] ?? 0);
    $type   = $body['type'] ?? 'single';
    $text   = trim($body['question_text'] ?? '');
    $options = $body['options'] ?? [];

    if (!$testId || !$text) json(['ok' => false, 'error' => 'test_id и текст вопроса обязательны'], 400);

    $maxSort = Database::find('SELECT COALESCE(MAX(sort),0)+1 as n FROM questions WHERE test_id = ?', [$testId]);
    $qId = Database::insert('questions', [
        'test_id'          => $testId,
        'sort'             => $maxSort['n'],
        'type'             => $type,
        'question_text'    => $text,
        // Для single/multi — разрешить сотруднику вписать свой вариант ответа
        // дополнительно к вариантам (не влияет на подсчёт баллов, только для админа)
        'allow_own_answer' => ($type !== 'open' && !empty($body['allow_own_answer'])) ? 1 : 0,
    ]);

    foreach ($options as $opt) {
        if (empty($opt['option_text'])) continue;
        Database::insert('question_options', [
            'question_id' => $qId,
            'option_text' => trim($opt['option_text']),
            'is_correct'  => (int)($opt['is_correct'] ?? 0),
        ]);
    }
    json(['ok' => true, 'id' => $qId]);
}

// Edit in place so existing answer references keep their option IDs.
if ($method === 'POST' && $action === 'update_question') {
    if (!$isAdmin) json(['ok'=>false,'error'=>'Нет доступа'],403);
    $id=(int)($body['id'] ?? 0);
    $q=Database::find('SELECT * FROM questions WHERE id=?',[$id]);
    if (!$q) json(['ok'=>false,'error'=>'Вопрос не найден'],404);
    $text=$body['question_text'] ?? null;
    if (!is_string($text) || trim($text)==='' || strlen($text)>65535) json(['ok'=>false,'error'=>'Введите текст вопроса (до 65535 байт)'],400);
    $options=$body['options'] ?? [];
    $existing=Database::findAll('SELECT id FROM question_options WHERE question_id=? ORDER BY id',[$id]);
    if (!is_array($options) || count($options)!==count($existing)) json(['ok'=>false,'error'=>'Состав вариантов изменился. Откройте вопрос заново'],409);
    $expected=array_map('intval',array_column($existing,'id')); $seen=[]; $correct=0;
    foreach ($options as $o) {
        if (!is_array($o) || !in_array((int)($o['id'] ?? 0),$expected,true) || in_array((int)$o['id'],$seen,true) || !is_string($o['option_text'] ?? null) || trim($o['option_text'])==='' || strlen($o['option_text'])>65535 || !in_array($o['is_correct'] ?? null,[0,1],true)) json(['ok'=>false,'error'=>'Некорректные варианты ответа'],400);
        $seen[]=(int)$o['id']; $correct+=$o['is_correct'];
    }
    if (($q['type']==='single' && $correct!==1) || ($q['type']==='multi' && !$correct)) json(['ok'=>false,'error'=>'Отметьте правильные ответы: для single ровно один'],400);
    $db=Database::get(); $db->beginTransaction();
    try {
        Database::update('questions',['question_text'=>trim($text)],'id=?',[$id]);
        foreach ($options as $o) Database::update('question_options',['option_text'=>trim($o['option_text']),'is_correct'=>$o['is_correct']],'id=? AND question_id=?',[(int)$o['id'],$id]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    json(['ok'=>true,'id'=>$id]);
}

// Save all submitted questions of one test atomically.
if ($method === 'POST' && $action === 'update_questions') {
    if (!$isAdmin) json(['ok'=>false,'error'=>'Нет доступа'],403);
    $testId=(int)($body['test_id'] ?? 0);
    if (!Database::find('SELECT id FROM tests WHERE id=?',[$testId])) json(['ok'=>false,'error'=>'Тест не найден'],404);
    $items=$body['questions'] ?? null;
    if (!is_array($items) || !$items || count($items)>1000) json(['ok'=>false,'error'=>'Неверный список вопросов'],400);
    $validated=[]; $ids=[];
    foreach ($items as $item) {
        if (!is_array($item)) json(['ok'=>false,'error'=>'Неверный вопрос'],400);
        $id=(int)($item['id'] ?? 0);
        if (in_array($id,$ids,true)) json(['ok'=>false,'error'=>'Повторяющийся вопрос'],400);
        $ids[]=$id;
    $q=Database::find('SELECT * FROM questions WHERE id=? AND test_id=?',[$id,$testId]);
    if (!$q) json(['ok'=>false,'error'=>'Вопрос не найден'],404);
    $text=$item['question_text'] ?? null;
    if (!is_string($text) || trim($text)==='' || strlen($text)>65535) json(['ok'=>false,'error'=>'Введите текст вопроса (до 65535 байт)'],400);
    $options=$item['options'] ?? [];
    $existing=Database::findAll('SELECT id FROM question_options WHERE question_id=? ORDER BY id',[$id]);
    if (!is_array($options) || count($options)!==count($existing)) json(['ok'=>false,'error'=>'Состав вариантов изменился. Откройте вопрос заново'],409);
    $expected=array_map('intval',array_column($existing,'id')); $seen=[]; $correct=0;
    foreach ($options as $o) {
        if (!is_array($o) || !in_array((int)($o['id'] ?? 0),$expected,true) || in_array((int)$o['id'],$seen,true) || !is_string($o['option_text'] ?? null) || trim($o['option_text'])==='' || strlen($o['option_text'])>65535 || !in_array($o['is_correct'] ?? null,[0,1],true)) json(['ok'=>false,'error'=>'Некорректные варианты ответа'],400);
        $seen[]=(int)$o['id']; $correct+=$o['is_correct'];
    }
    if (($q['type']==='single' && $correct!==1) || ($q['type']==='multi' && !$correct)) json(['ok'=>false,'error'=>'Отметьте правильные ответы: для single ровно один'],400);
        $validated[]=['id'=>$id,'text'=>trim($text),'options'=>$options];
    }
    $db=Database::get(); $db->beginTransaction();
    try {
        foreach ($validated as $item) {
            Database::update('questions',['question_text'=>$item['text']],'id=? AND test_id=?',[$item['id'],$testId]);
            foreach ($item['options'] as $o) Database::update('question_options',['option_text'=>trim($o['option_text']),'is_correct'=>$o['is_correct']],'id=? AND question_id=?',[(int)$o['id'],$item['id']]);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    json(['ok'=>true]);
}

// ── POST delete_question ──────────────────────────────────────────
if ($method === 'POST' && $action === 'delete_question') {
    if (!$isAdmin) json(['ok' => false, 'error' => 'Нет доступа'], 403);
    $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
    if (!$id) json(['ok' => false, 'error' => 'id обязателен'], 400);
    Database::delete('question_options', 'question_id = ?', [$id]);
    Database::delete('questions', 'id = ?', [$id]);
    json(['ok' => true]);
}

// ── POST submit — сдача теста ─────────────────────────────────────
if ($method === 'POST' && $action === 'submit') {
    $testId     = (int)($body['test_id'] ?? 0);
    $answers    = $body['answers'] ?? [];
    $ownAnswers = $body['own_answers'] ?? [];
    if (!$testId) json(['ok' => false, 'error' => 'test_id обязателен'], 400);

    $test = Database::find('SELECT * FROM tests WHERE id = ?', [$testId]);
    if (!$test) json(['ok' => false, 'error' => 'Тест не найден'], 404);
    lmsRequireLearningMaterial($test);

    // Lock the attempt and make retries of the same task submission idempotent.
    if ($test['content_type']==='task' && !empty($test['skill_level_id'])) {
        $comment=$body['comment'] ?? '';
        if(!is_string($comment)||trim($comment)==='')json(['ok'=>false,'error'=>'Добавьте ответ на задание'],400);
        $comment=trim($comment);
        if(strlen($comment)>200000)json(['ok'=>false,'error'=>'Ответ слишком длинный'],400);
        $pdo=Database::get();$pdo->beginTransaction();
        $attempt=Database::find('SELECT * FROM skill_level_attempts WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE',[$userId,$test['skill_level_id'],(int)lmsActor()['dept_id'],(int)lmsActor()['role_id']]);
        if(!$attempt || (!empty($body['attempt_id']) && (int)$body['attempt_id']!==(int)$attempt['id'])){$pdo->rollBack();json(['ok'=>false,'error'=>'Попытка изменилась'],409);}
        $existing=Database::find('SELECT * FROM test_results WHERE is_current=1 AND user_id=? AND test_id=? ORDER BY id DESC LIMIT 1',[$userId,$testId]);
        if($existing){
            $saved=json_decode($existing['answers_json']??'',true) ?: [];
            if(($saved['comment']??'')!==$comment){$pdo->rollBack();json(['ok'=>false,'error'=>'Другой ответ уже отправлен'],409);}
            $pdo->commit();json(['ok'=>true,'already_submitted'=>true,'attempt_completed'=>$attempt['status']==='completed','review_status'=>'pending']);
        }
        if($attempt['status']!=='active'||strtotime($attempt['expires_at'])<=time()){$pdo->rollBack();json(['ok'=>false,'error'=>'Время попытки истекло','expired'=>true],408);}
        if(!in_array($testId,array_map('intval',json_decode($attempt['assignment_ids'],true) ?: []),true)){$pdo->rollBack();json(['ok'=>false,'error'=>'Задание не выдавалось в этой попытке'],409);}
        if((json_decode($attempt['question_ids'],true) ?: []) && !Database::find('SELECT id FROM skill_level_results WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? LIMIT 1',[$userId,$test['skill_level_id'],(int)lmsActor()['dept_id'],(int)lmsActor()['role_id']])){$pdo->rollBack();json(['ok'=>false,'error'=>'Сначала отправьте тест'],409);}
        Database::insert('test_results',['user_id'=>$userId,'test_id'=>$testId,'score'=>0,'max_score'=>0,'passed'=>0,'review_status'=>'pending','answers_json'=>json_encode(['comment'=>$comment],JSON_UNESCAPED_UNICODE),'completed_at'=>date('Y-m-d H:i:s')]);
        $done=finalizeHardSkillAttempt($userId,(int)$test['skill_level_id']);
        $pdo->commit();json(['ok'=>true,'review_status'=>'pending','attempt_completed'=>$done,'coins_earned'=>0]);
    }

    // A Hard Skills assignment can only be submitted during the same server-side
    // level session in which it was randomly issued.
    if (!empty($test['skill_level_id']) && $test['content_type'] !== 'quiz') {
        $attempt = Database::find(
            'SELECT * FROM skill_level_attempts WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? ORDER BY id DESC LIMIT 1',
            [$userId, (int)$test['skill_level_id'], (int)lmsActor()['dept_id'], (int)lmsActor()['role_id']]
        );
        if (!$attempt || $attempt['status'] !== 'active' || strtotime($attempt['expires_at']) <= time()) {
            if ($attempt && $attempt['status'] === 'active') Database::update('skill_level_attempts', ['status'=>'expired'], 'id=?', [$attempt['id']]);
            json(['ok' => false, 'error' => 'Время прохождения уровня истекло', 'expired' => true], 408);
        }
        if ((json_decode($attempt['question_ids'],true) ?: []) && !Database::find('SELECT id FROM skill_level_results WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? LIMIT 1',[$userId,(int)$test['skill_level_id'],(int)lmsActor()['dept_id'],(int)lmsActor()['role_id']])) json(['ok'=>false,'error'=>'Сначала отправьте ответы на тест'],409);
        $issuedIds = array_map('intval', json_decode($attempt['assignment_ids'], true) ?: []);
        if (!in_array($testId, $issuedIds, true)) json(['ok' => false, 'error' => 'Это задание не было выдано в текущей попытке'], 409);
    }

    if (!empty($test['skill_level_id']) && Database::find('SELECT id FROM test_results WHERE is_current=1 AND user_id=? AND test_id=? LIMIT 1', [$userId,$testId])) json(['ok'=>false,'error'=>'Результат уже отправлен'],409);

    $isOnboarding = !empty($test['onboarding_step_id']) && empty($test['skill_level_id']);
    if (!$isOnboarding && Database::find("SELECT id FROM test_results WHERE is_current=1 AND user_id=? AND test_id=? AND review_status='pending' LIMIT 1", [$userId,$testId])) json(['ok'=>false,'error'=>'Ответы уже на рассмотрении'],409);
    // Считаем попытки
    $attemptCount = Database::find(
        'SELECT COUNT(*) as n FROM test_results WHERE is_current=1 AND user_id = ? AND test_id = ?' . ($isOnboarding ? " AND review_status<>'pending'" : ''),
        [$userId, $testId]
    )['n'] ?? 0;
    $maxAttempts = defined('TEST_MAX_ATTEMPTS') ? (int)TEST_MAX_ATTEMPTS : 0;
    if ($maxAttempts > 0 && (int)$attemptCount >= $maxAttempts) {
        json(['ok' => false, 'error' => 'Превышено количество попыток', 'attempts_used' => $attemptCount, 'max_attempts' => $maxAttempts], 429);
    }

    $questions = Database::findAll('SELECT * FROM questions WHERE test_id = ? ORDER BY sort', [$testId]);
    if ($test['content_type'] === 'quiz' && !empty($test['skill_level_id'])) json(['ok'=>false,'error'=>'Проходите тест через назначенное обучение'],409);
    if ($test['content_type'] === 'quiz' && ($error=quizAnswerError($questions,$answers))) json(['ok'=>false,'error'=>$error],400);
    $score = 0; $maxScore = 0; $results = []; $allCorrect = true;

    if ($test['content_type'] !== 'quiz') {
        if ($test['content_type']==='task' && trim((string)($body['comment'] ?? ''))==='') json(['ok'=>false,'error'=>'Добавьте ответ на задание'],400);
        // ТЗ ожидает ручной проверки; видео завершается после просмотра
        $passed = true;
        if (!empty($body['details'])) {
            $results['details'] = $body['details'];
        } elseif (!empty($body['comment'])) {
            $results['comment'] = trim($body['comment']);
        }
    } else {
        foreach ($questions as $q) {
            if ($q['type'] === 'open') {
                $results[$q['id']] = ['type' => 'open', 'answer' => $answers[$q['id']] ?? ''];
                continue; // открытые не считаем в maxScore
            }
            $maxScore++;
            $correctOptions = Database::findAll(
                'SELECT id FROM question_options WHERE question_id = ? AND is_correct = 1',
                [$q['id']]
            );
            $correctIds = array_column($correctOptions, 'id');
            $correctIds = array_map('intval', $correctIds);
            sort($correctIds);

            $userAnswer = $answers[$q['id']] ?? [];
            if (!is_array($userAnswer)) $userAnswer = [$userAnswer];
            $userAnswer = array_map('intval', $userAnswer);
            sort($userAnswer);

            $isCorrect = $correctIds === $userAnswer;
            if ($isCorrect) $score++;
            else $allCorrect = false;
            $results[$q['id']] = ['type' => $q['type'], 'answer' => $userAnswer, 'correct_ids' => $correctIds, 'is_correct' => $isCorrect];

            // Свой вариант ответа (если вопрос это разрешает) — не влияет на баллы,
            // только сохраняется для админа
            if (!empty($q['allow_own_answer'])) {
                $ownAnswer = trim($ownAnswers[$q['id']] ?? '');
                if ($ownAnswer !== '') $results[$q['id']]['own_answer'] = $ownAnswer;
            }
        }

        // Тест считается пройденным только при 100% верных ответов (без открытых)
        $require100 = defined('TEST_REQUIRE_100') ? TEST_REQUIRE_100 : true;
        $passed = $maxScore > 0 && ($require100 ? $allCorrect : $score > 0);
    }

    $hasOpen = count(array_filter($results, fn($a)=>is_array($a) && ($a['type'] ?? '')==='open')) > 0;
    $needsReview = !$isOnboarding && ($hasOpen || $test['content_type']==='task');
    if ($isOnboarding && $test['content_type']==='quiz' && $maxScore===0 && $hasOpen) $passed=true;
    if ($needsReview) $passed = false;
    Database::insert('test_results', [
        'user_id'      => $userId,
        'test_id'      => $testId,
        'score'        => $score,
        'max_score'    => $maxScore,
        'passed'       => $passed ? 1 : 0,
        'review_status' => $needsReview ? 'pending' : 'completed',
        'answers_json' => json_encode($results, JSON_UNESCAPED_UNICODE),
        'completed_at' => date('Y-m-d H:i:s'),
    ]);
    $hardSkillAttemptCompleted = false;
    if (!empty($test['skill_level_id']) && $test['content_type'] !== 'quiz' ) {
        $hardSkillAttemptCompleted = finalizeHardSkillAttempt($userId, (int)$test['skill_level_id']);
    }

    $coinsEarned=0;$stepCoinsEarned=0;$stepCompleted=false;
    $onboardingComplete=false;$certIssued=false;$certEmailSent=false;$certRecord=null;$newAchs=[];

    json([
        'ok'               => true,
        'score'            => $score,
        'max_score'        => $maxScore,
        'review_status' => $needsReview ? 'pending' : 'completed',
        'passed'           => $passed,
        'all_correct'      => $allCorrect,
        'coins_earned'     => $coinsEarned + $stepCoinsEarned,
        'attempt'          => (int)$attemptCount + 1,
        'step_completed'   => $stepCompleted,
        'onboarding_complete' => $onboardingComplete,
        'cert_issued'      => $certIssued,
        'cert_email_sent'  => $certEmailSent,
        'cert_path'        => $certRecord['cert_path'] ?? null,
        'new_achievements' => $newAchs,
        'attempt_completed'=> $hardSkillAttemptCompleted,
        'results'          => $results,
    ]);
}

// ── DELETE: удалить тест ──────────────────────────────────────────
if ($method === 'DELETE') {
    if (!$isAdmin) json(['ok' => false, 'error' => 'Нет доступа'], 403);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json(['ok' => false, 'error' => 'id обязателен'], 400);

    $questions = Database::findAll('SELECT id FROM questions WHERE test_id = ?', [$id]);
    foreach ($questions as $q) Database::delete('question_options', 'question_id = ?', [$q['id']]);
    Database::delete('questions', 'test_id = ?', [$id]);
    Database::delete('test_results', 'test_id = ?', [$id]);
    Database::delete('tests', 'id = ?', [$id]);
    json(['ok' => true]);
}

json(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
