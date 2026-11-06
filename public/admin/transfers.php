<?php
/** MediStock Cloud — Branch Transfers (admin tree). Logic: app/partials/transfers.php */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_permission('stock.view');
$tid = tenant_id();
$base = 'admin/transfers.php';
$activePath = 'admin/transfers.php';
require __DIR__ . '/../../app/partials/transfers.php';
