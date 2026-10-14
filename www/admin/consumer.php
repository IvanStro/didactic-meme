<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';require_once __DIR__.'/../auth/_session_user.php';require_once __DIR__.'/../api/_csrf.php';require_once __DIR__.'/../api/_mail_send.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__,2))->safeLoad();
$db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']);$db->set_charset('utf8mb4');$uid=current_user_id($db);
$r=$uid>0?$db->query('SELECT is_admin FROM users WHERE id='.(int)$uid.' LIMIT 1'):null;$u=$r?$r->fetch_assoc():null;
if(!$u || (int)$u['is_admin']!==1){http_response_code(403);exit('Доступ лише для адміністратора.');}
header('Cache-Control: no-store');$csrf=ensure_csrf_token();$notice='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 require_csrf_or_403();$id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'review');$reply=trim((string)($_POST['reply']??''));
 if($id>0 && in_array($status,['review','waiting','resolved'],true) && mb_strlen($reply)>=12 && mb_strlen($reply)<=10000){
  $q=$db->prepare('SELECT email,public_ref FROM consumer_requests WHERE id=?');$q->bind_param('i',$id);$q->execute();$ticket=$q->get_result()->fetch_assoc();$q->close();
  if($ticket){
   $sent=strim_mail_send($ticket['email'],'StrIM: звернення '.$ticket['public_ref'],$reply."\n\nНомер: ".$ticket['public_ref']."\nТОВ «СтрІМ Алго» · support@strim.in.ua",'support@strim.in.ua');
   if(!empty($sent['ok'])){
    $q=$db->prepare('UPDATE consumer_requests SET status=?,resolution=CONCAT(COALESCE(resolution,\'\'),?),updated_at=UTC_TIMESTAMP() WHERE id=?');
    $entry="\n".gmdate('c').' admin#'.$uid."\n".$reply."\n";$q->bind_param('ssi',$status,$entry,$id);$q->execute();$q->close();$notice='Відповідь надіслано й зафіксовано. Позначення «вирішено» не виконує повернення коштів автоматично.';
   }else{$notice='Лист не надіслано. Статус не змінено. Зв’яжіться іншим узгодженим каналом.';}
  }
 }else{$notice='Перевірте номер, статус і текст відповіді.';}
}
$items=$db->query('SELECT * FROM consumer_requests ORDER BY (status=\'resolved\'),created_at DESC LIMIT 100');
function ah($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Звернення споживачів — StrIM</title><link rel="stylesheet" href="/admin/admin-panel.css?v=20260923legal"></head><body class="adm-body"><main class="adm-wrap"><h1>Звернення споживачів</h1><p><a href="/admin/users.php">Адміністрування</a> · <a href="/consumer.html">Правила для споживача</a></p><p>Перевіряти кожного робочого дня. Первинна відповідь — до 2 робочих днів; належне повернення — до 14 календарних, запит про дані — за загальним правилом до 30. Уточнення не перезапускає законний строк. Дати в реєстрі — UTC.</p><?php if($notice):?><p role="status"><?=ah($notice)?></p><?php endif;?><?php while($it=$items->fetch_assoc()):?><section class="adm-card" style="margin:18px 0;padding:18px;border:1px solid #65717e"><h2><?=ah($it['public_ref'])?> · <?=ah($it['category'])?></h2><p><?=ah($it['created_at'])?> UTC · <?=ah($it['status'])?> · <?=ah($it['full_name'])?> · <?=ah($it['email'])?></p><p style="white-space:pre-wrap"><?=ah($it['body'])?></p><?php if($it['resolution']):?><details><summary>Історія відповідей</summary><pre style="white-space:pre-wrap"><?=ah($it['resolution'])?></pre></details><?php endif;?><form method="post"><input type="hidden" name="csrf_token" value="<?=ah($csrf)?>"><input type="hidden" name="id" value="<?=ah($it['id'])?>"><p><label>Результат <select name="status"><option value="review">Розглядається</option><option value="waiting">Очікуємо дані</option><option value="resolved">Вирішено</option></select></label></p><textarea name="reply" rows="5" style="width:100%" minlength="12" maxlength="10000" required placeholder="Мотивована відповідь. Для повернення: розрахунок, спосіб, дата й підтвердження фактичного виконання."></textarea><button type="submit">Надіслати відповідь і зберегти</button></form></section><?php endwhile;?></main></body></html>
