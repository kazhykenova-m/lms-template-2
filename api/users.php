<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/Database.php';

session_name(SESSION_NAME);
session_start();
require_once __DIR__ . '/access.php';
ensureLmsSchema();
header('Content-Type: application/json; charset=utf-8');

function json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) json(['ok' => false, 'error' => 'Не авторизован'], 401);

$userId  = (int) $_SESSION['user_id'];
$isAdmin = lmsStaff();
$method  = $_SERVER['REQUEST_METHOD'];

$body = [];
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
}

// Departments are implementation-only compatibility fields, never user-editable.
if (in_array($method,['POST','PUT'],true) && ($_GET['action'] ?? '')!=='levels') {
 $body['dept_id']=1;$body['role_id']=1;$body['is_manager']=0;
}
// Managers may only change visibility for employees in their own department.
if ($method==='PUT' && ($_GET['action'] ?? '')==='levels') {
    if (!lmsStaff()) lmsDeny();
    $id=(int)($_GET['id'] ?? 0);
    lmsRequireEmployee($id);
    $target=Database::find('SELECT is_admin FROM users WHERE id=?',[$id]);
    if (empty(lmsActor()['is_admin']) && !empty($target['is_admin'])) lmsDeny();
    if (!array_key_exists('visible_skill_level_ids',$body) || array_diff(array_keys($body),['visible_skill_level_ids'])) json(['ok'=>false,'error'=>'Можно менять только видимые уровни'],400);
    $encoded=lmsValidateVisibleLevels($body['visible_skill_level_ids']);
    Database::update('users',['visible_skill_level_ids'=>$encoded,'assigned_skill_level_id'=>null],'id=?',[$id]);
    json(['ok'=>true]);
}

if (empty(lmsActor()['is_admin']) && !empty(lmsActor()['is_manager'])) {
    if ($method !== 'GET' && ($method !== 'PUT' || (int)($_GET['id'] ?? 0) !== $userId)) lmsDeny();
    if (isset($_GET['id']) && (int)$_GET['id'] !== $userId) lmsRequireEmployee((int)$_GET['id']);
}

