<?php
require_once __DIR__.'/access.php';

function resultReadyForPublication(array $row): bool {
    $answers=$row['answers'] ?? [];
    if(($answers['verdict']['type'] ?? '')==='level' && is_bool($answers['verdict']['accepted'] ?? null))return true;
    if (lmsResultContentType($row+['content_type'=>'quiz'],$answers)==='task') return is_bool($answers['verdict']['accepted'] ?? null);
    foreach ($answers as $answer) {
        if (is_array($answer) && ($answer['type'] ?? '')==='open' && !is_bool($answer['accepted'] ?? null)) return false;
    }
    return ($row['review_status'] ?? '')!=='pending';
}
function publicationRows(int $userId): array {
    $rows=[];
    $queries=[
        'level'=>"SELECT r.*,s.title FROM skill_level_results r JOIN skill_levels s ON s.id=r.skill_level_id WHERE r.user_id=? AND r.is_current=1",
        'test'=>"SELECT r.*,t.title,t.description,t.content_type,t.dept_id,t.onboarding_step_id FROM test_results r JOIN tests t ON t.id=r.test_id WHERE r.user_id=? AND r.is_current=1",
    ];
    $sourceRows=[];$questionIds=[];$optionIds=[];
    foreach($queries as $kind=>$query) {
        $sourceRows[$kind]=Database::findAll($query,[$userId]);
        foreach($sourceRows[$kind] as $source) {
            foreach(json_decode($source['answers_json'] ?? '',true) ?: [] as $qid=>$answer) {
                if(!is_array($answer) || !isset($answer['type']) || in_array($answer['type'],['task','level'],true))continue;
                $questionIds[(int)$qid]=(int)$qid;
                if(is_array($answer['answer'] ?? null))foreach($answer['answer'] as $oid)$optionIds[(int)$oid]=(int)$oid;
            }
        }
    }
    $questionMap=[];$optionMap=[];
    if($questionIds)foreach(Database::findAll('SELECT id,question_text FROM questions WHERE id IN ('.implode(',',$questionIds).')') as $q)$questionMap[(int)$q['id']]=$q;
    if($optionIds)foreach(Database::findAll('SELECT id,option_text FROM question_options WHERE id IN ('.implode(',',$optionIds).')') as $o)$optionMap[(int)$o['id']]=$o;
    foreach ($sourceRows as $kind=>$sources) foreach ($sources as $row) {
        if (empty(lmsActor()['is_admin']) && !empty($row['dept_id']) && (int)$row['dept_id']!==(int)lmsActor()['dept_id']) continue;
        if (($row['content_type'] ?? '')==='video') continue;
        $row['answers']=json_decode($row['answers_json'] ?? '',true) ?: [];
        $row['content_type']=$kind==='level'||$kind==='lesson' ? 'quiz' : lmsResultContentType($row,$row['answers']);
        $answers=[];
        foreach ($row['answers'] as $qid=>$answer) {
            if (!is_array($answer) || !isset($answer['type']) || in_array($answer['type'],['task','level'],true)) continue;
            $q=$questionMap[(int)$qid] ?? null;
            $text=$answer['answer'] ?? '';
            if (is_array($text)) {
                $texts=[];
                foreach ($text as $oid) {$o=$optionMap[(int)$oid] ?? null;if($o)$texts[]=$o['option_text'];}
                $text=implode(', ',$texts);
            }
            $answers[]=['question'=>$q['question_text'] ?? ('Вопрос #'.$qid),'answer'=>(string)$text,'correct'=>$answer['type']==='open'?($answer['accepted'] ?? null):!empty($answer['is_correct'])];
        }
        $rows[]=['category'=>($kind==='lesson' || ($kind==='test' && !empty($row['onboarding_step_id']))) ? 'onboarding' : 'hard','kind'=>$kind,'id'=>(int)$row['id'],'title'=>$row['title'],'description'=>$row['content_type']==='task' ? (string)($row['description'] ?? '') : '','content_type'=>$row['content_type'] ?? 'quiz','score'=>(int)$row['score'],'max_score'=>(int)$row['max_score'],'passed'=>$row['content_type']==='task' ? (($row['answers']['verdict']['accepted'] ?? null)===true) : (bool)$row['passed'],'completed_at'=>$row['completed_at'],'ready'=>resultReadyForPublication($row),'comment'=>(string)($row['answers']['comment'] ?? $row['answers']['details'] ?? ''),'answers'=>$answers];
    }
    return $rows;
}

// Старые публикации не содержат категории: определяем её по источнику результата.
function categorizePublishedResults(array $rows): array {
    foreach ($rows as &$row) {
        if (isset($row['category'])) continue;
        $row['category']=($row['kind'] ?? '')==='lesson' ? 'onboarding' : 'hard';
        if (($row['kind'] ?? '')==='test') {
            $test=Database::find('SELECT t.onboarding_step_id FROM test_results r JOIN tests t ON t.id=r.test_id WHERE r.id=?',[(int)$row['id']]);
            if (!empty($test['onboarding_step_id'])) $row['category']='onboarding';
        }
    }
    unset($row);
    return $rows;
}
