<?php
require_once __DIR__.'/api_errors.php';
/**
 * upload.php — загрузка файлов к шагам онбординга
 * POST /api/upload.php  — multipart/form-data, поле: file
 * Возвращает: { ok: true, url: "/storage/uploads/filename.pdf" }
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/Database.php';
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
session_name(SESSION_NAME);
session_start();
header('Content-Type: application/json; charset=utf-8');

function json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (empty($_SESSION['user_id'])) json(['ok' => false, 'error' => 'Не авторизован'], 401);
$userId = (int)$_SESSION['user_id'];
$type = $_GET['type'] ?? '';
// Аватар — доступен всем пользователям. Остальные загрузки — только админам
if (!in_array($type, ['avatar','answer'], true) && empty($_SESSION['is_admin'])) json(['ok' => false, 'error' => 'Нет доступа'], 403);

session_write_close(); // Do not block timer/draft requests while saving an attachment.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['ok' => false, 'error' => 'Только POST'], 405);
// Для аватара поле называется 'avatar', для остального — 'file'
$fileKey = isset($_FILES['avatar']) ? 'avatar' : 'file';
if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
    $error = $_FILES[$fileKey]['error'] ?? null;
    $message = match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл превышает лимит загрузки сервера. Требуются upload_max_filesize=300M и post_max_size=320M',
        UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью. Повторите загрузку',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Сервер не может записать файл. Проверьте временную папку и права записи',
        default => 'Файл не получен сервером. Проверьте лимит post_max_size и настройки загрузки',
    };
    json(['ok' => false, 'error' => $message], 400);
}

$file = $_FILES[$fileKey];

// Ограничения
$maxSize = ($type === 'answer' ? 300 : 200) * 1024 * 1024;
if ($file['size'] > $maxSize) {
    json(['ok' => false, 'error' => 'Файл слишком большой (макс ' . ($type === 'answer' ? 300 : 200) . ' МБ)'], 413);
}

// Разрешённые типы
$allowed = [
    'application/pdf'  => 'pdf',
    'video/mp4'        => 'mp4',
    'video/webm'       => 'webm',
    'video/quicktime'  => 'mov',
    'image/jpeg'       => 'jpg',
    'image/png'        => 'png',
    'image/gif'        => 'gif',
    'image/webp'       => 'webp',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/msword' => 'doc',
    'application/zip'  => 'zip',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'text/plain' => 'txt',
];

if ($type === 'answer') {
    $mime = 'application/octet-stream';
} else {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
}

// Для аватара — дополнительно разрешаем все image/* типы
if ($type === 'answer') {
    $ext = 'bin'; // Any format; served only as a download with the original name.
} elseif (!isset($allowed[$mime])) {
    if ($type === 'avatar' && strpos($mime, 'image/') === 0) {
        $ext = 'jpg'; // fallback расширение
    } else {
        json(['ok' => false, 'error' => 'Недопустимый тип файла: ' . $mime], 415);
    }
} else {
    $ext = $allowed[$mime];
}


if ($ext === 'zip') {
    $officeExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($officeExt, ['docx','pptx','xlsx'], true)) $ext = $officeExt;
}

// Папка
$uploadDir = __DIR__ . '/../storage/uploads';
if (!is_dir($uploadDir) && !@mkdir($uploadDir,0755,true) && !is_dir($uploadDir)) {
    json(['ok'=>false,'error'=>'Не удалось создать папку загрузок. Обратитесь к администратору'],500);
}
if (!is_writable($uploadDir)) json(['ok'=>false,'error'=>'Папка загрузок недоступна для записи. Обратитесь к администратору'],500);

// Безопасное имя файла
$originalName = pathinfo($file['name'], PATHINFO_FILENAME);
$originalName = preg_replace('/[^a-zA-Z0-9_\-а-яёА-ЯЁ]/u', '_', $originalName);
$originalName = implode('', array_slice(preg_split('//u', $originalName, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 60));
$uniqueName   = $originalName . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$destPath     = $uploadDir . '/' . $uniqueName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    json(['ok' => false, 'error' => 'Не удалось сохранить файл'], 500);
}

$publicUrl = $type === 'answer'
    ? '/api/download_attachment.php?file=' . rawurlencode($uniqueName) . '&name=' . rawurlencode($file['name'])
    : '/storage/uploads/' . $uniqueName;

// Если тип avatar — сохраняем в профиль
if ($type === 'avatar') {
    Database::update('users', ['avatar' => $publicUrl], 'id = ?', [$userId]);
}

json([
    'ok'       => true,
    'url'      => $publicUrl,
    'filename' => $file['name'],
    'size'     => $file['size'],
    'mime'     => $mime,
]);
