<?php
declare(strict_types=1);
$msg = (string) ($_GET['msg'] ?? '');
$sent = !empty($_GET['sent']);
?><!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="description" content="Скидання пароля StrIM: надішлемо посилання на email або в Telegram-бот, якщо акаунт зареєстровано. Відновлення доступу до кабінету спот-торгівлі.">
  <link rel="canonical" href="https://strim.in.ua/auth/forgot_password.php">
  <meta name="robots" content="noindex, follow">
  <meta name="theme-color" content="#050810">
  <link rel="icon" href="/favicon.ico" sizes="48x48">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <title>Відновлення пароля StrIM — скидання доступу</title>
  <link rel="stylesheet" href="/assets/css/strim-public.css?v=20260923legal">
</head>
<body class="auth-page">
  <div class="ua-stripe" aria-hidden="true"></div>
  <div class="page-shell">
    <div class="page-bg" aria-hidden="true"></div>
    <header class="strim-header strim-header--pub">
      <div class="brand-block">
        <div class="logo"><a href="/">StrIM</a></div>
        <span>ТОВ «СтрІМ Алго»</span>
      </div>
      <nav class="strim-nav">
        <a class="chip" href="/auth/login.php">Увійти</a>
        <a class="chip" href="/auth/register.php">Реєстрація</a>
      </nav>
    </header>

    <div class="auth-main">
      <div class="auth-card">
        <h1>Відновлення пароля StrIM</h1>
        <?php if ($sent): ?>
          <div class="err" style="display:block;border-color:rgba(46,204,113,.45);color:#9be7b1">
            Якщо email зареєстровано в системі, ми надіслали посилання для нового пароля.
            Перевірте пошту та папку «Спам». Якщо підключено Telegram — також надійде повідомлення в бот.
          </div>
        <?php else: ?>
          <div class="err" style="display:<?= $msg ? 'block' : 'none' ?>"><?= htmlspecialchars($msg, ENT_QUOTES) ?></div>
          <p class="muted" style="margin:0 0 14px;font-size:14px">Вкажіть email, який використовували при реєстрації.</p>
          <form method="post" action="/auth/do_forgot_password.php">
            <label for="fp-email">Email</label>
            <input id="fp-email" type="email" name="email" maxlength="255" required autocomplete="email" autofocus>
            <button type="submit">Надіслати посилання</button>
            <p class="auth-links"><a href="/auth/login.php">← Назад до входу</a></p>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <script src="/assets/js/strim-theme.js?v=20260512_pubnav" defer></script>
</body>
</html>
