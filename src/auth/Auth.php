<?php
/**
 * Authentication Handler
 * Extol Global Service
 * Supports both MySQLi and SQLite
 */

class Auth {
    private $conn;
    private $is_pdo;

    public function __construct($database_connection) {
        $this->conn = $database_connection;
        $this->is_pdo = ($database_connection instanceof PDO);
    }

    /**
     * Register a new user
     */
    public function register($name, $email, $password, $address = '', $phone = '') {
        // Validate input
        if (empty($email) || empty($password) || empty($name)) {
            return ['success' => false, 'message' => 'All fields are required'];
        }

        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            return ['success' => false, 'message' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters'];
        }

        // Check if email already exists
        if ($this->is_pdo) {
            $check = $this->conn->prepare("SELECT id FROM customers WHERE email = ?");
            $check->execute([$email]);
        } else {
            $check = $this->conn->prepare("SELECT id FROM customers WHERE email = ?");
            $check->bind_param("s", $email);
            $check->execute();
            $check = $check->get_result();
        }

        if ($this->is_pdo ? $check->fetch(PDO::FETCH_ASSOC) !== false : $check->num_rows > 0) {
            return ['success' => false, 'message' => 'Email already registered'];
        }

        // Hash password
        $password_hash = password_hash($password, PASSWORD_BCRYPT);

        // Insert user
        if ($this->is_pdo) {
            $insert = $this->conn->prepare("
                INSERT INTO customers (name, email, password_hash, address, phone_number, is_admin)
                VALUES (?, ?, ?, ?, ?, 0)
            ");
            $result = $insert->execute([$name, $email, $password_hash, $address, $phone]);
        } else {
            $insert = $this->conn->prepare("
                INSERT INTO customers (name, email, password_hash, address, phone_number, is_admin)
                VALUES (?, ?, ?, ?, ?, FALSE)
            ");
            $insert->bind_param("sssss", $name, $email, $password_hash, $address, $phone);
            $result = $insert->execute();
        }

        if ($result) {
            return ['success' => true, 'message' => 'User registered successfully'];
        } else {
            return ['success' => false, 'message' => 'Registration failed'];
        }
    }

    /**
     * Universal login - accepts phone number as primary (for all user types)
     * Falls back to email if phone not found
     */
    public function universalLogin($identifier, $password) {
        if (empty($identifier) || empty($password)) {
            return ['success' => false, 'message' => 'Phone number/Email and password are required'];
        }

        // First, try to login as staff (phone + staff ID)
        if ($this->is_pdo) {
            $staff_query = $this->conn->prepare("SELECT id, name, phone_number, staff_id, password_hash, role, is_active FROM staff WHERE phone_number = ? AND is_active = 1");
            $staff_query->execute([$identifier]);
            $staff = $staff_query->fetch(PDO::FETCH_ASSOC);
        } else {
            $staff_query = $this->conn->prepare("SELECT id, name, phone_number, staff_id, password_hash, role, is_active FROM staff WHERE phone_number = ? AND is_active = 1");
            $staff_query->bind_param("s", $identifier);
            $staff_query->execute();
            $staff = $staff_query->get_result()->fetch_assoc();
        }

        if ($staff && password_verify($password, $staff['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $staff['id'];
            $_SESSION['name'] = $staff['name'];
            $_SESSION['phone_number'] = $staff['phone_number'];
            $_SESSION['staff_id'] = $staff['staff_id'];
            $_SESSION['staff_role'] = $staff['role'];
            $_SESSION['user_type'] = 'staff';
            $_SESSION['login_time'] = time();
            return ['success' => true, 'message' => 'Login successful', 'user_type' => 'staff', 'role' => $staff['role']];
        }

        // Second, try to login as customer (phone + card)
        if ($this->is_pdo) {
            $cust_query = $this->conn->prepare("SELECT id, name, phone_number, card_id, password_hash, is_admin FROM customers WHERE phone_number = ? AND is_admin = 0");
            $cust_query->execute([$identifier]);
            $customer = $cust_query->fetch(PDO::FETCH_ASSOC);
        } else {
            $cust_query = $this->conn->prepare("SELECT id, name, phone_number, card_id, password_hash, is_admin FROM customers WHERE phone_number = ? AND is_admin = 0");
            $cust_query->bind_param("s", $identifier);
            $cust_query->execute();
            $customer = $cust_query->get_result()->fetch_assoc();
        }

        if ($customer && password_verify($password, $customer['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $customer['id'];
            $_SESSION['name'] = $customer['name'];
            $_SESSION['phone_number'] = $customer['phone_number'];
            $_SESSION['card_id'] = $customer['card_id'];
            $_SESSION['user_type'] = 'customer';
            $_SESSION['is_admin'] = false;
            $_SESSION['login_time'] = time();
            return ['success' => true, 'message' => 'Login successful', 'user_type' => 'customer'];
        }

        // Third, try to login as admin (email or phone + password)
        if ($this->is_pdo) {
            $admin_query = $this->conn->prepare("SELECT id, name, email, password_hash, is_admin FROM customers WHERE (email = ? OR phone_number = ?) AND is_admin = 1");
            $admin_query->execute([$identifier, $identifier]);
            $admin = $admin_query->fetch(PDO::FETCH_ASSOC);
        } else {
            $admin_query = $this->conn->prepare("SELECT id, name, email, password_hash, is_admin FROM customers WHERE (email = ? OR phone_number = ?) AND is_admin = 1");
            $admin_query->bind_param("ss", $identifier, $identifier);
            $admin_query->execute();
            $admin = $admin_query->get_result()->fetch_assoc();
        }

        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['email'] = $admin['email'];
            $_SESSION['name'] = $admin['name'];
            $_SESSION['is_admin'] = true;
            $_SESSION['user_type'] = 'customer';
            $_SESSION['login_time'] = time();
            return ['success' => true, 'message' => 'Login successful', 'is_admin' => true];
        }

        return ['success' => false, 'message' => 'Invalid credentials'];
    }

    /**
     * Login staff (via phone + staff ID)
     */
    public function loginStaff($phone_number, $staff_id) {
        if (empty($phone_number) || empty($staff_id)) {
            return ['success' => false, 'message' => 'Phone number and Staff ID are required'];
        }

        if ($this->is_pdo) {
            $query = $this->conn->prepare("SELECT id, name, phone_number, staff_id, password_hash, role, is_active FROM staff WHERE phone_number = ? AND is_active = 1");
            $query->execute([$phone_number]);
            $result = $query->fetch(PDO::FETCH_ASSOC);
        } else {
            $query = $this->conn->prepare("SELECT id, name, phone_number, staff_id, password_hash, role, is_active FROM staff WHERE phone_number = ? AND is_active = 1");
            $query->bind_param("s", $phone_number);
            $query->execute();
            $result = $query->get_result();
            $result = $result->fetch_assoc();
        }

        if (!$result) {
            return ['success' => false, 'message' => 'Invalid credentials'];
        }

        if (!password_verify($staff_id, $result['password_hash'])) {
            return ['success' => false, 'message' => 'Invalid credentials'];
        }

        // Set session
        $_SESSION['user_id'] = $result['id'];
        $_SESSION['name'] = $result['name'];
        $_SESSION['phone_number'] = $result['phone_number'];
        $_SESSION['staff_id'] = $result['staff_id'];
        $_SESSION['staff_role'] = $result['role'];
        $_SESSION['user_type'] = 'staff';
        $_SESSION['login_time'] = time();

        return ['success' => true, 'message' => 'Login successful', 'user_type' => 'staff', 'role' => $result['role']];
    }

    /**
     * Login customer via phone + card number
     */
    public function loginCustomer($phone_number, $card_number) {
        if (empty($phone_number) || empty($card_number)) {
            return ['success' => false, 'message' => 'Phone number and Card Number are required'];
        }

        if ($this->is_pdo) {
            $query = $this->conn->prepare("SELECT id, name, phone_number, card_id, password_hash FROM customers WHERE phone_number = ? AND is_admin = 0");
            $query->execute([$phone_number]);
            $result = $query->fetch(PDO::FETCH_ASSOC);
        } else {
            $query = $this->conn->prepare("SELECT id, name, phone_number, card_id, password_hash FROM customers WHERE phone_number = ? AND is_admin = 0");
            $query->bind_param("s", $phone_number);
            $query->execute();
            $result = $query->get_result();
            $result = $result->fetch_assoc();
        }

        if (!$result) {
            return ['success' => false, 'message' => 'Invalid credentials'];
        }

        if (!password_verify($card_number, $result['password_hash'])) {
            return ['success' => false, 'message' => 'Invalid credentials'];
        }

        // Set session
        $_SESSION['user_id'] = $result['id'];
        $_SESSION['name'] = $result['name'];
        $_SESSION['phone_number'] = $result['phone_number'];
        $_SESSION['card_id'] = $result['card_id'];
        $_SESSION['user_type'] = 'customer';
        $_SESSION['is_admin'] = false;
        $_SESSION['login_time'] = time();

        return ['success' => true, 'message' => 'Login successful', 'user_type' => 'customer'];
    }

    /**
     * Logout user
     */
    public function logout() {
        session_destroy();
        return ['success' => true, 'message' => 'Logged out successfully'];
    }

    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }

    /**
     * Check if user is admin
     */
    public static function isAdmin() {
        return isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
    }

    /**
     * Get current user ID
     */
    public static function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get current user data
     */
    public static function getUserData() {
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'email' => $_SESSION['email'] ?? null,
            'name' => $_SESSION['name'] ?? null,
            'is_admin' => $_SESSION['is_admin'] ?? false
        ];
    }
}
?>
