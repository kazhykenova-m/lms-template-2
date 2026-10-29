<?php
require_once __DIR__.'/../config.php';
session_name(SESSION_NAME);
session_start();
if (empty($_SESSION['user_id'])) {http_response_code(401);exit('Войди в аккаунт, чтобы открыть файл.');}
session_write_close();
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'self'");
$name=(string)($_GET['file'] ?? '');
$root=realpath(__DIR__.'/../storage/uploads');
$path=$root ? realpath($root.'/'.$name) : false;
if (!$root || !$path || $name!==basename($name) || dirname($path)!==$root || strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='txt' || !is_file($path)) {
    http_response_code(404);exit('Файл не найден.');
}
if(filesize($path)>20*1024*1024){http_response_code(413);exit('Файл слишком большой для просмотра.');}
$raw=file_get_contents($path);
if($raw===false){http_response_code(500);exit('Не удалось прочитать файл.');}
$mode=(string)($_GET['encoding'] ?? 'auto');
$encodings=['auto'=>'Автоматически','UTF-8'=>'UTF-8','Windows-1251'=>'Windows-1251','UTF-16LE'=>'UTF-16 LE','UTF-16BE'=>'UTF-16 BE','repair'=>'Восстановить искажённый UTF-8'];
if(!isset($encodings[$mode]))$mode='auto';
$encoding=$mode;
if($mode==='auto'){
    if(strncmp($raw,"\xFF\xFE",2)===0)$encoding='UTF-16LE';
    elseif(strncmp($raw,"\xFE\xFF",2)===0)$encoding='UTF-16BE';
    else $encoding=preg_match('//u',$raw)===1?'UTF-8':'Windows-1251';
}
$text=$raw;$notice='';
if($mode==='repair'){
    // Undo a mistaken UTF-8 -> Windows-1251 -> UTF-8 conversion for display only.
    $candidate=function_exists('iconv') ? @iconv('UTF-8','Windows-1251',$raw) : false;
    if($candidate!==false && preg_match('//u',$candidate)===1)$text=$candidate;
    else $notice='Не удалось восстановить текст без потерь. Попробуй другую кодировку или загрузи исходный файл, сохранённый в UTF-8.';
}elseif($encoding!=='UTF-8'){
    $converted=function_exists('iconv') ? @iconv($encoding,'UTF-8',$raw) : false;
    if($converted!==false)$text=$converted;
    else $notice='Не удалось преобразовать кодировку. Попробуй другой вариант.';
}
if(strncmp($text,"\xEF\xBB\xBF",3)===0)$text=substr($text,3);
function h(string $s): string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html>
<html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?=h($name)?></title>
<style>body{margin:24px;font:15px system-ui,sans-serif;color:#222;background:#fff}form{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:16px 0}select,button{font:inherit;padding:6px}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:14px/1.6 ui-monospace,monospace;border-top:1px solid #ddd;padding-top:16px}.notice{color:#a03000}</style></head>
<body><h1 style="font-size:18px"><?=h($name)?></h1>
<form style="display:none;" method="get"><input type="hidden" name="file" value="<?=h($name)?>"><label for="encoding">Если текст отображается неверно:</label><select id="encoding" name="encoding">
<?php foreach($encodings as $key=>$label): ?><option value="<?=h($key)?>" <?=$key===$mode?'selected':''?>><?=h($label)?></option><?php endforeach; ?>
</select><button type="submit">Показать</button><a href="<?=h('/storage/uploads/'.rawurlencode($name))?>" download>Скачать оригинал</a></form>
<?php if($notice): ?><p class="notice"><?=h($notice)?></p><?php endif; ?>
<pre><?=h($text)?></pre></body></html>
