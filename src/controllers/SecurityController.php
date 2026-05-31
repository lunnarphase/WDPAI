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
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!empty($forwarded)) {
            $parts = explode(',', $forwarded);
            $candidate = trim($parts[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if (filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return $remoteAddr;
        }

        return 'unknown';
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

        $ip   = $this->getClientIpAddress();
        $user = $this->userRepository->getUserByEmail($email);

        if ($ip !== 'unknown' && $this->loginAttemptsRepository->isIpBlocked($ip)) {
            error_log("Blocked IP login attempt: {$ip} for email: {$email}");

            // Celowo nie ujawniamy pozostalego czasu blokady - to pozwoliloby zautomatyzowac
            // atak w rownych interwalach. Informacja o czasie jest dostepna wylacznie dla admina.
            return $this->render('login', [
                'messages'   => [
                    'Logowanie z tego adresu IP jest tymczasowo zablokowane.',
                    'Jesli to pomylka, skontaktuj sie z administratorem.'
                ],
                'csrf_token' => $this->generateCsrfToken(),
            ]);
        }

        // Globalny lockout konta - atak rozproszony (>=2 IP, lacznie >=4 nieudanych).
        // Tu blokujemy KAZDEMU dostep do konta, bo widac, ze sprawa nie ogranicza
        // sie do jednego napastnika. Sprawdzamy PRZED soft lockoutem per IP-konto,
        // bo jest ostrzejszy.
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

        // Soft lockout per (IP, konto) - 3 nieudane proby z tego IP na to konto.
        // Prawowity wlasciciel z innego IP nadal moze sie logowac. Zapobiega
        // klasycznemu account-lockout DoS, ktory atakujacy z jednego IP moglby
        // zrobic dowolnemu uzytkownikowi.
        if ($ip !== 'unknown' && $this->loginAttemptsRepository->isTemporarilyLockedForIpAccount($email, $ip, 3, 15)) {
            error_log("Per-IP soft lockout for email: {$email} from IP: {$ip}");
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

                // Powiadamiaj adminów dokladnie raz - przy pierwszej probie, ktora wpada
                // w lockout (3), oraz potem co kazde kolejne 3 nieudane proby (6, 9, 12, ...).
                if ($user && $failures >= 3 && $failures % 3 === 0) {
                    $this->loginAttemptsRepository->notifyAdmins($email, $failures);
                }
            }

            // Wykrywanie ataku rozproszonego NA KONTO (>=2 IP, lacznie >=4 nieudanych)
            // - to jest moment, w ktorym blokada konta wlasnie wchodzi w zycie. Wysylamy
            // pojedyncze powiadomienie krytyczne dla adminow (kazda kolejna proba juz
            // ich nie spamuje, bo isAccountGloballyLocked odetnie reqest wczesniej).
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

                        // Bez ujawniania pozostalego czasu (ochrona przed automatyzacja ataku).
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
        $this->requireHttps();

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
