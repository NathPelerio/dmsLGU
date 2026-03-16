<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/_account_helpers.php';
require_once dirname(__DIR__) . '/Auth/smtp_mailer.php';

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'Super Admin';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$sidebar_active = 'users';
$welcomeUsername = getUserUsername($_SESSION['user_id'] ?? '') ?: ($_SESSION['user_username'] ?? $userName) ?: 'User';

if (!isset($config)) {
    $config = require dirname(__DIR__) . '/config.php';
}
require_once __DIR__ . '/_notifications_super_admin.php';
require_once __DIR__ . '/_activity_logger.php';
$notifData = getSuperAdminNotifications($config);
$notifCount = $notifData['count'];
$notifItems = $notifData['items'];

/**
 * Get users list.
 * @return array
 */
function getUsersList($config, $search = '') {
    try {
        $pdo = dbPdo($config);
        if ($search !== '') {
            $terms = [$search];
            foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                if (!in_array($part, $terms, true)) {
                    $terms[] = $part;
                }
            }
            $whereParts = [];
            $params = [];
            foreach ($terms as $idx => $term) {
                $paramKey = ':s' . $idx;
                $whereParts[] = "(username LIKE {$paramKey} OR name LIKE {$paramKey} OR email LIKE {$paramKey})";
                $params[$paramKey] = '%' . $term . '%';
            }
            $sql = 'SELECT *
                    FROM users
                    WHERE ' . implode(' OR ', $whereParts) . '
                    ORDER BY COALESCE(NULLIF(username, \'\'), NULLIF(name, \'\'), email) ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } else {
            $stmt = $pdo->query('SELECT * FROM users ORDER BY COALESCE(NULLIF(username, \'\'), NULLIF(name, \'\'), email) ASC');
        }
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get office lookup maps for user table display.
 * @return array [officeNameById, officeNameByHeadUserId, officeCodeByDeptNameKey]
 */
function getOfficeLookupMaps($config) {
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->query(
            "SELECT
                o.office_id AS id,
                o.office_name,
                o.office_code,
                h.user_id AS office_head_id
             FROM offices o
             LEFT JOIN users h
                ON h.office_id = o.office_id
               AND LOWER(TRIM(h.role)) IN ('departmenthead', 'department_head', 'dept_head')
             ORDER BY o.office_name ASC, h.user_id ASC"
        );
        $rows = $stmt->fetchAll();
        $officeNameById = [];
        $officeNameByHeadUserId = [];
        $officeCodeByDeptNameKey = [];
        foreach ($rows as $row) {
            $officeId = trim((string)($row['id'] ?? $row['_id'] ?? ''));
            $officeName = trim((string)($row['office_name'] ?? ''));
            $officeCode = trim((string)($row['office_code'] ?? ''));
            $headUserId = trim((string)($row['office_head_id'] ?? ''));
            if ($officeId !== '' && $officeName !== '') {
                $officeNameById[$officeId] = $officeName;
                if ($officeCode !== '') {
                    $officeCodeByDeptNameKey[strtolower($officeName)] = $officeCode;
                }
            }
            if ($headUserId !== '' && $officeName !== '') {
                $officeNameByHeadUserId[$headUserId] = $officeName;
            }
        }
        return [$officeNameById, $officeNameByHeadUserId, $officeCodeByDeptNameKey];
    } catch (Exception $e) {
        return [[], [], []];
    }
}

function ensureUserSuspensionMonotonicColumn($config) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $pdo = dbPdo($config);
        $hasMono = false;
        $stmt = $pdo->query('SHOW COLUMNS FROM users');
        foreach ($stmt as $row) {
            $field = strtolower((string)($row['Field'] ?? ''));
            if ($field === 'suspended_mono_ns') {
                $hasMono = true;
                break;
            }
        }
        if (!$hasMono) {
            $pdo->exec('ALTER TABLE users ADD COLUMN suspended_mono_ns BIGINT NULL AFTER suspended_at');
        }
    } catch (Exception $e) {
        // Best-effort migration.
    }
}

/**
 * Normalize email input for add-user flows.
 * Allows plain Gmail username (e.g. "sunga123") and converts it to "sunga123@gmail.com".
 */
function normalizeAddUserEmail($rawEmail) {
    $value = strtolower(trim((string)$rawEmail));
    if ($value === '') {
        return '';
    }
    if (strpos($value, '@') === false) {
        if (!preg_match('/^[a-z0-9._%+\-]+$/i', $value)) {
            return '';
        }
        return $value . '@gmail.com';
    }
    return $value;
}

/**
 * Add a new user to the database.
 * @return array ['success' => bool, 'message' => string]
 */
