<?php
/**
 * Shared MySQL helper for the application.
 */

if (!function_exists('dbConfig')) {
    function dbConfig($config = null) {
        if (is_array($config)) {
            return $config;
        }
        return require __DIR__ . '/config.php';
    }
}

if (!function_exists('dbPdo')) {
    function dbPdo($config = null) {
        static $pdo = null;
        static $cachedDsn = null;

        $c = dbConfig($config);
        $host = $c['mysql_host'] ?? '127.0.0.1';
        $port = (int)($c['mysql_port'] ?? 3306);
        $dbName = $c['mysql_database'] ?? 'dmslgu';
        $user = $c['mysql_user'] ?? 'root';
        $pass = $c['mysql_password'] ?? '';

        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbName . ';charset=utf8mb4';
        if ($pdo instanceof PDO && $cachedDsn === $dsn) {
            return $pdo;
        }

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $cachedDsn = $dsn;
        return $pdo;
    }
}

if (!function_exists('dbGenerateId24')) {
    function dbGenerateId24() {
        return bin2hex(random_bytes(12));
    }
}

if (!function_exists('dbToTimestamp')) {
    function dbToTimestamp($value) {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        $ts = strtotime((string)$value);
        return $ts === false ? null : $ts;
    }
}

if (!function_exists('dbNowUtcString')) {
    function dbNowUtcString() {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('dbUtcToTimestamp')) {
    function dbUtcToTimestamp($value) {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        try {
            $dt = new DateTime((string)$value, new DateTimeZone('UTC'));
            return $dt->getTimestamp();
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('dbSuspendDurationSeconds')) {
    function dbSuspendDurationSeconds($durationValue, $durationUnit) {
        $value = (int)$durationValue;
        $unit = strtolower(trim((string)$durationUnit));
        if ($value <= 0) {
            return 0;
        }
        $secondsByUnit = [
            'hours' => 3600,
            'days' => 86400,
            'weeks' => 604800,
            'months' => 2592000,
            'years' => 31536000,
        ];
        if (!isset($secondsByUnit[$unit])) {
            return 0;
        }
        return $value * $secondsByUnit[$unit];
    }
}

if (!function_exists('dbSuspendRemainingSeconds')) {
    /**
     * Returns remaining suspension seconds.
     * - int >= 0 : remaining timer
     * - null     : unknown/indefinite (no duration metadata)
     */
    function dbSuspendRemainingSeconds($user, $nowTs = null) {
        if (!is_array($user)) {
            return null;
        }
        $monoNowNs = function_exists('hrtime') ? @hrtime(true) : null;
        $now = is_numeric($nowTs) ? (int)$nowTs : time();
        $durationSeconds = dbSuspendDurationSeconds(
            $user['suspend_duration_value'] ?? 0,
            $user['suspend_duration_unit'] ?? ''
        );
        $monoStartNs = isset($user['suspended_mono_ns']) && is_numeric($user['suspended_mono_ns'])
            ? (int)$user['suspended_mono_ns']
            : null;
        if ($durationSeconds > 0 && is_int($monoNowNs) && $monoStartNs !== null && $monoStartNs > 0) {
            $elapsedNs = $monoNowNs - $monoStartNs;
            if ($elapsedNs > 0) {
                $elapsed = (int)floor($elapsedNs / 1000000000);
                return max(0, $durationSeconds - $elapsed);
            }
            return $durationSeconds;
        }
        $startedTs = dbUtcToTimestamp($user['suspended_at'] ?? null);
        if ($durationSeconds > 0 && $startedTs !== null) {
            $elapsed = max(0, $now - $startedTs);
            return max(0, $durationSeconds - $elapsed);
        }
        $untilTs = dbUtcToTimestamp($user['suspended_until'] ?? null);
        if ($untilTs !== null) {
            return max(0, $untilTs - $now);
        }
        return null;
    }
}

if (!function_exists('dbAutoActivityModule')) {
    function dbAutoActivityModule($scriptPath) {
        $script = str_replace('\\', '/', (string)$scriptPath);
        $parts = array_values(array_filter(explode('/', trim($script, '/'))));
        if (count($parts) >= 2) {
            $folder = strtolower(trim((string)$parts[count($parts) - 2]));
            if ($folder !== '') {
                $folder = preg_replace('/\s+/', '_', $folder) ?: $folder;
                return $folder;
            }
        }
        return 'app';
    }
}

if (!function_exists('dbAutoActivityRegister')) {
    /**
     * Auto-log authenticated request activity once per request.
     * This acts as a safety net so actions are captured across modules.
     */
    function dbAutoActivityRegister() {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        register_shutdown_function(function () {
            if (PHP_SAPI === 'cli') {
                return;
            }
            if (!empty($GLOBALS['__activity_log_written'])) {
                return;
            }
            if (!function_exists('session_status') || session_status() !== PHP_SESSION_ACTIVE) {
                return;
            }
            $actorId = trim((string)($_SESSION['user_id'] ?? ''));
            if ($actorId === '') {
                return;
            }

            $method = strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')));
            if ($method === '') {
                $method = 'GET';
            }
            if ($method === 'GET') {
                return;
            }
            $requestActionRaw = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
            if ($requestActionRaw === '') {
                return;
            }
            $requestAction = strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $requestActionRaw) ?? ''));
            if ($requestAction === '') {
                return;
            }
            foreach (['verify_', 'send_', 'mark_', 'fetch_', 'get_'] as $skipPrefix) {
                if (str_starts_with($requestAction, $skipPrefix)) {
                    return;
                }
            }
            $requestUri = trim((string)($_SERVER['REQUEST_URI'] ?? ''));
            $scriptName = trim((string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $statusCode = (int)http_response_code();
            if ($statusCode <= 0) {
                $statusCode = 200;
            }

            $status = 'success';
            if ($statusCode >= 500) {
                $status = 'error';
            } elseif ($statusCode >= 400) {
                $status = 'failed';
            }

            $action = $requestAction;
            $details = [
                'module' => dbAutoActivityModule($scriptName),
                'request_uri' => $requestUri,
                'script_name' => $scriptName,
                'http_method' => $method,
                'http_status' => (string)$statusCode,
            ];

            try {
                $pdo = dbPdo();
                $cols = [];
                foreach ($pdo->query('SHOW COLUMNS FROM activity_logs') as $row) {
                    $cols[strtolower((string)($row['Field'] ?? ''))] = true;
                }
                $hasModern = isset($cols['actor_id']) && isset($cols['actor_name']) && isset($cols['status']) && isset($cols['module']);
                if ($hasModern) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO activity_logs
                            (action, status, module, details, actor_id, actor_name, actor_email, actor_role, ip_address, user_agent, created_at)
                         VALUES
                            (:action, :status, :module, :details, :actor_id, :actor_name, :actor_email, :actor_role, :ip_address, :user_agent, :created_at)'
                    );
                    $stmt->execute([
                        ':action' => $action,
                        ':status' => $status,
                        ':module' => (string)$details['module'],
                        ':details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ':actor_id' => $actorId,
                        ':actor_name' => trim((string)($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'Unknown')),
                        ':actor_email' => trim((string)($_SESSION['user_email'] ?? '')),
                        ':actor_role' => strtolower(trim((string)($_SESSION['user_role'] ?? 'guest'))),
                        ':ip_address' => trim((string)($_SERVER['REMOTE_ADDR'] ?? '')),
                        ':user_agent' => trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
                        ':created_at' => dbNowUtcString(),
                    ]);
                } else {
                    $actorIdInt = ctype_digit($actorId) ? (int)$actorId : null;
                    $legacyPayload = [
                        'status' => $status,
                        'module' => (string)$details['module'],
                        'details' => $details,
                        'actor_name' => trim((string)($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'Unknown')),
                        'actor_email' => trim((string)($_SESSION['user_email'] ?? '')),
                        'actor_role' => strtolower(trim((string)($_SESSION['user_role'] ?? 'guest'))),
                    ];
                    $stmt = $pdo->prepare(
                        'INSERT INTO activity_logs
                            (user_id, action, description, ip_address, created_at)
                         VALUES
                            (:user_id, :action, :description, :ip_address, :created_at)'
                    );
                    $stmt->bindValue(':user_id', $actorIdInt, $actorIdInt === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $stmt->bindValue(':action', $action, PDO::PARAM_STR);
                    $stmt->bindValue(':description', json_encode($legacyPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
                    $stmt->bindValue(':ip_address', trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), PDO::PARAM_STR);
                    $stmt->bindValue(':created_at', dbNowUtcString(), PDO::PARAM_STR);
                    $stmt->execute();
                }
            } catch (Exception $e) {
                // Swallow logging errors so normal requests are unaffected.
            }
        });
    }
}

dbAutoActivityRegister();
