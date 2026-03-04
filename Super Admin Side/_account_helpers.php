<?php
/**
 * Shared helpers for account/settings/profile (signature, photo, password).
 * Requires $config (from config.php) to be loaded before including.
 */

if (!isset($config)) {
    $config = require dirname(__DIR__) . '/config.php';
}
require_once dirname(__DIR__) . '/db.php';

function getAccountManager() {
    global $config;
    return dbPdo($config);
}

function getUserUsername($userId) {
    if ($userId === '') return '';
    try {
        $pdo = getAccountManager();
        $stmt = $pdo->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $u = $stmt->fetch();
        return trim((string)($u['username'] ?? ''));
    } catch (Exception $e) {}
    return '';
}

function getUserSignature($userId) {
    if ($userId === '') return '';
    try {
        $pdo = getAccountManager();
        $stmt = $pdo->prepare('SELECT signature FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $u = $stmt->fetch();
        return trim((string)($u['signature'] ?? ''));
    } catch (Exception $e) {}
    return '';
}

function getUserPhoto($userId) {
    if ($userId === '') return '';
    try {
        $pdo = getAccountManager();
        $stmt = $pdo->prepare('SELECT photo FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $u = $stmt->fetch();
        $photo = $u['photo'] ?? '';
        return is_string($photo) ? trim($photo) : '';
    } catch (Exception $e) {}
    return '';
}

function ensureUserStampColumns() {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $pdo = getAccountManager();
        $cols = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM users');
        foreach ($stmt as $row) {
            $name = strtolower((string)($row['Field'] ?? ''));
            if ($name !== '') {
                $cols[$name] = true;
            }
        }
        if (!isset($cols['stamp'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN stamp LONGTEXT NULL AFTER signature');
        }
        if (!isset($cols['stamp_width_pct'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN stamp_width_pct DECIMAL(5,2) NOT NULL DEFAULT 18.00 AFTER stamp');
        }
        if (!isset($cols['stamp_x_pct'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN stamp_x_pct DECIMAL(5,2) NOT NULL DEFAULT 82.00 AFTER stamp_width_pct');
        }
        if (!isset($cols['stamp_y_pct'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN stamp_y_pct DECIMAL(5,2) NOT NULL DEFAULT 84.00 AFTER stamp_x_pct');
        }
    } catch (Exception $e) {
        // Best-effort backward-compatible migration.
    }
}

function getUserStampConfig($userId) {
    if ($userId === '') {
        return [
            'stamp' => '',
            'width_pct' => 18,
            'x_pct' => 82,
            'y_pct' => 84,
        ];
    }
    ensureUserStampColumns();
    try {
        $pdo = getAccountManager();
        $stmt = $pdo->prepare('SELECT stamp, stamp_width_pct, stamp_x_pct, stamp_y_pct FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $u = $stmt->fetch();
        $width = (float)($u['stamp_width_pct'] ?? 18);
        $x = (float)($u['stamp_x_pct'] ?? 82);
        $y = (float)($u['stamp_y_pct'] ?? 84);
        return [
            'stamp' => trim((string)($u['stamp'] ?? '')),
            'width_pct' => $width > 0 ? $width : 18,
            'x_pct' => $x >= 0 ? $x : 82,
            'y_pct' => $y >= 0 ? $y : 84,
        ];
    } catch (Exception $e) {
        return [
            'stamp' => '',
            'width_pct' => 18,
            'x_pct' => 82,
            'y_pct' => 84,
        ];
    }
}

function updateUserStamp($userId, $stampBase64, $widthPct = null, $xPct = null, $yPct = null) {
    if ($userId === '') return ['success' => false, 'message' => 'Not authenticated.'];
    $stampBase64 = trim((string)$stampBase64);
    if ($stampBase64 === '') return ['success' => false, 'message' => 'Stamp image is required.'];
    $currentCfg = getUserStampConfig($userId);
    if ($widthPct === null || $widthPct === '') $widthPct = (float)($currentCfg['width_pct'] ?? 18);
    if ($xPct === null || $xPct === '') $xPct = (float)($currentCfg['x_pct'] ?? 82);
    if ($yPct === null || $yPct === '') $yPct = (float)($currentCfg['y_pct'] ?? 84);
    $widthPct = max(5, min(60, (float)$widthPct));
    $xPct = max(5, min(95, (float)$xPct));
    $yPct = max(5, min(95, (float)$yPct));
    try {
        ensureUserStampColumns();
        $pdo = getAccountManager();
        $stmt = $pdo->prepare(
            'UPDATE users
             SET stamp = :stamp, stamp_width_pct = :width_pct, stamp_x_pct = :x_pct, stamp_y_pct = :y_pct, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':stamp' => $stampBase64,
            ':width_pct' => $widthPct,
            ':x_pct' => $xPct,
            ':y_pct' => $yPct,
            ':updated_at' => dbNowUtcString(),
            ':id' => $userId,
        ]);
        $_SESSION['user_stamp'] = $stampBase64;
        $_SESSION['user_stamp_width_pct'] = $widthPct;
        $_SESSION['user_stamp_x_pct'] = $xPct;
        $_SESSION['user_stamp_y_pct'] = $yPct;
        return ['success' => true, 'message' => 'Stamp updated successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

function updateUserSignature($userId, $signatureBase64) {
    global $config;
    if ($userId === '') return ['success' => false, 'message' => 'Not authenticated.'];
    $signatureBase64 = trim($signatureBase64);
    if ($signatureBase64 === '') return ['success' => false, 'message' => 'Signature is required.'];
    try {
        $pdo = getAccountManager();
        $stmt = $pdo->prepare('UPDATE users SET signature = :signature, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':signature' => $signatureBase64,
            ':updated_at' => dbNowUtcString(),
            ':id' => $userId,
        ]);
        $_SESSION['user_signature'] = $signatureBase64;
        return ['success' => true, 'message' => 'Signature updated successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

function updateUserPhoto($userId, $photoBase64) {
    global $config;
    if ($userId === '') return ['success' => false, 'message' => 'Not authenticated.'];
    $photoBase64 = trim($photoBase64);
    if ($photoBase64 === '') return ['success' => false, 'message' => 'Photo is required.'];
    try {
        $pdo = getAccountManager();
        $stmt = $pdo->prepare('UPDATE users SET photo = :photo, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':photo' => $photoBase64,
            ':updated_at' => dbNowUtcString(),
            ':id' => $userId,
        ]);
        $_SESSION['user_photo'] = $photoBase64;
        return ['success' => true, 'message' => 'Profile photo updated successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

function changePassword($userId, $currentPassword, $newPassword, $confirmPassword) {
    if ($userId === '') {
        return ['success' => false, 'message' => 'Not authenticated.'];
    }
    $newPassword = trim($newPassword);
    $confirmPassword = trim($confirmPassword);
    if (strlen($newPassword) < 6) {
        return ['success' => false, 'message' => 'New password must be at least 6 characters.'];
    }
    if ($newPassword !== $confirmPassword) {
        return ['success' => false, 'message' => 'New password and confirmation do not match.'];
    }
    try {
        $pdo = getAccountManager();
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return ['success' => false, 'message' => 'User not found.'];
        }
        $stored = $user['password'] ?? '';
        if (!password_verify($currentPassword, $stored) && $stored !== $currentPassword) {
            return ['success' => false, 'message' => 'Current password is incorrect.'];
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $up = $pdo->prepare('UPDATE users SET password = :password, updated_at = :updated_at WHERE id = :id');
        $up->execute([
            ':password' => $hash,
            ':updated_at' => dbNowUtcString(),
            ':id' => $userId,
        ]);
        return ['success' => true, 'message' => 'Password updated successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}
