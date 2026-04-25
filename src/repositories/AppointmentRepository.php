<?php
require_once 'Repository.php';

class AppointmentRepository extends Repository {

    public function createAppointment(int $userId, int $doctorId, string $date, string $time) {
        $db = $this->database->connect();
        
        try {
            $db->beginTransaction();

            // 1. Zalogowany jest USER, ale wizytę rezerwuje PACJENT.
            // Musimy pobrać id_patient na podstawie id_user z sesji.
            $stmt = $db->prepare('SELECT id FROM patients WHERE id_user = ?');
            $stmt->execute([$userId]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$patient) {
                throw new Exception("Nie znaleziono profilu pacjenta dla tego konta.");
            }

            $patientId = $patient['id'];

            // 2. Zapisujemy wizytę!
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
}