<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/UsersRepository.php';
require_once __DIR__ . '/../repositories/LoginAttemptsRepository.php';

class SecurityController extends AppController {

    private UsersRepository $userRepository;
    private LoginAttemptsRepository $loginAttemptsRepository;

    public function __construct(UsersRepository $userRepository = null) {
        parent::__construct();
        $this->userRepository = $userRepository ?: UsersRepository::getInstance();
        $this->loginAttemptsRepository = new LoginAttemptsRepository();
    }

    public function login() {
        if (isset($_SESSION['user_id'])) {
            $url = $this->getBaseUrl();
            if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
                header("Location: {$url}/admin-dashboard");
            } else {
                header("Location: {$url}/dashboard");
            }
            exit();
        }

        if (!$this->isPost()) {
            return $this->render('login', ['csrf_token' => $this->generateCsrfToken()]);
        }

        // Weryfikacja CSRF
        $this->verifyCsrf();

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        // Walidacja długości wejścia
        if (strlen($email) > 255 || strlen($password) > 1024) {
            return $this->render('login', [
                'messages'   => ['Nieprawidłowy email lub hasło.'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        // Walidacja formatu email
        if (empty($email) || empty($password) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->render('login', [
                'messages'   => ['Nieprawidłowy email lub hasło.'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user = $this->userRepository->getUserByEmail($email);

        if ($this->loginAttemptsRepository->isTemporarilyLocked($email, 5, 15)) {
            error_log("Temporary lockout for email: {$email} from IP: {$ip}");
            return $this->render('login', [
                'messages'   => ['Zbyt wiele nieudanych prób logowania. Spróbuj ponownie za 15 minut lub skontaktuj się z administratorem.'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        // Weryfikacja hasła (constant-time, nawet jeśli user nie istnieje)
        $passwordCorrect = false;
        if ($user) {
            $passwordCorrect = password_verify($password, $user->getPassword());
        } else {
            // Dummy verify, zapobiega timing attack
            password_verify($password, '$2y$10$dummyhashfordummyverificationonly');
        }

        if (!$user || !$passwordCorrect) {
            $this->loginAttemptsRepository->recordAttempt($email, $ip, false);
            $failures = $this->loginAttemptsRepository->getConsecutiveFailures($email);

            $messages = ['Nieprawidłowy email lub hasło.'];

            if ($failures >= 2) {
                $messages[] = 'Uwaga: Wykryto wiele nieudanych prób logowania. Po przekroczeniu limitu konto może zostać zablokowane przez administratora.';

                // Powiadom adminów przy dokładnie 2 próbach (i co 5 kolejnych)
                if ($user && ($failures == 2 || $failures % 5 === 0)) {
                    $this->loginAttemptsRepository->notifyAdmins($email, $failures);
                }
            }

            error_log("Failed login attempt for email: {$email} from IP: {$ip} (consecutive: {$failures})");

            return $this->render('login', [
                'messages'   => $messages,
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        // Konto zablokowane (sprawdzamy dopiero po weryfikacji hasła)
        if ($user->getIsBlocked()) {
            $this->loginAttemptsRepository->recordAttempt($email, $ip, false);
            error_log("Blocked account login attempt for email: {$email} from IP: {$ip}");
            return $this->render('login', [
                'messages'   => ['Twoje konto zostało zablokowane. Skontaktuj się z administratorem.'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        // Logowanie udane
        $this->loginAttemptsRepository->recordAttempt($email, $ip, true);

        if (isset($_POST['remember'])) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            setcookie('remember_email', $user->getEmail(), [
                'expires'  => time() + (86400 * 30),
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie('remember_email', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        session_regenerate_id(true);
        unset($_SESSION['csrf_token']); // Nowy token po nowej sesji

        $_SESSION['user_id']      = $user->getId();
        $_SESSION['user_email']   = $user->getEmail();
        $_SESSION['user_name']    = $user->getUsername();
        $_SESSION['user_role']    = $user->getRole();
        $_SESSION['is_logged_in'] = true;

        $url = $this->getBaseUrl();
        if ($user->getRole() === 'admin') {
            header("Location: {$url}/admin-dashboard");
        } elseif ($user->getRole() === 'doctor') {
            header("Location: {$url}/doctor-dashboard");
        } else {
            header("Location: {$url}/dashboard");
        }
        exit();
    }

    public function logout() {
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Lax',
            ]);
        }

        session_destroy();

        $url = $this->getBaseUrl();
        header("Location: {$url}/login");
        exit();
    }

    public function register() {
        if (isset($_SESSION['user_id'])) {
            $url = $this->getBaseUrl();
            header("Location: {$url}/dashboard");
            exit();
        }

        if (!$this->isPost()) {
            return $this->render('register', ['csrf_token' => $this->generateCsrfToken()]);
        }

        // Weryfikacja CSRF
        $this->verifyCsrf();

        $email             = trim($_POST['email'] ?? '');
        $password          = $_POST['password'] ?? '';
        $confirmedPassword = $_POST['password_confirm'] ?? '';
        $name              = trim($_POST['name'] ?? '');
        $surname           = trim($_POST['surname'] ?? '');
        $pesel             = trim($_POST['pesel'] ?? '');

        // Walidacja długości wejścia
        if (strlen($email) > 255 || strlen($name) > 100 || strlen($surname) > 100 || strlen($password) > 1024) {
            return $this->render('register', [
                'messages'   => ['Dane wejściowe są zbyt długie.'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        // Walidacja formatu email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->render('register', [
                'messages'   => ['Podaj poprawny adres email.'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        // Walidacja złożoności hasła
        $passwordError = $this->validateStrongPassword($password);
        if ($passwordError !== null) {
            return $this->render('register', [
                'messages'   => [$passwordError],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        if ($password !== $confirmedPassword) {
            return $this->render('register', [
                'messages'   => ['Hasła nie są identyczne!'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        // Proaktywne sprawdzenie, czy email istnieje (neutralny komunikat)
        if ($this->userRepository->emailExists($email)) {
            return $this->render('register', [
                'messages'   => ['Jeśli w systemie istnieje konto z tym adresem, wysłaliśmy instrukcje na email.'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        $username = strtolower($name . $surname);

        try {
            $this->userRepository->createUser(
                $email,
                password_hash($password, PASSWORD_BCRYPT),
                $username,
                $name,
                $surname,
                $pesel
            );
            return $this->render('login', [
                'messages'   => ['Konto założone! Możesz się zalogować.'],
                'is_success' => true,
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        } catch (Exception $e) {
            error_log('Registration error: ' . $e->getMessage());
            return $this->render('register', [
                'messages'   => ['Błąd rejestracji. Spróbuj ponownie.'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }
    }
}
