<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/repositories/AppointmentRepository.php';

class SqliteNotificationsDatabase extends Database
{
    private ?PDO $connection = null;

    public function __construct()
    {
    }

    public function connect()
    {
        if ($this->connection === null) {
            $this->connection = new PDO('sqlite::memory:');
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->bootstrapSchema($this->connection);
        }

        return $this->connection;
    }

    private function bootstrapSchema(PDO $db): void
    {
        $db->exec('
            CREATE TABLE notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                id_user INTEGER NOT NULL,
                message TEXT NOT NULL,
                is_read INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT "general",
                related_id INTEGER NULL
            )
        ');

        $db->exec('
            CREATE TABLE patients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                id_user INTEGER NOT NULL,
                pesel TEXT NULL
            )
        ');

        $db->exec('
            CREATE TABLE doctors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                id_user INTEGER NOT NULL,
                bio TEXT NULL
            )
        ');

        $db->exec('
            CREATE TABLE appointments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                id_patient INTEGER NOT NULL,
                id_doctor INTEGER NOT NULL,
                appointment_date TEXT NOT NULL,
                appointment_time TEXT NOT NULL,
                status TEXT NOT NULL,
                recommendations TEXT NULL,
                cancel_reason TEXT NULL,
                cancel_comment TEXT NULL,
                review_submitted INTEGER NOT NULL DEFAULT 0
            )
        ');

        $db->exec('
            CREATE TABLE doctor_availability (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                id_doctor INTEGER NOT NULL,
                date TEXT NOT NULL,
                start_time TEXT NOT NULL,
                end_time TEXT NOT NULL
            )
        ');

        $db->exec("INSERT INTO patients (id, id_user, pesel) VALUES (1, 1, '01234567890'), (2, 2, '10987654321')");
        $db->exec("INSERT INTO doctors (id, id_user, bio) VALUES (1, 100, 'Cardio'), (2, 101, 'Ortho')");
    }
}

class AppointmentRepositoryNotificationsTest extends TestCase
{
    private AppointmentRepository $repository;
    private PDO $pdo;

    protected function setUp(): void
    {
        $database = new SqliteNotificationsDatabase();
        $this->repository = new AppointmentRepository($database);
        $this->pdo = $database->connect();
    }

    public function testGetUserNotificationsLimitsToFiftyAndPrioritizesGlobalAttack(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $createdAt = date('Y-m-d H:i:s', strtotime('2026-01-01 00:00:00') + $i);
            $this->insertNotification(1, 'general message ' . $i, false, $createdAt, 'general');
        }

        $this->insertNotification(1, 'critical attack', false, '2025-12-31 23:59:59', 'global_ip_attack');
        $this->insertNotification(2, 'another user notification', false, '2026-01-01 10:00:00', 'general');

        $notifications = $this->repository->getUserNotifications(1);

        $this->assertCount(50, $notifications);
        $this->assertSame('global_ip_attack', $notifications[0]['type']);
        $this->assertSame('general message 60', $notifications[1]['message']);
        $this->assertSame('general message 12', $notifications[49]['message']);
    }

    public function testGetUnreadNotificationsCountReturnsOnlyUnreadForGivenUser(): void
    {
        $this->insertNotification(1, 'u1 unread 1', false, '2026-01-01 08:00:00');
        $this->insertNotification(1, 'u1 unread 2', false, '2026-01-01 08:01:00');
        $this->insertNotification(1, 'u1 read', true, '2026-01-01 08:02:00');
        $this->insertNotification(2, 'u2 unread', false, '2026-01-01 08:03:00');

        $count = $this->repository->getUnreadNotificationsCount(1);

        $this->assertSame(2, $count);
    }

    public function testClearNotificationsDeletesOnlyCurrentUsersRows(): void
    {
        $this->insertNotification(1, 'u1 a', false, '2026-01-01 08:00:00');
        $this->insertNotification(1, 'u1 b', false, '2026-01-01 08:01:00');
        $this->insertNotification(1, 'u1 c', true, '2026-01-01 08:02:00');
        $this->insertNotification(2, 'u2 a', false, '2026-01-01 08:03:00');

        $deleted = $this->repository->clearNotifications(1);

        $remainingUserOne = (int)$this->pdo->query('SELECT COUNT(*) FROM notifications WHERE id_user = 1')->fetchColumn();
        $remainingUserTwo = (int)$this->pdo->query('SELECT COUNT(*) FROM notifications WHERE id_user = 2')->fetchColumn();

        $this->assertSame(3, $deleted);
        $this->assertSame(0, $remainingUserOne);
        $this->assertSame(1, $remainingUserTwo);
    }

