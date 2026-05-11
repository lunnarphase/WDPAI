<?php

require_once 'AppController.php';
require_once __DIR__.'/../repositories/AppointmentRepository.php';
require_once __DIR__.'/../repositories/UsersRepository.php';

class DashboardController extends AppController {

    public function index() {
        $this->requireLogin();

        $appointmentRepo = new AppointmentRepository();
        $appointments = $appointmentRepo->getUpcomingAppointmentsForPatient($_SESSION['user_id']);
        $notifications = $appointmentRepo->getUserNotifications($_SESSION['user_id']);

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
        if (session_status() === PHP_SESSION_NONE) session_start();

        header('Content-Type: application/json');

        $keyword = $_GET['q'] ?? '';
        $spec = $_GET['spec'] ?? 'all';

        $repo = new UsersRepository();
        $doctors = $repo->searchDoctors($keyword, $spec);

        echo json_encode($doctors);
        exit();
    }

    public function apiGetAppointments() {
        if (session_status() === PHP_SESSION_NONE) session_start();

        header('Content-Type: application/json');

        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['error' => 'Not logged in']);
            exit();
        }

        $appointmentRepo = new AppointmentRepository();
        $appointments = $appointmentRepo->getUpcomingAppointmentsForPatient($_SESSION['user_id']);

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
}