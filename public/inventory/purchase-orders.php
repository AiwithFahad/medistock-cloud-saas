<?php
/** MediStock Cloud — Purchase Orders (inventory workspace). Logic: app/partials/purchase_orders.php */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_role(['inventory_manager', 'admin', 'owner']);
$tid = tenant_id();
$base = 'inventory/purchase-orders.php';
$activePath = 'inventory/purchase-orders.php';
require __DIR__ . '/../../app/partials/purchase_orders.php';
