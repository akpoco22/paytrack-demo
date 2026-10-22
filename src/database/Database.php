<?php
/**
 * Database Abstraction Layer
 * Supports both MySQLi and SQLite/PDO
 */

class Database {
    private $conn;
    private $is_sqlite = false;

    public function __construct($connection) {
        $this->conn = $connection;
        $this->is_sqlite = ($connection instanceof PDO);
    }

    public function prepare($sql) {
        if ($this->is_sqlite) {
            return $this->conn->prepare($sql);
        } else {
            return $this->conn->prepare($sql);
        }
    }

    public function query($sql) {
        if ($this->is_sqlite) {
            return $this->conn->query($sql);
        } else {
            return $this->conn->query($sql);
        }
    }

    public function lastInsertId() {
        if ($this->is_sqlite) {
            return $this->conn->lastInsertId();
        } else {
            return $this->conn->insert_id;
        }
    }

    public function fetchAssoc($result) {
        if ($this->is_sqlite) {
            return $result->fetch(PDO::FETCH_ASSOC);
        } else {
            return $result->fetch_assoc();
        }
    }

    public function fetchAll($result) {
        if ($this->is_sqlite) {
            return $result->fetchAll(PDO::FETCH_ASSOC);
        } else {
            return $result->fetch_all(MYSQLI_ASSOC);
        }
    }

    public function numRows($result) {
        if ($this->is_sqlite) {
            return $result->rowCount();
        } else {
            return $result->num_rows;
        }
    }

    public function isUsingPDO() {
        return $this->is_sqlite;
    }

    public function getConnection() {
        return $this->conn;
    }
}
?>
