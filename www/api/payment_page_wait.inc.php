<?php
declare(strict_types=1);
/** @var string $title */
?>
<!doctype html>
<html lang="uk">
<head>
<?php payment_page_gtag_head(); ?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow">
  <title><?= payment_page_h($title) ?></title>
  <link rel="stylesheet" href="/assets/css/strim-public.css?v=20260923legal">
</head>
<body>
  <div class="ua-stripe" aria-hidden="true"></div>
  <div class="page-shell">
    <div class="page-bg" aria-hidden="true"></div>
    <main class="page-main" style="max-width:640px;margin:48px auto;padding:0 16px">
      <div class="card tariffs-sheet">
        <h1>Підтверджуємо оплату</h1>
        <p class="muted" id="wait-msg">Зачекайте, перевіряємо платіж у системі (зазвичай до 2 хвилин)…</p>
        <p class="muted" style="margin-top:12px;font-size:14px">
          <a href="/tariffs.html">Повернутись до тарифів</a> ·
          <a href="/dashboard.php">Кабінет</a>
        </p>
      </div>
    </main>
  </div>
  <script src="/assets/js/strim-cookie-consent.js?v=20260927a" defer></script>
  <script>
  (function () {
    var msg = document.getElementById('wait-msg');
    var started = Date.now();
    var MAX = 300000;
    var POLL = 3500;

    function poll() {
      fetch('/api/billing_purchase_confirmed.php', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          var j = res.j || {};
          if (res.ok && j.confirmed && j.transaction_id) {
            window.location.replace('/payment/success.php?tx=' + encodeURIComponent(j.transaction_id));
            return;
          }
          if (Date.now() - started < MAX) {
            window.setTimeout(poll, POLL);
            return;
          }
          if (msg) {
            msg.textContent = 'Платіж ще обробляється. Перевірте кабінет через кілька хвилин або напишіть у підтримку.';
          }
        })
        .catch(function () {
          if (Date.now() - started < MAX) {
            window.setTimeout(poll, POLL);
          }
        });
    }
    poll();
  })();
  </script>
</body>
</html>
