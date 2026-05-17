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

    public function myAppointments() {
        $this->requireLogin();

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
        header('Content-Type: application/json');

        $keyword = $_GET['q'] ?? '';
        $spec = $_GET['spec'] ?? 'all';

        $doctors = $this->userRepo->searchDoctors($keyword, $spec);

        foreach ($doctors as &$doctor) {
            $doctor['next_slot'] = $this->reviewRepo->getNextAvailableSlot((int)$doctor['doctor_id']);
        }
        unset($doctor);

        echo json_encode($doctors);
        exit();
    }

    public function apiGetAppointments() {
        header('Content-Type: application/json');

        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['error' => 'Not logged in']);
            exit();
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

        echo json_encode($processedAppointments);
        exit();
    }

    public function apiMarkNotificationsRead() {
        $this->requireLogin();
        $this->appointmentRepo->markNotificationsAsRead($_SESSION['user_id']);
        echo json_encode(['status' => 'ok']);
        exit();
    }

    public function apiClearNotifications() {
        $this->requireLogin();
        $this->appointmentRepo->clearNotifications($_SESSION['user_id']);
        echo json_encode(['status' => 'ok']);
        exit();
    }

    public function apiDeleteNotification() {
        $this->requireLogin();
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true);
        $notifId = (int)($input['id'] ?? 0);
        if ($notifId <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid id']); exit(); }
        $this->appointmentRepo->deleteNotification($notifId, $_SESSION['user_id']);
        echo json_encode(['success' => true]);
        exit();
    }

    public function apiGetProfile() {
        $this->requireLogin();
        header('Content-Type: application/json');
        $data = $this->userRepo->getUserProfileData($_SESSION['user_id']);
        echo json_encode($data ?: ['error' => 'Not found']);
        exit();
    }

    public function apiChangePassword() {
        $this->requireLogin();
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $password = trim($input['password'] ?? '');

        if (strlen($password) > 1024) {
            echo json_encode(['success' => false, 'message' => 'Hasło jest zbyt długie.']);
            exit();
        }

        $passwordError = $this->validateStrongPassword($password);
        if ($passwordError !== null) {
            echo json_encode(['success' => false, 'message' => $passwordError]);
            exit();
        }

        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $result = $this->userRepo->updateUserPassword($_SESSION['user_id'], $hashed);
        if ($result) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Błąd aktualizacji hasła']);
        }
        exit();
    }

    public function apiUpdateProfile() {
        $this->requireLogin();
        header('Content-Type: application/json');
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            echo json_encode(['success' => false, 'message' => 'Błędne dane']);
            exit();
        }

        $email = trim($input['email'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = !empty($input['password']) ? trim($input['password']) : null;

        if (empty($email) || empty($username)) {
            echo json_encode(['success' => false, 'message' => 'Wypełnij wymagane pola (email, nazwa)']);
            exit();
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Podaj poprawny adres email.']);
            exit();
        }

        if ($password !== null) {
            if (strlen($password) > 1024) {
                echo json_encode(['success' => false, 'message' => 'Hasło jest zbyt długie.']);
                exit();
            }

            $passwordError = $this->validateStrongPassword($password);
            if ($passwordError !== null) {
                echo json_encode(['success' => false, 'message' => $passwordError]);
                exit();
            }
        }

        $result = $this->userRepo->updateUserProfileData($_SESSION['user_id'], $email, $username, $password);
        if ($result) {
            $_SESSION['user_name'] = $username;
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Błąd aktualizacji lub email jest zajęty']);
        }
        exit();
    }
}