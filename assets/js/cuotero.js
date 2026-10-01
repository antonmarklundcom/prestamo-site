/* engine/calculators/cuotero.js — sistema francés, browser only.
   No dependency, no network call, no form element and no submission: the
   inputs never leave the reader's browser and nothing is stored.

   cuota = C * i / (1 - (1 + i)^-n),  i = tasa nominal anual / 1200
   With i = 0 the closed form is undefined, so cuota = C / n. */
(function () {
  'use strict';

  var MAX_CAPITAL = 10000000000; // Gs. 10.000.000.000
  var MIN_MONTHS = 3; // loans of 60 or 90 days or less are out of scope for this site
  var MAX_MONTHS = 600;
  var MAX_RATE = 200;
  var MAX_FEES = 100000000;
  var VISIBLE_ROWS = 12;

  /** Gs. 1.000.000 — Paraguayan guaraní, thousands separated by a dot. */
  function money(value) {
    var rounded = Math.round(value);
    var sign = rounded < 0 ? '-' : '';
    var digits = String(Math.abs(rounded));
    var out = '';
    for (var i = 0; i < digits.length; i += 1) {
      if (i > 0 && (digits.length - i) % 3 === 0) out += '.';
      out += digits.charAt(i);
    }
    return sign + 'Gs. ' + out;
  }

  function percent(value) {
    return (Math.round(value * 10000) / 10000).toString().replace('.', ',') + ' %';
  }

  /**
   * @returns {{ok: true, value: number}|{ok: false, message: string}}
   */
  function readNumber(input, label, opts) {
    var raw = input.value === null || input.value === undefined ? '' : String(input.value).trim();
    if (raw === '') {
      if (opts.optional) return { ok: true, value: 0 };
      return { ok: false, message: 'Ingresá ' + label + '. El campo está vacío.' };
    }
    var value = Number(raw.replace(',', '.'));
    if (!isFinite(value)) return { ok: false, message: 'El valor de ' + label + ' no es un número válido.' };
    if (value < opts.min) return { ok: false, message: label.charAt(0).toUpperCase() + label.slice(1) + ' no puede ser menor que ' + opts.min + '.' };
    if (value > opts.max) return { ok: false, message: label.charAt(0).toUpperCase() + label.slice(1) + ' supera el máximo admitido por esta simulación (' + opts.max + ').' };
    if (opts.integer && Math.floor(value) !== value) return { ok: false, message: label.charAt(0).toUpperCase() + label.slice(1) + ' debe ser un número entero de meses.' };
    return { ok: true, value: value };
  }

  /** Pure calculation. Exposed for tests; never touches the DOM. */
  function simulate(capital, months, annualRatePercent, monthlyFees) {
    var i = annualRatePercent / 1200;
    var base = i === 0 ? capital / months : (capital * i) / (1 - Math.pow(1 + i, -months));
    var payment = Math.round(base);
    var rows = [];
    var balance = capital;
    var totalInterest = 0;
    var totalPaid = 0;

    for (var n = 1; n <= months; n += 1) {
      var interest = Math.round(balance * i);
      var principal = payment - interest;
      if (n === months) principal = balance; // last row absorbs the rounding drift
      var due = principal + interest;
      balance = balance - principal;
      if (balance < 0) balance = 0;
      totalInterest += interest;
      totalPaid += due;
      rows.push({ n: n, payment: due, interest: interest, principal: principal, balance: balance });
    }

    return {
      monthlyRate: i,
      payment: payment,
      paymentWithFees: payment + monthlyFees,
      totalPaid: totalPaid,
      totalWithFees: totalPaid + monthlyFees * months,
      totalInterest: totalInterest,
      totalFees: monthlyFees * months,
      rows: rows
    };
  }

  function renderTable(host, result, monthlyFees) {
    var rows = result.rows;
    var head = '<thead><tr><th scope="col">Cuota</th><th scope="col">Pago</th>'
      + '<th scope="col">Interés</th><th scope="col">Amortización</th><th scope="col">Saldo</th></tr></thead>';

    function body(list) {
      var html = '';
      for (var k = 0; k < list.length; k += 1) {
        var r = list[k];
        html += '<tr><th scope="row">' + r.n + '</th><td>' + money(r.payment + monthlyFees) + '</td><td>'
          + money(r.interest) + '</td><td>' + money(r.principal) + '</td><td>' + money(r.balance) + '</td></tr>';
      }
      return html;
    }

    var first = rows.slice(0, VISIBLE_ROWS);
    var rest = rows.slice(VISIBLE_ROWS);

    var html = '<h3>Tabla de amortización</h3>'
      + '<div class="table-scroll" tabindex="0" role="region" aria-label="Tabla de amortización, primeras cuotas">'
      + '<table class="compare"><caption>Primeras ' + first.length + ' cuotas de ' + rows.length + '.</caption>'
      + head + '<tbody>' + body(first) + '</tbody></table></div>';

    if (rest.length) {
      html += '<details class="cuotero__rest"><summary>Ver las ' + rest.length + ' cuotas restantes</summary>'
        + '<div class="table-scroll" tabindex="0" role="region" aria-label="Tabla de amortización, cuotas restantes">'
        + '<table class="compare">' + head + '<tbody>' + body(rest) + '</tbody></table></div></details>';
    }

    host.innerHTML = html;
    host.hidden = false;
  }

  function wire(root) {
    var capitalEl = root.querySelector('[data-cuotero-capital]');
    var monthsEl = root.querySelector('[data-cuotero-months]');
    var rateEl = root.querySelector('[data-cuotero-rate]');
    var feesEl = root.querySelector('[data-cuotero-fees]');
    var errorEl = root.querySelector('[data-cuotero-error]');
    var resultsEl = root.querySelector('[data-cuotero-results]');
    var tableEl = root.querySelector('[data-cuotero-table]');
    var discEl = root.querySelector('[data-cuotero-disclaimer]');
    var cfg = {};
    try { cfg = JSON.parse(root.getAttribute('data-cuotero-config') || '{}'); } catch (e) { cfg = {}; }
    var copy = cfg.copy || {};
    if (!capitalEl || !monthsEl || !rateEl || !resultsEl) return;

    function showError(message) {
      errorEl.textContent = message;
      errorEl.hidden = false;
      [capitalEl, monthsEl, rateEl].forEach(function (el) { el.setAttribute('aria-invalid', 'true'); });
      resultsEl.innerHTML = '<p class="calc__out">Corregí los datos para ver la simulación.</p>';
      tableEl.hidden = true;
      tableEl.innerHTML = '';
      if (discEl) { discEl.hidden = true; discEl.innerHTML = ''; }
    }

    function clearError() {
      errorEl.hidden = true;
      errorEl.textContent = '';
      [capitalEl, monthsEl, rateEl].forEach(function (el) { el.removeAttribute('aria-invalid'); });
    }

    function update() {
      var capital = readNumber(capitalEl, 'el capital', { min: 1, max: MAX_CAPITAL });
      if (!capital.ok) return showError(capital.message);
      var months = readNumber(monthsEl, 'el plazo en meses', { min: MIN_MONTHS, max: MAX_MONTHS, integer: true });
      if (!months.ok) return showError(months.message);
      var rate = readNumber(rateEl, 'la tasa nominal anual', { min: 0, max: MAX_RATE });
      if (!rate.ok) return showError(rate.message);
      var fees = readNumber(feesEl, 'los gastos fijos mensuales', { min: 0, max: MAX_FEES, optional: true });
      if (!fees.ok) return showError(fees.message);

      clearError();
      var result = simulate(capital.value, months.value, rate.value, fees.value);

      var lines = '<p class="calc__out">Cuota fija estimada: ' + money(result.paymentWithFees) + ' por mes.</p>'
        + '<dl class="datalist">'
        + '<div class="datalist__row"><dt>Cuota pura (capital e interés)</dt><dd>' + money(result.payment) + '</dd></div>'
        + (fees.value > 0 ? '<div class="datalist__row"><dt>Gastos fijos mensuales</dt><dd>' + money(fees.value) + '</dd></div>' : '')
        + '<div class="datalist__row"><dt>Total a pagar</dt><dd>' + money(result.totalWithFees) + '</dd></div>'
        + '<div class="datalist__row"><dt>Total de intereses</dt><dd>' + money(result.totalInterest) + '</dd></div>'
        + (fees.value > 0 ? '<div class="datalist__row"><dt>Total de gastos fijos</dt><dd>' + money(result.totalFees) + '</dd></div>' : '')
        + '<div class="datalist__row"><dt>Tasa mensual aplicada</dt><dd>' + percent(result.monthlyRate * 100) + '</dd></div>'
        + '</dl>';
      resultsEl.innerHTML = lines;
      renderTable(tableEl, result, fees.value);
      renderDisclaimer(rate.value, result.totalWithFees);
    }

    /* Current month in Paraguay, as YYYY-MM, so the right BCP limit is picked. */
    function currentMonth() {
      try {
        return new Date().toLocaleDateString('en-CA', { timeZone: cfg.tz || 'America/Asuncion' }).slice(0, 7);
      } catch (e) {
        return new Date().toISOString().slice(0, 7);
      }
    }

    function esc(text) {
      return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* Illustrative-result notice (always) + usury warning (only above the limit).
       A figure is shown only if the dated BCP table has the current month; the
       typed rate is nominal, and a nominal rate above the limit is also above
       it as an effective rate, so the warning never under-reports. */
    function renderDisclaimer(rateValue, total) {
      if (!discEl || !copy.lead) return;
      var html = '<p><strong>' + esc(copy.lead) + '</strong> ' + esc(copy.body) + ' '
        + esc((copy.total || '').replace('{total}', money(total))) + ' ' + esc(copy.ctc) + '</p>';
      var limit = cfg.limits && cfg.limits[currentMonth()];
      var link = cfg.limitsUrl ? ' <a href="' + esc(cfg.limitsUrl) + '" rel="noopener nofollow" target="_blank">Ver la serie en el sitio del BCP</a>' : '';
      if (limit) {
        if (rateValue > limit.rate) {
          html += '<p class="cuotero__warn" role="alert"><strong>' + esc((copy.overLimit || '')
            .replace('{rate}', String(limit.rate).replace('.', ',')).replace('{month}', limit.label)) + '</strong>' + link + '</p>';
        }
      } else {
        html += '<p>' + esc(copy.noFigure || '') + link + '</p>';
      }
      discEl.innerHTML = html;
      discEl.hidden = false;
    }

    [capitalEl, monthsEl, rateEl, feesEl].forEach(function (el) {
      if (el) el.addEventListener('input', update);
    });

    // A preset that ships without a dated public rate stays empty on load.
    if (String(rateEl.value).trim() === '') {
      if (discEl) { discEl.hidden = true; discEl.innerHTML = ''; }
      resultsEl.innerHTML = '<p class="calc__out">Ingresá la tasa nominal anual para ver la cuota estimada.</p>';
    } else {
      update();
    }
  }

  var nodes = document.querySelectorAll('[data-cuotero]');
  for (var n = 0; n < nodes.length; n += 1) wire(nodes[n]);

  if (typeof window !== 'undefined') window.__cuotero = { simulate: simulate, money: money };
})();
