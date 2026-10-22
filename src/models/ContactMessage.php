<?php
/**
 * Contact Message Model
 * Extol Global Service
 * Supports both MySQLi and SQLite
 */

class ContactMessage {
    private $conn;
    private $is_pdo;

    public function __construct($database_connection) {
        $this->conn = $database_connection;
        $this->is_pdo = ($database_connection instanceof PDO);
    }

    /**
     * Send a contact message
     */
    public function sendMessage($customer_id, $subject, $message) {
        if (empty($subject) || empty($message)) {
            return ['success' => false, 'message' => 'Subject and message are required'];
        }

        $query = "INSERT INTO contact_messages (customer_id, subject, message)
                  VALUES (?, ?, ?)";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute([$customer_id, $subject, $message]);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iss", $customer_id, $subject, $message);
            $result = $stmt->execute();
        }

        if ($result) {
            return ['success' => true, 'message' => 'Message sent successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to send message'];
        }
    }

    /**
     * Get all messages (admin)
     */
    public function getAllMessages($limit = 15, $offset = 0) {
        $query = "SELECT m.id, m.customer_id, c.name, c.email, m.subject, m.message,
                         m.status, m.created_at
                  FROM contact_messages m
                  JOIN customers c ON m.customer_id = c.id
                  ORDER BY m.created_at DESC LIMIT ? OFFSET ?";

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
     * Get user's messages
     */
    public function getUserMessages($customer_id) {
        $query = "SELECT id, subject, message, status, response, created_at, responded_at
                  FROM contact_messages WHERE customer_id = ?
                  ORDER BY created_at DESC";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$customer_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Get message count
     */
    public function getMessageCount() {
        $query = "SELECT COUNT(*) as count FROM contact_messages WHERE status = 'pending'";
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
     * Respond to message (admin)
     */
    public function respondToMessage($message_id, $response) {
        $query = "UPDATE contact_messages SET status = 'responded', response = ?, responded_at = datetime('now')
                  WHERE id = ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            return $stmt->execute([$response, $message_id]);
        } else {
            $stmt = $this->conn->prepare("UPDATE contact_messages SET status = 'responded', response = ?, responded_at = NOW()
                  WHERE id = ?");
            $stmt->bind_param("si", $response, $message_id);
            return $stmt->execute();
        }
    }
}
?>