// GET action=results — все результаты тестов/заданий/хардскиллов конкретного сотрудника (админ)
if ($method === 'GET' && ($_GET['action'] ?? '') === 'results') {
    if (!$isAdmin) json(['ok' => false, 'error' => 'Вы вошли как сотрудник. Для просмотра сотрудников и их результатов нужны права администратора или руководителя.'], 403);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json(['ok' => false, 'error' => 'id обязателен'], 400);

    $resultScope = !empty(lmsActor()['is_admin']) ? '1=1' : '(t.dept_id=' . (int)lmsActor()['dept_id'] . ' OR COALESCE(t.dept_id,0)=0)';
    $levelScope = !empty(lmsActor()['is_admin']) ? '1=1' : 'slr.dept_id=' . (int)lmsActor()['dept_id'];
    $attemptScope = !empty(lmsActor()['is_admin']) ? '1=1' : 'a.dept_id=' . (int)lmsActor()['dept_id'];
    $lessonScope = !empty(lmsActor()['is_admin']) ? '1=1' : '(l.dept_id=' . (int)lmsActor()['dept_id'] . ' OR COALESCE(l.dept_id,0)=0)';
    // Тесты, задания хардскиллов, шаги онбординга — всё что попадает в test_results
    require_once __DIR__.'/hard_skill_attempts.php';
    ensureTestResultsPassedColumn();
    freezeExpiredAssignmentDrafts($id);
    $testResults = Database::findAll(
        'SELECT tr.*, t.title as test_title, t.description AS task_description, t.content_type, t.skill_level_id, t.onboarding_step_id,
                sl.title as skill_level_title
         FROM test_results tr
         JOIN tests t ON t.id = tr.test_id
         LEFT JOIN skill_levels sl ON sl.id = t.skill_level_id
         WHERE tr.user_id = ? AND (' . $resultScope . ')
         ORDER BY tr.completed_at DESC',
        [$id]
    );
    foreach ($testResults as &$r) {
        $r['answers'] = json_decode($r['answers_json'] ?? '', true) ?: [];
        if(array_key_exists('content_type',$r)) {
            $r['content_type']=lmsResultContentType($r,$r['answers']);
            if($r['content_type']==='task') {$verdict=$r['answers']['verdict']['accepted'] ?? null;$r['passed']=$verdict===true?1:0;$r['review_status']=is_bool($verdict)?'reviewed':'pending';}
        }
        unset($r['answers_json']);
    }
    unset($r);

    $lessonResults=[];

    // Тесты хардскилл-уровней (случайная подборка вопросов из банка уровня)
    $levelResults = Database::findAll(
        'SELECT slr.id, slr.skill_level_id, slr.score, slr.max_score, slr.passed, slr.completed_at,
                slr.answers_json, slr.review_status, sl.title as skill_level_title
         FROM skill_level_results slr
         JOIN skill_levels sl ON sl.id = slr.skill_level_id
         WHERE slr.user_id = ? AND (' . $levelScope . ')
         ORDER BY slr.completed_at DESC',
        [$id]
    );
    foreach ($levelResults as &$r) {
        $r['answers'] = json_decode($r['answers_json'] ?? '', true) ?: [];
        foreach ($r['answers'] as $qid=>&$answer) {
            if (!is_array($answer) || !isset($answer['type']))continue;
            $question=Database::find('SELECT question_text FROM questions WHERE id=?',[(int)$qid]);
            $answer['question_text']=$question['question_text'] ?? ('Вопрос #'.$qid);
            if ($answer['type']!=='open') {
                $texts=[];
                foreach (($answer['answer'] ?? []) as $optionId) {
                    $option=Database::find('SELECT option_text FROM question_options WHERE id=?',[(int)$optionId]);
                    if($option)$texts[]=$option['option_text'];
                }
                $answer['answer_text']=implode(', ',$texts);
            }
        }
        unset($answer);

        if(array_key_exists('content_type',$r)) {
            $r['content_type']=lmsResultContentType($r,$r['answers']);
            if($r['content_type']==='task') {$verdict=$r['answers']['verdict']['accepted'] ?? null;$r['passed']=$verdict===true?1:0;$r['review_status']=is_bool($verdict)?'reviewed':'pending';}
        }
        unset($r['answers_json']);
    }
    unset($r);

    // Подтягиваем текст вопроса для открытых вопросов и «своих вариантов ответа» —
    // чтобы админ видел не только текст ответа, но и на какой вопрос он дан
    $qIds = [];
    foreach (array_merge($testResults, $levelResults, $lessonResults) as $r) {
        foreach (($r['answers'] ?? []) as $qid => $a) {
            if (is_array($a) && (($a['type'] ?? '') === 'open' || !empty($a['own_answer']))) $qIds[] = (int)$qid;
        }
    }
    $qIds = array_values(array_unique($qIds));
    $qTextMap = [];
    if ($qIds) {
        $qRows = Database::findAll('SELECT id, question_text FROM questions WHERE id IN (' . implode(',', $qIds) . ')', []);
        foreach ($qRows as $qr) $qTextMap[$qr['id']] = $qr['question_text'];
    }
    foreach ($testResults as &$r) {
        foreach ($r['answers'] as $qid => &$a) {
            if (is_array($a) && isset($qTextMap[$qid])) $a['question_text'] = $qTextMap[$qid];
        }
        unset($a);
    }
    unset($r);
    foreach ($levelResults as &$r) {
        foreach ($r['answers'] as $qid => &$a) {
            if (is_array($a) && isset($qTextMap[$qid])) $a['question_text'] = $qTextMap[$qid];
        }
        unset($a);
    }
    unset($r);

    foreach ($lessonResults as &$r) { foreach ($r['answers'] as $qid=>&$a) if (is_array($a) && isset($qTextMap[$qid])) $a['question_text']=$qTextMap[$qid]; unset($a); } unset($r);

    $onboardingSteps=[];$standardResults=[];$certificates=[];

    $hardSkillAttempts = Database::findAll(
        'SELECT a.*, sl.title AS skill_level_title
         FROM skill_level_attempts a
         JOIN skill_levels sl ON sl.id=a.skill_level_id
         WHERE a.user_id=? AND (' . $attemptScope . ') ORDER BY a.started_at DESC, a.id DESC',
        [$id]
    );
    $allAssignmentIds = [];
    foreach ($hardSkillAttempts as &$attempt) {
        $attempt['question_ids'] = array_map('intval', json_decode($attempt['question_ids'] ?? '', true) ?: []);
        $attempt['assignment_ids'] = array_map('intval', json_decode($attempt['assignment_ids'] ?? '', true) ?: []);
        $allAssignmentIds = array_merge($allAssignmentIds, $attempt['assignment_ids']);
        $effectiveEnd = $attempt['completed_at'] ?: (
            strtotime($attempt['expires_at']) <= time() ? $attempt['expires_at'] : date('Y-m-d H:i:s')
        );
        $attempt['drafts']=Database::findAll('SELECT d.test_id,d.comment,d.updated_at,t.title,t.description AS task_description FROM assignment_drafts d JOIN tests t ON t.id=d.test_id WHERE d.attempt_id=?',[$attempt['id']]);
        if ($attempt['status'] === 'active' && strtotime($attempt['expires_at']) <= time()) $attempt['status'] = 'expired';
        $attempt['duration_seconds'] = max(0, strtotime($effectiveEnd) - strtotime($attempt['started_at']));
    }
    unset($attempt);
    $assignmentMap = [];
    $allAssignmentIds = array_values(array_unique($allAssignmentIds));
    if ($allAssignmentIds) {
        $rows = Database::findAll(
            'SELECT id, title, content_type FROM tests WHERE id IN (' . implode(',', $allAssignmentIds) . ')', []
        );
        foreach ($rows as $row) $assignmentMap[(int)$row['id']] = $row;
    }
    foreach ($hardSkillAttempts as &$attempt) {
        $attempt['assignments'] = [];
        foreach ($attempt['assignment_ids'] as $assignmentId) {
            if (isset($assignmentMap[$assignmentId])) {
                $assignment=$assignmentMap[$assignmentId];
                $latest=Database::find('SELECT id FROM skill_level_attempts WHERE user_id=? AND skill_level_id=? AND is_current=1 ORDER BY id DESC LIMIT 1',[$id,$attempt['skill_level_id']]);
                $assignment['can_reopen']=$assignment['content_type']==='task' && $attempt['status']==='expired'
                    && (int)($latest['id'] ?? 0)===(int)$attempt['id']
                    && !Database::find('SELECT id FROM test_results WHERE user_id=? AND test_id=? AND is_current=1 LIMIT 1',[$id,$assignmentId]);
                $attempt['assignments'][]=$assignment;
            }
        }
    }
    unset($attempt);

    $coinTransactions=[];$coinsEarnedTotal=0;$currentCoins=0;
    $publication=lmsCurrentPublications($id)[0] ?? null;

    json([
        'ok' => true,
        'published_at' => $publication['published_at'] ?? null,
        'lesson_results' => $lessonResults,
        'standard_results' => $standardResults,
        'test_results' => $testResults,
        'level_results' => $levelResults,
        'onboarding_steps' => $onboardingSteps,
        'certificates' => $certificates,
        'hard_skill_attempts' => $hardSkillAttempts,
        'coin_transactions' => $coinTransactions,
        'coins_earned_total' => $coinsEarnedTotal,
        'current_coins' => $currentCoins,
    ]);
}

