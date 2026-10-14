<?php
declare(strict_types=1);
$next = $_GET['next'] ?? '/dashboard_saas.html';
$msg  = $_GET['msg'] ?? '';
?><!DOCTYPE html>
<html lang="uk">
<head>
  <!-- Google tag (gtag.js) + Consent Mode v2 -->
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('consent', 'default', {
      ad_storage: 'denied',
      analytics_storage: 'denied',
      ad_user_data: 'denied',
      ad_personalization: 'denied',
      functionality_storage: 'granted',
      security_storage: 'granted',
      wait_for_update: 500
    });
    gtag('set', 'ads_data_redaction', true);
    gtag('js', new Date());
  </script>
  
  <script>
    gtag('config', 'G-5G2LNXY143');
    gtag('config', 'AW-18167666446');
    (function () {
      try {
        var c = localStorage.getItem('strim_consent_v2');
        if (c === 'all') {
          gtag('consent', 'update', {
            ad_storage: 'granted',
            analytics_storage: 'granted',
            ad_user_data: 'granted',
            ad_personalization: 'granted'
          });
          gtag('set', 'ads_data_redaction', false);
        }
      } catch (e) { /* ignore */ }
    })();
  </script>
  <link rel="stylesheet" href="/assets/css/strim-cookie-consent.css?v=20260923legal">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="description" content="Вхід у кабінет StrIM: спот-стратегії на Binance, журнал угод і баланс. SaaS від ТОВ «СтрІМ Алго».">
  <link rel="canonical" href="https://strim.in.ua/auth/login.php">
  <meta name="theme-color" content="#050810">
  <link rel="icon" href="/favicon.ico" sizes="48x48">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <title>Вхід у кабінет StrIM — Binance spot</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/strim-public.css?v=20260923legal">
<link rel="stylesheet" href="/assets/css/strim-premium.css?v=20260923legal">
</head>
<body class="auth-page premium-page">
  <div class="ua-stripe" aria-hidden="true"></div>
  <div class="page-shell">
    <div class="page-bg" aria-hidden="true"></div>
    <header class="pr-header"><a class="pr-brand" href="/?v=20260923legal">StrIM<span>Український продукт</span></a><nav class="pr-primary" aria-label="Швидкі переходи"><a href="/instruction.html?v=20260923legal">Інструкція</a><a href="/auth/register.php?next=<?= urlencode($next) ?>">Реєстрація</a></nav><button type="button" class="theme-toggle" id="theme-toggle" aria-label="Увімкнути світлу тему" title="Тема оформлення">
          <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 18v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m18 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
          <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
        </button><details class="pr-menu"><summary>Меню <span aria-hidden="true">☰</span></summary><nav class="strim-nav" aria-label="Навігація сайтом"><a class="chip" href="/consumer.html?v=20260923legal">Споживачу</a><a class="chip" href="/?v=20260923legal">Головна</a><a class="chip" href="/about.html?v=20260923legal">Про StrIM</a><a class="chip" href="/tariffs.html?v=20260923legal">Тарифи</a><a class="chip" href="/instruction.html?v=20260923legal">Інструкція</a><a class="chip" href="/security.html?v=20260923legal">Безпека</a><a class="chip" href="/faq.html?v=20260923legal">Запитання</a><a class="chip" href="/blog/?v=20260923legal">Блог</a><a class="chip" href="/auth/login.php">Увійти</a><a class="chip primary" href="/auth/register.php?next=<?= urlencode($next) ?>">Створити акаунт</a></nav></details></header>

    <main class="auth-main pr-auth"><aside class="pr-auth-aside"><p class="pr-eyebrow">StrIM / ВАШ КАБІНЕТ</p><h2>З поверненням.<br>Усе під рукою.</h2><p>Ваші стратегії, наступні дії, журнал і баланс — у кабінеті StrIM.</p><img src="/assets/guide/guide-assistant.png" alt="Робот-помічник StrIM" width="1254" height="1254"><a href="/instruction.html?v=20260923legal">Потрібна підказка? Відкрити гід →</a></aside>
      <div class="auth-card">
        <div class="auth-brand auth-brand--wide">
          <img src="/assets/branding/strim-auth-logo.png?v=20260529b" srcset="/assets/branding/strim-auth-logo.png?v=20260529b 1x, /assets/branding/strim-auth-logo@2x.png?v=20260529b 2x" alt="StrIM — кабінет автоматизації Binance spot" width="320" height="150" decoding="async">
        </div>
        <h1>Вхід у кабінет StrIM</h1>
        <p class="pr-form-intro">Введіть дані свого акаунту StrIM. Якщо забули пароль, скористайтеся посиланням під формою.</p>
        <div class="err" style="display:<?= $msg ? 'block' : 'none' ?>"><?= htmlspecialchars($msg, ENT_QUOTES) ?></div>
        <form method="post" action="/auth/do_login.php">
          <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">
          <label for="login-name">Логін</label>
          <input id="login-name" type="text" name="name" minlength="3" maxlength="64" required autocomplete="username">
          <label for="login-pass">Пароль</label>
          <input id="login-pass" type="password" name="pass" minlength="6" required autocomplete="current-password">
          <button type="submit">Увійти</button>
          <p class="auth-links">Немає акаунта? <a href="/auth/register.php?next=<?= urlencode($next) ?>">Зареєструватись</a></p>
          <p class="auth-links"><a href="/auth/forgot_password.php">Забули пароль?</a></p>
          <p class="auth-links"><a href="/?v=20260923legal">На головну</a></p>
        </form>
      </div>
    </main>

    <footer class="strim-footer">
      <div class="company">ТОВ «СтрІМ Алго» · StrIM</div>
      <div>Інформація не є фінансовою порадою.</div>
      <div style="margin-top:12px"><a href="/tariffs.html?v=20260923legal">Тарифи</a> · <a href="/offer.html?v=20260923legal">Оферта</a> · <a href="mailto:support@strim.in.ua">Контакт</a></div>
    <p><a href="/consumer.html?v=20260923legal">Споживачу</a> · <a href="/legal/">Архів документів</a> · <button type="button" class="strim-consent__btn" data-cookie-settings>Cookie</button></p></footer>
  </div>
  <script src="/assets/js/strim-theme.js?v=20260512_pubnav" defer></script>
  <script src="/assets/js/strim-public-nav.js?v=20260927a" defer></script>
  <script src="/assets/js/strim-lang-switch.js?v=20260923legal" defer></script>
  <script src="/assets/js/strim-cookie-consent.js?v=20260927a" defer></script>
<script src="/assets/js/strim-premium.js?v=20260923legal" defer></script>
</body>
</html>
