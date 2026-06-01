<?php

require_once 'AppController.php';
require_once __DIR__.'/../repositories/AppointmentRepository.php';
require_once __DIR__.'/../repositories/UsersRepository.php';
require_once __DIR__.'/../repositories/ReviewRepository.php';

class DashboardController extends AppController {

    private $appointmentRepo;
    private $userRepo;
    private $reviewRepo;

    public function __construct(AppointmentRepository $appointmentRepo = null, UsersRepository $userRepo = null) {
        parent::__construct();
        $this->appointmentRepo = $appointmentRepo ?: new AppointmentRepository();
        $this->userRepo = $userRepo ?: UsersRepository::getInstance();
        $this->reviewRepo = new ReviewRepository();
    }

    private function redirectNonPatientToRoleDashboard(): void
    {
        $role = $_SESSION['user_role'] ?? '';

        if ($role === 'admin') {
            header('Location: ' . $this->getBaseUrl() . '/admin-dashboard');
            exit();
        }

        if ($role === 'doctor') {
            header('Location: ' . $this->getBaseUrl() . '/doctor-dashboard');
            exit();
        }
    }

    public function myAppointments() {
        $this->requireLogin();
        $this->redirectNonPatientToRoleDashboard();

        $appointments = $this->appointmentRepo->getUpcomingAppointmentsForPatient($_SESSION['user_id']);
        $notifications = $this->appointmentRepo->getUserNotifications($_SESSION['user_id']);

        $messages = [];
        if (isset($_GET['cancelled']) && $_GET['cancelled'] === 'success') {
            $messages[] = 'Wizyta została pomyślnie anulowana.';
        }

        return $this->render('my_appointments', [
            'appointments' => $appointments,
            'notifications' => $notifications,
            'messages' => $messages
        ]);
    }

    public function index() {
        $this->requireLogin();
        $this->redirectNonPatientToRoleDashboard();

        $appointments = $this->appointmentRepo->getUpcomingAppointmentsForPatient($_SESSION['user_id']);
        $notifications = $this->appointmentRepo->getUserNotifications($_SESSION['user_id']);

        $messages = [];
        if (isset($_GET['booking']) && $_GET['booking'] === 'success') {
            $messages[] = 'Wizyta została pomyślnie zarezerwowana!';
        }

        return $this->render('dashboard', [
            'user_name' => $_SESSION['user_name'],
            'appointments' => $appointments,
            'notifications' => $notifications,
            'messages' => $messages,
            'is_success' => true
        ]);
    }

