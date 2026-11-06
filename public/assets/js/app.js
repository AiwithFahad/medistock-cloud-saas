/**
 * MediStock Cloud — shared admin JavaScript layer.
 *
 * Loaded on every dashboard page (see View::admin_footer). Provides:
 *  - msToast(message, type)          toast notifications
 *  - msConfirm(message) -> Promise   Bootstrap confirm dialog
 *  - msFetch(url, options)           JSON fetch with CSRF header + errors
 *  - [data-confirm] on forms/buttons intercepted with the confirm dialog
 *  - canvas[data-chart-url]          AJAX Chart.js loader (real API data)
 *  - [data-live-search] inputs       debounced live search -> renderer fn
 *
 * Display-only helpers: every total that matters is recomputed and
 * validated server-side (see api/pos-checkout.php + StockService).
 */
(function () {
  'use strict';

  /* ---------------------------------------------------------- Toasts */
  window.msToast = function (message, type) {
    var container = document.getElementById('msToasts');
    if (!container || typeof bootstrap === 'undefined') {
      window.alert(message);
      return;
    }
    type = type || 'success';
    var icons = { success: 'bi-check-circle', error: 'bi-exclamation-octagon', danger: 'bi-exclamation-octagon', warning: 'bi-exclamation-triangle', info: 'bi-info-circle' };
    var colors = { success: 'text-bg-success', error: 'text-bg-danger', danger: 'text-bg-danger', warning: 'text-bg-warning', info: 'text-bg-info' };
    var el = document.createElement('div');
    el.className = 'toast align-items-center border-0 ' + (colors[type] || colors.info);
    el.setAttribute('role', 'alert');
    el.innerHTML = '<div class="d-flex"><div class="toast-body"><i class="bi ' + (icons[type] || icons.info) + ' me-2"></i></div></div>';
    el.querySelector('.toast-body').appendChild(document.createTextNode(message));
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close btn-close-white me-2 m-auto';
    close.setAttribute('data-bs-dismiss', 'toast');
    el.querySelector('.d-flex').appendChild(close);
    container.appendChild(el);
    var toast = new bootstrap.Toast(el, { delay: 4500 });
    toast.show();
    el.addEventListener('hidden.bs.toast', function () { el.remove(); });
  };

  /* --------------------------------------------------------- Confirm */
  var confirmResolve = null;
  var confirmModal = null;
  function ensureConfirmModal() {
    var modalEl = document.getElementById('msConfirmModal');
    if (!modalEl || typeof bootstrap === 'undefined') return null;
    if (!confirmModal) {
      confirmModal = new bootstrap.Modal(modalEl);
      modalEl.querySelector('[data-ms-confirm="yes"]').addEventListener('click', function () {
        confirmModal.hide();
        if (confirmResolve) confirmResolve(true);
        confirmResolve = null;
      });
      modalEl.querySelector('[data-ms-confirm="no"]').addEventListener('click', function () {
        confirmModal.hide();
        if (confirmResolve) confirmResolve(false);
        confirmResolve = null;
      });
      modalEl.addEventListener('hidden.bs.modal', function () {
        if (confirmResolve) confirmResolve(false);
        confirmResolve = null;
      });
    }
    return modalEl;
  }
  window.msConfirm = function (message) {
    return new Promise(function (resolve) {
      var modalEl = ensureConfirmModal();
      if (!modalEl) { resolve(window.confirm(message)); return; }
      document.getElementById('msConfirmText').textContent = message;
      confirmResolve = resolve;
      confirmModal.show();
    });
  };
  // Forms/buttons marked data-confirm="..." ask first, then proceed.
  document.addEventListener('submit', function (ev) {
    var form = ev.target.closest('form[data-confirm]');
    if (!form || form.dataset.confirmed === '1') return;
    ev.preventDefault();
    window.msConfirm(form.getAttribute('data-confirm')).then(function (ok) {
      if (ok) { form.dataset.confirmed = '1'; form.submit(); }
    });
  });
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-confirm-click]');
    if (!btn) return;
    ev.preventDefault();
    window.msConfirm(btn.getAttribute('data-confirm-click')).then(function (ok) {
      if (ok) window.location.href = btn.getAttribute('href');
    });
  });

  /* ----------------------------------------------------------- Fetch */
  window.msFetch = function (url, options) {
    options = options || {};
    var headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
    if ((options.method || 'GET').toUpperCase() !== 'GET') {
      headers['X-CSRF-Token'] = window.MS_CSRF || '';
      if (options.json) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(options.json);
        delete options.json;
      }
    }
    options.headers = headers;
    return fetch(url, options).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) {
          throw new Error(data.error || ('Request failed (' + res.status + ')'));
        }
        return data;
      });
    });
  };

  /* --------------------------------------------------------- Helpers */
  window.msDebounce = function (fn, wait) {
    var t;
    return function () {
      var args = arguments, self = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, args); }, wait || 300);
    };
  };
  window.msMoney = function (n) {
    return (Math.round((Number(n) || 0) * 100) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };
  window.msEsc = function (s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  };

  /* --------------------------------------- AJAX charts (Chart.js) */
  var PALETTE = ['#059669', '#0ea5e9', '#f59e0b', '#8b5cf6', '#f43f5e', '#065f46', '#0f2a43', '#14b8a6'];
  function loadChart(canvas) {
    if (typeof Chart === 'undefined') return;
    var url = canvas.getAttribute('data-chart-url');
    var type = canvas.getAttribute('data-chart-type') || 'bar';
    window.msFetch(url).then(function (data) {
      var datasets = (data.datasets || []).map(function (ds, i) {
        var color = ds.color || PALETTE[i % PALETTE.length];
        return {
          label: ds.label || '',
          data: ds.data || [],
          backgroundColor: type === 'line' ? color + '33' : (ds.backgroundColor || color + 'cc'),
          borderColor: color,
          borderWidth: 2,
          fill: type === 'line',
          tension: 0.3
        };
      });
      new Chart(canvas, {
        type: type,
        data: { labels: data.labels || [], datasets: datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: datasets.length > 1 } },
          scales: type === 'doughnut' || type === 'pie' ? {} : { y: { beginAtZero: true } }
        }
      });
    }).catch(function (err) {
      var wrap = canvas.parentElement;
      if (wrap) {
        var p = document.createElement('p');
        p.className = 'text-muted small mb-0';
        p.textContent = 'Chart unavailable: ' + err.message;
        wrap.appendChild(p);
      }
    });
  }
  document.querySelectorAll('canvas[data-chart-url]').forEach(loadChart);
  // Date-range filter forms for charts: [data-chart-filter] selects a
  // canvas and reloads it with ?from=&to= appended.
  document.querySelectorAll('[data-chart-filter-for]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var canvas = document.getElementById(form.getAttribute('data-chart-filter-for'));
      if (!canvas) return;
      var base = canvas.getAttribute('data-chart-url-base') || canvas.getAttribute('data-chart-url');
      canvas.setAttribute('data-chart-url-base', base.split('?')[0]);
      var params = new URLSearchParams(new FormData(form));
      canvas.setAttribute('data-chart-url', base.split('?')[0] + '?' + params.toString());
      if (canvas._chart) canvas._chart.destroy();
      loadChart(canvas);
    });
  });

  /* ---------------------------------------------------- Live search */
  // input[data-live-search="#tbodyId"][data-live-url="..."][data-live-render="fnName"]
  // The page provides window[fnName](rows, tbody) to render results.
  document.querySelectorAll('[data-live-search]').forEach(function (input) {
    var target = document.querySelector(input.getAttribute('data-live-search'));
    var renderName = input.getAttribute('data-live-render');
    var url = input.getAttribute('data-live-url');
    if (!target || !url) return;
    var run = window.msDebounce(function () {
      var sep = url.indexOf('?') === -1 ? '?' : '&';
      window.msFetch(url + sep + 'q=' + encodeURIComponent(input.value)).then(function (data) {
        var rows = data.rows || data.results || data.customers || data.medicines || [];
        if (renderName && typeof window[renderName] === 'function') {
          window[renderName](rows, target);
        }
      }).catch(function (err) { window.msToast(err.message, 'error'); });
    }, 300);
    input.addEventListener('input', run);
  });
})();
