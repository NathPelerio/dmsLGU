<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'Super Admin';
$userDepartment = $_SESSION['user_department'] ?? 'Not Assigned';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$sidebar_active = 'offices';

// Load config
$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/_account_helpers.php';
require_once __DIR__ . '/_notifications_super_admin.php';
$notifData = getSuperAdminNotifications($config);
$notifCount = $notifData['count'];
$notifItems = $notifData['items'];

/**
 * Department-head role aliases used in normalized users.role values.
 * @return string SQL IN clause values
 */
function departmentHeadRolesSqlList() {
    return "'departmenthead','department_head','dept_head'";
}

/**
 * Fetch users for Assign Head dropdown.
 * Returns compatibility keys expected by existing UI.
 * @return array
 */
function getUsers() {
    try {
        global $config;
        $pdo = dbPdo($config);
        $headRoleSql = departmentHeadRolesSqlList();
        $stmt = $pdo->query(
            "SELECT
                user_id,
                username,
                name,
                email,
                role,
                office_id
             FROM users
             WHERE LOWER(TRIM(role)) IN (" . $headRoleSql . ")
             ORDER BY COALESCE(NULLIF(username, ''), NULLIF(name, ''), email) ASC"
        );
        $rows = [];
        foreach ($stmt as $arr) {
            $arr['_id'] = (string)($arr['user_id'] ?? '');
            $username = trim($arr['username'] ?? '');
            $name = trim($arr['name'] ?? '');
            if ($username !== '' || $name !== '') {
                $rows[] = $arr;
            }
        }
        return $rows;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Fetch offices with derived office head details from users table.
 * UI compatibility fields are preserved: _id, office_head, office_head_id, description.
 * @param string $search Search term for office name or code
 * @return array
 */
function getOffices($search = '') {
    try {
        global $config;
        $pdo = dbPdo($config);
        $headRoleSql = departmentHeadRolesSqlList();
        $baseSql =
            "SELECT
                o.office_id,
                o.office_name,
                o.office_code,
                o.created_at,
                u.user_id AS office_head_id,
                COALESCE(NULLIF(u.username, ''), NULLIF(u.name, ''), u.email) AS office_head
             FROM offices o
             LEFT JOIN users u
                ON u.office_id = o.office_id
               AND LOWER(TRIM(u.role)) IN (" . $headRoleSql . ")";
        if ($search !== '') {
            $stmt = $pdo->prepare(
                $baseSql . '
                 WHERE o.office_name LIKE :s OR o.office_code LIKE :s
                 ORDER BY o.office_name ASC, u.user_id ASC'
            );
            $stmt->execute([':s' => '%' . $search . '%']);
        } else {
            $stmt = $pdo->query($baseSql . ' ORDER BY o.office_name ASC, u.user_id ASC');
        }
        $rows = [];
        $seenOffice = [];
        foreach ($stmt as $arr) {
            $officeId = (string)($arr['office_id'] ?? '');
            if ($officeId === '' || isset($seenOffice[$officeId])) {
                continue;
            }
            $seenOffice[$officeId] = true;
            $arr['_id'] = $officeId;
            $arr['office_head_id'] = (string)($arr['office_head_id'] ?? '');
            $arr['office_head'] = (string)($arr['office_head'] ?? '');
            // Keep compatibility key used by UI; details not part of normalized offices table.
            $arr['description'] = '';
            $rows[] = $arr;
        }
        return $rows;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Build per-office document flow stats.
 * - received_by_office: documents routed to each office
 * - sent_by_head: documents sent by office head accounts
 * @return array
 */
function getOfficeDocumentStats() {
    try {
        global $config;
        $pdo = dbPdo($config);
        $receivedByOffice = [];
        $sentByHead = [];
        $pendingByOffice = [];
        $archivedByHead = [];
        $receivedSendersByOffice = [];
        $sentRecipientsByHead = [];

        $headRoleSql = departmentHeadRolesSqlList();

        $receivedStmt = $pdo->query(
            'SELECT to_office_id AS office_id, COUNT(*) AS total
             FROM document_routes
             WHERE to_office_id IS NOT NULL
             GROUP BY to_office_id'
        );
        foreach ($receivedStmt as $row) {
            $officeId = (string)($row['office_id'] ?? '');
            if ($officeId === '') {
                continue;
            }
            $receivedByOffice[$officeId] = (int)($row['total'] ?? 0);
        }

        $pendingStmt = $pdo->query(
            "SELECT to_office_id AS office_id, COUNT(*) AS total
             FROM document_routes
             WHERE to_office_id IS NOT NULL
               AND LOWER(TRIM(COALESCE(status, ''))) LIKE 'pending%'
             GROUP BY to_office_id"
        );
        foreach ($pendingStmt as $row) {
            $officeId = (string)($row['office_id'] ?? '');
            if ($officeId === '') {
                continue;
            }
            $pendingByOffice[$officeId] = (int)($row['total'] ?? 0);
        }

        $receivedSendersStmt = $pdo->query(
            'SELECT
                dr.to_office_id AS office_id,
                COALESCE(NULLIF(u.username, \'\'), NULLIF(u.name, \'\'), u.email, \'Unknown sender\') AS sender_name,
                COUNT(*) AS total,
                MAX(dr.route_date) AS latest_received_at
             FROM document_routes dr
             LEFT JOIN users u ON u.user_id = dr.from_user_id
             WHERE dr.to_office_id IS NOT NULL
             GROUP BY dr.to_office_id, sender_name
             ORDER BY dr.to_office_id ASC, total DESC, sender_name ASC'
        );
        foreach ($receivedSendersStmt as $row) {
            $officeId = (string)($row['office_id'] ?? '');
            if ($officeId === '') {
                continue;
            }
            $senderName = trim((string)($row['sender_name'] ?? ''));
            if ($senderName === '') {
                $senderName = 'Unknown sender';
            }
            if (!isset($receivedSendersByOffice[$officeId])) {
                $receivedSendersByOffice[$officeId] = [];
            }
            $receivedSendersByOffice[$officeId][] = [
                'name' => $senderName,
                'count' => (int)($row['total'] ?? 0),
                'latest_received_at' => (string)($row['latest_received_at'] ?? ''),
            ];
        }

        $sentStmt = $pdo->query(
            "SELECT dr.from_user_id AS sent_by_user_id, COUNT(*) AS total
             FROM document_routes dr
             INNER JOIN users u ON u.user_id = dr.from_user_id
             WHERE dr.from_user_id IS NOT NULL
               AND LOWER(TRIM(u.role)) IN (" . $headRoleSql . ")
             GROUP BY dr.from_user_id"
        );
        foreach ($sentStmt as $row) {
            $userId = (string)($row['sent_by_user_id'] ?? '');
            if ($userId === '') {
                continue;
            }
            $sentByHead[$userId] = (int)($row['total'] ?? 0);
        }

        $sentRecipientsStmt = $pdo->query(
            "SELECT
                dr.from_user_id AS sent_by_user_id,
                COALESCE(NULLIF(o.office_name, ''), 'Recipient') AS recipient_label,
                COUNT(*) AS total,
                MAX(dr.route_date) AS latest_sent_at
             FROM document_routes dr
             LEFT JOIN offices o ON o.office_id = dr.to_office_id
             INNER JOIN users u ON u.user_id = dr.from_user_id
             WHERE dr.from_user_id IS NOT NULL
               AND LOWER(TRIM(u.role)) IN (" . $headRoleSql . ")
             GROUP BY dr.from_user_id, recipient_label
             ORDER BY dr.from_user_id ASC, total DESC, recipient_label ASC"
        );
        foreach ($sentRecipientsStmt as $row) {
            $userId = trim((string)($row['sent_by_user_id'] ?? ''));
            if ($userId === '') {
                continue;
            }
            if (!isset($sentRecipientsByHead[$userId])) {
                $sentRecipientsByHead[$userId] = [];
            }
            $sentRecipientsByHead[$userId][] = [
                'name' => (string)($row['recipient_label'] ?? 'Recipient'),
                'count' => (int)($row['total'] ?? 0),
                'latest_sent_at' => (string)($row['latest_sent_at'] ?? ''),
            ];
        }

        $archivedStmt = $pdo->query(
            "SELECT da.performed_by AS user_id, COUNT(*) AS total
             FROM document_actions da
             INNER JOIN users u ON u.user_id = da.performed_by
             WHERE da.performed_by IS NOT NULL
               AND LOWER(TRIM(da.action_type)) = 'archived'
               AND LOWER(TRIM(u.role)) IN (" . $headRoleSql . ")
             GROUP BY da.performed_by"
        );
        foreach ($archivedStmt as $row) {
            $userId = trim((string)($row['user_id'] ?? ''));
            if ($userId === '') {
                continue;
            }
            $archivedByHead[$userId] = (int)($row['total'] ?? 0);
        }

        return [
            'received_by_office' => $receivedByOffice,
            'sent_by_head' => $sentByHead,
            'pending_by_office' => $pendingByOffice,
            'archived_by_head' => $archivedByHead,
            'received_senders_by_office' => $receivedSendersByOffice,
            'sent_recipients_by_head' => $sentRecipientsByHead,
        ];
    } catch (Exception $e) {
        return [
            'received_by_office' => [],
            'sent_by_head' => [],
            'pending_by_office' => [],
            'archived_by_head' => [],
            'received_senders_by_office' => [],
            'sent_recipients_by_head' => [],
        ];
    }
}

/**
 * Add a new office.
 * @param string $officeCode
 * @param string $officeName
 * @param string $officeHead
 * @param string $description
 * @return array ['success' => bool, 'message' => string]
 */
function addOffice($officeCode, $officeName, $officeHead, $description = '') {
    global $config;
    $officeCode = trim($officeCode);
    $officeName = trim($officeName);
    if ($officeCode === '' || $officeName === '') {
        return ['success' => false, 'message' => 'Office code and name are required.'];
    }
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare(
            'INSERT INTO offices (office_code, office_name, created_at)
             VALUES (:office_code, :office_name, :created_at)'
        );
        $stmt->execute([
            ':office_code' => $officeCode,
            ':office_name' => $officeName,
            ':created_at' => dbNowUtcString(),
        ]);
        return ['success' => true, 'message' => 'Department added successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

/**
 * Update an existing office.
 */
function updateOffice($id, $officeCode, $officeName, $officeHead, $description = '') {
    global $config;
    $officeCode = trim($officeCode);
    $officeName = trim($officeName);
    if ($officeCode === '' || $officeName === '') {
        return ['success' => false, 'message' => 'Office code and name are required.'];
    }
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare(
            'UPDATE offices
             SET office_code = :office_code, office_name = :office_name
             WHERE office_id = :id'
        );
        $stmt->execute([
            ':office_code' => $officeCode,
            ':office_name' => $officeName,
            ':id' => $id,
        ]);
        return ['success' => true, 'message' => 'Department updated successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

/**
 * Assign or update department head by user account ID.
 * Looks up the user and stores office_head_id and office_head (display name).
 */
function assignHead($id, $officeHeadUserId) {
    global $config;
    if ($id === '') {
        return ['success' => false, 'message' => 'Invalid department ID.'];
    }
    $officeHeadUserId = trim($officeHeadUserId);
    if ($officeHeadUserId !== '') {
        try {
            $pdo = dbPdo($config);
            $stmt = $pdo->prepare('SELECT user_id FROM users WHERE user_id = :id LIMIT 1');
            $stmt->execute([':id' => $officeHeadUserId]);
            $u = $stmt->fetch();
            if (!$u) {
                return ['success' => false, 'message' => 'Invalid user selected.'];
            }
            $pdo->beginTransaction();
            $headRoleSql = departmentHeadRolesSqlList();
            // Ensure one primary head per office by demoting existing heads in this office.
            $demote = $pdo->prepare(
                "UPDATE users
                 SET role = 'staff'
                 WHERE office_id = :office_id
                   AND user_id <> :user_id
                   AND LOWER(TRIM(role)) IN (" . $headRoleSql . ")"
            );
            $demote->execute([
                ':office_id' => (int)$id,
                ':user_id' => (int)$officeHeadUserId,
            ]);
            $assign = $pdo->prepare(
                "UPDATE users
                 SET office_id = :office_id, role = 'department_head'
                 WHERE user_id = :user_id"
            );
            $assign->execute([
                ':office_id' => (int)$id,
                ':user_id' => (int)$officeHeadUserId,
            ]);
            $pdo->commit();
            return ['success' => true, 'message' => 'Department head assigned successfully.'];
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Invalid user selected.'];
        }
    }
    try {
        $pdo = dbPdo($config);
        $headRoleSql = departmentHeadRolesSqlList();
        $demote = $pdo->prepare(
            "UPDATE users
             SET role = 'staff'
             WHERE office_id = :office_id
               AND LOWER(TRIM(role)) IN (" . $headRoleSql . ")"
        );
        $demote->execute([':office_id' => (int)$id]);
        return ['success' => true, 'message' => 'Department head cleared.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

/**
 * Delete an office by ID.
 * @param string $id MongoDB _id (string)
 * @return array ['success' => bool, 'message' => string]
 */
function deleteOffice($id) {
    global $config;
    if ($id === '') {
        return ['success' => false, 'message' => 'Invalid office ID.'];
    }
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare('DELETE FROM offices WHERE office_id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return ['success' => true, 'message' => 'Office deleted successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

// Handle POST actions
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'verify_current_password' && !empty($_SESSION['user_id'])) {
        header('Content-Type: application/json; charset=UTF-8');
        $result = verifyCurrentPassword($_SESSION['user_id'], $_POST['current_password'] ?? '');
        http_response_code(!empty($result['success']) ? 200 : 422);
        echo json_encode($result);
        exit;
    }
    if ($action === 'add') {
        $flash = addOffice(
            $_POST['office_code'] ?? '',
            $_POST['office_name'] ?? '',
            $_POST['office_head'] ?? '',
            $_POST['description'] ?? ''
        );
    } elseif ($action === 'update' && !empty($_POST['office_id'])) {
        $flash = updateOffice(
            $_POST['office_id'],
            $_POST['office_code'] ?? '',
            $_POST['office_name'] ?? '',
            $_POST['office_head'] ?? '',
            $_POST['description'] ?? ''
        );
    } elseif ($action === 'assign_head' && !empty($_POST['office_id'])) {
        $flash = assignHead($_POST['office_id'], $_POST['office_head_id'] ?? '');
    } elseif ($action === 'delete' && !empty($_POST['office_id'])) {
        $flash = deleteOffice($_POST['office_id']);
    } elseif ($action === 'change_password' && !empty($_SESSION['user_id'])) {
        $flash = changePassword(
            $_SESSION['user_id'],
            $_POST['current_password'] ?? '',
            $_POST['new_password'] ?? '',
            $_POST['confirm_password'] ?? ''
        );
    } elseif ($action === 'update_profile' && !empty($_SESSION['user_id'])) {
        $flash = updateUserProfileBasics(
            $_SESSION['user_id'],
            $_POST['name'] ?? '',
            $_POST['username'] ?? '',
            isset($_POST['photo']) ? (string)$_POST['photo'] : null
        );
    } elseif ($action === 'update_signature' && !empty($_SESSION['user_id']) && isset($_POST['signature'])) {
        $flash = updateUserSignature($_SESSION['user_id'], $_POST['signature']);
    } elseif ($action === 'update_photo' && !empty($_SESSION['user_id']) && isset($_POST['photo'])) {
        $flash = updateUserPhoto($_SESSION['user_id'], $_POST['photo']);
    }
    if ($flash) {
        $redirectTarget = 'offices-department.php';
        $redirectInput = trim((string)($_POST['redirect'] ?? ''));
        if ($redirectInput !== '' && preg_match('/^[A-Za-z0-9._-]+\.php$/', $redirectInput)) {
            $redirectTarget = $redirectInput;
        }
        header('Location: ' . $redirectTarget . '?msg=' . urlencode($flash['message']) . '&ok=' . ($flash['success'] ? '1' : '0'));
        exit;
    }
}

// Query params for filters
$search = trim($_GET['search'] ?? '');
$msg = $_GET['msg'] ?? null;
$msgOk = isset($_GET['ok']) && $_GET['ok'] === '1';
$offices = getOffices($search);
$officeDocStats = getOfficeDocumentStats();
$receivedSendersByOfficeJson = json_encode($officeDocStats['received_senders_by_office'] ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($receivedSendersByOfficeJson)) {
    $receivedSendersByOfficeJson = '{}';
}
$sentRecipientsByOffice = [];
foreach ($offices as $officeRow) {
    $officeIdRow = trim((string)($officeRow['_id'] ?? ''));
    $headIdRow = trim((string)($officeRow['office_head_id'] ?? ''));
    if ($officeIdRow === '') {
        continue;
    }
    $sentRecipientsByOffice[$officeIdRow] = $headIdRow !== ''
        ? (array)($officeDocStats['sent_recipients_by_head'][$headIdRow] ?? [])
        : [];
}
$sentRecipientsByOfficeJson = json_encode($sentRecipientsByOffice, JSON_UNESCAPED_UNICODE);
if (!is_string($sentRecipientsByOfficeJson)) {
    $sentRecipientsByOfficeJson = '{}';
}
$usersList = getUsers();
$userSignature = isset($_SESSION['user_signature']) ? $_SESSION['user_signature'] : getUserSignature($_SESSION['user_id'] ?? '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Offices/Department</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/css/styles.css">
    <?php $sidebarCssVer = @filemtime(__DIR__ . '/assets/css/sidebar_super_admin.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/css/sidebar_super_admin.css?v=<?= (int)$sidebarCssVer ?>">
    <link rel="stylesheet" href="assets/css/profile_modal_super_admin.css">
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
        .main-content { background: #f8fafc; }
        .content-header { background: #fff; padding: 1.5rem 2.2rem; border-bottom: 1px solid #e2e8f0; }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: nowrap; }
        .dashboard-title-wrap { display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1; }
        .dashboard-header h1 { font-size: 1.6rem; margin: 0 0 0.2rem 0; font-weight: 700; color: #1e293b; }
        .dashboard-header small { display: block; color: #64748b; font-size: 0.95rem; margin-top: 6px; }
        .content-body { padding: 2rem 2.2rem; }
        .header-controls { position: relative; }
        .icon-btn { background: #f1f5f9; border: none; color: #475569; padding: 0; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .icon-btn:hover { background: #e2e8f0; color: #1e293b; }
        .icon-btn { position: relative; width: 48px; height: 48px; }
        .icon-btn svg { width: 26px; height: 26px; }
        .notif-badge { position: absolute; top: 6px; right: 6px; background: #ef4444; color: white; font-size: 13px; padding: 4px 8px; border-radius: 999px; line-height: 1; }
        .profile-dropdown { position: absolute; right: 0; top: 54px; background: white; color: #0b1720; min-width: 240px; border-radius: 8px; box-shadow: 0 8px 20px rgba(2,6,23,0.12); border: 1px solid #e6eef8; display: none; z-index: 1200; padding: 10px 0; }
        .profile-link { display: flex; align-items: center; gap: 8px; padding: 10px 12px; text-decoration: none; color: #0b1720; }
        .profile-link svg { width: 16px; height: 16px; flex-shrink: 0; }
        .profile-link:hover { background: #f1f5f9; }
        .settings-modal-overlay { position: fixed; inset: 0; background: rgba(27, 21, 72, 0.5); z-index: 2000; display: none; align-items: center; justify-content: center; padding: 1rem; overflow-y: auto; }
        .settings-modal-overlay.settings-modal-open { display: flex; }
        .profile-photo-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; }
        .profile-photo-card h3 { margin: 0 0 0.25rem 0; font-size: 1.1rem; font-weight: 700; color: #1e293b; }
        .profile-photo-card .profile-info-desc { margin: 0 0 1rem 0; font-size: 0.9rem; color: #64748b; }
        .profile-photo-row { display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; }
        .profile-photo-avatar { width: 80px; height: 80px; border-radius: 50%; background: #63B3ED; color: #fff; font-size: 2rem; font-weight: 700; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .profile-photo-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-photo-actions { flex: 1; min-width: 0; }
        .profile-signature-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; }
        .profile-signature-card h3 { margin: 0 0 0.25rem 0; font-size: 1.1rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 8px; }
        .profile-signature-card h3 svg { width: 20px; height: 20px; color: #3B82F6; flex-shrink: 0; }
        .profile-signature-card .profile-info-desc { margin: 0 0 1rem 0; font-size: 0.9rem; color: #64748b; }
        .profile-signature-current { margin-bottom: 1rem; }
        .profile-signature-current-label { font-size: 0.8rem; font-weight: 600; color: #64748b; margin-bottom: 0.5rem; display: block; }
        .profile-signature-box { width: 100%; max-width: 320px; height: 120px; border: 1px dashed #cbd5e1; border-radius: 8px; background: #f8fafc; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .profile-signature-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .profile-signature-box.empty { color: #94a3b8; font-size: 0.9rem; }
        .profile-signature-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; background: #3B82F6; color: #fff; border: none; border-radius: 10px; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
        .profile-signature-btn:hover { background: #2563eb; color: #fff; }
        .profile-signature-btn svg { width: 18px; height: 18px; flex-shrink: 0; }
        .signature-modal-overlay { position: fixed; inset: 0; z-index: 300; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(0,0,0,0.4); }
        .signature-modal-overlay.signature-modal-open { display: flex; }
        .signature-modal-overlay[aria-hidden="true"] { display: none; }
        .signature-modal { background: #fff; border-radius: 12px; width: 100%; max-width: 480px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 20px 40px rgba(0,0,0,0.2); }
        .signature-modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; }
        .signature-modal-header h3 { margin: 0; font-size: 1.2rem; font-weight: 700; color: #1e293b; }
        .signature-modal-close { width: 36px; height: 36px; border: none; background: transparent; color: #64748b; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .signature-modal-close:hover { background: #f1f5f9; color: #1e293b; }
        .signature-modal-close svg { width: 20px; height: 20px; }
        .signature-tabs { display: flex; border-bottom: 1px solid #e5e7eb; padding: 0 1rem; gap: 0; }
        .signature-tab { padding: 12px 20px; border: none; background: none; font-size: 0.95rem; font-weight: 500; color: #64748b; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: inherit; }
        .signature-tab:hover { color: #1e293b; }
        .signature-tab.active { color: #3B82F6; border-bottom-color: #3B82F6; }
        .signature-modal-body { padding: 1.25rem; overflow-y: auto; flex: 1; min-height: 0; }
        .signature-pane { display: none; }
        .signature-pane.active { display: block; }
        .signature-upload-zone { border: 2px dashed #cbd5e1; border-radius: 10px; padding: 2rem; text-align: center; background: #f8fafc; cursor: pointer; transition: border-color 0.2s, background 0.2s; }
        .signature-upload-zone:hover, .signature-upload-zone.dragover { border-color: #3B82F6; background: rgba(59, 130, 246, 0.05); }
        .signature-upload-zone input[type="file"] { display: none; }
        .signature-upload-preview { max-width: 100%; max-height: 180px; margin-top: 1rem; display: none; }
        .signature-upload-preview.show { display: block; }
        .signature-canvas-wrap { border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; background: #fff; }
        #signature-pad { display: block; width: 100%; height: 200px; cursor: crosshair; touch-action: none; }
        .signature-actions { display: flex; gap: 10px; margin-top: 1rem; flex-wrap: wrap; }
        .signature-actions .btn-clear { background: #64748b; color: #fff; }
        .signature-actions .btn-clear:hover { background: #475569; color: #fff; }
        .signature-modal-footer { padding: 1rem 1.25rem; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; }
        .delete-confirm-modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); z-index: 2500; display: none; align-items: center; justify-content: center; padding: 1rem; }
        .delete-confirm-modal-overlay.show { display: flex; }
        .delete-confirm-modal { background: #fff; border-radius: 12px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); width: 100%; max-width: 420px; overflow: hidden; }
        .delete-confirm-modal-header { padding: 1.5rem 1.5rem 1rem; text-align: center; }
        .delete-confirm-modal-icon { width: 64px; height: 64px; margin: 0 auto 1rem; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; }
        .delete-confirm-modal-icon svg { width: 32px; height: 32px; }
        .delete-confirm-modal-title { font-size: 1.25rem; font-weight: 700; color: #1e293b; margin: 0 0 0.5rem 0; }
        .delete-confirm-modal-message { font-size: 0.95rem; color: #64748b; margin: 0; }
        .delete-confirm-modal-body { padding: 0 1.5rem 1.5rem; }
        .delete-confirm-modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #e5e7eb; display: flex; gap: 10px; justify-content: flex-end; }
        .delete-confirm-btn-cancel { padding: 10px 20px; border: 1px solid #e2e8f0; background: #fff; color: #64748b; border-radius: 8px; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
        .delete-confirm-btn-cancel:hover { background: #f8fafc; color: #1e293b; }
        .delete-confirm-btn-delete { padding: 10px 20px; border: none; background: #dc2626; color: #fff; border-radius: 8px; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
        .delete-confirm-btn-delete:hover { background: #b91c1c; color: #fff; }
        .main-content {
            background: #f1f5f9;
            /* Keep sticky header working even with shared sidebar styles. */
            overflow: visible !important;
        }
        .content-header {
            position: sticky;
            top: 0;
            z-index: 1200;
        }
        .dept-page-header { display: block; margin: 0; }
        .dept-header-actions { display: flex; align-items: center; gap: 12px; flex-wrap: nowrap; flex-shrink: 0; }
        .dept-content-actions { display: flex; justify-content: flex-end; margin-bottom: 0.9rem; }
        .dept-page-title-row { display: flex; align-items: center; gap: 0.7rem; }
        .dept-page-title-icon { width: 34px; height: 34px; border-radius: 10px; background: #dbeafe; color: #2563eb; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .dept-page-title-icon svg { width: 19px; height: 19px; }
        .dept-page-title { margin: 0; font-size: 1.6rem; font-weight: 700; color: #1e293b; }
        .dept-page-subtitle { margin: 0.25rem 0 0 0; font-size: 0.95rem; color: #64748b; }
        .dept-add-btn { display: inline-flex; align-items: center; gap: 8px; padding: 0.6rem 1.25rem; background: #1A202C; color: #fff; border: none; border-radius: 10px; font-size: 0.95rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: background 0.15s; }
        .dept-add-btn:hover { background: #2d3748; color: #fff; }
        .dept-add-btn svg { width: 20px; height: 20px; flex-shrink: 0; }
        .dept-search-row { display: flex; align-items: stretch; gap: 0; margin-bottom: 1.5rem; max-width: 100%; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
        .dept-search-wrap { flex: 1; position: relative; min-width: 0; display: flex; align-items: center; }
        .dept-search-wrap svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 20px; height: 20px; color: #94a3b8; pointer-events: none; flex-shrink: 0; }
        .dept-search { width: 100%; height: 44px; padding: 0 16px 0 44px; border: none; border-radius: 0; font-size: 0.95rem; color: #1e293b; background: transparent; outline: none; }
        .dept-search::placeholder { color: #94a3b8; }
        .dept-search:focus { outline: none; }
        .dept-search-row:focus-within { border-color: #3B82F6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
        .dept-filter-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 44px; padding: 0 20px; border: none; border-left: 1px solid rgba(255,255,255,0.08); border-radius: 0; background: #1A202C; color: #fff; font-size: 0.95rem; font-weight: 600; cursor: pointer; outline: none; transition: background 0.15s, color 0.15s; flex-shrink: 0; font-family: inherit; -webkit-appearance: none; appearance: none; }
        .dept-filter-btn:hover { background: #2d3748; color: #fff; border-left-color: rgba(255,255,255,0.08); }
        .dept-filter-btn:focus { outline: none; }
        .dept-filter-btn:focus-visible { box-shadow: inset 0 0 0 2px rgba(255,255,255,0.3); }
        .dept-filter-btn svg { width: 18px; height: 18px; flex-shrink: 0; }
        .dept-cards-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; align-items: stretch; }
        .dept-card { background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.08); transition: box-shadow 0.2s ease; min-height: 320px; display: flex; flex-direction: column; overflow: hidden; }
        .dept-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .dept-card-header { padding: 1.5rem; display: flex; flex-direction: row; align-items: flex-start; justify-content: space-between; padding-bottom: 0.5rem; }
        .dept-card-header-left { display: flex; align-items: center; gap: 0.75rem; min-width: 0; flex: 1; }
        .dept-card-icon { width: 48px; height: 48px; border-radius: 0.5rem; background: #dbeafe; color: #2563eb; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .dept-card-icon svg { width: 24px; height: 24px; }
        .dept-card-title-wrap { min-width: 0; }
        .dept-card-name { font-size: 1.125rem; font-weight: 700; color: #1e293b; margin: 0; line-height: 1.3; letter-spacing: -0.01em; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .dept-card-code { font-size: 0.875rem; color: #64748b; margin: 0; font-family: ui-monospace, 'JetBrains Mono', monospace; }
        .dept-card-content { padding: 0 1.5rem 1.5rem; padding-top: 0; flex: 1; display: flex; flex-direction: column; min-height: 0; }
        .dept-card-desc { font-size: 0.875rem; color: #475569; margin: 0 0 1rem 0; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .dept-card-head-section { padding-top: 1rem; border-top: 1px solid #e5e7eb; }
        .dept-card-head-label { font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 0.25rem 0; }
        .dept-card-head-value { font-size: 0.9rem; font-weight: 500; color: #0f172a; margin: 0; }
        .dept-card-head-value.not-assigned { color: #94a3b8; font-weight: 500; }
        .dept-card-flow-stats { margin-top: 0.6rem; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 0.9rem; row-gap: 0.5rem; }
        .dept-card-flow-stat { margin: 0; font-size: 0.95rem; color: #475569; display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.45rem 0.55rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; min-height: 36px; }
        .dept-card-flow-stat svg { width: 14px; height: 14px; color: #64748b; flex-shrink: 0; }
        .dept-card-flow-stat strong { color: #1e293b; font-weight: 700; font-size: 1rem; }
        .view-flow-value { font-size: 1.08rem !important; font-weight: 600; }
        .dept-card-created-section { margin-top: auto; padding-top: 0.75rem; border-top: 1px solid #e5e7eb; }
        .dept-card-created { font-size: 0.75rem; color: #94a3b8; margin: 0; font-family: ui-monospace, 'JetBrains Mono', monospace; }
        .dept-card-menu { position: relative; flex-shrink: 0; }
        .dept-card-menu-btn { width: 36px; height: 36px; border: none; background: transparent; color: #64748b; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s ease, color 0.15s ease; }
        .dept-card-menu-btn:hover { background: #FEF3C7; color: #92400e; }
        .dept-card-menu-btn svg { width: 20px; height: 20px; }
        .dept-card-dropdown { position: absolute; right: 0; top: 100%; margin-top: 4px; background: #fff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border: 1px solid #e5e7eb; padding: 6px 0; min-width: 180px; z-index: 50; display: none; }
        .dept-card-dropdown.show { display: block; }
        .dept-empty { text-align: center; padding: 3rem 1rem; color: #64748b; font-size: 1rem; }
        .dept-empty.dept-empty-live { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.65rem; color: #64748b; }
        .dept-empty.dept-empty-live svg { width: 34px; height: 34px; color: #94a3b8; }
        .dept-empty.dept-empty-live span { font-size: 1.02rem; color: #475569; }
        .dept-empty.dept-empty-live .dept-empty-add-link { color: #2563eb; font-weight: 600; text-decoration: underline; cursor: pointer; }
        .dept-empty.dept-empty-live .dept-empty-add-link:hover { color: #1d4ed8; }
        .dept-toast { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 1500; display: flex; align-items: center; gap: 12px; padding: 0.875rem 1rem 0.875rem 1rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(0,0,0,0.15); max-width: 360px; animation: dept-toast-in 0.3s ease; }
        .dept-toast.success { background: #22c55e; color: #fff; }
        .dept-toast.error { background: #ef4444; color: #fff; }
        .dept-toast-icon { width: 24px; height: 24px; border-radius: 50%; background: rgba(255,255,255,0.3); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .dept-toast-icon svg { width: 14px; height: 14px; }
        .dept-toast-text { flex: 1; font-size: 0.95rem; font-weight: 500; }
        @keyframes dept-toast-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .offices-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 200; display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .offices-modal-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.4); cursor: pointer; }
        .offices-modal-content { position: relative; background: #fff; padding: 1.5rem 1.75rem; border-radius: 12px; width: 100%; max-width: 440px; box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        .offices-modal-content h3 { margin: 0 0 0.25rem 0; font-size: 1.35rem; font-weight: 700; color: #1e293b; }
        .offices-modal-subtitle { margin: 0 0 1.25rem 0; font-size: 0.9rem; color: #64748b; }
        .offices-modal-close { position: absolute; top: 1rem; right: 1rem; width: 32px; height: 32px; border: none; background: transparent; color: #64748b; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .offices-modal-close:hover { background: #f1f5f9; color: #1e293b; }
        .offices-modal-close svg { width: 20px; height: 20px; }
        .offices-field { margin-bottom: 1rem; }
        .offices-field label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
        .offices-field label .required { color: #dc2626; }
        .offices-field input, .offices-field textarea, .offices-field select { width: 100%; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; font-family: inherit; box-sizing: border-box; max-width: 100%; }
        .offices-field input, .offices-field select { height: 40px; }
        .offices-field select.offices-select { cursor: pointer; background: #fff; }
        .offices-field textarea { min-height: 80px; padding: 10px 12px; resize: vertical; }
        .offices-modal-actions { display: flex; gap: 10px; margin-top: 1.5rem; justify-content: flex-end; }
        .offices-modal-actions .offices-btn-create { display: inline-flex; align-items: center; gap: 8px; }
        .dept-card-dropdown .dept-dropdown-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 14px; border: none; background: none; color: #1e293b; font-size: 0.9rem; cursor: pointer; text-align: left; white-space: nowrap; transition: background 0.15s ease, color 0.15s ease; }
        .dept-card-dropdown .dept-dropdown-item:hover { background: #FEF3C7; color: #92400e; }
        .dept-card-dropdown .dept-dropdown-item.dept-dropdown-delete:hover { background: #fee2e2; color: #dc2626; }
        .dept-card-dropdown .dept-dropdown-item svg { width: 16px; height: 16px; flex-shrink: 0; }
        .offices-btn { height: 42px; padding: 0 16px; border: none; border-radius: 10px; background: #3B82F6; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .offices-btn:hover { background: #2563eb; }
        .offices-btn svg { width: 18px; height: 18px; flex-shrink: 0; }
        .offices-btn-secondary { background: #64748b; color: #fff; }
        .offices-btn-secondary:hover { background: #475569; color: #fff; }
        .dept-card-clickable { cursor: pointer; }
        .dept-view-modal-content { max-width: 480px; }
        .dept-view-modal-content .offices-field { margin-bottom: 1rem; }
        .dept-view-top-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem 1rem; }
        .dept-view-top-grid .offices-field { margin-bottom: 0; }
        .dept-view-modal-content .view-label { font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 0.25rem 0; }
        .dept-view-modal-content .view-value { font-size: 1rem; color: #1e293b; margin: 0 0 0.5rem 0; line-height: 1.4; }
        .dept-view-modal-content .view-flow-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.55rem; align-items: stretch; }
        .dept-view-modal-content .view-flow-item { display: inline-flex; align-items: center; gap: 0.28rem; color: #334155; padding: 0.4rem 0.5rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; }
        .dept-view-modal-content .view-flow-item svg { width: 15px; height: 15px; color: #64748b; flex-shrink: 0; }
        .dept-view-modal-content .view-flow-label { font-weight: 700; color: #1e293b; }
        .dept-view-modal-content .view-value .flow-clickable { color: #2563eb; text-decoration: underline; background: none; border: none; padding: 0; font: inherit; cursor: pointer; }
        .dept-view-modal-content .view-value .flow-clickable:hover { color: #1d4ed8; }
        .dept-view-modal-content .view-value.desc { white-space: pre-wrap; }
        .dept-senders-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.5rem; }
        .dept-senders-item { display: flex; justify-content: space-between; align-items: center; gap: 0.8rem; padding: 0.55rem 0.7rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
        .dept-senders-item-main { min-width: 0; display: grid; gap: 0.12rem; }
        .dept-senders-item-name { font-size: 0.92rem; color: #1e293b; }
        .dept-senders-item-time { font-size: 0.8rem; color: #64748b; }
        .dept-senders-item-count { font-size: 0.85rem; color: #475569; font-weight: 600; }
        .dept-senders-empty { font-size: 0.9rem; color: #64748b; margin: 0; }
        @media (max-width: 1200px) {
            .content-header, .content-body { padding-left: 1.3rem; padding-right: 1.3rem; }
            .dept-cards-grid { grid-template-columns: repeat(2, 1fr); }
            .dept-page-title { font-size: 1.6rem; }
            .dept-page-subtitle { font-size: 0.9rem; }
            .dept-card-name { font-size: 1.02rem; }
            .dept-card-desc { font-size: 0.84rem; }
            .dept-card-head-value { font-size: 0.86rem; }
            .dept-card-flow-stat { font-size: 0.9rem; }
            .dept-card-flow-stat strong { font-size: 0.95rem; }
            .dashboard-header .sidebar-toggle-btn.in-header {
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
            .dashboard-header .sidebar-toggle-btn.in-header:hover { background: #334155; }
            .sidebar.sidebar-open ~ .main-content .dashboard-header .sidebar-toggle-btn.in-header {
                opacity: 0;
                pointer-events: none;
            }
        }
        @media (max-width: 768px) {
            .content-header { padding: 1rem; }
            .content-body { padding: 1rem; }
            .dashboard-header { flex-wrap: nowrap; align-items: center; gap: 0.7rem; }
            .dashboard-title-wrap { flex: 1; min-width: 0; gap: 8px; width: auto; }
            .dept-page-header { margin: 0; width: 100%; }
            .dept-header-actions { width: auto; justify-content: flex-end; }
            .dept-page-title { font-size: 1.16rem; }
            .dept-page-subtitle { font-size: 0.78rem; margin-top: 3px; line-height: 1.25; }
            .dept-add-btn { height: 42px; padding: 0 0.95rem; }
            .dept-content-actions { margin-bottom: 0.75rem; }
            .dept-cards-grid { grid-template-columns: 1fr; gap: 1rem; }
            .dept-card-header { padding: 1rem 1rem 0.35rem; }
            .dept-card-content { padding: 0 1rem 1rem; }
            .dept-card-name { font-size: 0.98rem; }
            .dept-card-code { font-size: 0.8rem; }
            .dept-card-desc { font-size: 0.8rem; }
            .dept-card-head-label { font-size: 0.7rem; }
            .dept-card-head-value { font-size: 0.82rem; }
            .dept-card-flow-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .dept-card-flow-stat { font-size: 0.85rem; }
            .dept-card-flow-stat strong { font-size: 0.9rem; }
            .dept-view-top-grid { grid-template-columns: 1fr; }
            .dept-view-modal-content .view-flow-row { grid-template-columns: 1fr; }
            .offices-modal-content { padding: 1.15rem 1rem; }
        }
        @media (max-width: 640px) {
            .dept-page-title { font-size: 1.1rem; }
            .dept-page-subtitle { margin-top: 2px; font-size: 0.76rem; line-height: 1.2; }
            .dept-header-actions { gap: 8px; }
            .dept-content-actions { justify-content: flex-end; }
            .dept-add-btn { width: auto; justify-content: center; min-width: 0; padding: 0 0.85rem; height: 38px; font-size: 0.86rem; }
            .dept-search-row { flex-direction: row; border-radius: 10px; overflow: hidden; }
            .dept-filter-btn { width: auto; border-left: 1px solid rgba(255,255,255,0.08); border-top: none; border-radius: 0; justify-content: center; padding: 0 14px; font-size: 0.9rem; }
            .dept-search { height: 42px; }
            .dept-filter-btn { height: 42px; }
        }
        @media (max-width: 420px) {
            .content-header { padding: 0.85rem 0.7rem; }
            .dept-page-subtitle { display: none; }
            .dept-card-flow-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 0.45rem; row-gap: 0.4rem; }
            .dept-card-flow-stat { font-size: 0.78rem; padding: 0.35rem 0.42rem; min-height: 32px; gap: 0.2rem; }
            .dept-card-flow-stat strong { font-size: 0.8rem; }
            .dept-card-flow-stat svg { width: 12px; height: 12px; }
            .dept-page-title-icon { width: 34px; height: 34px; border-radius: 9px; }
            .dept-page-title-icon svg { width: 20px; height: 20px; }
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
                    <div class="dept-page-header" style="flex: 1; margin-bottom: 0;">
                        <div>
                            <div class="dept-page-title-row">
                                <span class="dept-page-title-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M4 21V7l8-4 8 4v14"/><path d="M9 21v-6h6v6"/><path d="M9 10h.01"/><path d="M15 10h.01"/></svg>
                                </span>
                                <h1 class="dept-page-title">Departments</h1>
                            </div>
                            <p class="dept-page-subtitle">Manage municipal departments and their heads</p>
                        </div>
                    </div>
                    </div>
                    <div class="dept-header-actions">
                        <div class="header-controls">
                            <?php include __DIR__ . '/_notif_dropdown_super_admin.php'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-body">
                <?php if ($msg !== null): ?>
                <div id="dept-toast" class="dept-toast <?= $msgOk ? 'success' : 'error' ?>" role="alert">
                    <div class="dept-toast-icon">
                        <?php if ($msgOk): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        <?php endif; ?>
                    </div>
                    <span class="dept-toast-text"><?= htmlspecialchars($msg) ?></span>
                </div>
                <?php endif; ?>
                <div class="dept-content-actions">
                    <button type="button" class="dept-add-btn" onclick="openAddModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>Add Department</button>
                </div>

                <form method="get" id="dept-search-form" class="dept-search-row">
                    <div class="dept-search-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" name="search" class="dept-search" placeholder="Search departments..." aria-label="Search departments" value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <button type="submit" class="dept-filter-btn" aria-label="Filter">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        Filter
                    </button>
                </form>

                <div class="dept-cards-grid" id="dept-cards-grid">
                    <?php if (count($offices) === 0): ?>
                    <p class="dept-empty" style="grid-column: 1 / -1;">No departments yet. Click &ldquo;Add Department&rdquo; to create one.</p>
                    <?php else: ?>
                    <?php foreach ($offices as $o):
                        $createdAt = $o['created_at'] ?? null;
                        $createdTs = dbToTimestamp($createdAt);
                        $createdAt = $createdTs !== null ? date('M j, Y', $createdTs) : '—';
                        $head = trim($o['office_head'] ?? '');
                        $headId = trim((string)($o['office_head_id'] ?? ''));
                        $officeId = trim((string)($o['_id'] ?? ''));
                        $desc = trim($o['description'] ?? '');
                        $descDisplay = $desc !== '' ? $desc : 'Municipal department';
                        $receivedCount = (int)($officeDocStats['received_by_office'][$officeId] ?? 0);
                        $sentCount = $headId !== '' ? (int)($officeDocStats['sent_by_head'][$headId] ?? 0) : 0;
                        $pendingCount = (int)($officeDocStats['pending_by_office'][$officeId] ?? 0);
                        $archivedCount = $headId !== '' ? (int)($officeDocStats['archived_by_head'][$headId] ?? 0) : 0;
                    ?>
                    <article class="dept-card dept-card-clickable" data-office-id="<?= htmlspecialchars($officeId) ?>" data-name="<?= htmlspecialchars($o['office_name'] ?? '') ?>" data-code="<?= htmlspecialchars($o['office_code'] ?? '') ?>" data-desc="<?= htmlspecialchars($descDisplay) ?>" data-head="<?= htmlspecialchars($head !== '' ? $head : 'Not assigned') ?>" data-sent="<?= (int)$sentCount ?>" data-received="<?= (int)$receivedCount ?>" data-pending="<?= (int)$pendingCount ?>" data-archived="<?= (int)$archivedCount ?>" data-created="<?= htmlspecialchars($createdAt) ?>" onclick="openViewModalFromCard(this)">
                        <div class="dept-card-header">
                            <div class="dept-card-header-left">
                                <div class="dept-card-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>
                                </div>
                                <div class="dept-card-title-wrap">
                                    <h3 class="dept-card-name"><?= htmlspecialchars($o['office_name'] ?? '') ?></h3>
                                    <p class="dept-card-code"><?= htmlspecialchars($o['office_code'] ?? '') ?></p>
                                </div>
                            </div>
                            <div class="dept-card-menu" onclick="event.stopPropagation()">
                                <button type="button" class="dept-card-menu-btn" aria-label="Options" onclick="toggleCardMenu(this)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                                </button>
                                <div class="dept-card-dropdown" role="menu">
                                    <button type="button" class="dept-dropdown-item dept-dropdown-edit" role="menuitem" data-id="<?= htmlspecialchars($o['_id']) ?>" data-code="<?= htmlspecialchars($o['office_code'] ?? '') ?>" data-name="<?= htmlspecialchars($o['office_name'] ?? '') ?>" data-head="<?= htmlspecialchars($o['office_head'] ?? '') ?>" data-desc="<?= htmlspecialchars($desc) ?>" onclick="openEditModal(this); closeCardMenu(this);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>Edit Department</button>
                                    <button type="button" class="dept-dropdown-item" role="menuitem" data-id="<?= htmlspecialchars($o['_id']) ?>" data-name="<?= htmlspecialchars($o['office_name'] ?? '') ?>" data-head-id="<?= htmlspecialchars($o['office_head_id'] ?? '') ?>" onclick="openAssignHeadModal(this); closeCardMenu(this);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Assign Head</button>
                                    <button type="button" class="dept-dropdown-item dept-dropdown-delete" role="menuitem" data-id="<?= htmlspecialchars($o['_id']) ?>" data-name="<?= htmlspecialchars($o['office_name'] ?? '') ?>" onclick="confirmDeleteOffice(this); closeCardMenu(this);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>Delete</button>
                                </div>
                            </div>
                        </div>
                        <div class="dept-card-content">
                            <p class="dept-card-desc"><?= htmlspecialchars($descDisplay) ?></p>
                            <div class="dept-card-head-section">
                                <p class="dept-card-head-label">Department Head</p>
                                <p class="dept-card-head-value <?= $head === '' ? 'not-assigned' : '' ?>"><?= $head !== '' ? htmlspecialchars($head) : 'Not assigned' ?></p>
                                <div class="dept-card-flow-stats">
                                    <p class="dept-card-flow-stat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13"/><path d="M22 2 15 22 11 13 2 9 22 2z"/></svg><strong>Sent:</strong> <?= (int)$sentCount ?></p>
                                    <p class="dept-card-flow-stat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-8"/><path d="m15 5-7 7 7 7"/><rect x="2" y="5" width="6" height="14" rx="1"/></svg><strong>Received:</strong> <?= (int)$receivedCount ?></p>
                                    <p class="dept-card-flow-stat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><strong>Pending:</strong> <?= (int)$pendingCount ?></p>
                                    <p class="dept-card-flow-stat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5" rx="1"/><line x1="10" y1="12" x2="14" y2="12"/></svg><strong>Archived:</strong> <?= (int)$archivedCount ?></p>
                                </div>
                            </div>
                            <div class="dept-card-created-section">
                                <p class="dept-card-created">Created: <?= $createdAt ?></p>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- View Department Modal -->
            <div id="modal-view" class="offices-modal" style="display:none;">
                <div class="offices-modal-overlay" onclick="closeViewModal()"></div>
                <div class="offices-modal-content dept-view-modal-content">
                    <button type="button" class="offices-modal-close" onclick="closeViewModal()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
                    <h3 id="view-modal-title">Department Details</h3>
                    <p class="offices-modal-subtitle">View department information.</p>
                    <div class="dept-view-top-grid">
                        <div class="offices-field">
                            <p class="view-label">Department Name</p>
                            <p class="view-value" id="view-name">—</p>
                        </div>
                        <div class="offices-field">
                            <p class="view-label">Department Code</p>
                            <p class="view-value" id="view-code">—</p>
                        </div>
                        <div class="offices-field">
                            <p class="view-label">Description</p>
                            <p class="view-value desc" id="view-desc">—</p>
                        </div>
                        <div class="offices-field">
                            <p class="view-label">Department Head</p>
                            <p class="view-value" id="view-head">—</p>
                        </div>
                    </div>
                    <div class="offices-field">
                        <p class="view-label">Document Flow</p>
                        <p class="view-value view-flow-value" id="view-flow-top">Sent: 0 | Received: 0 | Pending: 0 | Archived: 0</p>
                    </div>
                    <div class="offices-field">
                        <p class="view-label">Created</p>
                        <p class="view-value" id="view-created">—</p>
                    </div>
                    <div class="offices-modal-actions">
                        <button type="button" class="offices-btn offices-btn-secondary" onclick="closeViewModal()">Close</button>
                    </div>
                </div>
            </div>
            <div id="modal-received-senders" class="offices-modal" style="display:none;">
                <div class="offices-modal-overlay" onclick="closeReceivedSendersModal()"></div>
                <div class="offices-modal-content dept-view-modal-content">
                    <button type="button" class="offices-modal-close" onclick="closeReceivedSendersModal()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
                    <h3>Received Document Senders</h3>
                    <p class="offices-modal-subtitle" id="received-senders-subtitle">Department senders list</p>
                    <ul class="dept-senders-list" id="received-senders-list"></ul>
                    <p class="dept-senders-empty" id="received-senders-empty" style="display:none;">No received senders found for this department.</p>
                    <div class="offices-modal-actions">
                        <button type="button" class="offices-btn offices-btn-secondary" onclick="closeReceivedSendersModal()">Close</button>
                    </div>
                </div>
            </div>
            <div id="modal-sent-recipients" class="offices-modal" style="display:none;">
                <div class="offices-modal-overlay" onclick="closeSentRecipientsModal()"></div>
                <div class="offices-modal-content dept-view-modal-content">
                    <button type="button" class="offices-modal-close" onclick="closeSentRecipientsModal()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
                    <h3>Sent Document Recipients</h3>
                    <p class="offices-modal-subtitle" id="sent-recipients-subtitle">Department recipients list</p>
                    <ul class="dept-senders-list" id="sent-recipients-list"></ul>
                    <p class="dept-senders-empty" id="sent-recipients-empty" style="display:none;">No sent recipient records found for this department.</p>
                    <div class="offices-modal-actions">
                        <button type="button" class="offices-btn offices-btn-secondary" onclick="closeSentRecipientsModal()">Close</button>
                    </div>
                </div>
            </div>

            <div id="modal-add" class="offices-modal" style="display:none;">
                <div class="offices-modal-overlay" onclick="closeAddModal()"></div>
                <div class="offices-modal-content">
                    <button type="button" class="offices-modal-close" onclick="closeAddModal()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
                    <h3>Create New Department</h3>
                    <p class="offices-modal-subtitle">Add a new department to the system.</p>
                    <form method="post">
                        <input type="hidden" name="action" value="add">
                        <div class="offices-field">
                            <label>Department Name <span class="required">*</span></label>
                            <input type="text" name="office_name" required placeholder="e.g., Municipal Treasurer's Office.">
                        </div>
                        <div class="offices-field">
                            <label>Department Code <span class="required">*</span></label>
                            <input type="text" name="office_code" required placeholder="e.g., MTO.">
                        </div>
                        <div class="offices-field">
                            <label>Description</label>
                            <textarea name="description" placeholder="Brief description of the department..." rows="3"></textarea>
                        </div>
                        <div class="offices-modal-actions">
                            <button type="button" class="offices-btn offices-btn-secondary" onclick="closeAddModal()">Cancel</button>
                            <button type="submit" class="offices-btn offices-btn-create"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" width="18" height="18"><path d="M12 5v14"/><path d="M5 12h14"/></svg>Create Department</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="modal-edit" class="offices-modal" style="display:none;">
                <div class="offices-modal-overlay" onclick="closeEditModal()"></div>
                <div class="offices-modal-content">
                    <button type="button" class="offices-modal-close" onclick="closeEditModal()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
                    <h3>Edit Department</h3>
                    <p class="offices-modal-subtitle">Update department details.</p>
                    <form method="post">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="office_id" id="edit-office-id" value="">
                        <div class="offices-field"><label>Department Code <span class="required">*</span></label><input type="text" name="office_code" id="edit-office-code" required></div>
                        <div class="offices-field"><label>Department Name <span class="required">*</span></label><input type="text" name="office_name" id="edit-office-name" required></div>
                        <div class="offices-field"><label>Description</label><textarea name="description" id="edit-office-desc" rows="3" placeholder="Brief description of the department..."></textarea></div>
                        <input type="hidden" name="office_head" id="edit-office-head" value="">
                        <div class="offices-modal-actions">
                            <button type="button" class="offices-btn offices-btn-secondary" onclick="closeEditModal()">Cancel</button>
                            <button type="submit" class="offices-btn">Update</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="modal-assign-head" class="offices-modal" style="display:none;">
                <div class="offices-modal-overlay" onclick="closeAssignHeadModal()"></div>
                <div class="offices-modal-content">
                    <button type="button" class="offices-modal-close" onclick="closeAssignHeadModal()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
                    <h3>Assign Head</h3>
                    <p class="offices-modal-subtitle">Set or change the head for this department.</p>
                    <form method="post">
                        <input type="hidden" name="action" value="assign_head">
                        <input type="hidden" name="office_id" id="assign-office-id" value="">
                        <div class="offices-field">
                            <label>Department</label>
                            <input type="text" id="assign-office-name" readonly style="background:#f8fafc; color:#64748b;">
                        </div>
                        <div class="offices-field">
                            <label>Department Head</label>
                            <select name="office_head_id" id="assign-office-head" class="offices-select">
                                <option value="">— Select user —</option>
                                <?php foreach ($usersList as $u):
                                    $username = trim($u['username'] ?? '');
                                    $name = trim($u['name'] ?? '');
                                    $label = $username !== '' ? $username : ($name !== '' ? $name : trim($u['email'] ?? ''));
                                    if ($label === '') continue;
                                ?>
                                <option value="<?= htmlspecialchars($u['_id']) ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="offices-modal-actions">
                            <button type="button" class="offices-btn offices-btn-secondary" onclick="closeAssignHeadModal()">Cancel</button>
                            <button type="submit" class="offices-btn">Save</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            var receivedSendersByOffice = <?= $receivedSendersByOfficeJson ?>;
            var sentRecipientsByOffice = <?= $sentRecipientsByOfficeJson ?>;
            (function(){
                var searchForm = document.getElementById('dept-search-form');
                var searchInput = document.querySelector('.dept-search');
                var cardsGrid = document.getElementById('dept-cards-grid');
                var cards = cardsGrid ? Array.prototype.slice.call(cardsGrid.querySelectorAll('.dept-card')) : [];
                if (!searchInput || !cardsGrid || cards.length === 0) return;

                var emptyState = document.createElement('p');
                emptyState.className = 'dept-empty dept-empty-live';
                emptyState.id = 'dept-live-empty';
                emptyState.style.gridColumn = '1 / -1';
                emptyState.style.display = 'none';
                emptyState.innerHTML =
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="8.5" y1="11" x2="13.5" y2="11"></line></svg>'
                    + '<span>No departments match your search. <a href="#" class="dept-empty-add-link" id="dept-empty-add-link">Add department here</a>.</span>';
                cardsGrid.appendChild(emptyState);
                emptyState.addEventListener('click', function(e) {
                    var addLink = e.target && e.target.closest ? e.target.closest('.dept-empty-add-link') : null;
                    if (!addLink) return;
                    e.preventDefault();
                    if (typeof openAddModal === 'function') openAddModal();
                });

                function applyLiveFilter() {
                    var q = String(searchInput.value || '').toLowerCase().trim();
                    var terms = q === '' ? [] : q.split(/\s+/).filter(Boolean);
                    var visibleCount = 0;
                    cards.forEach(function(card) {
                        var d = card.dataset || {};
                        var haystack = [
                            d.name || '',
                            d.code || '',
                            d.desc || '',
                            d.head || ''
                        ].join(' ').toLowerCase();
                        var matched = terms.every(function(term) {
                            return haystack.indexOf(term) !== -1;
                        });
                        card.style.display = matched ? '' : 'none';
                        if (matched) visibleCount++;
                    });
                    emptyState.style.display = visibleCount === 0 ? '' : 'none';
                }

                searchInput.addEventListener('input', applyLiveFilter);
                if (searchForm) {
                    searchForm.addEventListener('submit', function(e) {
                        e.preventDefault();
                        applyLiveFilter();
                        if (searchInput && document.activeElement !== searchInput) {
                            searchInput.focus();
                        }
                    });
                }
                applyLiveFilter();
            })();
            function openAddModal() { document.getElementById('modal-add').style.display = 'flex'; }
            function closeAddModal() { document.getElementById('modal-add').style.display = 'none'; }
            function openViewModalFromCard(card) {
                var d = card.dataset || {};
                window.__activeViewOfficeId = d.officeId || '';
                window.__activeViewOfficeName = d.name || 'Department';
                document.getElementById('view-name').textContent = d.name || '—';
                document.getElementById('view-code').textContent = d.code || '—';
                document.getElementById('view-desc').textContent = d.desc || '—';
                document.getElementById('view-head').textContent = d.head || '—';
                var sent = Number(d.sent || 0);
                var received = Number(d.received || 0);
                var pending = Number(d.pending || 0);
                var archived = Number(d.archived || 0);
                document.getElementById('view-flow-top').innerHTML =
                    '<span class="view-flow-row">'
                    + '<span class="view-flow-item">'
                    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13"/><path d="M22 2 15 22 11 13 2 9 22 2z"/></svg>'
                    + '<span class="view-flow-label">Sent:</span> <button type="button" class="flow-clickable" onclick="openSentRecipientsModal()">' + sent + '</button>'
                    + '</span>'
                    + '<span class="view-flow-item">'
                    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-8"/><path d="m15 5-7 7 7 7"/><rect x="2" y="5" width="6" height="14" rx="1"/></svg>'
                    + '<span class="view-flow-label">Received:</span> <button type="button" class="flow-clickable" onclick="openReceivedSendersModal()">' + received + '</button>'
                    + '</span>'
                    + '<span class="view-flow-item">'
                    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>'
                    + '<span class="view-flow-label">Pending:</span> ' + pending
                    + '</span>'
                    + '<span class="view-flow-item">'
                    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5" rx="1"/><line x1="10" y1="12" x2="14" y2="12"/></svg>'
                    + '<span class="view-flow-label">Archived:</span> ' + archived
                    + '</span>'
                    + '</span>';
                document.getElementById('view-created').textContent = d.created || '—';
                document.getElementById('modal-view').style.display = 'flex';
            }
            function closeViewModal() { document.getElementById('modal-view').style.display = 'none'; }
            function formatFlowDateTime(raw, prefix) {
                if (!raw) return prefix + ': —';
                var ts = String(raw).trim();
                var m = ts.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}):(\d{2})(?::(\d{2}))?$/);
                if (!m) return prefix + ': ' + ts;
                var datePart = m[1];
                var h24 = parseInt(m[2], 10);
                var min = m[3];
                var sec = m[4] || '00';
                var ampm = h24 >= 12 ? 'PM' : 'AM';
                var h12 = h24 % 12;
                if (h12 === 0) h12 = 12;
                var hh = String(h12).padStart(2, '0');
                return prefix + ': ' + datePart + ' ' + hh + ':' + min + ':' + sec + ' ' + ampm;
            }
            function openSentRecipientsModal() {
                var officeId = window.__activeViewOfficeId || '';
                var officeName = window.__activeViewOfficeName || 'Department';
                var subtitle = document.getElementById('sent-recipients-subtitle');
                var list = document.getElementById('sent-recipients-list');
                var empty = document.getElementById('sent-recipients-empty');
                if (subtitle) subtitle.textContent = 'People this department sent documents to: ' + officeName + '.';
                if (list) list.innerHTML = '';
                var recipients = (sentRecipientsByOffice && officeId && sentRecipientsByOffice[officeId]) ? sentRecipientsByOffice[officeId] : [];
                if (!Array.isArray(recipients) || recipients.length === 0) {
                    if (empty) empty.style.display = '';
                } else {
                    if (empty) empty.style.display = 'none';
                    recipients.forEach(function(item) {
                        if (!list) return;
                        var li = document.createElement('li');
                        li.className = 'dept-senders-item';

                        var main = document.createElement('div');
                        main.className = 'dept-senders-item-main';

                        var name = document.createElement('span');
                        name.className = 'dept-senders-item-name';
                        name.textContent = (item && item.name) ? item.name : 'Recipient';

                        var time = document.createElement('span');
                        time.className = 'dept-senders-item-time';
                        time.textContent = formatFlowDateTime(item && item.latest_sent_at ? item.latest_sent_at : '', 'Last sent');

                        main.appendChild(name);
                        main.appendChild(time);

                        var count = document.createElement('span');
                        count.className = 'dept-senders-item-count';
                        var total = Number(item && item.count ? item.count : 0);
                        count.textContent = total + (total === 1 ? ' document' : ' documents');

                        li.appendChild(main);
                        li.appendChild(count);
                        list.appendChild(li);
                    });
                }
                document.getElementById('modal-sent-recipients').style.display = 'flex';
            }
            function closeSentRecipientsModal() { document.getElementById('modal-sent-recipients').style.display = 'none'; }
            function openReceivedSendersModal() {
                var officeId = window.__activeViewOfficeId || '';
                var officeName = window.__activeViewOfficeName || 'Department';
                var subtitle = document.getElementById('received-senders-subtitle');
                var list = document.getElementById('received-senders-list');
                var empty = document.getElementById('received-senders-empty');
                if (subtitle) subtitle.textContent = 'Who sent received documents to ' + officeName + '.';
                if (list) list.innerHTML = '';
                var senders = (receivedSendersByOffice && officeId && receivedSendersByOffice[officeId]) ? receivedSendersByOffice[officeId] : [];
                if (!Array.isArray(senders) || senders.length === 0) {
                    if (empty) empty.style.display = '';
                } else {
                    if (empty) empty.style.display = 'none';
                    senders.forEach(function(item) {
                        if (!list) return;
                        var li = document.createElement('li');
                        li.className = 'dept-senders-item';

                        var main = document.createElement('div');
                        main.className = 'dept-senders-item-main';

                        var name = document.createElement('span');
                        name.className = 'dept-senders-item-name';
                        name.textContent = (item && item.name) ? item.name : 'Unknown sender';

                        var time = document.createElement('span');
                        time.className = 'dept-senders-item-time';
                        time.textContent = formatFlowDateTime(item && item.latest_received_at ? item.latest_received_at : '', 'Received');

                        main.appendChild(name);
                        main.appendChild(time);

                        var count = document.createElement('span');
                        count.className = 'dept-senders-item-count';
                        var total = Number(item && item.count ? item.count : 0);
                        count.textContent = total + (total === 1 ? ' document' : ' documents');
                        li.appendChild(main);
                        li.appendChild(count);
                        list.appendChild(li);
                    });
                }
                document.getElementById('modal-received-senders').style.display = 'flex';
            }
            function closeReceivedSendersModal() { document.getElementById('modal-received-senders').style.display = 'none'; }
            function openEditModal(btn) {
                var d = btn.dataset || {};
                document.getElementById('edit-office-id').value = d.id || '';
                document.getElementById('edit-office-code').value = d.code || '';
                document.getElementById('edit-office-name').value = d.name || '';
                document.getElementById('edit-office-head').value = d.head || '';
                document.getElementById('edit-office-desc').value = d.desc || '';
                document.getElementById('modal-edit').style.display = 'flex';
            }
            function closeEditModal() { document.getElementById('modal-edit').style.display = 'none'; }
            function openAssignHeadModal(btn) {
                var d = btn.dataset || {};
                document.getElementById('assign-office-id').value = d.id || '';
                document.getElementById('assign-office-name').value = d.name || '';
                var headSelect = document.getElementById('assign-office-head');
                if (headSelect) headSelect.value = d.headId || '';
                document.getElementById('modal-assign-head').style.display = 'flex';
            }
            function closeAssignHeadModal() { document.getElementById('modal-assign-head').style.display = 'none'; }
            function setupDeleteDepartment() {
                var deleteModalOverlay = document.getElementById('delete-confirm-modal-overlay');
                var deleteConfirmMessage = document.getElementById('delete-confirm-message');
                var deleteOfficeIdInput = document.getElementById('delete-office-id');
                var deleteForm = document.getElementById('delete-office-form');
                var deleteConfirmCancel = document.getElementById('delete-confirm-cancel');
                var deleteConfirmDelete = document.getElementById('delete-confirm-delete');
                if (!deleteForm || !deleteOfficeIdInput) return;
                window.openDeleteConfirmModal = function(id, name) {
                    if (!id || !deleteModalOverlay) return;
                    deleteOfficeIdInput.value = id;
                    if (deleteConfirmMessage) deleteConfirmMessage.textContent = 'Are you sure you want to delete "' + (name || 'this department') + '"? This action cannot be undone.';
                    deleteModalOverlay.classList.add('show');
                };
                window.closeDeleteConfirmModal = function() {
                    if (deleteModalOverlay) deleteModalOverlay.classList.remove('show');
                };
                if (deleteConfirmCancel) deleteConfirmCancel.addEventListener('click', window.closeDeleteConfirmModal);
                if (deleteConfirmDelete) deleteConfirmDelete.addEventListener('click', function() {
                    deleteForm.submit();
                });
                if (deleteModalOverlay) deleteModalOverlay.addEventListener('click', function(e) {
                    if (e.target === deleteModalOverlay) window.closeDeleteConfirmModal();
                });
            }
            function confirmDeleteOffice(btn) {
                var d = btn.dataset || {};
                var id = d.id || '';
                var name = (d.name || 'this department').trim();
                if (!id) return;
                if (typeof window.openDeleteConfirmModal === 'function') {
                    window.openDeleteConfirmModal(id, name);
                } else {
                    if (confirm('Are you sure you want to delete "' + name + '"? This action cannot be undone.')) {
                        var form = document.getElementById('delete-office-form');
                        var input = document.getElementById('delete-office-id');
                        if (form && input) { input.value = id; form.submit(); }
                    }
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', setupDeleteDepartment);
            } else {
                setupDeleteDepartment();
            }
            function toggleCardMenu(menuBtn) {
                var dropdown = menuBtn.closest('.dept-card-menu').querySelector('.dept-card-dropdown');
                var open = dropdown.classList.contains('show');
                document.querySelectorAll('.dept-card-dropdown.show').forEach(function(el) { el.classList.remove('show'); });
                if (!open) dropdown.classList.add('show');
            }
            function closeCardMenu(insideEl) {
                var dropdown = insideEl.closest('.dept-card-dropdown');
                if (dropdown) dropdown.classList.remove('show');
            }
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.dept-card-menu')) document.querySelectorAll('.dept-card-dropdown.show').forEach(function(el) { el.classList.remove('show'); });
            });
            function closeDeptToast() {
                var toast = document.getElementById('dept-toast');
                if (toast) toast.remove();
            }
            (function(){
                var toast = document.getElementById('dept-toast');
                if (toast) setTimeout(closeDeptToast, 5000);
            })();
            </script>
    <?php include __DIR__ . '/_profile_modal_super_admin.php'; ?>
    <div class="settings-modal-overlay" id="settings-modal-overlay" aria-hidden="true">
        <div class="profile-modal" id="settings-modal" role="dialog" aria-labelledby="settings-modal-title">
            <div class="profile-modal-header">
                <button type="button" class="profile-modal-close-btn" id="settings-modal-close" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
                <h2 class="profile-modal-title" id="settings-modal-title">Settings</h2>
                <p class="profile-modal-subtitle">E-signature and profile photo</p>
            </div>
            <div class="profile-modal-body">
                <div class="profile-photo-card">
                    <h3>Profile Photo</h3>
                    <p class="profile-info-desc">Your avatar shown in the sidebar and across the app</p>
                    <div class="profile-photo-row">
                        <div class="profile-photo-avatar profile-photo-view-trigger" role="button" tabindex="0" data-photo="" title="Click to view"><?php if (!empty($_SESSION['user_photo'])): ?><img src="<?php echo htmlspecialchars($_SESSION['user_photo']); ?>" alt=""><?php else: ?><?php echo htmlspecialchars($userInitial); ?><?php endif; ?></div>
                        <div class="profile-photo-actions">
                            <label class="profile-signature-btn" for="profile-photo-file-input"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Upload Photo</label>
                            <input type="file" id="profile-photo-file-input" accept="image/png,image/jpeg,image/jpg,image/gif" style="display:none;">
                        </div>
                    </div>
                </div>
                <div class="profile-signature-card">
                    <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/></svg>E-Signature</h3>
                    <p class="profile-info-desc">Your digital signature for document approvals</p>
                    <div class="profile-signature-current">
                        <span class="profile-signature-current-label">Current Signature:</span>
                        <div class="profile-signature-box <?php echo $userSignature === '' ? 'empty' : ''; ?>">
                            <?php if ($userSignature !== ''): ?><img src="<?php echo htmlspecialchars($userSignature); ?>" alt="Your signature"><?php else: ?><span>No signature set</span><?php endif; ?>
                        </div>
                    </div>
                    <button type="button" class="profile-signature-btn" id="profile-update-signature-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Update Signature</button>
                </div>
            </div>
        </div>
    </div>
    <div class="signature-modal-overlay" id="signature-modal-overlay">
        <div class="signature-modal">
            <div class="signature-modal-header">
                <h3>Update Signature</h3>
                <button type="button" class="signature-modal-close" id="signature-modal-close" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
            </div>
            <div class="signature-tabs">
                <button type="button" class="signature-tab active" data-pane="upload">Upload Picture</button>
                <button type="button" class="signature-tab" data-pane="draw">Draw Signature</button>
            </div>
            <div class="signature-modal-body">
                <div class="signature-pane active" id="signature-pane-upload">
                    <label class="signature-upload-zone" id="signature-upload-zone" for="signature-file-input">
                        <span>Click or drag an image here (PNG, JPG)</span>
                        <input type="file" id="signature-file-input" accept="image/png,image/jpeg,image/jpg,image/gif">
                        <img class="signature-upload-preview" id="signature-upload-preview" alt="Preview">
                    </label>
                </div>
                <div class="signature-pane" id="signature-pane-draw">
                    <div class="signature-canvas-wrap">
                        <canvas id="signature-pad" width="428" height="200"></canvas>
                    </div>
                    <div class="signature-actions">
                        <button type="button" class="profile-signature-btn btn-clear" id="signature-clear-btn">Clear</button>
                    </div>
                </div>
            </div>
            <div class="signature-modal-footer">
                <button type="button" class="offices-btn offices-btn-secondary" id="signature-modal-cancel">Cancel</button>
                <button type="button" class="offices-btn" id="signature-save-btn">Save Signature</button>
            </div>
        </div>
    </div>
    <form method="post" id="signature-update-form" action="offices-department.php" style="display:none;">
        <input type="hidden" name="action" value="update_signature">
        <input type="hidden" name="signature" id="signature-hidden-input">
    </form>
    <form method="post" id="profile-photo-form" action="offices-department.php" style="display:none;">
        <input type="hidden" name="action" value="update_photo">
        <input type="hidden" name="photo" id="profile-photo-hidden-input">
    </form>
    <div class="delete-confirm-modal-overlay" id="delete-confirm-modal-overlay">
        <div class="delete-confirm-modal">
            <div class="delete-confirm-modal-header">
                <div class="delete-confirm-modal-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                </div>
                <h3 class="delete-confirm-modal-title">Delete Department</h3>
                <p class="delete-confirm-modal-message" id="delete-confirm-message">Are you sure you want to delete this department? This action cannot be undone.</p>
            </div>
            <div class="delete-confirm-modal-footer">
                <button type="button" class="delete-confirm-btn-cancel" id="delete-confirm-cancel">Cancel</button>
                <button type="button" class="delete-confirm-btn-delete" id="delete-confirm-delete">Delete</button>
            </div>
        </div>
    </div>
    <form method="post" id="delete-office-form" action="offices-department.php" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="office_id" id="delete-office-id">
    </form>
<?php $sidebarJsVer = @filemtime(__DIR__ . '/assets/js/sidebar_super_admin.js') ?: time(); ?>
<script src="assets/js/sidebar_super_admin.js?v=<?= (int)$sidebarJsVer ?>"></script>
<?php $notifJsVer = @filemtime(__DIR__ . '/assets/js/super_admin_notifications.js') ?: time(); ?>
<script src="assets/js/super_admin_notifications.js?v=<?= (int)$notifJsVer ?>"></script>
<script>
(function(){
    var profileOverlay = document.getElementById('profile-modal-overlay');
    var profileCloseBtn = document.getElementById('profile-modal-close');
    var settingsOverlay = document.getElementById('settings-modal-overlay');
    var settingsCloseBtn = document.getElementById('settings-modal-close');
    function closeProfileModal(){ if (profileOverlay) { profileOverlay.classList.remove('profile-modal-open'); profileOverlay.setAttribute('aria-hidden', 'true'); } }
    function closeSettingsModal(){ if (settingsOverlay) { settingsOverlay.classList.remove('settings-modal-open'); settingsOverlay.setAttribute('aria-hidden', 'true'); } }
    if (profileCloseBtn) profileCloseBtn.addEventListener('click', closeProfileModal);
    if (settingsCloseBtn) settingsCloseBtn.addEventListener('click', closeSettingsModal);
    if (profileOverlay) profileOverlay.addEventListener('click', function(e){ if (e.target === profileOverlay) closeProfileModal(); });
    if (settingsOverlay) settingsOverlay.addEventListener('click', function(e){ if (e.target === settingsOverlay) closeSettingsModal(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { closeProfileModal(); closeSettingsModal(); } });
    if (window.location.search.indexOf('open=profile') !== -1 && profileOverlay) profileOverlay.classList.add('profile-modal-open');
})();
(function(){
    var settingsOverlay = document.getElementById('settings-modal-overlay');
    var sigOverlay = document.getElementById('signature-modal-overlay');
    var openSigBtn = document.getElementById('profile-update-signature-btn');
    var closeSigBtn = document.getElementById('signature-modal-close');
    var cancelSigBtn = document.getElementById('signature-modal-cancel');
    var tabs = document.querySelectorAll('.signature-tab');
    var paneUpload = document.getElementById('signature-pane-upload');
    var paneDraw = document.getElementById('signature-pane-draw');
    var fileInput = document.getElementById('signature-file-input');
    var uploadZone = document.getElementById('signature-upload-zone');
    var uploadPreview = document.getElementById('signature-upload-preview');
    var canvas = document.getElementById('signature-pad');
    var clearBtn = document.getElementById('signature-clear-btn');
    var saveBtn = document.getElementById('signature-save-btn');
    var form = document.getElementById('signature-update-form');
    var hiddenInput = document.getElementById('signature-hidden-input');
    var currentSignatureData = '';
    function openSignatureModal() {
        if (settingsOverlay) settingsOverlay.classList.remove('settings-modal-open');
        currentSignatureData = '';
        if (uploadPreview) { uploadPreview.src = ''; uploadPreview.classList.remove('show'); }
        if (fileInput) fileInput.value = '';
        if (canvas) { var ctx = canvas.getContext('2d'); if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height); }
        tabs.forEach(function(t){ t.classList.toggle('active', t.getAttribute('data-pane') === 'upload'); });
        if (paneUpload) paneUpload.classList.add('active');
        if (paneDraw) paneDraw.classList.remove('active');
        if (sigOverlay) sigOverlay.classList.add('signature-modal-open');
    }
    function closeSignatureModal() {
        if (sigOverlay) sigOverlay.classList.remove('signature-modal-open');
        if (settingsOverlay) settingsOverlay.classList.add('settings-modal-open');
    }
    if (openSigBtn) openSigBtn.addEventListener('click', openSignatureModal);
    if (closeSigBtn) closeSigBtn.addEventListener('click', closeSignatureModal);
    if (cancelSigBtn) cancelSigBtn.addEventListener('click', closeSignatureModal);
    if (sigOverlay) sigOverlay.addEventListener('click', function(e){ if (e.target === sigOverlay) closeSignatureModal(); });
    tabs.forEach(function(tab){
        tab.addEventListener('click', function(){
            var pane = tab.getAttribute('data-pane');
            tabs.forEach(function(t){ t.classList.toggle('active', t.getAttribute('data-pane') === pane); });
            if (pane === 'upload') { paneUpload.classList.add('active'); paneDraw.classList.remove('active'); }
            else { paneUpload.classList.remove('active'); paneDraw.classList.add('active'); }
        });
    });
    function setSignatureFromDataUrl(dataUrl) { currentSignatureData = dataUrl || ''; }
    if (uploadZone && fileInput) {
        uploadZone.addEventListener('click', function(e){ if (e.target !== fileInput) fileInput.click(); });
        uploadZone.addEventListener('dragover', function(e){ e.preventDefault(); uploadZone.classList.add('dragover'); });
        uploadZone.addEventListener('dragleave', function(){ uploadZone.classList.remove('dragover'); });
        uploadZone.addEventListener('drop', function(e){ e.preventDefault(); uploadZone.classList.remove('dragover'); if (e.dataTransfer.files.length && e.dataTransfer.files[0].type.indexOf('image/') === 0) { var r = new FileReader(); r.onload = function(){ setSignatureFromDataUrl(r.result); uploadPreview.src = r.result; uploadPreview.classList.add('show'); }; r.readAsDataURL(e.dataTransfer.files[0]); } });
        fileInput.addEventListener('change', function(){ if (fileInput.files.length) { var r = new FileReader(); r.onload = function(){ setSignatureFromDataUrl(r.result); uploadPreview.src = r.result; uploadPreview.classList.add('show'); }; r.readAsDataURL(fileInput.files[0]); } });
    }
    if (canvas) {
        var ctx = canvas.getContext('2d');
        var drawing = false;
        function getPos(e){ var rect = canvas.getBoundingClientRect(); var scaleX = canvas.width/rect.width, scaleY = canvas.height/rect.height; var x = e.touches ? e.touches[0].clientX : e.clientX; var y = e.touches ? e.touches[0].clientY : e.clientY; return { x: (x - rect.left)*scaleX, y: (y - rect.top)*scaleY }; }
        function start(e){ e.preventDefault(); drawing = true; var p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
        function move(e){ e.preventDefault(); if (!drawing) return; var p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
        function end(e){ e.preventDefault(); drawing = false; setSignatureFromDataUrl(canvas.toDataURL('image/png')); }
        ctx.strokeStyle = '#1e293b'; ctx.lineWidth = 2; ctx.lineCap = 'round';
        canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); canvas.addEventListener('mouseup', end); canvas.addEventListener('mouseleave', end);
        canvas.addEventListener('touchstart', start, { passive: false }); canvas.addEventListener('touchmove', move, { passive: false }); canvas.addEventListener('touchend', end, { passive: false });
    }
    if (clearBtn && canvas) clearBtn.addEventListener('click', function(){ canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height); setSignatureFromDataUrl(''); });
    if (saveBtn && form && hiddenInput) saveBtn.addEventListener('click', function(){
        var data = (paneDraw && paneDraw.classList.contains('active') && canvas) ? canvas.toDataURL('image/png') : currentSignatureData;
        if (!data) { alert('Please upload an image or draw your signature.'); return; }
        hiddenInput.value = data;
        form.submit();
    });
})();
(function(){
    var fileInput = document.getElementById('profile-photo-file-input');
    var form = document.getElementById('profile-photo-form');
    var hiddenInput = document.getElementById('profile-photo-hidden-input');
    if (fileInput && form && hiddenInput) fileInput.addEventListener('change', function(){
        if (!fileInput.files || !fileInput.files.length) return;
        if (fileInput.files[0].type.indexOf('image/') !== 0) { alert('Please choose an image file.'); return; }
        var r = new FileReader();
        r.onload = function(){ hiddenInput.value = r.result; form.submit(); };
        r.readAsDataURL(fileInput.files[0]);
    });
})();
(function(){
    var dashboardTitleWrap = document.querySelector('.dashboard-title-wrap');
    var sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
    if (dashboardTitleWrap && sidebarToggleBtn && !dashboardTitleWrap.contains(sidebarToggleBtn)) {
        sidebarToggleBtn.classList.add('in-header');
        dashboardTitleWrap.insertBefore(sidebarToggleBtn, dashboardTitleWrap.firstChild);
    }
})();
</script>
        </div>
    </div>
</body>
</html>
