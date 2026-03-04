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
