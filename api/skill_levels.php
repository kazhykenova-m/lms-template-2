<?php
require_once __DIR__.'/api_errors.php';
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../php_errors.log');
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/quiz_validation.php';
require_once __DIR__ . '/hard_skill_attempts.php';
require_once __DIR__ . '/skill_assignment_selection.php';
require_once __DIR__ . '/coins_bridge.php';
require_once __DIR__ . '/skill_access_policy.php';

session_name(SESSION_NAME);
session_start();
require_once __DIR__ . '/access.php';
ensureLmsSchema();
header('Content-Type: application/json; charset=utf-8');

function json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($code>=400 ? $data : lmsHideLearningGrades($data), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (empty($_SESSION['user_id'])) json(['ok' => false, 'error' => 'Не авторизован'], 401);
ensureTestResultsPassedColumn();
$userId  = (int)$_SESSION['user_id'];
if (!in_array($_GET['action'] ?? '', ['expire','draft'],true)) freezeExpiredAssignmentDrafts($userId);
$isAdmin = lmsStaff();
$method  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? '';

$body = [];
if (in_array($method, ['POST', 'PUT'])) {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
}

if ($method !== 'GET' && !in_array($action,['submit','expire','draft'],true) && empty(lmsActor()['is_admin'])) lmsDeny();

$user = Database::find('SELECT * FROM users WHERE id=?', [$userId]);

function hardSkillAttemptOrFail(int $userId, int $levelId, int $expectedAttemptId=0): array {
    $attempt = Database::find(
        'SELECT * FROM skill_level_attempts WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? ORDER BY id DESC LIMIT 1',
        [$userId, $levelId, (int)lmsActor()['dept_id'], (int)lmsActor()['role_id']]
    );
    if (!$attempt) json(['ok' => false, 'error' => 'Сначала начните прохождение уровня','refresh_attempt'=>true], 409);
    if ($expectedAttemptId>0 && $expectedAttemptId !== (int)$attempt['id']) json(['ok'=>false,'error'=>'Попытка изменилась в другой вкладке. Откройте текущую попытку.','refresh_attempt'=>true],409);
    if ($attempt['status'] === 'completed') json(['ok'=>false,'error'=>'Попытка уже завершена. Откройте её результаты.','refresh_attempt'=>true],409);
    if ($attempt['status'] !== 'active' || strtotime($attempt['expires_at']) <= time()) {
        if ($attempt['status'] === 'active') {
            Database::update('skill_level_attempts', ['status' => 'expired'], 'id=?', [$attempt['id']]);
        }
        json(['ok' => false, 'error' => 'Время прохождения уровня истекло', 'expired' => true], 408);
    }
    return $attempt;
}

if ($method==='GET' && $action==='visibility_levels') {
    if (!lmsStaff()) json(['ok'=>false,'error'=>'Вы вошли как сотрудник. Для панели нужны права администратора или руководителя.'],403);
    json(['ok'=>true,'levels'=>Database::findAll('SELECT id,title FROM skill_levels WHERE is_active=1 ORDER BY sort,id')]);
}

// Autosave while the attempt is active. Expiry has a separate grace-period route.
if ($method === 'POST' && $action === 'draft') {
    session_write_close();
    $pdo=Database::get(); $pdo->beginTransaction();
    $attempt=Database::find('SELECT * FROM skill_level_attempts WHERE id=? AND user_id=? AND is_current=1 FOR UPDATE',[(int)($body['attempt_id'] ?? 0),$userId]);
    if (!$attempt || (int)$attempt['dept_id']!==(int)$user['dept_id'] || (int)$attempt['role_id']!==(int)$user['role_id']) {$pdo->rollBack();json(['ok'=>false,'error'=>'Попытка изменилась'],409);}
    lmsRequireSkillLevel((int)$attempt['skill_level_id']);
    if ($attempt['status']!=='active' || strtotime($attempt['expires_at'])<=time()) {$pdo->rollBack();json(['ok'=>false,'error'=>'Время попытки истекло'],408);}
    $answers=$body['answers'] ?? []; $own=$body['own_answers'] ?? [];
    if (!is_array($answers) || !is_array($own)) {$pdo->rollBack();json(['ok'=>false,'error'=>'Некорректный черновик'],400);}
    $clean=[]; $cleanOwn=[];
    $issued=array_map('intval',json_decode($attempt['question_ids'],true) ?: []);
    $questions=$issued ? Database::findAll('SELECT * FROM questions WHERE id IN ('.implode(',',$issued).')') : [];
    $options=[];
    foreach ($issued ? Database::findAll('SELECT id,question_id FROM question_options WHERE question_id IN ('.implode(',',$issued).')') : [] as $option) $options[(int)$option['question_id']][]=(int)$option['id'];
    foreach ($questions as $q) {
        $qid=(int)$q['id'];$value=$answers[$qid] ?? null;
        if ($q['type']==='open' && is_string($value)) $clean[$qid]=lmsAnswerByteLimit($value,200000);
        elseif ($q['type']!=='open' && is_array($value) && $value && ($q['type']!=='single' || count($value)===1)) {
            $valid=true;
            foreach($value as $optionId) if(!is_numeric($optionId) || !in_array((int)$optionId,$options[$qid] ?? [],true)) {$valid=false;break;}
            if($valid)$clean[$qid]=array_values(array_unique(array_map('intval',$value)));
        }
        if (!empty($q['allow_own_answer']) && isset($own[$qid]) && is_string($own[$qid])) $cleanOwn[$qid]=lmsAnswerByteLimit($own[$qid],200000);
    }
    $encoded=json_encode($clean,JSON_UNESCAPED_UNICODE); $encodedOwn=json_encode($cleanOwn,JSON_UNESCAPED_UNICODE);
    if (strlen($encoded)+strlen($encodedOwn)>2000000) {$pdo->rollBack();json(['ok'=>false,'error'=>'Черновик теста слишком большой'],413);}
    Database::query('INSERT INTO skill_quiz_drafts (attempt_id,answers_json,own_answers_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE answers_json=VALUES(answers_json),own_answers_json=VALUES(own_answers_json),updated_at=NOW()',[$attempt['id'],$encoded,$encodedOwn]);
    $pdo->commit();echo json_encode(['ok'=>true]);exit;
}

// Save partial answers only for the issued, active attempt.
if ($method === 'POST' && $action === 'expire') {
    $levelId=(int)($body['level_id'] ?? 0);
    lmsRequireSkillLevel($levelId);
    $pdo=Database::get();$pdo->beginTransaction();
    try {
        $attempt=Database::find('SELECT * FROM skill_level_attempts WHERE id=? AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? AND is_current=1 FOR UPDATE',[(int)($body['attempt_id'] ?? 0),$userId,$levelId,(int)$user['dept_id'],(int)$user['role_id']]);
        if (!$attempt || !in_array($attempt['status'],['active','expired'],true)) {$pdo->rollBack();json(['ok'=>false,'error'=>'Попытка недоступна'],409);}
        if (Database::find('SELECT attempt_id FROM skill_expiry_receipts WHERE attempt_id=?',[$attempt['id']])) {$pdo->commit();freezeExpiredAssignmentDrafts($userId);json(['ok'=>true]);}
        $deadline=strtotime($attempt['expires_at']);
        // Short delivery window for the frozen browser snapshot, never a timer extension.
        if(time()<$deadline || time()>$deadline+120) {$pdo->rollBack();json(['ok'=>false,'error'=>'Фиксация доступна при окончании таймера и в течение двух минут после него'],409);}

        $answers=$body['answers'] ?? []; $own=$body['own_answers'] ?? [];
        if (!is_array($answers) || !is_array($own)) {$pdo->rollBack();json(['ok'=>false],400);}
        $ids=array_map('intval',json_decode($attempt['question_ids'],true) ?: []);
        $clean=[];$cleanOwn=[];
        foreach ($ids as $qid) {
            $q=Database::find('SELECT * FROM questions WHERE id=?',[$qid]);if(!$q)continue;
            $answer=$answers[$qid] ?? null;
            if ($q['type']==='open' && is_string($answer)) $clean[$qid]=lmsAnswerByteLimit($answer,200000);
            elseif (is_array($answer) && $answer && !quizAnswerError([$q],[$qid=>$answer])) $clean[$qid]=array_values(array_unique(array_map('intval',$answer)));
            if (!empty($q['allow_own_answer']) && is_string($own[$qid] ?? null)) $cleanOwn[$qid]=lmsAnswerByteLimit($own[$qid],200000);
        }
        Database::query('INSERT INTO skill_quiz_drafts (attempt_id,answers_json,own_answers_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE answers_json=VALUES(answers_json),own_answers_json=VALUES(own_answers_json),updated_at=NOW()',[$attempt['id'],json_encode($clean,JSON_UNESCAPED_UNICODE),json_encode($cleanOwn,JSON_UNESCAPED_UNICODE)]);
        $assignments=$body['assignments'] ?? [];
        if(!is_array($assignments)) {$pdo->rollBack();json(['ok'=>false],400);}
        foreach (array_map('intval',json_decode($attempt['assignment_ids'],true) ?: []) as $testId) {
            if(!is_string($assignments[$testId] ?? null))continue;
            $task=Database::find("SELECT id FROM tests WHERE id=? AND content_type='task'",[$testId]);if(!$task)continue;
            if(Database::find('SELECT id FROM test_results WHERE user_id=? AND test_id=? AND completed_at>=? LIMIT 1',[$userId,$testId,$attempt['started_at']]))continue;
            Database::query('INSERT INTO assignment_drafts (attempt_id,test_id,comment) VALUES (?,?,?) ON DUPLICATE KEY UPDATE comment=VALUES(comment),updated_at=NOW()',[$attempt['id'],$testId,lmsAnswerByteLimit($assignments[$testId],200000)]);
        }
        Database::insert('skill_expiry_receipts',['attempt_id'=>$attempt['id']]);
        $pdo->commit();freezeExpiredAssignmentDrafts($userId);
        if ($ids && !Database::find('SELECT id FROM skill_level_results WHERE user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? AND completed_at>=? LIMIT 1',[$userId,$levelId,(int)$user['dept_id'],(int)$user['role_id'],$attempt['started_at']])) {
            json(['ok'=>false,'error'=>'Черновик получен, но результат не создан. Обновите api/hard_skill_attempts.php и проверьте журнал PHP'],500);
        }
        json(['ok'=>true]);
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

// ── GET: список уровней с доступностью для пользователя ──────────
if ($method === 'GET' && !$action) {
    $levels = Database::findAll('SELECT * FROM skill_levels WHERE is_active=1 ORDER BY sort');

    if (empty($user['is_admin'])) $levels=array_values(array_filter($levels, fn($lv)=> !( !empty($user['is_manager']) && empty($user['role_id'])) && lmsSkillLevelVisible($user,(int)$lv['id'])));
    foreach ($levels as &$lv) {
        // Доступен ли уровень (по коинам)
        $lv['available'] = !HARD_SKILL_COIN_GATE_ENABLED || (int)($user['coins'] ?? 0) >= (int)$lv['coins_required'];

        // Лучший результат пользователя по этому уровню
        $best = Database::find('SELECT MAX(score) as best_score, MAX(max_score) as best_max, MAX(passed) as ever_passed
            FROM skill_level_results WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=?', [$userId, $lv['id'], (int)$user['dept_id'], (int)$user['role_id']]);
        $lv['best_score']   = (int)($best['best_score'] ?? 0);
        $lv['best_max']     = (int)($best['best_max'] ?? 0);
        $lv['ever_passed'] = Database::find('SELECT id FROM skill_level_results WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? LIMIT 1', [$userId,$lv['id'],(int)$user['dept_id'],(int)$user['role_id']]) !== null;
        $latestResult = Database::find('SELECT id,review_status FROM skill_level_results WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? ORDER BY id DESC LIMIT 1', [$userId,$lv['id'],(int)$user['dept_id'],(int)$user['role_id']]);
        $lv['review_status'] = $latestResult['review_status'] ?? null;
        $lv['result_id'] = (int)($latestResult['id'] ?? 0);
        $lv['attempts']     = (int)(Database::find('SELECT COUNT(*) as n FROM skill_level_results WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=?', [$userId, $lv['id'], (int)$user['dept_id'], (int)$user['role_id']])['n'] ?? 0);

        // Количество вопросов в банке для этого уровня — тесты своего отдела/должности
        // и общие (для всех) не взаимоисключающие, берём вместе
        $userDeptId = (int)($user['dept_id'] ?? 0);
        $userRoleId = (int)($user['role_id'] ?? 0);
        $deptTests = Database::findAll(
            'SELECT t.id, t.content_type, t.dept_id, t.role_id, t.questions_to_issue FROM tests t WHERE t.skill_level_id=?
               AND (t.dept_id=? OR t.dept_id IS NULL OR t.dept_id=0)
               AND (t.role_id=? OR t.role_id IS NULL OR t.role_id=0)
               AND t.is_active=1',
            [$lv['id'], $userDeptId, $userRoleId]
        );
        $quizIds = array_column(array_filter($deptTests, function($t){ return $t['content_type'] === 'quiz'; }), 'id');
        $assignmentIds = array_column(skillRoleAssignments($deptTests, $userDeptId, $userRoleId), 'id');

        $lv['questions_in_bank']     = 0;
        $lv['questions_count'] = 0;
        $lv['assignments_available_count'] = count($assignmentIds);
        $lv['assignments_required_count'] = min(1, count($assignmentIds));
        $lv['assignments_done_count'] = 0;

        if ($quizIds) {
            $bankCounts = Database::findAll(
                'SELECT test_id, COUNT(*) as n FROM questions WHERE test_id IN (' . implode(',', array_map('intval', $quizIds)) . ') GROUP BY test_id'
            );
            $countsByTest = [];
            foreach ($bankCounts as $row) {
                $countsByTest[(int)$row['test_id']] = (int)$row['n'];
                $lv['questions_in_bank'] += (int)$row['n'];
            }
            foreach ($deptTests as $test) {
                if ($test['content_type'] !== 'quiz') continue;
                $lv['questions_count'] += min(
                    max(1, (int)($test['questions_to_issue'] ?? 10)),
                    $countsByTest[(int)$test['id']] ?? 0
                );
            }
        }
        if ($assignmentIds) {
            $lv['assignments_done_count'] = (int)(Database::find(
                'SELECT COUNT(DISTINCT test_id) as n FROM test_results WHERE is_current=1 AND user_id=? AND test_id IN (' . implode(',', $assignmentIds) . ')',
                [$userId]
            )['n'] ?? 0);
        }

        // Уровень закрыт после теста и одного задания для этой должности.
        $approvedAssignments=$assignmentIds ? (int)(Database::find('SELECT COUNT(DISTINCT test_id) AS n FROM test_results WHERE is_current=1 AND user_id=? AND passed=1 AND test_id IN ('.implode(',',array_map('intval',$assignmentIds)).')',[$userId])['n'] ?? 0) : 0;
        $lv['evaluated_passed']=($lv['questions_in_bank']===0 || !empty($best['ever_passed'])) && $approvedAssignments >= $lv['assignments_required_count'] && ($lv['questions_in_bank']>0 || $lv['assignments_required_count']>0);
        $lv['fully_done'] = ($lv['questions_in_bank'] > 0 || $lv['assignments_required_count'] > 0) && ($lv['ever_passed'] || $lv['questions_in_bank'] === 0) && $lv['assignments_done_count'] >= $lv['assignments_required_count'];
        $activeAttempt = Database::find("SELECT started_at, expires_at FROM skill_level_attempts
            WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id={$userDeptId} AND role_id={$userRoleId} AND status='active' AND expires_at>NOW()
            ORDER BY id DESC LIMIT 1", [$userId, $lv['id']]);
        $usedAttempt = Database::find('SELECT id FROM skill_level_attempts WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? LIMIT 1', [$userId,$lv['id'],$userDeptId,$userRoleId]);
        $lv['attempt_used'] = !$activeAttempt && ($usedAttempt !== null || $lv['ever_passed']);
        $lv['has_active_attempt'] = (bool)$activeAttempt;
        $lv['active_remaining_seconds'] = $activeAttempt ? max(0, strtotime($activeAttempt['expires_at']) - time()) : 0;
        if (!empty(lmsActor()['is_admin'])) {
            $lv['linked_tests'] = Database::findAll(
                "SELECT id, title FROM tests WHERE skill_level_id=? AND content_type='quiz' ORDER BY id", [$lv['id']]
            );
        }
    }
    unset($lv);

    json(['ok' => true, 'levels' => $levels, 'user_coins' => (int)($user['coins'] ?? 0)]);
}

// ── GET: получить тест для уровня (рандомные вопросы) ────────────
// Only the owner of a completed attempt can see this quiz's answer count.
if ($method === 'GET' && $action === 'quiz_summary') {
    $attemptId=(int)($_GET['attempt_id'] ?? 0);
    $attempt=Database::find('SELECT * FROM skill_level_attempts WHERE id=? AND user_id=? AND dept_id=? AND role_id=? AND is_current=1',[$attemptId,$userId,(int)$user['dept_id'],(int)$user['role_id']]);
    if (!$attempt || $attempt['status']!=='completed') json(['ok'=>false,'error'=>'Сначала заверши все этапы уровня'],409);
    $result=Database::find('SELECT answers_json FROM skill_level_results WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? AND completed_at>=? ORDER BY id DESC LIMIT 1',[$userId,$attempt['skill_level_id'],(int)$user['dept_id'],(int)$user['role_id'],$attempt['started_at']]);
    $correct=0;$total=0;$pending=0;
    foreach (json_decode($result['answers_json'] ?? '',true) ?: [] as $answer) {
        if (!is_array($answer) || !in_array($answer['type'] ?? '',['single','multi','open'],true)) continue;
        $total++;
        if ($answer['type']==='open') {
            if (!is_bool($answer['accepted'] ?? null)) $pending++;
            elseif ($answer['accepted']) $correct++;
        } elseif (!empty($answer['is_correct'])) $correct++;
    }
    json(['ok'=>true,'correct_count'=>$correct,'total_count'=>$total,'pending_count'=>$pending]);
}

if ($method === 'GET' && $action === 'get_test') {
    $levelId = (int)($_GET['level_id'] ?? 0);
    lmsRequireSkillLevel($levelId);
    if (!$levelId) json(['ok' => false, 'error' => 'level_id обязателен'], 400);

    $level = Database::find('SELECT * FROM skill_levels WHERE id=? AND is_active=1', [$levelId]);
    if (!$level) json(['ok' => false, 'error' => 'Уровень не найден'], 404);

    // Проверка доступа
    if (HARD_SKILL_COIN_GATE_ENABLED && (int)($user['coins'] ?? 0) < (int)$level['coins_required']) {
        json(['ok' => false, 'error' => 'Недостаточно коинов для этого уровня',
              'required' => $level['coins_required'], 'current' => (int)$user['coins']], 403);
    }

    // Собираем тесты этого уровня для отдела пользователя.
    // Берём ОДНИМ запросом и тесты своего отдела, и общие (для всех отделов) —
    // они не взаимоисключающие: например, тест хардскилла может быть привязан
    // к конкретному отделу, а задание к тому же уровню — сделано «для всех отделов».
    $deptId = (int)($user['dept_id'] ?? 0);
    $roleId = (int)($user['role_id'] ?? 0);
    $tests = Database::findAll(
        'SELECT * FROM tests
         WHERE skill_level_id=?
           AND (dept_id=? OR dept_id IS NULL OR dept_id=0)
           AND (role_id=? OR role_id IS NULL OR role_id=0)
           AND is_active=1',
        [$levelId, $deptId, $roleId]
    );

    if (!$tests) {
        json(['ok' => false, 'error' => 'Тесты для этого уровня не добавлены. Создай тест в разделе «Тесты» и выбери уровень хардскилла.'], 404);
    }

    $quizTests = array_values(array_filter($tests, function($t){ return $t['content_type'] === 'quiz'; }));
    $assignmentTests = skillRoleAssignments($tests, $deptId, $roleId);

    // Собираем все активные вопросы, подходящие текущему отделу и должности.
    $quizIds = array_map('intval', array_column($quizTests, 'id'));
    $allQuestions = $quizIds ? Database::findAll('SELECT q.* FROM questions q WHERE q.test_id IN (' . implode(',', $quizIds) . ') ORDER BY q.sort, q.id') : [];
    if (!$allQuestions && !$assignmentTests) json(['ok'=>false,'error'=>'Для этой должности пока нет вопросов или заданий'], 404);

    $attemptPdo=Database::get();$attemptPdo->beginTransaction();
    Database::find('SELECT id FROM users WHERE id=? FOR UPDATE',[$userId]);
    $latestAttempt = Database::find(
        'SELECT * FROM skill_level_attempts WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? ORDER BY id DESC LIMIT 1',
        [$userId, $levelId, $deptId, $roleId]
    );
    if ($latestAttempt && $latestAttempt['status'] === 'active' && strtotime($latestAttempt['expires_at']) <= time()) {
        Database::update('skill_level_attempts', ['status' => 'expired'], 'id=?', [$latestAttempt['id']]);
        $latestAttempt['status'] = 'expired';
    }
    $quizResult = Database::find('SELECT * FROM skill_level_results WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? AND completed_at>=? ORDER BY id DESC LIMIT 1', [$userId,$levelId,$deptId,$roleId,$latestAttempt['started_at'] ?? '9999-12-31 23:59:59']);
    // One attempt per current assignment. Only staff can reopen an unanswered task.
    // Legacy results also consume the attempt, even if its attempt row is missing.
    if (!$latestAttempt && Database::find('SELECT id FROM skill_level_results WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? LIMIT 1', [$userId,$levelId,$deptId,$roleId])) {
        json(['ok'=>false,'error'=>'Тест уже пройден. Результаты появятся в разделе «Результаты» после публикации руководителем.'],409);
    }
    if ($latestAttempt && $latestAttempt['status'] !== 'active') {
        json(['ok'=>false,'error'=>'Попытка завершена. Повторное прохождение недоступно. Результаты появятся в разделе «Результаты» после публикации руководителем.'],409);
    }
    if (!$latestAttempt) {
        // Each matching test has its own quota; existing attempts keep their IDs.
        $questionIds = [];
        $questionsByTest = [];
        foreach ($allQuestions as $question) $questionsByTest[(int)$question['test_id']][] = $question;
        foreach ($quizTests as $quizTest) {
            $bank = $questionsByTest[(int)$quizTest['id']] ?? [];
            shuffle($bank);
            $count = max(1, (int)($quizTest['questions_to_issue'] ?? 10));
            $questionIds = array_merge($questionIds, array_column(array_slice($bank, 0, $count), 'id'));
        }
        shuffle($questionIds);
        $assignmentIds = skillRandomAssignmentIds($assignmentTests);
        $startedAt = date('Y-m-d H:i:s');
        $duration = max(1, (int)($level['duration_minutes'] ?? 60));
        $expiresAt = date('Y-m-d H:i:s', time() + $duration * 60);
        $attemptId = Database::insert('skill_level_attempts', [
            'user_id' => $userId, 'skill_level_id' => $levelId, 'dept_id'=>$deptId, 'role_id'=>$roleId,
            'question_ids' => json_encode($questionIds), 'assignment_ids' => json_encode($assignmentIds),
            'started_at' => $startedAt, 'expires_at' => $expiresAt, 'status' => 'active',
        ]);
        $latestAttempt = Database::find('SELECT * FROM skill_level_attempts WHERE id=?', [$attemptId]);
        $quizResult = null;
    }

    $attemptPdo->commit();

    $issuedQuestionIds = array_map('intval', json_decode($latestAttempt['question_ids'], true) ?: []);
    $issuedAssignmentIds = array_map('intval', json_decode($latestAttempt['assignment_ids'], true) ?: []);
    $selected = [];
    if ($issuedQuestionIds) {
        $questionMap = [];
        foreach ($allQuestions as $question) $questionMap[(int)$question['id']] = $question;
        foreach ($issuedQuestionIds as $questionId) if (isset($questionMap[$questionId])) $selected[] = $questionMap[$questionId];

        // Загружаем варианты ответов
        foreach ($selected as &$q) {
            $q['options'] = $q['type'] !== 'open'
                ? Database::findAll('SELECT id, option_text, is_correct FROM question_options WHERE question_id=? ORDER BY RAND()', [$q['id']])
                : [];
        }
        unset($q);
    }

    if (!$quizResult && count($selected) !== count($issuedQuestionIds)) {
        Database::update('skill_level_attempts',['status'=>'expired'],'id=?',[$latestAttempt['id']]);
        json(['ok'=>false,'error'=>'Содержание теста изменилось. Обратитесь к администратору.'],409);
    }
    // Помечаем задания, которые пользователь уже выполнил
    $assignmentTests = array_values(array_filter($assignmentTests, function($assignment) use ($issuedAssignmentIds) {
        return in_array((int)$assignment['id'], $issuedAssignmentIds, true);
    }));
    if ($assignmentTests) {
        $assignmentIds = array_column($assignmentTests, 'id');
        $doneRows = Database::findAll(
            'SELECT test_id, 1 as done FROM test_results WHERE is_current=1 AND user_id=? AND test_id IN (' . implode(',', $assignmentIds) . ') GROUP BY test_id',
            [$userId]
        );
        $doneMap = [];
        foreach ($doneRows as $r) $doneMap[(int)$r['test_id']] = (bool)$r['done'];
        foreach ($assignmentTests as &$a) {
            $a['done'] = $doneMap[(int)$a['id']] ?? false;
            $r=Database::find('SELECT passed,review_status,answers_json FROM test_results WHERE is_current=1 AND user_id=? AND test_id=? ORDER BY id DESC LIMIT 1',[$userId,$a['id']]);
            $saved=json_decode($r['answers_json'] ?? '',true) ?: [];
            $a['verdict']=$saved['verdict']['accepted'] ?? null;
        }
        unset($a);
    }

    if (!$allQuestions && !$assignmentTests) {
        json(['ok' => false, 'error' => 'Тесты для этого уровня не добавлены. Создай тест в разделе «Тесты» и выбери уровень хардскилла.'], 404);
    }

    json([
        'ok'            => true,
        'level'         => $level,
        'questions'     => $selected,
        'total_in_bank' => count($allQuestions),
        'assignments'   => $assignmentTests,
        'attempt_id'    => (int)$latestAttempt['id'],
        'quiz_draft' => Database::find('SELECT answers_json,own_answers_json FROM skill_quiz_drafts WHERE attempt_id=?',[$latestAttempt['id']]),
        'started_at'    => $latestAttempt['started_at'],
        'expires_at'    => $latestAttempt['expires_at'],
        'quiz_result' => $quizResult ? array_intersect_key($quizResult,array_flip(['id','score','max_score','passed','review_status'])) : null,
        'attempt_completed' => $latestAttempt['status'] === 'completed',
        'remaining_seconds' => max(0, strtotime($latestAttempt['expires_at']) - time()),
    ]);
}

// ── POST: сдать тест уровня ───────────────────────────────────────
if ($method === 'POST' && $action === 'submit') {
    $levelId  = (int)($body['level_id'] ?? 0);
    $testId   = (int)($body['test_id'] ?? 0);
    $answers  = $body['answers'] ?? [];
    $ownAnswers = $body['own_answers'] ?? [];
    $questionIds = $body['question_ids'] ?? [];

    lmsRequireSkillLevel($levelId);
    $level = Database::find('SELECT * FROM skill_levels WHERE id=?', [$levelId]);
    if (!$level) json(['ok' => false, 'error' => 'Уровень не найден'], 404);
    $attempt = hardSkillAttemptOrFail($userId, $levelId, (int)($body['attempt_id'] ?? 0));

    if (Database::find('SELECT id FROM skill_level_results WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? AND completed_at>=? LIMIT 1', [$userId,$levelId,(int)$user['dept_id'],(int)$user['role_id'],$attempt['started_at']])) json(['ok'=>false,'error'=>'Тест уже отправлен. Перейдите к заданиям','already_submitted'=>true],409);

    // Загружаем только те вопросы что были выданы пользователю
    if (!$questionIds) json(['ok' => false, 'error' => 'Список вопросов пуст'], 400);
    $issuedQuestionIds = array_map('intval', json_decode($attempt['question_ids'], true) ?: []);
    $submittedQuestionIds = array_values(array_unique(array_map('intval', $questionIds)));
    sort($issuedQuestionIds); sort($submittedQuestionIds);
    if ($issuedQuestionIds !== $submittedQuestionIds) {
        json(['ok' => false, 'error' => 'Список вопросов не совпадает с текущей попыткой. Откройте её заново.','refresh_attempt'=>true], 409);
    }

    $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
    $questions = Database::findAll("SELECT * FROM questions WHERE id IN ($placeholders)", $questionIds);

    if ($error=quizAnswerError($questions,$answers)) json(['ok'=>false,'error'=>$error],400);
    $score = 0; $maxScore = 0; $allCorrect = true; $results = [];

    foreach ($questions as $q) {
        if ($q['type'] === 'open') {
            $results[$q['id']] = ['type' => 'open', 'answer' => $answers[$q['id']] ?? ''];
            continue;
        }
        $maxScore++;
        $correctIds = array_column(
            Database::findAll('SELECT id FROM question_options WHERE question_id=? AND is_correct=1', [$q['id']]),
            'id'
        );
        $correctIds = array_map('intval', $correctIds);
        sort($correctIds);

        $ua = $answers[$q['id']] ?? [];
        if (!is_array($ua)) $ua = [$ua];
        $ua = array_map('intval', $ua);
        sort($ua);

        $isCorrect = $correctIds === $ua;
        if ($isCorrect) $score++; else $allCorrect = false;
        $results[$q['id']] = ['type' => $q['type'], 'answer' => $ua, 'correct_ids' => $correctIds, 'is_correct' => $isCorrect];

        // Свой вариант ответа (если вопрос это разрешает) — не влияет на баллы,
        // сохраняется только для просмотра админом
        if (!empty($q['allow_own_answer'])) {
            $ownAnswer = trim($ownAnswers[$q['id']] ?? '');
            if ($ownAnswer !== '') $results[$q['id']]['own_answer'] = $ownAnswer;
        }
    }

    $require100 = defined('TEST_REQUIRE_100') ? TEST_REQUIRE_100 : true;
    $hasOpen=count(array_filter($results,fn($a)=>$a['type']==='open'))>0;
    $passed=!$hasOpen && $maxScore>0 && ($require100 ? $allCorrect : $score>0);

    $resolvedTestId = (int)($questions[0]['test_id'] ?? 0);

    // Сохраняем результат
    Database::insert('skill_level_results', [
        'user_id'        => $userId,
        'skill_level_id' => $levelId,
        'dept_id' => (int)$user['dept_id'], 'role_id' => (int)$user['role_id'],
        'review_status' => count(array_filter($results, fn($a)=>$a['type']==='open')) ? 'pending' : 'completed',
        'test_id'        => $resolvedTestId,
        'score'          => $score,
        'max_score'      => $maxScore,
        'passed'         => $passed ? 1 : 0,
        'question_ids'   => json_encode($questionIds),
        'answers_json'   => json_encode($results, JSON_UNESCAPED_UNICODE),
        'completed_at'   => date('Y-m-d H:i:s'),
    ]);
    $attemptCompleted = finalizeHardSkillAttempt($userId, $levelId);

    $coinsEarned=0;$coinsAlreadyEarned=false;$merchSynced=null;$currentCoins=0;

    // Проверяем ачивки за уровень (выдаём при первой сдаче)
    $newAchs = [];
    if ($passed) {
        try {
            if (!class_exists('AchievementEngine')) require_once __DIR__ . '/achievements.php';
            AchievementEngine::syncSkillLevelAchievements();
            $newAchs = AchievementEngine::check($userId, 'skill_level', [
                'skill_level_slug' => $level['slug'],
                'new_coins' => (int)(Database::find('SELECT coins FROM users WHERE id=?', [$userId])['coins'] ?? 0),
            ]);
        } catch (\Throwable $e) { error_log('[SkillLevel ach] ' . $e->getMessage()); }
    }

    json([
        'ok'              => true,
        'review_status' => count(array_filter($results, fn($a)=>is_array($a) && ($a['type'] ?? '')==='open')) ? 'pending' : 'completed',
        'passed'          => $passed,
        'score'           => $score,
        'max_score'       => $maxScore,
        'coins_earned'    => $coinsEarned,
        'total_coins'     => $currentCoins,
        'coins_already_earned' => $coinsAlreadyEarned,
        'merch_synced'    => $merchSynced,
        'attempt_completed'=> $attemptCompleted,
        'new_achievements'=> $newAchs,
        'results'         => $results,
    ]);
}

// ── Admin CRUD уровней ────────────────────────────────────────────
if (empty(lmsActor()['is_admin'])) json(['ok' => false, 'error' => 'Нет доступа'], 403);

if ($method === 'POST' && $action === 'save_level') {
    $id = (int)($body['id'] ?? 0);
    $existingLevel = $id ? Database::find('SELECT duration_minutes FROM skill_levels WHERE id=?', [$id]) : null;
    $durationMinutes = filter_var($body['duration_minutes'] ?? ($existingLevel['duration_minutes'] ?? 60), FILTER_VALIDATE_INT);
    if ($durationMinutes === false || $durationMinutes < 1 || $durationMinutes > 35791394) {
        json(['ok' => false, 'error' => 'Время прохождения должно быть целым положительным числом минут'], 400);
    }
    $data = [
        'title'           => trim($body['title'] ?? ''),
        'description'     => trim($body['description'] ?? ''),
        'coins_required'  => 0,
        'questions_count' => 10,
        'duration_minutes' => $durationMinutes,
        'assignments_count' => 1,
        'sort'            => (int)($body['sort'] ?? 1),
        'is_active'       => (int)($body['is_active'] ?? 1),
    ];
    if (!$data['title']) json(['ok' => false, 'error' => 'Название обязательно'], 400);

    if ($id) {
        Database::update('skill_levels', $data, 'id=?', [$id]);
        json(['ok' => true, 'id' => $id]);
    } else {
        // Транслитерация без intl extension
        $title = $data['title'];
        $tr = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya','А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ж'=>'Zh','З'=>'Z','И'=>'I','К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F','Х'=>'Kh','Ц'=>'Ts','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch','Э'=>'E','Ю'=>'Yu','Я'=>'Ya'];
        $slug = strtr($title, $tr);
        $data['slug'] = preg_replace('/[^a-z0-9_]/', '_', strtolower($slug));
        $newId = Database::insert('skill_levels', $data);
        json(['ok' => true, 'id' => $newId]);
    }
}

if ($method === 'POST' && $action === 'delete_level') {
    $id = (int)($_GET['id'] ?? 0);
    Database::delete('skill_levels', 'id=?', [$id]);
    json(['ok' => true]);
}

json(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
