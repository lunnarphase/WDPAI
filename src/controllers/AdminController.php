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

            $status = $this->userRepo->addUserAdmin($email, $password, $username, $role, $pesel);

            if (!$status) {
                // Should optimally return error view
            }
        }
        
        header("Location: " . $this->getBaseUrl() . "/admin-dashboard"); 
        exit();
    }

    public function adminDeleteReview() {
        $this->requireAdmin();
        header('Content-Type: application/json');

        if (!$this->isPost()) {
            echo json_encode(['error' => 'Niedozwolona metoda.']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $reviewId = (int)($input['review_id'] ?? 0);

        if (!$reviewId) {
            echo json_encode(['error' => 'Brak ID opinii.']);
            exit();
        }

        $this->reviewRepo->deleteReviewAdmin($reviewId);
        echo json_encode(['success' => true]);
        exit();
    }

    public function adminDismissReport() {
        $this->requireAdmin();
        header('Content-Type: application/json');

        if (!$this->isPost()) {
            echo json_encode(['error' => 'Niedozwolona metoda.']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $reportId = (int)($input['report_id'] ?? 0);
        $adminResponse = trim($input['admin_response'] ?? '');

        if (!$reportId || empty($adminResponse)) {
            echo json_encode(['error' => 'Podaj uzasadnienie odrzucenia.']);
            exit();
        }

        $this->reviewRepo->dismissReport($reportId, $adminResponse);
        echo json_encode(['success' => true]);
        exit();
    }

    public function adminResolveReport() {
        $this->requireAdmin();
        header('Content-Type: application/json');

        if (!$this->isPost()) {
            echo json_encode(['error' => 'Niedozwolona metoda.']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $reviewId = (int)($input['review_id'] ?? 0);

        if (!$reviewId) {
            echo json_encode(['error' => 'Brak ID opinii.']);
            exit();
        }

        $this->reviewRepo->resolveReportByDeletion($reviewId);
        echo json_encode(['success' => true]);
        exit();
    }

    public function apiGetReviewReports() {
        $this->requireAdmin();
        header('Content-Type: application/json');

        $reviewId = (int)($_GET['review_id'] ?? 0);
        if (!$reviewId) {
            echo json_encode([]);
            exit();
        }

        $reports = $this->reviewRepo->getPendingReportsForReview($reviewId);
        echo json_encode($reports);
        exit();
    }

    public function apiGetLoginLogs() {
        $this->requireAdmin();
        header('Content-Type: application/json');

        $logs = $this->loginAttemptsRepo->getAccountsWithSuspiciousActivity(2);
        echo json_encode($logs);
        exit();
    }

    public function adminBlockUser() {
        $this->requireAdmin();

        if (!$this->isPost()) {
            $this->badRequest();
        }

        header('Content-Type: application/json');

        $input  = json_decode(file_get_contents('php://input'), true);
        $userId = (int)($input['user_id'] ?? 0);

        if ($userId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID użytkownika.']);
            exit();
        }

        // Nie można zablokować samego siebie
        if ($userId === (int)$_SESSION['user_id']) {
            echo json_encode(['success' => false, 'error' => 'Nie możesz zablokować własnego konta.']);
            exit();
        }

        $this->userRepo->blockUser($userId);
        echo json_encode(['success' => true]);
        exit();
    }

    public function adminUnblockUser() {
        $this->requireAdmin();

        if (!$this->isPost()) {
            $this->badRequest();
        }

        header('Content-Type: application/json');

        $input  = json_decode(file_get_contents('php://input'), true);
        $userId = (int)($input['user_id'] ?? 0);

        if ($userId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID użytkownika.']);
            exit();
        }

        $this->userRepo->unblockUser($userId);
        echo json_encode(['success' => true]);
        exit();
    }
}
