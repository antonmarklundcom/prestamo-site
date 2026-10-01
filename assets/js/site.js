/* engine/assets/js/site.js — progressive enhancement only.
   Reading, navigation and every page work with JavaScript disabled.
   No lead form, no WhatsApp, no third-party loader without consent. */
(function () {
  'use strict';

  var STORAGE_KEY = 'consent.optional';

  /* ---------------------------------------------------------- header */
  var hdr = document.querySelector('[data-hdr]');
  if (hdr) {
    var onScroll = function () { hdr.classList.toggle('is-stuck', window.scrollY > 8); };
    onScroll();
    document.addEventListener('scroll', onScroll, { passive: true });

    var burger = document.querySelector('[data-hdr-burger]');
    var panel = document.querySelector('[data-hdr-panel]');
    if (burger && panel) {
      burger.addEventListener('click', function () {
        var open = panel.classList.toggle('is-open');
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }
  }

  /* ------------------------------------------------ deducible calculator */
  var calc = document.querySelector('[data-calc="deducible"]');
  if (calc) {
    var damage = calc.querySelector('[data-calc-damage]');
    var deductible = calc.querySelector('[data-calc-deductible]');
    var out = calc.querySelector('[data-calc-out]');
    var money = new Intl.NumberFormat('es-PY', { style: 'currency', currency: 'PYG', maximumFractionDigits: 0 });

    var update = function () {
      var d = Number(damage.value);
      var f = Number(deductible.value);
      if (!isFinite(d) || !isFinite(f) || d < 0 || f < 0) {
        out.textContent = 'Ingresá montos válidos, en guaraníes y sin signos.';
        return;
      }
      var paid = Math.max(0, d - f);
      out.textContent = 'Ejemplo: sobre un daño de ' + money.format(d) + ' con un deducible fijo de '
        + money.format(f) + ', la aseguradora pagaría ' + money.format(paid) + ' y quedarían a tu cargo '
        + money.format(Math.min(d, f)) + '. Es una simulación didáctica, no una oferta.';
    };
    damage.addEventListener('input', update);
    deductible.addEventListener('input', update);
    update();
  }

  /* --------------------------------------------------------- cookie bar */
  var bar = document.querySelector('[data-cookiebar]');
  function readChoice() {
    try { return window.localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
  }
  function writeChoice(value) {
    try { window.localStorage.setItem(STORAGE_KEY, value); } catch (e) { /* storage blocked */ }
  }
  function loadAnalytics() {
    var id = typeof window.ANALYTICS_ID === 'string' ? window.ANALYTICS_ID.trim() : '';
    if (!id || window.__analyticsLoaded) return;
    window.__analyticsLoaded = true;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', id, { anonymize_ip: true });
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
    document.head.appendChild(s);
  }

  if (bar) {
    var choice = readChoice();
    if (choice === 'granted') loadAnalytics();
    if (!choice) bar.hidden = false;

    var accept = bar.querySelector('[data-cookie-accept]');
    var reject = bar.querySelector('[data-cookie-reject]');
    if (accept) accept.addEventListener('click', function () { writeChoice('granted'); bar.hidden = true; loadAnalytics(); });
    if (reject) reject.addEventListener('click', function () { writeChoice('denied'); bar.hidden = true; });
  }

  /* Any link with data-cookie-reopen lets the reader change the choice. */
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest ? e.target.closest('[data-cookie-reopen]') : null;
    if (!trigger || !bar) return;
    e.preventDefault();
    bar.hidden = false;
  });
})();
