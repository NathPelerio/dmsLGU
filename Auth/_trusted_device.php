<?php

if (!function_exists('authTrustedCookieName')) {
    function authTrustedCookieName() {
        return 'dms_google_trusted';
    }
}

if (!function_exists('authTrustedCookieSecure')) {
    function authTrustedCookieSecure() {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    }
}

if (!function_exists('authTrustedClearCookie')) {
    function authTrustedClearCookie() {
        $name = authTrustedCookieName();
        setcookie($name, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => authTrustedCookieSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[$name]);
    }
}

if (!function_exists('authTrustedEnsureTable')) {
    function authTrustedEnsureTable($pdo) {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS trusted_devices (
                id CHAR(24) NOT NULL PRIMARY KEY,
                user_id CHAR(24) NOT NULL,
                selector VARCHAR(32) NOT NULL,
                validator_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                user_agent VARCHAR(255) NOT NULL DEFAULT "",
                ip_address VARCHAR(64) NOT NULL DEFAULT "",
                created_at DATETIME NOT NULL,
                last_used_at DATETIME NULL,
                UNIQUE KEY uq_trusted_selector (selector),
                KEY idx_trusted_user (user_id),
                KEY idx_trusted_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $ensured = true;
    }
}

if (!function_exists('authTrustedParseCookie')) {
    function authTrustedParseCookie() {
        $raw = (string)($_COOKIE[authTrustedCookieName()] ?? '');
        if ($raw === '') {
            return null;
        }
        $parts = explode(':', $raw, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $selector = trim((string)$parts[0]);
        $validator = trim((string)$parts[1]);
        if (!preg_match('/^[a-f0-9]{16,64}$/i', $selector) || !preg_match('/^[a-f0-9]{32,128}$/i', $validator)) {
            return null;
        }
        return [$selector, $validator];
    }
}

if (!function_exists('authTrustedIssue')) {
    function authTrustedIssue($config, $userId, $days = 30) {
        $userId = trim((string)$userId);
        if ($userId === '') {
            return false;
        }
        $days = max(1, (int)$days);
        $expiresTs = time() + ($days * 86400);
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $validatorHash = hash('sha256', $validator);
        $pdo = dbPdo($config);
        authTrustedEnsureTable($pdo);

        $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = :user_id AND expires_at < :now_utc')
            ->execute([
                ':user_id' => $userId,
                ':now_utc' => dbNowUtcString(),
            ]);

        $stmt = $pdo->prepare(
            'INSERT INTO trusted_devices
             (id, user_id, selector, validator_hash, expires_at, user_agent, ip_address, created_at, last_used_at)
             VALUES
             (:id, :user_id, :selector, :validator_hash, :expires_at, :user_agent, :ip_address, :created_at, :last_used_at)'
        );
        $nowUtc = dbNowUtcString();
        $stmt->execute([
            ':id' => dbGenerateId24(),
            ':user_id' => $userId,
            ':selector' => $selector,
            ':validator_hash' => $validatorHash,
            ':expires_at' => gmdate('Y-m-d H:i:s', $expiresTs),
            ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ':ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            ':created_at' => $nowUtc,
            ':last_used_at' => $nowUtc,
        ]);

        $cookieValue = $selector . ':' . $validator;
        setcookie(authTrustedCookieName(), $cookieValue, [
            'expires' => $expiresTs,
            'path' => '/',
            'secure' => authTrustedCookieSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[authTrustedCookieName()] = $cookieValue;
        return true;
    }
}

if (!function_exists('authTrustedConsume')) {
    function authTrustedConsume($config, $userId) {
        $userId = trim((string)$userId);
        if ($userId === '') {
            return false;
        }
        $parsed = authTrustedParseCookie();
        if (!is_array($parsed)) {
            return false;
        }
        [$selector, $validator] = $parsed;
        $pdo = dbPdo($config);
        authTrustedEnsureTable($pdo);
        $stmt = $pdo->prepare(
            'SELECT id, validator_hash, expires_at
             FROM trusted_devices
             WHERE user_id = :user_id AND selector = :selector
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':selector' => $selector,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            authTrustedClearCookie();
            return false;
        }

        $expiresTs = dbToTimestamp($row['expires_at'] ?? null);
        if ($expiresTs !== null && $expiresTs <= time()) {
            $pdo->prepare('DELETE FROM trusted_devices WHERE id = :id')->execute([':id' => (string)$row['id']]);
            authTrustedClearCookie();
            return false;
        }

        $expected = strtolower((string)($row['validator_hash'] ?? ''));
        $actual = strtolower(hash('sha256', $validator));
        if (!hash_equals($expected, $actual)) {
            $pdo->prepare('DELETE FROM trusted_devices WHERE id = :id')->execute([':id' => (string)$row['id']]);
            authTrustedClearCookie();
            return false;
        }

        $pdo->prepare('UPDATE trusted_devices SET last_used_at = :last_used_at WHERE id = :id')
            ->execute([
                ':last_used_at' => dbNowUtcString(),
                ':id' => (string)$row['id'],
            ]);

        authTrustedIssue($config, $userId, 30);
        return true;
    }
}
