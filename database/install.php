<?php
/**
 * MediStock Cloud — database installer.
 *
 * Usage (recommended, CLI):
 *   php database/install.php
 *
 * It creates the database (if missing) and imports database/database.sql,
 * which contains the full schema + demo seeds. Safe to run once on a fresh
 * database; re-running on an existing database will fail on duplicate seeds.
 *
 * Web access is refused unless APP_DEBUG=true and a ?key matches
 * INSTALL_KEY from the environment — delete or protect this file in
 * production.
 */

declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $debug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    $keyOk = hash_equals((string)(getenv('INSTALL_KEY') ?: ''), (string)($_GET['key'] ?? ''));
    if (!$debug || !$keyOk) {
        http_response_code(403);
        exit('Installer locked. Run "php database/install.php" from the command line.');
    }
}

// Minimal .env loading (mirrors app/bootstrap.php).
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim(trim($v), "\"'"));
    }
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_DATABASE') ?: 'medistock_cloud';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';

try {
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");

    $sql = file_get_contents(__DIR__ . '/database.sql');
    // Split on statement boundaries (the file contains no stored procedures).
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
    $count = 0;
    foreach ($statements as $statement) {
        // Skip pure comment blocks.
        $meaningful = preg_replace('/^\s*--.*$/m', '', $statement);
        if (trim((string)$meaningful) === '') {
            continue;
        }
        $pdo->exec($statement);
        $count++;
    }
    $tables = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
    echo "MediStock Cloud installed: {$count} statements executed, {$tables} tables present in `{$dbName}`.\n";
    echo "Demo logins (password: password):\n";
    echo "  Super Admin      super@medistock.test\n";
    echo "  Pharmacy Admin   admin@demo.com\n";
    echo "  Pharmacist       pharmacist@demo.com\n";
    echo "  Cashier          cashier@demo.com\n";
    echo "Next: set APP_INSTALLED=1 in .env and serve the public/ directory.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, 'Install failed: ' . $ex->getMessage() . "\n");
    exit(1);
}
