<?php
/**
 * Staff Model
 * Extol Global Service
 * Supports both MySQLi and SQLite
 */

class Staff {
    private $conn;
    private $is_pdo;

    public function __construct($database_connection) {
        $this->conn = $database_connection;
        $this->is_pdo = ($database_connection instanceof PDO);
    }

    /**
     * Create new staff member (admin only)
     */
    public function createStaff($name, $phone_number, $staff_id, $role, $address = '', $gender = '', $date_of_birth = '', $notes = '') {
        // Validate input
        if (empty($name) || empty($phone_number) || empty($staff_id) || empty($role)) {
            return ['success' => false, 'message' => 'Required fields missing'];
        }

        if ($role !== 'loans') {
            return ['success' => false, 'message' => 'Invalid role'];
        }

        // Use Staff ID as password
        $password_hash = password_hash($staff_id, PASSWORD_BCRYPT);

        $query = "INSERT INTO staff (name, phone_number, staff_id, password_hash, role, address, gender, date_of_birth, notes, is_active)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";

        try {
            if ($this->is_pdo) {
                $stmt = $this->conn->prepare($query);
                $result = $stmt->execute([$name, $phone_number, $staff_id, $password_hash, $role, $address, $gender, $date_of_birth, $notes]);
            } else {
                $stmt = $this->conn->prepare($query);
                $is_active = 1;
                $stmt->bind_param("ssssssss", $name, $phone_number, $staff_id, $password_hash, $role, $address, $gender, $date_of_birth);
                $result = $stmt->execute();
            }

            if ($result) {
                return ['success' => true, 'message' => 'Staff member created successfully', 'staff_id' => $staff_id];
            } else {
                return ['success' => false, 'message' => 'Failed to create staff member'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Get all staff members
     */
    public function getAllStaff($limit = 15, $offset = 0) {
        $query = "SELECT id, name, phone_number, staff_id, role, address, is_active, created_at
                  FROM staff
                  ORDER BY created_at DESC LIMIT ? OFFSET ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $limit, $offset);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Search staff by name / phone / staff ID with real SQL pagination (1000-user safe).
     */
    public function searchStaff($search_term, $limit = 15, $offset = 0) {
        $like = '%' . trim($search_term) . '%';
        $query = "SELECT id, name, phone_number, staff_id, role, address, is_active, created_at
                  FROM staff
                  WHERE name LIKE ? OR phone_number LIKE ? OR staff_id LIKE ?
                  ORDER BY created_at DESC LIMIT ? OFFSET ?";
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(1, $like);
            $stmt->bindValue(2, $like);
            $stmt->bindValue(3, $like);
            $stmt->bindValue(4, (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(5, (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("sssii", $like, $like, $like, $limit, $offset);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Count staff matching a search term (for pagination).
     */
    public function getSearchStaffCount($search_term) {
        $like = '%' . trim($search_term) . '%';
        $query = "SELECT COUNT(*) as count FROM staff
                  WHERE name LIKE ? OR phone_number LIKE ? OR staff_id LIKE ?";
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$like, $like, $like]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("sss", $like, $like, $like);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
        }
        return (int)($row['count'] ?? 0);
    }

    /**
     * Get staff by ID
     */
    public function getStaffById($staff_id) {
        $query = "SELECT id, name, phone_number, staff_id, role, address, gender, date_of_birth, is_active, created_at
                  FROM staff WHERE id = ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$staff_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $staff_id);
            $stmt->execute();
            return $stmt->get_result()->fetch_assoc();
        }
    }

    /**
     * Get staff activity (all transactions recorded by staff)
     */
    public function getStaffActivity($staff_id, $limit = 50, $offset = 0, $date_from = null, $date_to = null) {
        $query = "SELECT t.id, t.customer_id, c.name as customer_name, t.type, t.amount, t.reference_code, t.description, t.created_at
                  FROM transactions t
                  JOIN customers c ON t.customer_id = c.id
                  WHERE t.staff_id = ?";

        if ($date_from && $date_to) {
            $query .= " AND DATE(t.created_at) BETWEEN ? AND ?";
        }

        $query .= " ORDER BY t.created_at DESC LIMIT ? OFFSET ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            if ($date_from && $date_to) {
                $stmt->execute([$staff_id, $date_from, $date_to, $limit, $offset]);
            } else {
                $stmt->execute([$staff_id, $limit, $offset]);
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            if ($date_from && $date_to) {
                $stmt->bind_param("issii", $staff_id, $date_from, $date_to, $limit, $offset);
            } else {
                $stmt->bind_param("iii", $staff_id, $limit, $offset);
            }
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Get staff activity count
     */
    public function getStaffActivityCount($staff_id) {
        $query = "SELECT COUNT(*) as count FROM transactions WHERE staff_id = ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$staff_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $staff_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
        }
        return $result['count'];
    }

    /**
     * Get total staff count
     */
    public function getTotalStaffCount() {
        $query = "SELECT COUNT(*) as count FROM staff";
        if ($this->is_pdo) {
            $result = $this->conn->query($query);
            $row = $result->fetch(PDO::FETCH_ASSOC);
        } else {
            $result = $this->conn->query($query);
            $row = $result->fetch_assoc();
        }
        return $row['count'];
    }

    /**
     * Update staff password (admin only)
     */
    public function updatePassword($staff_id, $new_password) {
        if (strlen($new_password) < 8) {
            return ['success' => false, 'message' => 'Password must be at least 8 characters'];
        }

        $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
        $query = "UPDATE staff SET password_hash = ? WHERE id = ?";

        try {
            if ($this->is_pdo) {
                $stmt = $this->conn->prepare($query);
                $result = $stmt->execute([$password_hash, $staff_id]);
            } else {
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("si", $password_hash, $staff_id);
                $result = $stmt->execute();
            }

            if ($result) {
                return ['success' => true, 'message' => 'Password updated successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to update password'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Deactivate staff member
     */
    public function deactivateStaff($staff_id) {
        $query = "UPDATE staff SET is_active = 0 WHERE id = ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            return $stmt->execute([$staff_id]);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $staff_id);
            return $stmt->execute();
        }
    }

    /**
     * Get staff by phone (for login)
     */
    public function getStaffByPhone($phone_number) {
        $query = "SELECT id, name, phone_number, staff_id, password_hash, role, is_active FROM staff WHERE phone_number = ? AND is_active = 1";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$phone_number]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("s", $phone_number);
            $stmt->execute();
            return $stmt->get_result()->fetch_assoc();
        }
    }

    /**
     * Get today's transactions for a specific staff member
     */
    public function getStaffTodayActivity($staff_id) {
        $today = date('Y-m-d');
        $query = "SELECT t.id, t.customer_id, c.name as customer_name, t.type, t.amount,
                         t.reference_code, t.description, t.created_at
                  FROM transactions t
                  JOIN customers c ON t.customer_id = c.id
                  WHERE t.staff_id = ? AND DATE(t.created_at) = ?
                  ORDER BY t.created_at DESC";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$staff_id, $today]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("is", $staff_id, $today);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Get today's totals for a specific staff member
     */
    public function getStaffTodayTotals($staff_id) {
        $today = date('Y-m-d');
        $query = "SELECT
                    SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END) as today_deposits,
                    SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) as today_withdrawals,
                    COUNT(*) as today_count
                  FROM transactions
                  WHERE staff_id = ? AND DATE(created_at) = ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$staff_id, $today]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("is", $staff_id, $today);
            $stmt->execute();
            return $stmt->get_result()->fetch_assoc();
        }
    }

    /**
     * Get all staff today's summary (per-staff breakdown)
     */
    public function getAllStaffTodaySummary() {
        $today = date('Y-m-d');
        $query = "SELECT s.id, s.name, s.staff_id, s.role,
                    SUM(CASE WHEN t.type = 'deposit' THEN t.amount ELSE 0 END) as today_deposits,
                    SUM(CASE WHEN t.type = 'withdrawal' THEN t.amount ELSE 0 END) as today_withdrawals,
                    COUNT(t.id) as today_count
                  FROM staff s
                  LEFT JOIN transactions t ON t.staff_id = s.id AND DATE(t.created_at) = ?
                  WHERE s.is_active = 1
                  GROUP BY s.id, s.name, s.staff_id, s.role
                  ORDER BY today_count DESC";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$today]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("s", $today);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Get all transactions recorded by staff with filters (admin view)
     */
    public function getAllStaffTransactions($limit = 20, $offset = 0, $staff_id = null, $date_from = null, $date_to = null, $type = null) {
        $query = "SELECT t.id, t.customer_id, c.name as customer_name, t.type, t.amount,
                         t.reference_code, t.description, t.created_at,
                         s.name as staff_name, s.staff_id as staff_code
                  FROM transactions t
                  JOIN customers c ON t.customer_id = c.id
                  LEFT JOIN staff s ON t.staff_id = s.id
                  WHERE t.staff_id IS NOT NULL";

        $params = [];
        $types  = '';

        if ($staff_id) {
            $query   .= " AND t.staff_id = ?";
            $params[] = $staff_id;
            $types   .= 'i';
        }
        if ($date_from) {
            $query   .= " AND DATE(t.created_at) >= ?";
            $params[] = $date_from;
            $types   .= 's';
        }
        if ($date_to) {
            $query   .= " AND DATE(t.created_at) <= ?";
            $params[] = $date_to;
            $types   .= 's';
        }
        if ($type && in_array($type, ['deposit', 'withdrawal'])) {
            $query   .= " AND t.type = ?";
            $params[] = $type;
            $types   .= 's';
        }

        $query   .= " ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types   .= 'ii';

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Count all staff transactions with filters
     */
    public function countAllStaffTransactions($staff_id = null, $date_from = null, $date_to = null, $type = null) {
        $query  = "SELECT COUNT(*) as count FROM transactions t WHERE t.staff_id IS NOT NULL";
        $params = [];
        $types  = '';

        if ($staff_id) {
            $query   .= " AND t.staff_id = ?";
            $params[] = $staff_id;
            $types   .= 'i';
        }
        if ($date_from) {
            $query   .= " AND DATE(t.created_at) >= ?";
            $params[] = $date_from;
            $types   .= 's';
        }
        if ($date_to) {
            $query   .= " AND DATE(t.created_at) <= ?";
            $params[] = $date_to;
            $types   .= 's';
        }
        if ($type && in_array($type, ['deposit', 'withdrawal'])) {
            $query   .= " AND t.type = ?";
            $params[] = $type;
            $types   .= 's';
        }

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
        }
        return $row['count'];
    }
}
?>
