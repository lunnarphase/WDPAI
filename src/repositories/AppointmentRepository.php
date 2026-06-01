<?php

require_once 'Repository.php';

class AppointmentRepository extends Repository {

    private function getBusinessTimezone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Warsaw');
    }

    private function buildSlotDateTime(string $date, string $time): ?DateTimeImmutable
    {
        $normalizedDate = trim($date);
        $normalizedTime = trim($time);

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $normalizedTime) === 1) {
            $normalizedTime = substr($normalizedTime, 0, 5);
        }

        $slot = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $normalizedDate . ' ' . $normalizedTime,
            $this->getBusinessTimezone()
        );

        $errors = DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors)
            && ((int)($errors['warning_count'] ?? 0) > 0 || (int)($errors['error_count'] ?? 0) > 0);

        if ($slot === false || $hasErrors) {
            return null;
        }

        return $slot;
    }

    private function assertBookableSlot(string $date, string $time): array
    {
        $slot = $this->buildSlotDateTime($date, $time);
        if ($slot === null) {
            throw new RuntimeException('Przepraszamy, ale wybrany termin nie jest już dostępny. Proszę wybrać inny termin.');
        }

        $now = new DateTimeImmutable('now', $this->getBusinessTimezone());
        if ($slot <= $now) {
            throw new RuntimeException('Przepraszamy, ale wybrany termin już minął. Wybierz późniejszą godzinę.');
        }

        return [
            'date' => $slot->format('Y-m-d'),
            'time' => $slot->format('H:i'),
        ];
    }

    private function isSlotInFuture(string $date, string $time, DateTimeImmutable $now): bool
    {
        $slot = $this->buildSlotDateTime($date, $time);
        return $slot !== null && $slot > $now;
    }

    public function createAppointment(int $userId, int $doctorId, string $date, string $time) {
        $db = $this->database->connect();

        $normalized = $this->assertBookableSlot($date, $time);
        $date = $normalized['date'];
        $time = $normalized['time'];
        
        try {
            $this->beginTransactionWithIsolation($db, 'SERIALIZABLE');

            $stmt = $db->prepare('SELECT id FROM patients WHERE id_user = ?');
            $stmt->execute([$userId]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$patient) {
                throw new Exception("Nie znaleziono profilu pacjenta dla tego konta.");
            }

            $patientId = $patient['id'];

            $checkStmt = $db->prepare('SELECT id FROM appointments WHERE id_doctor = ? AND appointment_date = ? AND appointment_time = ? AND status != \'cancelled\'');
            $checkStmt->execute([$doctorId, $date, $time]);
            if ($checkStmt->fetch()) {
                throw new RuntimeException('Przepraszamy, ale ktoś inny właśnie zarezerwował ten termin.');
            }

            $availStmt = $db->prepare('SELECT COUNT(*) FROM doctor_availability WHERE id_doctor = ? AND date = ? AND start_time <= ? AND end_time > ?');
            $availStmt->execute([$doctorId, $date, $time . ':00', $time . ':00']);
            if ((int)$availStmt->fetchColumn() === 0) {
                throw new RuntimeException('Przepraszamy, ale wybrany termin nie jest już dostępny. Proszę wybrać inny termin.');
            }

            $stmt = $db->prepare('
                INSERT INTO appointments (id_patient, id_doctor, appointment_date, appointment_time, status)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$patientId, $doctorId, $date, $time, 'confirmed']);

            $db->commit();
            return true;
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            // Race condition safety: DB-level unique slot guard may reject concurrent inserts.
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw new RuntimeException('Przepraszamy, ale ktoś inny właśnie zarezerwował ten termin.');
            }

            throw $e;
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function getUpcomingAppointmentsForPatient(int $userId): array {
        $db = $this->database->connect();
        $stmt = $db->prepare("
            SELECT
                a.id as appointment_id,
                a.appointment_date,
                a.appointment_time,
                a.status,
                a.recommendations,
                u.username as doctor_name,
                d.id as doctor_id,
                (SELECT STRING_AGG(s.name, ', ')
                 FROM doctors_specializations ds
                 JOIN specializations s ON ds.id_specialization = s.id
                 WHERE ds.id_doctor = d.id) as specializations,
                (SELECT COUNT(*) FROM reviews r WHERE r.id_appointment = a.id) > 0 as has_review,
                a.review_submitted
            FROM appointments a
            JOIN doctors d ON a.id_doctor = d.id
            JOIN users u ON d.id_user = u.id
            JOIN patients p ON a.id_patient = p.id
            WHERE p.id_user = :user_id
            ORDER BY
                (CASE WHEN a.status = 'completed' AND a.review_submitted = FALSE THEN 0 ELSE 1 END),
                a.id DESC
        ");
        
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cancelAppointment(int $appointmentId, int $userId, string $reason, string $comment) {
        $db = $this->database->connect();
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

   public function getAllAppointmentsAdmin(): array {
        $db = $this->database->connect();
        $stmt = $db->prepare('
            SELECT 
                a.id,
                a.id_patient,
                a.id_doctor,
                a.appointment_date, 
                a.appointment_time, 
                a.status,
                pu.username as patient_name,
                du.username as doctor_name
            FROM appointments a
            JOIN patients p ON a.id_patient = p.id
            JOIN users pu ON p.id_user = pu.id
            JOIN doctors d ON a.id_doctor = d.id
            JOIN users du ON d.id_user = du.id
            ORDER BY a.appointment_date DESC, a.appointment_time DESC
        ');
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateAppointmentAdmin(int $id, int $patientId, int $doctorId, string $date, string $time, string $status) {
        $db = $this->database->connect();
        $stmt = $db->prepare('
            UPDATE appointments 
            SET id_patient = ?, id_doctor = ?, appointment_date = ?, appointment_time = ?, status = ?
            WHERE id = ?
        ');
        $stmt->execute([$patientId, $doctorId, $date, $time, $status, $id]);
    }

    public function deleteAppointmentAdmin(int $id) {
        $db = $this->database->connect();
        $stmt = $db->prepare('DELETE FROM appointments WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function getTodaysAppointmentsCount(): int {
        $db = $this->database->connect();
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM appointments 
            WHERE appointment_date = CURRENT_DATE 
              AND status != 'cancelled'
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? (int)$result['count'] : 0;
    }

    public function getDoctorAppointments(int $userId): array {
        $db = $this->database->connect();
        $stmt = $db->prepare('
            SELECT 
                a.id,
                a.appointment_date, 
                a.appointment_time, 
                a.status,
                a.recommendations,
                pu.username as patient_name,
                p.pesel as patient_pesel
            FROM appointments a
            JOIN patients p ON a.id_patient = p.id
            JOIN users pu ON p.id_user = pu.id
            JOIN doctors d ON a.id_doctor = d.id
            WHERE d.id_user = :user_id
            ORDER BY a.appointment_date DESC, a.appointment_time DESC
        ');
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDoctorStats(int $userId): array {
        $db = $this->database->connect();
        
        $stmtToday = $db->prepare("
            SELECT COUNT(*) FROM appointments a
            JOIN doctors d ON a.id_doctor = d.id
            WHERE d.id_user = ? AND a.appointment_date = CURRENT_DATE AND a.status != 'cancelled'
        ");
        $stmtToday->execute([$userId]);
        $todayCount = $stmtToday->fetchColumn();

        $stmtUpcoming = $db->prepare("
            SELECT COUNT(*) FROM appointments a
            JOIN doctors d ON a.id_doctor = d.id
            WHERE d.id_user = ? AND a.appointment_date >= CURRENT_DATE AND a.status = 'confirmed'
        ");
        $stmtUpcoming->execute([$userId]);
        $upcomingCount = $stmtUpcoming->fetchColumn();

        return [
            'today' => (int)$todayCount,
            'upcoming' => (int)$upcomingCount
        ];
    }

    public function updateAppointmentStatusByDoctor(int $appointmentId, int $userId, string $status, ?string $recommendations) {
        $db = $this->database->connect();
        
        try {
            $this->beginTransactionWithIsolation($db, 'READ COMMITTED');

            $stmt = $db->prepare('
                UPDATE appointments 
                SET status = ?, recommendations = ? 
                WHERE id = ? AND id_doctor = (SELECT id FROM doctors WHERE id_user = ?)
            ');
            $stmt->execute([$status, $recommendations, $appointmentId, $userId]);

            if ($status === 'completed' && !empty($recommendations)) {
                $stmt2 = $db->prepare('SELECT p.id_user FROM appointments a JOIN patients p ON a.id_patient = p.id WHERE a.id = ?');
                $stmt2->execute([$appointmentId]);
                $patientUserId = $stmt2->fetchColumn();

                if ($patientUserId) {
                    $msg = "Lekarz dodał zalecenia do Twojej zakończonej wizyty. Sprawdź szczegóły w historii wizyt.";
                    $stmt3 = $db->prepare('INSERT INTO notifications (id_user, message, type) VALUES (?, ?, \'general\')');
                    $stmt3->execute([$patientUserId, $msg]);
                }
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function getTakenSlots(int $doctorId, string $date): array {
        $db = $this->database->connect();
        $stmt = $db->prepare('
            SELECT appointment_time 
            FROM appointments 
            WHERE id_doctor = :doctor_id AND appointment_date = :date AND status != :status
        ');
        $cancelledStatus = 'cancelled';
        $stmt->bindParam(':doctor_id', $doctorId, PDO::PARAM_INT);
        $stmt->bindParam(':date', $date, PDO::PARAM_STR);
        $stmt->bindParam(':status', $cancelledStatus, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getAppointmentsInRange(int $doctorId, string $startDate, string $endDate): array {
        $db = $this->database->connect();
        $stmt = $db->prepare('
            SELECT appointment_date, appointment_time
            FROM appointments
            WHERE id_doctor = :doctor_id
              AND appointment_date BETWEEN :start AND :end
              AND status != \'cancelled\'
        ');
        $stmt->bindParam(':doctor_id', $doctorId, PDO::PARAM_INT);
        $stmt->bindParam(':start', $startDate, PDO::PARAM_STR);
        $stmt->bindParam(':end', $endDate, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUserNotifications(int $userId): array {
        $db = $this->database->connect();
        $stmt = $db->prepare('
            SELECT id, message, is_read, created_at, type, related_id
            FROM notifications
            WHERE id_user = :user_id
            ORDER BY
                CASE WHEN type = \'global_ip_attack\' THEN 0 ELSE 1 END,
                created_at DESC
            LIMIT 50
        ');
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUnreadNotificationsCount(int $userId): int {
        $db = $this->database->connect();
        $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE id_user = :user_id AND is_read = FALSE');
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    public function markNotificationsAsRead(int $userId): void {
        $db = $this->database->connect();
        $stmt = $db->prepare('UPDATE notifications SET is_read = TRUE WHERE id_user = :user_id');
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function clearNotifications(int $userId): int {
        $db = $this->database->connect();
        $stmt = $db->prepare('DELETE FROM notifications WHERE id_user = :user_id');
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function deleteNotification(int $notifId, int $userId): bool {
        $db = $this->database->connect();
        $stmt = $db->prepare('DELETE FROM notifications WHERE id = :id AND id_user = :user_id');
        $stmt->execute([':id' => $notifId, ':user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    // =================== DOCTOR AVAILABILITY ===================

    public function getDoctorAvailabilityForWeek(int $doctorId, string $weekStart, string $weekEnd): array {
        $db = $this->database->connect();
        $stmt = $db->prepare("
            SELECT CAST(date AS TEXT) AS date, CAST(start_time AS TEXT) AS start_time, CAST(end_time AS TEXT) AS end_time
            FROM doctor_availability
            WHERE id_doctor = ? AND date >= ? AND date <= ?
            ORDER BY date, start_time
        ");
        $stmt->execute([$doctorId, $weekStart, $weekEnd]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveWeekAvailability(int $doctorId, string $weekStart, string $weekEnd, array $dayRanges): void {
        $db = $this->database->connect();
        $this->beginTransactionWithIsolation($db, 'REPEATABLE READ');
        try {
            $db->prepare("DELETE FROM doctor_availability WHERE id_doctor = ? AND date >= ? AND date <= ?")
               ->execute([$doctorId, $weekStart, $weekEnd]);
            $stmt = $db->prepare("INSERT INTO doctor_availability (id_doctor, date, start_time, end_time) VALUES (?, ?, ?, ?)");
            foreach ($dayRanges as $r) {
                $stmt->execute([$doctorId, $r['date'], $r['start_time'], $r['end_time']]);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function getAvailableSlotsForDate(int $doctorId, string $date): array {
        $db = $this->database->connect();
        $stmt = $db->prepare("
            SELECT CAST(start_time AS TEXT) AS start_time, CAST(end_time AS TEXT) AS end_time
            FROM doctor_availability
            WHERE id_doctor = ? AND date = ?
            ORDER BY start_time
        ");
        $stmt->execute([$doctorId, $date]);
        $ranges = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($ranges)) return [];

        $slots = [];
        foreach ($ranges as $r) {
            $start = strtotime($r['start_time']);
            $end   = strtotime($r['end_time']);
            for ($t = $start; $t + 1800 <= $end; $t += 1800) {
                $slots[] = date('H:i', $t);
            }
        }
        $slots = array_values(array_unique($slots));
        sort($slots);

        $stmt = $db->prepare("
            SELECT CAST(appointment_time AS TEXT) AS t
            FROM appointments
            WHERE id_doctor = ? AND appointment_date = ? AND status != 'cancelled'
        ");
        $stmt->execute([$doctorId, $date]);
        $booked = array_map(fn($v) => substr($v, 0, 5), $stmt->fetchAll(PDO::FETCH_COLUMN));

        $now = new DateTimeImmutable('now', $this->getBusinessTimezone());

        return array_values(array_filter($slots, function ($slot) use ($booked, $date, $now) {
            if (in_array($slot, $booked, true)) {
                return false;
            }

            return $this->isSlotInFuture($date, $slot, $now);
        }));
    }

    public function getAvailableDatesInRange(int $doctorId, string $startDate, string $endDate): array {
        $db = $this->database->connect();
        $stmt = $db->prepare("
            SELECT CAST(date AS TEXT) AS date, CAST(start_time AS TEXT) AS start_time, CAST(end_time AS TEXT) AS end_time
            FROM doctor_availability
            WHERE id_doctor = ? AND date >= ? AND date <= ?
            ORDER BY date, start_time
        ");
        $stmt->execute([$doctorId, $startDate, $endDate]);
        $allRanges = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($allRanges)) return [];

        $stmt = $db->prepare("
            SELECT CAST(appointment_date AS TEXT) AS date, CAST(appointment_time AS TEXT) AS time
            FROM appointments
            WHERE id_doctor = ? AND appointment_date >= ? AND appointment_date <= ? AND status != 'cancelled'
        ");
        $stmt->execute([$doctorId, $startDate, $endDate]);
        $booked = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bookedMap = [];
        foreach ($booked as $b) {
            $bookedMap[$b['date']][] = substr($b['time'], 0, 5);
        }

        $byDate = [];
        foreach ($allRanges as $r) {
            $byDate[$r['date']][] = $r;
        }

        $now = new DateTimeImmutable('now', $this->getBusinessTimezone());
        $available = [];
        foreach ($byDate as $date => $ranges) {
            $taken = $bookedMap[$date] ?? [];
            foreach ($ranges as $r) {
                $start = strtotime($r['start_time']);
                $end   = strtotime($r['end_time']);
                for ($t = $start; $t + 1800 <= $end; $t += 1800) {
                    $slot = date('H:i', $t);
                    if (!$this->isSlotInFuture($date, $slot, $now)) {
                        continue;
                    }

                    if (!in_array($slot, $taken, true)) {
                        $available[] = $date;
                        break 2;
                    }
                }
            }
        }
        return $available;
    }

    // =================== SCHEDULE TEMPLATES ===================

    public function getScheduleTemplates(int $doctorId): array {
        $db = $this->database->connect();
        $stmt = $db->prepare("
            SELECT id, name, CAST(start_time AS TEXT) AS start_time, CAST(end_time AS TEXT) AS end_time
            FROM doctor_schedule_templates
            WHERE id_doctor = ?
            ORDER BY name
        ");
        $stmt->execute([$doctorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveScheduleTemplate(int $doctorId, string $name, string $startTime, string $endTime): int {
        $db = $this->database->connect();
        $stmt = $db->prepare("
            INSERT INTO doctor_schedule_templates (id_doctor, name, start_time, end_time)
            VALUES (?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([$doctorId, $name, $startTime, $endTime]);
        return (int)$stmt->fetchColumn();
    }

    public function deleteScheduleTemplate(int $doctorId, int $templateId): void {
        $db = $this->database->connect();
        $db->prepare("DELETE FROM doctor_schedule_templates WHERE id = ? AND id_doctor = ?")
           ->execute([$templateId, $doctorId]);
    }
}