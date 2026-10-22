<?php
/**
 * Extol Phase 5 SQLite migration (automatic monthly loan deduction).
 * Destructive table rebuilds: run only on a confirmed database after a SQLite-consistent backup.
 * Usage (CLI only):  EXTOL_DB_PATH=/path/to/paytrack.db php migrate-phase5.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once __DIR__ . '/src/database/Phase5Migration.php';

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
    $result = Phase5Migration::apply($db);
    fwrite(STDOUT, "Phase 5 migration completed; foreign-key checks passed.\n");
    fwrite(STDOUT, 'transactions rebuilt: ' . ($result['transactions_rebuilt'] ? 'yes' : 'no (already done)') . "\n");
    fwrite(STDOUT, 'loan_repayments rebuilt: ' . ($result['repayments_rebuilt'] ? 'yes' : 'no (already done)') . "\n");
    fwrite(STDOUT, 'loan_auto_deductions created: ' . ($result['deductions_table_created'] ? 'yes' : 'no (already exists)') . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
