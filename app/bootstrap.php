<?php
/**
 * MediStock Cloud — application bootstrap.
 * Loads environment, starts a hardened session, wires config + helpers + services.
 */

declare(strict_types=1);

// ---------------------------------------------------------------- .env loader
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

$GLOBALS['app_config'] = require dirname(__DIR__) . '/config/app.php';
date_default_timezone_set($GLOBALS['app_config']['timezone']);

if ($GLOBALS['app_config']['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', dirname(__DIR__) . '/storage/logs/php-errors.log');
}

// ------------------------------------------------------------- Secure session
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('MEDISTOCKSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Buffer output so redirects remain possible after layout rendering
// (module pages render the header before the CRUD engine handles POSTs).
ob_start();

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/SubscriptionService.php';
require_once __DIR__ . '/StockService.php';
require_once __DIR__ . '/Crud.php';
require_once __DIR__ . '/View.php';
