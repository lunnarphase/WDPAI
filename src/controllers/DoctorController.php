<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/AppointmentRepository.php';

class DoctorController extends AppController {

    private $appointmentRepo;

    public function __construct(AppointmentRepository $appointmentRepo = null) {
        parent::__construct();
        $this->appointmentRepo = $appointmentRepo ?: new AppointmentRepository();
    }
    
    private function requireDoctor() {
        $this->requireLogin();
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'doctor') {
            $this->forbidden();
        }
    }

    public function doctorDashboard() {
        $this->requireDoctor();

        $userId = $_SESSION['user_id'];
        
        $appointments = $this->appointmentRepo->getDoctorAppointments($userId);
        $stats = $this->appointmentRepo->getDoctorStats($userId);

        return $this->render('doctor_dashboard', [
            'appointments' => $appointments,
            'todayCount' => $stats['today'],
            'upcomingCount' => $stats['upcoming']
        ]);
    }

    public function doctorUpdateStatus() {
        $this->requireDoctor();

        if ($this->isPost()) {
            if (empty($_POST['appointment_id']) || empty($_POST['status'])) {
                $this->badRequest();
            }
            $appointmentId = (int)$_POST['appointment_id'];
            $status = $_POST['status'];
            $recommendations = $_POST['recommendations'] ?? null; 
            $userId = $_SESSION['user_id'];

            $this->appointmentRepo->updateAppointmentStatusByDoctor($appointmentId, $userId, $status, $recommendations);
        }
        
        header("Location: http://$_SERVER[HTTP_HOST]/doctor-dashboard"); exit();
    }

    public function findDoctor() {
        $this->requireLogin();

        $doctorId = $_GET['id'] ?? $_GET['doctor_id'] ?? null;

        if (!$doctorId) {
            header("Location: http://$_SERVER[HTTP_HOST]/dashboard");
            exit();
        }

        return $this->render('book_appointment', [
            'doctorId' => $doctorId
        ]);
    }

    public function apiGetSlots() {
        header('Content-Type: application/json');
        
        $doctorId = (int)($_GET['doctor_id'] ?? 0);
        $date = $_GET['date'] ?? '';

        if(!$doctorId || empty($date)) {
            echo json_encode([]);
            exit();
        }

        $takenSlots = $this->appointmentRepo->getTakenSlots($doctorId, $date);

        $allSlots = ['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '13:00', '13:30', '14:00', '14:30', '15:00'];

        $availableSlots = [];
        foreach ($allSlots as $slot) {
            if (!in_array($slot, $takenSlots)) {
                $availableSlots[] = $slot;
            }
        }

        echo json_encode($availableSlots);
        exit();
    }

    public function doctorAvailability() {
        $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';

        if ($contentType === "application/json") {
            $content = trim(file_get_contents("php://input"));
            $decoded = json_decode($content, true);

            $doctorId = (int)($decoded['doctor_id'] ?? 0);
            $startDate = $decoded['start_date'] ?? '';
            $endDate = $decoded['end_date'] ?? '';

            if(!$doctorId || empty($startDate) || empty($endDate)) {
                echo json_encode([]);
                exit();
            }

            $booked = $this->appointmentRepo->getAppointmentsInRange($doctorId, $startDate, $endDate);

            header('Content-Type: application/json');
            echo json_encode($booked);
            exit();
        }
    }
}