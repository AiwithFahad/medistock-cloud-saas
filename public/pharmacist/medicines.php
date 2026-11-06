<?php
/**
 * MediStock Cloud — Pharmacist medicine search & availability explorer.
 * Live AJAX search (api/medicines-list.php) with category/status filters,
 * sorting and pagination; expanding a row loads that medicine's batch
 * availability with expiry warnings (api/pos-search.php). Read-only.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_role(['pharmacist', 'admin', 'owner', 'cashier', 'inventory_manager']);
$tid = tenant_id();
$pdo = db();
$categories = $pdo->prepare('SELECT id, name FROM categories WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name');
$categories->execute([$tid]);
$categories = $categories->fetchAll();

admin_header('Medicine Search', 'pharmacist/medicines.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-search-heart text-emerald"></i> Medicine Search & Availability</h4>
  <span class="text-muted small">Live data · click a row for batch-level availability</span>
</div>

<div class="card shadow-sm mb-3"><div class="card-body py-3">
  <div class="row g-2">
    <div class="col-md-5"><input class="form-control" id="medQ" placeholder="Search name, generic, SKU or barcode…" value="<?= e((string)input('q', '')) ?>"></div>
    <div class="col-md-3"><select class="form-select" id="medCat">
      <option value="0">All categories</option>
      <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
    </select></div>
    <div class="col-md-2"><select class="form-select" id="medSort">
      <option value="name">Sort: name</option>
      <option value="available">Sort: availability</option>
      <option value="price">Sort: price</option>
    </select></div>
    <div class="col-md-2"><select class="form-select" id="medDir"><option value="asc">Ascending</option><option value="desc">Descending</option></select></div>
  </div>
</div></div>

<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th></th><th>Medicine</th><th>Brand / Category</th><th>Strength</th><th>Rx</th><th class="text-end">Available</th><th class="text-end">From</th><th>Batches</th></tr></thead>
  <tbody id="medBody"><tr><td colspan="8" class="text-center text-muted py-4">Loading…</td></tr></tbody>
</table></div></div>
<div class="d-flex justify-content-between align-items-center mt-2">
  <span class="small text-muted" id="medMeta"></span>
  <div class="btn-group btn-group-sm">
    <button class="btn btn-outline-secondary" id="medPrev"><i class="bi bi-chevron-left"></i></button>
    <button class="btn btn-outline-secondary" id="medNext"><i class="bi bi-chevron-right"></i></button>
  </div>
</div>

<script>
(function () {
  var listUrl = <?= json_encode(url('api/medicines-list.php')) ?>;
  var searchUrl = <?= json_encode(url('api/pos-search.php')) ?>;
  var page = 1, pages = 1;
  var body = document.getElementById('medBody');

  function badge(r) {
    var avail = Number(r.available);
    var cls = r.low_stock ? 'text-bg-danger' : 'text-bg-success';
    return '<span class="badge ' + cls + '">' + avail + '</span>' + (r.low_stock ? ' <span class="text-danger small">low</span>' : '');
  }
  function load() {
    var params = new URLSearchParams({
      q: document.getElementById('medQ').value,
      category_id: document.getElementById('medCat').value,
      sort: document.getElementById('medSort').value,
      dir: document.getElementById('medDir').value,
      page: page, per_page: 12
    });
    window.msFetch(listUrl + '?' + params.toString()).then(function (data) {
      pages = data.pages; page = data.page;
      document.getElementById('medMeta').textContent = data.total + ' medicines · page ' + data.page + ' of ' + data.pages;
      if (!data.rows.length) { body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No medicines match.</td></tr>'; return; }
      body.innerHTML = data.rows.map(function (r) {
        return '<tr data-med="' + r.id + '" style="cursor:pointer">'
          + '<td>' + (r.photo ? '<img class="thumb-sm" src="' + msEsc(r.photo) + '" alt="">' : '<span class="thumb-sm d-inline-flex align-items-center justify-content-center bg-light"><i class="bi bi-capsule text-muted"></i></span>') + '</td>'
          + '<td><strong>' + msEsc(r.name) + '</strong><br><small class="text-muted">' + msEsc(r.generic_name || '') + ' · ' + msEsc(r.sku || r.barcode || '') + '</small></td>'
          + '<td class="small">' + msEsc(r.brand_name || r.manufacturer || '—') + '<br><span class="text-muted">' + msEsc(r.category || '') + '</span></td>'
          + '<td>' + msEsc(r.strength || '') + ' ' + msEsc(r.unit || '') + '</td>'
          + '<td>' + (r.requires_prescription ? '<span class="badge text-bg-warning">Rx</span>' : '<span class="text-muted small">OTC</span>') + '</td>'
          + '<td class="text-end">' + badge(r) + '</td>'
          + '<td class="text-end">' + (r.min_price != null ? msMoney(r.min_price) : '—') + '</td>'
          + '<td>' + r.batch_count + '</td></tr>'
          + '<tr class="d-none" data-detail="' + r.id + '"><td colspan="8" class="bg-light"></td></tr>';
      }).join('');
      body.querySelectorAll('[data-med]').forEach(function (tr) {
        tr.addEventListener('click', function () { toggleDetail(tr); });
      });
    }).catch(function (err) { window.msToast(err.message, 'error'); });
  }
  function toggleDetail(tr) {
    var id = tr.getAttribute('data-med');
    var detailRow = body.querySelector('[data-detail="' + id + '"]');
    if (!detailRow.classList.contains('d-none')) { detailRow.classList.add('d-none'); return; }
    detailRow.classList.remove('d-none');
    var cell = detailRow.querySelector('td');
    cell.innerHTML = '<span class="text-muted small">Loading batches…</span>';
    var med = tr.querySelector('strong').textContent;
    window.msFetch(searchUrl + '?q=' + encodeURIComponent(med)).then(function (data) {
      var found = (data.results || []).find(function (r) { return r.id === Number(id); });
      if (!found || !found.batches.length) { cell.innerHTML = '<span class="text-muted small">No batches recorded.</span>'; return; }
      cell.innerHTML = '<table class="table table-sm mb-0"><thead><tr><th>Batch</th><th>Expiry</th><th>Status</th><th class="text-end">Qty</th><th class="text-end">Price</th></tr></thead><tbody>'
        + found.batches.map(function (b) {
          var status = b.expired ? '<span class="badge text-bg-danger">EXPIRED — cannot sell</span>'
            : (b.days_left <= 30 ? '<span class="badge text-bg-warning">Expires in ' + b.days_left + 'd</span>' : '<span class="badge text-bg-success">In date</span>');
          return '<tr><td>' + msEsc(b.batch_number) + '</td><td>' + msEsc(b.expiry_date) + '</td><td>' + status + '</td><td class="text-end">' + b.quantity + '</td><td class="text-end">' + msMoney(b.selling_price) + '</td></tr>';
        }).join('') + '</tbody></table>';
    }).catch(function (err) { cell.innerHTML = '<span class="text-danger small">' + msEsc(err.message) + '</span>'; });
  }
  var debounced = window.msDebounce(function () { page = 1; load(); }, 300);
  document.getElementById('medQ').addEventListener('input', debounced);
  ['medCat', 'medSort', 'medDir'].forEach(function (id) { document.getElementById(id).addEventListener('change', function () { page = 1; load(); }); });
  document.getElementById('medPrev').addEventListener('click', function () { if (page > 1) { page--; load(); } });
  document.getElementById('medNext').addEventListener('click', function () { if (page < pages) { page++; load(); } });
  load();
})();
</script>
<?php admin_footer(); ?>
