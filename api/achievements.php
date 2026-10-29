<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/Database.php';

/**
 * AchievementEngine — вызывается после любого события (завершение раздела, начисление коинов и т.д.)
 * Не подключает session — безопасен для require_once из других файлов.
 */
class AchievementEngine {

    /**
     * Проверить и выдать ачивки пользователю после события.
     * Возвращает массив только что полученных ачивок (для показа пользователю).
     */
    public static function check(int $userId, string $event, array $context = []): array {
        $user = Database::find('SELECT u.*, d.slug as dept_slug FROM users u LEFT JOIN departments d ON d.id=u.dept_id WHERE u.id=?', [$userId]);
        if (!$user) return [];

        $allAchs = Database::findAll('SELECT * FROM achievements WHERE is_active=1');
        $earned  = array_column(
            Database::findAll('SELECT achievement_id FROM user_achievements WHERE user_id=?', [$userId]),
            'achievement_id'
        );

        $new = [];
        foreach ($allAchs as $ach) {
            if (in_array($ach['id'], $earned)) continue; // уже есть

            $grant = false;
            switch ($ach['condition_type']) {
                case 'skill_level':
                    // condition_value = slug уровня (junior, middle, senior)
                    $levelSlug = trim($ach['condition_value']);
                    if ($levelSlug) {
                        $lvRow = Database::find('SELECT id FROM skill_levels WHERE slug=? AND is_active=1', [$levelSlug]);
                        if ($lvRow) {
                            $passed = Database::find(
                                'SELECT COUNT(*) as n FROM skill_level_results WHERE user_id=? AND skill_level_id=? AND passed=1',
                                [$userId, $lvRow['id']]
                            );
                            $grant = (int)($passed['n'] ?? 0) > 0;
                        }
                    }
                    break;
            }

            if ($grant) {
                Database::insert('user_achievements', ['user_id'=>$userId,'achievement_id'=>$ach['id']]);
                $new[] = $ach;
            }
        }
        return $new;
    }

