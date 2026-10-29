<?php
require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/access.php';

/** Bring older installations up to the schema expected by tests and assignments. */
function ensureTestResultsPassedColumn(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    if (in_array($_SESSION['lms_schema_version'] ?? '', ['20261014-db-hotfix','20261014-question-count'],true)) return;
    try {
        if (!Database::find("SHOW COLUMNS FROM test_results LIKE 'passed'")) {
            Database::query('ALTER TABLE test_results ADD COLUMN passed TINYINT(1) NOT NULL DEFAULT 0 AFTER max_score');
        }
    } catch (Throwable $e) {
        error_log('[test_results.passed migration] ' . $e->getMessage());
    }
}

/** Mark the current level attempt completed once its quiz and every issued task are done. */
function finalizeHardSkillAttempt(int $userId, int $levelId): bool {
    $attempt = Database::find(
        "SELECT * FROM skill_level_attempts
         WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? AND status='active'
         ORDER BY id DESC LIMIT 1",
        [$userId, $levelId, (int)lmsActor()['dept_id'], (int)lmsActor()['role_id']]
    );
    if (!$attempt || strtotime($attempt['expires_at']) <= time()) return false;

    // A submitted quiz is retained for this role even if the task session had to restart.
    $quizPassed = (int)(Database::find(
        'SELECT COUNT(*) AS n FROM skill_level_results
         WHERE is_current=1 AND user_id=? AND skill_level_id=? AND dept_id=? AND role_id=?',
        [$userId, $levelId, (int)$attempt['dept_id'], (int)$attempt['role_id']]
    )['n'] ?? 0) > 0;
    if (!$quizPassed && (json_decode($attempt['question_ids'],true) ?: [])) return false;

    $assignmentIds = array_values(array_unique(array_map(
        'intval', json_decode($attempt['assignment_ids'], true) ?: []
    )));
    if ($assignmentIds) {
        $doneCount = (int)(Database::find(
            'SELECT COUNT(DISTINCT test_id) AS n FROM test_results
             WHERE is_current=1 AND user_id=? AND test_id IN (' . implode(',', $assignmentIds) . ')',
            [$userId]
        )['n'] ?? 0);
        if ($doneCount < count($assignmentIds)) return false;
    }

    $completedAt = date('Y-m-d H:i:s');
    $completionData = [
        'status' => 'completed',
        'completed_at' => $completedAt,
    ];
    $completionData['elapsed_seconds'] = max(0, strtotime($completedAt) - strtotime($attempt['started_at']));
    Database::update('skill_level_attempts', $completionData, 'id=?', [$attempt['id']]);
    return true;
}

/** Freeze server-saved task drafts at expiry and make them available for review. */
function freezeExpiredAssignmentDrafts(int $userId): void {
    $serverNow=date('Y-m-d H:i:s');
    if (!Database::find("SELECT id FROM skill_level_attempts WHERE user_id=? AND expires_at<=? AND status IN ('active','expired') LIMIT 1",[$userId,$serverNow])) return;
    $pdo=Database::get();
    $pdo->beginTransaction();
    try {
        $attempts=Database::findAll("SELECT * FROM skill_level_attempts WHERE user_id=? AND expires_at<=? AND status IN ('active','expired') FOR UPDATE",[$userId,$serverNow]);
        foreach ($attempts as $attempt) {
            // Let the browser deliver its final frozen snapshot before sealing an older draft.
            // This never extends the time allowed for editing or normal submission.
            if (time() < strtotime($attempt['expires_at'])+120 && !Database::find('SELECT attempt_id FROM skill_expiry_receipts WHERE attempt_id=?',[$attempt['id']])) continue;
            $draft=Database::find('SELECT * FROM skill_quiz_drafts WHERE attempt_id=?',[$attempt['id']]);
            if ($draft && !Database::find('SELECT id FROM skill_level_results WHERE user_id=? AND skill_level_id=? AND dept_id=? AND role_id=? AND completed_at>=? LIMIT 1',[$userId,$attempt['skill_level_id'],$attempt['dept_id'],$attempt['role_id'],$attempt['started_at']])) {
                $answers=json_decode($draft['answers_json'],true) ?: [];$own=json_decode($draft['own_answers_json'],true) ?: [];
                $results=[];$score=0;$max=0;$pending=false;$testId=0;
                foreach (json_decode($attempt['question_ids'],true) ?: [] as $qid) {
                    $q=Database::find('SELECT * FROM questions WHERE id=?',[(int)$qid]);if(!$q)continue;
                    if(!$testId)$testId=(int)$q['test_id'];
                    if ($q['type']==='open') {
                        $text=(string)($answers[$qid] ?? '');
                        $results[$qid]=['type'=>'open','answer'=>$text];
                        if(trim($text)!=='')$pending=true;else $results[$qid]['accepted']=false;
                    } else {
                        $max++;$correct=array_map('intval',array_column(Database::findAll('SELECT id FROM question_options WHERE question_id=? AND is_correct=1',[$qid]),'id'));sort($correct);
                        $answer=array_map('intval',$answers[$qid] ?? []);sort($answer);
                        $ok=(bool)$answer && $answer===$correct;if($ok)$score++;
                        $results[$qid]=['type'=>$q['type'],'answer'=>$answer,'correct_ids'=>$correct,'is_correct'=>$ok];
                    }
                    if (!empty($own[$qid]))$results[$qid]['own_answer']=$own[$qid];
                }
                if ($results) Database::insert('skill_level_results',[
                    'user_id'=>$userId,'skill_level_id'=>$attempt['skill_level_id'],'dept_id'=>$attempt['dept_id'],'role_id'=>$attempt['role_id'],
                    'test_id'=>$testId,'score'=>$score,'max_score'=>$max,'passed'=>0,'review_status'=>$pending?'pending':'completed',
                    'question_ids'=>$attempt['question_ids'],'answers_json'=>json_encode($results,JSON_UNESCAPED_UNICODE),
                    'completed_at'=>$attempt['expires_at'],'is_current'=>(int)$attempt['is_current'],
                ]);
            }

            $drafts=Database::findAll('SELECT d.* FROM assignment_drafts d JOIN tests t ON t.id=d.test_id WHERE d.attempt_id=? AND t.content_type=\'task\'',[$attempt['id']]);
            foreach ($drafts as $draft) {
                if (trim($draft['comment'])==='' || Database::find('SELECT id FROM test_results WHERE user_id=? AND test_id=? AND completed_at>=? LIMIT 1',[$userId,$draft['test_id'],$attempt['started_at']])) continue;
                Database::insert('test_results',[
                    'user_id'=>$userId,'test_id'=>$draft['test_id'],'score'=>0,'max_score'=>0,'passed'=>0,
                    'is_current'=>(int)$attempt['is_current'],'review_status'=>'pending',
                    'answers_json'=>json_encode(['comment'=>$draft['comment'],'expired'=>true],JSON_UNESCAPED_UNICODE),
                    'completed_at'=>$attempt['expires_at'],
                ]);
            }
            if ($attempt['status']==='active') Database::update('skill_level_attempts',['status'=>'expired'],'id=?',[$attempt['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {$pdo->rollBack();throw $e;}
}
