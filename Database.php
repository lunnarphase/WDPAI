<?php
require_once __DIR__ . '/config.php';

class Database {
    private $username;
    private $password;
    private $host;
    private $database;
    private $connection;

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
            $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            $xrw = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');

            $isJsonRequest = stripos($accept, 'application/json') !== false
                || stripos($contentType, 'application/json') !== false
                || str_starts_with($requestUri, '/api-')
                || $xrw === 'xmlhttprequest';

            if ($isJsonRequest) {
                header('Content-Type: application/json; charset=UTF-8');
                http_response_code(500);
                echo json_encode(['error' => 'Błąd połączenia z bazą danych.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit();
            }

            http_response_code(500);
            include 'public/views/500.html';
            exit();
        }
    }
}