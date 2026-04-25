<?php

require_once 'Repository.php';

class AppointmentRepository extends Repository {

    public function createAppointment(int $userId, int $doctorId, string $date, string $time) {
        $db = $this->database->connect();
        
        try {
            $db->beginTransaction();

            $stmt = $db->prepare('SELECT id FROM patients WHERE id_user = ?');
            $stmt->execute([$userId]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$patient) {
                throw new Exception("Nie znaleziono profilu pacjenta dla tego konta.");
            }

            $patientId = $patient['id'];

            // SPRAWDZANIE KOLIZJI (zapobiega podwójnym rezerwacjom)
            $checkStmt = $db->prepare('SELECT id FROM appointments WHERE id_doctor = ? AND appointment_date = ? AND appointment_time = ? AND status != \'cancelled\'');
            $checkStmt->execute([$doctorId, $date, $time]);
            if ($checkStmt->fetch()) {
                throw new Exception("Przykro nam, ale ten termin został przed chwilą zarezerwowany przez inną osobę.");
            }

            $stmt = $db->prepare('
                INSERT INTO appointments (id_patient, id_doctor, appointment_date, appointment_time, status)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$patientId, $doctorId, $date, $time, 'confirmed']);

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function getUpcomingAppointmentsForPatient(int $userId): array {
        $db = $this->database->connect();
        $stmt = $db->prepare('
            SELECT 
                a.id as appointment_id,
                a.appointment_date, 
                a.appointment_time, 
                a.status,
                u.username as doctor_name,
                (SELECT STRING_AGG(s.name, \', \') 
                 FROM doctors_specializations ds 
                 JOIN specializations s ON ds.id_specialization = s.id 
                 WHERE ds.id_doctor = d.id) as specializations
            FROM appointments a
            JOIN doctors d ON a.id_doctor = d.id
            JOIN users u ON d.id_user = u.id
            JOIN patients p ON a.id_patient = p.id
            WHERE p.id_user = :user_id 
            ORDER BY a.id DESC
        ');
        
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cancelAppointment(int $appointmentId, int $userId, string $reason, string $comment) {
        $db = $this->database->connect();
        // Zabezpieczenie: Sprawdzamy czy to pacjent przypisany do konta usera anuluje
        $stmt = $db->prepare('
            UPDATE appointments 
            SET status = \'cancelled\', cancel_reason = :reason, cancel_comment = :comment
            WHERE id = :id AND id_patient = (SELECT id FROM patients WHERE id_user = :user_id)
        ');
        $stmt->execute([
            ':id' => $appointmentId,
            ':user_id' => $userId,
            ':reason' => $reason,
            ':comment' => $comment
        ]);
    }

    // Metoda dla kalendarza - pobiera zajęte godziny w zadanym przedziale dat
    public function getBookedSlots(int $doctorId, string $startDate, string $endDate): array {
        $db = $this->database->connect();
        $stmt = $db->prepare("
            SELECT appointment_date, appointment_time 
            FROM appointments 
            WHERE id_doctor = :doctor_id 
              AND appointment_date >= :start_date 
              AND appointment_date <= :end_date
              AND status != 'cancelled'
        ");
        $stmt->execute([
            ':doctor_id' => $doctorId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}