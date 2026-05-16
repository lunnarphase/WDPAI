<?php

require_once 'Repository.php';

class ReviewRepository extends Repository {

    public function hasReview(int $appointmentId): bool {
        $db = $this->database->connect();
        $stmt = $db->prepare('SELECT id FROM reviews WHERE id_appointment = ?');
        $stmt->execute([$appointmentId]);
        return (bool)$stmt->fetch();
    }

    public function isReviewSubmitted(int $appointmentId): bool {
        $db = $this->database->connect();
        $stmt = $db->prepare('SELECT review_submitted FROM appointments WHERE id = ?');
        $stmt->execute([$appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && (bool)$row['review_submitted'];
    }

    public function submitReview(int $appointmentId, int $userId, int $rating, ?string $comment): void {
        $db = $this->database->connect();

        $stmt = $db->prepare('
            SELECT a.id_doctor, p.id as patient_id
            FROM appointments a
            JOIN patients p ON a.id_patient = p.id
            WHERE a.id = ? AND p.id_user = ? AND a.status = \'completed\'
        ');
        $stmt->execute([$appointmentId, $userId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            throw new Exception('Nie znaleziono zakończonej wizyty lub brak uprawnień.');
        }

        $stmt = $db->prepare('
            INSERT INTO reviews (id_appointment, id_doctor, id_patient, rating, comment)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$appointmentId, $data['id_doctor'], $data['patient_id'], $rating, $comment]);

        // Oznacz wizytę jako ocenioną (nieodwracalnie, nawet po usunięciu opinii)
        $stmt = $db->prepare('UPDATE appointments SET review_submitted = TRUE WHERE id = ?');
        $stmt->execute([$appointmentId]);

        // Powiadomienie dla lekarza o nowej opinii
        $doctorUserStmt = $db->prepare('SELECT id_user FROM doctors WHERE id = ?');
        $doctorUserStmt->execute([$data['id_doctor']]);
        $doctorUserId = $doctorUserStmt->fetchColumn();
        if ($doctorUserId) {
            $notifStmt = $db->prepare("INSERT INTO notifications (id_user, message, type, related_id) VALUES (?, 'Otrzymałeś nową ocenę od pacjenta. Sprawdź zakładkę Twoje opinie.', 'new_review', ?)");
            $notifStmt->execute([$doctorUserId, $data['id_doctor']]);
        }
    }

    public function getDoctorReviews(int $doctorId, bool $showFullName = false): array {
        $db = $this->database->connect();

        if ($showFullName) {
            $nameExpr = "u.username as patient_display_name";
        } else {
            $nameExpr = "UPPER(SUBSTR(u.username, 1, 1)) || '*****' as patient_display_name";
        }

        $stmt = $db->prepare("
            SELECT r.id, r.rating, r.comment, r.created_at,
                   $nameExpr,
                   (SELECT COUNT(*) FROM review_reports rr WHERE rr.id_review = r.id AND rr.status = 'pending') as pending_reports
            FROM reviews r
            JOIN patients p ON r.id_patient = p.id
            JOIN users u ON p.id_user = u.id
            WHERE r.id_doctor = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$doctorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getReviewSummary(int $doctorId): array {
        $db = $this->database->connect();
        $stmt = $db->prepare('
            SELECT
                COALESCE(ROUND(AVG(rating)::NUMERIC, 1), 0) as avg_rating,
                COUNT(*) as review_count
            FROM reviews
            WHERE id_doctor = ?
        ');
        $stmt->execute([$doctorId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['avg_rating' => 0, 'review_count' => 0];
    }

    public function reportReview(int $reviewId, int $reporterUserId, string $category, string $reason): void {
        $db = $this->database->connect();

        $stmt = $db->prepare('SELECT id FROM reviews WHERE id = ?');
        $stmt->execute([$reviewId]);
        if (!$stmt->fetch()) {
            throw new Exception('Opinia nie istnieje.');
        }

        $stmt = $db->prepare('
            INSERT INTO review_reports (id_review, id_reporter, category, reason)
            VALUES (?, ?, ?, ?) RETURNING id
        ');
        $stmt->execute([$reviewId, $reporterUserId, $category, $reason]);
        $reportId = $stmt->fetchColumn();

        // Powiadomienie dla wszystkich administratorów
        $adminStmt = $db->prepare("SELECT u.id FROM users u JOIN roles r ON u.id_role = r.id WHERE r.name = 'admin'");
        $adminStmt->execute();
        $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);

        $notifStmt = $db->prepare('
            INSERT INTO notifications (id_user, message, type, related_id)
            VALUES (?, \'Nowe zgłoszenie opinii oczekuje na Twoją weryfikację.\', \'report\', ?)
        ');
        foreach ($admins as $adminId) {
            $notifStmt->execute([$adminId, $reviewId]);
        }
    }

    public function getAllReviewsAdmin(
        string $patientFilter = '',
        string $doctorFilter = '',
        string $dateFilter = '',
        int $ratingFilter = 0
    ): array {
        $db = $this->database->connect();

        $sql = "
            SELECT r.id, r.rating, r.comment, r.created_at,
                   u_patient.username as patient_name,
                   u_doctor.username as doctor_name,
                   (SELECT COUNT(*) FROM review_reports rr WHERE rr.id_review = r.id AND rr.status = 'pending') as pending_reports
            FROM reviews r
            JOIN patients p ON r.id_patient = p.id
            JOIN users u_patient ON p.id_user = u_patient.id
            JOIN doctors d ON r.id_doctor = d.id
            JOIN users u_doctor ON d.id_user = u_doctor.id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($patientFilter)) {
            $sql .= " AND u_patient.username ILIKE :patient";
            $params[':patient'] = '%' . $patientFilter . '%';
        }
        if (!empty($doctorFilter)) {
            $sql .= " AND u_doctor.username ILIKE :doctor";
            $params[':doctor'] = '%' . $doctorFilter . '%';
        }
        if (!empty($dateFilter)) {
            $sql .= " AND DATE(r.created_at) = :date";
            $params[':date'] = $dateFilter;
        }
        if ($ratingFilter > 0) {
            $sql .= " AND r.rating = :rating";
            $params[':rating'] = $ratingFilter;
        }

        $sql .= " ORDER BY r.created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteReviewAdmin(int $reviewId): void {
        $db = $this->database->connect();
        $stmt = $db->prepare('DELETE FROM reviews WHERE id = ?');
        $stmt->execute([$reviewId]);
    }

    public function getPendingReportsForReview(int $reviewId): array {
        $db = $this->database->connect();
        $stmt = $db->prepare("
            SELECT rr.id, rr.category, rr.reason, rr.status, rr.created_at,
                   u_reporter.username as reporter_name
            FROM review_reports rr
            JOIN users u_reporter ON rr.id_reporter = u_reporter.id
            WHERE rr.id_review = ? AND rr.status = 'pending'
            ORDER BY rr.created_at DESC
        ");
        $stmt->execute([$reviewId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function dismissReport(int $reportId, string $adminResponse): void {
        $db = $this->database->connect();

        $stmt = $db->prepare('SELECT id_reporter FROM review_reports WHERE id = ?');
        $stmt->execute([$reportId]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) return;

        $stmt = $db->prepare("UPDATE review_reports SET status = 'dismissed', admin_response = ? WHERE id = ?");
        $stmt->execute([$adminResponse, $reportId]);

        $msg = 'Twoje zgłoszenie opinii zostało rozpatrzone przez administratora. Odpowiedź: ' . $adminResponse;
        $stmt = $db->prepare("INSERT INTO notifications (id_user, message, type) VALUES (?, ?, 'report_dismissed')");
        $stmt->execute([$report['id_reporter'], $msg]);
    }

    public function resolveReportByDeletion(int $reviewId): void {
        $db = $this->database->connect();

        // Zbierz reporterów przed usunięciem
        $stmt = $db->prepare("SELECT DISTINCT id_reporter FROM review_reports WHERE id_review = ? AND status = 'pending'");
        $stmt->execute([$reviewId]);
        $reporters = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Usuń opinię — kaskadowo usunie też review_reports
        $stmt = $db->prepare('DELETE FROM reviews WHERE id = ?');
        $stmt->execute([$reviewId]);

        // Powiadomienie dla zgłaszających
        $notifStmt = $db->prepare("INSERT INTO notifications (id_user, message, type) VALUES (?, 'Twoje zgłoszenie opinii zostało rozpatrzone. Opinia, którą zgłosiłeś/-aś, została usunięta z systemu.', 'report_resolved')");
        foreach ($reporters as $reporterId) {
            $notifStmt->execute([$reporterId]);
        }
    }

    public function getNextAvailableSlot(int $doctorId): ?string {
        $db = $this->database->connect();
        $slots = ['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '13:00', '13:30', '14:00', '14:30', '15:00'];

        $date = new DateTime('today');
        for ($i = 0; $i < 60; $i++) {
            $dateStr = $date->format('Y-m-d');

            $stmt = $db->prepare("
                SELECT appointment_time FROM appointments
                WHERE id_doctor = ? AND appointment_date = ? AND status != 'cancelled'
            ");
            $stmt->execute([$doctorId, $dateStr]);
            $takenRaw = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $taken = array_map(fn($t) => substr($t, 0, 5), $takenRaw);

            foreach ($slots as $slot) {
                if (!in_array($slot, $taken)) {
                    return $dateStr . ' ' . $slot;
                }
            }
            $date->modify('+1 day');
        }
        return null;
    }

    public function getDoctorProfileData(int $doctorId): ?array {
        $db = $this->database->connect();
        $stmt = $db->prepare("
            SELECT d.id, d.bio, d.visit_price, d.visit_duration, u.username as name,
                   STRING_AGG(s.name, ', ') as specializations
            FROM doctors d
            JOIN users u ON d.id_user = u.id
            LEFT JOIN doctors_specializations ds ON d.id = ds.id_doctor
            LEFT JOIN specializations s ON ds.id_specialization = s.id
            WHERE d.id = ?
            GROUP BY d.id, d.bio, d.visit_price, d.visit_duration, u.username
        ");
        $stmt->execute([$doctorId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateDoctorProfile(int $userId, string $bio, ?float $visitPrice, int $visitDuration): void {
        $db = $this->database->connect();
        $stmt = $db->prepare('UPDATE doctors SET bio = ?, visit_price = ?, visit_duration = ? WHERE id_user = ?');
        $stmt->execute([$bio, $visitPrice, $visitDuration, $userId]);
    }

    public function getDoctorIdByUserId(int $userId): int {
        $db = $this->database->connect();
        $stmt = $db->prepare('SELECT id FROM doctors WHERE id_user = ?');
        $stmt->execute([$userId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }
}