    /**
     * Получить все ачивки пользователя (заработанные и нет).
     */
    /**
     * Синхронизировать ачивки для уровней хардскиллов.
     * Вызывается при каждом получении ачивок — создаёт недостающие записи.
     */
    public static function syncSkillLevelAchievements(): void {
        try {
            $levels = Database::findAll('SELECT * FROM skill_levels WHERE is_active=1 ORDER BY sort');
            foreach ($levels as $lv) {
                $slug = 'skill_level_' . $lv['slug'];
                $existing = Database::find('SELECT id FROM achievements WHERE slug=?', [$slug]);
                if (!$existing) {
                    // Иконки по порядку уровня
                    $icons = ['🌱', '⚡', '🏆', '💫', '🔥'];
                    $icon  = $icons[max(0,min($lv['sort'] - 1, count($icons) - 1))];
                    Database::insert('achievements', [
                        'slug'            => $slug,
                        'title'           => $lv['title'],
                        'description'     => 'Завершил обучение «' . $lv['title'] . '»' . ($lv['description'] ? ' — ' . $lv['description'] : ''),
                        'icon'            => $icon,
                        'condition_type'  => 'skill_level',
                        'condition_value' => $lv['slug'],
                        'is_active'       => 1,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[Achievements] syncSkillLevel: ' . $e->getMessage());
        }
    }

    /**
     * Проверяет и довыдаёт ачивки которые должны были быть получены
     * но не были (например пользователь прошёл онбординг до добавления ачивок)
     */
    public static function retroCheck(int $userId): void {
        self::check($userId,'skill_level');
    }

    public static function getAll(int $userId): array {
        try {
            $all = Database::findAll('SELECT a.*, ua.earned_at FROM achievements a
                LEFT JOIN user_achievements ua ON ua.achievement_id=a.id AND ua.user_id=?
                WHERE a.is_active=1 ORDER BY ua.earned_at DESC, a.id', [$userId]);
            foreach ($all as &$a) {
                $a['earned'] = !empty($a['earned_at']);
            }
            return $all;
        } catch (\Throwable $e) {
            error_log('[Achievements] getAll error: ' . $e->getMessage());
            return [];
        }
    }
}

// ── Прямой GET запрос: GET /api/achievements.php ──────────────────
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    session_name(SESSION_NAME);
    if (session_status() === PHP_SESSION_NONE) session_start();
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'Не авторизован'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $userId  = (int)$_SESSION['user_id'];
    require_once __DIR__ . '/access.php';
    ensureLmsSchema();
    require_once __DIR__ . '/achievement_svg.php';
    $isAdmin = !empty(lmsActor()['is_admin']);
    $method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action  = $_GET['action'] ?? '';

    function achJson($data, int $code = 200): void {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── GET /api/achievements.php — список для пользователя ──────────
    if ($method === 'GET' && !$action) {
        try {
            AchievementEngine::syncSkillLevelAchievements();
            AchievementEngine::retroCheck($userId);
            $achievements = AchievementEngine::getAll($userId);
            achJson(['ok'=>true,'achievements'=>$achievements]);
        } catch (\Throwable $e) {
            error_log('[achievements.php] ' . $e->getMessage());
            achJson(['ok'=>true,'achievements'=>[]]);
        }
    }

    // ── GET ?action=admin — все ачивки для админа (без фильтра is_active) ─
    if ($method === 'GET' && $action === 'admin') {
        if (!$isAdmin) achJson(['ok'=>false,'error'=>'Нет доступа'], 403);
        $all = Database::findAll('SELECT * FROM achievements ORDER BY id');
        achJson(['ok'=>true,'achievements'=>$all]);
    }

    // ── POST ?action=create — создать ────────────────────────────────
    if ($method === 'POST' && $action === 'create') {
        if (!$isAdmin) achJson(['ok'=>false,'error'=>'Нет доступа'], 403);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        if(isset($body['condition_type']) && $body['condition_type']!=='skill_level') achJson(['ok'=>false,'error'=>'Выберите обучение для достижения'],400);
        $title = trim($body['title'] ?? '');
        if (!$title) achJson(['ok'=>false,'error'=>'Название обязательно'], 400);
        try {$iconSvg=validateAchievementSvg($body['icon_svg'] ?? '');}
        catch (InvalidArgumentException $e) {achJson(['ok'=>false,'error'=>$e->getMessage()],400);}
        $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($body['slug'] ?? '')));
        if (!$slug) {
            $tr = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
            $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower(strtr($title, $tr)));
        }
        // Уникальность slug
        $existing = Database::find('SELECT id FROM achievements WHERE slug=?', [$slug]);
        if ($existing) $slug .= '_' . time();
        $id = Database::insert('achievements', [
            'slug'            => $slug,
            'title'           => $title,
            'description'     => trim($body['description'] ?? ''),
            'icon'            => mb_substr(trim($body['icon'] ?? '🏆'), 0, 10),
            'icon_svg'        => $iconSvg,
            'condition_type'  => $body['condition_type'] ?? 'skill_level',
            'condition_value' => trim($body['condition_value'] ?? ''),
            'is_active'       => isset($body['is_active']) ? (int)$body['is_active'] : 1,
        ]);
        achJson(['ok'=>true,'id'=>$id]);
    }

    // ── PUT ?id=N — обновить ─────────────────────────────────────────
    if ($method === 'PUT') {
        if (!$isAdmin) achJson(['ok'=>false,'error'=>'Нет доступа'], 403);
        $id   = (int)($_GET['id'] ?? 0);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        if(isset($body['condition_type']) && $body['condition_type']!=='skill_level') achJson(['ok'=>false,'error'=>'Выберите обучение для достижения'],400);
        if (!$id) achJson(['ok'=>false,'error'=>'ID обязателен'], 400);
        $data = [];
        if (array_key_exists('icon_svg',$body)) {
            try {$data['icon_svg']=validateAchievementSvg($body['icon_svg']);}
            catch (InvalidArgumentException $e) {achJson(['ok'=>false,'error'=>$e->getMessage()],400);}
        }
        if (isset($body['title']))           $data['title']           = trim($body['title']);
        if (isset($body['description']))     $data['description']     = trim($body['description']);
        if (isset($body['icon']))            $data['icon']            = mb_substr(trim($body['icon']), 0, 10);
        if (isset($body['condition_type']))  $data['condition_type']  = $body['condition_type'];
        if (isset($body['condition_value'])) $data['condition_value'] = trim($body['condition_value']);
        if (isset($body['is_active']))       $data['is_active']       = (int)$body['is_active'];
        if ($data) Database::update('achievements', $data, 'id=?', [$id]);
        achJson(['ok'=>true]);
    }

    // ── DELETE ?id=N — удалить ───────────────────────────────────────
    if ($method === 'DELETE') {
        if (!$isAdmin) achJson(['ok'=>false,'error'=>'Нет доступа'], 403);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) achJson(['ok'=>false,'error'=>'ID обязателен'], 400);
        Database::delete('achievements', 'id=?', [$id]);
        achJson(['ok'=>true]);
    }

    achJson(['ok'=>false,'error'=>'Неизвестное действие'], 400);
}
