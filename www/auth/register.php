<?php
declare(strict_types=1);
require_once __DIR__.'/../api/_legal.php';
$legalManifest = strim_legal_manifest();
$next = $_GET['next'] ?? '/dashboard.php';
$msg  = $_GET['msg'] ?? '';
$prefillEmail = trim((string)($_GET['email'] ?? ''));
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
  <script src="/assets/js/strim-analytics-boot.js?v=20260927a"></script>
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="description" content="Реєстрація StrIM: тест до першого поповнення, до 3 стратегій, Binance spot через API без виведення.">
  <link rel="canonical" href="https://strim.in.ua/auth/register.php">
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://strim.in.ua/auth/register.php">
  <meta property="og:title" content="Реєстрація в StrIM — перший крок">
  <meta property="og:description" content="Створіть акаунт StrIM для спот-торгівлі на Binance.">
  <meta property="og:image" content="https://strim.in.ua/assets/branding/strim-og.png">
  <meta property="og:locale" content="uk_UA">
  <meta name="theme-color" content="#050810">
  <link rel="icon" href="/favicon.ico" sizes="48x48">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <title>Реєстрація в StrIM — перший крок, Binance spot</title>
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
    <header class="pr-header"><a class="pr-brand" href="/?v=20260923legal">StrIM<span>Український продукт</span></a><nav class="pr-primary" aria-label="Швидкі переходи"><a href="/instruction.html?v=20260923legal">Інструкція</a><a href="/auth/login.php">Увійти</a></nav><button type="button" class="theme-toggle" id="theme-toggle" aria-label="Увімкнути світлу тему" title="Тема оформлення">
          <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 18v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m18 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
          <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
        </button><details class="pr-menu"><summary>Меню <span aria-hidden="true">☰</span></summary><nav class="strim-nav" aria-label="Навігація сайтом"><a class="chip" href="/consumer.html?v=20260923legal">Споживачу</a><a class="chip" href="/?v=20260923legal">Головна</a><a class="chip" href="/about.html?v=20260923legal">Про StrIM</a><a class="chip" href="/tariffs.html?v=20260923legal">Тарифи</a><a class="chip" href="/instruction.html?v=20260923legal">Інструкція</a><a class="chip" href="/security.html?v=20260923legal">Безпека</a><a class="chip" href="/faq.html?v=20260923legal">Запитання</a><a class="chip" href="/blog/?v=20260923legal">Блог</a><a class="chip" href="/auth/login.php">Увійти</a><a class="chip primary" href="/auth/register.php?next=/dashboard.php">Створити акаунт</a></nav></details></header>

    <main class="auth-main pr-auth"><aside class="pr-auth-aside"><p class="pr-eyebrow">StrIM / ВАШ КАБІНЕТ</p><h2>Почнемо<br>зі знайомства.</h2><p>Створіть акаунт. Далі — підключення Binance та перевірка першої стратегії.</p><img src="/assets/guide/guide-assistant.png" alt="Робот-помічник StrIM" width="1254" height="1254"><a href="/instruction.html?v=20260923legal">Потрібна підказка? Відкрити гід →</a></aside>
      <div class="auth-card register-page">
        <h1>Реєстрація в StrIM</h1>
        <p class="pr-form-intro">Підтвердьте свою пошту та створіть пароль. Тестовий доступ діє до першого поповнення: до 3 стратегій, перша покупка до 10 USDT. <strong>Це реальна торгівля з ризиком збитків.</strong></p>
        <div class="err" style="display:<?= $msg ? 'block' : 'none' ?>"><?= htmlspecialchars($msg, ENT_QUOTES) ?></div>
        <form method="post" action="/auth/do_register.php">
          <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">
          <label for="reg-name">Логін (нікнейм)</label>
          <input id="reg-name" type="text" name="name" minlength="3" maxlength="64" required autocomplete="username">
          <label for="reg-email">Електронна пошта</label>
          <div class="reg-email-row">
            <input id="reg-email" type="email" name="email" maxlength="255" required autocomplete="email" value="<?= htmlspecialchars($prefillEmail, ENT_QUOTES) ?>">
            <button type="button" class="btn-secondary" id="btnSendEmailCode">Надіслати код</button>
          </div>
          <p id="emailCodeMsg" class="muted" style="margin:6px 0 0;font-size:13px;display:none"></p>
          <label for="reg-email-code">Код з листа (6 цифр)</label>
          <input id="reg-email-code" type="text" name="email_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" minlength="6" required autocomplete="one-time-code" placeholder="000000">
          <p class="muted" style="margin:6px 0 0;font-size:13px">На вказану пошту надійде код. Без нього акаунт не створиться — це захист від помилкових адрес.</p>
          <label for="reg-tg">Телеграм</label>
          <input id="reg-tg" type="text" name="telegram" maxlength="255" placeholder="@нікнейм" autocomplete="off">
          <label for="reg-pass">Пароль</label>
          <input id="reg-pass" type="password" name="pass" minlength="6" required autocomplete="new-password">
          <label for="reg-pass2">Повторіть пароль</label>
          <input id="reg-pass2" type="password" name="pass2" minlength="6" required autocomplete="new-password">

          <label for="refInput" style="margin-top:10px">Реферальний код <span class="muted" style="font-weight:400">(необовʼязково)</span></label>
          <input name="ref" id="refInput" placeholder="STRIM" value="STRIM">

          <input type="hidden" name="legal_version" value="<?= htmlspecialchars($legalManifest['version'], ENT_QUOTES, 'UTF-8') ?>"><label class="reg-legal-check" for="reg-accept-legal">
            <input type="checkbox" name="accept_legal" id="reg-accept-legal" value="1" required>
            <span>Я приймаю <a href="/offer.html?v=20260923legal" target="_blank" rel="noopener">Договір публічної оферти</a>, <a href="/privacy.html?v=20260923legal" target="_blank" rel="noopener">Політику конфіденційності</a> та <a href="/risks.html?v=20260923legal" target="_blank" rel="noopener">Розкриття ризиків</a>. Розумію, що StrIM — програмний інструмент автоматизації, не інвестиційна порада; торгівля може призвести до втрати коштів.</span>
          </label>

          <label class="reg-legal-check"><input type="checkbox" name="adult" value="1" required><span>Мені виповнилося 18 років; я дієздатний/дієздатна та дію від власного імені або маю належні повноваження. Прошу надати доступ після реєстрації. Згоду на необов’язкову аналітику обираю окремо.</span></label><p><a href="/legal/2026-09-23/offer.html" target="_blank" rel="noopener">Зберегти редакцію умов від 23.09.2026</a> · <a href="/consumer.html">Споживачу</a></p><button type="submit" id="regSubmitBtn">Створити акаунт</button>
          <p class="auth-links">Вже маєте акаунт? <a href="/auth/login.php">Увійти</a></p>
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
  <script>
  (function(){
    var params = new URLSearchParams(window.location.search);
    var ref = params.get('ref');
    if(ref){
      var input = document.getElementById('refInput');
      if(input){ input.value = ref; }
    }
  })();
  </script>
  <style>
    .reg-email-row { display:flex; gap:8px; align-items:stretch; flex-wrap:wrap; }
    .reg-email-row input[type="email"] { flex:1 1 180px; min-width:0; }
    .btn-secondary {
      flex:0 0 auto;
      padding:10px 14px;
      border-radius:10px;
      border:1px solid rgba(255,255,255,.18);
      background:rgba(255,255,255,.06);
      color:inherit;
      font:inherit;
      cursor:pointer;
      white-space:nowrap;
    }
    .btn-secondary:disabled { opacity:.55; cursor:not-allowed; }
    .email-code-ok { color:#8bd38e; }
    .email-code-err { color:#ff9a9a; }
  </style>
  <script>
  (function(){
    var btn = document.getElementById('btnSendEmailCode');
    var emailInp = document.getElementById('reg-email');
    var msg = document.getElementById('emailCodeMsg');
    var cooldown = 0;
    var timer = null;

    function showMsg(text, ok){
      if (!msg) return;
      msg.style.display = text ? 'block' : 'none';
      msg.textContent = text || '';
      msg.className = 'muted ' + (ok ? 'email-code-ok' : 'email-code-err');
      msg.style.fontSize = '13px';
      msg.style.margin = '6px 0 0';
    }

    function tickCooldown(){
      if (!btn) return;
      if (cooldown <= 0) {
        btn.disabled = false;
        btn.textContent = 'Надіслати код';
        if (timer) { clearInterval(timer); timer = null; }
        return;
      }
      btn.disabled = true;
      btn.textContent = 'Повтор (' + cooldown + ' с)';
      cooldown--;
    }

    function startCooldown(sec){
      cooldown = Math.max(1, parseInt(sec, 10) || 120);
      tickCooldown();
      if (timer) clearInterval(timer);
      timer = setInterval(tickCooldown, 1000);
    }

    if (!btn || !emailInp) return;

    btn.addEventListener('click', function(){
      var email = (emailInp.value || '').trim();
      if (!email || email.indexOf('@') < 1) {
        showMsg('Спочатку введіть коректний email.', false);
        emailInp.focus();
        return;
      }
      btn.disabled = true;
      btn.textContent = 'Надсилання…';
      showMsg('', true);
      var body = new URLSearchParams();
      body.set('email', email);
      fetch('/auth/send_register_code.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (j && j.ok) {
            showMsg(j.message || 'Код надіслано.', true);
            startCooldown(120);
            var codeInp = document.getElementById('reg-email-code');
            if (codeInp) codeInp.focus();
          } else {
            showMsg((j && j.message) ? j.message : 'Не вдалося надіслати код.', false);
            startCooldown((j && j.retry_sec) ? j.retry_sec : 30);
          }
        })
        .catch(function(){
          showMsg('Помилка мережі. Спробуйте ще раз.', false);
          btn.disabled = false;
          btn.textContent = 'Надіслати код';
        });
    });
  })();
  </script>
  <script src="/assets/js/strim-cookie-consent.js?v=20260927a" defer></script>
<script src="/assets/js/strim-premium.js?v=20260923legal" defer></script>
</body>
</html>
