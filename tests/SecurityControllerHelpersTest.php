<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/controllers/SecurityController.php';

class SecurityControllerHelpersTest extends TestCase
{
    private SecurityController $controller;
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $_SERVER = [
            'HTTP_HOST' => 'localhost',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/login',
        ];

        $usersRepository = $this->createMock(UsersRepository::class);
        $this->controller = new SecurityController($usersRepository);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function testGetClientIpAddressUsesFirstForwardedIpOnlyWhenProxyIsTrusted(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '172.18.0.2';

        $this->assertSame('203.0.113.9', $this->invoke('getClientIpAddress'));
    }

    public function testGetClientIpAddressIgnoresForwardedIpForUntrustedRemoteAddress(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        $this->assertSame('198.51.100.7', $this->invoke('getClientIpAddress'));
    }

    public function testGetClientIpAddressFallsBackToRemoteAddr(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        $this->assertSame('198.51.100.7', $this->invoke('getClientIpAddress'));
    }

    public function testGetClientIpAddressReturnsUnknownForInvalidInput(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'still-not-valid';
        $_SERVER['REMOTE_ADDR'] = 'invalid';

        $this->assertSame('unknown', $this->invoke('getClientIpAddress'));
    }

    public function testFormatRemainingLockTimeHandlesNegativeValue(): void
    {
        $this->assertSame('do odwolania', $this->invoke('formatRemainingLockTime', -1));
    }

    public function testFormatRemainingLockTimeFormatsMinutesAndSeconds(): void
    {
        $this->assertSame('1m 05s', $this->invoke('formatRemainingLockTime', 65));
    }

    public function testFormatRemainingLockTimeFormatsHoursMinutesAndSeconds(): void
    {
        $this->assertSame('1h 01m 05s', $this->invoke('formatRemainingLockTime', 3665));
    }

    private function invoke(string $methodName, mixed ...$args): mixed
    {
        $method = new ReflectionMethod($this->controller, $methodName);
        $method->setAccessible(true);

        return $method->invoke($this->controller, ...$args);
    }
}
