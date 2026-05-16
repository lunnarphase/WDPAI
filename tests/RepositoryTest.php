<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/repositories/Repository.php';

class FakeDatabase extends Database {
    public function connect() {
        return new PDO('sqlite::memory:');
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
}