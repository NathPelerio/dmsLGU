<?php
/**
 * Authentication rate limiter (MySQL-backed).
 *
 * Rules:
 * - Every 3 failed attempts: 15-second cooldown.
 * - At 5 failed attempts: 10-minute lock.
 */
require_once dirname(__DIR__) . '/db.php';

if (!function_exists('authRateLimiterKey')) {
    function authRateLimiterKey($scope, $identifier, $ipAddress = '') {
        $scope = strtolower(trim((string)$scope));
        $identifier = strtolower(trim((string)$identifier));
        $ipAddress = trim((string)$ipAddress);
        return hash('sha256', $scope . '|' . $identifier . '|' . $ipAddress);
    }
}

if (!function_exists('authRateLimiterStatus')) {
    function authRateLimiterStatus($config, $scope, $identifier, $ipAddress = '') {
        $scope = strtolower(trim((string)$scope));
        $identifier = strtolower(trim((string)$identifier));
        if ($identifier === '') {
            return ['blocked' => false, 'seconds_left' => 0, 'type' => 'none'];
        }

        try {
            $key = authRateLimiterKey($scope, $identifier, $ipAddress);
            $pdo = dbPdo($config);
            $stmt = $pdo->prepare('SELECT short_block_until, long_block_until FROM auth_rate_limits WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $key]);
            $doc = $stmt->fetch();
            if (!$doc) {
                return ['blocked' => false, 'seconds_left' => 0, 'type' => 'none'];
            }
            $now = time();
            $shortUntil = isset($doc['short_block_until']) ? (int)$doc['short_block_until'] : 0;
            $longUntil = isset($doc['long_block_until']) ? (int)$doc['long_block_until'] : 0;

            if ($longUntil > $now) {
                return ['blocked' => true, 'seconds_left' => $longUntil - $now, 'type' => 'long'];
            }
            if ($shortUntil > $now) {
                return ['blocked' => true, 'seconds_left' => $shortUntil - $now, 'type' => 'short'];
            }
            return ['blocked' => false, 'seconds_left' => 0, 'type' => 'none'];
        } catch (Exception $e) {
            return ['blocked' => false, 'seconds_left' => 0, 'type' => 'none'];
        }
    }
}

if (!function_exists('authRateLimiterFail')) {
    function authRateLimiterFail($config, $scope, $identifier, $ipAddress = '') {
        $scope = strtolower(trim((string)$scope));
        $identifier = strtolower(trim((string)$identifier));
        if ($identifier === '') {
            return ['blocked' => false, 'seconds_left' => 0, 'type' => 'none', 'attempts' => 0];
        }

        try {
            $key = authRateLimiterKey($scope, $identifier, $ipAddress);
            $pdo = dbPdo($config);
            $stmt = $pdo->prepare('SELECT attempts, long_block_until FROM auth_rate_limits WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $key]);
            $doc = $stmt->fetch();

            $now = time();
            $attempts = 0;
            if ($doc) {
                $attempts = (int)($doc['attempts'] ?? 0);
                $longUntil = (int)($doc['long_block_until'] ?? 0);
                if ($longUntil > $now) {
                    return ['blocked' => true, 'seconds_left' => $longUntil - $now, 'type' => 'long', 'attempts' => $attempts];
                }
                if ($longUntil > 0 && $longUntil <= $now) {
                    // Long lock expired; restart attempts fresh.
                    $attempts = 0;
                }
            }

            $attempts++;
            $shortBlockUntil = 0;
            $longBlockUntil = 0;
            $type = 'none';
            $seconds = 0;
            if ($attempts >= 5) {
                $longBlockUntil = $now + 600;
                $type = 'long';
                $seconds = 600;
                $attempts = 0; // reset after long lock starts
            } elseif ($attempts % 3 === 0) {
                $shortBlockUntil = $now + 15;
                $type = 'short';
                $seconds = 15;
            }

            $upsert = $pdo->prepare(
                'INSERT INTO auth_rate_limits
                    (id, scope, identifier, ip_address, attempts, short_block_until, long_block_until, updated_at)
                 VALUES
                    (:id, :scope, :identifier, :ip_address, :attempts, :short_block_until, :long_block_until, :updated_at)
                 ON DUPLICATE KEY UPDATE
                    scope = VALUES(scope),
                    identifier = VALUES(identifier),
                    ip_address = VALUES(ip_address),
                    attempts = VALUES(attempts),
                    short_block_until = VALUES(short_block_until),
                    long_block_until = VALUES(long_block_until),
                    updated_at = VALUES(updated_at)'
            );
            $upsert->execute([
                ':id' => $key,
                ':scope' => $scope,
                ':identifier' => $identifier,
                ':ip_address' => (string)$ipAddress,
                ':attempts' => $attempts,
                ':short_block_until' => $shortBlockUntil,
                ':long_block_until' => $longBlockUntil,
                ':updated_at' => dbNowUtcString(),
            ]);

            return ['blocked' => $seconds > 0, 'seconds_left' => $seconds, 'type' => $type, 'attempts' => $attempts];
        } catch (Exception $e) {
            return ['blocked' => false, 'seconds_left' => 0, 'type' => 'none', 'attempts' => 0];
        }
    }
}

if (!function_exists('authRateLimiterReset')) {
    function authRateLimiterReset($config, $scope, $identifier, $ipAddress = '') {
        $scope = strtolower(trim((string)$scope));
        $identifier = strtolower(trim((string)$identifier));
        if ($identifier === '') return false;

        try {
            $key = authRateLimiterKey($scope, $identifier, $ipAddress);
            $pdo = dbPdo($config);
            $stmt = $pdo->prepare('DELETE FROM auth_rate_limits WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $key]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('authRateLimiterMessage')) {
    function authRateLimiterMessage($status) {
        $seconds = max(0, (int)($status['seconds_left'] ?? 0));
        $type = (string)($status['type'] ?? 'short');
        if ($type === 'long') {
            $minutes = (int)ceil($seconds / 60);
            return 'Too many failed attempts. Please wait ' . $minutes . ' minute(s) before trying again.';
        }
        if ($type === 'short' && $seconds > 0) {
            return 'Incorrect credentials. Please wait ' . $seconds . ' second(s) before trying again.';
        }
        return 'Incorrect credentials.';
    }
}
