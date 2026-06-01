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
     * Czy para (email, IP) ma za duzo nieudanych prob w oknie czasowym?
     *
     * To jest "miekka" blokada IP-aware: atakujacy z X.X.X.X po 3 nieudanych
     * probach na konto user@a.pl zostaje odciety od TEGO konta na 15 min.
     * Prawowity wlasciciel z innego IP nadal moze sie zalogowac (chyba ze
     * doszedl globalny atak rozproszony - patrz isAccountGloballyLocked).
     */
    public function isTemporarilyLockedForIpAccount(
        string $email,
        string $ipAddress,
        int $maxFailures = 3,
        int $windowMinutes = 15
    ): bool {
        if ($ipAddress === '' || $ipAddress === 'unknown') {
            return false;
        }

        $db = $this->database->connect();
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM login_attempts
            WHERE email = :email
              AND ip_address = :ip
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
        $stmt->bindParam(':ip', $ipAddress, PDO::PARAM_STR);
        $window = (string)$windowMinutes;
        $stmt->bindParam(':window', $window, PDO::PARAM_STR);
        $stmt->execute();

        return ((int)$stmt->fetchColumn()) >= $maxFailures;
    }

    /**
     * Czy na to konto leci atak rozproszony? Wymaga rownoczesnie:
     *   - lacznie >= $minFailures nieudanych prob w oknie $windowMinutes
     *   - z co najmniej $minDistinctIps roznych adresow IP
     *   - od ostatniego udanego logowania na to konto
     *
     * Dopiero ten warunek powoduje twardy globalny lockout konta (bo
     * widac, ze problem nie ogranicza sie do jednego atakujacego).
     */
    public function isAccountGloballyLocked(
        string $email,
        int $minFailures = 4,
        int $minDistinctIps = 2,
        int $windowMinutes = 15
    ): bool {
        $details = $this->getAccountAttackDetails($email, $windowMinutes);
        return $details['total_failures'] >= $minFailures
            && $details['distinct_ips'] >= $minDistinctIps;
    }

    /**
     * Zwraca informacje o aktualnym ataku na konto: ile nieudanych prob,
     * z ilu IP, jakie to byly IP, kiedy byla ostatnia proba, ile sekund
     * zostalo do konca okna (sluzy do liczenia remaining time globalnej
     * blokady konta).
     */
    public function getAccountAttackDetails(string $email, int $windowMinutes = 15): array
    {
        $db = $this->database->connect();
        $stmt = $db->prepare("
            WITH last_success AS (
                SELECT MAX(attempted_at) AS ts
                FROM login_attempts
                WHERE email = :email AND success = TRUE
            ),
            failures AS (
                SELECT ip_address, attempted_at
                FROM login_attempts
                WHERE email = :email
                  AND success = FALSE
                  AND attempted_at > COALESCE((SELECT ts FROM last_success), '1970-01-01'::timestamp)
                  AND attempted_at >= (NOW() - (:window || ' minutes')::interval)
                  AND ip_address IS NOT NULL
            )
            SELECT
                COUNT(*)::INT                                  AS total_failures,
                COUNT(DISTINCT ip_address)::INT                AS distinct_ips,
                MAX(attempted_at)                              AS last_attempt,
                CASE
                    WHEN MAX(attempted_at) IS NULL THEN 0
                    ELSE GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (
                        (MAX(attempted_at) + (:window || ' minutes')::interval) - NOW()
                    ))))::INT
                END                                            AS remaining_seconds
            FROM failures
        ");
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $window = (string)$windowMinutes;
        $stmt->bindParam(':window', $window, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Lista atakujacych IP wraz z liczba prob (zeby admin mogl podejrzec)
        $ipsStmt = $db->prepare("
            SELECT
                ip_address,
                COUNT(*)::INT AS failure_count,
                MAX(attempted_at) AS last_attempt
            FROM login_attempts
            WHERE email = :email
              AND success = FALSE
              AND ip_address IS NOT NULL
              AND attempted_at > COALESCE(
                    (SELECT MAX(attempted_at) FROM login_attempts WHERE email = :email AND success = TRUE),
                    '1970-01-01'::timestamp
              )
              AND attempted_at >= (NOW() - (:window || ' minutes')::interval)
            GROUP BY ip_address
            ORDER BY failure_count DESC, MAX(attempted_at) DESC
        ");
        $ipsStmt->bindParam(':email', $email, PDO::PARAM_STR);
        $ipsStmt->bindParam(':window', $window, PDO::PARAM_STR);
        $ipsStmt->execute();
        $attackerIps = $ipsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'total_failures'    => (int)($row['total_failures'] ?? 0),
            'distinct_ips'      => (int)($row['distinct_ips'] ?? 0),
            'remaining_seconds' => (int)($row['remaining_seconds'] ?? 0),
            'window_minutes'    => $windowMinutes,
            'attacker_ips'      => $attackerIps,
        ];
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
                     LIMIT 1)                                              AS last_ip,
                    -- Statystyki w oknie ostatnich 15 minut - na podstawie tego
                    -- liczymy biezace blokady (i globalna, i per-IP).
                    COUNT(*) FILTER (
                        WHERE la.attempted_at >= (NOW() - INTERVAL '15 minutes')
                    )                                                      AS failures_15m,
                    COUNT(DISTINCT la.ip_address) FILTER (
                        WHERE la.attempted_at >= (NOW() - INTERVAL '15 minutes')
                          AND la.ip_address IS NOT NULL
                    )                                                      AS distinct_ips_15m,
                    MAX(la.attempted_at) FILTER (
                        WHERE la.attempted_at >= (NOW() - INTERVAL '15 minutes')
                    )                                                      AS last_attempt_15m,
                    -- Te same statystyki, ale tylko dla ostatniego (najczestszego) IP -
                    -- pozwala wyliczyc remaining time miekkiego lockoutu per (IP, konto).
                    COUNT(*) FILTER (
                        WHERE la.attempted_at >= (NOW() - INTERVAL '15 minutes')
                          AND la.ip_address = (
                            SELECT ip_address
                            FROM login_attempts
                            WHERE email = la.email AND success = FALSE
                            ORDER BY attempted_at DESC
                            LIMIT 1
                          )
                    )                                                      AS same_ip_failures_15m,
                    MAX(la.attempted_at) FILTER (
                        WHERE la.attempted_at >= (NOW() - INTERVAL '15 minutes')
                          AND la.ip_address = (
                            SELECT ip_address
                            FROM login_attempts
                            WHERE email = la.email AND success = FALSE
                            ORDER BY attempted_at DESC
                            LIMIT 1
                          )
                    )                                                      AS last_same_ip_attempt_15m
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
                    AND rf.distinct_ips_15m >= 2
                    AND rf.failures_15m >= 4
                )           AS is_account_globally_locked,
                CASE
                    WHEN u.id IS NOT NULL
                     AND rf.distinct_ips_15m >= 2
                     AND rf.failures_15m >= 4
                    THEN GREATEST(0, FLOOR(EXTRACT(EPOCH FROM ((rf.last_attempt_15m + INTERVAL '15 minutes') - NOW()))))::INT
                    ELSE 0
                END         AS account_global_lock_remaining_seconds,
                rf.distinct_ips_15m AS attacker_ips_count,
                (
                    u.id IS NOT NULL
                    AND rf.same_ip_failures_15m >= 3
                )           AS is_temporarily_locked,
                CASE
                    WHEN u.id IS NOT NULL
                     AND rf.same_ip_failures_15m >= 3
                    THEN TO_CHAR(
                        timezone(
                            'Europe/Warsaw',
                            (rf.last_same_ip_attempt_15m + INTERVAL '15 minutes') AT TIME ZONE current_setting('TIMEZONE')
                        ),
                        'YYYY-MM-DD HH24:MI:SS'
                    )
                    ELSE NULL
                END         AS temporary_locked_until,
                CASE
                    WHEN u.id IS NOT NULL
                     AND rf.same_ip_failures_15m >= 3
                    THEN GREATEST(0, FLOOR(EXTRACT(EPOCH FROM ((rf.last_same_ip_attempt_15m + INTERVAL '15 minutes') - NOW()))))::INT
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
     * Powiadomienie adminow o ataku rozproszonym na konkretne konto (atak
     * z wielu IP wyzwala twardy globalny lockout konta).
     */
    public function notifyAdminsAboutAccountAttack(
        string $email,
        int $totalFailures,
        int $distinctIps,
        int $windowMinutes,
        array $attackerIps = []
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

        $ipsLine = '';
        if (!empty($attackerIps)) {
            $parts = [];
            foreach ($attackerIps as $row) {
                $ip = trim((string)($row['ip_address'] ?? ''));
                if ($ip === '') continue;
                $count = (int)($row['failure_count'] ?? 0);
                $parts[] = $count > 0 ? sprintf('%s (%d)', $ip, $count) : $ip;
            }
            if (!empty($parts)) {
                $ipsLine = "\nAtakujace IP: " . implode(', ', $parts);
            }
        }

        $message = "GLOBALNY LOCKOUT KONTA\n"
            . "Wykryto atak rozproszony na konto {$email}\n"
            . "Nieudane proby: {$totalFailures} w ciagu {$windowMinutes} min\n"
            . "Rozne adresy IP: {$distinctIps}\n"
            . "Konto zostalo automatycznie tymczasowo zablokowane."
            . $ipsLine;

        $notifStmt = $db->prepare(
            "INSERT INTO notifications (id_user, message, type) VALUES (?, ?, 'account_global_attack')"
        );
        foreach ($adminIds as $adminId) {
            $notifStmt->execute([$adminId, $message]);
        }
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
     * Debug helper: zdejmuje wszystkie aktywne blokady i czyści nieudane próby.
     * Używaj tylko lokalnie podczas testów manualnych.
     */
    public function clearAllLocksForDebug(): array
    {
        $db = $this->database->connect();
        $this->ensureBlockedIpsTableExists($db);

        try {
            $this->beginTransactionWithIsolation($db, 'READ COMMITTED');

            $deletedBlockedIps = (int)$db->exec('DELETE FROM blocked_ips');
            $clearedHardBlockedUsers = (int)$db->exec('UPDATE users SET is_blocked = FALSE WHERE is_blocked = TRUE');
            $deletedFailedAttempts = (int)$db->exec('DELETE FROM login_attempts WHERE success = FALSE');

            $db->commit();

            return [
                'deleted_blocked_ips' => $deletedBlockedIps,
                'cleared_hard_blocked_users' => $clearedHardBlockedUsers,
                'deleted_failed_attempts' => $deletedFailedAttempts,
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Zdejmuje aktywne blokady IP powiazane z kontem uzytkownika.
     * Metode wywoluj przed clearAttemptsForUserId().
     */
    public function unblockIpsForUser(int $userId): array
    {
        $db = $this->database->connect();
        $this->ensureBlockedIpsTableExists($db);

        $select = $db->prepare("
            SELECT DISTINCT bi.ip_address
            FROM blocked_ips bi
            WHERE bi.ip_address IN (
                SELECT DISTINCT la.ip_address
                FROM login_attempts la
                JOIN users u ON LOWER(la.email) = LOWER(u.email)
                WHERE u.id = :user_id
                  AND la.success = FALSE
                  AND la.ip_address IS NOT NULL
            )
        ");
        $select->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $select->execute();
        $ips = $select->fetchAll(PDO::FETCH_COLUMN) ?: [];

        if (empty($ips)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ips), '?'));
        $delete = $db->prepare("DELETE FROM blocked_ips WHERE ip_address IN ({$placeholders})");
        $delete->execute($ips);

        return $ips;
    }

    /**
     * Usuwa powiadomienia admina typu global_ip_attack dla podanych adresow IP.
     */
    public function deleteGlobalIpAttackNotificationsForIps(array $ipAddresses): int
    {
        $cleanIps = array_values(array_unique(array_filter($ipAddresses, function ($ip) {
            return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP);
        })));

        if (empty($cleanIps)) {
            return 0;
        }

        $db = $this->database->connect();

        $stmt = $db->prepare(" 
            DELETE FROM notifications
            WHERE type = 'global_ip_attack'
              AND message LIKE :pattern
        ");

        $deleted = 0;
        foreach ($cleanIps as $ip) {
            $pattern = '%IP: ' . $ip . '%';
            $stmt->bindParam(':pattern', $pattern, PDO::PARAM_STR);
            $stmt->execute();
            $deleted += $stmt->rowCount();
        }

        return $deleted;
    }

    /**
     * Usuwa powiadomienia admina typu account_global_attack dla podanego emaila.
     * Wywolywane po recznym odblokowaniu konta przez admina, zeby alert nie
     * wisial w panelu z odliczaniem czasu, ktorego juz nie ma.
     */
    public function deleteAccountAttackNotificationsForEmail(string $email): int
    {
        if ($email === '') return 0;

        $db = $this->database->connect();
        $pattern = '%konto ' . $email . '%';
        $stmt = $db->prepare("
            DELETE FROM notifications
            WHERE type = 'account_global_attack'
              AND message LIKE :pattern
        ");
        $stmt->bindParam(':pattern', $pattern, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->rowCount();
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
