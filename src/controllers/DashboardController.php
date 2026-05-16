<?php

require_once 'AppController.php';
require_once __DIR__.'/../repositories/AppointmentRepository.php';
require_once __DIR__.'/../repositories/UsersRepository.php';

class DashboardController extends AppController {

    private $appointmentRepo;
    private $userRepo;

    public function __construct(AppointmentRepository $appointmentRepo = null, UsersRepository $userRepo = null) {
        parent::__construct();
        $this->appointmentRepo = $appointmentRepo ?: new AppointmentRepository();
        $this->userRepo = $userRepo ?: new UsersRepository();
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

    public function apiGetProfile() {
        $this->requireLogin();
        header('Content-Type: application/json');
        $data = $this->userRepo->getUserProfileData($_SESSION['user_id']);
        echo json_encode($data ?: ['error' => 'Not found']);
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