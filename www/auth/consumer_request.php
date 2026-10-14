<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/_session_user.php';
require_once __DIR__.'/../api/_csrf.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__,2))->safeLoad();
header('Cache-Control: no-store');
$csrf=ensure_csrf_token(); $error=''; $reference='';
$categories=['refund'=>'Повернення / припинення договору','payment'=>'Оплата та документи','incident'=>'Технічний інцидент','privacy'=>'Мої персональні дані','complaint'=>'Скарга / перегляд рішення','other'=>'Інше звернення'];
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 require_csrf_or_403();
 $name=trim((string)($_POST['full_name']??''));$email=trim((string)($_POST['email']??''));$body=trim((string)($_POST['body']??''));$category=(string)($_POST['category']??'');
 if(!empty($_POST['website'])) {http_response_code(400);$error='Не вдалося прийняти форму. Скористайтеся email підтримки.';}
 elseif(mb_strlen($name)<2 || mb_strlen($name)>180 || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL) || !isset($categories[$category]) || mb_strlen($body)<12 || mb_strlen($body)>6000) $error='Перевірте ім’я, email, тему та опис (від 12 до 6 000 символів).';
 else {
  // A small per-IP hourly limit, independent from optional marketing consent.
  $key=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'').gmdate('YmdH'));
  $fp=fopen(sys_get_temp_dir().'/strim_consumer_'.$key,'c+');
  if(!$fp || !flock($fp,LOCK_EX)) $error='Форма тимчасово недоступна. Напишіть support@strim.in.ua.';
  else {
   $n=(int)stream_get_contents($fp);
   if($n>=8){http_response_code(429);$error='Забагато звернень за годину. Для термінового питання напишіть support@strim.in.ua.';}
   else {
    try {
     $db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']);$db->set_charset('utf8mb4');
     $uid=current_user_id($db)?:null;
     $reference='STRIM-'.gmdate('ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
     $s=$db->prepare('INSERT INTO consumer_requests(public_ref,user_id,full_name,email,category,body,created_at,updated_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
     $s->bind_param('sissss',$reference,$uid,$name,$email,$category,$body);
     if(!$s->execute())throw new RuntimeException('Save failed');$s->close();
     ftruncate($fp,0);rewind($fp);fwrite($fp,(string)($n+1));
    }catch(Throwable $e){$reference='';http_response_code(503);$error='Не вдалося зареєструвати звернення. Надішліть його на support@strim.in.ua; повідомлення про успіх не створено.';}
   }
   flock($fp,LOCK_UN);fclose($fp);
  }
 }
}
function ch($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Звернення споживача — StrIM</title><link rel="stylesheet" href="/assets/css/strim-public.css?v=20260923legal"><link rel="stylesheet" href="/assets/css/strim-premium.css?v=20260923legal"><style>.request-form{display:grid;gap:16px}.request-form label{display:grid;gap:6px}.request-form input,.request-form select,.request-form textarea{font:inherit;padding:12px;border:1px solid #65717e;border-radius:8px;background:transparent;color:inherit}.request-form option{color:#162331;background:#fff}.request-form button{justify-self:start}</style></head><body class="premium-page"><main class="pr-main" style="max-width:820px;margin:auto;padding:32px 20px"><a href="/consumer.html">← Споживачу</a><h1>Ми вас почуємо.</h1><?php if($reference):?><section class="pr-card" role="status"><h2>Звернення зареєстроване</h2><p>Номер: <strong><?=ch($reference)?></strong></p><p>Дата: <?=ch((new DateTimeImmutable('now',new DateTimeZone('Europe/Kyiv')))->format('d.m.Y H:i'))?>, Київ.</p><p>Збережіть номер або роздрукуйте сторінку. Відповідь надійде на <?=ch($email)?>. Зазвичай первинно відповідаємо до двох робочих днів. Законні строки повернення/відповіді мають пріоритет.</p><button type="button" onclick="window.print()">Зберегти / надрукувати</button></section><?php else:?><p>Форма працює без реєстрації. Дані потрібні для відповіді й розгляду вимоги, не для реклами. Не додавайте паролі, API Secret, seed, CVV чи коди входу. <a href="/privacy.html">Правила обробки даних</a>.</p><?php if($error):?><p role="alert"><?=ch($error)?></p><?php endif;?><form method="post" class="request-form"><input type="hidden" name="csrf_token" value="<?=ch($csrf)?>"><label>Ім’я та прізвище<input name="full_name" required maxlength="180" autocomplete="name" value="<?=ch($_POST['full_name']??'')?>"></label><label>Email для відповіді<input type="email" name="email" required maxlength="254" autocomplete="email" value="<?=ch($_POST['email']??'')?>"></label><label>Тема<select name="category" required><?php foreach($categories as $key=>$label):?><option value="<?=ch($key)?>" <?=($_POST['category']??'')===$key?'selected':''?>><?=ch($label)?></option><?php endforeach;?></select></label><label>Що сталося та якого рішення ви очікуєте<textarea name="body" rows="8" required minlength="12" maxlength="6000"><?=ch($_POST['body']??'')?></textarea></label><div hidden aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div><button class="pr-button" type="submit">Зареєструвати звернення</button></form><?php endif;?><p>Альтернатива: <a href="mailto:support@strim.in.ua">support@strim.in.ua</a> · <a href="tel:+380970664804">+380 97 066 48 04</a>.</p></main></body></html>
