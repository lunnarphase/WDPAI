<?php

require_once 'Repository.php';

class LoginAttemptsRepository extends Repository {

    /**
     * Tworzy tabelę blokad IP, jeśli nie istnieje (ochrona przed brakującą migracją).
     */
    private function ensureBlockedIpsTableExists(PDO $db): void
    {
        $db->exec(" 
            CREATE TABLE IF NOT EXISTS blocked_ips (
                ip_address VARCHAR(45) PRIMARY KEY,
                blocked_until TIMESTAMP NULL,
                reason TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_blocked_ips_blocked_until ON blocked_ips(blocked_until)");
    }

    private function formatSecondsAsHuman(int $seconds): string
    {
        if ($seconds < 0) {
            return 'do odwolania';
        }

        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %02dm %02ds', $hours, $minutes, $secs);
        }

        return sprintf('%dm %02ds', $minutes, $secs);
    }

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
        $this->ensureBlockedIpsTableExists($db);
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
                TO_CHAR(
                    timezone(
                        'Europe/Warsaw',
                        rf.last_attempt AT TIME ZONE current_setting('TIMEZONE')
                    ),
                    'YYYY-MM-DD HH24:MI:SS'
                )          AS last_attempt,
                rf.last_ip,
                u.id        AS user_id,
                u.username,
                u.is_blocked,
                (
                    u.id IS NOT NULL
                    AND rf.failure_count >= 5
                    AND rf.last_attempt >= (NOW() - INTERVAL '15 minutes')
                )           AS is_temporarily_locked,
                CASE
                    WHEN u.id IS NOT NULL
                     AND rf.failure_count >= 5
                     AND rf.last_attempt >= (NOW() - INTERVAL '15 minutes')
                    THEN TO_CHAR(
                        timezone(
                            'Europe/Warsaw',
                            (rf.last_attempt + INTERVAL '15 minutes') AT TIME ZONE current_setting('TIMEZONE')
                        ),
                        'YYYY-MM-DD HH24:MI:SS'
                    )
                    ELSE NULL
                END         AS temporary_locked_until,
                CASE
                    WHEN u.id IS NOT NULL
                     AND rf.failure_count >= 5
                     AND rf.last_attempt >= (NOW() - INTERVAL '15 minutes')
                    THEN GREATEST(0, FLOOR(EXTRACT(EPOCH FROM ((rf.last_attempt + INTERVAL '15 minutes') - NOW()))))::INT
                    ELSE 0
                END         AS temporary_lock_remaining_seconds,
                (
                    bi.ip_address IS NOT NULL
                    AND (bi.blocked_until IS NULL OR bi.blocked_until > NOW())
                )           AS is_ip_blocked,
                CASE
                    WHEN bi.blocked_until IS NULL THEN NULL
                    ELSE TO_CHAR(
                        timezone(
                            'Europe/Warsaw',
                            bi.blocked_until AT TIME ZONE current_setting('TIMEZONE')
                        ),
                        'YYYY-MM-DD HH24:MI:SS'
                    )
                END         AS ip_blocked_until,
                CASE
                    WHEN bi.ip_address IS NULL THEN 0
                    WHEN bi.blocked_until IS NULL THEN -1
                    ELSE GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (bi.blocked_until - NOW()))))::INT
                END         AS ip_block_remaining_seconds
            FROM recent_failures rf
            LEFT JOIN users u ON u.email = rf.email
            LEFT JOIN blocked_ips bi ON bi.ip_address = rf.last_ip
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

    /**
     * Czyści historię prób logowania dla konta użytkownika.
     * Przydaje się po ręcznym odblokowaniu konta przez administratora.
     */
    public function clearAttemptsForUserId(int $userId): void
    {
        $db = $this->database->connect();
        $stmt = $db->prepare(" 
            DELETE FROM login_attempts la
            USING users u
            WHERE u.id = :user_id
              AND la.email = u.email
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Sprawdza, czy adres IP ma aktywną blokadę logowania.
     */
    public function isIpBlocked(string $ipAddress): bool
    {
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            return false;
        }

        try {
            $db = $this->database->connect();
            $this->ensureBlockedIpsTableExists($db);
            $stmt = $db->prepare(" 
                SELECT 1
                FROM blocked_ips
                WHERE ip_address = :ip
                  AND (blocked_until IS NULL OR blocked_until > NOW())
                LIMIT 1
            ");
            $stmt->bindParam(':ip', $ipAddress, PDO::PARAM_STR);
            $stmt->execute();

            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log('IP block check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Wykrywa potencjalny globalny atak: wiele nieudanych prób z jednego IP
     * na co najmniej kilka różnych kont w krótkim oknie czasowym.
     */
    public function detectGlobalIpAttack(
        string $ipAddress,
        int $minFailures = 6,
        int $minDistinctAccounts = 2,
        int $windowMinutes = 15
    ): array {
        $defaultResult = [
            'detected' => false,
            'total_failures' => 0,
            'distinct_accounts' => 0,
            'window_minutes' => $windowMinutes,
        ];

        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            return $defaultResult;
        }

        $db = $this->database->connect();
        $stmt = $db->prepare(" 
            SELECT
                COUNT(*) AS total_failures,
                COUNT(DISTINCT LOWER(email)) AS distinct_accounts
            FROM login_attempts
            WHERE ip_address = :ip
              AND success = FALSE
              AND attempted_at >= (NOW() - (:window || ' minutes')::interval)
        ");

        $stmt->bindParam(':ip', $ipAddress, PDO::PARAM_STR);
        $window = (string)$windowMinutes;
        $stmt->bindParam(':window', $window, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalFailures = (int)($row['total_failures'] ?? 0);
        $distinctAccounts = (int)($row['distinct_accounts'] ?? 0);

        return [
            'detected' => $totalFailures >= $minFailures && $distinctAccounts >= $minDistinctAccounts,
            'total_failures' => $totalFailures,
            'distinct_accounts' => $distinctAccounts,
            'window_minutes' => $windowMinutes,
        ];
    }

    /**
     * Nakłada tymczasową blokadę na adres IP.
     */
    public function blockIpAddress(
        string $ipAddress,
        int $durationMinutes = 60,
        string $reason = 'Wykryto podejrzaną aktywność logowania z tego adresu IP.'
    ): bool {
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            return false;
        }

        try {
            $db = $this->database->connect();
            $this->ensureBlockedIpsTableExists($db);
            $stmt = $db->prepare(" 
                INSERT INTO blocked_ips (ip_address, blocked_until, reason, created_at, updated_at)
                VALUES (
                    :ip,
                    NOW() + (:duration || ' minutes')::interval,
                    :reason,
                    NOW(),
                    NOW()
                )
                ON CONFLICT (ip_address) DO UPDATE
                SET blocked_until = CASE
                        WHEN blocked_ips.blocked_until IS NULL THEN NULL
                        ELSE GREATEST(blocked_ips.blocked_until, EXCLUDED.blocked_until)
                    END,
                    reason = EXCLUDED.reason,
                    updated_at = NOW()
            ");

            $duration = (string)max(1, $durationMinutes);
            $stmt->bindParam(':ip', $ipAddress, PDO::PARAM_STR);
            $stmt->bindParam(':duration', $duration, PDO::PARAM_STR);
            $stmt->bindParam(':reason', $reason, PDO::PARAM_STR);
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            error_log('IP block update failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Zwraca szczegóły aktywnej blokady IP wraz z pozostałym czasem.
     */
    public function getBlockedIpDetails(string $ipAddress): array
    {
        $default = [
            'is_blocked' => false,
            'ip_address' => $ipAddress,
            'blocked_until' => null,
            'remaining_seconds' => 0,
        ];

        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            return $default;
        }

        try {
            $db = $this->database->connect();
            $this->ensureBlockedIpsTableExists($db);

            $stmt = $db->prepare(" 
                SELECT
                    TO_CHAR(
                        timezone(
                            'Europe/Warsaw',
                            blocked_until AT TIME ZONE current_setting('TIMEZONE')
                        ),
                        'YYYY-MM-DD HH24:MI:SS'
                    ) AS blocked_until,
                    CASE
                        WHEN blocked_until IS NULL THEN -1
                        ELSE GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (blocked_until - NOW()))))::INT
                    END AS remaining_seconds
                FROM blocked_ips
                WHERE ip_address = :ip
                  AND (blocked_until IS NULL OR blocked_until > NOW())
                LIMIT 1
            ");
            $stmt->bindParam(':ip', $ipAddress, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return $default;
            }

            return [
                'is_blocked' => true,
                'ip_address' => $ipAddress,
                'blocked_until' => $row['blocked_until'] ?? null,
                'remaining_seconds' => (int)($row['remaining_seconds'] ?? 0),
            ];
        } catch (Exception $e) {
            error_log('Failed to read blocked IP details: ' . $e->getMessage());
            return $default;
        }
    }

    /**
     * Zwraca listę kont (email + liczba prób), które były celem ataku z danego IP.
     */
    public function getRecentFailedTargetsForIp(string $ipAddress, int $windowMinutes = 15, int $limit = 8): array
    {
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            return [];
        }

        $db = $this->database->connect();
        $stmt = $db->prepare(" 
            SELECT
                LOWER(email) AS email,
                COUNT(*)::INT AS failure_count,
                MAX(attempted_at) AS last_attempt
            FROM login_attempts
            WHERE ip_address = :ip
              AND success = FALSE
              AND attempted_at >= (NOW() - (:window || ' minutes')::interval)
            GROUP BY LOWER(email)
            ORDER BY failure_count DESC, MAX(attempted_at) DESC
            LIMIT :limit
        ");

        $window = (string)$windowMinutes;
        $safeLimit = max(1, min(20, $limit));
        $stmt->bindParam(':ip', $ipAddress, PDO::PARAM_STR);
        $stmt->bindParam(':window', $window, PDO::PARAM_STR);
        $stmt->bindParam(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Wysyła do administratorów alert o globalnym ataku z jednego adresu IP.
     */
    public function notifyAdminsAboutIpAttack(
        string $ipAddress,
        int $totalFailures,
        int $distinctAccounts,
        int $windowMinutes,
        array $targetAccounts = [],
        ?string $blockedUntilWarsaw = null,
        ?int $lockRemainingSeconds = null
    ): void {
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

        $targetsLine = '';
        if (!empty($targetAccounts)) {
            $parts = [];
            foreach ($targetAccounts as $target) {
                $targetEmail = trim((string)($target['email'] ?? ''));
                if ($targetEmail === '') {
                    continue;
                }

                $attempts = (int)($target['failure_count'] ?? 0);
                $parts[] = $attempts > 0
                    ? sprintf('%s (%d)', $targetEmail, $attempts)
                    : $targetEmail;
            }

            if (!empty($parts)) {
                $targetsLine = "\nCele ataku: " . implode(', ', $parts);
            }
        }

        $blockedUntilLine = $blockedUntilWarsaw
            ? "\nBlokada aktywna do: {$blockedUntilWarsaw}"
            : '';

        $remainingLine = '';
        if ($lockRemainingSeconds !== null) {
            $remainingLine = "\nPozostaly czas blokady IP: " . $this->formatSecondsAsHuman((int)$lockRemainingSeconds);
        }

        $message = "GLOBALNY ALERT BEZPIECZEŃSTWA\n"
            . "Wykryto seryjne nieudane logowania z IP: {$ipAddress}\n"
            . "Nieudane próby: {$totalFailures} w ciągu {$windowMinutes} min\n"
            . "Różne konta: {$distinctAccounts}\n"
            . "Adres IP został automatycznie tymczasowo zablokowany."
            . $targetsLine
            . $blockedUntilLine
            . $remainingLine;

        $notifStmt = $db->prepare(
            "INSERT INTO notifications (id_user, message, type) VALUES (?, ?, 'global_ip_attack')"
        );
        foreach ($adminIds as $adminId) {
            $notifStmt->execute([$adminId, $message]);
        }
    }
}
