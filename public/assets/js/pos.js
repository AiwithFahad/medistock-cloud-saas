/**
 * MediStock Cloud — JavaScript Point of Sale (cashier/pos.php).
 *
 * Cart lives entirely in JS for speed, but NOTHING is trusted: the
 * checkout endpoint (api/pos-checkout.php) re-resolves every price from
 * the batch table, caps discounts, recomputes tax from the tenant
 * setting, validates stock + expiry server-side and only then calls
 * StockService::createSale() inside a database transaction.
 *
 * The page provides window.POS_CONFIG = {
 *   searchUrl, checkoutUrl, customersUrl, taxRate, receiptUrl
 * } and the DOM hooks used below (#posSearch, #posResults, ...).
 */
(function () {
  'use strict';
  var cfg = window.POS_CONFIG || {};
  var cart = []; // {key, medicine_id, name, strength, unit, requiresRx, batches[], batch_id, qty, discount}

  var $ = function (id) { return document.getElementById(id); };
  var branchSel = $('posBranch'), searchInput = $('posSearch'), resultsBox = $('posResults');

  function branchId() { return branchSel ? Number(branchSel.value) : 0; }

  function expiryBadge(b) {
    if (b.expired) return '<span class="badge text-bg-danger">EXPIRED ' + msEsc(b.expiry_date) + '</span>';
    if (b.days_left <= 30) return '<span class="badge text-bg-danger">' + b.days_left + 'd left</span>';
    if (b.days_left <= 90) return '<span class="badge text-bg-warning">' + b.days_left + 'd left</span>';
    return '<span class="badge text-bg-light text-dark">' + msEsc(b.expiry_date) + '</span>';
  }
  function sellableBatches(med) { return (med.batches || []).filter(function (b) { return !b.expired && b.quantity > 0; }); }
  function lineBatch(line) {
    if (line.batch_id) return line.batches.find(function (b) { return b.id === line.batch_id; }) || null;
    return sellableBatches(line)[0] || null; // FEFO = earliest expiry first
  }
  function linePrice(line) { var b = lineBatch(line); return b ? Number(b.selling_price) : 0; }
  function lineMax(line) {
    if (line.batch_id) { var b = lineBatch(line); return b ? Number(b.quantity) : 0; }
    return sellableBatches(line).reduce(function (s, b) { return s + Number(b.quantity); }, 0);
  }
  function lineTotal(line) { return Math.max(0, linePrice(line) * line.qty - Number(line.discount || 0)); }

  /* ------------------------------------------------------------ Search */
  var doSearch = window.msDebounce(function () {
    var q = searchInput.value.trim();
    if (!branchId()) { resultsBox.innerHTML = '<p class="text-muted small mb-0">Select a branch first.</p>'; return; }
    window.msFetch(cfg.searchUrl + '?branch_id=' + branchId() + '&q=' + encodeURIComponent(q))
      .then(function (data) { renderResults(data.results || [], q); })
      .catch(function (err) { window.msToast(err.message, 'error'); });
  }, 250);

  function renderResults(results, q) {
    if (!results.length) {
      resultsBox.innerHTML = '<p class="text-muted small mb-0">No medicines found' + (q ? ' for “' + msEsc(q) + '”' : '') + ' with sellable stock in this branch.</p>';
      return;
    }
    resultsBox.innerHTML = results.map(function (m, i) {
      var fefo = sellableBatches(m)[0];
      return '<div class="pos-product">'
        + '<div class="fw-semibold small">' + msEsc(m.name) + (m.requires_prescription ? ' <span class="badge text-bg-warning">Rx</span>' : '') + '</div>'
        + '<div class="text-muted" style="font-size:.75rem">' + msEsc(m.strength || '') + ' · ' + msEsc(m.unit || '') + ' · ' + msEsc(m.barcode || m.sku || '') + '</div>'
        + '<div class="d-flex justify-content-between align-items-center mt-1">'
        + '<span class="small"><strong>' + msMoney(fefo ? fefo.selling_price : 0) + '</strong> · ' + m.available + ' in stock</span>'
        + (fefo ? expiryBadge(fefo) : '')
        + '</div>'
        + '<button type="button" class="btn btn-emerald btn-sm w-100 mt-2" data-add="' + i + '"><i class="bi bi-plus-lg"></i> Add</button>'
        + '</div>';
    }).join('');
    resultsBox.querySelectorAll('[data-add]').forEach(function (btn) {
      btn.addEventListener('click', function () { addToCart(results[Number(btn.getAttribute('data-add'))]); });
    });
    // Barcode scanners type the code + Enter: an exact barcode match adds instantly.
    var exact = results.find(function (m) { return m.barcode && m.barcode === q; });
    if (exact && searchInput.dataset.autoAdd === '1') addToCart(exact);
  }

  /* -------------------------------------------------------------- Cart */
  function addToCart(med) {
    var key = 'm' + med.id;
    var existing = cart.find(function (l) { return l.key === key; });
    if (existing) {
      if (existing.qty < lineMax(existing)) existing.qty++;
      renderCart();
      return;
    }
    cart.push({
      key: key, medicine_id: med.id, name: med.name, strength: med.strength || '', unit: med.unit || '',
      requiresRx: !!med.requires_prescription, batches: med.batches || [], batch_id: null, qty: 1, discount: 0
    });
    renderCart();
  }

  function renderCart() {
    var body = $('posCartBody');
    if (!cart.length) {
      body.innerHTML = '<p class="text-muted small mb-0">Cart is empty — search and add medicines.</p>';
    } else {
      body.innerHTML = cart.map(function (line, idx) {
        var batch = lineBatch(line);
        var options = '<option value="">FEFO (earliest expiry)</option>' + line.batches.map(function (b) {
          var dis = b.expired ? ' disabled' : '';
          var sel = line.batch_id === b.id ? ' selected' : '';
          return '<option value="' + b.id + '"' + dis + sel + '>' + msEsc(b.batch_number) + ' — exp ' + msEsc(b.expiry_date)
            + (b.expired ? ' (EXPIRED — cannot sell)' : ' (' + b.quantity + ' avail, ' + b.days_left + 'd)') + '</option>';
        }).join('');
        var warn = '';
        if (batch && batch.days_left <= 30 && !batch.expired) warn = '<div class="text-danger small"><i class="bi bi-exclamation-triangle"></i> Expires in ' + batch.days_left + ' days — dispense first.</div>';
        if (line.requiresRx) warn += '<div class="text-warning-emphasis small"><i class="bi bi-file-earmark-medical"></i> Prescription required — select a prescription below.</div>';
        return '<div class="border rounded p-2 mb-2">'
          + '<div class="d-flex justify-content-between gap-2"><strong class="small">' + msEsc(line.name) + ' ' + msEsc(line.strength) + '</strong>'
          + '<button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" data-remove="' + idx + '"><i class="bi bi-x"></i></button></div>'
          + '<select class="form-select form-select-sm mt-1" data-batch="' + idx + '">' + options + '</select>' + warn
          + '<div class="row g-1 mt-1 align-items-center">'
          + '<div class="col-4"><label class="form-label small mb-0">Qty (max ' + lineMax(line) + ')</label><input type="number" min="1" max="' + lineMax(line) + '" class="form-control form-control-sm" data-qty="' + idx + '" value="' + line.qty + '"></div>'
          + '<div class="col-4"><label class="form-label small mb-0">Discount</label><input type="number" min="0" step="0.01" class="form-control form-control-sm" data-discount="' + idx + '" value="' + line.discount + '"></div>'
          + '<div class="col-4 text-end"><label class="form-label small mb-0 d-block">Line total</label><strong>' + msMoney(lineTotal(line)) + '</strong><div class="text-muted" style="font-size:.72rem">@ ' + msMoney(linePrice(line)) + '</div></div>'
          + '</div></div>';
      }).join('');
      body.querySelectorAll('[data-remove]').forEach(function (b) { b.addEventListener('click', function () { cart.splice(Number(b.getAttribute('data-remove')), 1); renderCart(); }); });
      body.querySelectorAll('[data-batch]').forEach(function (s) { s.addEventListener('change', function () { var l = cart[Number(s.getAttribute('data-batch'))]; l.batch_id = s.value ? Number(s.value) : null; if (l.qty > lineMax(l)) l.qty = Math.max(1, lineMax(l)); renderCart(); }); });
      body.querySelectorAll('[data-qty]').forEach(function (inp) { inp.addEventListener('change', function () { var l = cart[Number(inp.getAttribute('data-qty'))]; l.qty = Math.max(1, Math.min(lineMax(l) || 1, Number(inp.value) || 1)); renderCart(); }); });
      body.querySelectorAll('[data-discount]').forEach(function (inp) { inp.addEventListener('change', function () { var l = cart[Number(inp.getAttribute('data-discount'))]; l.discount = Math.max(0, Number(inp.value) || 0); renderCart(); }); });
    }
    renderTotals();
  }

  function totals() {
    var subtotal = cart.reduce(function (s, l) { return s + linePrice(l) * l.qty; }, 0);
    var discount = cart.reduce(function (s, l) { return s + Math.min(Number(l.discount || 0), linePrice(l) * l.qty); }, 0);
    var tax = Math.round(Math.max(0, subtotal - discount) * Number(cfg.taxRate || 0)) / 100;
    return { subtotal: subtotal, discount: discount, tax: tax, grand: Math.round((subtotal - discount + tax) * 100) / 100 };
  }
  function renderTotals() {
    var t = totals();
    $('posSubtotal').textContent = msMoney(t.subtotal);
    $('posDiscountTotal').textContent = msMoney(t.discount);
    $('posTax').textContent = msMoney(t.tax);
    $('posGrand').textContent = msMoney(t.grand);
    renderChange();
  }
  function paidTotal() {
    var sum = 0;
    document.querySelectorAll('[data-pay-amount]').forEach(function (i) { sum += Number(i.value) || 0; });
    return Math.round(sum * 100) / 100;
  }
  function renderChange() {
    var paid = paidTotal(), grand = totals().grand;
    var el = $('posChange');
    if (!el) return;
    if (paid >= grand) { el.innerHTML = 'Change due: <strong>' + msMoney(paid - grand) + '</strong>'; el.className = 'small text-success'; }
    else { el.innerHTML = 'Still to pay: <strong>' + msMoney(grand - paid) + '</strong>'; el.className = 'small text-danger'; }
  }

  /* ---------------------------------------------------------- Payments */
  function addPaymentRow(method, amount) {
    var wrap = $('posPayments');
    var row = document.createElement('div');
    row.className = 'row g-1 mb-1 align-items-center';
    row.innerHTML = '<div class="col-4"><select class="form-select form-select-sm" data-pay-method>'
      + ['cash', 'card', 'mobile', 'bank'].map(function (m) { return '<option value="' + m + '"' + (m === method ? ' selected' : '') + '>' + m[0].toUpperCase() + m.slice(1) + '</option>'; }).join('')
      + '</select></div>'
      + '<div class="col-4"><input type="number" min="0" step="0.01" class="form-control form-control-sm" data-pay-amount placeholder="Amount" value="' + (amount || '') + '"></div>'
      + '<div class="col-3"><input type="text" class="form-control form-control-sm" data-pay-ref placeholder="Reference"></div>'
      + '<div class="col-1"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" data-pay-remove><i class="bi bi-x"></i></button></div>';
    wrap.appendChild(row);
    row.querySelector('[data-pay-remove]').addEventListener('click', function () { row.remove(); renderChange(); });
    row.querySelector('[data-pay-amount]').addEventListener('input', renderChange);
    renderChange();
  }

  /* ---------------------------------------------------------- Customer */
  var customerInput = $('posCustomerSearch'), customerBox = $('posCustomerResults');
  if (customerInput) {
    customerInput.addEventListener('input', window.msDebounce(function () {
      var q = customerInput.value.trim();
      if (q.length < 2) { customerBox.innerHTML = ''; return; }
      window.msFetch(cfg.customersUrl + '?q=' + encodeURIComponent(q)).then(function (data) {
        customerBox.innerHTML = (data.customers || []).map(function (c) {
          return '<button type="button" class="list-group-item list-group-item-action py-1" data-customer="' + c.id + '">' + msEsc(c.name) + ' <span class="text-muted small">' + msEsc(c.phone || '') + '</span></button>';
        }).join('') || '<div class="list-group-item py-1 text-muted small">No match — leave blank for walk-in.</div>';
        customerBox.querySelectorAll('[data-customer]').forEach(function (b) {
          b.addEventListener('click', function () {
            $('posCustomerId').value = b.getAttribute('data-customer');
            customerInput.value = b.textContent.trim();
            customerBox.innerHTML = '';
          });
        });
      }).catch(function () { /* typeahead is best-effort */ });
    }, 300));
    customerInput.addEventListener('change', function () { if (!customerInput.value.trim()) $('posCustomerId').value = ''; });
  }

  /* ---------------------------------------------------------- Checkout */
  function checkout() {
    if (!cart.length) { window.msToast('The cart is empty.', 'warning'); return; }
    if (!branchId()) { window.msToast('Select a branch first.', 'warning'); return; }
    var rxId = $('posRx') && $('posRx').value ? Number($('posRx').value) : null;
    if (cart.some(function (l) { return l.requiresRx; }) && !rxId) {
      window.msToast('This cart contains prescription medicines — select the prescription before checkout.', 'error');
      return;
    }
    var payments = [];
    document.querySelectorAll('#posPayments > div').forEach(function (row) {
      var amount = Number(row.querySelector('[data-pay-amount]').value) || 0;
      if (amount > 0) payments.push({ method: row.querySelector('[data-pay-method]').value, amount: amount, reference: row.querySelector('[data-pay-ref]').value || null });
    });
    if (!payments.length) { window.msToast('Add at least one payment.', 'warning'); return; }
    var btn = $('posCheckout');
    btn.disabled = true;
    window.msFetch(cfg.checkoutUrl, {
      method: 'POST',
      json: {
        branch_id: branchId(),
        customer_id: $('posCustomerId').value ? Number($('posCustomerId').value) : null,
        prescription_id: rxId,
        items: cart.map(function (l) { return { medicine_id: l.medicine_id, batch_id: l.batch_id, quantity: l.qty, discount: Number(l.discount || 0) }; }),
        payments: payments
      }
    }).then(function (res) {
      window.msToast('Sale ' + res.invoice_number + ' completed — ' + msMoney(res.grand_total), 'success');
      showReceipt(res.receipt);
      cart = [];
      $('posPayments').innerHTML = '';
      addPaymentRow('cash', '');
      if ($('posCustomerId')) $('posCustomerId').value = '';
      if (customerInput) customerInput.value = '';
      renderCart();
      doSearch();
    }).catch(function (err) {
      window.msToast(err.message, 'error');
    }).finally(function () { btn.disabled = false; });
  }

  function receiptHtml(r) {
    var rows = (r.items || []).map(function (i) {
      return '<tr><td>' + msEsc(i.name) + '<br><small>' + msEsc(i.batch_number || '') + '</small></td><td class="text-end">' + i.quantity + ' × ' + msMoney(i.unit_price) + '</td><td class="text-end">' + msMoney(i.total) + '</td></tr>';
    }).join('');
    var pays = (r.payments || []).map(function (p) { return '<div class="d-flex justify-content-between"><span>' + msEsc(p.method) + '</span><span>' + msMoney(p.amount) + '</span></div>'; }).join('');
    return '<div class="receipt"><h5 class="text-center mb-0">' + msEsc(r.pharmacy) + '</h5>'
      + '<p class="text-center small text-muted mb-1">' + msEsc(r.branch || '') + '</p><hr>'
      + '<div class="d-flex justify-content-between small"><span>' + msEsc(r.invoice_number) + '</span><span>' + msEsc(r.created_at) + '</span></div>'
      + (r.customer ? '<div class="small">Customer: ' + msEsc(r.customer) + '</div>' : '')
      + '<table class="table table-sm mt-2 mb-2"><tbody>' + rows + '</tbody></table><hr>'
      + '<div class="d-flex justify-content-between"><span>Subtotal</span><span>' + msMoney(r.subtotal) + '</span></div>'
      + '<div class="d-flex justify-content-between"><span>Discount</span><span>−' + msMoney(r.discount) + '</span></div>'
      + '<div class="d-flex justify-content-between"><span>Tax</span><span>' + msMoney(r.tax) + '</span></div>'
      + '<div class="d-flex justify-content-between fw-bold"><span>Total</span><span>' + msMoney(r.grand_total) + '</span></div>'
      + pays
      + (Number(r.change) > 0 ? '<div class="d-flex justify-content-between"><span>Change</span><span>' + msMoney(r.change) + '</span></div>' : '')
      + '<hr><p class="text-center small mb-0">' + msEsc(r.footer || 'Thank you!') + '</p></div>';
  }
  function showReceipt(r) {
    var html = receiptHtml(r);
    var card = $('posReceiptCard');
    if (card) { $('posReceiptHtml').innerHTML = html; card.classList.remove('d-none'); card.scrollIntoView({ behavior: 'smooth' }); }
    var printArea = document.getElementById('posPrintArea');
    if (!printArea) { printArea = document.createElement('div'); printArea.id = 'posPrintArea'; document.body.appendChild(printArea); }
    printArea.innerHTML = html;
  }

  /* -------------------------------------------------------------- Init */
  if (searchInput) {
    searchInput.addEventListener('input', function () { searchInput.dataset.autoAdd = '0'; doSearch(); });
    searchInput.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); searchInput.dataset.autoAdd = '1'; doSearch(); } });
  }
  if (branchSel) branchSel.addEventListener('change', function () { cart = []; renderCart(); doSearch(); });
  var addPayBtn = $('posAddPayment');
  if (addPayBtn) addPayBtn.addEventListener('click', function () { addPaymentRow('card', ''); });
  var clearBtn = $('posClear');
  if (clearBtn) clearBtn.addEventListener('click', function () { cart = []; renderCart(); window.msToast('Cart cleared.', 'info'); });
  var checkoutBtn = $('posCheckout');
  if (checkoutBtn) checkoutBtn.addEventListener('click', checkout);
  var printBtn = $('posPrintBtn');
  if (printBtn) printBtn.addEventListener('click', function () { document.body.classList.add('receipt-printing'); window.print(); setTimeout(function () { document.body.classList.remove('receipt-printing'); }, 500); });
  addPaymentRow('cash', '');
  renderCart();
  doSearch();
})();
