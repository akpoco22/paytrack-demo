<?php
/**
 * Customer Model
 * Extol Global Service
 * Supports both MySQLi and SQLite (including legacy SQLite email schemas).
 */

class Customer {
    private $conn;
    private $is_pdo;
    private $columns = null;

    public function __construct($database_connection) {
        $this->conn = $database_connection;
        $this->is_pdo = ($database_connection instanceof PDO);
    }

    private function customerColumns() {
        if ($this->columns === null) {
            if ($this->is_pdo) {
                $this->columns = array_column($this->conn->query('PRAGMA table_info(customers)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            } else {
                $result = $this->conn->query('SHOW COLUMNS FROM customers');
                $this->columns = [];
                while ($row = $result->fetch_assoc()) $this->columns[] = $row['Field'];
            }
        }
        return $this->columns;
    }

    private function hasColumn($name) {
        return in_array($name, $this->customerColumns(), true);
    }

    private function customerFields() {
        $fields = ['id','name'];
        foreach (['email','phone_number','address','card_id','balance'] as $field) {
            if ($this->hasColumn($field)) $fields[] = $field === 'email' ? "NULLIF(email, '') AS email" : $field;
            else $fields[] = "NULL AS {$field}";
        }
        if ($this->hasColumn('created_at')) $fields[] = 'created_at';
        else $fields[] = "'' AS created_at";
        return implode(', ', $fields);
    }

    public function getAllCustomers($limit = 15, $offset = 0) {
        $order_by = $this->hasColumn('created_at') ? 'created_at DESC' : 'id DESC';
        $query = 'SELECT ' . $this->customerFields() . " FROM customers WHERE is_admin = 0 ORDER BY {$order_by} LIMIT ? OFFSET ?";
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query); $stmt->execute([(int)$limit,(int)$offset]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $stmt = $this->conn->prepare($query); $limit=(int)$limit; $offset=(int)$offset; $stmt->bind_param('ii',$limit,$offset); $stmt->execute(); return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getTotalCustomerCount() {
        $query = 'SELECT COUNT(*) AS count FROM customers WHERE is_admin = 0';
        $row = $this->is_pdo ? $this->conn->query($query)->fetch(PDO::FETCH_ASSOC) : $this->conn->query($query)->fetch_assoc();
        return (int)($row['count'] ?? 0);
    }

    public function getCustomerById($customer_id) {
        $query = 'SELECT ' . $this->customerFields() . ' FROM customers WHERE id = ? AND is_admin = 0';
        if ($this->is_pdo) { $stmt=$this->conn->prepare($query); $stmt->execute([(int)$customer_id]); return $stmt->fetch(PDO::FETCH_ASSOC); }
        $stmt=$this->conn->prepare($query); $customer_id=(int)$customer_id; $stmt->bind_param('i',$customer_id); $stmt->execute(); return $stmt->get_result()->fetch_assoc();
    }

    public function getUserInfo($user_id) {
        $query = 'SELECT ' . $this->customerFields() . ' FROM customers WHERE id = ?';
        if ($this->is_pdo) { $stmt=$this->conn->prepare($query); $stmt->execute([(int)$user_id]); return $stmt->fetch(PDO::FETCH_ASSOC); }
        $stmt=$this->conn->prepare($query); $user_id=(int)$user_id; $stmt->bind_param('i',$user_id); $stmt->execute(); return $stmt->get_result()->fetch_assoc();
    }

    public function getTotalSystemBalance() {
        if (!$this->hasColumn('balance')) return 0;
        $row = $this->conn->query('SELECT COALESCE(SUM(balance),0) AS total FROM customers WHERE is_admin=0');
        $row = $this->is_pdo ? $row->fetch(PDO::FETCH_ASSOC) : $row->fetch_assoc();
        return (float)($row['total'] ?? 0);
    }

    public function updateBalance($customer_id, $amount) {
        if (!$this->hasColumn('balance')) return false;
        $query = 'UPDATE customers SET balance = balance + ? WHERE id = ?';
        if ($this->is_pdo) { $stmt=$this->conn->prepare($query); return $stmt->execute([$amount,(int)$customer_id]); }
        $stmt=$this->conn->prepare($query); $customer_id=(int)$customer_id; $stmt->bind_param('di',$amount,$customer_id); return $stmt->execute();
    }

    public function searchCustomersByName($search_term, $limit = 15, $offset = 0) {
        $search_term = '%' . $search_term . '%';
        $order_by = $this->hasColumn('created_at') ? 'created_at DESC' : 'id DESC';
        $query = 'SELECT ' . $this->customerFields() . " FROM customers WHERE is_admin=0 AND name LIKE ? ORDER BY {$order_by} LIMIT ? OFFSET ?";
        if ($this->is_pdo) { $stmt=$this->conn->prepare($query); $stmt->execute([$search_term,(int)$limit,(int)$offset]); return $stmt->fetchAll(PDO::FETCH_ASSOC); }
        $stmt=$this->conn->prepare($query); $limit=(int)$limit; $offset=(int)$offset; $stmt->bind_param('sii',$search_term,$limit,$offset); $stmt->execute(); return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getSearchCustomerCount($search_term) {
        $stmt = $this->conn->prepare('SELECT COUNT(*) AS count FROM customers WHERE is_admin=0 AND name LIKE ?');
        $like = '%' . $search_term . '%';
        if ($this->is_pdo) { $stmt->execute([$like]); $row=$stmt->fetch(PDO::FETCH_ASSOC); }
        else { $stmt->bind_param('s',$like); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); }
        return (int)($row['count'] ?? 0);
    }

    /** Create a customer; older local SQLite schemas may require a unique non-null email. */
    public function createCustomer($name, $email, $card_id = '', $phone_number = '', $address = '', $password = null) {
        if (empty($password)) $password = $card_id;
        if ($this->hasColumn('email') && ($email === null || trim((string)$email) === '')) {
            $email = 'no-email+' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$card_id) . '@invalid.local';
        }
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $fields = ['name','email','phone_number','address','password_hash','card_id','is_admin'];
        $values = [$name,$email,$phone_number,$address,$password_hash,$card_id,0];
        if ($this->hasColumn('balance')) { $fields[]='balance'; $values[]=0.00; }
        $fields = array_values(array_filter($fields, fn($f) => $this->hasColumn($f)));
        $values = array_values(array_filter($values, fn($v, $i) => $this->hasColumn(['name','email','phone_number','address','password_hash','card_id','is_admin','balance'][$i]), ARRAY_FILTER_USE_BOTH));
        $query = 'INSERT INTO customers (' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0,count($fields),'?')) . ')';
        try {
            if ($this->is_pdo) { $stmt=$this->conn->prepare($query); $stmt->execute($values); }
            else {
                $types = str_repeat('s',count($values)); $stmt=$this->conn->prepare($query); $stmt->bind_param($types,...$values); $stmt->execute();
            }
            return ['success'=>true,'message'=>'Customer created successfully','password'=>$password,'card_id'=>$card_id];
        } catch (Throwable $e) {
            $message = stripos($e->getMessage(),'UNIQUE constraint failed: customers.email') !== false
                ? 'That email address is already in use. Enter a different email or leave it blank.'
                : 'Could not create customer. Check that the card ID and phone number are unique.';
            return ['success'=>false,'message'=>$message];
        }
    }

