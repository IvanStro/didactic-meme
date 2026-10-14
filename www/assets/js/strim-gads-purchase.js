/**
 * Google Ads «Купівля» — лише після підтвердження WhitePay у БД (webhook).
 * Працює на /tariffs.html?pay=success (користувач повернувся з WhitePay).
 */
(function () {
  'use strict';

  var params = new URLSearchParams(window.location.search);
  if (params.get('pay') !== 'success') {
    return;
  }

  var POLL_MS = 4000;
  var MAX_MS = 300000;
  var LS_PREFIX = 'strim_gads_purchase_';
  var started = Date.now();
  var banner = document.getElementById('pay-banner');

  function hasConsent() {
    try {
      return (localStorage.getItem('strim_consent_v2') === 'all' && Date.now()-Date.parse(localStorage.getItem('strim_consent_v2_time')||'') < 180*86400000);
    } catch (e) {
      return false;
    }
  }

  function fireConversion(data) {
    if (typeof gtag !== 'function') {
      return false;
    }
    var sendTo = data.send_to || '';
    if (!sendTo) {
      return false;
    }
    var tx = data.transaction_id || '';
    if (tx) {
      try {
        if (localStorage.getItem(LS_PREFIX + tx)) {
          return true;
        }
      } catch (e) { /* ignore */ }
    }
    gtag('event', 'conversion', {
      send_to: sendTo,
      value: data.purchase_value_uah || 500,
      currency: 'UAH',
      transaction_id: tx || undefined
    });
    if (tx) {
      try {
        localStorage.setItem(LS_PREFIX + tx, String(Date.now()));
      } catch (e) { /* ignore */ }
    }
    return true;
  }

  function setBanner(text) {
    if (!banner) {
      return;
    }
    banner.textContent = text;
    banner.removeAttribute('hidden');
    banner.style.display = 'block';
  }

  function poll() {
    fetch('/api/billing_purchase_confirmed.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        return r.json().then(function (j) {
          return { ok: r.ok, j: j };
        });
      })
      .then(function (res) {
        var j = res.j || {};
        if (!res.ok) {
          if (j.error === 'login_required') {
            setBanner('Увійдіть у кабінет, щоб ми могли зарахувати оплату та підтвердити підписку.');
          }
          schedule();
          return;
        }
        if (j.confirmed) {
          setBanner('Оплату підтверджено. Доступ активний' + (j.paid_until ? ' до ' + j.paid_until : '') + '.');
          if (hasConsent() && j.send_to) {
            fireConversion(j);
          } else if (hasConsent() === false && j.send_to) {
            window.addEventListener(
              'strim-consent-updated',
              function once(e) {
                if (e.detail && e.detail.granted) {
                  fireConversion(j);
                }
              },
              { once: true }
            );
          } else if (!j.send_to) {
            console.info('[StrIM] Google Ads: GOOGLE_ADS_PURCHASE_LABEL не налаштовано в .env');
          }
          return;
        }
        if (j.pending_webhook) {
          setBanner('Оплату отримано. Зачекайте, підтверджуємо платіж (зазвичай до 2 хв)…');
        } else {
          setBanner('Дякуємо! Якщо оплата пройшла успішно, доступ оновиться автоматично протягом кількох хвилин.');
        }
        schedule();
      })
      .catch(function () {
        schedule();
      });
  }

  function schedule() {
    if (Date.now() - started >= MAX_MS) {
      return;
    }
    window.setTimeout(poll, POLL_MS);
  }

  poll();
})();
