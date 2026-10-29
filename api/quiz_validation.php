<?php
function quizAnswerError(array $questions, array $answers): ?string {
    if (!$questions) return 'В тесте пока нет вопросов';
    foreach ($questions as $q) {
        $answer = $answers[$q['id']] ?? null;
        if ($q['type'] === 'open') {
            if (!is_string($answer) || trim($answer) === '') return 'Ответьте на все открытые вопросы';
        } else {
            if (!is_array($answer) || !$answer) return 'Выберите ответы на все вопросы';
            if ($q['type'] === 'single' && count($answer) !== 1) return 'Выберите один вариант ответа';
            $options = array_map('intval', array_column(Database::findAll('SELECT id FROM question_options WHERE question_id=?', [$q['id']]), 'id'));
            foreach ($answer as $id) if (!is_numeric($id) || !in_array((int)$id,$options,true)) return 'Некорректный вариант ответа';
        }
    }
    return null;
}
