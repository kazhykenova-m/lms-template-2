<?php
require_once __DIR__ . '/../db/Database.php';

// Cache schema verification across browser sessions and serialize migrations.
function ensureLmsSchema(): void {
    static $done=false;
    if($done)return;
    $version='template-20261029-v1';
    $cache=sys_get_temp_dir().'/lms-schema-'.hash('sha256',__DIR__.DB_HOST.DB_NAME.$version);
    $fresh=static fn()=>is_file($cache) && filemtime($cache)>time()-86400;
    if($fresh()) {$_SESSION['lms_schema_version']=$version;$_SESSION['lms_schema_checked']=time();$done=true;return;}
    $lock=@fopen($cache.'.lock','c');
    if($lock) {
        $deadline=microtime(true)+5;
        while(!flock($lock,LOCK_EX|LOCK_NB)) {
            if(microtime(true)>$deadline) {
                fclose($lock);
                if(PHP_SAPI==='cli')throw new RuntimeException('Schema migration is busy');
                http_response_code(503);header('Content-Type: application/json; charset=utf-8');header('Retry-After: 10');
                echo json_encode(['ok'=>false,'error'=>'Подготовка базы ещё выполняется. Повторите через 10 секунд.'],JSON_UNESCAPED_UNICODE);exit;
            }
            usleep(50000);
        }
    }
    try {
        clearstatcache(true,$cache);
        if(!$fresh()) {
            // Do not let a per-session cache certify a missing shared marker.
            unset($_SESSION['lms_schema_version']);
            ensureLmsSchemaUncached();
            @file_put_contents($cache,$version);
        }
        $_SESSION['lms_schema_version']=$version;$_SESSION['lms_schema_checked']=time();$done=true;
    } finally {if($lock){flock($lock,LOCK_UN);fclose($lock);}}
}

