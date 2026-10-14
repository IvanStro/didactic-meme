/**
 * Публічні сторінки:
 * 1) якщо є сесія — «Увійти» → кабінет, ховаємо «Реєстрація»;
 * 2) lightweight аналітика (page_view + register funnel).
 */
(function () {
  var VID_KEY = 'strim_vid';
  var SID_KEY = 'strim_sid';
  var ATTR_KEY = 'strim_attr';

  function applyLoggedInNav() {
    document.querySelectorAll('a[href="/auth/login.php"]').forEach(function (a) {
      a.setAttribute('href', '/dashboard.php');
      a.textContent = a.closest('.pr-primary') ? 'Кабінет' : 'Повернутися до кабінету';
    });
    document.querySelectorAll('nav.strim-nav a.chip[href^="/auth/register.php"]').forEach(function (a) {
      a.setAttribute('hidden', '');
      a.style.display = 'none';
    });
  }

  function consentChoice() {
    try {
      var c = localStorage.getItem('strim_consent_v2') || '';
      var ts = Date.parse(localStorage.getItem('strim_consent_v2_time') || '');
      if (!ts || Date.now() - ts >= 180 * 86400000) return '';
      return c;
    } catch (e) { return ''; }
  }
  function firstPartyOk() {
    var c = consentChoice();
    return c === 'all' || c === 'essential';
  }
  function ensureConsentCookie() {
    var c = consentChoice();
    if (!firstPartyOk()) return;
    try {
      var base = '; Path=/; Max-Age=15552000; SameSite=Lax';
      if (location.protocol === 'https:') base += '; Secure';
      document.cookie = 'strim_consent_v2=' + c + base;
    } catch (e) {}
  }

  function rnd() {
    return Math.random().toString(36).slice(2) + Date.now().toString(36);
  }

  function getOrCreateStorageKey(storage, key) {
    try {
      var v = storage.getItem(key);
      if (!v) {
        v = rnd();
        storage.setItem(key, v);
      }
      return v;
    } catch (e) {
      return rnd();
    }
  }

  function detectDevice() {
    var ua = (navigator.userAgent || '').toLowerCase();
    if (/ipad|tablet/.test(ua)) return 'tablet';
    if (/mobi|iphone|android/.test(ua)) return 'mobile';
    return 'desktop';
  }

  function getRefHost() {
    try {
      if (!document.referrer) return '';
      var h = new URL(document.referrer).host || '';
      var own = (location.hostname || '').replace(/^www\./, '');
      var rh = h.replace(/^www\./, '');
      if (rh === own || /(^|\.)strim\.in\.ua$|(^|\.)luckydrop\.uno$/.test(rh)) return '';
      return h;
    } catch (e) {
      return '';
    }
  }

  function readAttribution() {
    var out = {
      utm_source: '',
      utm_medium: '',
      utm_campaign: '',
      utm_term: '',
      utm_content: '',
      gclid: '',
      ref: ''
    };
    try {
      var raw = sessionStorage.getItem(ATTR_KEY) || localStorage.getItem(ATTR_KEY);
      if (raw) {
        var saved = JSON.parse(raw);
        if (saved && typeof saved === 'object') {
          out = saved;
        }
      }
    } catch (e) {}

    var search = new URLSearchParams(window.location.search || '');
    var keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'ref'];
    keys.forEach(function (k) {
      var v = search.get(k);
      if (v && !out[k]) out[k] = v;
    });
    if (!out.utm_source && search.get('gad_source')) out.utm_source = 'google';
    if (!out.utm_medium && (search.get('gclid') || search.get('gad_campaignid'))) out.utm_medium = 'cpc';
    if (!out.utm_campaign && search.get('gad_campaignid')) out.utm_campaign = 'gads:' + search.get('gad_campaignid');
    if (!out.ref && document.referrer) {
      try {
        out.ref = new URL(document.referrer).searchParams.get('ref') || '';
      } catch (e) {}
    }
    return out;
  }

  function persistAttribution(att) {
    try {
      var s = JSON.stringify(att);
      sessionStorage.setItem(ATTR_KEY, s);
      localStorage.setItem(ATTR_KEY, s);
      var maxAge = 60 * 60 * 24 * 90;
      var base = '; Path=/; Max-Age=' + maxAge + '; SameSite=Lax';
      if (location.protocol === 'https:') base += '; Secure';
      document.cookie = 'strim_attr=' + encodeURIComponent(s) + base;
      document.cookie = 'strim_vid=' + encodeURIComponent(getOrCreateStorageKey(window.localStorage, VID_KEY)) + base;
      document.cookie = 'strim_sid=' + encodeURIComponent(getOrCreateStorageKey(window.sessionStorage, SID_KEY)) + base;
    } catch (e) {}
  }

  function classifySource(att, refHost) {
    var gclid = (att.gclid || '').trim();
    var utmMedium = (att.utm_medium || '').toLowerCase();
    var utmSource = (att.utm_source || '').toLowerCase();
    if (gclid !== '') return 'paid_search';
    if (utmMedium === 'cpc' || utmMedium === 'ppc' || utmMedium === 'paid') return 'paid_search';
    if (utmSource === 'google' && utmMedium === 'cpc') return 'paid_search';
    if (utmSource === 'instagram' || utmSource === 'facebook' || utmSource === 'fb'
        || utmSource === 'tiktok' || utmSource === 'telegram' || utmSource === 'ig') {
      return 'social';
    }
    if (refHost) {
      if (/google\./i.test(refHost)) return 'organic_search';
      if (/bing\./i.test(refHost)) return 'organic_search';
      if (/facebook\.|fb\./i.test(refHost)) return 'social';
      if (/t\.co|twitter\.|instagram\.|tiktok\./i.test(refHost)) return 'social';
      return 'referral';
    }
    return 'direct';
  }

  function sendEvent(name, props) {
    if (!firstPartyOk()) return;
    ensureConsentCookie();
    var att = readAttribution();
    persistAttribution(att);
    var rh = getRefHost();
    var sourceChannel = classifySource(att, rh);
    var path = window.location.pathname || '/';
    var qs = window.location.search || '';
    if (qs && path.indexOf('?') === -1) {
      path += qs;
    }
    var vid = getOrCreateStorageKey(window.localStorage, VID_KEY);
    var sid = getOrCreateStorageKey(window.sessionStorage, SID_KEY);
    var propsObj = props || {};

    // Лише GIF-beacon (як page_view): надійно при кліку/сабміті, без подвійного запису
    if (window.strimAnalyticsBoot && typeof window.strimAnalyticsBoot.sendGif === 'function') {
      window.strimAnalyticsBoot.sendGif(name, path, att, sourceChannel, vid, sid, propsObj);
      return;
    }
    var q = [
      'v=' + encodeURIComponent(vid),
      's=' + encodeURIComponent(sid),
      'e=' + encodeURIComponent(name),
      'p=' + encodeURIComponent(path.slice(0, 240)),
      'sc=' + encodeURIComponent(sourceChannel),
      'd=' + encodeURIComponent(detectDevice()),
      'rh=' + encodeURIComponent(rh.slice(0, 120)),
      'us=' + encodeURIComponent(att.utm_source || ''),
      'um=' + encodeURIComponent(att.utm_medium || ''),
      'uc=' + encodeURIComponent(att.utm_campaign || ''),
      'g=' + encodeURIComponent(att.gclid || ''),
      'lg=' + encodeURIComponent(navigator.language || ''),
      'tz=' + encodeURIComponent((Intl.DateTimeFormat().resolvedOptions().timeZone || '').slice(0, 60)),
      'pr=' + encodeURIComponent(JSON.stringify(propsObj).slice(0, 800))
    ].join('&');
    try {
      var img = new Image(1, 1);
      img.src = '/api/session_public.php?' + q;
    } catch (e) {}
  }

  function bootPageViewSent() {
    try {
      var path = (window.location.pathname || '/') + (window.location.search || '');
      return sessionStorage.getItem('strim_pv_boot') === path;
    } catch (e) {
      return false;
    }
  }

  window.strimTrack = sendEvent;

  function hasCookie(name) {
    return document.cookie.split(';').some(function (entry) {
      return entry.trim().indexOf(name + '=') === 0;
    });
  }
  function clearCookie(name) {
    document.cookie = name + '=; Max-Age=0; path=/; SameSite=Lax';
  }

  if (!bootPageViewSent()) {
    sendEvent('page_view', { title: document.title || '', via: 'defer' });
  }

  // register_submit пише лише сервер (do_register.php) — без подвійного підрахунку

  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href*="/auth/register.php"]');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    sendEvent('cta_click', { href: href, label: (a.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80) });
    if (consentChoice() === 'all' && typeof window.gtag === 'function') {
      try { window.gtag('event', 'cta_click', { event_category: 'funnel', event_label: 'register' }); } catch (err) {}
    }
  }, true);

  if (window.location.pathname === '/dashboard.php'
      && hasCookie('strim_reg_event')) {
    sendEvent('register_success', { via: 'client' });
    clearCookie('strim_reg_event');
  }

  fetch('/api/session_public.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (j && j.ok && j.logged_in) {
        applyLoggedInNav();
      }
    })
    .catch(function () {});
})();
