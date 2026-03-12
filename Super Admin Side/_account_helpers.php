<?php
/**
 * Shared helpers for account/settings/profile (signature, photo, password).
 * Requires $config (from config.php) to be loaded before including.
 */

if (!isset($config)) {
    $config = require dirname(__DIR__) . '/config.php';
}
require_once dirname(__DIR__) . '/db.php';

function getUsersIdColumn() {
    static $idCol = null;
    if ($idCol !== null) {
        return $idCol;
    }
    $idCol = 'user_id';
    try {
        $pdo = getAccountManager();
        $stmt = $pdo->query('SHOW COLUMNS FROM users');
        $hasUserId = false;
        $hasLegacyId = false;
        foreach ($stmt as $row) {
            $field = strtolower((string)($row['Field'] ?? ''));
            if ($field === 'user_id') $hasUserId = true;
            if ($field === 'id') $hasLegacyId = true;
        }
        if ($hasUserId) {
            $idCol = 'user_id';
        } elseif ($hasLegacyId) {
            $idCol = 'id';
        }
    } catch (Exception $e) {
        $idCol = 'user_id';
    }
    return $idCol;
}

function getAccountManager() {
    global $config;
    return dbPdo($config);
}

function getUserUsername($userId) {
    if ($userId === '') return '';
    try {
        $pdo = getAccountManager();
        $idCol = getUsersIdColumn();
        $stmt = $pdo->prepare('SELECT username, name, email FROM users WHERE ' . $idCol . ' = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $u = $stmt->fetch();
        $username = trim((string)($u['username'] ?? ''));
        if ($username !== '') {
            return $username;
        }
        $name = trim((string)($u['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        return trim((string)($u['email'] ?? ''));
    } catch (Exception $e) {}
    return '';
}

function getUserSignature($userId) {
    if ($userId === '') return '';
    try {
        $pdo = getAccountManager();
        $idCol = getUsersIdColumn();
        $stmt = $pdo->prepare(
            'SELECT signature_path, signature
             FROM users
             WHERE ' . $idCol . ' = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $u = $stmt->fetch();
        $signature = trim((string)($u['signature_path'] ?? ''));
        if ($signature !== '') {
            return $signature;
        }
        return trim((string)($u['signature'] ?? ''));
    } catch (Exception $e) {}
    return '';
}

function getUserPhoto($userId) {
    if ($userId === '') return '';
    try {
        $pdo = getAccountManager();
        $idCol = getUsersIdColumn();
        $stmt = $pdo->prepare(
            'SELECT photo_path, photo
             FROM users
             WHERE ' . $idCol . ' = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $u = $stmt->fetch();
        $photo = $u['photo_path'] ?? ($u['photo'] ?? '');
        return is_string($photo) ? trim($photo) : '';
    } catch (Exception $e) {}
    return '';
}

function verifyCurrentPassword($userId, $currentPassword) {
    if ($userId === '') {
        return ['success' => false, 'message' => 'Not authenticated.'];
    }
    if ((string)$currentPassword === '') {
        return ['success' => false, 'message' => 'Current password is required.'];
    }
    try {
        $pdo = getAccountManager();
        $idCol = getUsersIdColumn();
        $stmt = $pdo->prepare('SELECT password FROM users WHERE ' . $idCol . ' = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return ['success' => false, 'message' => 'User not found.'];
        }
        $stored = (string)($user['password'] ?? '');
        if (!password_verify($currentPassword, $stored) && $stored !== $currentPassword) {
            return ['success' => false, 'message' => 'Current password is incorrect.'];
        }
        return ['success' => true, 'message' => 'Current password verified.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
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
        if (!isset($cols['signature_path'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN signature_path LONGTEXT NULL AFTER office_id');
        }
        if (!isset($cols['photo_path'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN photo_path LONGTEXT NULL AFTER signature_path');
        }
        if (!isset($cols['stamp_path'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN stamp_path LONGTEXT NULL AFTER photo_path');
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
        $idCol = getUsersIdColumn();
        $stmt = $pdo->prepare(
            'SELECT stamp_path, stamp, stamp_width_pct, stamp_x_pct, stamp_y_pct
             FROM users
             WHERE ' . $idCol . ' = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $u = $stmt->fetch();
        $width = (float)($u['stamp_width_pct'] ?? 18);
        $x = (float)($u['stamp_x_pct'] ?? 82);
        $y = (float)($u['stamp_y_pct'] ?? 84);
        return [
            'stamp' => trim((string)($u['stamp_path'] ?? ($u['stamp'] ?? ''))),
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
        $idCol = getUsersIdColumn();
        $stmt = $pdo->prepare(
            'UPDATE users
             SET stamp_path = :stamp, stamp_width_pct = :width_pct, stamp_x_pct = :x_pct, stamp_y_pct = :y_pct, updated_at = :updated_at
             WHERE ' . $idCol . ' = :id'
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
        $idCol = getUsersIdColumn();
        $stmt = $pdo->prepare('UPDATE users SET signature_path = :signature, updated_at = :updated_at WHERE ' . $idCol . ' = :id');
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
        $idCol = getUsersIdColumn();
        $stmt = $pdo->prepare('UPDATE users SET photo_path = :photo, updated_at = :updated_at WHERE ' . $idCol . ' = :id');
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

function updateUserProfileBasics($userId, $name, $username, $photoBase64 = null) {
    if ($userId === '') {
        return ['success' => false, 'message' => 'Not authenticated.'];
    }
    $name = trim((string)$name);
    $username = trim((string)$username);
    if ($name === '' || $username === '') {
        return ['success' => false, 'message' => 'Name and username are required.'];
    }
    if (strlen($username) < 3) {
        return ['success' => false, 'message' => 'Username must be at least 3 characters.'];
    }
    try {
        $pdo = getAccountManager();
        $idCol = getUsersIdColumn();
        $dup = $pdo->prepare('SELECT ' . $idCol . ' FROM users WHERE username = :username AND ' . $idCol . ' <> :id LIMIT 1');
        $dup->execute([
            ':username' => $username,
            ':id' => $userId,
        ]);
        if ($dup->fetch()) {
            return ['success' => false, 'message' => 'Username is already taken.'];
        }

        $params = [
            ':name' => $name,
            ':username' => $username,
            ':updated_at' => dbNowUtcString(),
            ':id' => $userId,
        ];
        if ($photoBase64 !== null) {
            $photoBase64 = trim((string)$photoBase64);
            if ($photoBase64 !== '') {
                $stmt = $pdo->prepare('UPDATE users SET name = :name, username = :username, photo_path = :photo, updated_at = :updated_at WHERE ' . $idCol . ' = :id');
                $params[':photo'] = $photoBase64;
            } else {
                $stmt = $pdo->prepare('UPDATE users SET name = :name, username = :username, updated_at = :updated_at WHERE ' . $idCol . ' = :id');
            }
        } else {
            $stmt = $pdo->prepare('UPDATE users SET name = :name, username = :username, updated_at = :updated_at WHERE ' . $idCol . ' = :id');
        }
        $stmt->execute($params);

        $_SESSION['user_name'] = $name;
        $_SESSION['user_username'] = $username;
        if (isset($params[':photo'])) {
            $_SESSION['user_photo'] = (string)$params[':photo'];
        }
        return ['success' => true, 'message' => 'Profile updated successfully.'];
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
    $verified = verifyCurrentPassword($userId, $currentPassword);
    if (empty($verified['success'])) {
        return $verified;
    }
    try {
        $pdo = getAccountManager();
        $idCol = getUsersIdColumn();
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $up = $pdo->prepare('UPDATE users SET password = :password, updated_at = :updated_at WHERE ' . $idCol . ' = :id');
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
