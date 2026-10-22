<?php
/**
 * Extol Global Service Database Initialization Script (SQLite)
 * Run this once to set up the database
 */

$db_path = __DIR__ . '/paytrack.db';

try {
    // Create or connect to SQLite database
    $conn = new PDO('sqlite:' . $db_path);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Enable foreign keys
    $conn->exec('PRAGMA foreign_keys = ON');

    echo "✓ SQLite database connected\n";

    // Create customers table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            address TEXT,
            phone_number TEXT,
            is_admin INTEGER DEFAULT 0,
            balance REAL DEFAULT 0.00,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✓ customers table created\n";

    // Create transactions table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL,
            type TEXT NOT NULL CHECK(type IN ('deposit', 'withdrawal')),
            amount REAL NOT NULL,
            reference_code TEXT NOT NULL UNIQUE,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        )
    ");
    echo "✓ transactions table created\n";

    // Create contact_messages table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS contact_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL,
            subject TEXT NOT NULL,
            message TEXT NOT NULL,
            status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'responded')),
            response TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            responded_at DATETIME,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        )
    ");
    echo "✓ contact_messages table created\n";

    // Create admin account
    $admin_email = 'admin@paytrack.local';
    $admin_password = password_hash('Admin123!', PASSWORD_BCRYPT);
    $admin_name = 'Admin User';

    $check = $conn->query("SELECT id FROM customers WHERE email = ?");
    $stmt = $conn->prepare("SELECT id FROM customers WHERE email = ?");
    $stmt->execute([$admin_email]);

    if ($stmt->rowCount() === 0) {
        $insert = $conn->prepare("
            INSERT INTO customers (name, email, password_hash, is_admin, balance)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insert->execute([$admin_name, $admin_email, $admin_password, 1, 50000.00]);
        echo "✓ Admin account created\n";
    }

    // Create test user account
    $user_email = 'user@paytrack.local';
    $user_password = password_hash('User123!', PASSWORD_BCRYPT);
    $user_name = 'Test User';

    $stmt = $conn->prepare("SELECT id FROM customers WHERE email = ?");
    $stmt->execute([$user_email]);
    $user = $stmt->fetch();

    if (!$user) {
        $insert = $conn->prepare("
            INSERT INTO customers (name, email, password_hash, address, phone_number, is_admin, balance)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$user_name, $user_email, $user_password, '123 Main St', '555-0123', 0, 5000.00]);
        echo "✓ Test user account created\n";
        $user_id = $conn->lastInsertId();
    } else {
        $user_id = $user['id'];
    }

    // Add test transactions
    $transactions = [
        ['deposit', 1000, 'Initial deposit'],
        ['deposit', 2500, 'Salary deposit'],
        ['withdrawal', 500, 'Withdrawal for expenses'],
        ['deposit', 1200, 'Bonus payment'],
    ];

    $insert_stmt = $conn->prepare("
        INSERT INTO transactions (customer_id, type, amount, reference_code, description)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($transactions as $trans) {
        $ref = 'TXN-' . strtoupper(uniqid());
        $insert_stmt->execute([$user_id, $trans[0], $trans[1], $ref, $trans[2]]);
    }
    echo "✓ Sample transactions added\n";

    // Update user balance based on transactions
    $conn->exec("
        UPDATE customers
        SET balance = (
            SELECT COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0)
            FROM transactions
            WHERE customer_id = customers.id
        )
        WHERE is_admin = 0
    ");

    echo "\n📊 Extol Global Service Database Setup Complete!\n";
    echo str_repeat("=", 55) . "\n";
    echo "Admin Login:\n";
    echo "  Email: admin@paytrack.local\n";
    echo "  Password: Admin123!\n";
    echo "\nUser Login:\n";
    echo "  Email: user@paytrack.local\n";
    echo "  Password: User123!\n";
    echo str_repeat("=", 55) . "\n";
    echo "\n🌐 Access the application at: http://localhost:8000\n";

} catch (PDOException $e) {
    die("❌ Error: " . $e->getMessage() . "\n");
}
?>