function addUser($config, $username, $name, $email, $password, $role) {
    $username = trim($username);
    $name = trim($name);
    $email = normalizeAddUserEmail($email);
    if ($username === '' || $email === '') {
        return ['success' => false, 'message' => 'Username and email are required.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Enter a valid Gmail/email address.'];
    }
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/', $password)) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters and include uppercase, lowercase, number, and special character.'];
    }
    $allowedRoles = ['superadmin', 'admin', 'user', 'staff', 'departmenthead', 'department_head', 'dept_head'];
    $role = strtolower(trim($role));
    if ($role === 'frontdesk') {
        $role = 'staff';
    }
    if (!in_array($role, $allowedRoles)) {
        $role = 'user';
    }
    try {
        $pdo = dbPdo($config);
        $check = $pdo->prepare('SELECT user_id FROM users WHERE username = :username OR email = :email LIMIT 1');
        $check->execute([':username' => $username, ':email' => $email]);
        if ($check->fetch()) {
            return ['success' => false, 'message' => 'Username or email already exists.'];
        }
        $officeId = null;
        if (in_array($role, ['superadmin', 'admin', 'staff'], true)) {
            $officeStmt = $pdo->prepare(
                "SELECT office_id
                 FROM offices
                 WHERE UPPER(TRIM(office_code)) = 'MMO'
                    OR LOWER(TRIM(office_name)) = LOWER(:office_name)
                 ORDER BY CASE WHEN UPPER(TRIM(office_code)) = 'MMO' THEN 0 ELSE 1 END, office_id ASC
                 LIMIT 1"
            );
            $officeStmt->execute([':office_name' => "Municipal Mayor's Office"]);
            $officeRow = $officeStmt->fetch();
            if (!$officeRow) {
                return ['success' => false, 'message' => "Municipal Mayor's Office is not configured. Please add it in Offices/Department first."];
            }
            $officeId = (int)($officeRow['office_id'] ?? 0);
            if ($officeId <= 0) {
                return ['success' => false, 'message' => "Unable to resolve Municipal Mayor's Office. Please verify office setup."];
            }
        }
        $insert = $pdo->prepare(
            'INSERT INTO users (username, name, email, password, role, office_id, created_at)
             VALUES (:username, :name, :email, :password, :role, :office_id, :created_at)'
        );
        $insert->execute([
            ':username' => $username,
            ':name' => $name,
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => $role,
            ':office_id' => $officeId,
            ':created_at' => dbNowUtcString(),
        ]);
        return ['success' => true, 'message' => 'User added successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

function sendAddUserOtpEmail($toEmail, $otp, $config, $displayName = '') {
    $expiryMinutes = (int)($config['otp_expiry_minutes'] ?? 5);
    if ($expiryMinutes <= 0) {
        $expiryMinutes = 5;
    }
    $nameLine = trim($displayName) !== '' ? trim($displayName) : 'User';
    $subject = 'DMS LGU Solano - Add User OTP Code';
    $message = "Good day, {$nameLine}.\n\n"
        . "A request was made to add a user account in DMS LGU Solano.\n"
        . "Your one-time verification code is: {$otp}\n\n"
        . "This code expires in {$expiryMinutes} minute(s).\n"
        . "If you did not request this, please ignore this message.\n\n"
        . "Regards,\n"
        . "DMS LGU Solano";
    return sendEmailViaSmtp($toEmail, $subject, $message, $config);
}

function sendEditUserChangeOtpEmail($toEmail, $otp, $config, $displayName = '') {
    $expiryMinutes = (int)($config['otp_expiry_minutes'] ?? 5);
    if ($expiryMinutes <= 0) {
        $expiryMinutes = 5;
    }
    $nameLine = trim($displayName) !== '' ? trim($displayName) : 'User';
    $subject = 'DMS LGU Solano - Edit User OTP Code';
    $message = "Good day, {$nameLine}.\n\n"
        . "A request was made to edit user information in DMS LGU Solano.\n"
        . "Your one-time verification code is: {$otp}\n\n"
        . "This code expires in {$expiryMinutes} minute(s).\n"
        . "If you did not request this, please ignore this message.\n\n"
        . "Regards,\n"
        . "DMS LGU Solano";
    return sendEmailViaSmtp($toEmail, $subject, $message, $config);
}

/**
 * Disable or suspend a user account.
 * @return array ['success' => bool, 'message' => string]
 */
function updateUserAccountState($config, $targetUserId, $mode, $reason, $durationValue = 0, $durationUnit = 'hours') {
    $targetUserId = trim((string)$targetUserId);
    $mode = strtolower(trim((string)$mode));
    $reason = trim((string)$reason);
    $durationValue = (int)$durationValue;
    $durationUnit = strtolower(trim((string)$durationUnit));

    if ($targetUserId === '') {
        return ['success' => false, 'message' => 'Invalid user ID.'];
    }
    if (!in_array($mode, ['disable', 'suspend', 'enable'], true)) {
        return ['success' => false, 'message' => 'Invalid account action.'];
    }
    if (in_array($mode, ['disable', 'suspend'], true) && $reason === '') {
        return ['success' => false, 'message' => 'Reason is required.'];
    }
    if (!empty($_SESSION['user_id']) && $targetUserId === (string)$_SESSION['user_id']) {
        return ['success' => false, 'message' => 'You cannot apply this action to your own account.'];
    }

    try {
        ensureUserSuspensionMonotonicColumn($config);
        $pdo = dbPdo($config);
        if ($mode === 'disable') {
            $stmt = $pdo->prepare(
                'UPDATE users
                 SET account_state = :state, disabled_reason = :disabled_reason, disabled_at = :disabled_at,
                    suspend_reason = NULL, suspended_at = NULL, suspended_mono_ns = NULL, suspended_until = NULL,
                     suspend_duration_value = NULL, suspend_duration_unit = NULL, updated_at = :updated_at
                 WHERE user_id = :id'
            );
            $ok = $stmt->execute([
                ':state' => 'disabled',
                ':disabled_reason' => $reason,
                ':disabled_at' => dbNowUtcString(),
                ':updated_at' => dbNowUtcString(),
                ':id' => $targetUserId,
            ]);
        } elseif ($mode === 'suspend') {
            $allowedUnits = ['hours', 'days', 'weeks', 'months', 'years'];
            if ($durationValue <= 0) {
                return ['success' => false, 'message' => 'Suspend duration must be greater than zero.'];
            }
            if (!in_array($durationUnit, $allowedUnits, true)) {
                return ['success' => false, 'message' => 'Invalid suspend duration unit.'];
            }
            $map = ['hours' => 'hour', 'days' => 'day', 'weeks' => 'week', 'months' => 'month', 'years' => 'year'];
            $untilDt = new DateTime('now', new DateTimeZone('UTC'));
            $untilDt->modify('+' . $durationValue . ' ' . $map[$durationUnit]);
            $monoNowNs = function_exists('hrtime') ? @hrtime(true) : null;
            $stmt = $pdo->prepare(
                'UPDATE users
                 SET account_state = :state, suspend_reason = :suspend_reason, suspended_at = :suspended_at,
                    suspended_mono_ns = :suspended_mono_ns, suspended_until = :suspended_until, suspend_duration_value = :duration_value,
                     suspend_duration_unit = :duration_unit, disabled_reason = NULL, disabled_at = NULL,
                     updated_at = :updated_at
                 WHERE user_id = :id'
            );
            $ok = $stmt->execute([
                ':state' => 'suspended',
                ':suspend_reason' => $reason,
                ':suspended_at' => dbNowUtcString(),
                ':suspended_mono_ns' => is_int($monoNowNs) ? $monoNowNs : null,
                ':suspended_until' => $untilDt->format('Y-m-d H:i:s'),
                ':duration_value' => $durationValue,
                ':duration_unit' => $durationUnit,
                ':updated_at' => dbNowUtcString(),
                ':id' => $targetUserId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE users
                 SET account_state = :state, enabled_at = :enabled_at, disabled_reason = NULL, disabled_at = NULL,
                    suspend_reason = NULL, suspended_at = NULL, suspended_mono_ns = NULL, suspended_until = NULL,
                     suspend_duration_value = NULL, suspend_duration_unit = NULL, updated_at = :updated_at
                 WHERE user_id = :id'
            );
            $ok = $stmt->execute([
                ':state' => 'active',
                ':enabled_at' => dbNowUtcString(),
                ':updated_at' => dbNowUtcString(),
                ':id' => $targetUserId,
            ]);
        }

        if (!$ok || $stmt->rowCount() < 1) {
            return ['success' => false, 'message' => 'No changes applied. User may not exist.'];
        }
        if ($mode === 'disable') {
            return ['success' => true, 'message' => 'User disabled successfully.'];
        }
        if ($mode === 'suspend') {
            return ['success' => true, 'message' => 'User suspended successfully.'];
        }
        return ['success' => true, 'message' => 'User enabled successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

$msg = $_GET['msg'] ?? null;
$msgOk = isset($_GET['ok']) && $_GET['ok'] === '1';
$search = trim($_GET['search'] ?? '');
$roleFilter = strtolower(trim((string)($_GET['role'] ?? '')));
$allowedRoleFilters = ['superadmin', 'admin', 'staff', 'frontdesk', 'departmenthead', 'department_head', 'dept_head'];
if ($roleFilter === 'frontdesk') {
    $roleFilter = 'staff';
}
if ($roleFilter !== '' && !in_array($roleFilter, $allowedRoleFilters, true)) {
    $roleFilter = '';
}
$departmentFilter = trim((string)($_GET['dept'] ?? ''));
$openAddUserModal = isset($_GET['open_add_user']) && $_GET['open_add_user'] === '1';
$addUserInvalidField = trim((string)($_GET['field'] ?? ''));
$addUserSuccess = $msgOk && $msg === 'User added successfully.';
$otpInlineError = ($openAddUserModal && $addUserInvalidField === 'otp') ? 'Invalid OTP' : '';
$openEditUserModal = false;
$editOriginalEmail = '';
$editModalData = [
    'user_id' => '',
    'username' => '',
    'name' => '',
    'email' => '',
    'role' => 'user',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'send_add_user_otp') {
        header('Content-Type: application/json');
        $email = normalizeAddUserEmail($_POST['email'] ?? '');
        $name = trim((string)($_POST['name'] ?? $_POST['username'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Enter a valid Gmail/email first. You may type only the Gmail username (before @gmail.com).']);
            exit;
        }
        $nextResendAt = (int)($_SESSION['add_user_otp_resend_at'] ?? 0);
        $now = time();
        if ($nextResendAt > $now) {
            $retryAfter = $nextResendAt - $now;
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => 'Please wait ' . $retryAfter . ' second(s) before resending OTP.',
                'retry_after' => $retryAfter,
            ]);
            exit;
        }
        $expiryMinutes = (int)($config['otp_expiry_minutes'] ?? 5);
        if ($expiryMinutes <= 0) {
            $expiryMinutes = 5;
        }
        $otp = (string)random_int(100000, 999999);
        $_SESSION['add_user_otp_code_hash'] = password_hash($otp, PASSWORD_DEFAULT);
        $_SESSION['add_user_otp_email'] = strtolower($email);
        $_SESSION['add_user_otp_expires_at'] = time() + ($expiryMinutes * 60);
        $_SESSION['add_user_otp_resend_at'] = time() + 30;
        $mailError = '';
        if (!sendEmailViaSmtp(
            $email,
            'DMS LGU Solano - Add User OTP Code',
            "Good day, " . (trim($name) !== '' ? trim($name) : 'User') . ".\n\n"
                . "A request was made to add a user account in DMS LGU Solano.\n"
                . "Your one-time verification code is: {$otp}\n\n"
                . "This code expires in {$expiryMinutes} minute(s).\n"
                . "If you did not request this, please ignore this message.\n\n"
                . "Regards,\nDMS LGU Solano",
            $config,
            '',
            $mailError
        )) {
            error_log('Add user OTP send failed: ' . (string)$mailError);
            http_response_code(500);
            $safeReason = trim((string)$mailError) !== '' ? trim((string)$mailError) : 'Unknown SMTP error.';
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP. ' . $safeReason]);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'OTP has been sent to the provided Gmail/email.']);
        exit;
    }
    if ($action === 'send_edit_user_change_otp') {
        header('Content-Type: application/json');
        $userId = trim((string)($_POST['user_id'] ?? ''));
        $newEmail = trim((string)($_POST['new_email'] ?? ''));
        $name = trim((string)($_POST['name'] ?? $_POST['username'] ?? ''));
        if ($userId === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid user selected.']);
            exit;
        }
        if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Enter a valid Gmail/email address first.']);
            exit;
        }
        try {
            $pdo = dbPdo($config);
            $userStmt = $pdo->prepare('SELECT email FROM users WHERE user_id = :id LIMIT 1');
            $userStmt->execute([':id' => $userId]);
            $existingUser = $userStmt->fetch() ?: null;
        } catch (Exception $e) {
            $existingUser = null;
        }
        if (!$existingUser) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid user selected.']);
            exit;
        }
        $currentEmail = strtolower(trim((string)($existingUser['email'] ?? '')));
        $targetEmail = strtolower($newEmail);
        if ($currentEmail !== '' && $targetEmail === $currentEmail) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Email did not change.']);
            exit;
        }
        if ($currentEmail === '' || !filter_var($currentEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Current account email is invalid.']);
            exit;
        }
        $nextResendAt = (int)($_SESSION['edit_user_change_otp_resend_at'] ?? 0);
        $now = time();
        if ($nextResendAt > $now) {
            $retryAfter = $nextResendAt - $now;
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => 'Please wait ' . $retryAfter . ' second(s) before resending OTP.',
                'retry_after' => $retryAfter,
            ]);
            exit;
        }
        $expiryMinutes = (int)($config['otp_expiry_minutes'] ?? 5);
        if ($expiryMinutes <= 0) {
            $expiryMinutes = 5;
        }
        $otp = (string)random_int(100000, 999999);
        $_SESSION['edit_user_change_otp_code_hash'] = password_hash($otp, PASSWORD_DEFAULT);
        $_SESSION['edit_user_change_otp_user_id'] = $userId;
        $_SESSION['edit_user_change_otp_old_email'] = $currentEmail;
        $_SESSION['edit_user_change_otp_new_email'] = $targetEmail;
        $_SESSION['edit_user_change_otp_expires_at'] = time() + ($expiryMinutes * 60);
        $_SESSION['edit_user_change_otp_resend_at'] = time() + 15;
        $mailError = '';
        if (!sendEmailViaSmtp(
            $currentEmail,
            'DMS LGU Solano - Edit User OTP Code',
            "Good day, " . (trim($name) !== '' ? trim($name) : 'User') . ".\n\n"
                . "A request was made to edit user information in DMS LGU Solano.\n"
                . "Your one-time verification code is: {$otp}\n\n"
                . "This code expires in {$expiryMinutes} minute(s).\n"
                . "If you did not request this, please ignore this message.\n\n"
                . "Regards,\nDMS LGU Solano",
            $config,
            '',
            $mailError
        )) {
            error_log('Edit user OTP send failed: ' . (string)$mailError);
            http_response_code(500);
            $safeReason = trim((string)$mailError) !== '' ? trim((string)$mailError) : 'Unknown SMTP error.';
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP. ' . $safeReason]);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'OTP sent to old Gmail/email for confirmation.']);
        exit;
    }
    if ($action === 'verify_edit_user_change_otp') {
        header('Content-Type: application/json');
        $userId = trim((string)($_POST['user_id'] ?? ''));
        $newEmail = strtolower(trim((string)($_POST['new_email'] ?? '')));
        $otpInput = trim((string)($_POST['otp'] ?? ''));
        $otpHash = (string)($_SESSION['edit_user_change_otp_code_hash'] ?? '');
        $otpUserId = trim((string)($_SESSION['edit_user_change_otp_user_id'] ?? ''));
        $otpOldEmail = strtolower(trim((string)($_SESSION['edit_user_change_otp_old_email'] ?? '')));
        $otpNewEmail = strtolower(trim((string)($_SESSION['edit_user_change_otp_new_email'] ?? '')));
        $otpExpiresAt = (int)($_SESSION['edit_user_change_otp_expires_at'] ?? 0);

        if ($userId === '' || $newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid email change request.']);
            exit;
        }
        if (!preg_match('/^\d{6}$/', $otpInput)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Please enter a valid 6-digit OTP.']);
            exit;
        }
        if ($otpHash === '' || $otpUserId === '' || $otpOldEmail === '' || $otpNewEmail === '' || $otpExpiresAt <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'No OTP request found. Please resend OTP.']);
            exit;
        }
        if ($userId !== $otpUserId || $newEmail !== $otpNewEmail) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Email changed after OTP request. Please resend OTP.']);
            exit;
        }
        if (time() > $otpExpiresAt) {
            unset($_SESSION['edit_user_change_otp_code_hash'], $_SESSION['edit_user_change_otp_user_id'], $_SESSION['edit_user_change_otp_old_email'], $_SESSION['edit_user_change_otp_new_email'], $_SESSION['edit_user_change_otp_expires_at']);
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'OTP expired. Please resend OTP.']);
            exit;
        }
        if (!password_verify($otpInput, $otpHash)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Incorrect OTP. Please retype or resend OTP.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'OTP verified.']);
        exit;
    }
    if ($action === 'add_user') {
        $addUserError = null;
        $addUserInvalidField = '';
        $rawPassword = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $otpInput = trim((string)($_POST['email_otp'] ?? ''));
        $otpHash = (string)($_SESSION['add_user_otp_code_hash'] ?? '');
        $otpEmail = (string)($_SESSION['add_user_otp_email'] ?? '');
        $otpExpiresAt = (int)($_SESSION['add_user_otp_expires_at'] ?? 0);
        $formEmail = normalizeAddUserEmail($_POST['email'] ?? '');
        $_POST['email'] = $formEmail;

        if ($formEmail === '' || !filter_var($formEmail, FILTER_VALIDATE_EMAIL)) {
            $addUserError = 'Enter a valid Gmail/email address first.';
            $addUserInvalidField = '';
        }
        if ($otpInput === '') {
            $addUserError = 'OTP is required. Please click Send OTP first.';
            $addUserInvalidField = 'otp';
        }
        if ($addUserError === null && ($otpHash === '' || $otpEmail === '' || $otpExpiresAt <= 0)) {
            $addUserError = 'No OTP request found. Please send OTP again.';
            $addUserInvalidField = 'otp';
        }
        if ($addUserError === null && ($formEmail === '' || $formEmail !== $otpEmail)) {
            $addUserError = 'Email changed after OTP request. Please resend OTP.';
            $addUserInvalidField = 'otp';
        }
        if ($addUserError === null && time() > $otpExpiresAt) {
            unset($_SESSION['add_user_otp_code_hash'], $_SESSION['add_user_otp_email'], $_SESSION['add_user_otp_expires_at']);
            $addUserError = 'OTP has expired. Please request a new OTP.';
            $addUserInvalidField = 'otp';
        }
        if ($addUserError === null && !password_verify($otpInput, $otpHash)) {
            $addUserError = 'Invalid OTP';
            $addUserInvalidField = 'otp';
        }
        if ($addUserError === null && $rawPassword !== $confirmPassword) {
            $addUserError = 'Password and retype password do not match.';
            $addUserInvalidField = '';
        }
        if ($addUserError === null) {
            $flash = addUser(
                $config,
                $_POST['username'] ?? '',
                $_POST['name'] ?? '',
                $formEmail,
                $rawPassword,
                $_POST['role'] ?? 'user'
            );
            if ($flash) {
                if (!empty($flash['success'])) {
                    unset($_SESSION['add_user_otp_code_hash'], $_SESSION['add_user_otp_email'], $_SESSION['add_user_otp_expires_at'], $_SESSION['add_user_otp_resend_at']);
                    activityLog($config, 'user_add', [
                        'module' => 'super_admin_users',
                        'target_name' => trim((string)($_POST['name'] ?? $_POST['username'] ?? $_POST['email'] ?? '')),
                        'target_username' => trim((string)($_POST['username'] ?? '')),
                        'target_email' => $formEmail,
                        'target_role' => trim((string)($_POST['role'] ?? 'user')),
                    ]);
                } else {
                    $addUserError = (string)($flash['message'] ?? 'Failed to add user.');
                }
                if (!empty($flash['success'])) {
                    header('Location: users.php?msg=' . urlencode($flash['message']) . '&ok=1');
                    exit;
                }
            }
        }
        if ($addUserError !== null) {
            $msg = $addUserError;
            $msgOk = false;
            $openAddUserModal = true;
        }
    } elseif ($action === 'update_user') {
        $editModalData['user_id'] = trim((string)($_POST['user_id'] ?? ''));
        $editModalData['username'] = trim((string)($_POST['username'] ?? ''));
        $editModalData['name'] = trim((string)($_POST['name'] ?? ''));
        $editModalData['email'] = trim((string)($_POST['email'] ?? ''));
        $editModalData['role'] = strtolower(trim((string)($_POST['role'] ?? '')));
        if ($editModalData['role'] === 'frontdesk') {
            $editModalData['role'] = 'staff';
        }
        $openEditUserModal = true;

        $newPassword = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $editOtpInput = trim((string)($_POST['edit_email_change_otp'] ?? ''));
        $editOtpHash = (string)($_SESSION['edit_user_change_otp_code_hash'] ?? '');
        $editOtpUserId = trim((string)($_SESSION['edit_user_change_otp_user_id'] ?? ''));
        $editOtpOldEmail = strtolower(trim((string)($_SESSION['edit_user_change_otp_old_email'] ?? '')));
        $editOtpNewEmail = strtolower(trim((string)($_SESSION['edit_user_change_otp_new_email'] ?? '')));
        $editOtpExpiresAt = (int)($_SESSION['edit_user_change_otp_expires_at'] ?? 0);
        $formEmail = strtolower(trim((string)$editModalData['email']));
        $allowedRoles = ['superadmin', 'admin', 'user', 'staff', 'departmenthead', 'department_head', 'dept_head'];
        $currentUser = null;
        if ($editModalData['user_id'] !== '') {
            try {
                $pdo = dbPdo($config);
                $currentUserStmt = $pdo->prepare('SELECT email, role FROM users WHERE user_id = :id LIMIT 1');
                $currentUserStmt->execute([':id' => $editModalData['user_id']]);
                $currentUser = $currentUserStmt->fetch() ?: null;
                $editOriginalEmail = trim((string)($currentUser['email'] ?? ''));
            } catch (Exception $e) {
                $currentUser = null;
                $editOriginalEmail = '';
            }
        }
        $currentRole = strtolower(trim((string)($currentUser['role'] ?? 'user')));
        if ($currentRole === '') {
            $currentRole = 'user';
        }
        if (!in_array($currentRole, $allowedRoles, true)) {
            $currentRole = 'user';
        }
        if (!in_array($editModalData['role'], $allowedRoles, true)) {
            // Preserve existing role when form role is empty/invalid.
            $editModalData['role'] = $currentRole;
        }
        $emailChanged = strtolower($editOriginalEmail) !== $formEmail;
        if ($editModalData['user_id'] === '' || !$currentUser) {
            $msg = 'Invalid user selected.';
            $msgOk = false;
        } elseif ($editModalData['username'] === '' || $editModalData['email'] === '') {
            $msg = 'Username and email are required.';
            $msgOk = false;
        } elseif (!filter_var($editModalData['email'], FILTER_VALIDATE_EMAIL)) {
            $msg = 'Please enter a valid email address.';
            $msgOk = false;
        } elseif ($emailChanged && $editOtpInput === '') {
            $msg = 'OTP confirmation is required to change email.';
            $msgOk = false;
        } elseif ($emailChanged && ($editOtpHash === '' || $editOtpUserId === '' || $editOtpOldEmail === '' || $editOtpNewEmail === '' || $editOtpExpiresAt <= 0)) {
            $msg = 'OTP request not found. Please try saving again.';
            $msgOk = false;
        } elseif ($emailChanged && ($editModalData['user_id'] !== $editOtpUserId || $formEmail === '' || $formEmail !== $editOtpNewEmail || strtolower($editOriginalEmail) !== $editOtpOldEmail)) {
            $msg = 'Email changed after OTP request. Please try again.';
            $msgOk = false;
        } elseif ($emailChanged && time() > $editOtpExpiresAt) {
            unset($_SESSION['edit_user_change_otp_code_hash'], $_SESSION['edit_user_change_otp_user_id'], $_SESSION['edit_user_change_otp_old_email'], $_SESSION['edit_user_change_otp_new_email'], $_SESSION['edit_user_change_otp_expires_at']);
            $msg = 'OTP expired. Please try again.';
            $msgOk = false;
        } elseif ($emailChanged && !password_verify($editOtpInput, $editOtpHash)) {
            $msg = 'Invalid OTP confirmation code.';
            $msgOk = false;
        } elseif ($newPassword !== '' && !preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/', $newPassword)) {
            $msg = 'Password must be at least 8 characters and include uppercase, lowercase, number, and special character.';
            $msgOk = false;
        } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {
            $msg = 'Password and retype password do not match.';
            $msgOk = false;
        } else {
            try {
                $pdo = dbPdo($config);
                $check = $pdo->prepare('SELECT user_id FROM users WHERE (username = :username OR email = :email) AND user_id <> :id LIMIT 1');
                $check->execute([
                    ':username' => $editModalData['username'],
                    ':email' => $editModalData['email'],
                    ':id' => $editModalData['user_id'],
                ]);
                if ($check->fetch()) {
                    $msg = 'Username or email already exists.';
                    $msgOk = false;
                } else {
                    if ($newPassword !== '') {
                        $stmt = $pdo->prepare(
                            'UPDATE users
                             SET username = :username, name = :name, email = :email, role = :role,
                                 password = :password, updated_at = :updated_at
                             WHERE user_id = :id'
                        );
                        $ok = $stmt->execute([
                            ':username' => $editModalData['username'],
                            ':name' => $editModalData['name'],
                            ':email' => $editModalData['email'],
                            ':role' => $editModalData['role'],
                            ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
                            ':updated_at' => dbNowUtcString(),
                            ':id' => $editModalData['user_id'],
                        ]);
                    } else {
                        $stmt = $pdo->prepare(
                            'UPDATE users
                             SET username = :username, name = :name, email = :email, role = :role, updated_at = :updated_at
                             WHERE user_id = :id'
                        );
                        $ok = $stmt->execute([
                            ':username' => $editModalData['username'],
                            ':name' => $editModalData['name'],
                            ':email' => $editModalData['email'],
                            ':role' => $editModalData['role'],
                            ':updated_at' => dbNowUtcString(),
                            ':id' => $editModalData['user_id'],
                        ]);
                    }
                    if ($ok) {
                        unset($_SESSION['edit_user_change_otp_code_hash'], $_SESSION['edit_user_change_otp_user_id'], $_SESSION['edit_user_change_otp_old_email'], $_SESSION['edit_user_change_otp_new_email'], $_SESSION['edit_user_change_otp_expires_at'], $_SESSION['edit_user_change_otp_resend_at']);
                        activityLog($config, 'user_edit', [
                            'module' => 'super_admin_users',
                            'target_user_id' => $editModalData['user_id'],
                            'target_name' => $editModalData['name'] !== '' ? $editModalData['name'] : $editModalData['username'],
                            'target_username' => $editModalData['username'],
                            'target_email' => $editModalData['email'],
                            'target_role' => $editModalData['role'],
                            'password_changed' => $newPassword !== '' ? 'yes' : 'no',
                        ]);
                        header('Location: users.php?msg=' . urlencode('User updated successfully.') . '&ok=1');
                        exit;
                    }
                    $msg = 'Failed to update user.';
                    $msgOk = false;
                }
            } catch (Exception $e) {
                $msg = 'Error: ' . $e->getMessage();
                $msgOk = false;
            }
        }
    } elseif ($action === 'disable_user') {
        $flash = updateUserAccountState(
            $config,
            $_POST['user_id'] ?? '',
            'disable',
            $_POST['reason'] ?? ''
        );
        if (!empty($flash['success'])) {
            activityLog($config, 'user_disable', [
                'module' => 'super_admin_users',
                'target_user_id' => trim((string)($_POST['user_id'] ?? '')),
                'target_name' => trim((string)($_POST['target_name'] ?? '')),
                'reason' => trim((string)($_POST['reason'] ?? '')),
            ]);
        }
        header('Location: users.php?msg=' . urlencode($flash['message']) . '&ok=' . ($flash['success'] ? '1' : '0'));
        exit;
    } elseif ($action === 'suspend_user') {
        $flash = updateUserAccountState(
            $config,
            $_POST['user_id'] ?? '',
            'suspend',
            $_POST['reason'] ?? '',
            (int)($_POST['duration_value'] ?? 0),
            $_POST['duration_unit'] ?? 'hours'
        );
        if (!empty($flash['success'])) {
            activityLog($config, 'user_suspend', [
                'module' => 'super_admin_users',
                'target_user_id' => trim((string)($_POST['user_id'] ?? '')),
                'target_name' => trim((string)($_POST['target_name'] ?? '')),
                'duration_value' => (string)((int)($_POST['duration_value'] ?? 0)),
                'duration_unit' => trim((string)($_POST['duration_unit'] ?? '')),
                'reason' => trim((string)($_POST['reason'] ?? '')),
            ]);
        }
        header('Location: users.php?msg=' . urlencode($flash['message']) . '&ok=' . ($flash['success'] ? '1' : '0'));
        exit;
    } elseif ($action === 'enable_user') {
        $flash = updateUserAccountState(
            $config,
            $_POST['user_id'] ?? '',
            'enable',
            ''
        );
        if (!empty($flash['success'])) {
            activityLog($config, 'user_enable', [
                'module' => 'super_admin_users',
                'target_user_id' => trim((string)($_POST['user_id'] ?? '')),
                'target_name' => trim((string)($_POST['target_name'] ?? '')),
            ]);
        }
        header('Location: users.php?msg=' . urlencode($flash['message']) . '&ok=' . ($flash['success'] ? '1' : '0'));
        exit;
    }
}