    public function testDeleteNotificationRespectsOwnership(): void
    {
        $foreignId = $this->insertNotification(2, 'belongs to another user', false, '2026-01-01 08:00:00');
        $ownId = $this->insertNotification(1, 'belongs to current user', false, '2026-01-01 08:01:00');

        $deletedForeign = $this->repository->deleteNotification($foreignId, 1);
        $deletedOwn = $this->repository->deleteNotification($ownId, 1);

        $remainingForeign = (int)$this->pdo->query('SELECT COUNT(*) FROM notifications WHERE id = ' . (int)$foreignId)->fetchColumn();
        $remainingOwn = (int)$this->pdo->query('SELECT COUNT(*) FROM notifications WHERE id = ' . (int)$ownId)->fetchColumn();

        $this->assertFalse($deletedForeign);
        $this->assertTrue($deletedOwn);
        $this->assertSame(1, $remainingForeign);
        $this->assertSame(0, $remainingOwn);
    }

    public function testMarkNotificationsAsReadUpdatesOnlySelectedUser(): void
    {
        $this->insertNotification(1, 'u1 unread', false, '2026-01-01 08:00:00');
        $this->insertNotification(1, 'u1 unread 2', false, '2026-01-01 08:01:00');
        $this->insertNotification(2, 'u2 unread', false, '2026-01-01 08:02:00');

        $this->repository->markNotificationsAsRead(1);

        $userOneUnread = (int)$this->pdo->query('SELECT COUNT(*) FROM notifications WHERE id_user = 1 AND is_read = 0')->fetchColumn();
        $userTwoUnread = (int)$this->pdo->query('SELECT COUNT(*) FROM notifications WHERE id_user = 2 AND is_read = 0')->fetchColumn();

        $this->assertSame(0, $userOneUnread);
        $this->assertSame(1, $userTwoUnread);
    }

    public function testUnreadCountDropsAfterMarkingAsRead(): void
    {
        $this->insertNotification(1, 'unread one', false, '2026-01-01 08:00:00');
        $this->insertNotification(1, 'unread two', false, '2026-01-01 08:01:00');

        $this->assertSame(2, $this->repository->getUnreadNotificationsCount(1));

        $this->repository->markNotificationsAsRead(1);

        $this->assertSame(0, $this->repository->getUnreadNotificationsCount(1));
    }

    public function testGetBookedSlotsReturnsOnlyNonCancelledWithinDateRange(): void
    {
        $this->insertAppointment(1, 1, '2026-01-10', '09:00', 'confirmed');
        $this->insertAppointment(1, 1, '2026-01-11', '09:30', 'cancelled');
        $this->insertAppointment(1, 1, '2026-01-12', '10:00', 'completed');
        $this->insertAppointment(1, 2, '2026-01-10', '11:00', 'confirmed');
        $this->insertAppointment(1, 1, '2026-01-25', '12:00', 'confirmed');

        $slots = $this->repository->getBookedSlots(1, '2026-01-10', '2026-01-15');

        $this->assertCount(2, $slots);
        $this->assertSame('2026-01-10', $slots[0]['appointment_date']);
        $this->assertSame('09:00', $slots[0]['appointment_time']);
        $this->assertSame('2026-01-12', $slots[1]['appointment_date']);
        $this->assertSame('10:00', $slots[1]['appointment_time']);
    }

    public function testCancelAppointmentUpdatesOnlyOwnersAppointment(): void
    {
        $ownAppointmentId = $this->insertAppointment(1, 1, '2026-01-20', '12:30', 'confirmed');
        $foreignAppointmentId = $this->insertAppointment(2, 1, '2026-01-20', '13:00', 'confirmed');

        $this->repository->cancelAppointment($ownAppointmentId, 1, 'Zmiana planow', 'Brak');
        $this->repository->cancelAppointment($foreignAppointmentId, 1, 'Nie powinno zadzialac', 'Brak');

        $ownRow = $this->pdo->query('SELECT status, cancel_reason FROM appointments WHERE id = ' . (int)$ownAppointmentId)->fetch(PDO::FETCH_ASSOC);
        $foreignRow = $this->pdo->query('SELECT status, cancel_reason FROM appointments WHERE id = ' . (int)$foreignAppointmentId)->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('cancelled', $ownRow['status']);
        $this->assertSame('Zmiana planow', $ownRow['cancel_reason']);
        $this->assertSame('confirmed', $foreignRow['status']);
        $this->assertNull($foreignRow['cancel_reason']);
    }

