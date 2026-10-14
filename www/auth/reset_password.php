<?php
declare(strict_types=1);
$token = trim((string) ($_GET['token'] ?? ''));
$msg = (string) ($_GET['msg'] ?? '');
$ok = !empty($_GET['ok']);
?><!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="description" content="Встановіть новий пароль для входу в кабінет StrIM за одноразовим посиланням з листа або Telegram. Сторінка службова, не індексується пошуком.">
  <link rel="canonical" href="https://strim.in.ua/auth/reset_password.php">
  <meta name="robots" content="noindex, follow">
  <meta name="theme-color" content="#050810">
  <link rel="icon" href="/favicon.ico" sizes="48x48">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <title>Встановлення нового пароля StrIM</title>
  <link rel="stylesheet" href="/assets/css/strim-public.css?v=20260923legal">
</head>
<body class="auth-page">
  <div class="ua-stripe" aria-hidden="true"></div>
  <div class="page-shell">
    <div class="page-bg" aria-hidden="true"></div>
    <header class="strim-header strim-header--pub">
      <div class="brand-block">
        <div class="logo"><a href="/">StrIM</a></div>
      </div>
    </header>

    <div class="auth-main">
      <div class="auth-card">
        <h1>Встановлення нового пароля StrIM</h1>
        <?php if ($ok): ?>
          <h2 class="auth-state-title">Пароль змінено</h2>
          <div class="err" style="display:block;border-color:rgba(46,204,113,.45);color:#9be7b1">
            Новий пароль збережено. Тепер можете увійти в кабінет.
          </div>
          <p class="auth-links" style="margin-top:16px"><a href="/auth/login.php">Увійти</a></p>
        <?php elseif ($token === ''): ?>
          <h2 class="auth-state-title">Посилання недійсне</h2>
          <div class="err" style="display:block">Запитіть нове посилання для скидання пароля.</div>
          <p class="auth-links"><a href="/auth/forgot_password.php">Скинути пароль</a></p>
        <?php else: ?>
          <h2 class="auth-state-title">Задайте новий пароль</h2>
          <div class="err" style="display:<?= $msg ? 'block' : 'none' ?>"><?= htmlspecialchars($msg, ENT_QUOTES) ?></div>
          <form method="post" action="/auth/do_reset_password.php">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
            <label for="rp-pass">Новий пароль</label>
            <input id="rp-pass" type="password" name="pass" minlength="6" required autocomplete="new-password">
            <label for="rp-pass2">Повторіть пароль</label>
            <input id="rp-pass2" type="password" name="pass2" minlength="6" required autocomplete="new-password">
            <button type="submit">Зберегти пароль</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
