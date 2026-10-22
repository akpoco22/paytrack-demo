<?php
/**
 * Database Configuration
 * Extol Global Service
 * Uses SQLite. tuned for low-concurrency shared hosting + up to ~1000 users.
 *
 * On Hostinger: move paytrack.db OUTSIDE public_html and set the
 * EXTOL_DB_PATH environment variable to its absolute path, e.g.
 *   /home/u123456789/extol-data/paytrack.db
 * Locally (or if the env var is missing) it falls back to paytrack.db
 * next to this file.
 */

$db_path = getenv('PAYTRACK_DB_PATH');
if (!$db_path) {
    $db_path = getenv('DB_PATH');
}
if (!$db_path) {
    $db_path = getenv('EXTOL_DB_PATH');
}
if (!$db_path) {
    $db_path = dirname(__DIR__) . '/paytrack.db';
}

try {
    $conn = new PDO('sqlite:' . $db_path);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Enforce foreign keys
    $conn->exec('PRAGMA foreign_keys = ON');

    // WAL mode: readers don't block writers — fewer "database is locked"
    // errors when two staff save at the same time.
    $conn->exec('PRAGMA journal_mode = WAL');

    // Wait up to 5s on a locked DB instead of failing instantly.
    $conn->exec('PRAGMA busy_timeout = 5000');

    // NORMAL is safe with WAL and much faster on shared disks.
    $conn->exec('PRAGMA synchronous = NORMAL');
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

return $conn;
?>
