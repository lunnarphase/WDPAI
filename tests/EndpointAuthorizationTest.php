<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/controllers/AppointmentController.php';
require_once __DIR__ . '/../src/controllers/DoctorController.php';

class CapturedJsonResponseException extends RuntimeException
{
    public array $payload;
    public int $statusCode;

    public function __construct(array $payload, int $statusCode)
    {
        parent::__construct('Captured JSON response');
        $this->payload = $payload;
        $this->statusCode = $statusCode;
    }
}

class AppointmentControllerAuthProxy extends AppointmentController
{
    protected function jsonResponse(mixed $payload, int $statusCode = 200): void
    {
        $normalizedPayload = is_array($payload) ? $payload : ['raw' => $payload];
        throw new CapturedJsonResponseException($normalizedPayload, $statusCode);
    }
}

class DoctorControllerAuthProxy extends DoctorController
{
    protected function jsonResponse(mixed $payload, int $statusCode = 200): void
    {
        $normalizedPayload = is_array($payload) ? $payload : ['raw' => $payload];
        throw new CapturedJsonResponseException($normalizedPayload, $statusCode);
    }
}

class EndpointAuthorizationTest extends TestCase
{
    private array $serverBackup;
    private array $getBackup;
    private array $postBackup;
    private array $sessionBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->sessionBackup = $_SESSION ?? [];

        $_SERVER = [
            'HTTP_HOST' => 'localhost',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
        ];
        $_GET = [];
        $_POST = [];

        unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['is_logged_in']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_SESSION = $this->sessionBackup;
    }

    public function testDoctorAvailabilityRequiresAuthentication(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/doctor-availability';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $repository = $this->createMock(AppointmentRepository::class);
        $controller = new AppointmentControllerAuthProxy($repository);

        $this->assertUnauthorized(fn() => $controller->getAvailability());
    }

    public function testApiGetSlotsRequiresAuthentication(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api-get-slots';
        $_GET = [
            'doctor_id' => '1',
            'date' => '2026-06-15',
        ];

        $appointmentRepo = $this->createMock(AppointmentRepository::class);
        $reviewRepo = $this->createMock(ReviewRepository::class);
        $controller = new DoctorControllerAuthProxy($appointmentRepo, $reviewRepo);

        $this->assertUnauthorized(fn() => $controller->apiGetSlots());
    }

    public function testApiGetAvailableDatesRequiresAuthentication(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api-get-available-dates';
        $_GET = [
            'doctor_id' => '1',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-21',
        ];

        $appointmentRepo = $this->createMock(AppointmentRepository::class);
        $reviewRepo = $this->createMock(ReviewRepository::class);
        $controller = new DoctorControllerAuthProxy($appointmentRepo, $reviewRepo);

        $this->assertUnauthorized(fn() => $controller->apiGetAvailableDates());
    }

    private function assertUnauthorized(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected unauthorized JSON response.');
        } catch (CapturedJsonResponseException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertSame('Brak autoryzacji.', $e->payload['error'] ?? null);
        }
    }
}
