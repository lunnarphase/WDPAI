<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/AppointmentRepository.php';
require_once __DIR__ . '/../repositories/ReviewRepository.php';

class DoctorController extends AppController {

    private $appointmentRepo;
    private $reviewRepo;

    public function __construct(AppointmentRepository $appointmentRepo = null, ReviewRepository $reviewRepo = null) {
        parent::__construct();
        $this->appointmentRepo = $appointmentRepo ?: new AppointmentRepository();
        $this->reviewRepo = $reviewRepo ?: new ReviewRepository();
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

        $doctorId = $this->getDoctorIdByUserId($userId);
        $doctorProfile  = $this->reviewRepo->getDoctorProfileData($doctorId);
        $doctorReviews  = $this->reviewRepo->getDoctorReviews($doctorId, true);
        $reviewSummary  = $this->reviewRepo->getReviewSummary($doctorId);

        return $this->render('doctor_dashboard', [
            'appointments'  => $appointments,
            'todayCount'    => $stats['today'],
            'upcomingCount' => $stats['upcoming'],
            'doctorProfile' => $doctorProfile,
            'doctorReviews' => $doctorReviews,
            'reviewSummary' => $reviewSummary,
            'notifications' => $this->appointmentRepo->getUserNotifications($userId)
        ]);
    }

    private function getDoctorIdByUserId(int $userId): int {
        return $this->reviewRepo->getDoctorIdByUserId($userId);
    }

    public function updateDoctorProfile() {
        $this->requireDoctor();
        header('Content-Type: application/json');

        if (!$this->isPost()) {
            echo json_encode(['error' => 'Niedozwolona metoda.']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $bio = trim($input['bio'] ?? '');
        $visitPrice = isset($input['visit_price']) && $input['visit_price'] !== '' ? (float)$input['visit_price'] : null;
        $visitDuration = isset($input['visit_duration']) && (int)$input['visit_duration'] > 0
            ? (int)$input['visit_duration']
            : 30;

        $this->reviewRepo->updateDoctorProfile(
            $_SESSION['user_id'],
            $bio,
            $visitPrice,
            $visitDuration
        );

        echo json_encode(['success' => true]);
        exit();
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
            $notifications = $this->appointmentRepo->getUserNotifications($_SESSION['user_id'] ?? 0);
            return $this->render('find_doctor', [
                'notifications' => $notifications
            ]);
        }

        return $this->render('book_appointment', [
            'doctorId' => $doctorId
        ]);
    }

    public function apiGetSlots() {
        header('Content-Type: application/json');

        $doctorId = (int)($_GET['doctor_id'] ?? 0);
        $date = $_GET['date'] ?? '';

        if (!$doctorId || empty($date)) {
            echo json_encode([]);
            exit();
        }

        echo json_encode($this->appointmentRepo->getAvailableSlotsForDate($doctorId, $date));
        exit();
    }

    public function apiGetDoctorAppointments() {
        $this->requireDoctor();
        header('Content-Type: application/json');
        $appointments = $this->appointmentRepo->getDoctorAppointments($_SESSION['user_id']);
        echo json_encode($appointments);
        exit();
    }

    public function apiGetWeekAvailability() {
        $this->requireDoctor();
        header('Content-Type: application/json');
        $doctorId = $this->getDoctorIdForCurrentUser();
        $weekStart = $_GET['week_start'] ?? '';
        $weekEnd   = $_GET['week_end']   ?? '';
        if (!$doctorId || empty($weekStart) || empty($weekEnd)) {
            echo json_encode([]);
            exit();
        }
        echo json_encode($this->appointmentRepo->getDoctorAvailabilityForWeek($doctorId, $weekStart, $weekEnd));
        exit();
    }

    public function apiSaveWeekAvailability() {
        $this->requireDoctor();
        header('Content-Type: application/json');
        $doctorId = $this->getDoctorIdForCurrentUser();
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$doctorId || empty($body['week_start']) || empty($body['week_end'])) {
            echo json_encode(['success' => false, 'error' => 'Brakujące dane.']);
            exit();
        }
        $ranges = $body['ranges'] ?? [];
        // Sanitise input
        $clean = [];
        foreach ($ranges as $r) {
            $date  = preg_replace('/[^0-9\-]/', '', $r['date'] ?? '');
            $start = preg_replace('/[^0-9:]/', '', $r['start_time'] ?? '');
            $end   = preg_replace('/[^0-9:]/', '', $r['end_time']   ?? '');
            if ($date && $start && $end && $start < $end) {
                $clean[] = ['date' => $date, 'start_time' => $start, 'end_time' => $end];
            }
        }
        try {
            $this->appointmentRepo->saveWeekAvailability($doctorId, $body['week_start'], $body['week_end'], $clean);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    public function apiGetScheduleTemplates() {
        $this->requireDoctor();
        header('Content-Type: application/json');
        $doctorId = $this->getDoctorIdForCurrentUser();
        echo json_encode($doctorId ? $this->appointmentRepo->getScheduleTemplates($doctorId) : []);
        exit();
    }

    public function apiSaveScheduleTemplate() {
        $this->requireDoctor();
        header('Content-Type: application/json');
        $doctorId = $this->getDoctorIdForCurrentUser();
        $body = json_decode(file_get_contents('php://input'), true);
        $name  = trim($body['name']       ?? '');
        $start = preg_replace('/[^0-9:]/', '', $body['start_time'] ?? '');
        $end   = preg_replace('/[^0-9:]/', '', $body['end_time']   ?? '');
        if (!$doctorId || !$name || !$start || !$end || $start >= $end) {
            echo json_encode(['success' => false, 'error' => 'Nieprawidłowe dane.']);
            exit();
        }
        try {
            $id = $this->appointmentRepo->saveScheduleTemplate($doctorId, $name, $start, $end);
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    public function apiDeleteScheduleTemplate() {
        $this->requireDoctor();
        header('Content-Type: application/json');
        $doctorId = $this->getDoctorIdForCurrentUser();
        $body = json_decode(file_get_contents('php://input'), true);
        $templateId = (int)($body['id'] ?? 0);
        if (!$doctorId || !$templateId) {
            echo json_encode(['success' => false, 'error' => 'Brakujące dane.']);
            exit();
        }
        $this->appointmentRepo->deleteScheduleTemplate($doctorId, $templateId);
        echo json_encode(['success' => true]);
        exit();
    }

    public function apiGetAvailableDates() {
        header('Content-Type: application/json');
        $doctorId  = (int)($_GET['doctor_id']  ?? 0);
        $startDate = $_GET['start_date'] ?? '';
        $endDate   = $_GET['end_date']   ?? '';
        if (!$doctorId || empty($startDate) || empty($endDate)) {
            echo json_encode([]);
            exit();
        }
        echo json_encode($this->appointmentRepo->getAvailableDatesInRange($doctorId, $startDate, $endDate));
        exit();
    }

    private function getDoctorIdForCurrentUser(): ?int {
        try {
            return $this->getDoctorIdByUserId($_SESSION['user_id']);
        } catch (Exception $e) {
            return null;
        }
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