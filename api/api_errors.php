<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__.'/../php_errors.log');
set_exception_handler(function (Throwable $error): void {
    $id = bin2hex(random_bytes(6));
    if (class_exists('Database', false)) Database::rollbackActive();
    error_log('[API '.$id.'] '.get_class($error).': '.$error->getMessage().' at '.$error->getFile().':'.$error->getLine());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Не удалось завершить действие. Не закрывайте страницу и повторите попытку. Код ошибки: '.$id,'error_id'=>$id], JSON_UNESCAPED_UNICODE);
});
