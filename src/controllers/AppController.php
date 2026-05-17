<?php


class AppController {

    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    protected function isGet(): bool
    {
        return $_SERVER["REQUEST_METHOD"] === 'GET';
    }

    protected function isPost(): bool
    {
        return $_SERVER["REQUEST_METHOD"] === 'POST';
    }

    protected function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    protected function verifyCsrf(): void
    {
        $token = $_POST['csrf_token'] ?? '';
        if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            $this->forbidden();
        }
    }
 
    protected function render(string $template = null, array $variables = [])
    {
        $templatePath = 'public/views/'. $template.'.html';
        $templatePath404 = 'public/views/404.html';
        $output = "";
                 
        if(file_exists($templatePath)){
            extract($variables);

            ob_start();
            include $templatePath;
            $output = ob_get_clean();
        } else {
            http_response_code(404);
            ob_start();
            include $templatePath404;
            $output = ob_get_clean();
        }
        echo $output;
    }

    protected function requireLogin()
    {
        if (empty($_SESSION['user_id'])) {
            $url = $this->getBaseUrl();
            header("Location: {$url}/login");
            exit();
        }
    }

    protected function getBaseUrl(): string
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $isHttps ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'];
    }

    protected function validateStrongPassword(string $password): ?string
    {
        if (strlen($password) < 9) {
            return 'Hasło musi mieć co najmniej 9 znaków.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            return 'Hasło musi zawierać co najmniej jedną małą literę.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return 'Hasło musi zawierać co najmniej jedną wielką literę.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            return 'Hasło musi zawierać co najmniej jedną cyfrę.';
        }

        if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            return 'Hasło musi zawierać co najmniej jeden znak specjalny.';
        }

        return null;
    }
    
    protected function forbidden()
    {
        http_response_code(403);
        include 'public/views/403.html';
        exit();
    }
    
    protected function badRequest()
    {
        http_response_code(400);
        include 'public/views/400.html';
        exit();
    }
}
