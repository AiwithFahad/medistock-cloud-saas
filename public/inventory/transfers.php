<?php
/** MediStock Cloud — Branch Transfers (inventory workspace). Logic: app/partials/transfers.php */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_role(['inventory_manager', 'admin', 'owner']);
$tid = tenant_id();
$base = 'inventory/transfers.php';
$activePath = 'inventory/transfers.php';
require __DIR__ . '/../../app/partials/transfers.php';
