<?php

require_once __DIR__ . '/../../Database.php';

class Repository {
    protected $database;

    public function __construct(Database $database = null) {
        $this->database = $database ?: new Database();
    }

    protected function beginTransactionWithIsolation(PDO $db, string $isolationLevel = 'READ COMMITTED'): void
    {
        if ($db->inTransaction()) {
            return;
        }

        $allowedLevels = ['READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'];
        $normalizedLevel = strtoupper(trim($isolationLevel));
        if (!in_array($normalizedLevel, $allowedLevels, true)) {
            $normalizedLevel = 'READ COMMITTED';
        }

        $driverName = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driverName === 'pgsql') {
            // For PostgreSQL, set isolation level for the current transaction explicitly.
            $db->exec('BEGIN');
            $db->exec('SET TRANSACTION ISOLATION LEVEL ' . $normalizedLevel);
            return;
        }

        if ($driverName === 'mysql') {
            // For MySQL, isolation level must be set before starting a transaction.
            $db->exec('SET TRANSACTION ISOLATION LEVEL ' . $normalizedLevel);
            $db->beginTransaction();
            return;
        }

        // SQLite and other drivers fallback to default transaction semantics.
        $db->beginTransaction();
    }
}