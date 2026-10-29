<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/Database.php';

session_name(SESSION_NAME);
session_start();
require_once __DIR__ . '/access.php';
ensureLmsSchema();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function currentUser(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    return Database::find('SELECT u.*, d.slug as dept_slug, d.name as dept_name, r.name as role_name
        FROM users u
        LEFT JOIN departments d ON d.id = u.dept_id
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?', [$_SESSION['user_id']]);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'me';
$method = $_SERVER['REQUEST_METHOD'];

// JSON body support
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
    $body = array_merge($_POST, $body);
}

if (in_array($action,['forgot_password','reset_password'],true)) require __DIR__.'/password_reset.php';

switch ($action) {

    case 'me':
        $user = currentUser();
        // При первом открытии отсутствие сессии штатно: клиент покажет вход.
        if (!$user) json(['ok' => false, 'error' => 'Не авторизован']);
        unset($user['password_hash']);
        json(['ok' => true, 'user' => $user]);

    case 'login':
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        if (!$email || !$password) json(['ok' => false, 'error' => 'Заполни все поля'], 400);

        $userCheck = Database::find('SELECT * FROM users WHERE email = ?', [strtolower($email)]);
        if (!$userCheck || !password_verify($password, $userCheck['password_hash'])) {
            json(['ok' => false, 'error' => 'Неверный email или пароль'], 401);
        }

        session_regenerate_id(true);
        $_SESSION['user_id']  = $userCheck['id'];
        $_SESSION['is_admin'] = (bool) $userCheck['is_admin'];

        // Загружаем полные данные с отделом и ролью
        $user = Database::find('SELECT u.*, d.slug as dept_slug, d.name as dept_name, r.name as role_name
            FROM users u
            LEFT JOIN departments d ON d.id = u.dept_id
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.id = ?', [$userCheck['id']]);
        unset($user['password_hash']);
        json(['ok' => true, 'user' => $user]);

    case 'register':
        json(['ok'=>false,'error'=>'Аккаунт создаёт администратор платформы'],403);
        $email     = strtolower(trim($body['email'] ?? ''));
        $password  = $body['password'] ?? '';
        $firstName = trim($body['first_name'] ?? '');
        $lastName  = trim($body['last_name'] ?? '');
        $deptId    = (int) ($body['dept_id'] ?? 0);
        $roleId    = (int) ($body['role_id'] ?? 0);

        $isManager=!empty($body['is_manager']) ? 1 : 0;
        if (!$email || !$password || !$firstName || !$lastName || !$deptId || (!$roleId && !$isManager)) {
            json(['ok' => false, 'error' => 'Заполни все поля'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json(['ok' => false, 'error' => 'Некорректный email'], 400);
        }
        if (strlen($password) < 6) {
            json(['ok' => false, 'error' => 'Пароль минимум 6 символов'], 400);
        }
        if (Database::find('SELECT id FROM users WHERE email = ?', [$email])) {
            json(['ok' => false, 'error' => 'Email уже зарегистрирован'], 409);
        }

        if (!Database::find('SELECT id FROM departments WHERE id=?',[$deptId])) json(['ok'=>false,'error'=>'Выберите отдел'],400);
        if ($roleId && !Database::find('SELECT id FROM roles WHERE id=? AND dept_id=?',[$roleId,$deptId])) json(['ok'=>false,'error'=>'Должность не относится к отделу'],400);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $userId = Database::insert('users', [
            'email'         => $email,
            'password_hash' => $hash,
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'dept_id'       => $deptId,
            'role_id'       => $roleId ?: null,
            'is_manager'    => $isManager,
            'coins'         => 0,
            'level'         => 1,
        ]);

        

        session_regenerate_id(true);
        $_SESSION['user_id']  = $userId;
        $_SESSION['is_admin'] = false;

        $user = Database::find('SELECT u.*, d.slug as dept_slug, d.name as dept_name, r.name as role_name
            FROM users u
            LEFT JOIN departments d ON d.id = u.dept_id
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.id = ?', [$userId]);
        unset($user['password_hash']);
        json(['ok' => true, 'user' => $user]);

    case 'logout':
        session_destroy();
        json(['ok' => true]);

    default:
        json(['ok' => false, 'error' => 'Неизвестное действие'], 400);
}
