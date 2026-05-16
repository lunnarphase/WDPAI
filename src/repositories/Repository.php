<?php

require_once __DIR__ . '/../../Database.php';

class Repository {
    protected $database;

    public function __construct(Database $database = null) {
        $this->database = $database ?: new Database();
    }
}