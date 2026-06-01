<?php

require_once 'AppController.php';
require_once __DIR__.'/../repositories/AppointmentRepository.php';

class AppointmentController extends AppController {

    private $appointmentRepo;

    private function sanitizeReturnTo(?string $returnTo): string
    {
        if (!is_string($returnTo) || $returnTo === '' || $returnTo[0] !== '/') {
            return '/dashboard';
        }

        $path = parse_url($returnTo, PHP_URL_PATH) ?: '';
        $query = parse_url($returnTo, PHP_URL_QUERY);
        $allowedPaths = ['/dashboard', '/find-doctor', '/my-appointments'];

        if (!in_array($path, $allowedPaths, true)) {
            return '/dashboard';
        }

        return $path . ($query ? ('?' . $query) : '');
    }

    public function __construct(AppointmentRepository $appointmentRepo = null) {
        parent::__construct();
        $this->appointmentRepo = $appointmentRepo ?: new AppointmentRepository();
    }

    public function book() {
        $this->requireLogin();
        
        $doctorId = $_GET['id'] ?? null;
        $returnTo = $this->sanitizeReturnTo($_GET['return_to'] ?? null);

        if (!$doctorId) {
            header("Location: " . $this->getBaseUrl() . "/dashboard");
            exit();
        }

        return $this->render('book_appointment', [
            'doctorId' => $doctorId,
            'returnTo' => $returnTo,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    public function confirm() {
        $this->requireLogin();

        if (!$this->isPost()) {
            header("Location: " . $this->getBaseUrl() . "/dashboard");
            exit();
        }

        $this->verifyCsrf();

        $doctorId = (int)($_POST['doctor_id'] ?? 0);
        $date = $_POST['appointment_date'] ?? null;
        $time = $_POST['appointment_time'] ?? null;
        $returnTo = $this->sanitizeReturnTo($_POST['return_to'] ?? null);
        $userId = $_SESSION['user_id'];

        if (!$doctorId || !$date || !$time) {
            return $this->render('book_appointment', [
                'doctorId' => $doctorId, 
                'returnTo' => $returnTo,
                'csrf_token' => $this->generateCsrfToken(),
                'messages' => ['Wybierz datę i godzinę przed zatwierdzeniem!']
            ]);
        }

        try {
            $this->appointmentRepo->createAppointment($userId, $doctorId, $date, $time);
            header("Location: " . $this->getBaseUrl() . "/dashboard?booking=success");
            exit();
        } catch (RuntimeException $e) {
            error_log('Appointment creation business error: ' . $e->getMessage());
            return $this->render('book_appointment', [
                'doctorId' => $doctorId,
                'returnTo' => $returnTo,
                'csrf_token' => $this->generateCsrfToken(),
                'messages' => [$e->getMessage()]
            ]);
        } catch (Exception $e) {
            error_log('Appointment creation error: ' . $e->getMessage());
            return $this->render('book_appointment', [
                'doctorId' => $doctorId, 
                'returnTo' => $returnTo,
                'csrf_token' => $this->generateCsrfToken(),
                'messages' => ['Wystąpił błąd podczas zapisu wizyty. Spróbuj ponownie.']
            ]);
        }
    }

    public function cancel() {
        $this->requireLogin();

        if (!$this->isPost()) {
            header("Location: " . $this->getBaseUrl() . "/dashboard");
            exit();
        }

        $this->verifyCsrf();

        if (empty($_POST['appointment_id'])) {
            $this->badRequest();
        }

        $appointmentId = (int)$_POST['appointment_id'];
        $cancelReason = $_POST['cancel_reason'] ?? '';
        $userId = $_SESSION['user_id'];

        try {
            $this->appointmentRepo->cancelAppointment($appointmentId, $userId, $cancelReason, '');
            header("Location: " . $this->getBaseUrl() . "/dashboard?cancel=success");
            exit();
        } catch (Exception $e) {
            error_log('Appointment cancellation error: ' . $e->getMessage());
            header("Location: " . $this->getBaseUrl() . "/dashboard?cancel=error");
            exit();
        }
    }

    public function getAvailability($id = null) {
        $this->requireLogin();

        if (!$this->isPost()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';

        if (strpos($contentType, "application/json") === false) {
            $this->jsonResponse(['error' => 'Nieprawidłowy typ danych.'], 400);
        }

        $content = trim(file_get_contents("php://input"));
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            $this->jsonResponse(['error' => 'Błędny format JSON.'], 400);
        }

        $doctorId = (int)($decoded['doctor_id'] ?? 0);
        $startDate = $decoded['start_date'] ?? '';
        $endDate = $decoded['end_date'] ?? '';

        if ($doctorId <= 0 || empty($startDate) || empty($endDate)) {
            $this->jsonResponse(['error' => 'Brak wymaganych danych.'], 400);
        }

        $bookedSlots = $this->appointmentRepo->getBookedSlots($doctorId, $startDate, $endDate);
        $this->jsonResponse($bookedSlots);
    }
}