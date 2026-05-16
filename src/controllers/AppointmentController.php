<?php

require_once 'AppController.php';
require_once __DIR__.'/../repositories/AppointmentRepository.php';

class AppointmentController extends AppController {

    private $appointmentRepo;

    public function __construct(AppointmentRepository $appointmentRepo = null) {
        parent::__construct();
        $this->appointmentRepo = $appointmentRepo ?: new AppointmentRepository();
    }

    public function book() {
        $this->requireLogin();
        
        $doctorId = $_GET['id'] ?? null;

        if (!$doctorId) {
            header("Location: http://$_SERVER[HTTP_HOST]/dashboard");
            exit();
        }

        return $this->render('book_appointment', ['doctorId' => $doctorId]);
    }

    public function confirm() {
        $this->requireLogin();

        if (!$this->isPost()) {
            header("Location: http://$_SERVER[HTTP_HOST]/dashboard");
            exit();
        }

        $doctorId = (int)($_POST['doctor_id'] ?? 0);
        $date = $_POST['appointment_date'] ?? null;
        $time = $_POST['appointment_time'] ?? null;
        $userId = $_SESSION['user_id'];

        if (!$doctorId || !$date || !$time) {
            return $this->render('book_appointment', [
                'doctorId' => $doctorId, 
                'messages' => ['Wybierz datę i godzinę przed zatwierdzeniem!']
            ]);
        }

        try {
            $this->appointmentRepo->createAppointment($userId, $doctorId, $date, $time);
            header("Location: http://$_SERVER[HTTP_HOST]/dashboard?booking=success");
            exit();
        } catch (Exception $e) {
            return $this->render('book_appointment', [
                'doctorId' => $doctorId, 
                'messages' => ['Wystąpił błąd podczas zapisu: ' . $e->getMessage()]
            ]);
        }
    }

    public function cancel() {
        $this->requireLogin();

        if (!$this->isPost()) {
            header("Location: http://$_SERVER[HTTP_HOST]/dashboard");
            exit();
        }

        if (empty($_POST['appointment_id'])) {
            $this->badRequest();
        }

        $appointmentId = (int)$_POST['appointment_id'];
        $cancelReason = $_POST['cancel_reason'] ?? '';
        $userId = $_SESSION['user_id'];

        try {
            $this->appointmentRepo->cancelAppointment($appointmentId, $userId, $cancelReason, '');
            header("Location: http://$_SERVER[HTTP_HOST]/dashboard?cancel=success");
            exit();
        } catch (Exception $e) {
            header("Location: http://$_SERVER[HTTP_HOST]/dashboard?cancel=error");
            exit();
        }
    }

    public function getAvailability($id = null) {
        $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';

        if (strpos($contentType, "application/json") !== false) {
            $content = trim(file_get_contents("php://input"));
            $decoded = json_decode($content, true);

            $doctorId = (int)$decoded['doctor_id'];
            $startDate = $decoded['start_date'];
            $endDate = $decoded['end_date'];

            $bookedSlots = $this->appointmentRepo->getBookedSlots($doctorId, $startDate, $endDate);

            header('Content-Type: application/json');
            echo json_encode($bookedSlots);
            exit();
        }
    }
}