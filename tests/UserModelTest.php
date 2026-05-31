<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/models/User.php';

class UserModelTest extends TestCase
{
    public function testConstructorAndGettersExposeAssignedValues(): void
    {
        $user = new User(
            'tester@example.com',
            'hashed-password',
            'tester_name',
            'doctor',
            42,
            true
        );

        $this->assertSame(42, $user->getId());
        $this->assertSame('tester@example.com', $user->getEmail());
        $this->assertSame('hashed-password', $user->getPassword());
        $this->assertSame('tester_name', $user->getUsername());
        $this->assertSame('doctor', $user->getRole());
        $this->assertTrue($user->getIsBlocked());
    }

    public function testDefaultsForOptionalArguments(): void
    {
        $user = new User('patient@example.com', 'hash', 'patient_name');

        $this->assertNull($user->getId());
        $this->assertSame('patient', $user->getRole());
        $this->assertFalse($user->getIsBlocked());
    }
}
