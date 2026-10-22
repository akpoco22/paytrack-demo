<?php
/**
 * Receipt Model
 * Extol Global Service
 * Handles receipt generation for transactions and loan repayments
 */

class Receipt {
    private $conn;
    private $is_pdo;

    public function __construct($database_connection) {
        $this->conn = $database_connection;
        $this->is_pdo = ($database_connection instanceof PDO);
    }

    /**
     * Create receipt for transaction
     */
    public function createTransactionReceipt($transaction_id, $generated_by = null, $generated_by_admin = null) {
        $generated_by = $generated_by === null ? null : (int)$generated_by;
        $generated_by_admin = $generated_by_admin === null ? null : (int)$generated_by_admin;
        if ((($generated_by === null) === ($generated_by_admin === null)) || ($generated_by !== null && $generated_by <= 0) || ($generated_by_admin !== null && $generated_by_admin <= 0)) {
            return ['success' => false, 'message' => 'A valid staff or admin generator must be specified'];
        }
        // Get transaction details
        $query = "SELECT t.id, t.customer_id, c.name as customer_name, c.phone_number, t.type, t.amount,
                         t.reference_code, t.description, t.created_at, s.name as staff_name
                  FROM transactions t
                  JOIN customers c ON t.customer_id = c.id
                  LEFT JOIN staff s ON t.staff_id = s.id
                  WHERE t.id = ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$transaction_id]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $transaction_id);
            $stmt->execute();
            $transaction = $stmt->get_result()->fetch_assoc();
        }

        if (!$transaction) {
            return ['success' => false, 'message' => 'Transaction not found'];
        }

        $receipt_number = 'RCP-' . strtoupper(uniqid());

        try {
            if ($this->is_pdo) {
                $insert_query = "INSERT INTO receipts (transaction_id, receipt_number, amount, receipt_type, generated_by, generated_by_admin, generated_at)
                                VALUES (?, ?, ?, 'transaction', ?, ?, datetime('now'))";
            } else {
                $insert_query = "INSERT INTO receipts (transaction_id, receipt_number, amount, receipt_type, generated_by, generated_by_admin, generated_at)
                                VALUES (?, ?, ?, 'transaction', ?, ?, NOW())";
            }

            if ($this->is_pdo) {
                $insert_stmt = $this->conn->prepare($insert_query);
                $result = $insert_stmt->execute([$transaction_id, $receipt_number, $transaction['amount'], $generated_by, $generated_by_admin]);
            } else {
                $insert_stmt = $this->conn->prepare($insert_query);
                $insert_stmt->bind_param("isdii", $transaction_id, $receipt_number, $transaction['amount'], $generated_by, $generated_by_admin);
                $result = $insert_stmt->execute();
            }

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Receipt created',
                    'receipt_number' => $receipt_number,
                    'transaction' => $transaction
                ];
            } else {
                return ['success' => false, 'message' => 'Failed to create receipt'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Create receipt for loan repayment
     */
    public function createRepaymentReceipt($repayment_id, $generated_by = null, $generated_by_admin = null) {
        $generated_by = $generated_by === null ? null : (int)$generated_by;
        $generated_by_admin = $generated_by_admin === null ? null : (int)$generated_by_admin;
        if ((($generated_by === null) === ($generated_by_admin === null)) || ($generated_by !== null && $generated_by <= 0) || ($generated_by_admin !== null && $generated_by_admin <= 0)) {
            return ['success' => false, 'message' => 'A valid staff or admin generator must be specified'];
        }
        $query = "SELECT lr.id, lr.loan_id, lr.amount, lr.interest_paid, lr.principal_paid, lr.reference_code,
                         lr.repayment_date, l.customer_id, c.name as customer_name, c.phone_number
                  FROM loan_repayments lr
                  JOIN loans l ON lr.loan_id = l.id
                  JOIN customers c ON l.customer_id = c.id
                  WHERE lr.id = ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$repayment_id]);
            $repayment = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $repayment_id);
            $stmt->execute();
            $repayment = $stmt->get_result()->fetch_assoc();
        }

        if (!$repayment) {
            return ['success' => false, 'message' => 'Repayment not found'];
        }

        $receipt_number = 'RECP-' . strtoupper(uniqid());

        try {
            if ($this->is_pdo) {
                $insert_query = "INSERT INTO receipts (loan_repayment_id, receipt_number, amount, receipt_type, generated_by, generated_by_admin, generated_at)
                                VALUES (?, ?, ?, 'loan_repayment', ?, ?, datetime('now'))";
            } else {
                $insert_query = "INSERT INTO receipts (loan_repayment_id, receipt_number, amount, receipt_type, generated_by, generated_by_admin, generated_at)
                                VALUES (?, ?, ?, 'loan_repayment', ?, ?, NOW())";
            }

            if ($this->is_pdo) {
                $insert_stmt = $this->conn->prepare($insert_query);
                $result = $insert_stmt->execute([$repayment_id, $receipt_number, $repayment['amount'], $generated_by, $generated_by_admin]);
            } else {
                $insert_stmt = $this->conn->prepare($insert_query);
                $insert_stmt->bind_param("isdii", $repayment_id, $receipt_number, $repayment['amount'], $generated_by, $generated_by_admin);
                $result = $insert_stmt->execute();
            }

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Receipt created',
                    'receipt_number' => $receipt_number,
                    'repayment' => $repayment
                ];
            } else {
                return ['success' => false, 'message' => 'Failed to create receipt'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Get receipt by number
     */
    public function getReceiptByNumber($receipt_number) {
        $query = "SELECT r.id, r.receipt_number, r.amount, r.receipt_type, r.generated_by, r.generated_by_admin, r.generated_at,
                         r.transaction_id, r.loan_repayment_id
                  FROM receipts r
                  WHERE r.receipt_number = ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$receipt_number]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("s", $receipt_number);
            $stmt->execute();
            return $stmt->get_result()->fetch_assoc();
        }
    }

    /**
     * Get all receipts for customer
     */
    public function getCustomerReceipts($customer_id, $limit = 20, $offset = 0) {
        $query = "SELECT r.receipt_number, r.amount, r.receipt_type, r.generated_at
                  FROM receipts r
                  LEFT JOIN transactions t ON r.transaction_id = t.id
                  LEFT JOIN loan_repayments lr ON r.loan_repayment_id = lr.id
                  LEFT JOIN loans l ON lr.loan_id = l.id
                  WHERE t.customer_id = ? OR l.customer_id = ?
                  ORDER BY r.generated_at DESC LIMIT ? OFFSET ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$customer_id, $customer_id, $limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iiii", $customer_id, $customer_id, $limit, $offset);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Get receipt by ID (with customer + source refs for printing)
     */
    public function getReceiptById($receipt_id) {
        $query = "SELECT r.id, r.receipt_number, r.amount, r.receipt_type, r.generated_at,
                         r.transaction_id, r.loan_repayment_id,
                         t.reference_code AS txn_ref, t.type AS txn_type,
                         lr.reference_code AS repay_ref,
                         COALESCE(tc.name, lc.name) AS customer_name,
                         COALESCE(tc.phone_number, lc.phone_number) AS phone_number
                  FROM receipts r
                  LEFT JOIN transactions t ON r.transaction_id = t.id
                  LEFT JOIN customers tc ON t.customer_id = tc.id
                  LEFT JOIN loan_repayments lr ON r.loan_repayment_id = lr.id
                  LEFT JOIN loans l ON lr.loan_id = l.id
                  LEFT JOIN customers lc ON l.customer_id = lc.id
                  WHERE r.id = ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$receipt_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $receipt_id);
            $stmt->execute();
            return $stmt->get_result()->fetch_assoc();
        }
    }

    /**
     * Generate HTML for transaction receipt
     */
    public function generateTransactionHTML($transaction_id) {
        $query = "SELECT t.id, t.customer_id, c.name as customer_name, c.phone_number, c.address, t.type, t.amount,
                         t.reference_code, t.description, t.created_at, s.name as staff_name, r.receipt_number
                  FROM transactions t
                  JOIN customers c ON t.customer_id = c.id
                  LEFT JOIN staff s ON t.staff_id = s.id
                  LEFT JOIN receipts r ON r.transaction_id = t.id
                  WHERE t.id = ?
                  LIMIT 1";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$transaction_id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $transaction_id);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_assoc();
        }

        if (!$data) {
            return null;
        }

        $currency = '₦';
        $transaction_type = strtoupper($data['type']);
        $date = date('F d, Y g:i A', strtotime($data['created_at']));

        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd;'>
            <div style='text-align: center; border-bottom: 2px solid #007bff; padding-bottom: 20px;'>
                <h1 style='margin: 0; color: #007bff;'>EXTOL</h1>
                <p style='margin: 5px 0; color: #666;'>Global Service</p>
            </div>

            <div style='margin: 20px 0;'>
                <h2 style='color: #333; border-bottom: 1px solid #ddd; padding-bottom: 10px;'>RECEIPT</h2>

                <table style='width: 100%; margin: 20px 0;'>
                    <tr>
                        <td style='padding: 8px 0;'><strong>Receipt Number:</strong></td>
                        <td style='text-align: right;'>{$data['receipt_number']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0;'><strong>Date:</strong></td>
                        <td style='text-align: right;'>{$date}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0;'><strong>Reference Code:</strong></td>
                        <td style='text-align: right;'>{$data['reference_code']}</td>
                    </tr>
                </table>

                <div style='background: #f8f9fa; padding: 15px; margin: 20px 0; border-radius: 5px;'>
                    <h3 style='margin-top: 0;'>Customer Information</h3>
                    <p style='margin: 5px 0;'><strong>Name:</strong> {$data['customer_name']}</p>
                    <p style='margin: 5px 0;'><strong>Phone:</strong> {$data['phone_number']}</p>
                    <p style='margin: 5px 0;'><strong>Address:</strong> {$data['address']}</p>
                </div>

                <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                    <tr style='background: #007bff; color: white;'>
                        <th style='padding: 10px; text-align: left; border: 1px solid #ddd;'>Description</th>
                        <th style='padding: 10px; text-align: right; border: 1px solid #ddd;'>Amount</th>
                    </tr>
                    <tr>
                        <td style='padding: 10px; border: 1px solid #ddd;'>{$transaction_type} - {$data['description']}</td>
                        <td style='padding: 10px; text-align: right; border: 1px solid #ddd;'>{$currency}" . number_format($data['amount'], 2) . "</td>
                    </tr>
                </table>

                <div style='text-align: right; margin-top: 20px; padding-top: 20px; border-top: 2px solid #ddd;'>
                    <h3 style='margin: 0;'>Total: {$currency}" . number_format($data['amount'], 2) . "</h3>
                </div>

                " . ($data['staff_name'] ? "<p style='margin-top: 20px; color: #666;'><strong>Processed by:</strong> {$data['staff_name']}</p>" : "") . "

                <div style='text-align: center; margin-top: 30px; color: #999; font-size: 12px;'>
                    <p>Thank you for your transaction</p>
                    <p>This is an official receipt from Extol Global Service</p>
                </div>
            </div>
        </div>
        ";

        return $html;
    }

    /**
     * Generate HTML for loan repayment receipt
     */
    public function generateRepaymentHTML($repayment_id) {
        $query = "SELECT lr.id, lr.amount, lr.interest_paid, lr.principal_paid, lr.reference_code, lr.repayment_date,
                         l.loan_amount, l.id as loan_id, c.name as customer_name, c.phone_number, c.address,
                         r.receipt_number
                  FROM loan_repayments lr
                  JOIN loans l ON lr.loan_id = l.id
                  JOIN customers c ON l.customer_id = c.id
                  LEFT JOIN receipts r ON r.loan_repayment_id = lr.id
                  WHERE lr.id = ?
                  LIMIT 1";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$repayment_id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $repayment_id);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_assoc();
        }

        if (!$data) {
            return null;
        }

        $currency = '₦';
        $date = date('F d, Y', strtotime($data['repayment_date']));

        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd;'>
            <div style='text-align: center; border-bottom: 2px solid #28a745; padding-bottom: 20px;'>
                <h1 style='margin: 0; color: #28a745;'>EXTOL</h1>
                <p style='margin: 5px 0; color: #666;'>Global Service</p>
            </div>

            <div style='margin: 20px 0;'>
                <h2 style='color: #333; border-bottom: 1px solid #28a745; padding-bottom: 10px;'>LOAN REPAYMENT RECEIPT</h2>

                <table style='width: 100%; margin: 20px 0;'>
                    <tr>
                        <td style='padding: 8px 0;'><strong>Receipt Number:</strong></td>
                        <td style='text-align: right;'>{$data['receipt_number']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0;'><strong>Repayment Date:</strong></td>
                        <td style='text-align: right;'>{$date}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0;'><strong>Reference Code:</strong></td>
                        <td style='text-align: right;'>{$data['reference_code']}</td>
                    </tr>
                </table>

                <div style='background: #f8f9fa; padding: 15px; margin: 20px 0; border-radius: 5px;'>
                    <h3 style='margin-top: 0;'>Borrower Information</h3>
                    <p style='margin: 5px 0;'><strong>Name:</strong> {$data['customer_name']}</p>
                    <p style='margin: 5px 0;'><strong>Phone:</strong> {$data['phone_number']}</p>
                    <p style='margin: 5px 0;'><strong>Loan ID:</strong> {$data['loan_id']}</p>
                </div>

                <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                    <tr style='background: #28a745; color: white;'>
                        <th style='padding: 10px; text-align: left; border: 1px solid #ddd;'>Description</th>
                        <th style='padding: 10px; text-align: right; border: 1px solid #ddd;'>Amount</th>
                    </tr>
                    <tr>
                        <td style='padding: 10px; border: 1px solid #ddd;'>Principal Repaid</td>
                        <td style='padding: 10px; text-align: right; border: 1px solid #ddd;'>{$currency}" . number_format($data['principal_paid'], 2) . "</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; border: 1px solid #ddd;'>Interest Paid</td>
                        <td style='padding: 10px; text-align: right; border: 1px solid #ddd;'>{$currency}" . number_format($data['interest_paid'], 2) . "</td>
                    </tr>
                    <tr style='background: #e7f3e7; font-weight: bold;'>
                        <td style='padding: 10px; border: 1px solid #ddd;'>Total Repayment</td>
                        <td style='padding: 10px; text-align: right; border: 1px solid #ddd;'>{$currency}" . number_format($data['amount'], 2) . "</td>
                    </tr>
                </table>

                <div style='text-align: center; margin-top: 30px; color: #999; font-size: 12px;'>
                    <p>Thank you for your repayment</p>
                    <p>This is an official receipt from Extol Global Service</p>
                </div>
            </div>
        </div>
        ";

        return $html;
    }
}
?>