    public function getCustomerOptions($search = '', $limit = 20) {
        $limit = max(1,min(50,(int)$limit));
        $balance_field = $this->hasColumn('balance') ? ', balance' : ', 0 AS balance';
        if (trim($search) !== '') {
            $like='%'.trim($search).'%';
            $query="SELECT id,name,phone_number,card_id{$balance_field} FROM customers WHERE is_admin=0 AND (name LIKE ? OR phone_number LIKE ? OR card_id LIKE ?) ORDER BY name ASC LIMIT ?";
            if ($this->is_pdo) { $stmt=$this->conn->prepare($query); $stmt->bindValue(1,$like); $stmt->bindValue(2,$like); $stmt->bindValue(3,$like); $stmt->bindValue(4,$limit,PDO::PARAM_INT); $stmt->execute(); return $stmt->fetchAll(PDO::FETCH_ASSOC); }
            $stmt=$this->conn->prepare($query); $stmt->bind_param('sssi',$like,$like,$like,$limit); $stmt->execute(); return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $order_by = $this->hasColumn('created_at') ? 'created_at DESC' : 'id DESC';
        $query="SELECT id,name,phone_number,card_id{$balance_field} FROM customers WHERE is_admin=0 ORDER BY {$order_by} LIMIT ?";
        if ($this->is_pdo) { $stmt=$this->conn->prepare($query); $stmt->bindValue(1,$limit,PDO::PARAM_INT); $stmt->execute(); return $stmt->fetchAll(PDO::FETCH_ASSOC); }
        $stmt=$this->conn->prepare($query); $stmt->bind_param('i',$limit); $stmt->execute(); return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
?>
