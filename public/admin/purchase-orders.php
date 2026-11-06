<?php
/** MediStock Cloud — Purchase Orders (admin tree). Logic: app/partials/purchase_orders.php */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_permission('purchases.manage');
$tid = tenant_id();
$base = 'admin/purchase-orders.php';
$activePath = 'admin/purchase-orders.php';
require __DIR__ . '/../../app/partials/purchase_orders.php';
