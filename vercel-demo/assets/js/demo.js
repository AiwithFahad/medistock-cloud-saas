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

  /* ------------------------------------------------ POS mock (pos.html) */
  var grid = document.getElementById('posGrid');
  if (grid) {
    var products = [
      { id: 1, name: 'Paracetamol 500mg', meta: '500mg · Tablet', batch: 'PCM-2409', expiry: 'in 55 days', stock: 460, price: 1.50 },
      { id: 2, name: 'Ibuprofen 400mg', meta: '400mg · Tablet', batch: 'IBU-118', expiry: 'Aug 2027', stock: 210, price: 4.20 },
      { id: 3, name: 'Amoxicillin 250mg', meta: '250mg · Capsule · Rx', batch: 'AMX-118', expiry: 'May 2027', stock: 150, price: 5.00 },
      { id: 4, name: 'Cetirizine 10mg', meta: '10mg · Tablet', batch: 'CET-077', expiry: 'Jul 2027', stock: 5, price: 2.25, low: true },
      { id: 5, name: 'Vitamin D3 1000 IU', meta: '1000 IU · Capsule', batch: 'VIT-310', expiry: 'Feb 2028', stock: 96, price: 8.50 },
      { id: 6, name: 'Omeprazole 20mg', meta: '20mg · Capsule', batch: 'OMP-204', expiry: 'Sep 2027', stock: 180, price: 3.75 },
      { id: 7, name: 'Metformin 500mg', meta: '500mg · Tablet · Rx', batch: 'MET-512', expiry: 'Dec 2027', stock: 260, price: 2.80 },
      { id: 8, name: 'Ambroxol Cough Syrup', meta: '30mg/5ml · Syrup', batch: 'AMB-033', expiry: 'in 80 days', stock: 48, price: 4.95 },
      { id: 9, name: 'ORS Sachets', meta: 'Sachet', batch: 'ORS-611', expiry: 'Jun 2028', stock: 500, price: 0.90 }
    ];
    var cart = {}; // id -> qty
    var money = function (n) { return '$' + n.toFixed(2); };

    function renderGrid(filter) {
      grid.innerHTML = '';
      products.filter(function (p) { return !filter || p.name.toLowerCase().indexOf(filter.toLowerCase()) !== -1; })
      .forEach(function (p) {
        var col = document.createElement('div');
        col.className = 'col-md-6 col-xl-4';
        col.innerHTML = '<button type="button" class="pos-product">' +
          '<strong class="text-navy d-block">' + p.name + '</strong>' +
          '<small class="text-muted d-block">' + p.meta + '</small>' +
          '<small class="d-block mt-1">Batch ' + p.batch + ' · exp ' + p.expiry + ' · FEFO</small>' +
          '<span class="d-flex justify-content-between mt-1"><span class="badge ' + (p.low ? 'bg-warning text-dark' : 'bg-success-subtle text-success') + '">' + p.stock + ' available' + (p.low ? ' · LOW' : '') + '</span>' +
          '<strong class="text-emerald">' + money(p.price) + '</strong></span></button>';
        col.querySelector('button').addEventListener('click', function () {
          cart[p.id] = (cart[p.id] || 0) + 1; renderCart();
        });
        grid.appendChild(col);
      });
    }

    function renderCart() {
      var body = document.getElementById('cartBody');
      var total = 0, html = '';
      Object.keys(cart).forEach(function (id) {
        var p = products.find(function (x) { return x.id == id; });
        var line = p.price * cart[id]; total += line;
        html += '<tr><td><strong class="small">' + p.name + '</strong><br><small class="text-muted">' + money(p.price) + ' · Batch ' + p.batch + '</small></td>' +
          '<td><input class="form-control form-control-sm qty-input" type="number" min="0" value="' + cart[id] + '" data-id="' + p.id + '"></td>' +
          '<td class="text-end">' + money(line) + '</td></tr>';
      });
      body.innerHTML = html || '<tr><td colspan="3" class="text-muted small">Cart is empty — click a medicine to add it. Expired stock is never listed.</td></tr>';
      document.getElementById('cartTotal').textContent = money(total);
      body.querySelectorAll('.qty-input').forEach(function (input) {
        input.addEventListener('change', function () {
          var v = parseInt(input.value, 10) || 0;
          if (v <= 0) { delete cart[input.dataset.id]; } else { cart[input.dataset.id] = v; }
          renderCart();
        });
      });
    }

    var search = document.getElementById('posSearch');
    if (search) { search.addEventListener('input', function () { renderGrid(search.value); }); }
    var checkout = document.getElementById('checkoutBtn');
    if (checkout) {
      checkout.addEventListener('click', function () {
        if (!Object.keys(cart).length) { return; }
        document.getElementById('receiptBox').style.display = 'block';
        document.getElementById('receiptTotal').textContent = document.getElementById('cartTotal').textContent;
        cart = {}; renderCart();
        document.getElementById('receiptBox').scrollIntoView({ behavior: 'smooth' });
      });
    }
    renderGrid(''); renderCart();
  }
});
