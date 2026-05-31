<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/controllers/AppointmentController.php';

class AppointmentControllerSanitizeReturnToTest extends TestCase
{
    private AppointmentController $controller;
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $_SERVER = [
            'HTTP_HOST' => 'localhost',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/dashboard',
        ];

        $appointmentRepository = $this->createMock(AppointmentRepository::class);
        $this->controller = new AppointmentController($appointmentRepository);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function testSanitizeReturnToAcceptsWhitelistedPaths(): void
    {
        $this->assertSame('/dashboard', $this->sanitizeReturnTo('/dashboard'));
        $this->assertSame('/find-doctor', $this->sanitizeReturnTo('/find-doctor'));
        $this->assertSame('/my-appointments', $this->sanitizeReturnTo('/my-appointments'));
    }

    public function testSanitizeReturnToKeepsAllowedQueryString(): void
    {
        $safe = $this->sanitizeReturnTo('/find-doctor?specialization=cardio&page=2');

        $this->assertSame('/find-doctor?specialization=cardio&page=2', $safe);
    }

    public function testSanitizeReturnToFallsBackForInvalidTargets(): void
    {
        $this->assertSame('/dashboard', $this->sanitizeReturnTo(null));
        $this->assertSame('/dashboard', $this->sanitizeReturnTo(''));
        $this->assertSame('/dashboard', $this->sanitizeReturnTo('dashboard'));
        $this->assertSame('/dashboard', $this->sanitizeReturnTo('/admin-dashboard'));
        $this->assertSame('/dashboard', $this->sanitizeReturnTo('https://evil.example/dashboard'));
        $this->assertSame('/dashboard', $this->sanitizeReturnTo('/find-doctor/../admin'));
    }

    private function sanitizeReturnTo(?string $value): string
    {
        $method = new ReflectionMethod($this->controller, 'sanitizeReturnTo');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $value);
    }
}