    public function testUpdateAndDeleteAppointmentAdminFlow(): void
    {
        $appointmentId = $this->insertAppointment(1, 1, '2026-01-22', '08:30', 'confirmed');

        $this->repository->updateAppointmentAdmin($appointmentId, 2, 2, '2026-01-23', '09:15', 'completed');

        $row = $this->pdo->query('SELECT id_patient, id_doctor, appointment_date, appointment_time, status FROM appointments WHERE id = ' . (int)$appointmentId)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('2', (string)$row['id_patient']);
        $this->assertSame('2', (string)$row['id_doctor']);
        $this->assertSame('2026-01-23', $row['appointment_date']);
        $this->assertSame('09:15', $row['appointment_time']);
        $this->assertSame('completed', $row['status']);

        $this->repository->deleteAppointmentAdmin($appointmentId);
        $remaining = (int)$this->pdo->query('SELECT COUNT(*) FROM appointments WHERE id = ' . (int)$appointmentId)->fetchColumn();
        $this->assertSame(0, $remaining);
    }

    public function testGetTakenSlotsAndAppointmentsInRangeExcludeCancelled(): void
    {
        $this->insertAppointment(1, 1, '2026-01-24', '08:00', 'confirmed');
        $this->insertAppointment(1, 1, '2026-01-24', '08:30', 'cancelled');
        $this->insertAppointment(1, 1, '2026-01-25', '09:00', 'completed');

        $taken = $this->repository->getTakenSlots(1, '2026-01-24');
        $range = $this->repository->getAppointmentsInRange(1, '2026-01-24', '2026-01-25');

        $this->assertSame(['08:00'], $taken);
        $this->assertCount(2, $range);
        $this->assertSame('2026-01-24', $range[0]['appointment_date']);
        $this->assertSame('2026-01-25', $range[1]['appointment_date']);
    }

    public function testCreateAppointmentReturnsReadableMessageWhenSlotAlreadyTaken(): void
    {
        $tz = new DateTimeZone('Europe/Warsaw');
        $future = new DateTimeImmutable('tomorrow 10:00', $tz);
        $date = $future->format('Y-m-d');
        $time = '10:00';

        $this->insertAvailability(1, $date, '08:00', '20:00');
        $this->insertAppointment(2, 1, $date, $time, 'confirmed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ktoś inny właśnie zarezerwował');

        $this->repository->createAppointment(1, 1, $date, $time);
    }

    public function testCreateAppointmentRejectsPastTimeSlot(): void
    {
        $tz = new DateTimeZone('Europe/Warsaw');
        $past = new DateTimeImmutable('yesterday 08:00', $tz);
        $date = $past->format('Y-m-d');
        $time = '08:00';

        $this->insertAvailability(1, $date, '07:00', '10:00');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('termin już minął');

        $this->repository->createAppointment(1, 1, $date, $time);
    }

    public function testGetAvailableSlotsForDateFiltersOutPastSlotsForToday(): void
    {
        $tz = new DateTimeZone('Europe/Warsaw');
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $now = new DateTimeImmutable('now', $tz);

        $this->insertAvailability(1, $today, '00:00', '23:59');

        $slots = $this->repository->getAvailableSlotsForDate(1, $today);

        foreach ($slots as $slot) {
            $slotDateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i', $today . ' ' . $slot, $tz);
            $this->assertNotFalse($slotDateTime);
            $this->assertTrue(
                $slotDateTime > $now,
                sprintf('Slot %s should not be returned because it is in the past.', $slot)
            );
        }
    }

    public function testGetAvailableDatesInRangeSkipsTodayWhenOnlyPastSlotsExist(): void
    {
        $tz = new DateTimeZone('Europe/Warsaw');
        $now = new DateTimeImmutable('now', $tz);
        $today = $now->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');

        $this->insertAvailability(1, $today, '00:00', '00:30');
        $this->insertAvailability(1, $tomorrow, '08:00', '09:00');

        $available = $this->repository->getAvailableDatesInRange(1, $today, $tomorrow);

        $this->assertContains($tomorrow, $available);
        $this->assertNotContains($today, $available);
    }

    private function insertNotification(
        int $userId,
        string $message,
        bool $isRead,
        string $createdAt,
        string $type = 'general'
    ): int {
        $stmt = $this->pdo->prepare('
            INSERT INTO notifications (id_user, message, is_read, created_at, type, related_id)
            VALUES (:user_id, :message, :is_read, :created_at, :type, NULL)
        ');

        $stmt->execute([
            ':user_id' => $userId,
            ':message' => $message,
            ':is_read' => $isRead ? 1 : 0,
            ':created_at' => $createdAt,
            ':type' => $type,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    private function insertAppointment(
        int $patientId,
        int $doctorId,
        string $date,
        string $time,
        string $status
    ): int {
        $stmt = $this->pdo->prepare('
            INSERT INTO appointments (id_patient, id_doctor, appointment_date, appointment_time, status)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$patientId, $doctorId, $date, $time, $status]);

        return (int)$this->pdo->lastInsertId();
    }

    private function insertAvailability(int $doctorId, string $date, string $startTime, string $endTime): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO doctor_availability (id_doctor, date, start_time, end_time)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$doctorId, $date, $startTime, $endTime]);
    }
}