function formatRoleLabel($role) {
    $r = strtolower(trim($role ?? ''));
    $labels = [
        'superadmin' => 'Super Admin',
        'admin' => 'Admin',
        'user' => 'User',
        'staff' => 'Frontdesk',
        'departmenthead' => 'Department Head',
        'department_head' => 'Department Head',
        'dept_head' => 'Department Head',
    ];
    return $labels[$r] ?? ucfirst($r) ?: '—';
}

function resolveUserDepartmentName($u, $officeNameById, $officeNameByHeadUserId) {
    $dept = trim((string)($u['department'] ?? $u['user_department'] ?? $u['office_name'] ?? $u['office'] ?? ''));
    if ($dept === '') {
        $assignedOfficeId = trim((string)($u['office_id'] ?? $u['department_id'] ?? ''));
        if ($assignedOfficeId !== '' && isset($officeNameById[$assignedOfficeId])) {
            $dept = trim((string)$officeNameById[$assignedOfficeId]);
        }
    }
    if ($dept === '') {
        $currentUserId = trim((string)($u['user_id'] ?? $u['id'] ?? $u['_id'] ?? ''));
        if ($currentUserId !== '' && isset($officeNameByHeadUserId[$currentUserId])) {
            $dept = trim((string)$officeNameByHeadUserId[$currentUserId]);
        }
    }
    return $dept;
}

$usersList = getUsersList($config, $search);
list($officeNameById, $officeNameByHeadUserId, $officeCodeByDeptNameKey) = getOfficeLookupMaps($config);
if ($roleFilter !== '') {
    $usersList = array_filter($usersList, function ($u) use ($roleFilter) {
        $userRole = strtolower(trim((string)($u['role'] ?? '')));
        if (in_array($roleFilter, ['departmenthead', 'department_head', 'dept_head'], true)) {
            return in_array($userRole, ['departmenthead', 'department_head', 'dept_head'], true);
        }
        return $userRole === $roleFilter;
    });
    $usersList = array_values($usersList);
}

