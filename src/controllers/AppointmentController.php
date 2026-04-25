<?php

require_once 'AppController.php';

class AppointmentController extends AppController {

    public function book() {
        $this->requireLogin();
        
        // pobieramy ID lekarza z adresu URL
        $doctorId = $_GET['id'] ?? null;

        if (!$doctorId) {
            // jeśli brak ID, to wracamy do wyszukiwarki
            $url = "http://$_SERVER[HTTP_HOST]";
            header("Location: {$url}/find-doctor");
            exit();
        }

        // tutaj pobieramy dane lekarzy z bazy
        
        return $this->render('book_appointment', ['doctorId' => $doctorId]);
    }

    public function confirm() {
        $this->requireLogin();

        // akceptujemy tylko formularze POST
        if (!$this->isPost()) {
            $url = "http://$_SERVER[HTTP_HOST]";
            header("Location: {$url}/dashboard");
            exit();
        }

        $doctorId = $_POST['doctor_id'] ?? null;
        $date = $_POST['appointment_date'] ?? null;
        $time = $_POST['appointment_time'] ?? null;
        $userId = $_SESSION['user_id'];

        // jeśli ktoś coś pominął, wraca do wyboru
        if (!$doctorId || !$date || !$time) {
            return $this->render('book_appointment', [
                'doctorId' => $doctorId, 
                'messages' => ['Wybierz datę i godzinę przed zatwierdzeniem!']
            ]);
        }

        require_once __DIR__.'/../repositories/AppointmentRepository.php';
        $appointmentRepo = new AppointmentRepository();

        try {
            $appointmentRepo->createAppointment($userId, $doctorId, $date, $time);
            
            // jesli ok, to przekierowujemy na dashboard - dodac komunikat o poprawnym dodaniu
            $url = "http://$_SERVER[HTTP_HOST]";
            header("Location: {$url}/dashboard");
            exit();
            
        } catch (Exception $e) {
            return $this->render('book_appointment', [
                'doctorId' => $doctorId, 
                'messages' => ['Błąd bazy: ' . $e->getMessage()]
            ]);
        }
    }
}