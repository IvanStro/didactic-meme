<?php
declare(strict_types=1);
/** @var string $title */
/** @var string $paidUntil */
/** @var bool $fireConversion */
/** @var string $sendTo */
/** @var float $value */
/** @var string $transactionId */
?>
<!doctype html>
<html lang="uk">
<head>
<?php payment_page_gtag_head(); ?>
<?php if ($fireConversion) : ?>
  <!-- Event snippet for Покупка — лише після підтвердження webhook -->
  <script>
    (function () {
      function sendPurchase() {
        try {
          if (localStorage.getItem('strim_consent_v2') !== 'all') {
            return;
          }
        } catch (e) {
          return;
        }
        if (typeof gtag !== 'function') {
          return;
        }
        gtag('event', 'conversion', {
          send_to: <?= json_encode($sendTo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
          value: <?= json_encode($value, JSON_UNESCAPED_UNICODE) ?>,
          currency: 'UAH',
          transaction_id: <?= json_encode($transactionId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
        });
      }
      sendPurchase();
      window.addEventListener('strim-consent-updated', function (e) {
        if (e.detail && e.detail.granted) {
          sendPurchase();
        }
      });
    })();
  </script>
<?php endif; ?>
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
        <h1>Дякуємо за оплату</h1>
        <p class="muted" style="margin-top:8px">
          Підписку <b>StrIM</b> активовано. Доступ діє <b>до <?= payment_page_h($paidUntil) ?></b>.
        </p>
        <div class="btnline" style="margin-top:20px">
          <a class="btn" href="/dashboard.php">Перейти в кабінет</a>
          <a class="chip" href="/tariffs.html" style="margin-left:8px">Тарифи</a>
        </div>
        <p class="muted" style="margin-top:16px;font-size:13px">
          Інформація на сайті не є фінансовою порадою. Торгівля криптоактивами пов’язана з ризиками.
        </p>
      </div>
    </main>
  </div>
  <script src="/assets/js/strim-cookie-consent.js?v=20260927a" defer></script>
</body>
</html>
