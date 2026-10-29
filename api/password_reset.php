<?php
// Included from auth.php after JSON helpers and session initialization.
if (!function_exists('json') || !isset($method,$action)) {http_response_code(404);exit;}
if ($method !== 'POST') json(['ok'=>false,'error'=>'Только POST'],405);
Database::query("CREATE TABLE IF NOT EXISTS password_reset_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(254) NOT NULL,
 ip_hash CHAR(64) NOT NULL, code_hash VARCHAR(255) DEFAULT NULL,
 attempts INT NOT NULL DEFAULT 0, used TINYINT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL,
 INDEX reset_email(email,created_at), INDEX reset_ip(ip_hash,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$email=strtolower(trim((string)($body['email'] ?? '')));
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254) json(['ok'=>false,'error'=>'Введи корректный email'],400);
if ($action==='forgot_password') {
    $message='Если аккаунт с этим email существует, код отправлен на почту. Проверь также папку «Спам». Повторный запрос доступен через минуту.';
    $ipHash=hash('sha256',$_SERVER['REMOTE_ADDR'] ?? 'unknown');
    // Serialize requests, including requests for unknown addresses, to enforce limits.
    $lock='lms-reset-'.substr(hash('sha256',$ipHash),0,40);
    $locked=Database::find('SELECT GET_LOCK(?, 3) AS acquired',[$lock]);
    if(empty($locked['acquired'])) json(['ok'=>true,'message'=>$message]);
    try {
        $recent=Database::find('SELECT COUNT(*) AS n FROM password_reset_requests WHERE (email=? OR ip_hash=?) AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)',[$email,$ipHash]);
        $cooldown=Database::find('SELECT id FROM password_reset_requests WHERE email=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 MINUTE) LIMIT 1',[$email]);
        if ((int)$recent['n']<5 && !$cooldown) {
            $code=(string)random_int(10000000,99999999);
            Database::query('UPDATE password_reset_requests SET used=1 WHERE email=? AND used=0',[$email]);
            $id=Database::insert('password_reset_requests',[
                'email'=>$email,'ip_hash'=>$ipHash,'code_hash'=>password_hash($code,PASSWORD_DEFAULT),
                'expires_at'=>date('Y-m-d H:i:s',time()+600),
            ]);
            $user=Database::find('SELECT id FROM users WHERE email=?',[$email]);
            if ($user) {
                $subject='=?UTF-8?B?'.base64_encode('Сброс пароля miniLMS').'?=';
                $headers="From: ".MAIL_FROM_EMAIL."\r\nContent-Type: text/plain; charset=UTF-8\r\n";
                $sent=mail($email,$subject,"Код для сброса пароля miniLMS: $code\n\nКод действителен 10 минут. Если ты не запрашивал(а) сброс, просто проигнорируй это письмо.",$headers);
                if(!$sent){Database::query('UPDATE password_reset_requests SET used=1 WHERE id=?',[$id]);error_log('[LMS] Password reset email delivery failed');}
            }
        }
    } finally {Database::query('SELECT RELEASE_LOCK(?)',[$lock]);}
    json(['ok'=>true,'message'=>$message]);
}
$code=trim((string)($body['code'] ?? ''));
$password=(string)($body['password'] ?? '');
if(strlen($password)<6 || strlen($password)>72) json(['ok'=>false,'error'=>'Пароль должен содержать от 6 до 72 байт'],400);
$pdo=Database::get();$pdo->beginTransaction();
try {
    $reset=Database::find('SELECT * FROM password_reset_requests WHERE email=? ORDER BY id DESC LIMIT 1 FOR UPDATE',[$email]);
    $valid=$reset && !$reset['used'] && (int)$reset['attempts']<5 && strtotime($reset['expires_at'])>time();
    if(!$valid || !password_verify($code,$reset['code_hash'])) {
        if($valid) Database::query('UPDATE password_reset_requests SET attempts=attempts+1 WHERE id=?',[$reset['id']]);
        $pdo->commit();json(['ok'=>false,'error'=>'Неверный или истёкший код. Запроси новый код.'],400);
    }
    $user=Database::find('SELECT id FROM users WHERE email=?',[$email]);
    if(!$user){$pdo->commit();json(['ok'=>false,'error'=>'Неверный или истёкший код. Запроси новый код.'],400);}
    Database::update('users',['password_hash'=>password_hash($password,PASSWORD_BCRYPT)],'id=?',[$user['id']]);
    Database::query('UPDATE password_reset_requests SET used=1 WHERE email=?',[$email]);
    $pdo->commit();json(['ok'=>true,'message'=>'Пароль изменён. Войди с новым паролем.']);
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();error_log('[LMS password reset] '.$e->getMessage());
    json(['ok'=>false,'error'=>'Не удалось изменить пароль. Попробуй позже.'],500);
}
