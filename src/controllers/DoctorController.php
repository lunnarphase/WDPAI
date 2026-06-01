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

        if (!$this->isPost()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $this->verifyCsrf();

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->jsonResponse(['error' => 'Błędne dane wejściowe.'], 400);
        }

        $bio = trim($input['bio'] ?? '');
        $visitPrice = isset($input['visit_price']) && $input['visit_price'] !== '' ? (float)$input['visit_price'] : null;
        $visitDuration = isset($input['visit_duration']) && (int)$input['visit_duration'] > 0
            ? (int)$input['visit_duration']
            : 30;

        try {
            $this->reviewRepo->updateDoctorProfile(
                $_SESSION['user_id'],
                $bio,
                $visitPrice,
                $visitDuration
            );

            $this->jsonResponse(['success' => true]);
        } catch (Exception $e) {
            error_log('Doctor profile update error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się zapisać zmian profilu.'], 500);
        }
    }

    public function doctorUpdateStatus() {
        $this->requireDoctor();

        if ($this->isPost()) {
            $this->verifyCsrf();

            if (empty($_POST['appointment_id']) || empty($_POST['status'])) {
                $this->badRequest();
            }
            $appointmentId = (int)$_POST['appointment_id'];
            $status = $_POST['status'];
            $recommendations = $_POST['recommendations'] ?? null; 
            $userId = $_SESSION['user_id'];

            $this->appointmentRepo->updateAppointmentStatusByDoctor($appointmentId, $userId, $status, $recommendations);
        }
        
        header("Location: " . $this->getBaseUrl() . "/doctor-dashboard"); exit();
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
        $this->requireLogin();

        $doctorId = (int)($_GET['doctor_id'] ?? 0);
        $date = $_GET['date'] ?? '';

        if (!$doctorId || empty($date)) {
            $this->jsonResponse([]);
        }

        $this->jsonResponse($this->appointmentRepo->getAvailableSlotsForDate($doctorId, $date));
    }

    public function apiGetDoctorAppointments() {
        $this->requireDoctor();
        $appointments = $this->appointmentRepo->getDoctorAppointments($_SESSION['user_id']);
        $this->jsonResponse($appointments);
    }

    public function apiGetMyReviews() {
        $this->requireDoctor();

        $doctorId = $this->getDoctorIdForCurrentUser();
        if (!$doctorId) {
            $this->jsonResponse([
                'reviews' => [],
                'summary' => ['avg_rating' => 0, 'review_count' => 0],
            ]);
        }

        try {
            $this->jsonResponse([
                'reviews' => $this->reviewRepo->getDoctorReviews($doctorId, true),
                'summary' => $this->reviewRepo->getReviewSummary($doctorId),
            ]);
        } catch (Exception $e) {
            error_log('apiGetMyReviews error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się pobrać opinii.'], 500);
        }
    }

    public function apiGetWeekAvailability() {
        $this->requireDoctor();
        $doctorId = $this->getDoctorIdForCurrentUser();
        $weekStart = $_GET['week_start'] ?? '';
        $weekEnd   = $_GET['week_end']   ?? '';
        if (!$doctorId || empty($weekStart) || empty($weekEnd)) {
            $this->jsonResponse([]);
        }
        $this->jsonResponse($this->appointmentRepo->getDoctorAvailabilityForWeek($doctorId, $weekStart, $weekEnd));
    }

    public function apiSaveWeekAvailability() {
        $this->requireDoctor();
        $this->verifyCsrf();

        $doctorId = $this->getDoctorIdForCurrentUser();
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$doctorId || empty($body['week_start']) || empty($body['week_end'])) {
            $this->jsonResponse(['success' => false, 'error' => 'Brakujące dane.'], 400);
        }
        $ranges = $body['ranges'] ?? [];
        $today = date('Y-m-d');
        $skippedPastDays = 0;
        $clean = [];
        foreach ($ranges as $r) {
            $date  = preg_replace('/[^0-9\-]/', '', $r['date'] ?? '');
            $start = preg_replace('/[^0-9:]/', '', $r['start_time'] ?? '');
            $end   = preg_replace('/[^0-9:]/', '', $r['end_time']   ?? '');
            if (!$date || !$start || !$end || $start >= $end) {
                continue;
            }
            if ($date < $today) {
                $skippedPastDays++;
                continue;
            }
            $clean[] = ['date' => $date, 'start_time' => $start, 'end_time' => $end];
        }
        $effectiveStart = max($body['week_start'], $today);
        if ($effectiveStart > $body['week_end']) {
            $this->jsonResponse([
                'success' => true,
                'skipped_past_days' => $skippedPastDays,
                'message' => 'Nie można edytować harmonogramu z przeszłości.',
            ]);
        }
        try {
            $this->appointmentRepo->saveWeekAvailability($doctorId, $effectiveStart, $body['week_end'], $clean);
            $this->jsonResponse([
                'success' => true,
                'skipped_past_days' => $skippedPastDays,
            ]);
        } catch (Exception $e) {
            error_log('Save week availability error: ' . $e->getMessage());
            $this->jsonResponse(['success' => false, 'error' => 'Nie udało się zapisać grafiku.'], 500);
        }
    }

    public function apiGetScheduleTemplates() {
        $this->requireDoctor();
        $doctorId = $this->getDoctorIdForCurrentUser();
        $this->jsonResponse($doctorId ? $this->appointmentRepo->getScheduleTemplates($doctorId) : []);
    }

    public function apiSaveScheduleTemplate() {
        $this->requireDoctor();
        $this->verifyCsrf();

        $doctorId = $this->getDoctorIdForCurrentUser();
        $body = json_decode(file_get_contents('php://input'), true);
        $name  = trim($body['name']       ?? '');
        $start = preg_replace('/[^0-9:]/', '', $body['start_time'] ?? '');
        $end   = preg_replace('/[^0-9:]/', '', $body['end_time']   ?? '');
        if (!$doctorId || !$name || !$start || !$end || $start >= $end) {
            $this->jsonResponse(['success' => false, 'error' => 'Nieprawidłowe dane.'], 400);
        }
        try {
            $id = $this->appointmentRepo->saveScheduleTemplate($doctorId, $name, $start, $end);
            $this->jsonResponse(['success' => true, 'id' => $id]);
        } catch (Exception $e) {
            error_log('Save schedule template error: ' . $e->getMessage());
            $this->jsonResponse(['success' => false, 'error' => 'Nie udało się zapisać szablonu.'], 500);
        }
    }

    public function apiDeleteScheduleTemplate() {
        $this->requireDoctor();
        $this->verifyCsrf();

        $doctorId = $this->getDoctorIdForCurrentUser();
        $body = json_decode(file_get_contents('php://input'), true);
        $templateId = (int)($body['id'] ?? 0);
        if (!$doctorId || !$templateId) {
            $this->jsonResponse(['success' => false, 'error' => 'Brakujące dane.'], 400);
        }
        try {
            $this->appointmentRepo->deleteScheduleTemplate($doctorId, $templateId);
            $this->jsonResponse(['success' => true]);
        } catch (Exception $e) {
            error_log('Delete schedule template error: ' . $e->getMessage());
            $this->jsonResponse(['success' => false, 'error' => 'Nie udało się usunąć szablonu.'], 500);
        }
    }

    public function apiGetAvailableDates() {
        $this->requireLogin();

        $doctorId  = (int)($_GET['doctor_id']  ?? 0);
        $startDate = $_GET['start_date'] ?? '';
        $endDate   = $_GET['end_date']   ?? '';
        if (!$doctorId || empty($startDate) || empty($endDate)) {
            $this->jsonResponse([]);
        }
        $this->jsonResponse($this->appointmentRepo->getAvailableDatesInRange($doctorId, $startDate, $endDate));
    }

    private function getDoctorIdForCurrentUser(): ?int {
        try {
            return $this->getDoctorIdByUserId($_SESSION['user_id']);
        } catch (Exception $e) {
            return null;
        }
    }
}