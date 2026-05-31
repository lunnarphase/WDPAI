<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/controllers/AppController.php';

class AppControllerTestProxy extends AppController
{
    public bool $forbiddenTriggered = false;

    public function validatePasswordPublic(string $password): ?string
    {
        return $this->validateStrongPassword($password);
    }

    public function isJsonRequestPublic(): bool
    {
        return $this->isJsonRequest();
    }

    public function getBaseUrlPublic(): string
    {
        return $this->getBaseUrl();
    }

    public function isGetPublic(): bool
    {
        return $this->isGet();
    }

    public function isPostPublic(): bool
    {
        return $this->isPost();
    }

    public function isHttpsRequestPublic(): bool
    {
        return $this->isHttpsRequest();
    }

    public function generateCsrfTokenPublic(): string
    {
        return $this->generateCsrfToken();
    }

    public function verifyCsrfPublic(): void
    {
        $this->verifyCsrf();
    }

    protected function forbidden()
    {
        $this->forbiddenTriggered = true;
        throw new RuntimeException('forbidden');
    }
}

class AppControllerTest extends TestCase
{
    private AppControllerTestProxy $controller;
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;

        $_SERVER = [
            'HTTP_HOST' => 'localhost',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/dashboard',
        ];

        $this->controller = new AppControllerTestProxy();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function testValidateStrongPasswordAcceptsValidPassword(): void
    {
        $this->assertNull($this->controller->validatePasswordPublic('ValidPass1!'));
    }

    #[DataProvider('invalidPasswordProvider')]
    public function testValidateStrongPasswordRejectsWeakPassword(string $password): void
    {
        $this->assertNotNull($this->controller->validatePasswordPublic($password));
    }

    public static function invalidPasswordProvider(): array
    {
        return [
            'too_short' => ['Ab1!abcd'],
            'missing_lowercase' => ['ABCD1234!'],
            'missing_uppercase' => ['abcd1234!'],
            'missing_digit' => ['AbcdEfgh!'],
            'missing_special' => ['Abcd12345'],
        ];
    }

    public function testIsJsonRequestDetectedByAcceptHeader(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $this->assertTrue($this->controller->isJsonRequestPublic());
    }

    public function testIsJsonRequestDetectedByApiPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/api-get-notifications';

        $this->assertTrue($this->controller->isJsonRequestPublic());
    }

    public function testIsJsonRequestReturnsFalseForRegularRequest(): void
    {
        $_SERVER['REQUEST_URI'] = '/dashboard';
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_X_REQUESTED_WITH']);

        $this->assertFalse($this->controller->isJsonRequestPublic());
    }

    public function testGetBaseUrlUsesForwardedHttps(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS']);

        $this->assertSame('https://example.test', $this->controller->getBaseUrlPublic());
    }

    public function testGetBaseUrlFallsBackToHttp(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.test';
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        $this->assertSame('http://example.test', $this->controller->getBaseUrlPublic());
    }

    public function testIsGetAndIsPostBasedOnRequestMethod(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertTrue($this->controller->isGetPublic());
        $this->assertFalse($this->controller->isPostPublic());

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertFalse($this->controller->isGetPublic());
        $this->assertTrue($this->controller->isPostPublic());
    }

    public function testIsHttpsRequestDetectsHttpsAndForwardedProto(): void
    {
        $_SERVER['HTTPS'] = 'on';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
        $this->assertTrue($this->controller->isHttpsRequestPublic());

        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertTrue($this->controller->isHttpsRequestPublic());

        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $this->assertFalse($this->controller->isHttpsRequestPublic());
    }

    public function testIsJsonRequestDetectedByContentTypeAndXRequestedWith(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $this->assertTrue($this->controller->isJsonRequestPublic());

        unset($_SERVER['CONTENT_TYPE']);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $this->assertTrue($this->controller->isJsonRequestPublic());
    }

    public function testGenerateCsrfTokenPersistsWithinSession(): void
    {
        unset($_SESSION['csrf_token']);

        $first = $this->controller->generateCsrfTokenPublic();
        $second = $this->controller->generateCsrfTokenPublic();

        $this->assertSame($first, $second);
        $this->assertSame($first, $_SESSION['csrf_token']);
        $this->assertSame(64, strlen($first));
    }

    public function testVerifyCsrfAcceptsValidToken(): void
    {
        $_SESSION['csrf_token'] = 'known_token';
        $_POST['csrf_token'] = 'known_token';

        $this->controller->verifyCsrfPublic();

        $this->assertFalse($this->controller->forbiddenTriggered);
    }

    public function testVerifyCsrfRejectsInvalidToken(): void
    {
        $_SESSION['csrf_token'] = 'expected';
        $_POST['csrf_token'] = 'wrong';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forbidden');

        $this->controller->verifyCsrfPublic();
    }
}