// GET — list (admin only) or current user
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        if (!$isAdmin && $id !== $userId) json(['ok' => false, 'error' => 'Нет доступа'], 403);
        $user = Database::find('SELECT u.id, u.avatar, u.email, u.first_name, u.last_name, u.coins, u.level, u.is_admin, u.is_manager, u.assigned_skill_level_id, u.visible_skill_level_ids, u.created_at,
            d.slug as dept_slug, d.name as dept_name, d.id as dept_id, r.name as role_name, r.id as role_id
            FROM users u LEFT JOIN departments d ON d.id=u.dept_id LEFT JOIN roles r ON r.id=u.role_id
            WHERE u.id = ?', [$id]);
        if (!$user) json(['ok' => false, 'error' => 'Не найден'], 404);
        $user['visible_skill_level_ids']=lmsVisibleSkillLevelIds($user);
        json(['ok' => true, 'user' => $user]);
    }

    if (!$isAdmin) json(['ok' => false, 'error' => 'Вы вошли как сотрудник. Для просмотра сотрудников и их результатов нужны права администратора или руководителя.'], 403);

    $search = '%' . trim($_GET['search'] ?? '') . '%';
    $users = Database::findAll('SELECT u.id, u.avatar, u.email, u.first_name, u.last_name, u.coins, u.level, u.is_admin, u.is_manager, u.assigned_skill_level_id, u.visible_skill_level_ids, u.created_at,
        d.name as dept_name, r.name as role_name
        FROM users u LEFT JOIN departments d ON d.id=u.dept_id LEFT JOIN roles r ON r.id=u.role_id
        WHERE (' . (!empty(lmsActor()['is_admin']) ? '1=1' : 'u.dept_id=' . (int)lmsActor()['dept_id']) . ') AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)
        ORDER BY u.created_at DESC', [$search, $search, $search]);
    json(['ok' => true, 'users' => $users, 'total' => count($users)]);
}

