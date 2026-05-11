<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/AppointmentRepository.php';

class DoctorController extends AppController {

    public function doctorDashboard() {
        if (session_status() === PHP_SESSION_NONE) session_start();

        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'doctor') {
            header("Location: http://$_SERVER[HTTP_HOST]/dashboard"); 
            exit();
        }

        $repo = new AppointmentRepository();
        $userId = $_SESSION['user_id'];
        
        $appointments = $repo->getDoctorAppointments($userId);
        $stats = $repo->getDoctorStats($userId);

        return $this->render('doctor_dashboard', [
            'appointments' => $appointments,
            'todayCount' => $stats['today'],
            'upcomingCount' => $stats['upcoming']
        ]);
    }

    public function doctorUpdateStatus() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'doctor') {
            header("Location: http://$_SERVER[HTTP_HOST]/dashboard"); exit();
        }

        if ($this->isPost()) {
            $appointmentId = (int)$_POST['appointment_id'];
            $status = $_POST['status'];
            $recommendations = $_POST['recommendations'] ?? null; 
            $userId = $_SESSION['user_id'];

            $repo = new AppointmentRepository();
            $repo->updateAppointmentStatusByDoctor($appointmentId, $userId, $status, $recommendations);
        }
        
        header("Location: http://$_SERVER[HTTP_HOST]/doctor-dashboard"); exit();
    }

    public function findDoctor() {
        if (session_status() === PHP_SESSION_NONE) session_start();

        if (!isset($_SESSION['user_id'])) {
            header("Location: http://$_SERVER[HTTP_HOST]/login"); 
            exit();
        }

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
        
        $doctorId = (int)$_GET['doctor_id'];
        $date = $_GET['date'];

        $repo = new AppointmentRepository();
        $takenSlots = $repo->getTakenSlots($doctorId, $date);

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

            $doctorId = (int)$decoded['doctor_id'];
            $startDate = $decoded['start_date'];
            $endDate = $decoded['end_date'];

            $repo = new AppointmentRepository();
            $booked = $repo->getAppointmentsInRange($doctorId, $startDate, $endDate);

            header('Content-Type: application/json');
            echo json_encode($booked);
            exit();
        }
    }
}