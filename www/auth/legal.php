<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/_session_user.php';
require_once __DIR__.'/../api/_csrf.php';
require_once __DIR__.'/../api/_legal.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__,2))->safeLoad();
$db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']); $db->set_charset('utf8mb4');
$uid=current_user_id($db);
if ($uid<=0) { header('Location: /auth/login.php?next=/auth/legal.php'); exit; }
$csrf=ensure_csrf_token(); $m=strim_legal_manifest(); $error='';
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
 require_csrf_or_403();
 if (($_POST['accept_legal']??'')!=='1' || ($_POST['adult']??'')!=='1') $error='Підтвердьте ознайомлення з умовами та повноліття.';
 else {
  try { strim_legal_record($db,$uid,(string)($_POST['legal_version']??''),'existing_account'); header('Location: /dashboard.php'); exit; }
  catch(Throwable $e) { $error='Не вдалося зберегти підтвердження. Оновіть сторінку або зверніться до підтримки.'; }
 }
}
function lh($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
header('Cache-Control: no-store');
?><!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Умови користування — StrIM</title><link rel="stylesheet" href="/assets/css/strim-public.css?v=20260923legal"><link rel="stylesheet" href="/assets/css/strim-premium.css?v=20260923legal"></head><body class="premium-page"><main class="pr-main" style="max-width:860px;margin:auto;padding:32px 20px"><a href="/">StrIM</a><h1>Зрозумілі умови.<br>Свідомий початок.</h1><p>Редакція <?=lh($m['version'])?>. До нової оплати або запуску перегляньте умови. Відмова не позбавляє права звернутися, повернути невикористаний аванс чи керувати вже відкритими ордерами безпосередньо на Binance.</p><ul><li>Тест до першого поповнення: до 3 стратегій, перша покупка до 10 USDT. Це реальні угоди.</li><li>Аванс від 500 грн; плата — 10% позитивного результату кожного продажу. Збитки за іншими угодами не зменшують цю комісію.</li><li>Поповнення не відновлює покупки автоматично. «Пауза покупок» не зупиняє продажі вже відкритих позицій.</li><li>Кошти на Binance та аванс на оплату StrIM — різні речі.</li></ul><div class="pr-card"><?php foreach($m['documents'] as $doc):?><p><a href="<?=lh($doc['url'])?>" target="_blank" rel="noopener"><?=lh($doc['title'])?></a></p><?php endforeach;?></div><form method="post" style="margin-top:24px"><input type="hidden" name="csrf_token" value="<?=lh($csrf)?>"><input type="hidden" name="legal_version" value="<?=lh($m['version'])?>"><p><label><input type="checkbox" name="accept_legal" value="1" required> Приймаю оферту й пов’язані правила, ознайомився/ознайомилася з повідомленням про дані та ризики. Прошу надати доступ до сервісу після підтвердження. Це не згода на рекламу.</label></p><p><label><input type="checkbox" name="adult" value="1" required> Мені виповнилося 18 років; я дієздатний/дієздатна та дію від власного імені або маю належні повноваження.</label></p><?php if($error):?><p role="alert"><?=lh($error)?></p><?php endif;?><button class="pr-button" type="submit">Прийняти й перейти в кабінет</button></form><p><a href="/consumer.html">Споживачу та звернення</a> · <a href="/dashboard.php">Повернутися без прийняття</a></p></main></body></html>
