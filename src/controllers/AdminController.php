<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/AppointmentRepository.php';
require_once __DIR__ . '/../repositories/UsersRepository.php';
require_once __DIR__ . '/../repositories/ReviewRepository.php';
require_once __DIR__ . '/../repositories/LoginAttemptsRepository.php';

class AdminController extends AppController {

    private $appointmentRepo;
    private $userRepo;
    private $reviewRepo;
    private $loginAttemptsRepo;

    public function __construct(AppointmentRepository $appointmentRepo = null, UsersRepository $userRepo = null, ReviewRepository $reviewRepo = null) {
        parent::__construct();
        $this->appointmentRepo   = $appointmentRepo ?: new AppointmentRepository();
        $this->userRepo          = $userRepo ?: UsersRepository::getInstance();
        $this->reviewRepo        = $reviewRepo ?: new ReviewRepository();
        $this->loginAttemptsRepo = new LoginAttemptsRepository();
    }
    
    private function requireAdmin() {
        $this->requireLogin();
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            $this->forbidden();
        }
    }

    public function adminDashboard() {
        $this->requireAdmin();

        return $this->render('admin_dashboard', [
            'appointments'  => $this->appointmentRepo->getAllAppointmentsAdmin(),
            'todayCount'    => $this->appointmentRepo->getTodaysAppointmentsCount(),
            'usersCount'    => $this->userRepo->getUsersCount(),
            'patients'      => $this->userRepo->getAllPatientsAdmin(),
            'doctors'       => $this->userRepo->getAllDoctorsAdmin(),
            'users'         => $this->userRepo->getAllUsersWithRoles(),
            'reviews'       => $this->reviewRepo->getAllReviewsAdmin(),
            'notifications' => $this->appointmentRepo->getUserNotifications($_SESSION['user_id']),
            'login_logs'    => $this->loginAttemptsRepo->getAccountsWithSuspiciousActivity(2),
        ]);
    }

    public function adminUpdateAppointment() {
        $this->requireAdmin();

        if ($this->isPost()) {
            $this->verifyCsrf();

            if (empty($_POST['appointment_id']) || empty($_POST['patient_id']) || empty($_POST['doctor_id']) || empty($_POST['appointment_date'])) {
                $this->badRequest();
            }
            $id = (int)$_POST['appointment_id'];
            $patientId = (int)$_POST['patient_id'];
            $doctorId = (int)$_POST['doctor_id'];
            $date = $_POST['appointment_date'];
            $time = $_POST['appointment_time'];
            $status = $_POST['status'];
            
            $this->appointmentRepo->updateAppointmentAdmin($id, $patientId, $doctorId, $date, $time, $status);
        }
        header("Location: " . $this->getBaseUrl() . "/admin-dashboard"); exit();
    }

    public function adminDeleteAppointment() {
        $this->requireAdmin();

        if ($this->isPost()) {
            $this->verifyCsrf();

            if (empty($_POST['appointment_id'])) {
                $this->badRequest();
            }
            $id = (int)$_POST['appointment_id'];
            $this->appointmentRepo->deleteAppointmentAdmin($id);
        }
        header("Location: " . $this->getBaseUrl() . "/admin-dashboard"); exit();
    }

    public function adminUpdateUser() {
        $this->requireAdmin();

        if ($this->isPost()) {
            $this->verifyCsrf();

            if (empty($_POST['user_id']) || empty($_POST['username']) || empty($_POST['email']) || empty($_POST['role'])) {
                $this->badRequest();
            }
            $id = (int)$_POST['user_id'];
            $username = $_POST['username'];
            $email = trim($_POST['email']);
            $password = $_POST['password'] ?? ''; 
            $role = $_POST['role'];
            $pesel = $_POST['pesel'] ?? '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->badRequest();
            }

            if (!empty($password)) {
                if (strlen($password) > 1024) {
                    $this->badRequest();
                }
                $passwordError = $this->validateStrongPassword($password);
                if ($passwordError !== null) {
                    $this->badRequest();
                }
            }
            
            $this->userRepo->updateUserAdmin($id, $username, $email, $password, $role, $pesel);
        }
        
        header("Location: " . $this->getBaseUrl() . "/admin-dashboard"); 
        exit();
    }

    public function adminDeleteUser() {
        $this->requireAdmin();

        if ($this->isPost()) {
            $this->verifyCsrf();

            if (empty($_POST['user_id']) || empty($_POST['user_role'])) {
                $this->badRequest();
            }
            $id = (int)$_POST['user_id'];
            $role = $_POST['user_role'];

            if ($id === $_SESSION['user_id']) {
                header("Location: " . $this->getBaseUrl() . "/admin-dashboard?error=self_delete");
                exit();
            }

            if ($role === 'admin' && $this->userRepo->getAdminCount() <= 1) {
                header("Location: " . $this->getBaseUrl() . "/admin-dashboard?error=last_admin");
                exit();
            }

            $this->userRepo->deleteUserAdmin($id);
        }
        
        header("Location: " . $this->getBaseUrl() . "/admin-dashboard"); 
        exit();
    }

    public function adminAddUser() {
        $this->requireAdmin();

        if ($this->isPost()) {
            $this->verifyCsrf();

            if (empty($_POST['username']) || empty($_POST['email']) || empty($_POST['password']) || empty($_POST['role'])) {
                $this->badRequest();
            }
            if ($_POST['role'] === 'patient' && empty($_POST['pesel'])) {
                $this->badRequest();
            }
            
            $username = trim($_POST['username']);
            $email = trim($_POST['email']);
            $password = $_POST['password'];
            $role = $_POST['role'];
            $pesel = $_POST['pesel'] ?? '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->badRequest();
            }
            if (strlen($password) > 1024) {
                $this->badRequest();
            }
            $passwordError = $this->validateStrongPassword($password);
            if ($passwordError !== null) {
                $this->badRequest();
            }

            $this->userRepo->addUserAdmin($email, $password, $username, $role, $pesel);
        }
        
        header("Location: " . $this->getBaseUrl() . "/admin-dashboard"); 
        exit();
    }

    public function adminDeleteReview() {
        $this->requireAdmin();

        if (!$this->isPost()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $this->verifyCsrf();

        $input = json_decode(file_get_contents('php://input'), true);
        $reviewId = (int)($input['review_id'] ?? 0);

        if (!$reviewId) {
            $this->jsonResponse(['error' => 'Brak ID opinii.'], 400);
        }

        try {
            $this->reviewRepo->deleteReviewAdmin($reviewId);
            $this->jsonResponse(['success' => true]);
        } catch (Exception $e) {
            error_log('Admin delete review error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się usunąć opinii.'], 500);
        }
    }

    public function adminDismissReport() {
        $this->requireAdmin();

        if (!$this->isPost()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $this->verifyCsrf();

        $input = json_decode(file_get_contents('php://input'), true);
        $reportId = (int)($input['report_id'] ?? 0);
        $adminResponse = trim($input['admin_response'] ?? '');

        if (!$reportId || empty($adminResponse)) {
            $this->jsonResponse(['error' => 'Podaj uzasadnienie odrzucenia.'], 400);
        }

        try {
            $this->reviewRepo->dismissReport($reportId, $adminResponse);
            $this->jsonResponse(['success' => true]);
        } catch (Exception $e) {
            error_log('Admin dismiss report error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się odrzucić zgłoszenia.'], 500);
        }
    }

    public function adminResolveReport() {
        $this->requireAdmin();

        if (!$this->isPost()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $this->verifyCsrf();

        $input = json_decode(file_get_contents('php://input'), true);
        $reviewId = (int)($input['review_id'] ?? 0);

        if (!$reviewId) {
            $this->jsonResponse(['error' => 'Brak ID opinii.'], 400);
        }

        try {
            $this->reviewRepo->resolveReportByDeletion($reviewId);
            $this->jsonResponse(['success' => true]);
        } catch (Exception $e) {
            error_log('Admin resolve report error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się rozwiązać zgłoszenia.'], 500);
        }
    }

    public function apiGetReviewReports() {
        $this->requireAdmin();

        $reviewId = (int)($_GET['review_id'] ?? 0);
        if (!$reviewId) {
            $this->jsonResponse([]);
        }

        try {
            $reports = $this->reviewRepo->getPendingReportsForReview($reviewId);
            $this->jsonResponse($reports);
        } catch (Exception $e) {
            error_log('Admin get reports error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się pobrać zgłoszeń.'], 500);
        }
    }

    public function apiGetLoginLogs() {
        $this->requireAdmin();

        try {
            $logs = $this->loginAttemptsRepo->getAccountsWithSuspiciousActivity(2);
            $this->jsonResponse($logs);
        } catch (Exception $e) {
            error_log('Admin get login logs error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się pobrać logów.'], 500);
        }
    }

    public function apiGetAdminAppointments() {
        $this->requireAdmin();

        if (!$this->isGet()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        try {
            $appointments = $this->appointmentRepo->getAllAppointmentsAdmin();
            $this->jsonResponse($appointments);
        } catch (Exception $e) {
            error_log('Admin get appointments error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się pobrać wizyt.'], 500);
        }
    }

    public function apiGetAdminReviews() {
        $this->requireAdmin();

        if (!$this->isGet()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        try {
            $reviews = $this->reviewRepo->getAllReviewsAdmin();
            $this->jsonResponse($reviews);
        } catch (Exception $e) {
            error_log('Admin get reviews error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się pobrać opinii.'], 500);
        }
    }

    public function apiGetAdminNotifications() {
        $this->requireAdmin();

        if (!$this->isGet()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        try {
            $notifications = $this->appointmentRepo->getUserNotifications((int)$_SESSION['user_id']);

            foreach ($notifications as &$notification) {
                if (($notification['type'] ?? '') !== 'global_ip_attack') {
                    continue;
                }

                $message = (string)($notification['message'] ?? '');
                $notification['ip_address'] = null;
                $notification['ip_blocked_until'] = null;
                $notification['ip_block_remaining_seconds'] = 0;
                $notification['target_accounts'] = [];

                if (!preg_match('/IP:\s*([0-9a-fA-F\.:]+)/', $message, $ipMatch)) {
                    continue;
                }

                $ipAddress = $ipMatch[1];
                $notification['ip_address'] = $ipAddress;

                $windowMinutes = 15;
                if (preg_match('/w ciagu\s+([0-9]+)\s+min/i', $message, $windowMatch)) {
                    $windowMinutes = max(1, (int)$windowMatch[1]);
                }

                $blockInfo = $this->loginAttemptsRepo->getBlockedIpDetails($ipAddress);
                $notification['ip_blocked_until'] = $blockInfo['blocked_until'] ?? null;
                $notification['ip_block_remaining_seconds'] = isset($blockInfo['remaining_seconds'])
                    ? (int)$blockInfo['remaining_seconds']
                    : 0;
                $notification['target_accounts'] = $this->loginAttemptsRepo->getRecentFailedTargetsForIp($ipAddress, $windowMinutes, 8);
            }
            unset($notification);

            $unreadCount = 0;
            foreach ($notifications as $notification) {
                $isRead = $notification['is_read'] ?? false;
                $isUnread = $isRead === false
                    || $isRead === 0
                    || $isRead === '0'
                    || $isRead === 'f'
                    || $isRead === null;

                if ($isUnread) {
                    $unreadCount++;
                }
            }

            $this->jsonResponse([
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
            ]);
        } catch (Exception $e) {
            error_log('Admin get notifications error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się pobrać powiadomień.'], 500);
        }
    }

    /**
     * Zwraca szczegoly ataku dla IP albo konta.
     */
    public function apiAdminAttackDetails() {
        $this->requireAdmin();

        if (!$this->isGet()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $ip    = trim((string)($_GET['ip'] ?? ''));
        $email = trim((string)($_GET['email'] ?? ''));

        try {
            if ($ip !== '') {
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    $this->jsonResponse(['error' => 'Nieprawidłowy adres IP.'], 400);
                }
                $blockInfo = $this->loginAttemptsRepo->getBlockedIpDetails($ip);
                $targets = $this->loginAttemptsRepo->getRecentFailedTargetsForIp($ip, 15, 20);
                $this->jsonResponse([
                    'kind' => 'ip',
                    'ip_address' => $ip,
                    'is_blocked' => (bool)($blockInfo['is_blocked'] ?? false),
                    'blocked_until' => $blockInfo['blocked_until'] ?? null,
                    'remaining_seconds' => (int)($blockInfo['remaining_seconds'] ?? 0),
                    'window_minutes' => 15,
                    'target_accounts' => $targets,
                ]);
            }

            if ($email !== '') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->jsonResponse(['error' => 'Nieprawidłowy email.'], 400);
                }
                $details = $this->loginAttemptsRepo->getAccountAttackDetails($email, 15);
                $this->jsonResponse([
                    'kind' => 'account',
                    'email' => $email,
                    'total_failures' => $details['total_failures'],
                    'distinct_ips' => $details['distinct_ips'],
                    'remaining_seconds' => $details['remaining_seconds'],
                    'window_minutes' => $details['window_minutes'],
                    'attacker_ips' => $details['attacker_ips'],
                ]);
            }

            $this->jsonResponse(['error' => 'Brak parametru ip lub email.'], 400);
        } catch (Exception $e) {
            error_log('apiAdminAttackDetails error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się pobrać szczegółów ataku.'], 500);
        }
    }

    public function adminBlockUser() {
        $this->requireAdmin();

        if (!$this->isPost()) {
            $this->jsonResponse(['success' => false, 'error' => 'Niedozwolona metoda.'], 405);
        }

        $this->verifyCsrf();

        $input  = json_decode(file_get_contents('php://input'), true);
        $userId = (int)($input['user_id'] ?? 0);

        if ($userId <= 0) {
            $this->jsonResponse(['success' => false, 'error' => 'Nieprawidłowe ID użytkownika.'], 400);
        }

        if ($userId === (int)$_SESSION['user_id']) {
            $this->jsonResponse(['success' => false, 'error' => 'Nie możesz zablokować własnego konta.'], 409);
        }

        try {
            $this->userRepo->blockUser($userId);
            $this->jsonResponse(['success' => true]);
        } catch (Exception $e) {
            error_log('Admin block user error: ' . $e->getMessage());
            $this->jsonResponse(['success' => false, 'error' => 'Nie udało się zablokować konta.'], 500);
        }
    }

    public function adminUnblockUser() {
        $this->requireAdmin();

        if (!$this->isPost()) {
            $this->jsonResponse(['success' => false, 'error' => 'Niedozwolona metoda.'], 405);
        }

        $this->verifyCsrf();

        $input  = json_decode(file_get_contents('php://input'), true);
        $userId = (int)($input['user_id'] ?? 0);

        if ($userId <= 0) {
            $this->jsonResponse(['success' => false, 'error' => 'Nieprawidłowe ID użytkownika.'], 400);
        }

        try {
            // unblockIpsForUser musi byc wykonane przed clearAttemptsForUserId
            // aby zachowac dane z login_attempts potrzebne do znalezienia IP.
            $unblockedIps = $this->loginAttemptsRepo->unblockIpsForUser($userId);

            $userEmail = $this->userRepo->getEmailById($userId);
            $clearedIpAlerts = $this->loginAttemptsRepo->deleteGlobalIpAttackNotificationsForIps($unblockedIps);
            $clearedAccountAlerts = $userEmail !== ''
                ? $this->loginAttemptsRepo->deleteAccountAttackNotificationsForEmail($userEmail)
                : 0;

            $this->loginAttemptsRepo->clearAttemptsForUserId($userId);
            $this->userRepo->unblockUser($userId);

            $this->jsonResponse([
                'success' => true,
                'unblocked_ips' => $unblockedIps,
                'cleared_ip_alerts' => $clearedIpAlerts,
                'cleared_account_alerts' => $clearedAccountAlerts,
            ]);
        } catch (Exception $e) {
            error_log('Admin unblock user error: ' . $e->getMessage());
            $this->jsonResponse(['success' => false, 'error' => 'Nie udało się odblokować konta.'], 500);
        }
    }
}
