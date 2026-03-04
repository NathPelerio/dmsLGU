<?php
session_start();

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../Super Admin Side/_activity_logger.php';

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'Admin';
$userDepartment = $_SESSION['user_department'] ?? 'Not Assigned';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$sidebar_active = 'offices';

/**
 * Check if the current user has access to the admin side.
 * @return bool True if user is logged in and has an allowed role (admin, staff, department head)
 */
function isAdminSide() {
    $role = $_SESSION['user_role'] ?? '';
    $allowedRoles = ['admin', 'staff', 'departmenthead', 'department_head', 'dept_head'];
    return isset($_SESSION['user_id']) && in_array($role, $allowedRoles);
}

/**
 * Fetch all offices from the database (full documents for list/cards).
 * @return array List of offices with id, office_code, office_name, office_head, office_head_id, description, created_at
 */
function getOffices($config) {
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->query('SELECT * FROM offices ORDER BY created_at ASC');
        $offices = [];
        foreach ($stmt as $d) {
            $offices[] = [
                'id' => (string) ($d['id'] ?? ''),
                'office_code' => $d['office_code'] ?? '',
                'office_name' => $d['office_name'] ?? '',
                'office_head' => $d['office_head'] ?? '',
                'office_head_id' => $d['office_head_id'] ?? '',
                'description' => $d['description'] ?? '',
                'created_at' => $d['created_at'] ?? null,
            ];
        }
        return $offices;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Fetch all user accounts for Assign Head dropdown.
 * @return array List of users with _id (string), username, name, email
 */
function getUsers($config) {
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->query('SELECT * FROM users ORDER BY username ASC');
        $rows = [];
        foreach ($stmt as $arr) {
            $arr['_id'] = (string)($arr['id'] ?? '');
            $rows[] = $arr;
        }
        return $rows;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Update an existing office in the database.
 * @param array $config Database config
 * @param string $id Office MongoDB _id
 * @param string $officeCode Office code
 * @param string $officeName Office name
 * @param string $officeHead Department head display name
 * @param string $description Description
 * @return array ['success' => bool, 'error' => string|null]
 */
function updateOffice($config, $id, $officeCode, $officeName, $officeHead = '', $description = '') {
    $officeCode = trim($officeCode);
    $officeName = trim($officeName);
    $officeHead = trim($officeHead);
    $description = trim($description);
    if ($id === '' || $officeCode === '' || $officeName === '') {
        return ['success' => false, 'error' => 'Department ID, code and name are required.'];
    }
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare(
            'UPDATE offices
             SET office_code = :office_code, office_name = :office_name, office_head = :office_head,
                 description = :description, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':office_code' => $officeCode,
            ':office_name' => $officeName,
            ':office_head' => $officeHead,
            ':description' => $description,
            ':updated_at' => dbNowUtcString(),
            ':id' => $id,
        ]);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Add a new office to the database.
 * @param array $config Database config
 * @param string $officeCode Office code
 * @param string $officeName Office name
 * @param string $officeHead Department head (optional)
 * @param string $description Description (optional)
 * @return array ['success' => bool, 'error' => string|null]
 */
function addOffice($config, $officeCode, $officeName, $officeHead = '', $description = '') {
    $officeCode = trim($officeCode);
    $officeName = trim($officeName);
    $officeHead = trim($officeHead);
    $description = trim($description);
    if ($officeCode === '' || $officeName === '') {
        return ['success' => false, 'error' => 'Department code and name are required.'];
    }
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare(
            'INSERT INTO offices (id, office_code, office_name, office_head, office_head_id, description, created_at)
             VALUES (:id, :office_code, :office_name, :office_head, :office_head_id, :description, :created_at)'
        );
        $stmt->execute([
            ':id' => dbGenerateId24(),
            ':office_code' => $officeCode,
            ':office_name' => $officeName,
            ':office_head' => $officeHead,
            ':office_head_id' => '',
            ':description' => $description,
            ':created_at' => dbNowUtcString(),
        ]);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Assign or update department head by user account ID.
 * @param array $config Database config
 * @param string $id Office MongoDB _id
 * @param string $officeHeadUserId User _id (empty to clear)
 * @return array ['success' => bool, 'error' => string|null]
 */
function assignHead($config, $id, $officeHeadUserId) {
    if ($id === '') {
        return ['success' => false, 'error' => 'Invalid department ID.'];
    }
    $officeHeadUserId = trim($officeHeadUserId);
    $officeHead = '';
    $officeHeadId = null;
    if ($officeHeadUserId !== '') {
        try {
            $pdo = dbPdo($config);
            $stmt = $pdo->prepare('SELECT username, name, email FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $officeHeadUserId]);
            $u = $stmt->fetch();
            if ($u) {
                $officeHead = trim($u['username'] ?? $u['name'] ?? $u['email'] ?? '');
                $officeHeadId = $officeHeadUserId;
            }
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Invalid user selected.'];
        }
    }
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare(
            'UPDATE offices
             SET office_head = :office_head, office_head_id = :office_head_id, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':office_head' => $officeHead,
            ':office_head_id' => $officeHeadId !== null ? $officeHeadId : '',
            ':updated_at' => dbNowUtcString(),
            ':id' => $id,
        ]);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Delete an office by ID.
 * @param array $config Database config
 * @param string $id Office MongoDB _id
 * @return array ['success' => bool, 'error' => string|null]
 */
function deleteOffice($config, $id) {
    if ($id === '') {
        return ['success' => false, 'error' => 'Invalid office ID.'];
    }
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare('DELETE FROM offices WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

if (!isAdminSide()) {
    header('Location: ../index.php');
    exit;
}

$addError = '';
$addSuccess = false;
$editError = '';
$editSuccess = false;
$assignHeadError = '';
$assignHeadSuccess = false;
$deleteError = '';
$deleteSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_office'])) {
    $result = addOffice(
        $config,
        $_POST['office_code'] ?? '',
        $_POST['office_name'] ?? '',
        $_POST['office_head'] ?? '',
        $_POST['description'] ?? ''
    );
    if ($result['success']) {
        activityLog($config, 'office_add', [
            'module' => 'admin_offices',
            'office_code' => trim((string)($_POST['office_code'] ?? '')),
            'office_name' => trim((string)($_POST['office_name'] ?? '')),
        ]);
        header('Location: admin_offices.php?added=1');
        exit;
    }
    $addError = $result['error'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_office'])) {
    $result = updateOffice(
        $config,
        $_POST['office_id'] ?? '',
        $_POST['office_code'] ?? '',
        $_POST['office_name'] ?? '',
        $_POST['office_head'] ?? '',
        $_POST['description'] ?? ''
    );
    if ($result['success']) {
        activityLog($config, 'office_update', [
            'module' => 'admin_offices',
            'office_id' => trim((string)($_POST['office_id'] ?? '')),
            'office_code' => trim((string)($_POST['office_code'] ?? '')),
            'office_name' => trim((string)($_POST['office_name'] ?? '')),
        ]);
        header('Location: admin_offices.php?edited=1');
        exit;
    }
    $editError = $result['error'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_head'])) {
    $result = assignHead($config, $_POST['office_id'] ?? '', $_POST['office_head_id'] ?? '');
    if ($result['success']) {
        activityLog($config, 'office_assign_head', [
            'module' => 'admin_offices',
            'office_id' => trim((string)($_POST['office_id'] ?? '')),
            'office_head_id' => trim((string)($_POST['office_head_id'] ?? '')),
        ]);
        header('Location: admin_offices.php?head_assigned=1');
        exit;
    }
    $_SESSION['assign_head_error'] = $result['error'];
    header('Location: admin_offices.php?assign_error=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_office'])) {
    $result = deleteOffice($config, $_POST['office_id'] ?? '');
    if ($result['success']) {
        activityLog($config, 'office_delete', [
            'module' => 'admin_offices',
            'office_id' => trim((string)($_POST['office_id'] ?? '')),
        ]);
        header('Location: admin_offices.php?deleted=1');
        exit;
    }
    $_SESSION['delete_error'] = $result['error'];
    header('Location: admin_offices.php?delete_error=1');
    exit;
}

$addSuccess = isset($_GET['added']);
$editSuccess = isset($_GET['edited']);
$assignHeadSuccess = isset($_GET['head_assigned']);
$deleteSuccess = isset($_GET['deleted']);
if (isset($_GET['assign_error']) && isset($_SESSION['assign_head_error'])) {
    $assignHeadError = $_SESSION['assign_head_error'];
    unset($_SESSION['assign_head_error']);
}
if (isset($_GET['delete_error']) && isset($_SESSION['delete_error'])) {
    $deleteError = $_SESSION['delete_error'];
    unset($_SESSION['delete_error']);
}
$offices = getOffices($config);
$usersList = getUsers($config);

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $offices = array_values(array_filter($offices, function ($o) use ($search) {
        $q = mb_strtolower($search);
        $code = mb_strtolower($o['office_code'] ?? '');
        $name = mb_strtolower($o['office_name'] ?? '');
        return (strpos($code, $q) !== false || strpos($name, $q) !== false);
    }));
}

$limit = 10;
$totalOffices = count($offices);
$totalPages = max(1, (int) ceil($totalOffices / $limit));
$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $limit;
$officesPage = array_slice($offices, $offset, $limit);

$filterQuery = $search !== '' ? 'search=' . rawurlencode($search) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Departments</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin-dashboard.css">
    <link rel="stylesheet" href="admin-offices.css">
    <link rel="stylesheet" href="profile_modal_admin.css">
    <style>
    body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
    .dashboard-container { display: flex; min-height: 100vh; border-top: 3px solid #D4AF37; }
    .sidebar { width: 260px; height: 100vh; position: fixed; left: 0; top: 0; z-index: 100; background: #1A202C; color: #fff; display: flex; flex-direction: column; box-shadow: 2px 0 12px rgba(0,0,0,0.08); border-right: 1px solid rgba(255, 255, 255, 0.06); }
    .sidebar-header { padding: 1.25rem 1rem; border-bottom: 1px solid rgba(255, 255, 255, 0.06); display: flex; flex-direction: row; align-items: center; gap: 0.75rem; text-align: left; }
    .sidebar-logo { flex-shrink: 0; width: 44px; height: 44px; background: #63B3ED; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
    .sidebar-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; }
    .sidebar-header .sidebar-title { text-align: left; }
    .sidebar-header .sidebar-title h2 { margin: 0; font-size: 1.1rem; font-weight: 700; color: #fff; line-height: 1.3; text-transform: none; letter-spacing: 0.02em; }
    .sidebar-header .sidebar-title h2 span { font-size: 0.75rem; font-weight: 500; display: block; color: #A0AEC0; margin-top: 2px; letter-spacing: 0.02em; }
    .sidebar-nav { flex: 1; padding: 1rem 0.75rem; overflow-y: auto; }
    .sidebar-nav .nav-section-title { font-size: 0.7rem; font-weight: 600; letter-spacing: 0.08em; color: #718096; padding: 0.75rem 0.75rem 0.35rem; text-transform: uppercase; }
    .sidebar-nav ul { list-style: none; padding: 0; margin: 0; }
    .sidebar-nav li { margin: 0.2rem 0; }
    .sidebar-nav a { display: flex; align-items: center; gap: 10px; padding: 0.6rem 0.75rem; color: #fff; text-decoration: none; font-size: 0.95rem; font-weight: 500; border-radius: 8px; transition: background 0.15s ease, color 0.15s ease; letter-spacing: 0.02em; }
    .sidebar-nav a .nav-icon { width: 22px; height: 22px; flex-shrink: 0; color: #A0AEC0; transition: color 0.15s ease; }
    .sidebar-nav a:hover { background: rgba(255, 255, 255, 0.06); color: #fff; }
    .sidebar-nav a:hover .nav-icon { color: #fff; }
    .sidebar-nav a.active { background: #3B82F6; color: #fff; }
    .sidebar-nav a.active .nav-icon { color: #fff; }
    .sidebar-user-wrap { position: relative; padding: 0 1rem 1.25rem 1rem; border-top: 1px solid rgba(255, 255, 255, 0.06); }
    .sidebar-user { padding: 0.75rem; border-radius: 8px; display: flex; align-items: center; gap: 0.75rem; cursor: pointer; transition: background 0.2s ease, transform 0.2s ease; }
    .sidebar-user:hover { background: rgba(255, 255, 255, 0.08); }
    .sidebar-user:active { transform: scale(0.98); }
    .sidebar-user-avatar { width: 40px; height: 40px; border-radius: 50%; background: #63B3ED; color: #fff; font-size: 0.9rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .sidebar-user-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
    .sidebar-user-info { min-width: 0; }
    .sidebar-user-name { font-size: 0.95rem; font-weight: 600; color: #fff; margin: 0; }
    .sidebar-user-role { font-size: 0.8rem; color: #A0AEC0; margin: 2px 0 0 0; }
    .account-dropdown { position: absolute; left: 1rem; right: 1rem; bottom: 0; transform: translateY(calc(-100% - 10px)); background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); padding: 6px 0; min-width: 160px; z-index: 1100; display: none; overflow: hidden; }
    .account-dropdown.open { display: block; animation: account-dropdown-in 0.2s ease; }
    @keyframes account-dropdown-in { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(calc(-100% - 10px)); } }
    .account-dropdown-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 14px; border: none; background: none; color: #1e293b; font-size: 0.9rem; cursor: pointer; text-align: left; text-decoration: none; font-family: inherit; transition: background 0.15s ease, color 0.15s ease; box-sizing: border-box; }
    .account-dropdown-item:hover { background: #f1f5f9; }
    .account-dropdown-item.account-dropdown-profile:hover { background: rgba(59, 130, 246, 0.1); color: #3B82F6; }
    .account-dropdown-item.account-dropdown-profile:hover svg { color: #3B82F6; }
    .account-dropdown-item.account-dropdown-signout:hover { background: #dc2626; color: #fff; }
    .account-dropdown-item.account-dropdown-signout:hover svg { color: #fff; }
    .account-dropdown-item svg { width: 18px; height: 18px; flex-shrink: 0; color: #64748b; transition: color 0.15s ease; }
    .account-dropdown-item:hover svg { color: #3B82F6; }
    .main-content { flex: 1; margin-left: 260px; padding: 0; background: #f1f5f9; overflow-x: auto; display: flex; flex-direction: column; }
    .content-header { background: #fff; padding: 1.5rem 2.2rem; border-bottom: 1px solid #e2e8f0; }
    .dashboard-header { display: flex; justify-content: space-between; align-items: center; }
    .header-controls { position: relative; }
    .icon-btn { background: #f1f5f9; border: none; color: #475569; padding: 0; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; position: relative; width: 40px; height: 40px; }
    .icon-btn:hover { background: #e2e8f0; color: #1e293b; }
    .icon-btn svg { width: 22px; height: 22px; }
    .notif-badge { position: absolute; top: 8px; right: 8px; background: #ef4444; color: white; font-size: 12px; padding: 4px 8px; border-radius: 999px; line-height: 1; }
    .notif-dropdown { position: absolute; right: 0; top: 48px; background: white; color: #0b1720; min-width: 180px; border-radius: 6px; box-shadow: 0 8px 20px rgba(2,6,23,0.12); border: 1px solid #e6eef8; display: none; z-index: 1200; padding: 8px 0; }
    .notif-item { padding: 10px 12px; font-size: 0.95rem; color: #475569; }
    .content-body { padding: 2rem 2.2rem; }
    .dept-page-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 0; }
    .dept-page-title { margin: 0; font-size: 1.75rem; font-weight: 700; color: #1e293b; }
    .dept-page-subtitle { margin: 0.25rem 0 0 0; font-size: 0.95rem; color: #64748b; }
    .dept-card-head-section { padding-top: 1rem; border-top: 1px solid #e5e7eb; }
    .dept-card-head-label { font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 0.25rem 0; }
    .dept-card-head-value { font-size: 0.9rem; font-weight: 500; color: #0f172a; margin: 0; }
    .dept-card-head-value.not-assigned { color: #94a3b8; font-weight: 500; }
    .dept-card-created-section { margin-top: auto; padding-top: 0.75rem; border-top: 1px solid #e5e7eb; }
    .dept-card-created { font-size: 0.75rem; color: #94a3b8; margin: 0; font-family: ui-monospace, 'JetBrains Mono', monospace; }
    .dept-toast { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 1500; display: flex; align-items: center; gap: 12px; padding: 0.875rem 1rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(0,0,0,0.15); max-width: 360px; animation: dept-toast-in 0.3s ease; }
    .dept-toast.success { background: #22c55e; color: #fff; }
    .dept-toast.error { background: #ef4444; color: #fff; }
    .dept-toast-icon { width: 24px; height: 24px; border-radius: 50%; background: rgba(255,255,255,0.3); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .dept-toast-icon svg { width: 14px; height: 14px; }
    .dept-toast-text { flex: 1; font-size: 0.95rem; font-weight: 500; }
    @keyframes dept-toast-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    /* Force dark text in Select Department modal - override body's white inheritance */
    #select-office-modal .doc-modal-body-list,
    #select-office-modal .doc-modal-body-list * {
        color: #1e293b !important;
    }
    #select-office-modal .offices-list-name {
        color: #475569 !important;
    }
    #select-office-modal .doc-modal-body-list {
        background: #ffffff !important;
    }
    #select-office-modal .offices-list-item {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
    }
    #select-office-modal .offices-modal-pagination .offices-page-btn {
        color: #fff !important;
    }
    #select-office-modal .offices-modal-pagination .offices-page-btn:disabled {
        color: #94a3b8 !important;
    }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="../img/logo.png" alt="LGU Solano">
                </div>
                <div class="sidebar-title">
                    <h2>LGU Solano<span>Document Management</span></h2>
                </div>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-section-title">Main Menu</div>
                <ul>
                    <li><a href="admin_dashboard.php" class="<?php echo $sidebar_active === 'dashboard' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard</a></li>
                    <li><a href="documents.php" class="<?php echo $sidebar_active === 'documents' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>Documents</a></li>
                    <li><a href="admin_offices.php" class="<?php echo $sidebar_active === 'offices' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01"/><path d="M9 12v.01"/><path d="M9 15v.01"/><path d="M9 18v.01"/></svg>Departments</a></li>
                    <li><a href="document_history.php" class="<?php echo $sidebar_active === 'document-history' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Document History</a></li>
                </ul>
                <div class="nav-section-title">Account</div>
                <ul>
                    <li><a href="admin_settings.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Settings</a></li>
                </ul>
            </nav>
            <div class="sidebar-user-wrap">
                <div class="sidebar-user" id="sidebar-account-btn" role="button" tabindex="0" aria-label="Account menu" aria-haspopup="true" aria-expanded="false">
                    <div class="sidebar-user-avatar"><?php if (!empty($_SESSION['user_photo'])): ?><img src="<?php echo htmlspecialchars($_SESSION['user_photo']); ?>" alt=""><?php else: ?><?php echo htmlspecialchars($userInitial); ?><?php endif; ?></div>
                    <div class="sidebar-user-info">
                        <p class="sidebar-user-name"><?php echo htmlspecialchars($userName); ?></p>
                        <p class="sidebar-user-role"><?php echo htmlspecialchars($userRole); ?></p>
                    </div>
                </div>
                <div class="account-dropdown" id="account-dropdown" role="menu" aria-label="Account menu">
                    <button type="button" class="account-dropdown-item account-dropdown-profile" id="account-dropdown-profile" role="menuitem"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Profile</button>
                    <a href="../index.php?logout=1" class="account-dropdown-item account-dropdown-signout" role="menuitem"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Sign Out</a>
                </div>
            </div>
        </aside>

        <div class="main-content">
            <div class="content-header">
                <div class="dashboard-header">
                    <div class="dept-page-header" style="flex: 1; margin-bottom: 0;">
                        <div>
                            <h1 class="dept-page-title">Departments</h1>
                            <p class="dept-page-subtitle">Manage municipal departments and their heads</p>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <button type="button" class="dept-add-btn" id="open-add-office-modal">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                            Add Department
                        </button>
                        <div class="header-controls">
                            <button class="icon-btn" id="notif-btn" aria-label="Notifications" title="Notifications">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0 1 18 14.158V11a6 6 0 1 0-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                                <span class="notif-badge" id="notif-count" aria-hidden="true">3</span>
                            </button>
                            <div class="notif-dropdown" id="notif-dropdown" aria-hidden="true">
                                <div class="notif-item">No new notifications</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="content-body">
                <?php if ($addSuccess): ?>
                <div id="dept-toast" class="dept-toast success" role="alert">
                    <div class="dept-toast-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <span class="dept-toast-text">Department added successfully.</span>
                </div>
                <?php endif; ?>
                <?php if ($editSuccess): ?>
                <div id="dept-toast-edit" class="dept-toast success" role="alert">
                    <div class="dept-toast-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <span class="dept-toast-text">Department updated successfully.</span>
                </div>
                <?php endif; ?>
                <?php if ($assignHeadSuccess): ?>
                <div id="dept-toast-head" class="dept-toast success" role="alert">
                    <div class="dept-toast-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <span class="dept-toast-text">Department head assigned successfully.</span>
                </div>
                <?php endif; ?>
                <?php if ($deleteSuccess): ?>
                <div id="dept-toast-delete" class="dept-toast success" role="alert">
                    <div class="dept-toast-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <span class="dept-toast-text">Department deleted successfully.</span>
                </div>
                <?php endif; ?>
                <?php if ($assignHeadError): ?>
                <div id="dept-toast-assign-error" class="dept-toast error" role="alert">
                    <div class="dept-toast-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    </div>
                    <span class="dept-toast-text"><?= htmlspecialchars($assignHeadError) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($deleteError): ?>
                <div id="dept-toast-delete-error" class="dept-toast error" role="alert">
                    <div class="dept-toast-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    </div>
                    <span class="dept-toast-text"><?= htmlspecialchars($deleteError) ?></span>
                </div>
                <?php endif; ?>

                <form method="get" action="admin_offices.php" id="offices-filter-form" class="dept-search-row">
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
                    <?php if (empty($officesPage)): ?>
                    <p class="dept-empty" style="grid-column: 1 / -1;"><?= $search !== '' ? 'No departments match your search.' : 'No departments yet. Click &ldquo;Add Department&rdquo; to create one.' ?></p>
                    <?php else: ?>
                    <?php foreach ($officesPage as $i => $office):
                        $createdAt = $office['created_at'] ?? null;
                        $createdTs = dbToTimestamp($createdAt);
                        $createdAt = $createdTs !== null ? date('M j, Y', $createdTs) : '—';
                        $head = trim($office['office_head'] ?? '');
                        $desc = trim($office['description'] ?? '');
                        $descDisplay = $desc !== '' ? $desc : 'Municipal department';
                    ?>
                    <article class="dept-card" data-office-id="<?= htmlspecialchars($office['id']) ?>">
                        <div class="dept-card-header">
                            <div class="dept-card-header-left">
                                <div class="dept-card-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>
                                </div>
                                <div class="dept-card-title-wrap">
                                    <h3 class="dept-card-name"><?= htmlspecialchars($office['office_name']) ?></h3>
                                    <p class="dept-card-code"><?= htmlspecialchars($office['office_code']) ?></p>
                                </div>
                            </div>
                            <div class="dept-card-menu">
                                <button type="button" class="dept-card-menu-btn" aria-label="Options" onclick="toggleCardMenu(this)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                                </button>
                                <div class="dept-card-dropdown" role="menu">
                                    <button type="button" class="dept-dropdown-item dept-dropdown-edit" role="menuitem" data-id="<?= htmlspecialchars($office['id']) ?>" data-code="<?= htmlspecialchars($office['office_code']) ?>" data-name="<?= htmlspecialchars($office['office_name']) ?>" data-head="<?= htmlspecialchars($office['office_head'] ?? '') ?>" data-desc="<?= htmlspecialchars($desc) ?>" onclick="openEditOfficeModal(this); closeCardMenu(this);">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                                        Edit Department
                                    </button>
                                    <button type="button" class="dept-dropdown-item" role="menuitem" data-id="<?= htmlspecialchars($office['id']) ?>" data-name="<?= htmlspecialchars($office['office_name']) ?>" data-head-id="<?= htmlspecialchars($office['office_head_id'] ?? '') ?>" onclick="openAssignHeadModal(this); closeCardMenu(this);">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                        Assign Head
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="dept-card-content">
                            <p class="dept-card-desc"><?= htmlspecialchars($descDisplay) ?></p>
                            <div class="dept-card-head-section">
                                <p class="dept-card-head-label">Department Head</p>
                                <p class="dept-card-head-value <?= $head === '' ? 'not-assigned' : '' ?>"><?= $head !== '' ? htmlspecialchars($head) : 'Not assigned' ?></p>
                            </div>
                            <div class="dept-card-created-section">
                                <p class="dept-card-created">Created: <?= $createdAt ?></p>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="offices-pagination" style="display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 20px; padding: 12px 0;">
                    <?php $pagePrefix = $filterQuery ? $filterQuery . '&' : ''; ?>
                    <?php if ($page > 1): ?>
                    <a href="?<?= $pagePrefix ?>page=<?= $page - 1 ?>" class="offices-page-btn">Previous</a>
                    <?php else: ?>
                    <span class="offices-page-btn disabled" aria-disabled="true">Previous</span>
                    <?php endif; ?>
                    <span class="offices-page-info">Page <?= $page ?> of <?= $totalPages ?><?= $search !== '' ? ' (filtered)' : '' ?></span>
                    <?php if ($page < $totalPages): ?>
                    <a href="?<?= $pagePrefix ?>page=<?= $page + 1 ?>" class="offices-page-btn">Next</a>
                    <?php else: ?>
                    <span class="offices-page-btn disabled" aria-disabled="true">Next</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="doc-modal" id="select-office-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-select-office aria-label="Close"></button>
        <div class="doc-modal-dialog doc-modal-dialog-list" role="dialog" aria-modal="true" aria-labelledby="select-office-title">
            <div class="doc-modal-header">
                <h2 id="select-office-title">Select Department to Edit</h2>
                <button type="button" class="doc-modal-close" data-close-select-office aria-label="Close">&times;</button>
            </div>
            <div class="doc-modal-body-list" style="color:#1e293b;">
                <?php if (empty($offices)): ?>
                <p class="offices-list-empty" style="color:#475569;">No departments to edit.</p>
                <?php else: ?>
                <ul class="offices-list" id="offices-list" style="color:#1e293b;">
                    <?php 
                    $modalLimit = 10;
                    $modalTotalPages = max(1, (int) ceil(count($offices) / $modalLimit));
                    foreach ($offices as $idx => $office): 
                        $modalPage = (int) floor($idx / $modalLimit) + 1;
                    ?>
                    <li class="offices-list-item" data-office-id="<?= htmlspecialchars($office['id']) ?>" data-office-code="<?= htmlspecialchars($office['office_code']) ?>" data-office-name="<?= htmlspecialchars($office['office_name']) ?>" data-office-head="<?= htmlspecialchars($office['office_head'] ?? '') ?>" data-office-desc="<?= htmlspecialchars($office['description'] ?? '') ?>" data-modal-page="<?= $modalPage ?>" style="color:#1e293b;<?= $modalPage > 1 ? ' display:none;' : '' ?>">
                        <span class="offices-list-code" style="color:#1e293b;"><?= htmlspecialchars($office['office_code']) ?></span>
                        <span class="offices-list-name" style="color:#475569;"><?= htmlspecialchars($office['office_name']) ?></span>
                        <svg class="offices-list-arrow" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#64748b" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($modalTotalPages > 1): ?>
                <div class="offices-modal-pagination">
                    <button type="button" class="offices-page-btn" id="modal-prev-btn" aria-label="Previous page">Previous</button>
                    <span class="offices-page-info" id="modal-page-info">Page 1 of <?= $modalTotalPages ?></span>
                    <button type="button" class="offices-page-btn" id="modal-next-btn" aria-label="Next page">Next</button>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="doc-modal" id="edit-office-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-edit-office aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="edit-office-title">
            <div class="doc-modal-header">
                <h2 id="edit-office-title">Edit Department</h2>
                <button type="button" class="doc-modal-close" data-close-edit-office aria-label="Close">&times;</button>
            </div>
            <form id="edit-office-form" class="doc-modal-form" method="post" action="admin_offices.php">
                <input type="hidden" name="edit_office" value="1">
                <input type="hidden" name="office_id" id="edit-office-id" value="<?= isset($_POST['office_id']) ? htmlspecialchars($_POST['office_id']) : '' ?>">
                <div class="doc-form-field">
                    <label for="edit-office-code">Department Code</label>
                    <input type="text" id="edit-office-code" name="office_code" placeholder="Enter department code" required value="<?= isset($_POST['edit_office'], $_POST['office_code']) ? htmlspecialchars($_POST['office_code']) : '' ?>">
                </div>
                <div class="doc-form-field">
                    <label for="edit-office-name">Department Name</label>
                    <input type="text" id="edit-office-name" name="office_name" placeholder="Enter department name" required value="<?= isset($_POST['edit_office'], $_POST['office_name']) ? htmlspecialchars($_POST['office_name']) : '' ?>">
                </div>
                <div class="doc-form-field">
                    <label for="edit-office-desc">Description</label>
                    <textarea id="edit-office-desc" name="description" rows="3" placeholder="Brief description of the department..."><?= isset($_POST['edit_office'], $_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?></textarea>
                </div>
                <input type="hidden" name="office_head" id="edit-office-head" value="<?= isset($_POST['edit_office'], $_POST['office_head']) ? htmlspecialchars($_POST['office_head']) : '' ?>">
                <p class="doc-form-error" id="edit-office-form-error" <?= $editError ? '' : 'hidden' ?>><?= $editError ? htmlspecialchars($editError) : '' ?></p>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-edit-office>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Update Department</button>
                </div>
            </form>
        </div>
    </div>

    <div class="doc-modal" id="add-office-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-add-office aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="add-office-title">
            <div class="doc-modal-header">
                <h2 id="add-office-title">Add Department</h2>
                <button type="button" class="doc-modal-close" data-close-add-office aria-label="Close">&times;</button>
            </div>
            <form id="add-office-form" class="doc-modal-form" method="post" action="admin_offices.php">
                <input type="hidden" name="add_office" value="1">
                <div class="doc-form-field">
                    <label for="office-code">Department Code</label>
                    <input type="text" id="office-code" name="office_code" placeholder="Enter department code" required value="<?= isset($_POST['office_code']) ? htmlspecialchars($_POST['office_code']) : '' ?>">
                </div>
                <div class="doc-form-field">
                    <label for="office-name">Department Name</label>
                    <input type="text" id="office-name" name="office_name" placeholder="Enter department name" required value="<?= isset($_POST['office_name']) ? htmlspecialchars($_POST['office_name']) : '' ?>">
                </div>
                <div class="doc-form-field">
                    <label for="office-description">Description</label>
                    <textarea id="office-description" name="description" rows="3" placeholder="Brief description of the department..."><?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?></textarea>
                </div>
                <p class="doc-form-error" id="office-form-error" <?= $addError ? '' : 'hidden' ?>><?= $addError ? htmlspecialchars($addError) : '' ?></p>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-add-office>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Save Department</button>
                </div>
            </form>
        </div>
    </div>

    <div class="doc-modal" id="assign-head-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-assign-head aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="assign-head-title">
            <div class="doc-modal-header">
                <h2 id="assign-head-title">Assign Head</h2>
                <button type="button" class="doc-modal-close" data-close-assign-head aria-label="Close">&times;</button>
            </div>
            <form id="assign-head-form" class="doc-modal-form" method="post" action="admin_offices.php">
                <input type="hidden" name="assign_head" value="1">
                <input type="hidden" name="office_id" id="assign-office-id" value="">
                <div class="doc-form-field">
                    <label>Department</label>
                    <input type="text" id="assign-office-name" readonly style="background:#f8fafc; color:#64748b;">
                </div>
                <div class="doc-form-field">
                    <label for="assign-office-head">Department Head</label>
                    <select name="office_head_id" id="assign-office-head" class="doc-form-select">
                        <option value="">— Select user —</option>
                        <?php foreach ($usersList as $u):
                            $username = trim($u['username'] ?? '');
                            $label = $username !== '' ? $username : (trim($u['name'] ?? '') ?: trim($u['email'] ?? ''));
                            if ($label === '') $label = (string)($u['_id'] ?? '');
                        ?>
                        <option value="<?= htmlspecialchars($u['_id']) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-assign-head>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Save</button>
                </div>
            </form>
        </div>
    </div>

    <div class="delete-confirm-overlay" id="delete-confirm-modal-overlay" hidden>
        <div class="delete-confirm-modal">
            <div class="delete-confirm-header">
                <div class="delete-confirm-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                </div>
                <h3 class="delete-confirm-title">Delete Department</h3>
                <p class="delete-confirm-message" id="delete-confirm-message">Are you sure you want to delete this department? This action cannot be undone.</p>
            </div>
            <div class="delete-confirm-footer">
                <button type="button" class="doc-btn doc-btn-cancel" id="delete-confirm-cancel">Cancel</button>
                <button type="button" class="doc-btn doc-btn-danger" id="delete-confirm-delete">Delete</button>
            </div>
        </div>
    </div>
    <form method="post" id="delete-office-form" action="admin_offices.php" style="display:none;">
        <input type="hidden" name="delete_office" value="1">
        <input type="hidden" name="office_id" id="delete-office-id">
    </form>

    <?php include __DIR__ . '/_profile_modal_admin.php'; ?>
    <script src="sidebar_admin.js"></script>
    <script>
    (function() {
        var openAddOfficeBtn = document.getElementById('open-add-office-modal');
        var addOfficeModal = document.getElementById('add-office-modal');
        var addOfficeForm = document.getElementById('add-office-form');
        var officeFormError = document.getElementById('office-form-error');
        var successToast = document.getElementById('dept-toast') || document.getElementById('dept-toast-edit');

        function openAddOfficeModal() {
            if (!addOfficeModal) return;
            addOfficeModal.hidden = false;
            document.body.classList.add('modal-open');
        }

        function closeAddOfficeModal() {
            if (!addOfficeModal) return;
            addOfficeModal.hidden = true;
            document.body.classList.remove('modal-open');
            if (addOfficeForm) addOfficeForm.reset();
        }

        if (openAddOfficeBtn) openAddOfficeBtn.addEventListener('click', openAddOfficeModal);
        document.querySelectorAll('[data-close-add-office]').forEach(function(el) {
            el.addEventListener('click', closeAddOfficeModal);
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && addOfficeModal && !addOfficeModal.hidden) closeAddOfficeModal();
        });

        if (addOfficeModal && officeFormError && officeFormError.textContent.trim()) {
            openAddOfficeModal();
        }

        if (successToast) {
            setTimeout(function() { successToast.style.display = 'none'; }, 4000);
        }
        var editToastEl = document.getElementById('dept-toast-edit');
        if (editToastEl && editToastEl !== successToast) {
            setTimeout(function() { editToastEl.style.display = 'none'; }, 4000);
        }
        ['dept-toast-head', 'dept-toast-delete', 'dept-toast-assign-error', 'dept-toast-delete-error'].forEach(function(id) {
            var t = document.getElementById(id);
            if (t) setTimeout(function() { t.style.display = 'none'; }, 4000);
        });

        var openEditBtn = document.getElementById('open-edit-office-modal');
        var selectOfficeModal = document.getElementById('select-office-modal');
        var editOfficeModal = document.getElementById('edit-office-modal');
        var editOfficeForm = document.getElementById('edit-office-form');
        var editOfficeId = document.getElementById('edit-office-id');
        var editOfficeCode = document.getElementById('edit-office-code');
        var editOfficeName = document.getElementById('edit-office-name');
        var editOfficeFormError = document.getElementById('edit-office-form-error');
        var editToast = document.getElementById('offices-edit-toast');

        function toggleCardMenu(menuBtn) {
            var dropdown = menuBtn.closest('.dept-card-menu').querySelector('.dept-card-dropdown');
            var open = dropdown && dropdown.classList.contains('show');
            document.querySelectorAll('.dept-card-dropdown').forEach(function(el) { el.classList.remove('show'); });
            if (dropdown && !open) dropdown.classList.add('show');
        }

        function closeCardMenu(insideEl) {
            var dropdown = insideEl.closest('.dept-card-dropdown');
            if (dropdown) dropdown.classList.remove('show');
        }

        window.toggleCardMenu = toggleCardMenu;
        window.closeCardMenu = closeCardMenu;

        function openEditOfficeModal(btnOrId, code, name, clearError, headParam, descParam) {
            var id, officeCode, officeName, head, desc;
            if (typeof btnOrId === 'object' && btnOrId !== null) {
                var d = btnOrId.dataset || {};
                id = d.id || '';
                officeCode = d.code || '';
                officeName = d.name || '';
                head = d.head || '';
                desc = d.desc || '';
                clearError = true;
            } else {
                id = btnOrId || '';
                officeCode = code || '';
                officeName = name || '';
                head = headParam || '';
                desc = descParam || '';
            }
            if (editOfficeId) editOfficeId.value = id;
            if (editOfficeCode) editOfficeCode.value = officeCode;
            if (editOfficeName) editOfficeName.value = officeName;
            var editHead = document.getElementById('edit-office-head');
            var editDesc = document.getElementById('edit-office-desc');
            if (editHead) editHead.value = head || '';
            if (editDesc) editDesc.value = desc || '';
            if (editOfficeFormError && (clearError !== false)) { editOfficeFormError.hidden = true; editOfficeFormError.textContent = ''; }
            if (editOfficeModal) {
                editOfficeModal.hidden = false;
                document.body.classList.add('modal-open');
            }
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dept-card-menu')) {
                document.querySelectorAll('.dept-card-dropdown').forEach(function(el) { el.classList.remove('show'); });
            }
        });

        var assignHeadModal = document.getElementById('assign-head-modal');
        var assignOfficeId = document.getElementById('assign-office-id');
        var assignOfficeName = document.getElementById('assign-office-name');
        var assignOfficeHead = document.getElementById('assign-office-head');

        function openAssignHeadModal(btn) {
            var d = (btn && btn.dataset) || {};
            var id = d.id || '';
            var name = d.name || '';
            var headId = d.headId || '';
            if (assignOfficeId) assignOfficeId.value = id;
            if (assignOfficeName) assignOfficeName.value = name;
            if (assignOfficeHead) assignOfficeHead.value = headId || '';
            if (assignHeadModal) {
                assignHeadModal.hidden = false;
                document.body.classList.add('modal-open');
            }
        }

        function closeAssignHeadModal() {
            if (assignHeadModal) {
                assignHeadModal.hidden = true;
                document.body.classList.remove('modal-open');
            }
        }

        window.openEditOfficeModal = openEditOfficeModal;
        window.openAssignHeadModal = openAssignHeadModal;

        document.querySelectorAll('[data-close-assign-head]').forEach(function(el) {
            el.addEventListener('click', closeAssignHeadModal);
        });

        var deleteModalOverlay = document.getElementById('delete-confirm-modal-overlay');
        var deleteConfirmMessage = document.getElementById('delete-confirm-message');
        var deleteOfficeIdInput = document.getElementById('delete-office-id');
        var deleteForm = document.getElementById('delete-office-form');
        var deleteConfirmCancel = document.getElementById('delete-confirm-cancel');
        var deleteConfirmDelete = document.getElementById('delete-confirm-delete');

        function openDeleteConfirmModal(id, name) {
            if (!id || !deleteModalOverlay) return;
            if (deleteOfficeIdInput) deleteOfficeIdInput.value = id;
            if (deleteConfirmMessage) deleteConfirmMessage.textContent = 'Are you sure you want to delete "' + (name || 'this department') + '"? This action cannot be undone.';
            deleteModalOverlay.hidden = false;
            document.body.classList.add('modal-open');
        }

        function closeDeleteConfirmModal() {
            if (deleteModalOverlay) {
                deleteModalOverlay.hidden = true;
                document.body.classList.remove('modal-open');
            }
        }

        function confirmDeleteOffice(btn) {
            var d = (btn && btn.dataset) || {};
            var id = d.id || '';
            var name = d.name || 'this department';
            if (!id) return;
            openDeleteConfirmModal(id, name);
        }

        if (deleteConfirmCancel) deleteConfirmCancel.addEventListener('click', closeDeleteConfirmModal);
        if (deleteConfirmDelete && deleteForm) deleteConfirmDelete.addEventListener('click', function() { deleteForm.submit(); });
        if (deleteModalOverlay) deleteModalOverlay.addEventListener('click', function(e) {
            if (e.target === deleteModalOverlay) closeDeleteConfirmModal();
        });

        var modalCurrentPage = 1;
        var modalTotalPages = 1;
        var modalPrevBtn = document.getElementById('modal-prev-btn');
        var modalNextBtn = document.getElementById('modal-next-btn');
        var modalPageInfo = document.getElementById('modal-page-info');

        function updateModalPage() {
            var list = document.getElementById('offices-list');
            if (!list) return;
            var items = list.querySelectorAll('.offices-list-item:not(.offices-list-placeholder)');
            items.forEach(function(item) {
                item.style.display = parseInt(item.getAttribute('data-modal-page'), 10) === modalCurrentPage ? '' : 'none';
            });
            list.querySelectorAll('.offices-list-placeholder').forEach(function(p) { p.remove(); });
            var visibleCount = 0;
            items.forEach(function(item) {
                if (parseInt(item.getAttribute('data-modal-page'), 10) === modalCurrentPage) visibleCount++;
            });
            var placeholderCount = 10 - visibleCount;
            for (var i = 0; i < placeholderCount; i++) {
                var placeholder = document.createElement('li');
                placeholder.className = 'offices-list-item offices-list-placeholder';
                placeholder.style.pointerEvents = 'none';
                placeholder.innerHTML = '<span class="offices-list-code">&nbsp;</span><span class="offices-list-name"></span>';
                list.appendChild(placeholder);
            }
            if (modalPrevBtn) modalPrevBtn.disabled = modalCurrentPage <= 1;
            if (modalNextBtn) modalNextBtn.disabled = modalCurrentPage >= modalTotalPages;
            if (modalPageInfo) modalPageInfo.textContent = 'Page ' + modalCurrentPage + ' of ' + modalTotalPages;
        }

        function openSelectOfficeModal() {
            if (!selectOfficeModal) return;
            modalCurrentPage = 1;
            modalTotalPages = Math.max(1, Math.ceil(document.querySelectorAll('#offices-list .offices-list-item').length / 10));
            updateModalPage();
            selectOfficeModal.hidden = false;
            document.body.classList.add('modal-open');
        }

        function closeSelectOfficeModal() {
            if (!selectOfficeModal) return;
            selectOfficeModal.hidden = true;
            document.body.classList.remove('modal-open');
        }

        function closeEditOfficeModal() {
            if (!editOfficeModal) return;
            editOfficeModal.hidden = true;
            document.body.classList.remove('modal-open');
            if (editOfficeForm) { editOfficeForm.reset(); if (editOfficeId) editOfficeId.value = ''; }
        }

        if (openEditBtn) {
            openEditBtn.addEventListener('click', function() {
                openSelectOfficeModal();
            });
        }

        document.querySelectorAll('[data-close-select-office]').forEach(function(el) {
            el.addEventListener('click', function() {
                closeSelectOfficeModal();
                document.body.classList.remove('modal-open');
            });
        });

        document.querySelectorAll('[data-close-edit-office]').forEach(function(el) {
            el.addEventListener('click', function() {
                closeEditOfficeModal();
            });
        });

        document.getElementById('offices-list') && document.getElementById('offices-list').addEventListener('click', function(e) {
            var item = e.target.closest('.offices-list-item');
            if (!item || item.classList.contains('offices-list-placeholder')) return;
            var id = item.getAttribute('data-office-id');
            var code = item.getAttribute('data-office-code');
            var name = item.getAttribute('data-office-name');
            var head = item.getAttribute('data-office-head') || '';
            var desc = item.getAttribute('data-office-desc') || '';
            closeSelectOfficeModal();
            openEditOfficeModal(id, code, name, true, head, desc);
        });

        if (modalPrevBtn) {
            modalPrevBtn.addEventListener('click', function() {
                if (modalCurrentPage > 1) { modalCurrentPage--; updateModalPage(); }
            });
        }
        if (modalNextBtn) {
            modalNextBtn.addEventListener('click', function() {
                if (modalCurrentPage < modalTotalPages) { modalCurrentPage++; updateModalPage(); }
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (deleteModalOverlay && !deleteModalOverlay.hidden) {
                    closeDeleteConfirmModal();
                } else if (assignHeadModal && !assignHeadModal.hidden) {
                    closeAssignHeadModal();
                } else if (editOfficeModal && !editOfficeModal.hidden) {
                    closeEditOfficeModal();
                } else if (selectOfficeModal && !selectOfficeModal.hidden) {
                    closeSelectOfficeModal();
                }
            }
        });

        if (editOfficeModal && editOfficeFormError && editOfficeFormError.textContent.trim()) {
            openEditOfficeModal(editOfficeId ? editOfficeId.value : '', editOfficeCode ? editOfficeCode.value : '', editOfficeName ? editOfficeName.value : '', false);
        }

        if (editToast) {
            setTimeout(function() { editToast.classList.add('offices-toast-hide'); }, 3000);
        }

        // Notification dropdown functionality
        var notifBtn = document.getElementById('notif-btn');
        var notifDropdown = document.getElementById('notif-dropdown');
        function closeNotif() {
            if (notifDropdown) notifDropdown.style.display = 'none';
        }
        if (notifBtn) {
            notifBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (!notifDropdown) return;
                var showing = notifDropdown.style.display === 'block';
                closeNotif();
                notifDropdown.style.display = showing ? 'none' : 'block';
            });
            document.addEventListener('click', function() { closeNotif(); });
        }
    })();
    </script>
</body>
</html>
