<?php
/**
 * Extol Phase 4 SQLite migration.
 * Removes savings targets, reassigns the former savings staff role, and adds admin audit actor fields.
 * Destructive: use only on a confirmed database after an approved, SQLite-consistent backup.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once __DIR__ . '/src/database/Phase4Migration.php';

$db_path = getenv('EXTOL_DB_PATH') ?: (getenv('DB_PATH') ?: __DIR__ . '/paytrack.db');
if (!is_file($db_path)) {
    fwrite(STDERR, "Database does not exist at configured path: {$db_path}\n");
    exit(1);
}
try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA busy_timeout = 5000');
    $result = Phase4Migration::apply($db);
    fwrite(STDOUT, "Phase 4 migration completed; foreign-key checks passed.\n");
    fwrite(STDOUT, 'Staff on loans role: ' . $result['staff_reassigned'] . "\n");
    fwrite(STDOUT, 'Savings-target table removed: ' . ($result['savings_table_removed'] ? 'yes' : 'no') . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
