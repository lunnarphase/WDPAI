<?php

require_once 'Repository.php';

class LoginAttemptsRepository extends Repository {

    /**
     * Rejestruje próbę logowania.
     */
    public function recordAttempt(string $email, string $ipAddress, bool $success): void
    {
        $db = $this->database->connect();
        $stmt = $db->prepare(
            'INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)'
        );
        $stmt->execute([$email, $ipAddress, $success ? 1 : 0]);
    }

    /**
     * Zwraca liczbę kolejnych nieudanych prób od ostatniego udanego logowania.
     */
    public function getConsecutiveFailures(string $email): int
    {
        $db = $this->database->connect();
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM login_attempts
            WHERE email = :email
              AND success = FALSE
              AND attempted_at > COALESCE(
                    (SELECT MAX(attempted_at)
                     FROM login_attempts
                     WHERE email = :email AND success = TRUE),
                    '1970-01-01'::timestamp
              )
        ");
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Sprawdza, czy konto powinno zostać tymczasowo zablokowane.
     */
    public function isTemporarilyLocked(string $email, int $maxFailures = 5, int $windowMinutes = 15): bool
    {
        $db = $this->database->connect();
        $stmt = $db->prepare(" 
            SELECT COUNT(*)
            FROM login_attempts
            WHERE email = :email
              AND success = FALSE
              AND attempted_at > COALESCE(
                    (SELECT MAX(attempted_at)
                     FROM login_attempts
                     WHERE email = :email AND success = TRUE),
                    '1970-01-01'::timestamp
              )
              AND attempted_at >= (NOW() - (:window || ' minutes')::interval)
        ");

        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $window = (string)$windowMinutes;
        $stmt->bindParam(':window', $window, PDO::PARAM_STR);
        $stmt->execute();

        return ((int)$stmt->fetchColumn()) >= $maxFailures;
    }

    /**
     * Zwraca konta z >= $minFailures kolejnymi nieudanymi próbami logowania.
     */
    public function getAccountsWithSuspiciousActivity(int $minFailures = 2): array
    {
        $db = $this->database->connect();
        $stmt = $db->prepare("
            WITH last_success AS (
                SELECT email, MAX(attempted_at) AS last_success_at
                FROM login_attempts
                WHERE success = TRUE
                GROUP BY email
            ),
            recent_failures AS (
                SELECT
                    la.email,
                    COUNT(*)                                               AS failure_count,
                    MAX(la.attempted_at)                                   AS last_attempt,
                    (SELECT ip_address
                     FROM login_attempts
                     WHERE email = la.email AND success = FALSE
                     ORDER BY attempted_at DESC
                     LIMIT 1)                                              AS last_ip
                FROM login_attempts la
                LEFT JOIN last_success ls ON la.email = ls.email
                WHERE la.success = FALSE
                  AND la.attempted_at > COALESCE(ls.last_success_at, '1970-01-01'::timestamp)
                GROUP BY la.email
                HAVING COUNT(*) >= :min_failures
            )
            SELECT
                rf.email,
                rf.failure_count,
                rf.last_attempt,
                rf.last_ip,
                u.id        AS user_id,
                u.username,
                u.is_blocked
            FROM recent_failures rf
            LEFT JOIN users u ON u.email = rf.email
            ORDER BY rf.last_attempt DESC
        ");
        $stmt->bindParam(':min_failures', $minFailures, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tworzy powiadomienie dla wszystkich adminów o podejrzanej aktywności.
     * Wywołuje się tylko jeśli konto istnieje w systemie.
     */
    public function notifyAdmins(string $email, int $failureCount): void
    {
        $db = $this->database->connect();

        $adminStmt = $db->prepare("
            SELECT u.id FROM users u
            JOIN roles r ON u.id_role = r.id
            WHERE r.name = 'admin'
        ");
        $adminStmt->execute();
        $adminIds = $adminStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($adminIds)) {
            return;
        }

        $message = "Podejrzana aktywność: {$failureCount} nieudane próby logowania z rzędu dla konta {$email}.";
        $notifStmt = $db->prepare(
            "INSERT INTO notifications (id_user, message, type) VALUES (?, ?, 'security_alert')"
        );
        foreach ($adminIds as $adminId) {
            $notifStmt->execute([$adminId, $message]);
        }
    }
}