// POST — create user (admin)
if ($method === 'POST') {
    if (!$isAdmin) json(['ok' => false, 'error' => 'Нет доступа'], 403);

    $email    = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';
    $fName    = trim($body['first_name'] ?? '');
    $lName    = trim($body['last_name'] ?? '');
    $deptId   = (int)($body['dept_id'] ?? 0);
    $roleId   = (int)($body['role_id'] ?? 0);
    $admin    = !empty($body['is_admin']) ? 1 : 0;
    if (!empty($body['is_manager']) && !$deptId) json(['ok'=>false,'error'=>'Выберите отдел руководителя'],400);
    if ($roleId && !Database::find('SELECT id FROM roles WHERE id=? AND dept_id=?',[$roleId,$deptId])) json(['ok'=>false,'error'=>'Должность не относится к выбранному отделу'],400);

    if (!$email || !$password || !$fName || !$lName) json(['ok' => false, 'error' => 'Заполни все поля'], 400);
    if (Database::find('SELECT id FROM users WHERE email = ?', [$email])) json(['ok' => false, 'error' => 'Email занят'], 409);

    $assigned=(int)($body['assigned_skill_level_id'] ?? 0);
    if ($assigned && !Database::find('SELECT id FROM skill_levels WHERE id=? AND is_active=1',[$assigned])) json(['ok'=>false,'error'=>'Уровень не найден'],400);
    $id = Database::insert('users', [
        'assigned_skill_level_id'=>$assigned ?: null,
        'visible_skill_level_ids'=>array_key_exists('visible_skill_level_ids',$body) ? lmsValidateVisibleLevels($body['visible_skill_level_ids']) : null,
        'email' => $email, 'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'first_name' => $fName, 'last_name' => $lName,
        'dept_id' => $deptId ?: null, 'role_id' => $roleId ?: null,
        'is_manager' => !empty($body['is_manager']) ? 1 : 0, 'is_admin' => $admin, 'coins' => 0, 'level' => 1,
    ]);
    json(['ok' => true, 'id' => $id]);
}

