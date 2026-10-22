<?php
/**
 * Extol Global Service Database Initialization Script
 * Run this once to set up the database
 */

// Database credentials - EDIT THESE if needed
$db_host = 'localhost';
$db_user = 'root';
$db_pass = ''; // Change if you have a MySQL password
$db_name = 'paytrack';

// Connect to MySQL (without specifying database)
$conn = new mysqli($db_host, $db_user, $db_pass);

if ($conn->connect_error) {
    die("❌ Connection failed: " . $conn->connect_error . "\n");
}

echo "✓ Connected to MySQL\n";

// Create database
$sql = "CREATE DATABASE IF NOT EXISTS $db_name";
if ($conn->query($sql) === TRUE) {
    echo "✓ Database '$db_name' ready\n";
} else {
    die("❌ Error creating database: " . $conn->error . "\n");
}

// Select database
$conn->select_db($db_name);

// Read and execute schema
$schema = file_get_contents(__DIR__ . '/schema.sql');
$statements = array_filter(array_map('trim', explode(';', $schema)));

foreach ($statements as $statement) {
    if (!empty($statement)) {
        if ($conn->query($statement) === FALSE) {
            echo "⚠ Warning: " . $conn->error . "\n";
        }
    }
}

echo "✓ Schema created\n";

// Create a test admin account
$admin_email = 'admin@paytrack.local';
$admin_password = password_hash('Admin123!', PASSWORD_BCRYPT);
$admin_name = 'Admin User';

$check = $conn->query("SELECT id FROM customers WHERE email = '$admin_email'");
if ($check->num_rows === 0) {
    $insert = "INSERT INTO customers (name, email, password_hash, is_admin, balance)
               VALUES ('$admin_name', '$admin_email', '$admin_password', 1, 50000.00)";
    if ($conn->query($insert) === TRUE) {
        echo "✓ Admin account created (email: admin@paytrack.local, password: Admin123!)\n";
    }
}

// Create test user account
$user_email = 'user@paytrack.local';
$user_password = password_hash('User123!', PASSWORD_BCRYPT);
$user_name = 'Test User';

$check = $conn->query("SELECT id FROM customers WHERE email = '$user_email'");
if ($check->num_rows === 0) {
    $insert = "INSERT INTO customers (name, email, password_hash, address, phone_number, is_admin, balance)
               VALUES ('$user_name', '$user_email', '$user_password', '123 Main St', '555-0123', 0, 5000.00)";
    if ($conn->query($insert) === TRUE) {
        echo "✓ Test user account created (email: user@paytrack.local, password: User123!)\n";
    }
}

// Add some test transactions for the user
$user_id = $conn->query("SELECT id FROM customers WHERE email = '$user_email'")->fetch_assoc()['id'];
$transactions = [
    ['deposit', 1000, 'Initial deposit'],
    ['deposit', 2500, 'Salary deposit'],
    ['withdrawal', 500, 'Withdrawal for expenses'],
    ['deposit', 1200, 'Bonus payment'],
];

foreach ($transactions as $trans) {
    $ref = 'TXN-' . strtoupper(uniqid());
    $type = $trans[0];
    $amount = $trans[1];
    $desc = $trans[2];

    $insert = "INSERT INTO transactions (customer_id, type, amount, reference_code, description)
               VALUES ($user_id, '$type', $amount, '$ref', '$desc')";
    $conn->query($insert);
}

echo "✓ Sample transactions added\n";
echo "\n📊 Extol Global Service Database Setup Complete!\n";
echo "=" . str_repeat("=", 50) . "\n";
echo "Admin Login:\n";
echo "  Email: admin@paytrack.local\n";
echo "  Password: Admin123!\n";
echo "\nUser Login:\n";
echo "  Email: user@paytrack.local\n";
echo "  Password: User123!\n";
echo "=" . str_repeat("=", 50) . "\n";

$conn->close();
?>