function ensureLmsSchemaUncached(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (($_SESSION['lms_schema_version'] ?? '')==='20261014-question-count' && (int)($_SESSION['lms_schema_checked'] ?? 0)>time()-300) return;
    Database::query("CREATE TABLE IF NOT EXISTS skill_expiry_receipts (attempt_id INT PRIMARY KEY,received_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    Database::query("CREATE TABLE IF NOT EXISTS skill_quiz_drafts (attempt_id INT PRIMARY KEY,answers_json LONGTEXT NOT NULL,own_answers_json LONGTEXT NOT NULL,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    Database::query("CREATE TABLE IF NOT EXISTS assignment_drafts (attempt_id INT NOT NULL,test_id INT NOT NULL,comment LONGTEXT,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(attempt_id,test_id)) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    Database::query("CREATE TABLE IF NOT EXISTS result_publications (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,published_by INT NOT NULL,published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,results_json LONGTEXT NOT NULL,INDEX user_publications(user_id,id)) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $type=Database::find("SHOW COLUMNS FROM tests LIKE 'content_type'");
    if ($type && strpos($type['Type'],"'task'")===false) Database::query("ALTER TABLE tests MODIFY COLUMN content_type ENUM('quiz','video','task') DEFAULT 'quiz'");
    Database::query("UPDATE tests SET content_type='task' WHERE content_type='' OR content_type IS NULL");
    $columns = [
        'tests' => ['questions_to_issue'=>'INT NOT NULL DEFAULT 10'],
        'achievements' => ['icon_svg'=>'TEXT DEFAULT NULL'],
        'result_publications' => ['is_current'=>'TINYINT(1) NOT NULL DEFAULT 1'],
        'users' => ['visible_skill_level_ids'=>'TEXT DEFAULT NULL', 'assigned_skill_level_id'=>'INT DEFAULT NULL', 'is_manager' => 'TINYINT(1) NOT NULL DEFAULT 0', 'onboarding_dept_id'=>'INT DEFAULT NULL', 'onboarding_role_id'=>'INT DEFAULT NULL'],
        'skill_level_attempts' => ['elapsed_seconds'=>'INT DEFAULT NULL', 'is_current'=>'TINYINT(1) NOT NULL DEFAULT 1', 'dept_id' => 'INT DEFAULT NULL', 'role_id' => 'INT DEFAULT NULL'],
        'skill_level_results' => ['is_current'=>'TINYINT(1) NOT NULL DEFAULT 1', 'dept_id' => 'INT DEFAULT NULL', 'role_id' => 'INT DEFAULT NULL', 'review_status' => "VARCHAR(20) NOT NULL DEFAULT 'completed'"],
        'test_results' => ['passed'=>'TINYINT(1) NOT NULL DEFAULT 0', 'is_current'=>'TINYINT(1) NOT NULL DEFAULT 1', 'review_status' => "VARCHAR(20) NOT NULL DEFAULT 'completed'"],
    ];
    foreach ($columns as $table => $fields) {
        foreach ($fields as $field => $definition) {
            if (!Database::find("SHOW COLUMNS FROM `$table` LIKE '$field'")) {
                Database::query("ALTER TABLE `$table` ADD COLUMN `$field` $definition");
                if ($field === 'review_status') {
                    foreach (Database::findAll("SELECT id,answers_json FROM `$table`") as $result) {
                        $answers = json_decode($result['answers_json'] ?? '', true) ?: [];
                        foreach ($answers as $answer) if (is_array($answer) && ($answer['type'] ?? '') === 'open') {
                            Database::update($table, ['review_status'=>'pending'], 'id=?', [$result['id']]); break;
                        }
                    }
                }
                if ($table === 'skill_level_results' && $field === 'role_id') {
                    Database::query('UPDATE skill_level_results r JOIN tests t ON t.id=r.test_id SET r.dept_id=COALESCE(t.dept_id,0), r.role_id=COALESCE(t.role_id,0)');
                }
                if ($table === 'skill_level_attempts' && $field === 'role_id') {
                    Database::query("UPDATE skill_level_attempts a JOIN questions q ON q.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(a.question_ids,'$[0]')) AS UNSIGNED) JOIN tests t ON t.id=q.test_id SET a.dept_id=COALESCE(t.dept_id,0), a.role_id=COALESCE(t.role_id,0)");
                }

            }
        }
    }
    $_SESSION['lms_schema_version']='20261014-question-count';
    $_SESSION['lms_schema_checked']=time();
}
function lmsActor(): array {
    static $actor;
    if ($actor === null) $actor = Database::find('SELECT * FROM users WHERE id=?', [(int)($_SESSION['user_id'] ?? 0)]) ?: [];
    return $actor;
}
function lmsStaff(): bool {
    $u = lmsActor();
    return !empty($u['is_admin']);
}
function lmsDeny(): void {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Недостаточно прав'], JSON_UNESCAPED_UNICODE);
    exit;
}
function lmsRequireDepartment($deptId): void {
    $u = lmsActor();
    if (!empty($u['is_admin'])) return;
    if (empty($u['is_manager']) || (int)$deptId <= 0 || (int)$deptId !== (int)$u['dept_id']) lmsDeny();
}
function lmsRequireEmployee(int $id): void {
    $target = Database::find('SELECT dept_id FROM users WHERE id=?', [$id]);
    if (!$target) lmsDeny();
    lmsRequireDepartment($target['dept_id']);
}

function lmsRequireLearningMaterial(array $material): void {
    $actor=lmsActor();
    if (!empty($actor['is_manager']) && empty($actor['role_id']) && empty($actor['is_admin'])) lmsDeny();
    if (!empty($material['skill_level_id'])) lmsRequireSkillLevel((int)$material['skill_level_id']);
    if (!empty($actor['is_admin'])) return;
    if (!empty($actor['is_manager'])) {
        if (!empty($material['dept_id'])) lmsRequireDepartment($material['dept_id']);
        return;
    }
    if (!empty($material['onboarding_step_id'])) {
        $actor['dept_id']=$actor['onboarding_dept_id'] ?? $actor['dept_id'];
        $actor['role_id']=$actor['onboarding_role_id'] ?? $actor['role_id'];
    }
    if ((!empty($material['dept_id']) && (int)$material['dept_id'] !== (int)($actor['dept_id'] ?? 0))
        || (!empty($material['role_id']) && (int)$material['role_id'] !== (int)($actor['role_id'] ?? 0))) lmsDeny();
}

function lmsRequireSkillLevel(int $levelId): void {
    $u=lmsActor();
    if (!empty($u['is_admin'])) return;
    if ((!empty($u['is_manager']) && empty($u['role_id'])) || !lmsSkillLevelVisible($u,$levelId)) lmsDeny();
}

function lmsVisibleSkillLevelIds(array $user): ?array {
    if (isset($user['visible_skill_level_ids'])) {
        $ids=json_decode($user['visible_skill_level_ids'],true);
        return is_array($ids) ? array_values(array_unique(array_map('intval',$ids))) : [];
    }
    return !empty($user['assigned_skill_level_id']) ? [(int)$user['assigned_skill_level_id']] : [];
}
function lmsSkillLevelVisible(array $user,int $levelId): bool {
    $ids=lmsVisibleSkillLevelIds($user);
    return $ids===null || in_array($levelId,$ids,true);
}
function lmsValidateVisibleLevels($ids): ?string {
    if ($ids===null) return json_encode(array_map('intval',array_column(Database::findAll('SELECT id FROM skill_levels WHERE is_active=1'),'id')));
    if (!is_array($ids)) {http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Выберите уровни'],JSON_UNESCAPED_UNICODE);exit;}
    $valid=[];
    foreach ($ids as $id) {
        if (!(is_int($id) || (is_string($id) && ctype_digit($id))) || (int)$id<=0 || !Database::find('SELECT id FROM skill_levels WHERE id=? AND is_active=1',[(int)$id])) {
            http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Уровень не найден'],JSON_UNESCAPED_UNICODE);exit;
        }
        $valid[]=(int)$id;
    }
    return json_encode(array_values(array_unique($valid)));
}

/** Historical snapshots are retained, but never shown after a job/department change. */
function lmsCurrentPublications(int $userId): array {
    $publications=Database::findAll('SELECT id,published_at,results_json FROM result_publications WHERE user_id=? AND is_current=1 ORDER BY id DESC',[$userId]);
    $current=[];
    foreach (['level'=>'skill_level_results','test'=>'test_results'] as $kind=>$table) {
        foreach (Database::findAll("SELECT id FROM `$table` WHERE user_id=? AND is_current=1",[$userId]) as $row) $current[$kind][(int)$row['id']]=true;
    }
    return array_values(array_filter($publications,function($publication) use ($current) {
        foreach (json_decode($publication['results_json'],true) ?: [] as $row) {
            $kind=$row['kind'] ?? '';
            if (($kind==='level' || $kind==='test') && empty($current[$kind][(int)$row['id']])) return false;
        }
        return true;
    }));
}

/** Show the published snapshot, keeping subsequent reviewer edits private. */
function lmsHideLearningGrades(array $data): array {
    if (!empty(lmsActor()['is_admin'])) return $data;
    static $published;
    if ($published===null) {
        $published=['level'=>[],'test'=>[],'lesson'=>[],'assignment'=>[]];
        $publication=lmsCurrentPublications((int)($_SESSION['user_id'] ?? 0))[0] ?? null;
        foreach (json_decode($publication['results_json'] ?? '',true) ?: [] as $row) {
            $kind=$row['kind'] ?? '';
            if (!isset($published[$kind]) || empty($row['ready'])) continue;
            $published[$kind][(int)$row['id']]=$row;
            if ($kind==='test' && ($row['content_type'] ?? '')==='task') {
                $result=Database::find('SELECT test_id FROM test_results WHERE id=?',[$row['id']]);
                if($result)$published['assignment'][(int)$result['test_id']]=$row;
            }
        }
    }
    $script=basename($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? '');
    $kind=$script==='sections.php'?'lesson':($script==='skill_levels.php'?'level':'test');
    return lmsProjectPublishedGrades($data,$published,$kind);
}
function lmsProjectPublishedGrades(array $value,array $published,string $kind): array {
    unset($value['is_correct'],$value['correct_ids']);
    $id=(int)($value['result_id'] ?? $value['id'] ?? 0);
    $snapshot=$published[$kind][$id] ?? null;
    if (array_key_exists('score',$value) || isset($value['review_status'])) {
        if (array_key_exists('score',$value)) $value['score']=$snapshot['score'] ?? 0;
        if (array_key_exists('max_score',$value)) $value['max_score']=$snapshot['max_score'] ?? 0;
        if (array_key_exists('passed',$value)) $value['passed']=$snapshot['passed'] ?? false;
        $value['review_status']=$snapshot?'reviewed':'pending';
        unset($value['answers_json'],$value['results'],$value['answers']);
    }
    if (array_key_exists('verdict',$value)) $value['verdict']=$published['assignment'][$id]['passed'] ?? null;
    if (array_key_exists('best_score',$value)) {
        $value['best_score']=$snapshot['score'] ?? 0;
        $value['best_max']=$snapshot['max_score'] ?? 0;
        $value['review_status']=$snapshot?'reviewed':(!empty($value['ever_passed'])?'pending':null);
    }
    unset($value['is_correct']); // Never expose answer keys in learning payloads.
    foreach ($value as $key=>$child) if(is_array($child)) {
        $childKind=$key==='assignments'?'test':($key==='quiz_result'?'level':$kind);
        $value[$key]=lmsProjectPublishedGrades($child,$published,$childKind);
    }
    return $value;
}

function lmsResultContentType(array $row,array $answers): string {
    if (($row['content_type'] ?? '')==='video') return 'video';
    if (($row['content_type'] ?? '')==='task' || empty($row['content_type']) || isset($answers['comment']) || isset($answers['details'])) return 'task';
    return 'quiz';
}

// Preserve UTF-8 when a timer snapshot reaches the per-answer byte limit.
function lmsAnswerByteLimit(string $text,int $max): string {
    if(strlen($text)<=$max)return $text;
    $cut=substr($text,0,$max);
    for($i=0;$i<4 && preg_match('//u',$cut)!==1;$i++)$cut=substr($cut,0,-1);
    return $cut;
}