// PUT — update
if ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    if (empty(lmsActor()['is_admin']) && $id !== $userId) json(['ok' => false, 'error' => 'Нет доступа'], 403);
    if (!$id) json(['ok' => false, 'error' => 'id обязателен'], 400);

    $data = [];
    if (!empty(lmsActor()['is_admin']) && array_key_exists('assigned_skill_level_id',$body)) {
        $assigned=(int)$body['assigned_skill_level_id'];
        if ($assigned && !Database::find('SELECT id FROM skill_levels WHERE id=? AND is_active=1',[$assigned])) json(['ok'=>false,'error'=>'Уровень не найден'],400);
        $data['assigned_skill_level_id']=$assigned ?: null;
    }
    if (!empty(lmsActor()['is_admin']) && array_key_exists('visible_skill_level_ids',$body)) {
        $data['visible_skill_level_ids']=lmsValidateVisibleLevels($body['visible_skill_level_ids']);
        $data['assigned_skill_level_id']=null;
    }
    if (isset($body['first_name'])) $data['first_name'] = trim($body['first_name']);
    if (isset($body['last_name']))  $data['last_name']  = trim($body['last_name']);
    if (isset($body['email']))      $data['email']      = strtolower(trim($body['email']));

    if (isset($body['dept_id']))    $data['dept_id']    = (int)$body['dept_id'];
    if (isset($body['role_id']))    $data['role_id']    = (int)$body['role_id'];
    if (!empty(lmsActor()['is_admin']) && isset($body['is_admin'])) $data['is_admin'] = (int)$body['is_admin'];
    if (isset($body['password']) && strlen($body['password']) >= 6) {
        $data['password_hash'] = password_hash($body['password'], PASSWORD_BCRYPT);
    }
    if ((!empty(lmsActor()['is_admin']) || $id === $userId) && isset($body['is_manager'])) $data['is_manager'] = !empty($body['is_manager']) ? 1 : 0;
    if (!$data) json(['ok' => false, 'error' => 'Нет данных для обновления'], 400);

    if (isset($data['dept_id']) || isset($data['role_id'])) {
        $existing=Database::find('SELECT dept_id,role_id FROM users WHERE id=?',[$id]);
        $dept=(int)($data['dept_id'] ?? $existing['dept_id'] ?? 0);
        $role=(int)($data['role_id'] ?? $existing['role_id'] ?? 0);
        if ($role && !Database::find('SELECT id FROM roles WHERE id=? AND dept_id=?',[$role,$dept])) json(['ok'=>false,'error'=>'Должность не относится к выбранному отделу'],400);
    }
    $existingUser=Database::find('SELECT * FROM users WHERE id=?',[$id]);
    if (!$existingUser) json(['ok'=>false,'error'=>'Пользователь не найден'],404);
    $effectiveManager=$data['is_manager'] ?? $existingUser['is_manager'];
    $effectiveDept=array_key_exists('dept_id',$data) ? (int)$data['dept_id'] : (int)$existingUser['dept_id'];
    if ($effectiveDept && !Database::find('SELECT id FROM departments WHERE id=?',[$effectiveDept])) json(['ok'=>false,'error'=>'Отдел не найден'],400);
    $effectiveRole=array_key_exists('role_id',$data) ? (int)$data['role_id'] : (int)$existingUser['role_id'];
    if ($effectiveManager && !$effectiveDept) json(['ok'=>false,'error'=>'Выберите отдел руководителя'],400);
    if ($id===$userId && array_key_exists('is_manager',$data) && (!$effectiveDept || (!$effectiveManager && !$effectiveRole))) json(['ok'=>false,'error'=>'Выберите отдел и должность сотрудника'],400);
    if (array_key_exists('role_id',$data) && !$data['role_id']) $data['role_id']=null;
    $previous=Database::find('SELECT * FROM users WHERE id=?',[$id]);
    $changed=(array_key_exists('dept_id',$data) && (int)$data['dept_id']!==(int)$previous['dept_id']) || (array_key_exists('role_id',$data) && (int)$data['role_id']!==(int)$previous['role_id']);
    $pdo=Database::get();$pdo->beginTransaction();
    if ($changed) {
        Database::query('UPDATE result_publications SET is_current=0 WHERE user_id=?',[$id]);
        $data['onboarding_dept_id']=$previous['onboarding_dept_id'] ?? $previous['dept_id'];
        $data['onboarding_role_id']=$previous['onboarding_role_id'] ?? $previous['role_id'];
        Database::query('UPDATE skill_level_results SET is_current=0 WHERE user_id=?',[$id]);
        Database::query("UPDATE skill_level_attempts SET is_current=0,status=IF(status='active','expired',status) WHERE user_id=?",[$id]);
        Database::query('UPDATE test_results r JOIN tests t ON t.id=r.test_id SET r.is_current=0 WHERE r.user_id=? AND t.skill_level_id IS NOT NULL AND t.skill_level_id>0',[$id]);

    }
    Database::update('users', $data, 'id = ?', [$id]);
    $pdo->commit();
    json(['ok' => true]);
}

// DELETE — admin only
if ($method === 'DELETE') {
    if (!$isAdmin) json(['ok' => false, 'error' => 'Нет доступа'], 403);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id || $id === $userId) json(['ok' => false, 'error' => 'Нельзя удалить себя'], 400);
    Database::delete('users', 'id = ?', [$id]);
    json(['ok' => true]);
}

json(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
