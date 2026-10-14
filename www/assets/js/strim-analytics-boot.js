/** Синхронний boot: page_view через GET-gif до defer (краще для bounce з Ads). */
(function boot() {
  function consentChoice() {
    try {
      var c = localStorage.getItem('strim_consent_v2') || '';
      var ts = Date.parse(localStorage.getItem('strim_consent_v2_time') || '');
      if (!ts || Date.now() - ts >= 180 * 86400000) return '';
      return c;
    } catch (e) { return ''; }
  }
  function firstPartyOk(c) {
    return c === 'all' || c === 'essential';
  }
  function ensureConsentCookie(c) {
    if (!firstPartyOk(c)) return;
    try {
      var base = '; Path=/; Max-Age=15552000; SameSite=Lax';
      if (location.protocol === 'https:') base += '; Secure';
      document.cookie = 'strim_consent_v2=' + c + base;
    } catch (e) {}
  }

  var choice = consentChoice();
  if (!firstPartyOk(choice)) {
    window.addEventListener('strim-consent-updated', function onConsent(e) {
      if (e.detail && e.detail.firstParty) {
        window.removeEventListener('strim-consent-updated', onConsent);
        boot();
      }
    });
    return;
  }
  ensureConsentCookie(choice);

  var VID = 'strim_vid';
  var SID = 'strim_sid';
  var ATTR = 'strim_attr';
  var PVKEY = 'strim_pv_boot';

  function rnd() {
    return Math.random().toString(36).slice(2) + Date.now().toString(36);
  }
  function id(storage, key) {
    try {
      var v = storage.getItem(key);
      if (!v) { v = rnd(); storage.setItem(key, v); }
      return v;
    } catch (e) { return rnd(); }
  }
  function attr() {
    var out = { utm_source: '', utm_medium: '', utm_campaign: '', gclid: '' };
    try {
      var raw = sessionStorage.getItem(ATTR) || localStorage.getItem(ATTR);
      if (raw) out = JSON.parse(raw) || out;
    } catch (e) {}
    var q = new URLSearchParams(location.search || '');
    ['utm_source', 'utm_medium', 'utm_campaign', 'gclid'].forEach(function (k) {
      var v = q.get(k);
      if (v) out[k] = v;
    });
    if (!out.utm_source && q.get('gad_source')) out.utm_source = 'google';
    if (!out.utm_medium && (q.get('gclid') || q.get('gad_campaignid'))) out.utm_medium = 'cpc';
    if (!out.utm_campaign && q.get('gad_campaignid')) out.utm_campaign = 'gads:' + q.get('gad_campaignid');
    try {
      sessionStorage.setItem(ATTR, JSON.stringify(out));
      localStorage.setItem(ATTR, JSON.stringify(out));
    } catch (e) {}
    return out;
  }
  function device() {
    var ua = (navigator.userAgent || '').toLowerCase();
    if (/ipad|tablet/.test(ua)) return 'tablet';
    if (/mobi|iphone|android/.test(ua)) return 'mobile';
    return 'desktop';
  }
  function refHost() {
    try {
      if (!document.referrer) return '';
      var h = new URL(document.referrer).host || '';
      var own = (location.hostname || '').replace(/^www\./, '');
      var rh = h.replace(/^www\./, '');
      if (rh === own || /(^|\.)strim\.in\.ua$|(^|\.)luckydrop\.uno$/.test(rh)) return '';
      return h;
    } catch (e) { return ''; }
  }
  function persistAttrCookies(a, vid, sid) {
    try {
      var maxAge = 60 * 60 * 24 * 90;
      var base = '; Path=/; Max-Age=' + maxAge + '; SameSite=Lax';
      if (location.protocol === 'https:') base += '; Secure';
      document.cookie = 'strim_vid=' + encodeURIComponent(vid) + base;
      document.cookie = 'strim_sid=' + encodeURIComponent(sid) + base;
      document.cookie = 'strim_attr=' + encodeURIComponent(JSON.stringify(a)) + base;
    } catch (e) {}
  }
  function channel(a) {
    if ((a.gclid || '').trim()) return 'paid_search';
    var m = (a.utm_medium || '').toLowerCase();
    var s = (a.utm_source || '').toLowerCase();
    if (m === 'cpc' || m === 'ppc' || m === 'paid') return 'paid_search';
    if (s === 'instagram' || s === 'facebook' || s === 'fb' || s === 'tiktok' || s === 'telegram' || s === 'ig') return 'social';
    var rh = refHost();
    if (/google\.|bing\./i.test(rh)) return 'organic_search';
    if (/instagram\.|facebook\.|fb\.|t\.co|twitter\.|tiktok\./i.test(rh)) return 'social';
    return rh ? 'referral' : 'direct';
  }

  function sendGif(eventName, path, a, sc, vid, sid, props) {
    var qs = [
      'v=' + encodeURIComponent(vid),
      's=' + encodeURIComponent(sid),
      'e=' + encodeURIComponent(eventName),
      'p=' + encodeURIComponent(path.slice(0, 240)),
      'sc=' + encodeURIComponent(sc),
      'd=' + encodeURIComponent(device()),
      'rh=' + encodeURIComponent(refHost().slice(0, 120)),
      'us=' + encodeURIComponent(a.utm_source || ''),
      'um=' + encodeURIComponent(a.utm_medium || ''),
      'uc=' + encodeURIComponent(a.utm_campaign || ''),
      'g=' + encodeURIComponent(a.gclid || ''),
      'lg=' + encodeURIComponent(navigator.language || ''),
      'tz=' + encodeURIComponent((Intl.DateTimeFormat().resolvedOptions().timeZone || '').slice(0, 60))
    ];
    if (props && typeof props === 'object') {
      try {
        qs.push('pr=' + encodeURIComponent(JSON.stringify(props).slice(0, 800)));
      } catch (e) {}
    }
    var img = new Image(1, 1);
    img.decoding = 'async';
    img.src = '/api/session_public.php?' + qs.join('&');
  }

  var path = (location.pathname || '/') + (location.search || '');
  var bootKey = path;
  try {
    if (sessionStorage.getItem(PVKEY) === bootKey) return;
    sessionStorage.setItem(PVKEY, bootKey);
  } catch (e) {}

  var a = attr();
  var sc = channel(a);
  var vid = id(localStorage, VID);
  var sid = id(sessionStorage, SID);
  persistAttrCookies(a, vid, sid);
  sendGif('page_view', path, a, sc, vid, sid);
  if ((location.pathname || '') === '/auth/register.php') {
    sendGif('register_view', path, a, sc, vid, sid);
  }

  window.strimAnalyticsBoot = {
    sendGif: sendGif,
    attr: attr,
    channel: channel,
    vid: function () { return id(localStorage, VID); },
    sid: function () { return id(sessionStorage, SID); },
    ensureConsentCookie: function () { ensureConsentCookie(consentChoice()); }
  };
})();