$departmentTabs = [];
foreach ($officeNameById as $officeName) {
    $normalized = strtolower(trim((string)$officeName));
    if ($normalized !== '' && !isset($departmentTabs[$normalized])) {
        $departmentTabs[$normalized] = [
            'name' => trim((string)$officeName),
            'code' => trim((string)($officeCodeByDeptNameKey[$normalized] ?? '')),
        ];
    }
}
foreach ($usersList as $u) {
    $resolvedDept = resolveUserDepartmentName($u, $officeNameById, $officeNameByHeadUserId);
    $normalized = strtolower($resolvedDept);
    if ($normalized !== '' && !isset($departmentTabs[$normalized])) {
        $departmentTabs[$normalized] = [
            'name' => $resolvedDept,
            'code' => trim((string)($officeCodeByDeptNameKey[$normalized] ?? '')),
        ];
    }
}
if (!empty($departmentTabs)) {
    uasort($departmentTabs, function ($a, $b) {
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
}

$isUnassignedFilter = ($departmentFilter === '__unassigned__');
if ($departmentFilter !== '') {
    $usersList = array_values(array_filter($usersList, function ($u) use ($departmentFilter, $isUnassignedFilter, $officeNameById, $officeNameByHeadUserId) {
        $resolvedDept = resolveUserDepartmentName($u, $officeNameById, $officeNameByHeadUserId);
        if ($isUnassignedFilter) {
            return $resolvedDept === '';
        }
        return strcasecmp($resolvedDept, $departmentFilter) === 0;
    }));
}

function getUserAccountStatusMeta($u) {
    $state = strtolower(trim((string)($u['account_state'] ?? 'active')));
    if ($state === '') $state = 'active';
    if ($state === 'suspended') {
        $remainingSeconds = dbSuspendRemainingSeconds($u);
        if ($remainingSeconds !== null) {
            if ($remainingSeconds <= 0) {
                return ['label' => 'Active', 'class' => 'active', 'hint' => '', 'remaining_seconds' => 0];
            }
            $hours = (int)floor($remainingSeconds / 3600);
            $minutes = (int)floor(($remainingSeconds % 3600) / 60);
            $seconds = (int)($remainingSeconds % 60);
            $timerHint = sprintf('%02d:%02d:%02d remaining', $hours, $minutes, $seconds);
            return [
                'label' => 'Suspended',
                'class' => 'suspended',
                'hint' => $timerHint,
                'remaining_seconds' => (int)$remainingSeconds,
            ];
        }
        return ['label' => 'Suspended', 'class' => 'suspended', 'hint' => '', 'remaining_seconds' => null];
    }
    if ($state === 'disabled') {
        return ['label' => 'Disabled', 'class' => 'disabled', 'hint' => '', 'remaining_seconds' => null];
    }
    return ['label' => 'Active', 'class' => 'active', 'hint' => '', 'remaining_seconds' => null];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Users / Accounts</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/profile_modal_super_admin.css">
    <link rel="stylesheet" href="../Admin%20Side/assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="../Admin%20Side/assets/css/admin-offices.css">
    <link rel="stylesheet" href="assets/css/sidebar_super_admin.css">
    <style>
        body { margin: 0; background: #f8fafc; color: #0f172a; }
        .main-content {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            background: #fff;
            /* Prevent sticky header from breaking due to parent overflow rules. */
            overflow: visible !important;
        }
        .content-header {
            background: #fff;
            padding: 1.5rem 2.2rem;
            border-bottom: 1px solid #e2e8f0;
            flex-shrink: 0;
            position: sticky !important;
            top: 0 !important;
            z-index: 1200;
        }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; }
        .dashboard-title-wrap { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .users-page-title { display: inline-flex; align-items: center; gap: 10px; }
        .users-page-title-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: #eff6ff;
            color: #1d4ed8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }
        .users-page-title-icon svg { width: 19px; height: 19px; }
        .dashboard-header h1 { font-size: 1.6rem; margin: 0 0 0.2rem 0; font-weight: 700; color: #1e293b; }
        .dashboard-header small { display: block; color: #64748b; font-size: 0.95rem; margin-top: 6px; }
        .header-controls { position: relative; }
        .icon-btn, .avatar-btn { background: #f1f5f9; border: none; color: #475569; padding: 0; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .icon-btn:hover, .avatar-btn:hover { background: #e2e8f0; color: #1e293b; }
        .icon-btn { position: relative; width: 48px; height: 48px; }
        .icon-btn svg, .avatar-btn svg { width: 26px; height: 26px; }
        .notif-badge { position: absolute; top: 6px; right: 6px; background: #ef4444; color: white; font-size: 13px; padding: 4px 8px; border-radius: 999px; line-height: 1; }
        .avatar-btn { width: 48px; height: 48px; padding: 0; border-radius: 10px; }
        .main-content .admin-content-body { padding-top: 24px; }
        .offices-card .offices-tools.doc-filter-row select { height: 42px; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0 12px; font-size: 14px; color: #1e293b; background: #fff; font-family: 'Poppins', sans-serif; }
        #users-filter-form { grid-template-columns: 1.4fr 1fr auto !important; }
        #users-filter-form .users-search-wrap { position: relative; }
        #users-filter-form .users-search-wrap input { width: 100%; padding-right: 38px !important; }
        #users-filter-form .users-search-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            color: #94a3b8;
            pointer-events: none;
        }
        #users-filter-form #add-user-btn {
            flex: 0 0 auto !important;
            width: auto !important;
            min-width: max-content;
            justify-self: start;
            white-space: nowrap;
        }
        .users-toast { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 1500; display: flex; align-items: center; gap: 12px; padding: 0.875rem 1rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(0,0,0,0.15); max-width: 360px; animation: users-toast-in 0.3s ease; }
        .users-toast.is-hiding { opacity: 0; transform: translateY(8px); transition: opacity 0.25s ease, transform 0.25s ease; }
        .users-toast.success { background: #22c55e; color: #fff; }
        .users-toast.error { background: #ef4444; color: #fff; }
        @keyframes users-toast-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        /* Users table UX */
        .offices-card .offices-table-frame { border-radius: 12px; overflow: hidden; }
        .offices-card .offices-table thead th { background: #f8fafc; font-weight: 600; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.03em; padding: 10px 12px; border-bottom: 2px solid #e2e8f0; }
        .offices-card .offices-table tbody td { padding: 9px 12px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; font-size: 0.86rem; line-height: 1.25; }
        .offices-card .offices-table tbody tr[data-user-row="1"]:nth-child(odd) { background: #ffffff; }
        .offices-card .offices-table tbody tr[data-user-row="1"]:nth-child(even) { background: #eef4ff; }
        .offices-card .offices-table tbody tr[data-user-row="1"]:hover { background: #dbeafe; }
        .offices-card .offices-table tbody tr:last-child td { border-bottom: none; }
        .users-role-badge { display: inline-block; padding: 3px 8px; border-radius: 20px; font-size: 0.73rem; font-weight: 600; }
        .users-role-badge.superadmin { background: #fef3c7; color: #92400e; }
        .users-role-badge.admin { background: #dbeafe; color: #1e40af; }
        .users-role-badge.departmenthead, .users-role-badge.department_head, .users-role-badge.dept_head { background: #e0e7ff; color: #3730a3; }
        .users-role-badge.user, .users-role-badge.staff { background: #f1f5f9; color: #475569; }
        .users-status-badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 0.7rem; font-weight: 600; line-height: 1.15; }
        .users-status-badge.active { background: #dcfce7; color: #166534; }
        .users-status-badge.disabled { background: #fee2e2; color: #991b1b; }
        .users-status-badge.suspended { background: #fef3c7; color: #92400e; }
        .users-status-hint { display: block; margin-top: 2px; font-size: 0.68rem; color: #64748b; }
        .users-action-cell { white-space: nowrap; }
        .users-action-btn { display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 8px; font-size: 0.78rem; font-weight: 600; text-decoration: none; color: #475569; background: #f1f5f9; border: 1px solid #e2e8f0; cursor: pointer; transition: background 0.2s, color 0.2s; font-family: inherit; }
        .users-action-btn:hover { background: #e2e8f0; color: #1e293b; }
        .users-action-btn svg { width: 14px; height: 14px; flex-shrink: 0; }
        .users-action-btn.disable { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
        .users-action-btn.disable:hover { background: #fecaca; color: #991b1b; }
        .users-action-btn.suspend { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .users-action-btn.suspend:hover { background: #fde68a; color: #78350f; }
        .users-action-btn.enable { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
        .users-action-btn.enable:hover { background: #bbf7d0; color: #14532d; }
        .users-action-stack { display: inline-flex; gap: 6px; flex-wrap: wrap; }
        .offices-empty { padding: 2rem; text-align: center; color: #64748b; font-size: 0.95rem; }
        .users-empty-state { display: inline-flex; flex-direction: column; align-items: center; gap: 10px; max-width: 480px; margin: 0 auto; }
        .users-empty-icon {
            width: 44px;
            height: 44px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .users-empty-icon svg { width: 22px; height: 22px; }
        .users-empty-link {
            color: #1d4ed8;
            font-weight: 600;
            text-decoration: none;
            border-bottom: 1px solid transparent;
        }
        .users-empty-link:hover { color: #1e40af; border-bottom-color: #93c5fd; }
        .users-empty-info { color: #475569; font-size: 0.9rem; }
        .users-dept-tabs-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 8px 0 14px;
        }
        .users-dept-tabs {
            display: flex;
            flex-wrap: nowrap;
            gap: 8px;
            overflow-x: auto;
            overflow-y: hidden;
            scroll-behavior: smooth;
            scrollbar-width: thin;
            min-width: 0;
            flex: 1;
            padding-bottom: 2px;
        }
        .users-dept-tabs::-webkit-scrollbar { height: 6px; }
        .users-dept-tabs::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
        .users-dept-tab {
            display: inline-flex;
            align-items: center;
            flex: 0 0 auto;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid #dbe2ea;
            background: #fff;
            color: #475569;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            line-height: 1;
            transition: all 0.15s ease;
        }
        .users-dept-tab:hover { border-color: #93c5fd; color: #1d4ed8; background: #eff6ff; }
        .users-dept-tab.active { background: #1d4ed8; border-color: #1d4ed8; color: #fff; }
        .users-dept-scroll-btn {
            width: 32px;
            height: 32px;
            border: 1px solid #dbe2ea;
            border-radius: 999px;
            background: #fff;
            color: #334155;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
            flex: 0 0 auto;
        }
        .users-dept-scroll-btn:hover { border-color: #93c5fd; color: #1d4ed8; background: #eff6ff; }
        .users-dept-scroll-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            background: #f8fafc;
            color: #94a3b8;
        }
        .users-dept-scroll-btn svg { width: 16px; height: 16px; }
        @media (max-width: 1100px) {
            .content-header { padding: 1.15rem 1.2rem; }
            .main-content .admin-content-body { padding-top: 14px; }
            #users-filter-form { grid-template-columns: minmax(0, 1fr) 210px auto !important; gap: 10px; }
            .users-dept-tab { padding: 7px 12px; font-size: 12px; }
            .users-dept-tabs-wrap { margin: 6px 0 12px; }
        }
        @media (max-width: 1200px) {
            .dashboard-title-wrap .sidebar-toggle-btn.in-header {
                position: static !important;
                top: auto !important;
                right: auto !important;
                left: auto !important;
                width: 38px;
                height: 38px;
                border-radius: 10px;
                margin: 0;
                flex: 0 0 auto;
                box-shadow: none;
                background: #1e293b;
                display: inline-flex;
            }
            .dashboard-title-wrap .sidebar-toggle-btn.in-header:hover { background: #334155; }
            .sidebar.sidebar-open ~ .main-content .dashboard-title-wrap .sidebar-toggle-btn.in-header {
                opacity: 0;
                pointer-events: none;
            }
        }
        @media (max-width: 900px) {
            .dashboard-header { align-items: flex-start; gap: 10px; }
            .dashboard-header h1 { font-size: 1.35rem; }
            .dashboard-header small { font-size: 0.85rem; }
            #users-filter-form { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) !important; }
            #users-filter-form #add-user-btn { grid-column: 1 / -1; justify-self: stretch; justify-content: center; }
            .offices-card .offices-table thead th { font-size: 0.7rem; padding: 9px 10px; }
            .offices-card .offices-table tbody td { padding: 8px 10px; font-size: 0.82rem; }
            .users-action-btn { padding: 4px 8px; font-size: 0.73rem; }
        }
        @media (max-width: 640px) {
            .users-dept-scroll-btn { display: none; }
        }
        @media (max-width: 768px) {
            .content-header { padding: 0.95rem 0.85rem; }
            .users-page-title { gap: 8px; }
            .users-page-title-icon { width: 30px; height: 30px; border-radius: 9px; }
            .users-page-title-icon svg { width: 17px; height: 17px; }
            .dashboard-header {
                align-items: center;
                gap: 8px;
            }
            .dashboard-title-wrap {
                flex: 1;
                min-width: 0;
            }
            .dashboard-header h1 { font-size: 1.16rem; margin-bottom: 0; }
            .dashboard-header small { font-size: 0.78rem; margin-top: 3px; line-height: 1.25; }
            .main-content .admin-content-body { padding: 10px 6px 0; }
            .offices-card { padding: 12px; }
            .offices-card .offices-table-frame {
                border: none !important;
                background: transparent !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                overflow: visible;
            }
            .offices-card .offices-table {
                border: none !important;
                background: transparent !important;
            }
            #users-filter-form { grid-template-columns: 1fr !important; gap: 8px; }
            #users-filter-form .users-search-wrap input,
            #users-filter-form select,
            #users-filter-form #add-user-btn { height: 40px; }
            #users-filter-form #add-user-btn { grid-column: auto; justify-self: stretch; width: 100% !important; }
            .users-dept-tabs-wrap { margin: 6px 0 10px; gap: 6px; }
            .users-dept-tab { padding: 7px 11px; font-size: 11.5px; }
            .offices-card .offices-table { min-width: 0; }
            .offices-card .offices-table thead { display: none; }
            .offices-card .offices-table tbody tr[data-user-row="1"] {
                display: block;
                margin: 0 0 10px;
                padding: 6px 10px;
                border: 1px solid #dbe2ea;
                border-radius: 12px;
                background: #fff !important;
                box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            }
            .offices-card .offices-table tbody tr[data-user-row="1"] td {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 10px;
                border-bottom: 1px dashed #e2e8f0;
                padding: 8px 0;
                font-size: 0.85rem;
                line-height: 1.3;
            }
            .offices-card .offices-table tbody tr[data-user-row="1"] td:last-child { border-bottom: none; }
            .offices-card .offices-table tbody tr[data-user-row="1"] td::before {
                content: attr(data-label);
                color: #64748b;
                font-size: 0.72rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.02em;
                flex: 0 0 120px;
                padding-top: 2px;
            }
            .offices-card .offices-table tbody tr[data-user-row="1"] .users-action-cell { white-space: normal; }
            .offices-card .offices-table tbody tr[data-user-row="1"] .users-action-stack { width: 100%; gap: 6px; }
        }
        @media (max-width: 480px) {
            .content-header { padding: 0.85rem 0.7rem; }
            .users-page-title-icon { width: 28px; height: 28px; }
            .dashboard-header h1 { font-size: 1.04rem; }
            .dashboard-header small { display: none; }
            .main-content .admin-content-body { padding: 8px 4px 0; }
            .offices-card { padding: 10px; border-radius: 10px; }
            .users-empty-state { gap: 8px; }
            .users-empty-info { font-size: 0.82rem; }
            .offices-card .offices-table tbody tr[data-user-row="1"] {
                margin: 0 0 8px;
                padding: 6px 7px;
            }
            .offices-card .offices-table tbody tr[data-user-row="1"] td::before { flex-basis: 104px; font-size: 0.68rem; }
        }
        #add-user-modal .doc-modal-dialog { width: min(640px, calc(100vw - 24px)); border-radius: 14px; overflow: hidden; }
        #add-user-modal .doc-modal-header { padding: 16px 18px; border-bottom: 1px solid #e2e8f0; background: #ffffff; }
        #add-user-modal .doc-modal-header h2 { margin: 0; font-size: 1.3rem; color: #1e293b; }
        #add-user-modal .doc-modal-subtitle { margin: 6px 0 0; color: #64748b; font-size: 0.85rem; }
        #add-user-modal .doc-modal-form { padding: 16px 18px 18px; display: grid; gap: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        #add-user-modal .doc-form-field { display: grid; gap: 6px; }
        #add-user-modal .doc-form-field.full-span { grid-column: 1 / -1; }
        #add-user-modal .doc-form-field label { font-size: 13px; color: #334155; font-weight: 600; }
        #add-user-modal .doc-form-field input,
        #add-user-modal .doc-form-field select {
            width: 100%;
            height: 40px;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            padding: 0 12px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            color: #0f172a;
            background: #fff;
            box-sizing: border-box;
        }
        #add-user-modal .password-input-wrap { position: relative; }
        #add-user-modal .password-input-wrap input { padding-right: 44px; }
        #add-user-modal .password-toggle-btn {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #64748b;
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            cursor: pointer;
        }
        #add-user-modal .password-toggle-btn:hover { background: #f1f5f9; color: #1e293b; }
        #add-user-modal .password-toggle-btn svg { width: 18px; height: 18px; }
        #add-user-modal .password-help { margin: 0; color: #64748b; font-size: 12px; line-height: 1.4; }
        #add-user-modal .password-strength-msg { margin: -2px 0 0; font-size: 12px; font-weight: 600; }
        #add-user-modal .password-strength-msg.weak { color: #dc2626; }
        #add-user-modal .password-strength-msg.strong { color: #166534; }
        #add-user-modal .password-match-msg { margin: -2px 0 0; font-size: 12px; font-weight: 600; }
        #add-user-modal .password-match-msg.match { color: #166534; }
        #add-user-modal .password-match-msg.mismatch { color: #dc2626; }
        #add-user-modal .otp-row { display: flex; gap: 10px; align-items: center; }
        #add-user-modal .otp-row input { flex: 1; min-width: 0; }
        #add-user-modal .otp-send-btn {
            height: 40px;
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 10px;
            padding: 0 12px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
        }
        #add-user-modal .otp-send-btn:disabled { opacity: 0.7; cursor: not-allowed; }
        #add-user-modal .otp-status { margin: 4px 0 0; font-size: 12px; }
        #add-user-modal .otp-status.ok { color: #166534; }
        #add-user-modal .otp-status.err { color: #dc2626; }
        #add-user-modal #add-user-email-otp.is-invalid {
            border-color: #dc2626 !important;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.14);
        }
        #add-user-modal .doc-form-field input:focus,
        #add-user-modal .doc-form-field select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
        }
        #add-user-modal .doc-modal-actions { margin-top: 6px; display: flex; justify-content: flex-end; gap: 10px; grid-column: 1 / -1; }
        #add-user-modal .doc-btn { min-height: 38px; padding: 0 14px; border-radius: 10px; font-weight: 600; }
        #add-user-modal .doc-form-error { margin: 0; font-size: 13px; color: #dc2626; grid-column: 1 / -1; }
        #edit-user-modal .doc-modal-dialog { width: min(640px, calc(100vw - 24px)); border-radius: 14px; overflow: hidden; }
        #edit-user-modal .doc-modal-header { padding: 16px 18px; border-bottom: 1px solid #e2e8f0; background: #ffffff; }
        #edit-user-modal .doc-modal-header h2 { margin: 0; font-size: 1.3rem; color: #1e293b; }
        #edit-user-modal .doc-modal-subtitle { margin: 6px 0 0; color: #64748b; font-size: 0.85rem; }
        #edit-user-modal .doc-modal-form { padding: 16px 18px 18px; display: grid; gap: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        #edit-user-modal .doc-form-field { display: grid; gap: 6px; }
        #edit-user-modal .doc-form-field.full-span { grid-column: 1 / -1; }
        #edit-user-modal .doc-form-field label { font-size: 13px; color: #334155; font-weight: 600; }
        #edit-user-modal .doc-form-field input,
        #edit-user-modal .doc-form-field select {
            width: 100%;
            height: 40px;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            padding: 0 12px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            color: #0f172a;
            background: #fff;
            box-sizing: border-box;
        }
        #edit-user-modal .password-input-wrap { position: relative; }
        #edit-user-modal .password-input-wrap input { padding-right: 44px; }
        #edit-user-modal .password-toggle-btn {
            position: absolute; top: 50%; right: 10px; transform: translateY(-50%);
            border: none; background: transparent; color: #64748b; width: 28px; height: 28px;
            display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; cursor: pointer;
        }
        #edit-user-modal .password-toggle-btn:hover { background: #f1f5f9; color: #1e293b; }
        #edit-user-modal .password-toggle-btn svg { width: 18px; height: 18px; }
        #edit-user-modal .password-help { margin: 0; color: #64748b; font-size: 12px; line-height: 1.4; }
        #edit-user-modal .password-strength-msg { margin: -2px 0 0; font-size: 12px; font-weight: 600; }
        #edit-user-modal .password-strength-msg.weak { color: #dc2626; }
        #edit-user-modal .password-strength-msg.strong { color: #166534; }
        #edit-user-modal .password-match-msg { margin: -2px 0 0; font-size: 12px; font-weight: 600; }
        #edit-user-modal .password-match-msg.match { color: #166534; }
        #edit-user-modal .password-match-msg.mismatch { color: #dc2626; }
        #edit-user-modal .doc-form-field input:focus,
        #edit-user-modal .doc-form-field select:focus {
            outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
        }
        #edit-user-modal .doc-modal-actions { margin-top: 6px; display: flex; justify-content: flex-end; gap: 10px; grid-column: 1 / -1; }
        #edit-user-modal .doc-btn { min-height: 38px; padding: 0 14px; border-radius: 10px; font-weight: 600; }
        #edit-user-modal .doc-form-error { margin: 0; font-size: 13px; color: #dc2626; grid-column: 1 / -1; }
        #edit-user-otp-confirm-modal .doc-modal-dialog { width: min(460px, calc(100vw - 24px)); border-radius: 14px; overflow: hidden; }
        #edit-user-otp-confirm-modal .doc-modal-header {
            position: relative;
            display: block;
            padding: 16px 44px 12px 18px;
            border-bottom: 1px solid #e2e8f0;
            background: #ffffff;
        }
        #edit-user-otp-confirm-modal .doc-modal-header h2 {
            margin: 0;
            font-size: 1.1rem;
            line-height: 1.25;
            color: #1e293b;
        }
        #edit-user-otp-confirm-modal .doc-modal-header .doc-modal-close {
            position: absolute;
            top: 10px;
            right: 10px;
        }
        #edit-user-otp-confirm-modal .doc-modal-subtitle {
            margin: 6px 0 0;
            color: #64748b;
            font-size: 0.84rem;
            line-height: 1.4;
        }
        #edit-user-otp-confirm-modal .doc-modal-form { padding: 16px 18px 18px; display: grid; gap: 12px; }
        #edit-user-otp-confirm-modal input {
            width: 100%;
            height: 40px;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            padding: 0 12px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            color: #0f172a;
            background: #fff;
            box-sizing: border-box;
        }
        #edit-user-otp-confirm-modal .otp-status { margin: 0; font-size: 12px; line-height: 1.35; color: #475569; }
        #edit-user-otp-confirm-modal .otp-status.err { color: #dc2626; }
        #edit-user-otp-confirm-modal .doc-modal-actions {
            margin-top: 4px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }
        #edit-user-otp-confirm-modal .doc-btn { min-height: 36px; padding: 0 12px; }
        #edit-user-otp-confirm-modal .doc-btn-resend {
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            color: #1d4ed8;
        }
        #edit-user-otp-confirm-modal .doc-btn-resend:hover {
            background: #dbeafe;
            border-color: #93c5fd;
        }
        #edit-user-otp-confirm-modal .doc-btn-resend:disabled,
        #edit-user-otp-confirm-modal .doc-btn-resend:disabled:hover {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
        }
        @media (max-width: 640px) {
            #add-user-modal .doc-modal-dialog { width: calc(100vw - 16px); }
            #add-user-modal .doc-modal-form { padding: 14px; gap: 10px; grid-template-columns: 1fr; }
            #add-user-modal .doc-modal-actions { gap: 8px; }
            #edit-user-modal .doc-modal-dialog { width: calc(100vw - 16px); }
            #edit-user-modal .doc-modal-form { padding: 14px; gap: 10px; grid-template-columns: 1fr; }
            #edit-user-modal .doc-modal-actions { gap: 8px; }
            #edit-user-otp-confirm-modal .doc-modal-dialog { width: calc(100vw - 16px); }
            #edit-user-otp-confirm-modal .doc-modal-header { padding-right: 40px; }
            #edit-user-otp-confirm-modal .doc-modal-form { padding: 14px; gap: 10px; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/_sidebar_super_admin.php'; ?>

        <div class="main-content">
            <div class="content-header">
                <div class="dashboard-header">
                    <div class="dashboard-title-wrap">
                        <h1 class="users-page-title">
                            <span class="users-page-title-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="9" cy="7" r="4"></circle>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                </svg>
                            </span>
                            <span>User Management</span>
                        </h1>
                        <small>Manage user accounts and roles</small>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div class="header-controls">
                            <?php include __DIR__ . '/_notif_dropdown_super_admin.php'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admin-content-body">
                <section class="chart-card chart-card-wide offices-card">
                    <?php if ($msg !== null && !$openAddUserModal && !$openEditUserModal): ?>
                    <div id="users-toast" class="users-toast <?= $msgOk ? 'success' : 'error' ?>" role="alert">
                        <span class="users-toast-text"><?= htmlspecialchars($msg) ?></span>
                    </div>
                    <?php endif; ?>
                    <form method="get" class="offices-tools doc-filter-row" id="users-filter-form" autocomplete="off">
                        <div class="users-search-wrap">
                            <input type="text" name="search" placeholder="Search by name or email" aria-label="Search" value="<?= htmlspecialchars($search) ?>">
                            <svg class="users-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="11" cy="11" r="7"></circle>
                                <line x1="16.65" y1="16.65" x2="21" y2="21"></line>
                            </svg>
                        </div>
                        <input type="hidden" name="dept" value="<?= htmlspecialchars($departmentFilter) ?>">
                        <select name="role" aria-label="Filter by role">
                            <option value="" <?= $roleFilter === '' ? 'selected' : '' ?>>All Roles</option>
                            <option value="superadmin" <?= $roleFilter === 'superadmin' ? 'selected' : '' ?>>Super Admin</option>
                            <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="staff" <?= $roleFilter === 'staff' ? 'selected' : '' ?>>Frontdesk</option>
                            <option value="departmenthead" <?= $roleFilter === 'departmenthead' ? 'selected' : '' ?>>Department Head</option>
                        </select>
                        <button type="button" class="offices-btn" id="add-user-btn">
                            <svg class="offices-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                            Add User
                        </button>
                    </form>

                    <div class="users-dept-tabs-wrap">
                        <button type="button" class="users-dept-scroll-btn" id="users-dept-scroll-left" aria-label="Scroll departments left" hidden>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                        </button>
                        <div class="users-dept-tabs" id="users-dept-tabs">
                        <?php
                            $baseFilterQuery = [];
                            if ($search !== '') {
                                $baseFilterQuery['search'] = $search;
                            }
                            if ($roleFilter !== '') {
                                $baseFilterQuery['role'] = $roleFilter;
                            }
                            $allQuery = $baseFilterQuery;
                            $allHref = 'users.php' . (!empty($allQuery) ? ('?' . http_build_query($allQuery)) : '');
                        ?>
                        <a href="<?= htmlspecialchars($allHref) ?>" class="users-dept-tab <?= $departmentFilter === '' ? 'active' : '' ?>">All Departments</a>
                        <?php foreach ($departmentTabs as $deptTab): ?>
                            <?php
                                $deptTabLabel = trim((string)($deptTab['name'] ?? ''));
                                $deptTabCode = trim((string)($deptTab['code'] ?? ''));
                                $deptTabText = $deptTabCode !== '' ? $deptTabCode : $deptTabLabel;
                                $deptQuery = $baseFilterQuery;
                                $deptQuery['dept'] = $deptTabLabel;
                                $deptHref = 'users.php?' . http_build_query($deptQuery);
                            ?>
                            <a href="<?= htmlspecialchars($deptHref) ?>" title="<?= htmlspecialchars($deptTabLabel) ?>" class="users-dept-tab <?= strcasecmp($departmentFilter, $deptTabLabel) === 0 ? 'active' : '' ?>"><?= htmlspecialchars($deptTabText) ?></a>
                        <?php endforeach; ?>
                        <?php
                            $unassignedQuery = $baseFilterQuery;
                            $unassignedQuery['dept'] = '__unassigned__';
                            $unassignedHref = 'users.php?' . http_build_query($unassignedQuery);
                        ?>
                        <a href="<?= htmlspecialchars($unassignedHref) ?>" class="users-dept-tab <?= $isUnassignedFilter ? 'active' : '' ?>">Unassigned</a>
                        </div>
                        <button type="button" class="users-dept-scroll-btn" id="users-dept-scroll-right" aria-label="Scroll departments right" hidden>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </button>
                    </div>

                    <div class="offices-table-frame">
                        <table class="offices-table">
                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Office / Department</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="users-table-body">
                                <?php if (count($usersList) === 0): ?>
                                <tr>
                                    <td colspan="7" class="offices-empty" id="no-users-row">
                                        <div class="users-empty-state">
                                            <span class="users-empty-icon" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"></path>
                                                    <line x1="12" y1="10" x2="12" y2="16"></line>
                                                    <line x1="9" y1="13" x2="15" y2="13"></line>
                                                </svg>
                                            </span>
                                            <span>No users found for this tab/filter.</span>
                                            <span class="users-empty-info">To add a department, click <a class="users-empty-link" href="offices-department.php">add here</a>.</span>
                                            <span class="users-empty-info">To add user, click add <a class="users-empty-link js-open-add-user-inline" href="#">here</a>.</span>
                                        </div>
                                    </td>
                                </tr>
                                <?php else:
                                    $no = 1;
                                    foreach ($usersList as $u):
                                        $displayName = trim($u['name'] ?? '') ?: (trim($u['username'] ?? '') ?: trim($u['email'] ?? ''));
                                        if ($displayName === '') $displayName = '—';
                                        $dept = resolveUserDepartmentName($u, $officeNameById, $officeNameByHeadUserId);
                                        if ($dept === '') $dept = '—';
                                        $rawRole = strtolower(trim($u['role'] ?? ''));
                                        $roleClass = $rawRole ?: 'user';
                                        $statusMeta = getUserAccountStatusMeta($u);
                                        $showEnableBtn = in_array($statusMeta['class'], ['disabled', 'suspended'], true);
                                ?>
                                <tr
                                    data-user-row="1"
                                    data-role="<?= htmlspecialchars($rawRole) ?>"
                                    data-search="<?= htmlspecialchars(strtolower($displayName . ' ' . trim((string)($u['email'] ?? '')) . ' ' . $dept . ' ' . trim((string)($u['username'] ?? '')))) ?>"
                                >
                                    <td data-label="No."><?= (int)$no ?></td>
                                    <td data-label="Name"><?= htmlspecialchars($displayName) ?></td>
                                    <td data-label="Email"><?= htmlspecialchars(trim($u['email'] ?? '') ?: '—') ?></td>
                                    <td data-label="Role"><span class="users-role-badge <?= htmlspecialchars($roleClass) ?>"><?= htmlspecialchars(formatRoleLabel($u['role'] ?? '')) ?></span></td>
                                    <td data-label="Status">
                                        <span class="users-status-badge <?= htmlspecialchars($statusMeta['class']) ?>"><?= htmlspecialchars($statusMeta['label']) ?></span>
                                        <?php if (($statusMeta['class'] ?? '') === 'suspended'): ?>
                                        <small
                                            class="users-status-hint js-suspend-countdown"
                                            data-remaining-seconds="<?= isset($statusMeta['remaining_seconds']) && is_numeric($statusMeta['remaining_seconds']) ? (int)$statusMeta['remaining_seconds'] : '' ?>"
                                        ><?= htmlspecialchars(!empty($statusMeta['hint']) ? $statusMeta['hint'] : 'Suspended') ?></small>
                                        <?php elseif (!empty($statusMeta['hint'])): ?>
                                        <small class="users-status-hint"><?= htmlspecialchars($statusMeta['hint']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Office / Department"><?= htmlspecialchars($dept) ?></td>
                                    <td data-label="Action" class="users-action-cell">
                                        <div class="users-action-stack">
                                            <button type="button" class="users-action-btn js-edit-user-btn" title="Edit user"
                                                data-user-id="<?= htmlspecialchars($u['user_id'] ?? $u['id'] ?? $u['_id'] ?? '') ?>"
                                                data-username="<?= htmlspecialchars(trim((string)($u['username'] ?? '')) ?: trim((string)($u['email'] ?? '')) ) ?>"
                                                data-name="<?= htmlspecialchars(trim((string)($u['name'] ?? '')) ) ?>"
                                                data-email="<?= htmlspecialchars(trim((string)($u['email'] ?? '')) ) ?>"
                                                data-role="<?= htmlspecialchars(strtolower(trim((string)($u['role'] ?? 'user'))) ) ?>">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                Edit
                                            </button>
                                            <?php if ($showEnableBtn): ?>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Enable this account? The user will be able to login again.');">
                                                <input type="hidden" name="action" value="enable_user">
                                                <input type="hidden" name="user_id" value="<?= htmlspecialchars($u['user_id'] ?? $u['id'] ?? $u['_id'] ?? '') ?>">
                                                <input type="hidden" name="target_name" value="<?= htmlspecialchars($displayName) ?>">
                                                <button type="submit" class="users-action-btn enable" title="Enable user">Enable</button>
                                            </form>
                                            <?php else: ?>
                                            <button type="button" class="users-action-btn disable js-disable-user-btn" data-user-id="<?= htmlspecialchars($u['user_id'] ?? $u['id'] ?? $u['_id'] ?? '') ?>" data-user-name="<?= htmlspecialchars($displayName) ?>" title="Disable user">Disable</button>
                                            <button type="button" class="users-action-btn suspend js-suspend-user-btn" data-user-id="<?= htmlspecialchars($u['user_id'] ?? $u['id'] ?? $u['_id'] ?? '') ?>" data-user-name="<?= htmlspecialchars($displayName) ?>" title="Suspend user">Suspend</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                                    $no++;
                                    endforeach;
                                endif;
                                ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/_profile_modal_super_admin.php'; ?>

    <div class="doc-modal" id="add-user-modal" <?php echo $openAddUserModal ? '' : 'hidden'; ?>>
        <button type="button" class="doc-modal-overlay" data-close-add-user aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="add-user-title">
            <div class="doc-modal-header">
                <h2 id="add-user-title">Add User</h2>
                <p class="doc-modal-subtitle">Set account details and secure login credentials.</p>
                <button type="button" class="doc-modal-close" data-close-add-user aria-label="Close">&times;</button>
            </div>
            <form method="post" action="users.php" id="add-user-form" class="doc-modal-form" autocomplete="off">
                <input type="hidden" name="action" value="add_user">
                <div class="doc-form-field">
                    <label for="add-user-username">Username <span class="required">*</span></label>
                    <input type="text" id="add-user-username" name="username" placeholder="Enter your username" required>
                </div>
                <div class="doc-form-field">
                    <label for="add-user-name">Name</label>
                    <input type="text" id="add-user-name" name="name" placeholder="Enter your full name">
                </div>
                <div class="doc-form-field">
                    <label for="add-user-email">Email <span class="required">*</span></label>
                    <input type="text" id="add-user-email" name="email" placeholder="e.g. sunga123 or sunga123@gmail.com" required autocomplete="email" inputmode="email">
                </div>
                <div class="doc-form-field">
                    <label for="add-user-role">Role</label>
                    <select id="add-user-role" name="role" class="offices-select">
                        <option value="admin">Admin</option>
                        <option value="superadmin">Super Admin</option>
                        <option value="staff">Frontdesk</option>
                        <option value="departmenthead">Department Head</option>
                    </select>
                </div>
                <div class="doc-form-field full-span">
                    <label for="add-user-email-otp">OTP sent to Gmail <span class="required">*</span></label>
                    <div class="otp-row">
                        <input type="text" id="add-user-email-otp" name="email_otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="Enter 6-digit OTP" required autocomplete="one-time-code" class="<?php echo ($openAddUserModal && $addUserInvalidField === 'otp') ? 'is-invalid' : ''; ?>">
                        <button type="button" class="otp-send-btn" id="send-add-user-otp-btn">Send OTP</button>
                    </div>
                    <p class="otp-status <?php echo $otpInlineError !== '' ? 'err' : ''; ?>" id="add-user-otp-status" <?php echo $otpInlineError === '' ? 'hidden' : ''; ?>><?php echo htmlspecialchars($otpInlineError); ?></p>
                </div>
                <div class="doc-form-field full-span">
                    <label for="add-user-password">Password <span class="required">*</span></label>
                    <div class="password-input-wrap">
                        <input type="password" id="add-user-password" name="password" placeholder="Create a strong password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="password-toggle-btn js-toggle-password" data-target="add-user-password" aria-label="Show password" aria-pressed="false">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                    <small class="password-help">Use at least 8 characters with uppercase, lowercase, number, and special character.</small>
                    <p class="password-strength-msg" id="password-strength-msg" hidden></p>
                </div>
                <div class="doc-form-field full-span">
                    <label for="add-user-confirm-password">Retype Password <span class="required">*</span></label>
                    <div class="password-input-wrap">
                        <input type="password" id="add-user-confirm-password" name="confirm_password" placeholder="Retype password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="password-toggle-btn js-toggle-password" data-target="add-user-confirm-password" aria-label="Show password" aria-pressed="false">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                    <p class="password-match-msg" id="password-match-msg" hidden></p>
                </div>
                <p class="doc-form-error" id="add-user-form-error" hidden></p>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-add-user>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Add User</button>
                </div>
            </form>
        </div>
    </div>

    <div class="doc-modal" id="edit-user-modal" <?php echo $openEditUserModal ? '' : 'hidden'; ?>>
        <button type="button" class="doc-modal-overlay" data-close-edit-user aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="edit-user-title">
            <div class="doc-modal-header">
                <h2 id="edit-user-title">Edit User</h2>
                <p class="doc-modal-subtitle">Changing user information.</p>
                <button type="button" class="doc-modal-close" data-close-edit-user aria-label="Close">&times;</button>
            </div>
            <form method="post" action="users.php" id="edit-user-form" class="doc-modal-form">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" id="edit-user-id" name="user_id" value="<?= htmlspecialchars($editModalData['user_id']) ?>">
                <input type="hidden" id="edit-user-original-email" value="<?= htmlspecialchars($editOriginalEmail) ?>">
                <input type="hidden" id="edit-email-change-otp" name="edit_email_change_otp" value="">
                <div class="doc-form-field">
                    <label for="edit-user-username">Username <span class="required">*</span></label>
                    <input type="text" id="edit-user-username" name="username" required value="<?= htmlspecialchars($editModalData['username']) ?>">
                </div>
                <div class="doc-form-field">
                    <label for="edit-user-name">Name</label>
                    <input type="text" id="edit-user-name" name="name" value="<?= htmlspecialchars($editModalData['name']) ?>">
                </div>
                <div class="doc-form-field">
                    <label for="edit-user-email">Email <span class="required">*</span></label>
                    <input type="email" id="edit-user-email" name="email" required value="<?= htmlspecialchars($editModalData['email']) ?>">
                </div>
                <div class="doc-form-field">
                    <label for="edit-user-role">Role</label>
                    <select id="edit-user-role" name="role">
                        <?php $editRole = strtolower(trim((string)($editModalData['role'] ?? 'user'))); ?>
                        <option value="user" <?= $editRole === 'user' ? 'selected' : '' ?>>User</option>
                        <option value="admin" <?= $editRole === 'admin' ? 'selected' : '' ?>>Admin</option>
                        <option value="superadmin" <?= $editRole === 'superadmin' ? 'selected' : '' ?>>Super Admin</option>
                        <option value="staff" <?= $editRole === 'staff' ? 'selected' : '' ?>>Frontdesk</option>
                        <option value="departmenthead" <?= in_array($editRole, ['departmenthead', 'department_head', 'dept_head'], true) ? 'selected' : '' ?>>Department Head</option>
                    </select>
                </div>
                <div class="doc-form-field full-span">
                    <label for="edit-user-password">New Password (optional)</label>
                    <div class="password-input-wrap">
                        <input type="password" id="edit-user-password" name="password" placeholder="Leave blank to keep current password" autocomplete="new-password">
                        <button type="button" class="password-toggle-btn js-toggle-edit-password" data-target="edit-user-password" aria-label="Show password" aria-pressed="false">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                    <small class="password-help">Use at least 8 characters with uppercase, lowercase, number, and special character.</small>
                    <p class="password-strength-msg" id="edit-password-strength-msg" hidden></p>
                </div>
                <div class="doc-form-field full-span">
                    <label for="edit-user-confirm-password">Retype New Password</label>
                    <div class="password-input-wrap">
                        <input type="password" id="edit-user-confirm-password" name="confirm_password" placeholder="Retype new password" autocomplete="new-password">
                        <button type="button" class="password-toggle-btn js-toggle-edit-password" data-target="edit-user-confirm-password" aria-label="Show password" aria-pressed="false">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                    <p class="password-match-msg" id="edit-password-match-msg" hidden></p>
                </div>
                <p class="doc-form-error" id="edit-user-form-error" hidden></p>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-edit-user>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div class="doc-modal" id="edit-user-otp-confirm-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-edit-user-otp-confirm aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="edit-user-otp-confirm-title">
            <div class="doc-modal-header">
                <h2 id="edit-user-otp-confirm-title">Confirm OTP</h2>
                <p class="doc-modal-subtitle">Enter the OTP sent to the old Gmail/email to continue changing user information.</p>
                <button type="button" class="doc-modal-close" data-close-edit-user-otp-confirm aria-label="Close">&times;</button>
            </div>
            <div class="doc-modal-form">
                <p class="otp-status" id="edit-user-otp-confirm-message">OTP was sent to the old Gmail/email.</p>
                <input type="text" id="edit-user-otp-confirm-input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="Enter 6-digit OTP" autocomplete="one-time-code">
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-resend" id="resend-edit-user-otp-btn">Resend OTP</button>
                    <button type="button" class="doc-btn doc-btn-save" id="confirm-edit-user-otp-btn">Confirm OTP</button>
                </div>
            </div>
        </div>
    </div>

    <div class="doc-modal" id="disable-user-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-disable-user aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="disable-user-title">
            <div class="doc-modal-header">
                <h2 id="disable-user-title">Disable User</h2>
                <button type="button" class="doc-modal-close" data-close-disable-user aria-label="Close">&times;</button>
            </div>
            <form method="post" id="disable-user-form" class="doc-modal-form">
                <input type="hidden" name="action" value="disable_user">
                <input type="hidden" name="user_id" id="disable-user-id" value="">
                <input type="hidden" name="target_name" id="disable-target-name" value="">
                <div class="doc-form-field">
                    <label>User</label>
                    <input type="text" id="disable-user-name" value="" readonly>
                </div>
                <div class="doc-form-field">
                    <label for="disable-user-reason">Reason <span class="required">*</span></label>
                    <input type="text" id="disable-user-reason" name="reason" placeholder="Reason for disabling this account" required>
                </div>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-disable-user>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Disable User</button>
                </div>
            </form>
        </div>
    </div>

    <div class="doc-modal" id="suspend-user-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-suspend-user aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="suspend-user-title">
            <div class="doc-modal-header">
                <h2 id="suspend-user-title">Suspend User</h2>
                <button type="button" class="doc-modal-close" data-close-suspend-user aria-label="Close">&times;</button>
            </div>
            <form method="post" id="suspend-user-form" class="doc-modal-form">
                <input type="hidden" name="action" value="suspend_user">
                <input type="hidden" name="user_id" id="suspend-user-id" value="">
                <input type="hidden" name="target_name" id="suspend-target-name" value="">
                <div class="doc-form-field">
                    <label>User</label>
                    <input type="text" id="suspend-user-name" value="" readonly>
                </div>
                <div class="doc-form-field">
                    <label>Suspend for <span class="required">*</span></label>
                    <div style="display:flex;gap:8px;">
                        <input type="number" min="1" step="1" id="suspend-duration-value" name="duration_value" placeholder="Value" required style="max-width:130px;">
                        <select id="suspend-duration-unit" name="duration_unit" required>
                            <option value="hours">Hours</option>
                            <option value="days">Days</option>
                            <option value="weeks">Weeks</option>
                            <option value="months">Months</option>
                            <option value="years">Years</option>
                        </select>
                    </div>
                </div>
                <div class="doc-form-field">
                    <label for="suspend-user-reason">Reason <span class="required">*</span></label>
                    <input type="text" id="suspend-user-reason" name="reason" placeholder="Reason for suspension" required>
                </div>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-suspend-user>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Suspend User</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function() {
        var usersToast = document.getElementById('users-toast');
        if (usersToast) {
            window.setTimeout(function() {
                usersToast.classList.add('is-hiding');
                window.setTimeout(function() {
                    if (usersToast && usersToast.parentNode) {
                        usersToast.parentNode.removeChild(usersToast);
                    }
                }, 280);
            }, 5000);
        }

        var addBtn = document.getElementById('add-user-btn');
        var usersFilterForm = document.getElementById('users-filter-form');
        var usersSearchInput = usersFilterForm ? usersFilterForm.querySelector('input[name="search"]') : null;
        var usersRoleSelect = usersFilterForm ? usersFilterForm.querySelector('select[name="role"]') : null;
        var usersDeptTabs = document.getElementById('users-dept-tabs');
        var usersDeptScrollLeft = document.getElementById('users-dept-scroll-left');
        var usersDeptScrollRight = document.getElementById('users-dept-scroll-right');
        var usersTableBody = document.getElementById('users-table-body');
        var headerTitleWrap = document.querySelector('.dashboard-title-wrap');
        var sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
        if (headerTitleWrap && sidebarToggleBtn && !headerTitleWrap.contains(sidebarToggleBtn)) {
            sidebarToggleBtn.classList.add('in-header');
            headerTitleWrap.insertBefore(sidebarToggleBtn, headerTitleWrap.firstChild);
        }
        var modal = document.getElementById('add-user-modal');
        var form = document.getElementById('add-user-form');
        var errorEl = document.getElementById('add-user-form-error');
        var emailEl = document.getElementById('add-user-email');
        var otpEl = document.getElementById('add-user-email-otp');
        var sendOtpBtn = document.getElementById('send-add-user-otp-btn');
        var otpStatusEl = document.getElementById('add-user-otp-status');
        var pwd = document.getElementById('add-user-password');
        var confirmPwd = document.getElementById('add-user-confirm-password');
        var strengthMsg = document.getElementById('password-strength-msg');
        var matchMsg = document.getElementById('password-match-msg');
        var shouldOpenAddUserModal = <?php echo $openAddUserModal ? 'true' : 'false'; ?>;
        var invalidAddUserField = <?php echo json_encode($addUserInvalidField); ?>;
        var isAddUserSuccess = <?php echo $addUserSuccess ? 'true' : 'false'; ?>;
        var serverFlashMsg = <?php echo json_encode((string)($msg ?? '')); ?>;
        var addUserDraftKey = 'dms_add_user_form_draft_v1';
        var draftFieldIds = [
            'add-user-username',
            'add-user-name',
            'add-user-email',
            'add-user-role',
            'add-user-password',
            'add-user-confirm-password'
        ];
        function updateUsersDeptScrollButtons() {
            if (!usersDeptTabs || !usersDeptScrollLeft || !usersDeptScrollRight) return;
            var hasOverflow = usersDeptTabs.scrollWidth > (usersDeptTabs.clientWidth + 2);
            usersDeptScrollLeft.hidden = !hasOverflow;
            usersDeptScrollRight.hidden = !hasOverflow;
            if (!hasOverflow) return;
            var maxLeft = usersDeptTabs.scrollWidth - usersDeptTabs.clientWidth;
            var currentLeft = usersDeptTabs.scrollLeft;
            usersDeptScrollLeft.disabled = currentLeft <= 4;
            usersDeptScrollRight.disabled = currentLeft >= (maxLeft - 4);
        }
        function scrollUsersDeptTabs(direction) {
            if (!usersDeptTabs) return;
            var delta = Math.max(180, Math.round(usersDeptTabs.clientWidth * 0.55));
            usersDeptTabs.scrollBy({
                left: direction === 'left' ? -delta : delta,
                behavior: 'smooth'
            });
        }
        if (usersDeptTabs) {
            usersDeptTabs.addEventListener('scroll', updateUsersDeptScrollButtons);
            window.addEventListener('resize', updateUsersDeptScrollButtons);
            updateUsersDeptScrollButtons();
        }
        if (usersDeptScrollLeft) {
            usersDeptScrollLeft.addEventListener('click', function() {
                scrollUsersDeptTabs('left');
            });
        }
        if (usersDeptScrollRight) {
            usersDeptScrollRight.addEventListener('click', function() {
                scrollUsersDeptTabs('right');
            });
        }
        function getUsersDataRows() {
            if (!usersTableBody) return [];
            return Array.prototype.slice.call(usersTableBody.querySelectorAll('tr[data-user-row="1"]'));
        }
        function ensureNoUsersRow() {
            if (!usersTableBody) return null;
            var existing = document.getElementById('no-users-row');
            if (existing) return existing;
            var row = document.createElement('tr');
            var cell = document.createElement('td');
            cell.colSpan = 7;
            cell.className = 'offices-empty';
            cell.id = 'no-users-row';
            cell.innerHTML = ''
                + '<div class="users-empty-state">'
                + '  <span class="users-empty-icon" aria-hidden="true">'
                + '    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
                + '      <path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"></path>'
                + '      <line x1="12" y1="10" x2="12" y2="16"></line>'
                + '      <line x1="9" y1="13" x2="15" y2="13"></line>'
                + '    </svg>'
                + '  </span>'
                + '  <span>No users found for this tab/filter.</span>'
                + '  <span class="users-empty-info">To add a department, click <a class="users-empty-link" href="offices-department.php">add here</a>.</span>'
                + '  <span class="users-empty-info">To add user, click add <a class="users-empty-link js-open-add-user-inline" href="#">here</a>.</span>'
                + '</div>';
            row.appendChild(cell);
            usersTableBody.appendChild(row);
            return cell;
        }
        function normalizeRoleFilter(roleValue) {
            var role = (roleValue || '').toLowerCase().trim();
            if (role === 'frontdesk') return 'staff';
            if (role === 'department_head' || role === 'dept_head') return 'departmenthead';
            return role;
        }
        function roleMatchesFilter(rowRole, selectedRole) {
            if (!selectedRole) return true;
            var normalizedRowRole = (rowRole || '').toLowerCase().trim();
            if (selectedRole === 'departmenthead') {
                return normalizedRowRole === 'departmenthead'
                    || normalizedRowRole === 'department_head'
                    || normalizedRowRole === 'dept_head';
            }
            return normalizedRowRole === selectedRole;
        }
        function searchMatchesFilter(haystack, query) {
            var q = (query || '').toLowerCase().trim();
            if (!q) return true;
            var terms = q.split(/\s+/).filter(function(part) { return !!part; });
            if (!terms.length) return true;
            var text = (haystack || '').toLowerCase();
            for (var i = 0; i < terms.length; i++) {
                if (text.indexOf(terms[i]) === -1) {
                    return false;
                }
            }
            return true;
        }
        function applyUsersClientFilter() {
            var rows = getUsersDataRows();
            if (!rows.length) return;
            var query = usersSearchInput ? usersSearchInput.value : '';
            var selectedRole = normalizeRoleFilter(usersRoleSelect ? usersRoleSelect.value : '');
            var visibleNo = 1;
            var visibleCount = 0;
            rows.forEach(function(row) {
                var rowRole = row.getAttribute('data-role') || '';
                var rowSearch = row.getAttribute('data-search') || '';
                var matched = roleMatchesFilter(rowRole, selectedRole) && searchMatchesFilter(rowSearch, query);
                row.style.display = matched ? '' : 'none';
                if (matched) {
                    visibleCount += 1;
                    var numberCell = row.querySelector('td');
                    if (numberCell) {
                        numberCell.textContent = String(visibleNo++);
                    }
                }
            });
            var noUsersCell = ensureNoUsersRow();
            if (noUsersCell) {
                noUsersCell.parentElement.style.display = visibleCount === 0 ? '' : 'none';
            }
        }
        if (usersFilterForm) {
            usersFilterForm.addEventListener('submit', function(e) {
                e.preventDefault();
                applyUsersClientFilter();
            });
        }
        if (usersSearchInput) {
            usersSearchInput.addEventListener('input', applyUsersClientFilter);
            usersSearchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    applyUsersClientFilter();
                }
            });
        }
        if (usersRoleSelect) {
            usersRoleSelect.addEventListener('change', applyUsersClientFilter);
        }
        if (usersTableBody) {
            usersTableBody.addEventListener('click', function(e) {
                var addInlineLink = e.target && e.target.closest ? e.target.closest('.js-open-add-user-inline') : null;
                if (!addInlineLink) return;
                e.preventDefault();
                openAddUserModal(true);
            });
        }
        applyUsersClientFilter();
        function isStrongPassword(value) {
            return /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/.test(value || '');
        }
        function normalizeAddUserEmailInput(rawValue) {
            var value = (rawValue || '').trim().toLowerCase();
            if (!value) return '';
            if (value.indexOf('@') === -1) {
                if (!/^[a-z0-9._%+\-]+$/i.test(value)) return '';
                return value + '@gmail.com';
            }
            return value;
        }
        function saveAddUserDraft() {
            if (!form || !window.sessionStorage) return;
            var draft = {};
            draftFieldIds.forEach(function(id) {
                var el = document.getElementById(id);
                if (el) draft[id] = el.value || '';
            });
            try {
                sessionStorage.setItem(addUserDraftKey, JSON.stringify(draft));
            } catch (e) {}
        }
        function restoreAddUserDraft() {
            if (!form || !window.sessionStorage) return;
            var raw = '';
            try {
                raw = sessionStorage.getItem(addUserDraftKey) || '';
            } catch (e) {
                raw = '';
            }
            if (!raw) return;
            try {
                var draft = JSON.parse(raw);
                draftFieldIds.forEach(function(id) {
                    var el = document.getElementById(id);
                    if (el && typeof draft[id] === 'string') {
                        el.value = draft[id];
                    }
                });
            } catch (e) {}
        }
        function clearAddUserDraft() {
            if (!window.sessionStorage) return;
            try {
                sessionStorage.removeItem(addUserDraftKey);
            } catch (e) {}
        }
        function showError(msg) {
            if (!errorEl) return;
            if (!msg) { errorEl.hidden = true; errorEl.textContent = ''; return; }
            errorEl.hidden = false; errorEl.textContent = msg;
        }
        function showOtpStatus(msg, isOk) {
            if (!otpStatusEl) return;
            if (!msg) {
                otpStatusEl.hidden = true;
                otpStatusEl.textContent = '';
                otpStatusEl.classList.remove('ok', 'err');
                return;
            }
            otpStatusEl.hidden = false;
            otpStatusEl.textContent = msg;
            otpStatusEl.classList.toggle('ok', !!isOk);
            otpStatusEl.classList.toggle('err', !isOk);
        }
        function setOtpInvalid(state) {
            if (!otpEl) return;
            otpEl.classList.toggle('is-invalid', !!state);
        }
        function updatePasswordStrengthStatus() {
            if (!strengthMsg || !pwd) return;
            var value = pwd.value || '';
            if (!value) {
                strengthMsg.hidden = true;
                strengthMsg.textContent = '';
                strengthMsg.classList.remove('weak', 'strong');
                return;
            }
            strengthMsg.hidden = false;
            if (isStrongPassword(value)) {
                strengthMsg.textContent = 'Password strength: Strong';
                strengthMsg.classList.add('strong');
                strengthMsg.classList.remove('weak');
            } else {
                strengthMsg.textContent = 'Password strength: Weak';
                strengthMsg.classList.add('weak');
                strengthMsg.classList.remove('strong');
            }
        }
        function updatePasswordMatchStatus() {
            if (!matchMsg || !pwd || !confirmPwd) return;
            if (!confirmPwd.value) {
                matchMsg.hidden = true;
                matchMsg.textContent = '';
                matchMsg.classList.remove('match', 'mismatch');
                return;
            }
            matchMsg.hidden = false;
            if (pwd.value === confirmPwd.value) {
                matchMsg.textContent = 'Passwords match.';
                matchMsg.classList.add('match');
                matchMsg.classList.remove('mismatch');
            } else {
                matchMsg.textContent = 'Passwords do not match.';
                matchMsg.classList.add('mismatch');
                matchMsg.classList.remove('match');
            }
        }
        function resetPasswordVisibility() {
            document.querySelectorAll('#add-user-modal .js-toggle-password').forEach(function(btn) {
                var targetId = btn.getAttribute('data-target');
                var input = targetId ? document.getElementById(targetId) : null;
                if (input) input.type = 'password';
                btn.setAttribute('aria-pressed', 'false');
                btn.setAttribute('aria-label', 'Show password');
            });
        }
        function openAddUserModal(resetFields) {
            if (modal) {
                if (resetFields && form) {
                    form.reset();
                    clearAddUserDraft();
                    if (matchMsg) {
                        matchMsg.hidden = true;
                        matchMsg.textContent = '';
                        matchMsg.classList.remove('match', 'mismatch');
                    }
                    if (strengthMsg) {
                        strengthMsg.hidden = true;
                        strengthMsg.textContent = '';
                        strengthMsg.classList.remove('weak', 'strong');
                    }
                    showOtpStatus('');
                    setOtpInvalid(false);
                    resetPasswordVisibility();
                }
                modal.hidden = false;
                document.body.classList.add('modal-open');
                showError('');
            }
        }
        function closeAddUserModal() {
            if (modal) {
                modal.hidden = true;
                document.body.classList.remove('modal-open');
                showError('');
                if (matchMsg) {
                    matchMsg.hidden = true;
                    matchMsg.textContent = '';
                    matchMsg.classList.remove('match', 'mismatch');
                }
                if (strengthMsg) {
                    strengthMsg.hidden = true;
                    strengthMsg.textContent = '';
                    strengthMsg.classList.remove('weak', 'strong');
                }
                showOtpStatus('');
                setOtpInvalid(false);
                resetPasswordVisibility();
            }
        }
        if (addBtn) addBtn.addEventListener('click', function() { openAddUserModal(true); });
        document.querySelectorAll('[data-close-add-user]').forEach(function(btn) { btn.addEventListener('click', closeAddUserModal); });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && modal && !modal.hidden) closeAddUserModal(); });
        if (isAddUserSuccess) {
            clearAddUserDraft();
        }
        if (pwd) {
            pwd.addEventListener('input', updatePasswordMatchStatus);
            pwd.addEventListener('input', updatePasswordStrengthStatus);
        }
        if (confirmPwd) confirmPwd.addEventListener('input', updatePasswordMatchStatus);
        if (emailEl) {
            emailEl.addEventListener('input', function() {
                showOtpStatus('');
            });
        }
        if (otpEl) {
            otpEl.addEventListener('input', function() {
                otpEl.value = (otpEl.value || '').replace(/\D/g, '').slice(0, 6);
                setOtpInvalid(false);
            });
            otpEl.addEventListener('paste', function(e) {
                var pasted = (e.clipboardData || window.clipboardData).getData('text') || '';
                var digits = pasted.replace(/\D/g, '').slice(0, 6);
                e.preventDefault();
                otpEl.value = digits;
                setOtpInvalid(false);
            });
        }
        draftFieldIds.forEach(function(id) {
            var el = document.getElementById(id);
            if (!el) return;
            var eventName = el.tagName === 'SELECT' ? 'change' : 'input';
            el.addEventListener(eventName, saveAddUserDraft);
        });
        if (sendOtpBtn) {
            sendOtpBtn.addEventListener('click', function() {
                if (!emailEl) return;
                var emailValue = normalizeAddUserEmailInput(emailEl.value);
                if (!emailValue) {
                    showOtpStatus('Enter a valid Gmail/email first. You may type only the Gmail username.', false);
                    return;
                }
                emailEl.value = emailValue;
                sendOtpBtn.disabled = true;
                var originalText = sendOtpBtn.textContent;
                sendOtpBtn.textContent = 'Sending...';
                var fd = new FormData();
                fd.append('action', 'send_add_user_otp');
                fd.append('email', emailValue);
                var nameEl = document.getElementById('add-user-name');
                var usernameEl = document.getElementById('add-user-username');
                fd.append('name', (nameEl && nameEl.value ? nameEl.value : (usernameEl && usernameEl.value ? usernameEl.value : '')).trim());
                fetch('users.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                }).then(function(resp) {
                    return resp.text().then(function(raw) {
                        var data = null;
                        try {
                            data = raw ? JSON.parse(raw) : null;
                        } catch (e) {
                            data = null;
                        }
                        if (!resp.ok || !data || !data.success) {
                            var msg = (data && data.message) ? data.message : ('Failed to send OTP (HTTP ' + resp.status + ').');
                            throw new Error(msg);
                        }
                        return data;
                    });
                }).then(function(data) {
                    setOtpInvalid(false);
                    showOtpStatus(data.message || 'OTP sent successfully.', true);
                }).catch(function(err) {
                    showOtpStatus(err.message || 'Failed to send OTP.', false);
                }).finally(function() {
                    sendOtpBtn.disabled = false;
                    sendOtpBtn.textContent = originalText;
                });
            });
        }
        document.querySelectorAll('#add-user-modal .js-toggle-password').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var targetId = btn.getAttribute('data-target');
                var input = targetId ? document.getElementById(targetId) : null;
                if (!input) return;
                var isVisible = input.type === 'text';
                input.type = isVisible ? 'password' : 'text';
                btn.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
                btn.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
            });
        });
        if (form) {
            form.addEventListener('submit', function(e) {
                saveAddUserDraft();
                if (emailEl) {
                    var normalizedEmail = normalizeAddUserEmailInput(emailEl.value);
                    if (!normalizedEmail) {
                        e.preventDefault();
                        showOtpStatus('Enter a valid Gmail/email first. You may type only the Gmail username.', false);
                        showError('Enter a valid Gmail/email address first.');
                        return;
                    }
                    emailEl.value = normalizedEmail;
                }
                if (!pwd || !confirmPwd) { return; }
                if (!isStrongPassword(pwd.value)) {
                    e.preventDefault();
                    showError('Password must be at least 8 characters and include uppercase, lowercase, number, and special character.');
                    return;
                }
                if (!otpEl || !/^\d{6}$/.test((otpEl.value || '').trim())) {
                    e.preventDefault();
                    setOtpInvalid(true);
                    showOtpStatus('Invalid OTP', false);
                    showError('Invalid OTP');
                    return;
                }
                if (pwd.value !== confirmPwd.value) {
                    e.preventDefault();
                    showError('Password and retype password do not match.');
                    updatePasswordMatchStatus();
                    return;
                }
                showError('');
            });
        }
        if (shouldOpenAddUserModal) {
            restoreAddUserDraft();
            openAddUserModal(false);
            if (invalidAddUserField === 'otp') {
                if (otpEl) otpEl.value = '';
                setOtpInvalid(true);
                showOtpStatus('Invalid OTP', false);
            }
            updatePasswordStrengthStatus();
            updatePasswordMatchStatus();
        }
        var editBtn = document.getElementById('edit-user-btn');
        if (editBtn) editBtn.addEventListener('click', function() { alert('Click the Edit button on a user row.'); });

        var editModal = document.getElementById('edit-user-modal');
        var editForm = document.getElementById('edit-user-form');
        var editUserId = document.getElementById('edit-user-id');
        var editUsername = document.getElementById('edit-user-username');
        var editName = document.getElementById('edit-user-name');
        var editEmail = document.getElementById('edit-user-email');
        var editOriginalEmail = document.getElementById('edit-user-original-email');
        var editEmailChangeOtp = document.getElementById('edit-email-change-otp');
        var editOtpConfirmModal = document.getElementById('edit-user-otp-confirm-modal');
        var editOtpConfirmInput = document.getElementById('edit-user-otp-confirm-input');
        var editOtpConfirmMessage = document.getElementById('edit-user-otp-confirm-message');
        var confirmEditUserOtpBtn = document.getElementById('confirm-edit-user-otp-btn');
        var resendEditUserOtpBtn = document.getElementById('resend-edit-user-otp-btn');
        var editOtpResendCooldownEndsAt = 0;
        var editOtpResendCooldownTimer = null;
        var editRole = document.getElementById('edit-user-role');
        var editPwd = document.getElementById('edit-user-password');
        var editConfirmPwd = document.getElementById('edit-user-confirm-password');
        var editStrengthMsg = document.getElementById('edit-password-strength-msg');
        var editMatchMsg = document.getElementById('edit-password-match-msg');
        var editErr = document.getElementById('edit-user-form-error');
        var shouldOpenEditUserModal = <?php echo $openEditUserModal ? 'true' : 'false'; ?>;
        function showEditError(msg) {
            if (!editErr) return;
            if (!msg) { editErr.hidden = true; editErr.textContent = ''; return; }
            editErr.hidden = false; editErr.textContent = msg;
        }
        function normalizeEmail(value) {
            return (value || '').trim().toLowerCase();
        }
        function isEditEmailChanged() {
            if (!editEmail) return false;
            return normalizeEmail(editEmail.value) !== normalizeEmail(editOriginalEmail ? editOriginalEmail.value : '');
        }
        function clearEditEmailChangeOtp() {
            if (editEmailChangeOtp) editEmailChangeOtp.value = '';
        }
        function showEditOtpConfirmMessage(msg, isError) {
            if (!editOtpConfirmMessage) return;
            editOtpConfirmMessage.textContent = msg || '';
            editOtpConfirmMessage.classList.toggle('err', !!isError);
        }
        function stopEditOtpResendCooldown() {
            if (editOtpResendCooldownTimer) {
                clearInterval(editOtpResendCooldownTimer);
                editOtpResendCooldownTimer = null;
            }
            editOtpResendCooldownEndsAt = 0;
            if (resendEditUserOtpBtn) {
                resendEditUserOtpBtn.disabled = false;
                resendEditUserOtpBtn.textContent = 'Resend OTP';
            }
        }
        function tickEditOtpResendCooldown() {
            if (!resendEditUserOtpBtn || editOtpResendCooldownEndsAt <= 0) return;
            var remaining = Math.max(0, Math.ceil((editOtpResendCooldownEndsAt - Date.now()) / 1000));
            if (remaining <= 0) {
                stopEditOtpResendCooldown();
                return;
            }
            resendEditUserOtpBtn.disabled = true;
            resendEditUserOtpBtn.textContent = 'Resend OTP (' + remaining + 's)';
        }
        function startEditOtpResendCooldown(seconds) {
            var parsed = parseInt(seconds, 10);
            if (!(parsed > 0)) return;
            editOtpResendCooldownEndsAt = Date.now() + (parsed * 1000);
            if (editOtpResendCooldownTimer) {
                clearInterval(editOtpResendCooldownTimer);
            }
            tickEditOtpResendCooldown();
            editOtpResendCooldownTimer = setInterval(tickEditOtpResendCooldown, 1000);
        }
        function openEditOtpConfirmModal(message) {
            if (!editOtpConfirmModal) return;
            if (editModal) editModal.hidden = true;
            if (editOtpConfirmInput) editOtpConfirmInput.value = '';
            showEditOtpConfirmMessage(message || 'OTP sent to old Gmail/email.', false);
            editOtpConfirmModal.hidden = false;
            document.body.classList.add('modal-open');
            if (editOtpResendCooldownEndsAt > Date.now()) {
                tickEditOtpResendCooldown();
            }
            if (editOtpConfirmInput) {
                setTimeout(function() { editOtpConfirmInput.focus(); }, 0);
            }
        }
        function closeEditOtpConfirmModal(restoreEditModal) {
            if (!editOtpConfirmModal) return;
            editOtpConfirmModal.hidden = true;
            if (editOtpConfirmInput) editOtpConfirmInput.value = '';
            showEditOtpConfirmMessage('OTP was sent to the old Gmail/email.', false);
            if (restoreEditModal && editModal) {
                editModal.hidden = false;
                document.body.classList.add('modal-open');
            }
        }
        function requestEditEmailChangeOtp() {
            var fd = new FormData();
            fd.append('action', 'send_edit_user_change_otp');
            fd.append('user_id', (editUserId && editUserId.value) ? editUserId.value.trim() : '');
            fd.append('new_email', (editEmail && editEmail.value) ? editEmail.value.trim() : '');
            fd.append('name', ((editName && editName.value) ? editName.value : ((editUsername && editUsername.value) ? editUsername.value : '')).trim());
            return fetch('users.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(function(resp) {
                return resp.json().then(function(data) {
                    if (!resp.ok || !data.success) {
                        var err = new Error((data && data.message) ? data.message : 'Failed to send OTP.');
                        if (data && typeof data.retry_after !== 'undefined') {
                            err.retryAfter = parseInt(data.retry_after, 10) || 0;
                        }
                        throw err;
                    }
                    return data;
                });
            });
        }
        function verifyEditEmailChangeOtp(otpDigits) {
            var fd = new FormData();
            fd.append('action', 'verify_edit_user_change_otp');
            fd.append('user_id', (editUserId && editUserId.value) ? editUserId.value.trim() : '');
            fd.append('new_email', (editEmail && editEmail.value) ? editEmail.value.trim() : '');
            fd.append('otp', otpDigits || '');
            return fetch('users.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(function(resp) {
                return resp.json().then(function(data) {
                    if (!resp.ok || !data.success) {
                        throw new Error((data && data.message) ? data.message : 'OTP verification failed.');
                    }
                    return data;
                });
            });
        }
        function normalizeEditRoleValue(roleValue) {
            var role = (roleValue || '').toLowerCase().trim();
            if (role === 'frontdesk') return 'staff';
            if (role === 'department_head' || role === 'dept_head') return 'departmenthead';
            if (role === 'superadmin' || role === 'admin' || role === 'user' || role === 'staff' || role === 'departmenthead') {
                return role;
            }
            return 'user';
        }
        function openEditUserModal(userData) {
            if (!editModal) return;
            if (userData) {
                if (editUserId) editUserId.value = userData.userId || '';
                if (editUsername) editUsername.value = userData.username || '';
                if (editName) editName.value = userData.name || '';
                if (editEmail) editEmail.value = userData.email || '';
                if (editOriginalEmail) editOriginalEmail.value = userData.email || '';
                clearEditEmailChangeOtp();
                stopEditOtpResendCooldown();
                closeEditOtpConfirmModal(false);
                if (editRole) editRole.value = normalizeEditRoleValue(userData.role || 'user');
                if (editPwd) editPwd.value = '';
                if (editConfirmPwd) editConfirmPwd.value = '';
            }
            if (editStrengthMsg) { editStrengthMsg.hidden = true; editStrengthMsg.textContent = ''; editStrengthMsg.classList.remove('weak', 'strong'); }
            if (editMatchMsg) { editMatchMsg.hidden = true; editMatchMsg.textContent = ''; editMatchMsg.classList.remove('match', 'mismatch'); }
            showEditError('');
            editModal.hidden = false;
            document.body.classList.add('modal-open');
        }
        function closeEditUserModal() {
            if (!editModal) return;
            editModal.hidden = true;
            document.body.classList.remove('modal-open');
            clearEditEmailChangeOtp();
            stopEditOtpResendCooldown();
            closeEditOtpConfirmModal(false);
            showEditError('');
        }
        document.querySelectorAll('.js-edit-user-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openEditUserModal({
                    userId: btn.getAttribute('data-user-id') || '',
                    username: btn.getAttribute('data-username') || '',
                    name: btn.getAttribute('data-name') || '',
                    email: btn.getAttribute('data-email') || '',
                    role: btn.getAttribute('data-role') || 'user'
                });
            });
        });
        document.querySelectorAll('[data-close-edit-user]').forEach(function(btn) { btn.addEventListener('click', closeEditUserModal); });
        function updateEditPasswordStrength() {
            if (!editStrengthMsg || !editPwd) return;
            var value = editPwd.value || '';
            if (!value) {
                editStrengthMsg.hidden = true; editStrengthMsg.textContent = ''; editStrengthMsg.classList.remove('weak', 'strong'); return;
            }
            editStrengthMsg.hidden = false;
            if (isStrongPassword(value)) {
                editStrengthMsg.textContent = 'Password strength: Strong';
                editStrengthMsg.classList.add('strong'); editStrengthMsg.classList.remove('weak');
            } else {
                editStrengthMsg.textContent = 'Password strength: Weak';
                editStrengthMsg.classList.add('weak'); editStrengthMsg.classList.remove('strong');
            }
        }
        function updateEditPasswordMatch() {
            if (!editMatchMsg || !editPwd || !editConfirmPwd) return;
            if (!editConfirmPwd.value) {
                editMatchMsg.hidden = true; editMatchMsg.textContent = ''; editMatchMsg.classList.remove('match', 'mismatch'); return;
            }
            editMatchMsg.hidden = false;
            if (editPwd.value === editConfirmPwd.value) {
                editMatchMsg.textContent = 'Passwords match.';
                editMatchMsg.classList.add('match'); editMatchMsg.classList.remove('mismatch');
            } else {
                editMatchMsg.textContent = 'Passwords do not match.';
                editMatchMsg.classList.add('mismatch'); editMatchMsg.classList.remove('match');
            }
        }
        if (editPwd) {
            editPwd.addEventListener('input', updateEditPasswordStrength);
            editPwd.addEventListener('input', updateEditPasswordMatch);
        }
        if (editConfirmPwd) editConfirmPwd.addEventListener('input', updateEditPasswordMatch);
        if (editEmail) {
            editEmail.addEventListener('input', function() {
                clearEditEmailChangeOtp();
                closeEditOtpConfirmModal(false);
            });
        }
        if (editOtpConfirmInput) {
            editOtpConfirmInput.addEventListener('input', function() {
                editOtpConfirmInput.value = (editOtpConfirmInput.value || '').replace(/\D/g, '').slice(0, 6);
            });
            editOtpConfirmInput.addEventListener('paste', function(e) {
                var pasted = (e.clipboardData || window.clipboardData).getData('text') || '';
                e.preventDefault();
                editOtpConfirmInput.value = pasted.replace(/\D/g, '').slice(0, 6);
            });
        }
        document.querySelectorAll('[data-close-edit-user-otp-confirm]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                closeEditOtpConfirmModal(true);
            });
        });
        if (confirmEditUserOtpBtn) {
            confirmEditUserOtpBtn.addEventListener('click', function() {
                var digits = String((editOtpConfirmInput && editOtpConfirmInput.value) ? editOtpConfirmInput.value : '').replace(/\D/g, '').slice(0, 6);
                if (!/^\d{6}$/.test(digits)) {
                    showEditOtpConfirmMessage('Please enter a valid 6-digit OTP.', true);
                    return;
                }
                verifyEditEmailChangeOtp(digits).then(function() {
                    if (editEmailChangeOtp) editEmailChangeOtp.value = digits;
                    showEditError('');
                    closeEditOtpConfirmModal(true);
                }).catch(function(err) {
                    showEditOtpConfirmMessage(err && err.message ? err.message : 'OTP verification failed.', true);
                });
            });
        }
        if (resendEditUserOtpBtn) {
            resendEditUserOtpBtn.addEventListener('click', function() {
                if (editOtpResendCooldownEndsAt > Date.now()) {
                    tickEditOtpResendCooldown();
                    return;
                }
                resendEditUserOtpBtn.disabled = true;
                var originalText = resendEditUserOtpBtn.textContent;
                resendEditUserOtpBtn.textContent = 'Sending...';
                requestEditEmailChangeOtp().then(function(data) {
                    startEditOtpResendCooldown(15);
                    clearEditEmailChangeOtp();
                    if (editOtpConfirmInput) editOtpConfirmInput.value = '';
                    showEditOtpConfirmMessage((data && data.message) ? data.message : 'OTP resent to old Gmail/email.', false);
                }).catch(function(err) {
                    if (err && err.retryAfter > 0) {
                        startEditOtpResendCooldown(err.retryAfter);
                    } else {
                        showEditOtpConfirmMessage(err && err.message ? err.message : 'Failed to resend OTP.', true);
                    }
                }).finally(function() {
                    if (!(editOtpResendCooldownEndsAt > Date.now())) {
                        resendEditUserOtpBtn.disabled = false;
                        resendEditUserOtpBtn.textContent = originalText;
                    }
                });
            });
        }
        document.querySelectorAll('#edit-user-modal .js-toggle-edit-password').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var targetId = btn.getAttribute('data-target');
                var input = targetId ? document.getElementById(targetId) : null;
                if (!input) return;
                var isVisible = input.type === 'text';
                input.type = isVisible ? 'password' : 'text';
                btn.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
                btn.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
            });
        });
        if (editForm) {
            editForm.addEventListener('submit', function(e) {
                var pwdValue = (editPwd && editPwd.value) ? editPwd.value : '';
                var confirmValue = (editConfirmPwd && editConfirmPwd.value) ? editConfirmPwd.value : '';
                var requireOtp = isEditEmailChanged();
                if (pwdValue !== '' && !isStrongPassword(pwdValue)) {
                    e.preventDefault();
                    showEditError('Password must be at least 8 characters and include uppercase, lowercase, number, and special character.');
                    return;
                }
                if (pwdValue !== '' && pwdValue !== confirmValue) {
                    e.preventDefault();
                    showEditError('Password and retype password do not match.');
                    updateEditPasswordMatch();
                    return;
                }
                if (requireOtp && (!editEmailChangeOtp || !/^\d{6}$/.test((editEmailChangeOtp.value || '').trim()))) {
                    e.preventDefault();
                    requestEditEmailChangeOtp().then(function(data) {
                        startEditOtpResendCooldown(15);
                        openEditOtpConfirmModal((data && data.message) ? data.message : 'OTP sent to old Gmail/email.');
                    }).catch(function(err) {
                        if (err && err.retryAfter > 0) {
                            openEditOtpConfirmModal(err && err.message ? err.message : 'Please wait before resending OTP.');
                            startEditOtpResendCooldown(err.retryAfter);
                        } else {
                            showEditError(err && err.message ? err.message : 'Failed to send OTP.');
                        }
                    });
                    return;
                }
                if (!editUserId || !editUserId.value.trim()) {
                    e.preventDefault();
                    showEditError('Invalid user selected.');
                }
            });
        }
        if (shouldOpenEditUserModal) {
            openEditUserModal();
            if (editUserId) editUserId.value = <?php echo json_encode($editModalData['user_id']); ?>;
            if (editUsername) editUsername.value = <?php echo json_encode($editModalData['username']); ?>;
            if (editName) editName.value = <?php echo json_encode($editModalData['name']); ?>;
            if (editEmail) editEmail.value = <?php echo json_encode($editModalData['email']); ?>;
            if (editOriginalEmail) editOriginalEmail.value = <?php echo json_encode($editOriginalEmail); ?>;
            clearEditEmailChangeOtp();
            closeEditOtpConfirmModal(false);
            if (editRole) editRole.value = <?php echo json_encode($editModalData['role']); ?>;
            showEditError(<?php echo json_encode($openEditUserModal && !$msgOk ? (string)$msg : ''); ?>);
        }

        var disableModal = document.getElementById('disable-user-modal');
        var disableForm = document.getElementById('disable-user-form');
        var disableUserId = document.getElementById('disable-user-id');
        var disableTargetName = document.getElementById('disable-target-name');
        var disableUserName = document.getElementById('disable-user-name');
        var disableReason = document.getElementById('disable-user-reason');
        function openDisableModal(userId, userName) {
            if (!disableModal) return;
            if (disableUserId) disableUserId.value = userId || '';
            if (disableTargetName) disableTargetName.value = userName || '';
            if (disableUserName) disableUserName.value = userName || '';
            if (disableReason) disableReason.value = '';
            disableModal.hidden = false;
            document.body.classList.add('modal-open');
        }
        function closeDisableModal() {
            if (!disableModal) return;
            disableModal.hidden = true;
            document.body.classList.remove('modal-open');
            if (disableForm) disableForm.reset();
        }
        document.querySelectorAll('.js-disable-user-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openDisableModal(btn.getAttribute('data-user-id') || '', btn.getAttribute('data-user-name') || 'User');
            });
        });
        document.querySelectorAll('[data-close-disable-user]').forEach(function(btn) { btn.addEventListener('click', closeDisableModal); });
        if (disableForm) {
            disableForm.addEventListener('submit', function(e) {
                if (!disableUserId || disableUserId.value.trim() === '') {
                    e.preventDefault();
                    alert('No target user selected.');
                }
            });
        }

        var suspendModal = document.getElementById('suspend-user-modal');
        var suspendForm = document.getElementById('suspend-user-form');
        var suspendUserId = document.getElementById('suspend-user-id');
        var suspendTargetName = document.getElementById('suspend-target-name');
        var suspendUserName = document.getElementById('suspend-user-name');
        var suspendDurationValue = document.getElementById('suspend-duration-value');
        function openSuspendModal(userId, userName) {
            if (!suspendModal) return;
            if (suspendUserId) suspendUserId.value = userId || '';
            if (suspendTargetName) suspendTargetName.value = userName || '';
            if (suspendUserName) suspendUserName.value = userName || '';
            if (suspendDurationValue) suspendDurationValue.value = '';
            suspendModal.hidden = false;
            document.body.classList.add('modal-open');
        }
        function closeSuspendModal() {
            if (!suspendModal) return;
            suspendModal.hidden = true;
            document.body.classList.remove('modal-open');
            if (suspendForm) suspendForm.reset();
        }
        document.querySelectorAll('.js-suspend-user-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openSuspendModal(btn.getAttribute('data-user-id') || '', btn.getAttribute('data-user-name') || 'User');
            });
        });
        document.querySelectorAll('[data-close-suspend-user]').forEach(function(btn) { btn.addEventListener('click', closeSuspendModal); });
        if (suspendForm) {
            suspendForm.addEventListener('submit', function(e) {
                var value = parseInt((suspendDurationValue && suspendDurationValue.value) || '0', 10);
                if (value < 1) {
                    e.preventDefault();
                    alert('Suspend duration must be at least 1.');
                    return;
                }
                if (!suspendUserId || suspendUserId.value.trim() === '') {
                    e.preventDefault();
                    alert('No target user selected.');
                }
            });
        }

        function formatCountdown(totalSeconds) {
            var t = Math.max(0, parseInt(totalSeconds || 0, 10));
            var h = Math.floor(t / 3600);
            var m = Math.floor((t % 3600) / 60);
            var s = t % 60;
            var hh = h < 100 ? String(h).padStart(2, '0') : String(h);
            return hh + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0') + ' remaining';
        }

        function tickSuspendCountdowns() {
            document.querySelectorAll('.js-suspend-countdown').forEach(function(el) {
                var raw = el.getAttribute('data-remaining-seconds');
                var remaining = parseInt(raw || '', 10);
                if (isNaN(remaining)) return;
                if (remaining <= 0) {
                    el.textContent = '00:00:00 remaining';
                    var cell = el.closest('td');
                    var badge = cell ? cell.querySelector('.users-status-badge') : null;
                    if (badge) {
                        badge.classList.remove('suspended');
                        badge.classList.add('active');
                        badge.textContent = 'Active';
                    }
                    el.removeAttribute('data-remaining-seconds');
                    return;
                }
                remaining -= 1;
                el.setAttribute('data-remaining-seconds', String(remaining));
                el.textContent = formatCountdown(remaining);
            });
        }

        tickSuspendCountdowns();
        window.setInterval(tickSuspendCountdowns, 1000);
    })();
    </script>
    <?php $notifJsVer = @filemtime(__DIR__ . '/assets/js/super_admin_notifications.js') ?: time(); ?>
    <script src="assets/js/sidebar_super_admin.js"></script>
    <script src="assets/js/super_admin_notifications.js?v=<?= (int)$notifJsVer ?>"></script>
</body>
</html>