    public function apiSearchDoctors() {
        $this->requireLogin();

        if (!$this->isGet()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $keyword = $_GET['q'] ?? '';
        $spec = $_GET['spec'] ?? 'all';

        $doctors = $this->userRepo->searchDoctors($keyword, $spec);

        foreach ($doctors as &$doctor) {
            $doctor['next_slot'] = $this->reviewRepo->getNextAvailableSlot((int)$doctor['doctor_id']);
        }
        unset($doctor);

        $this->jsonResponse($doctors);
    }

    public function apiGetAppointments() {
        $this->requireLogin();

        if (!$this->isGet()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $appointments = $this->appointmentRepo->getUpcomingAppointmentsForPatient($_SESSION['user_id']);

        $miesiace = ['Jan'=>'Sty', 'Feb'=>'Lut', 'Mar'=>'Mar', 'Apr'=>'Kwi', 'May'=>'Maj', 'Jun'=>'Cze', 'Jul'=>'Lip', 'Aug'=>'Sie', 'Sep'=>'Wrz', 'Oct'=>'Paź', 'Nov'=>'Lis', 'Dec'=>'Gru'];
        $processedAppointments = [];
        if (!empty($appointments)) {
            foreach ($appointments as $app) {
                $ts = strtotime($app['appointment_date']);
                $app['day'] = date('d', $ts);
                $app['month'] = $miesiace[date('M', $ts)];
                $app['time_formatted'] = date('H:i', strtotime($app['appointment_time']));
                $processedAppointments[] = $app;
            }
        }

        $this->jsonResponse($processedAppointments);
    }

    public function apiMarkNotificationsRead() {
        $this->requireLogin();

        if (!$this->isPost()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $this->verifyCsrf();

        $this->appointmentRepo->markNotificationsAsRead($_SESSION['user_id']);
        $this->jsonResponse(['status' => 'ok']);
    }

    public function apiGetNotifications() {
        $this->requireLogin();

        if (!$this->isGet()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $notifications = $this->appointmentRepo->getUserNotifications((int)$_SESSION['user_id']);
        $unreadCount = $this->appointmentRepo->getUnreadNotificationsCount((int)$_SESSION['user_id']);

        $this->jsonResponse([
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    public function apiGetNotificationsUnreadCount() {
        $this->requireLogin();

        if (!$this->isGet()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $this->jsonResponse([
            'unread_count' => $this->appointmentRepo->getUnreadNotificationsCount((int)$_SESSION['user_id']),
        ]);
    }

    public function apiClearNotifications() {
        $this->requireLogin();

        if (!$this->isPost()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $this->verifyCsrf();

        try {
            $deletedCount = $this->appointmentRepo->clearNotifications((int)$_SESSION['user_id']);
            $this->jsonResponse([
                'status' => 'ok',
                'deleted_count' => $deletedCount,
            ]);
        } catch (Exception $e) {
            error_log('apiClearNotifications error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się wyczyścić powiadomień.'], 500);
        }
    }

    public function apiDeleteNotification() {
        $this->requireLogin();

        if (!$this->isPost()) {
            $this->jsonResponse(['success' => false, 'error' => 'Niedozwolona metoda.'], 405);
        }

        $this->verifyCsrf();

        $input = json_decode(file_get_contents('php://input'), true);
        $notifId = (int)($input['id'] ?? 0);
        if ($notifId <= 0) {
            $this->jsonResponse(['success' => false, 'error' => 'Invalid id'], 400);
        }

        try {
            $deleted = $this->appointmentRepo->deleteNotification($notifId, (int)$_SESSION['user_id']);
            if (!$deleted) {
                $this->jsonResponse(['success' => false, 'error' => 'Powiadomienie nie istnieje.'], 404);
            }

            $this->jsonResponse(['success' => true]);
        } catch (Exception $e) {
            error_log('apiDeleteNotification error: ' . $e->getMessage());
            $this->jsonResponse(['success' => false, 'error' => 'Nie udało się usunąć powiadomienia.'], 500);
        }
    }

    public function apiGetProfile() {
        $this->requireLogin();

        if (!$this->isGet()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $data = $this->userRepo->getUserProfileData($_SESSION['user_id']);

        if (!$data) {
            $this->jsonResponse(['error' => 'Not found'], 404);
        }

        $this->jsonResponse($data);
    }

    public function apiChangePassword() {
        $this->requireLogin();

        if (!$this->isPost()) {
            $this->jsonResponse(['success' => false, 'message' => 'Niedozwolona metoda.'], 405);
        }

        $this->verifyCsrf();

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->jsonResponse(['success' => false, 'message' => 'Błędne dane wejściowe.'], 400);
        }

        $password = trim($input['password'] ?? '');

        if (strlen($password) > 1024) {
            $this->jsonResponse(['success' => false, 'message' => 'Hasło jest zbyt długie.'], 400);
        }

        $passwordError = $this->validateStrongPassword($password);
        if ($passwordError !== null) {
            $this->jsonResponse(['success' => false, 'message' => $passwordError], 400);
        }

        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $result = $this->userRepo->updateUserPassword($_SESSION['user_id'], $hashed);
        if ($result) {
            $this->jsonResponse(['success' => true]);
        } else {
            $this->jsonResponse(['success' => false, 'message' => 'Błąd aktualizacji hasła'], 500);
        }
    }

    public function apiUpdateProfile() {
        $this->requireLogin();

        if (!$this->isPost()) {
            $this->jsonResponse(['success' => false, 'message' => 'Niedozwolona metoda.'], 405);
        }

        $this->verifyCsrf();
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $this->jsonResponse(['success' => false, 'message' => 'Błędne dane'], 400);
        }

        $email = trim($input['email'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = !empty($input['password']) ? trim($input['password']) : null;

        if (empty($email) || empty($username)) {
            $this->jsonResponse(['success' => false, 'message' => 'Wypełnij wymagane pola (email, nazwa)'], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->jsonResponse(['success' => false, 'message' => 'Podaj poprawny adres email.'], 400);
        }

        if ($password !== null) {
            if (strlen($password) > 1024) {
                $this->jsonResponse(['success' => false, 'message' => 'Hasło jest zbyt długie.'], 400);
            }

            $passwordError = $this->validateStrongPassword($password);
            if ($passwordError !== null) {
                $this->jsonResponse(['success' => false, 'message' => $passwordError], 400);
            }
        }

        $result = $this->userRepo->updateUserProfileData($_SESSION['user_id'], $email, $username, $password);
        if ($result) {
            $_SESSION['user_name'] = $username;
            $this->jsonResponse(['success' => true]);
        } else {
            $this->jsonResponse(['success' => false, 'message' => 'Błąd aktualizacji lub email jest zajęty'], 409);
        }
    }
}