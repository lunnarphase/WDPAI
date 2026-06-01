<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/repositories/Repository.php';

class FakeDatabase extends Database {
    public function connect() {
        return new PDO('sqlite::memory:');
    }
}

class RepositoryTransactionProxy extends Repository
{
    public function beginWithIsolationPublic(PDO $db, string $isolationLevel): void
    {
        $this->beginTransactionWithIsolation($db, $isolationLevel);
    }
}

class RepositoryTest extends TestCase
{
    public function testRepositoryInstantiationWorks()
    {
        $mockDb = $this->createMock(Database::class);
        $repo = new Repository($mockDb);

        $this->assertInstanceOf(Repository::class, $repo);
    }

    public function testBeginTransactionWithIsolationFallsBackOnSqlite(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $repo = new RepositoryTransactionProxy($this->createMock(Database::class));
        $repo->beginWithIsolationPublic($db, 'SERIALIZABLE');

        $this->assertTrue($db->inTransaction());
        $db->rollBack();
    }
}