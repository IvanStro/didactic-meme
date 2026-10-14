/**
 * StrIM landing — поява блоків при скролі (Intersection Observer).
 */
(function () {
  'use strict';
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.querySelectorAll('.reveal').forEach(function (el) {
      el.classList.add('is-visible');
    });
    return;
  }
  var els = document.querySelectorAll('.reveal');
  if (!els.length || !('IntersectionObserver' in window)) {
    els.forEach(function (el) { el.classList.add('is-visible'); });
    return;
  }
  var io = new IntersectionObserver(
    function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          io.unobserve(entry.target);
        }
      });
    },
    { root: null, rootMargin: '0px 0px -8% 0px', threshold: 0.12 }
  );
  els.forEach(function (el) { io.observe(el); });
})();
