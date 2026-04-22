<?php
require_once __DIR__ . '/config.php';

class Database {
    private $username;
    private $password;
    private $host;
    private $database;
    private $connection; // Tutaj będziemy trzymać aktywne połączenie

    public function __construct() {
        $this->username = USERNAME;
        $this->password = PASSWORD;
        $this->host = HOST;
        $this->database = DATABASE;
    }

    public function connect() {
        if ($this->connection) {
            return $this->connection;
        }

        try {
            $this->connection = new PDO(
                "pgsql:host=$this->host;port=5432;dbname=$this->database",
                $this->username,
                $this->password,
                ["sslmode" => "prefer"]
            );
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $this->connection;
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }
}