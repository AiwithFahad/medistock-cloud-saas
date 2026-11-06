// MediStock Cloud — static demo behaviours (sidebar, pricing toggle, POS mock).
document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.getElementById('sidebarToggle');
  var sidebar = document.getElementById('adminSidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () { sidebar.classList.toggle('open'); });
    document.addEventListener('click', function (ev) {
      if (window.innerWidth < 992 && sidebar.classList.contains('open') && !sidebar.contains(ev.target) && !toggle.contains(ev.target)) {
        sidebar.classList.remove('open');
      }
    });
  }

  var billingToggle = document.getElementById('billingToggle');
  if (billingToggle) {
    var apply = function () {
      var yearly = billingToggle.checked;
      document.querySelectorAll('[data-monthly]').forEach(function (el) {
        el.textContent = yearly ? el.getAttribute('data-yearly') : el.getAttribute('data-monthly');
      });
      document.querySelectorAll('.billing-period').forEach(function (el) {
        el.textContent = yearly ? '/ year' : '/ month';
      });
    };
    billingToggle.addEventListener('change', apply); apply();
  }

  /* --------------------------------- POS mock (pos.html) — upgraded:
     batch selection with expiry warnings, per-line discount, split
     payments and full cart math, mirroring the production JS POS.
     In production every total is ALSO recomputed server-side.        */
  var grid = document.getElementById('posGrid');
  if (grid) {
    var products = [
      { id: 1, name: 'Paracetamol 500mg', meta: '500mg · Tablet', barcode: '8901001', batches: [
        { no: 'PCM-2409', days: 55, stock: 120, price: 1.50 },
        { no: 'PCM-2501', days: 480, stock: 340, price: 1.50 },
        { no: 'PCM-OLD', days: -45, stock: 40, price: 1.50 } ] },
      { id: 2, name: 'Ibuprofen 400mg', meta: '400mg · Tablet', barcode: '8901002', batches: [ { no: 'IBU-118', days: 420, stock: 210, price: 4.20 } ] },
      { id: 3, name: 'Amoxicillin 250mg', meta: '250mg · Capsule', rx: true, barcode: '8901003', batches: [ { no: 'AMX-118', days: 240, stock: 150, price: 5.00 } ] },
      { id: 4, name: 'Cetirizine 10mg', meta: '10mg · Tablet', barcode: '8901004', low: true, batches: [ { no: 'CET-077', days: 300, stock: 5, price: 2.25 } ] },
      { id: 5, name: 'Vitamin D3 1000 IU', meta: '1000 IU · Capsule', barcode: '8901005', batches: [ { no: 'VIT-310', days: 600, stock: 96, price: 8.50 } ] },
      { id: 6, name: 'Omeprazole 20mg', meta: '20mg · Capsule', barcode: '8901006', batches: [ { no: 'OMP-204', days: 360, stock: 180, price: 3.75 } ] },
      { id: 7, name: 'Metformin 500mg', meta: '500mg · Tablet', rx: true, barcode: '8901007', batches: [ { no: 'MET-512', days: 450, stock: 260, price: 2.80 } ] },
      { id: 8, name: 'Ambroxol Cough Syrup', meta: '30mg/5ml · Syrup', barcode: '8901008', batches: [ { no: 'AMB-033', days: 80, stock: 48, price: 4.95 } ] },
      { id: 9, name: 'ORS Sachets', meta: 'Sachet', barcode: '8901009', batches: [ { no: 'ORS-611', days: 720, stock: 500, price: 0.90 } ] },
      { id: 10, name: 'Aspirin 75mg', meta: '75mg · Tablet', barcode: '8901010', batches: [ { no: 'ASP-208', days: 21, stock: 320, price: 1.20 } ] }
    ];
    var cart = []; // {pid, batch, qty, discount}
    var money = function (n) { return '$' + (Math.round(n * 100) / 100).toFixed(2); };
    var sellable = function (p) { return p.batches.filter(function (b) { return b.days >= 0 && b.stock > 0; }); };
    var fefo = function (p) { return sellable(p)[0] || null; };
    var stockOf = function (p) { return sellable(p).reduce(function (s, b) { return s + b.stock; }, 0); };
    var prod = function (id) { return products.find(function (x) { return x.id === id; }); };
    var batchOf = function (line) { return prod(line.pid).batches.find(function (b) { return b.no === line.batch; }); };
    function expiryBadge(b) {
      if (b.days < 0) return '<span class="badge bg-danger">EXPIRED</span>';
      if (b.days <= 30) return '<span class="badge bg-danger">' + b.days + 'd left</span>';
      if (b.days <= 90) return '<span class="badge bg-warning text-dark">' + b.days + 'd left</span>';
      return '<span class="badge bg-success-subtle text-success">in date</span>';
    }

    function renderGrid(filter) {
      grid.innerHTML = '';
      products.filter(function (p) {
        return !filter || p.name.toLowerCase().indexOf(filter.toLowerCase()) !== -1 || p.barcode === filter;
      }).forEach(function (p) {
        var f = fefo(p);
        var col = document.createElement('div');
        col.className = 'col-md-6 col-xl-4';
        col.innerHTML = '<button type="button" class="pos-product">' +
          '<strong class="text-navy d-block">' + p.name + (p.rx ? ' <span class="badge bg-warning text-dark">Rx</span>' : '') + '</strong>' +
          '<small class="text-muted d-block">' + p.meta + ' · ' + p.barcode + '</small>' +
          (f ? '<small class="d-block mt-1">FEFO batch ' + f.no + ' · ' + expiryBadge(f) + '</small>' : '<small class="d-block mt-1 text-danger">No sellable stock</small>') +
          '<span class="d-flex justify-content-between mt-1"><span class="badge ' + (p.low ? 'bg-warning text-dark' : 'bg-success-subtle text-success') + '">' + stockOf(p) + ' available' + (p.low ? ' · LOW' : '') + '</span>' +
          '<strong class="text-emerald">' + (f ? money(f.price) : '—') + '</strong></span></button>';
        col.querySelector('button').addEventListener('click', function () { addLine(p); });
        grid.appendChild(col);
      });
      if (!grid.children.length) grid.innerHTML = '<p class="text-muted small">No medicines match — try a name or barcode.</p>';
    }

    function addLine(p) {
      var f = fefo(p);
      if (!f) { alert('No sellable (in-date) stock for ' + p.name + '.'); return; }
      var existing = cart.find(function (l) { return l.pid === p.id && l.batch === f.no; });
      if (existing) { if (existing.qty < f.stock) existing.qty++; }
      else cart.push({ pid: p.id, batch: f.no, qty: 1, discount: 0 });
      renderCart();
    }

    function totals() {
      var sub = 0, disc = 0;
      cart.forEach(function (l) {
        var b = batchOf(l);
        var lineVal = b.price * l.qty;
        sub += lineVal; disc += Math.min(l.discount || 0, lineVal);
      });
      return { sub: sub, disc: disc, tax: 0, grand: Math.round((sub - disc) * 100) / 100 };
    }
    function paidTotal() {
      var sum = 0;
      document.querySelectorAll('[data-pay-amount]').forEach(function (i) { sum += parseFloat(i.value) || 0; });
      return Math.round(sum * 100) / 100;
    }

    function renderCart() {
      var body = document.getElementById('cartBody');
      if (!cart.length) {
        body.innerHTML = '<tr><td colspan="3" class="text-muted small">Cart is empty — click a medicine to add it. Expired batches (e.g. PCM-OLD) can be seen in the batch picker but are disabled, exactly like production.</td></tr>';
      } else {
        body.innerHTML = cart.map(function (l, i) {
          var p = prod(l.pid), b = batchOf(l);
          var opts = p.batches.map(function (x) {
            return '<option value="' + x.no + '"' + (x.no === l.batch ? ' selected' : '') + (x.days < 0 ? ' disabled' : '') + '>' + x.no + ' · ' + (x.days < 0 ? 'EXPIRED — cannot sell' : x.stock + ' avail · ' + x.days + 'd left') + ' · ' + money(x.price) + '</option>';
          }).join('');
          var warn = b.days >= 0 && b.days <= 30 ? '<div class="text-danger small"><i class="bi bi-exclamation-triangle"></i> Expires in ' + b.days + ' days — dispense first.</div>' : '';
          var rxWarn = p.rx ? '<div class="text-warning-emphasis small"><i class="bi bi-file-earmark-medical"></i> Prescription required.</div>' : '';
          return '<tr><td colspan="3"><div class="border rounded p-2">'
            + '<div class="d-flex justify-content-between"><strong class="small">' + p.name + '</strong>'
            + '<button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" data-remove="' + i + '"><i class="bi bi-x"></i></button></div>'
            + '<select class="form-select form-select-sm mt-1" data-batch="' + i + '">' + opts + '</select>' + warn + rxWarn
            + '<div class="row g-1 mt-1 align-items-center">'
            + '<div class="col-4"><label class="form-label small mb-0">Qty</label><input type="number" min="1" max="' + b.stock + '" class="form-control form-control-sm" data-qty="' + i + '" value="' + l.qty + '"></div>'
            + '<div class="col-4"><label class="form-label small mb-0">Discount $</label><input type="number" min="0" step="0.01" class="form-control form-control-sm" data-discount="' + i + '" value="' + (l.discount || 0) + '"></div>'
            + '<div class="col-4 text-end"><label class="form-label small mb-0 d-block">Line total</label><strong>' + money(b.price * l.qty - Math.min(l.discount || 0, b.price * l.qty)) + '</strong></div>'
            + '</div></div></td></tr>';
        }).join('');
        body.querySelectorAll('[data-remove]').forEach(function (btn) { btn.addEventListener('click', function () { cart.splice(Number(btn.dataset.remove), 1); renderCart(); }); });
        body.querySelectorAll('[data-batch]').forEach(function (s) { s.addEventListener('change', function () { cart[Number(s.dataset.batch)].batch = s.value; renderCart(); }); });
        body.querySelectorAll('[data-qty]').forEach(function (inp) { inp.addEventListener('change', function () { var l = cart[Number(inp.dataset.qty)]; l.qty = Math.max(1, Math.min(batchOf(l).stock, parseInt(inp.value, 10) || 1)); renderCart(); }); });
        body.querySelectorAll('[data-discount]').forEach(function (inp) { inp.addEventListener('change', function () { cart[Number(inp.dataset.discount)].discount = Math.max(0, parseFloat(inp.value) || 0); renderCart(); }); });
      }
      var t = totals();
      document.getElementById('cartSubtotal').textContent = money(t.sub);
      document.getElementById('cartDiscount').textContent = money(t.disc);
      document.getElementById('cartTotal').textContent = money(t.grand);
      renderChange();
    }
    function renderChange() {
      var el = document.getElementById('paySummary');
      if (!el) return;
      var paid = paidTotal(), grand = totals().grand;
      el.innerHTML = paid >= grand
        ? 'Change due: <strong>' + money(paid - grand) + '</strong>'
        : 'Still to pay: <strong>' + money(grand - paid) + '</strong>';
      el.className = 'small ' + (paid >= grand ? 'text-success' : 'text-danger');
    }
    function addPayRow(method) {
      var wrap = document.getElementById('payRows');
      if (!wrap) return;
      var row = document.createElement('div');
      row.className = 'row g-1 mb-1 align-items-center';
      row.innerHTML = '<div class="col-5"><select class="form-select form-select-sm" data-pay-method>'
        + ['Cash', 'Card', 'Mobile money', 'Bank transfer'].map(function (m) { return '<option' + (m === method ? ' selected' : '') + '>' + m + '</option>'; }).join('') + '</select></div>'
        + '<div class="col-5"><input type="number" min="0" step="0.01" class="form-control form-control-sm" data-pay-amount placeholder="Amount"></div>'
        + '<div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-pay-remove><i class="bi bi-x"></i></button></div>';
      wrap.appendChild(row);
      row.querySelector('[data-pay-remove]').addEventListener('click', function () { row.remove(); renderChange(); });
      row.querySelector('[data-pay-amount]').addEventListener('input', renderChange);
    }

    var search = document.getElementById('posSearch');
    if (search) { search.addEventListener('input', function () { renderGrid(search.value.trim()); }); }
    var addPay = document.getElementById('addPayBtn');
    if (addPay) addPay.addEventListener('click', function () { addPayRow('Card'); });
    var checkout = document.getElementById('checkoutBtn');
    if (checkout) {
      checkout.addEventListener('click', function () {
        if (!cart.length) { alert('Cart is empty.'); return; }
        var rxSel = document.getElementById('posRx');
        if (cart.some(function (l) { return prod(l.pid).rx; }) && rxSel && !rxSel.value) {
          alert('This cart contains prescription medicines — select a prescription first (production enforces this on the server too).');
          return;
        }
        var t = totals(), paid = paidTotal();
        if (paid + 0.001 < t.grand) { alert('Payments cover ' + money(paid) + ' of ' + money(t.grand) + ' — add the remaining amount.'); return; }
        var pays = [];
        document.querySelectorAll('#payRows > div').forEach(function (row) {
          var a = parseFloat(row.querySelector('[data-pay-amount]').value) || 0;
          if (a > 0) pays.push({ method: row.querySelector('[data-pay-method]').value, amount: a });
        });
        var customer = document.getElementById('posCustomer');
        document.getElementById('receiptCustomer').textContent = 'Customer: ' + (customer ? customer.value : 'Walk-in customer');
        document.getElementById('receiptItems').innerHTML = cart.map(function (l) {
          var p = prod(l.pid), b = batchOf(l);
          return '<div class="d-flex justify-content-between"><span>' + p.name + ' × ' + l.qty + '<br><small>Batch ' + l.batch + '</small></span><span>' + money(b.price * l.qty - Math.min(l.discount || 0, b.price * l.qty)) + '</span></div>';
        }).join('');
        document.getElementById('receiptPayments').innerHTML = pays.map(function (p) {
          return '<div class="d-flex justify-content-between"><span>' + p.method + '</span><span>' + money(p.amount) + '</span></div>';
        }).join('') + (paid > t.grand ? '<div class="d-flex justify-content-between"><span>Change</span><span>' + money(paid - t.grand) + '</span></div>' : '');
        document.getElementById('receiptTotal').textContent = money(t.grand);
        document.getElementById('receiptBox').style.display = 'block';
        cart = []; renderCart();
        document.getElementById('payRows').innerHTML = ''; addPayRow('Cash');
        document.getElementById('receiptBox').scrollIntoView({ behavior: 'smooth' });
      });
    }
    addPayRow('Cash');
    renderGrid(''); renderCart();
  }

  /* ------------------------- Dashboard mock: live stock-table search */
  var stockSearch = document.getElementById('stockSearch');
  if (stockSearch) {
    stockSearch.addEventListener('input', function () {
      var q = stockSearch.value.toLowerCase();
      document.querySelectorAll('#stockTable tbody tr').forEach(function (tr) {
        tr.style.display = tr.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
      });
    });
  }
});
