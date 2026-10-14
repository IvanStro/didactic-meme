/**
 * Google Ads «Реєстрація» — після успішного do_register (сторінка завантаження).
 */
(function () {
  'use strict';

  function fireConversion(data) {
    if ((localStorage.getItem('strim_consent_v2') !== 'all' || !(Date.now()-Date.parse(localStorage.getItem('strim_consent_v2_time')||'') < 180*86400000)) || typeof gtag !== 'function' || !data.send_to) {
      return false;
    }
    gtag('event', 'conversion', {
      send_to: data.send_to,
      value: data.registration_value_uah || 10,
      currency: 'UAH'
    });
    return true;
  }

  function tryFire(data) {
    if (!data || !data.fire || !data.send_to) {
      return;
    }
    // Consent Mode already set at page level; always send conversion ping.
    fireConversion(data);
  }

  function requestConversion(){
  if (localStorage.getItem('strim_consent_v2') !== 'all') return;
  fetch('/api/google_ads_reg_pending.php', { credentials: 'same-origin', cache: 'no-store' })
    .then(function (r) {
      return r.json();
    })
    .then(tryFire)
    .catch(function () { /* ignore */ });
  }
  requestConversion();
  window.addEventListener('strim-consent-updated',function(e){if(e.detail && e.detail.granted)requestConversion();});
})();
