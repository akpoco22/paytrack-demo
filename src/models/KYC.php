<?php
/**
 * KYC Model
 * Extol Global Service
 * Supports both MySQLi and SQLite
 */

class KYC {
    private $conn;
    private $is_pdo;

    public function __construct($database_connection) {
        $this->conn = $database_connection;
        $this->is_pdo = ($database_connection instanceof PDO);
    }

    /**
     * Submit KYC record
     */
    public function submitKYC($customer_id, $id_type, $id_number, $id_expiry, $address, $employment_status,
                              $income_source, $bank_account, $bank_name, $reference_contact_name, $reference_contact_phone,
                              $bvn = '', $nin = '', $guarantor_name = '', $guarantor_phone = '', $guarantor_address = '', $collateral = '') {

        // Check if customer already has KYC record
        $check_query = "SELECT id FROM kyc_records WHERE customer_id = ?";

        if ($this->is_pdo) {
            $check_stmt = $this->conn->prepare($check_query);
            $check_stmt->execute([$customer_id]);
            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $check_stmt = $this->conn->prepare($check_query);
            $check_stmt->bind_param("i", $customer_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $existing = $result->fetch_assoc();
        }

        if ($existing) {
            return ['success' => false, 'message' => 'Customer already has a KYC record'];
        }

        $query = "INSERT INTO kyc_records (customer_id, id_type, id_number, id_expiry, address, employment_status,
                                          income_source, bank_account, bank_name, reference_contact_name,
                                          reference_contact_phone, bvn, nin, guarantor_name, guarantor_phone,
                                          guarantor_address, collateral, verification_status)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";

        try {
            if ($this->is_pdo) {
                $stmt = $this->conn->prepare($query);
                $result = $stmt->execute([$customer_id, $id_type, $id_number, $id_expiry, $address, $employment_status,
                                         $income_source, $bank_account, $bank_name, $reference_contact_name, $reference_contact_phone,
                                         $bvn, $nin, $guarantor_name, $guarantor_phone, $guarantor_address, $collateral]);
            } else {
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("isssssssssssssssss", $customer_id, $id_type, $id_number, $id_expiry, $address,
                                 $employment_status, $income_source, $bank_account, $bank_name,
                                 $reference_contact_name, $reference_contact_phone,
                                 $bvn, $nin, $guarantor_name, $guarantor_phone, $guarantor_address, $collateral);
                $result = $stmt->execute();
            }

            if ($result) {
                return ['success' => true, 'message' => 'KYC record submitted for verification'];
            } else {
                return ['success' => false, 'message' => 'Failed to submit KYC record'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Get KYC record for customer
     */
    public function getKYCByCustomer($customer_id) {
        $query = "SELECT * FROM kyc_records WHERE customer_id = ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$customer_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            return $stmt->get_result()->fetch_assoc();
        }
    }

    /**
     * Get all pending KYC records (admin)
     */
    public function getPendingKYC($limit = 15, $offset = 0) {
        $query = "SELECT k.id, k.customer_id, c.name, c.phone_number, c.created_at, k.created_at as kyc_submitted_at
                  FROM kyc_records k
                  JOIN customers c ON k.customer_id = c.id
                  WHERE k.verification_status = 'pending'
                  ORDER BY k.created_at ASC LIMIT ? OFFSET ?";

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
     * Approve KYC record
     */
    public function approveKYC($kyc_id, $verified_by, $notes = '') {
        if ($this->is_pdo) {
            $query = "UPDATE kyc_records SET verification_status = 'approved', verified_by = ?, verified_at = datetime('now'), notes = ?
                  WHERE id = ?";
        } else {
            $query = "UPDATE kyc_records SET verification_status = 'approved', verified_by = ?, verified_at = NOW(), notes = ?
                  WHERE id = ?";
        }

        try {
            if ($this->is_pdo) {
                $stmt = $this->conn->prepare($query);
                $result = $stmt->execute([$verified_by, $notes, $kyc_id]);
            } else {
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("isi", $verified_by, $notes, $kyc_id);
                $result = $stmt->execute();
            }

            if ($result) {
                return ['success' => true, 'message' => 'KYC approved successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to approve KYC'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Reject KYC record
     */
    public function rejectKYC($kyc_id, $verified_by, $rejection_reason = '') {
        if ($this->is_pdo) {
            $query = "UPDATE kyc_records SET verification_status = 'rejected', verified_by = ?, verified_at = datetime('now'), notes = ?
                  WHERE id = ?";
        } else {
            $query = "UPDATE kyc_records SET verification_status = 'rejected', verified_by = ?, verified_at = NOW(), notes = ?
                  WHERE id = ?";
        }

        try {
            if ($this->is_pdo) {
                $stmt = $this->conn->prepare($query);
                $result = $stmt->execute([$verified_by, $rejection_reason, $kyc_id]);
            } else {
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("isi", $verified_by, $rejection_reason, $kyc_id);
                $result = $stmt->execute();
            }

            if ($result) {
                return ['success' => true, 'message' => 'KYC rejected'];
            } else {
                return ['success' => false, 'message' => 'Failed to reject KYC'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Get all KYC records (admin)
     */
    public function getAllKYC($limit = 15, $offset = 0) {
        $query = "SELECT k.id, k.customer_id, c.name, c.phone_number, k.verification_status, k.created_at
                  FROM kyc_records k
                  JOIN customers c ON k.customer_id = c.id
                  ORDER BY k.created_at DESC LIMIT ? OFFSET ?";

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
     * Get KYC approval count
     */
    public function getPendingKYCCount() {
        $query = "SELECT COUNT(*) as count FROM kyc_records WHERE verification_status = 'pending'";

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
     * Get KYC records by status with real SQL pagination (1000-user safe).
     * $status: 'all', 'pending', 'approved', 'rejected'
     */
    public function getKYCByStatus($status = 'all', $limit = 15, $offset = 0) {
        $allowed = ['pending', 'approved', 'rejected'];
        $where = '';
        $params = [];
        if (in_array($status, $allowed, true)) {
            $where = "WHERE k.verification_status = ?";
            $params[] = $status;
        }
        $query = "SELECT k.id, k.customer_id, c.name, c.phone_number, k.verification_status, k.created_at
                  FROM kyc_records k
                  JOIN customers c ON k.customer_id = c.id
                  $where
                  ORDER BY k.created_at DESC LIMIT ? OFFSET ?";
        $params[] = (int)$limit;
        $params[] = (int)$offset;
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $types = ($where ? 's' : '') . 'ii';
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Count KYC records by status (for pagination + tab badges).
     */
    public function getKYCCountByStatus($status = 'all') {
        $allowed = ['pending', 'approved', 'rejected'];
        if (in_array($status, $allowed, true)) {
            $query = "SELECT COUNT(*) as count FROM kyc_records WHERE verification_status = ?";
            if ($this->is_pdo) {
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$status]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("s", $status);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
            }
            return (int)($row['count'] ?? 0);
        }
        $query = "SELECT COUNT(*) as count FROM kyc_records";
        if ($this->is_pdo) {
            $row = $this->conn->query($query)->fetch(PDO::FETCH_ASSOC);
        } else {
            $row = $this->conn->query($query)->fetch_assoc();
        }
        return (int)($row['count'] ?? 0);
    }

    /**
     * Check if customer KYC is approved
     */
    public function isKYCApproved($customer_id) {
        $query = "SELECT id FROM kyc_records WHERE customer_id = ? AND verification_status = 'approved'";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$customer_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $result = $stmt->get_result();
            return $result->num_rows > 0;
        }
    }
}
?>
