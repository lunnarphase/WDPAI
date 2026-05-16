<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/ReviewRepository.php';

class ReviewController extends AppController {

    private $reviewRepo;

    public function __construct(ReviewRepository $reviewRepo = null) {
        parent::__construct();
        $this->reviewRepo = $reviewRepo ?: new ReviewRepository();
    }

    public function submitReview() {
        $this->requireLogin();
        header('Content-Type: application/json');

        if (!$this->isPost()) {
            echo json_encode(['error' => 'Niedozwolona metoda.']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $appointmentId = (int)($input['appointment_id'] ?? 0);
        $rating = (int)($input['rating'] ?? 0);
        $comment = trim($input['comment'] ?? '');

        if (!$appointmentId || $rating < 1 || $rating > 5) {
            echo json_encode(['error' => 'Nieprawidłowe dane formularza.']);
            exit();
        }

        if ($this->reviewRepo->isReviewSubmitted($appointmentId)) {
            echo json_encode(['error' => 'Opinia dla tej wizyty została już wystawiona.']);
            exit();
        }

        try {
            $this->reviewRepo->submitReview(
                $appointmentId,
                $_SESSION['user_id'],
                $rating,
                $comment ?: null
            );
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit();
    }

    public function reportReview() {
        $this->requireLogin();
        header('Content-Type: application/json');

        if (!$this->isPost()) {
            echo json_encode(['error' => 'Niedozwolona metoda.']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $reviewId = (int)($input['review_id'] ?? 0);
        $category = trim($input['category'] ?? '');
        $reason = trim($input['reason'] ?? '');

        if (!$reviewId || empty($category) || empty($reason)) {
            echo json_encode(['error' => 'Wypełnij wszystkie pola zgłoszenia.']);
            exit();
        }

        try {
            $this->reviewRepo->reportReview(
                $reviewId,
                $_SESSION['user_id'],
                $category,
                $reason
            );
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit();
    }

    public function apiGetDoctorProfile() {
        $this->requireLogin();
        header('Content-Type: application/json');

        $doctorId = (int)($_GET['id'] ?? 0);
        if (!$doctorId) {
            echo json_encode(['error' => 'Brak ID lekarza.']);
            exit();
        }

        $profile = $this->reviewRepo->getDoctorProfileData($doctorId);
        if (!$profile) {
            echo json_encode(['error' => 'Nie znaleziono lekarza.']);
            exit();
        }

        $summary = $this->reviewRepo->getReviewSummary($doctorId);
        $reviews = $this->reviewRepo->getDoctorReviews($doctorId, false);
        $nextSlot = $this->reviewRepo->getNextAvailableSlot($doctorId);

        echo json_encode([
            'profile' => $profile,
            'summary' => $summary,
            'reviews' => $reviews,
            'next_slot' => $nextSlot
        ]);
        exit();
    }
}
