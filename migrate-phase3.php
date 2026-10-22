<?php
/**
 * Extol Global Service — Phase 3 live migration (SQLite)
 * Idempotent + safe to run multiple times.
 *
 * Adds, to the EXISTING database:
 *   - kyc_records: bvn, nin, guarantor_name, guarantor_phone,
 *                  guarantor_address, collateral
 *   - new table:   loan_applications (customer-initiated apply-for-loan)
 *
 * The live DB already exists (created by migrate.php), so CREATE TABLE
 * IF NOT EXISTS alone won't add the new columns — we ALTER instead, with
 * a column-existence check so re-runs don't error.
 *
 * Run:   php migrate-phase3.php
 * It backs up the DB first to paytrack.db.backup.<timestamp>.
 */

$db_path = __DIR__ . '/paytrack.db';

if (!file_exists($db_path)) {
    die("No database found at {$db_path}\n");
}

// 1. Backup before touching anything
$backup_path = $db_path . '.backup.' . date('Y-m-d-H-i-s');
copy($db_path, $backup_path);

try {
    $conn = new PDO('sqlite:' . $db_path);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $conn->exec('PRAGMA foreign_keys = ON');

    echo "✓ Connected. Backup written to: " . basename($backup_path) . "\n\n";

    // Helper: only ALTER if the column is missing (idempotent)
    $columnExists = function ($table, $column) use ($conn) {
        $rows = $conn->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ($row['name'] === $column) return true;
        }
        return false;
    };

    // 2. Add KYC columns
    echo "Adding kyc_records columns...\n";
    $kyc_cols = [
        'bvn'               => 'TEXT',
        'nin'               => 'TEXT',
        'guarantor_name'    => 'TEXT',
        'guarantor_phone'   => 'TEXT',
        'guarantor_address' => 'TEXT',
        'collateral'        => 'TEXT',
    ];
    foreach ($kyc_cols as $col => $type) {
        if ($columnExists('kyc_records', $col)) {
            echo "  = kyc_records.{$col} already present\n";
        } else {
            $conn->exec("ALTER TABLE kyc_records ADD COLUMN {$col} {$type}");
            echo "  + kyc_records.{$col} added\n";
        }
    }

    // 3. Create loan_applications table (fresh installs + live DB)
    echo "\nCreating loan_applications table...\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS loan_applications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL,
        loan_amount REAL NOT NULL,
        interest_rate REAL NOT NULL DEFAULT 5.00,
        repayment_frequency TEXT NOT NULL DEFAULT 'daily',
        purpose TEXT,
        status TEXT CHECK(status IN ('pending', 'approved', 'rejected')) DEFAULT 'pending',
        reviewed_by INTEGER,
        reviewed_at TIMESTAMP,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewed_by) REFERENCES staff(id) ON DELETE SET NULL
    )");
    echo "✓ loan_applications ready\n";

    // 4. Indexes
    echo "\nCreating indexes...\n";
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_loan_app_customer ON loan_applications(customer_id)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_loan_app_status ON loan_applications(status)");
    echo "✓ Indexes ready\n";

    echo "\n✅ Phase 3 migration complete.\n";
    echo "   Backup: " . basename($backup_path) . "\n";

} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    echo "   Restore from: " . basename($backup_path) . "\n";
    exit(1);
}

?>
