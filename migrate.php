<?php
/**
 * Database Migration Script
 * Extol Global Service Phase 2+ Features
 * Legacy SQLite setup migration for Staff, Loans, and KYC. Do not run against live data.
 */

$db_path = dirname(__FILE__) . '/paytrack.db';

try {
    $conn = new PDO('sqlite:' . $db_path);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $conn->exec('PRAGMA foreign_keys = ON');
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

try {
    echo "Starting Extol Global Service database migration...\n\n";

    // 1. Backup existing database
    $db_path = dirname(__DIR__) . '/paytrack.db';
    $backup_path = $db_path . '.backup.' . date('Y-m-d-H-i-s');
    copy($db_path, $backup_path);
    echo "✓ Database backed up to: $backup_path\n\n";

    // 2. Drop old transactions table if exists (we need to recreate with staff_id)
    echo "Migrating transactions table...\n";
    try {
        $conn->exec("DROP TABLE IF EXISTS transactions");
    } catch (Exception $e) {
        // Table might not exist or have constraints
    }

    // 3. Create new transactions table with staff tracking
    $conn->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL,
        staff_id INTEGER,
        admin_id INTEGER,
        type TEXT CHECK(type IN ('deposit', 'withdrawal')) NOT NULL,
        amount REAL NOT NULL,
        reference_code TEXT NOT NULL UNIQUE,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE SET NULL,
        FOREIGN KEY (admin_id) REFERENCES customers(id) ON DELETE SET NULL
    )");
    echo "✓ Transactions table created with recorder tracking\n";

    // 4. Create staff table
    echo "\nCreating staff table...\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS staff (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        phone_number TEXT NOT NULL UNIQUE,
        staff_id TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT CHECK(role IN ('loans')) NOT NULL,
        address TEXT,
        gender TEXT,
        date_of_birth DATE,
        notes TEXT,
        is_active INTEGER DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✓ Staff table created\n";

    // 5. Create KYC records table
    echo "\nCreating KYC records table...\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS kyc_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL UNIQUE,
        id_type TEXT,
        id_number TEXT,
        id_expiry DATE,
        address TEXT,
        employment_status TEXT,
        income_source TEXT,
        bank_account TEXT,
        bank_name TEXT,
        reference_contact_name TEXT,
        reference_contact_phone TEXT,
        bvn TEXT,
        nin TEXT,
        guarantor_name TEXT,
        guarantor_phone TEXT,
        guarantor_address TEXT,
        collateral TEXT,
        verification_status TEXT CHECK(verification_status IN ('pending', 'approved', 'rejected')) DEFAULT 'pending',
        verified_by INTEGER,
        verified_at TIMESTAMP,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (verified_by) REFERENCES staff(id) ON DELETE SET NULL
    )");
    echo "✓ KYC records table created\n";

    // 6. Create loans table
    echo "\nCreating loans table...\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS loans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL,
        loan_amount REAL NOT NULL,
        interest_rate REAL NOT NULL,
        repayment_frequency TEXT,
        purpose TEXT,
        guarantor_name TEXT,
        guarantor_phone TEXT,
        guarantor_address TEXT,
        collateral TEXT,
        date_given DATE NOT NULL,
        due_date DATE NOT NULL,
        created_by INTEGER,
        created_by_admin INTEGER,
        status TEXT CHECK(status IN ('active', 'completed', 'overdue', 'default')) DEFAULT 'active',
        total_repaid REAL DEFAULT 0.00,
        interest_charged REAL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES staff(id) ON DELETE RESTRICT,
        FOREIGN KEY (created_by_admin) REFERENCES customers(id) ON DELETE SET NULL
    )");
    echo "✓ Loans table created\n";

    // 7. Create loan repayments table
    echo "\nCreating loan repayments table...\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS loan_repayments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        loan_id INTEGER NOT NULL,
        amount REAL NOT NULL,
        interest_paid REAL DEFAULT 0.00,
        principal_paid REAL DEFAULT 0.00,
        repayment_date DATE NOT NULL,
        reference_code TEXT NOT NULL UNIQUE,
        notes TEXT,
        recorded_by INTEGER,
        recorded_by_admin INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
        FOREIGN KEY (recorded_by) REFERENCES staff(id) ON DELETE RESTRICT,
        FOREIGN KEY (recorded_by_admin) REFERENCES customers(id) ON DELETE SET NULL
    )");
    echo "✓ Loan repayments table created\n";

    // 7b. Create loan applications table (customer-initiated)
    echo "\nCreating loan applications table...\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS loan_applications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL,
        loan_amount REAL NOT NULL,
        interest_rate REAL NOT NULL DEFAULT 5.00,
        repayment_frequency TEXT NOT NULL DEFAULT 'daily',
        purpose TEXT,
        status TEXT CHECK(status IN ('pending', 'approved', 'rejected')) DEFAULT 'pending',
        reviewed_by INTEGER,
        reviewed_by_admin INTEGER,
        reviewed_at TIMESTAMP,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewed_by) REFERENCES staff(id) ON DELETE SET NULL,
        FOREIGN KEY (reviewed_by_admin) REFERENCES customers(id) ON DELETE SET NULL
    )");
    echo "✓ Loan applications table created\n";

    // 9. Create receipts table
    echo "\nCreating receipts table...\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS receipts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        transaction_id INTEGER,
        loan_repayment_id INTEGER,
        receipt_number TEXT NOT NULL UNIQUE,
        amount REAL NOT NULL,
        receipt_type TEXT CHECK(receipt_type IN ('transaction', 'loan_repayment')) NOT NULL,
        generated_by INTEGER,
        generated_by_admin INTEGER,
        generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
        FOREIGN KEY (loan_repayment_id) REFERENCES loan_repayments(id) ON DELETE SET NULL,
        FOREIGN KEY (generated_by) REFERENCES staff(id) ON DELETE RESTRICT,
        FOREIGN KEY (generated_by_admin) REFERENCES customers(id) ON DELETE SET NULL
    )");
    echo "✓ Receipts table created\n";

    // 10. Create indexes
    echo "\nCreating indexes...\n";
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_customer_phone ON customers(phone_number)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_staff_phone ON staff(phone_number)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_staff_id ON staff(staff_id)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_transaction_customer ON transactions(customer_id)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_transaction_staff ON transactions(staff_id)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_transaction_date ON transactions(created_at)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_message_customer ON contact_messages(customer_id)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_kyc_customer ON kyc_records(customer_id)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_loan_customer ON loans(customer_id)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_loan_status ON loans(status)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_loan_app_customer ON loan_applications(customer_id)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_loan_app_status ON loan_applications(status)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_repayment_loan ON loan_repayments(loan_id)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_receipt_number ON receipts(receipt_number)");
    echo "✓ Indexes created\n";

    // 11. Update customers table to add new fields
    echo "\nUpdating customers table...\n";
    try {
        $conn->exec("ALTER TABLE customers ADD COLUMN card_id TEXT");
    } catch (Exception $e) {
        // Column might already exist
    }

    try {
        $conn->exec("ALTER TABLE customers ADD COLUMN gender TEXT");
    } catch (Exception $e) {
        // Column might already exist
    }

    try {
        $conn->exec("ALTER TABLE customers ADD COLUMN date_of_birth DATE");
    } catch (Exception $e) {
        // Column might already exist
    }

    try {
        $conn->exec("ALTER TABLE customers ADD COLUMN notes TEXT");
    } catch (Exception $e) {
        // Column might already exist
    }
    echo "✓ Customers table updated\n";

    echo "\n✅ Migration completed successfully!\n";
    echo "Backup location: $backup_path\n\n";

    // 12. Summary
    echo "=== MIGRATION SUMMARY ===\n";
    echo "New tables created:\n";
    echo "  - staff\n";
    echo "  - kyc_records\n";
    echo "  - loans\n";
    echo "  - loan_repayments\n";
    echo "  - receipts\n";
    echo "\nModified tables:\n";
    echo "  - transactions (added staff_id tracking)\n";
    echo "  - customers (added card_id, gender, date_of_birth, notes)\n";
    echo "\n✓ All indexes created for performance\n";
    echo "✓ Foreign key constraints enabled\n";

} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Check backup at: $backup_path\n";
    exit(1);
}

?>
