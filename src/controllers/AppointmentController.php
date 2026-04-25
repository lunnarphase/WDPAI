<?php

require_once 'AppController.php';
require_once __DIR__.'/../repositories/AppointmentRepository.php';

class AppointmentController extends AppController {

    public function book() {
        $this->requireLogin();
        
        $doctorId = $_GET['id'] ?? null;

        if (!$doctorId) {
            $url = "http://$_SERVER[HTTP_HOST]";
            header("Location: {$url}/find-doctor");
            exit();
        }

        return $this->render('book_appointment', ['doctorId' => $doctorId]);
    }

    public function confirm() {
        $this->requireLogin();

        if (!$this->isPost()) {
            $url = "http://$_SERVER[HTTP_HOST]";
            header("Location: {$url}/dashboard");
            exit();
        }

        $doctorId = $_POST['doctor_id'] ?? null;
        $date = $_POST['appointment_date'] ?? null;
        $time = $_POST['appointment_time'] ?? null;
        $userId = $_SESSION['user_id'];

        if (!$doctorId || !$date || !$time) {
            return $this->render('book_appointment', [
                'doctorId' => $doctorId, 
                'messages' => ['Wybierz datę i godzinę przed zatwierdzeniem!']
            ]);
        }

        $appointmentRepo = new AppointmentRepository();

        try {
            $appointmentRepo->createAppointment($userId, $doctorId, $date, $time);
            
            $url = "http://$_SERVER[HTTP_HOST]";
            header("Location: {$url}/dashboard?booking=success");
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

        if ($this->isPost()) {
            $appointmentId = $_POST['appointment_id'] ?? null;
            $reason = $_POST['cancel_reason'] ?? 'Brak podanego powodu';
            $comment = $_POST['cancel_comment'] ?? '';

            if ($appointmentId) {
                $appointmentRepo = new AppointmentRepository();
                $appointmentRepo->cancelAppointment($appointmentId, $_SESSION['user_id'], $reason, $comment);
            }
        }

        // Zwracamy pacjenta na dashboard z zielonym komunikatem
        $url = "http://$_SERVER[HTTP_HOST]";
        header("Location: {$url}/dashboard?cancel=success");
        exit();
    }

    // Odpowiada na zapytania Fetch API z kalendarza
    public function getAvailability() {
        $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
        if ($contentType === "application/json") {
            $content = trim(file_get_contents("php://input"));
            $decoded = json_decode($content, true);

            $doctorId = $decoded['doctor_id'];
            $startDate = $decoded['start_date'];
            $endDate = $decoded['end_date'];

            $repo = new AppointmentRepository();
            $bookedSlots = $repo->getBookedSlots($doctorId, $startDate, $endDate);

            header('Content-type: application/json');
            echo json_encode($bookedSlots);
            exit();
        }
    }
}