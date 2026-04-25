<?php

require_once 'AppController.php';
require_once __DIR__.'/../repositories/AppointmentRepository.php';

class DashboardController extends AppController {

    public function index() {
        $this->requireLogin();

        $appointmentRepo = new AppointmentRepository();
        $appointments = $appointmentRepo->getUpcomingAppointmentsForPatient($_SESSION['user_id']);

        // Sprawdzamy czy w adresie URL jest flaga sukcesu
        $messages = [];
        if (isset($_GET['booking']) && $_GET['booking'] === 'success') {
            $messages[] = 'Wizyta została pomyślnie zarezerwowana!';
        }

        return $this->render('dashboard', [
            'user_name' => $_SESSION['user_name'],
            'appointments' => $appointments,
            'messages' => $messages, // <--- Przekazujemy wiadomości do widoku
            'is_success' => true     // <--- Flaga do zielonego koloru komunikatu
        ]);
    }
}