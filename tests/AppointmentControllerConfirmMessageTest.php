<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/controllers/AppointmentController.php';

class AppointmentControllerConfirmProxy extends AppointmentController
{
    protected function requireLogin()
    {
    }

    protected function verifyCsrf(): void
    {
    }

    protected function render(string $template = null, array $variables = [])
    {
        return [
            'template' => $template,
            'variables' => $variables,
        ];
    }
}

class AppointmentControllerConfirmMessageTest extends TestCase
{
    private array $serverBackup;
    private array $postBackup;
    private array $sessionBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->postBackup = $_POST;
        $this->sessionBackup = $_SESSION ?? [];

        $_SERVER = [
            'HTTP_HOST' => 'localhost',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/confirm-appointment',
        ];

        $_POST = [
            'doctor_id' => '1',
            'appointment_date' => '2026-06-15',
            'appointment_time' => '10:00',
            'return_to' => '/dashboard',
        ];

        $_SESSION['user_id'] = 1;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_POST = $this->postBackup;
        $_SESSION = $this->sessionBackup;
    }

    public function testConfirmReturnsBusinessMessageForSlotConflict(): void
    {
        $repository = $this->createMock(AppointmentRepository::class);
        $repository
            ->expects($this->once())
            ->method('createAppointment')
            ->willThrowException(new RuntimeException('Przepraszamy, ale ktoś inny właśnie zarezerwował ten termin.'));

        $controller = new AppointmentControllerConfirmProxy($repository);
        $result = $controller->confirm();

        $this->assertIsArray($result);
        $this->assertSame('book_appointment', $result['template'] ?? null);
        $this->assertSame(
            'Przepraszamy, ale ktoś inny właśnie zarezerwował ten termin.',
            $result['variables']['messages'][0] ?? null
        );
    }
}
