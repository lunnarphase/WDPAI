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

    private function getClientIpAddress(): string
    {
        $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

        if (
            !empty($forwarded)
            && filter_var($remoteAddr, FILTER_VALIDATE_IP)
            && $this->isTrustedProxy($remoteAddr)
        ) {
            $parts = array_map('trim', explode(',', $forwarded));
            foreach ($parts as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        if (filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return $remoteAddr;
        }

        return 'unknown';
    }

    private function isTrustedProxy(string $ipAddress): bool
    {
        if ($ipAddress === '127.0.0.1' || $ipAddress === '::1') {
            return true;
        }

        if (!filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        return $this->isIpv4InCidr($ipAddress, '10.0.0.0/8')
            || $this->isIpv4InCidr($ipAddress, '172.16.0.0/12')
            || $this->isIpv4InCidr($ipAddress, '192.168.0.0/16');
    }

    private function isIpv4InCidr(string $ipAddress, string $cidr): bool
    {
        [$subnet, $maskBits] = explode('/', $cidr);
        $maskBits = (int)$maskBits;

        if ($maskBits < 0 || $maskBits > 32) {
            return false;
        }

        $ipLong = ip2long($ipAddress);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $maskBits === 0 ? 0 : (-1 << (32 - $maskBits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private function isLocalDebugResetEnabled(): bool
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $hostWithoutPort = explode(':', $host)[0];
        $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

        $isLocalHost = in_array($hostWithoutPort, ['localhost', '127.0.0.1', '::1'], true);
        $isLocalNetworkSource = $remoteAddr === '127.0.0.1'
            || $remoteAddr === '::1'
            || ($remoteAddr !== '' && $this->isTrustedProxy($remoteAddr));

        return $isLocalHost && $isLocalNetworkSource;
    }

    private function formatRemainingLockTime(int $seconds): string
    {
        if ($seconds < 0) {
            return 'do odwolania';
        }

        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %02dm %02ds', $hours, $minutes, $secs);
        }

        return sprintf('%dm %02ds', $minutes, $secs);
    }

    public function login() {
        $this->requireHttps();

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

        $this->verifyCsrf();

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (strlen($email) > 255 || strlen($password) > 1024) {
            return $this->render('login', [
                'messages'   => ['Nieprawidłowy email lub hasło.'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        if (empty($email) || empty($password) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->render('login', [
                'messages'   => ['Nieprawidłowy email lub hasło.'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        $ip   = $this->getClientIpAddress();
        $user = $this->userRepository->getUserByEmail($email);

        if ($ip !== 'unknown' && $this->loginAttemptsRepository->isIpBlocked($ip)) {
            error_log("Blocked IP login attempt: {$ip} for email: {$email}");

            return $this->render('login', [
                'messages'   => [
                    'Logowanie z tego adresu IP jest tymczasowo zablokowane.',
                    'Jesli to pomylka, skontaktuj sie z administratorem.'
                ],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        if ($this->loginAttemptsRepository->isAccountGloballyLocked($email, 4, 2, 15)) {
            error_log("Account-wide lockout (distributed attack) for email: {$email} from IP: {$ip}");
            return $this->render('login', [
                'messages'   => [
                    'To konto zostalo tymczasowo zablokowane ze wzgledow bezpieczenstwa.',
                    'Jesli to Twoje konto, sprobuj ponownie za 15 minut lub skontaktuj sie z administratorem.',
                ],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        if ($ip !== 'unknown' && $this->loginAttemptsRepository->isTemporarilyLockedForIpAccount($email, $ip, 3, 15)) {
            error_log("Per-IP soft lockout for email: {$email} from IP: {$ip}");
            return $this->render('login', [
                'messages'   => ['Zbyt wiele nieudanych prób logowania. Spróbuj ponownie za 15 minut lub skontaktuj się z administratorem.'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        $passwordCorrect = false;
        if ($user) {
            $passwordCorrect = password_verify($password, $user->getPassword());
        } else {
            password_verify($password, '$2y$10$dummyhashfordummyverificationonly');
        }

        if (!$user || !$passwordCorrect) {
            $this->loginAttemptsRepository->recordAttempt($email, $ip, false);
            $failures = $this->loginAttemptsRepository->getConsecutiveFailures($email);

            $messages = ['Nieprawidłowy email lub hasło.'];

            if ($failures >= 2) {
                $messages[] = 'Uwaga: Wykryto wiele nieudanych prób logowania. Po przekroczeniu limitu konto może zostać zablokowane przez administratora.';

                if ($user && $failures >= 3 && $failures % 3 === 0) {
                    $this->loginAttemptsRepository->notifyAdmins($email, $failures);
                }
            }

            if ($user) {
                $accountAttack = $this->loginAttemptsRepository->getAccountAttackDetails($email, 15);
                $crossedThreshold = $accountAttack['total_failures'] === 4
                    && $accountAttack['distinct_ips'] >= 2;
                if ($crossedThreshold) {
                    $this->loginAttemptsRepository->notifyAdminsAboutAccountAttack(
                        $email,
                        $accountAttack['total_failures'],
                        $accountAttack['distinct_ips'],
                        $accountAttack['window_minutes'],
                        $accountAttack['attacker_ips']
                    );
                    error_log("Distributed account attack on {$email}: {$accountAttack['total_failures']} fails from {$accountAttack['distinct_ips']} IPs");
                }
            }

            if ($ip !== 'unknown' && !$this->loginAttemptsRepository->isIpBlocked($ip)) {
                $attack = $this->loginAttemptsRepository->detectGlobalIpAttack($ip, 6, 2, 15);
                if ($attack['detected']) {
                    $blockApplied = $this->loginAttemptsRepository->blockIpAddress($ip, 60);
                    if ($blockApplied) {
                        $windowMinutes = (int)($attack['window_minutes'] ?? 15);
                        $targetAccounts = $this->loginAttemptsRepository->getRecentFailedTargetsForIp($ip, $windowMinutes, 8);
                        $ipBlockInfo = $this->loginAttemptsRepository->getBlockedIpDetails($ip);

                        $this->loginAttemptsRepository->notifyAdminsAboutIpAttack(
                            $ip,
                            (int)$attack['total_failures'],
                            (int)$attack['distinct_accounts'],
                            $windowMinutes,
                            $targetAccounts,
                            $ipBlockInfo['blocked_until'] ?? null,
                            isset($ipBlockInfo['remaining_seconds']) ? (int)$ipBlockInfo['remaining_seconds'] : null
                        );

                        $messages[] = 'Ze wzgledow bezpieczenstwa logowanie z Twojego adresu IP zostalo zablokowane.';
                        error_log("Global attack detected and blocked for IP: {$ip}");
                    }
                }
            }

            error_log("Failed login attempt for email: {$email} from IP: {$ip} (consecutive: {$failures})");

            return $this->render('login', [
                'messages'   => $messages,
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        if ($user->getIsBlocked()) {
            $this->loginAttemptsRepository->recordAttempt($email, $ip, false);
            error_log("Blocked account login attempt for email: {$email} from IP: {$ip}");
            return $this->render('login', [
                'messages'   => ['Twoje konto zostało zablokowane. Jeśli uważasz, że to błąd, skontaktuj się z naszym wsparciem.'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        $this->loginAttemptsRepository->recordAttempt($email, $ip, true);

        if (isset($_POST['remember'])) {
            setcookie('remember_email', $user->getEmail(), [
                'expires'  => time() + (86400 * 30),
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie('remember_email', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);

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

    public function debugUnblockAll(): void
    {
        if (!$this->isPost()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        if (!$this->isLocalDebugResetEnabled()) {
            // Celowo zwracamy 404, aby nie eksponowac endpointu poza localhost.
            $this->jsonResponse(['error' => 'Nie znaleziono zasobu.'], 404);
        }

        $this->verifyCsrf();

        try {
            $result = $this->loginAttemptsRepository->clearAllLocksForDebug();
            error_log('Debug unlock-all executed from login screen.');
            $this->jsonResponse(['success' => true, 'result' => $result]);
        } catch (Exception $e) {
            error_log('Debug unlock-all failed: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się zdjąć blokad debug.'], 500);
        }
    }

    public function register() {
        $this->requireHttps();

        if (isset($_SESSION['user_id'])) {
            $url = $this->getBaseUrl();
            header("Location: {$url}/dashboard");
            exit();
        }

        if (!$this->isPost()) {
            return $this->render('register', ['csrf_token' => $this->generateCsrfToken()]);
        }

        $this->verifyCsrf();

        $email             = trim($_POST['email'] ?? '');
        $password          = $_POST['password'] ?? '';
        $confirmedPassword = $_POST['password_confirm'] ?? '';
        $name              = trim($_POST['name'] ?? '');
        $surname           = trim($_POST['surname'] ?? '');
        $pesel             = trim($_POST['pesel'] ?? '');

        if (strlen($email) > 255 || strlen($name) > 100 || strlen($surname) > 100 || strlen($password) > 1024) {
            return $this->render('register', [
                'messages'   => ['Dane wejściowe są zbyt długie.'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->render('register', [
                'messages'   => ['Podaj poprawny adres email.'],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

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
