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

        if (!$this->isPost()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $this->verifyCsrf();

        $input = json_decode(file_get_contents('php://input'), true);
        $appointmentId = (int)($input['appointment_id'] ?? 0);
        $rating = (int)($input['rating'] ?? 0);
        $comment = trim($input['comment'] ?? '');

        if (!$appointmentId || $rating < 1 || $rating > 5) {
            $this->jsonResponse(['error' => 'Nieprawidłowe dane formularza.'], 400);
        }

        if ($this->reviewRepo->isReviewSubmitted($appointmentId)) {
            $this->jsonResponse(['error' => 'Opinia dla tej wizyty została już wystawiona.'], 409);
        }

        try {
            $this->reviewRepo->submitReview(
                $appointmentId,
                $_SESSION['user_id'],
                $rating,
                $comment ?: null
            );
            $this->jsonResponse(['success' => true]);
        } catch (Exception $e) {
            error_log('Review submit error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się zapisać opinii. Spróbuj ponownie.'], 500);
        }
    }

    public function reportReview() {
        $this->requireLogin();

        if (!$this->isPost()) {
            $this->jsonResponse(['error' => 'Niedozwolona metoda.'], 405);
        }

        $this->verifyCsrf();

        $input = json_decode(file_get_contents('php://input'), true);
        $reviewId = (int)($input['review_id'] ?? 0);
        $category = trim($input['category'] ?? '');
        $reason = trim($input['reason'] ?? '');

        if (!$reviewId || empty($category) || empty($reason)) {
            $this->jsonResponse(['error' => 'Wypełnij wszystkie pola zgłoszenia.'], 400);
        }

        try {
            $this->reviewRepo->reportReview(
                $reviewId,
                $_SESSION['user_id'],
                $category,
                $reason
            );
            $this->jsonResponse(['success' => true]);
        } catch (Exception $e) {
            error_log('Review report error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Nie udało się wysłać zgłoszenia.'], 500);
        }
    }

    public function apiGetDoctorProfile() {
        $this->requireLogin();

        $doctorId = (int)($_GET['id'] ?? 0);
        if (!$doctorId) {
            $this->jsonResponse(['error' => 'Brak ID lekarza.'], 400);
        }

        $profile = $this->reviewRepo->getDoctorProfileData($doctorId);
        if (!$profile) {
            $this->jsonResponse(['error' => 'Nie znaleziono lekarza.'], 404);
        }

        $summary = $this->reviewRepo->getReviewSummary($doctorId);
        $reviews = $this->reviewRepo->getDoctorReviews($doctorId, false);
        $nextSlot = $this->reviewRepo->getNextAvailableSlot($doctorId);

        $this->jsonResponse([
            'profile' => $profile,
            'summary' => $summary,
            'reviews' => $reviews,
            'next_slot' => $nextSlot
        ]);
    }
}
