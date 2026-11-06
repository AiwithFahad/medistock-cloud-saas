// MediStock Cloud — shared front-end behaviours (site + dashboards).
document.addEventListener('DOMContentLoaded', function () {
  // Collapsible admin sidebar (mobile / tablet).
  var toggle = document.getElementById('sidebarToggle');
  var sidebar = document.getElementById('adminSidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () { sidebar.classList.toggle('open'); });
    document.addEventListener('click', function (ev) {
      if (window.innerWidth < 992 && sidebar.classList.contains('open')
          && !sidebar.contains(ev.target) && ev.target !== toggle && !toggle.contains(ev.target)) {
        sidebar.classList.remove('open');
      }
    });
  }

  // Pricing monthly / yearly toggle.
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
    billingToggle.addEventListener('change', apply);
    apply();
  }

  // Auto-dismiss flash alerts.
  document.querySelectorAll('.alert-dismissible').forEach(function (el) {
    setTimeout(function () {
      if (window.bootstrap) { bootstrap.Alert.getOrCreateInstance(el).close(); }
    }, 6000);
  });
});
