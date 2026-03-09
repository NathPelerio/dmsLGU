<?php
session_start();
ob_start();

$role = $_SESSION['user_role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($role, ['admin', 'staff', 'departmenthead', 'department_head', 'dept_head'])) {
    header('Location: ../index.php');
    exit;
}

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../Super Admin Side/_activity_logger.php';
require_once __DIR__ . '/../Super Admin Side/_notifications_super_admin.php';
require_once __DIR__ . '/../Super Admin Side/_account_helpers.php';

// View document (inline – open in browser/viewer); must run before any includes that could output
if (!empty($_GET['view']) && preg_match('/^[a-f0-9]{24}$/i', $_GET['view'])) {
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare('SELECT file_name, file_content FROM documents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $_GET['view']]);
        $doc = $stmt->fetch();
        if ($doc) {
            $fileName = $doc['file_name'] ?? 'document.docx';
            $fileContent = $doc['file_content'] ?? '';
            if ($fileContent !== '') {
                if (ob_get_level()) ob_end_clean();
                header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                header('Content-Disposition: inline; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName) . '"');
                $decoded = base64_decode($fileContent, true);
                echo ($decoded !== false) ? $decoded : $fileContent;
                exit;
            }
        }
    } catch (Exception $e) {}
    if (ob_get_level()) ob_end_clean();
    header('HTTP/1.1 404 Not Found');
    exit;
}

// Download document (attachment); must run before any includes that could output
if (!empty($_GET['download']) && preg_match('/^[a-f0-9]{24}$/i', $_GET['download'])) {
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare('SELECT file_name, file_content FROM documents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $_GET['download']]);
        $doc = $stmt->fetch();
        if ($doc) {
            $fileName = $doc['file_name'] ?? 'document.docx';
            $fileContent = $doc['file_content'] ?? '';
            if ($fileContent !== '') {
                if (ob_get_level()) ob_end_clean();
                header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName) . '"');
                $decoded = base64_decode($fileContent, true);
                echo ($decoded !== false) ? $decoded : $fileContent;
                exit;
            }
        }
    } catch (Exception $e) {}
    if (ob_get_level()) ob_end_clean();
    header('HTTP/1.1 404 Not Found');
    exit;
}

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'Admin';
$userDepartment = $_SESSION['user_department'] ?? 'Not Assigned';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$sidebar_active = 'documents';
$documentsList = [];
$addMessage = null;
$addError = null;

if (!function_exists('getUserPhoto')) require_once __DIR__ . '/../Super Admin Side/_account_helpers.php';
if (function_exists('getUserPhoto') && !empty($_SESSION['user_id'])) { $fp = getUserPhoto($_SESSION['user_id']); if ($fp !== '') $_SESSION['user_photo'] = $fp; }

// Archive document and log to document history
if (!empty($_GET['archive']) && preg_match('/^[a-f0-9]{24}$/i', $_GET['archive'])) {
    $archiveId = $_GET['archive'];
    try {
        $pdo = dbPdo($config);
        $pdo->beginTransaction();
        $docStmt = $pdo->prepare('SELECT document_code, document_title FROM documents WHERE id = :id LIMIT 1');
        $docStmt->execute([':id' => $archiveId]);
        $doc = $docStmt->fetch();
        if ($doc) {
            $docCode = $doc['document_code'] ?? '';
            $docTitle = $doc['document_title'] ?? '';
            $up = $pdo->prepare('UPDATE documents SET status = :status, updated_at = :updated_at WHERE id = :id');
            $up->execute([':status' => 'archived', ':updated_at' => dbNowUtcString(), ':id' => $archiveId]);

            $hist = $pdo->prepare(
                'INSERT INTO document_history
                    (document_id, document_code, document_title, action, date_time, user_id, user_name)
                 VALUES
                    (:document_id, :document_code, :document_title, :action, :date_time, :user_id, :user_name)'
            );
            $hist->execute([
                ':document_id' => $archiveId,
                ':document_code' => $docCode,
                ':document_title' => $docTitle,
                ':action' => 'Archived',
                ':date_time' => dbNowUtcString(),
                ':user_id' => $_SESSION['user_id'] ?? '',
                ':user_name' => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
            ]);

            $del = $pdo->prepare('DELETE FROM sent_to_admin WHERE document_id = :document_id');
            $del->execute([':document_id' => $archiveId]);
            activityLog($config, 'document_archive', [
                'module' => 'admin_documents',
                'document_id' => $archiveId,
                'document_code' => (string)$docCode,
                'document_title' => (string)$docTitle,
            ]);
        }
        $pdo->commit();
    } catch (Exception $e) {}
    header('Location: documents.php');
    exit;
}

// Send document to department head(s) (POST from Send modal – multiple allowed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_to_head') {
    $docId = trim($_POST['document_id'] ?? '');
    $officeIds = isset($_POST['office_id']) ? (is_array($_POST['office_id']) ? $_POST['office_id'] : [$_POST['office_id']]) : [];
    $officeIds = array_filter(array_map('trim', $officeIds));
    $officeIds = array_values(array_unique($officeIds));
    if ($docId !== '' && count($officeIds) > 0) {
        try {
            $pdo = dbPdo($config);
            $docStmt = $pdo->prepare('SELECT id FROM documents WHERE id = :id LIMIT 1');
            $docStmt->execute([':id' => $docId]);
            if ($docStmt->fetch()) {
                $sentCount = 0;
                $ins = $pdo->prepare(
                    'INSERT INTO sent_to_department_heads
                        (document_id, office_id, office_name, office_head_id, office_head_name, sent_at, sent_by_user_id, sent_by_user_name)
                     VALUES
                        (:document_id, :office_id, :office_name, :office_head_id, :office_head_name, :sent_at, :sent_by_user_id, :sent_by_user_name)'
                );
                foreach ($officeIds as $officeId) {
                    $officeStmt = $pdo->prepare('SELECT * FROM offices WHERE id = :id LIMIT 1');
                    $officeStmt->execute([':id' => $officeId]);
                    $office = $officeStmt->fetch();
                    if (!$office) continue;

                    $rawHeadId = $office['office_head_id'] ?? null;
                    $officeHeadId = ($rawHeadId !== null && (string)$rawHeadId !== '') ? $rawHeadId : null;
                    $officeHeadName = trim((string)($office['office_head'] ?? ''));
                    $officeName = trim((string)($office['office_name'] ?? $office['office_code'] ?? 'Department'));
                    if ($officeHeadId === null && $officeHeadName === '') continue;

                    $rawUserId = $_SESSION['user_id'] ?? null;
                    $sentByUserId = ($rawUserId !== null && (string)$rawUserId !== '') ? $rawUserId : null;

                    $ins->execute([
                        ':document_id' => $docId,
                        ':office_id' => $officeId,
                        ':office_name' => $officeName,
                        ':office_head_id' => $officeHeadId,
                        ':office_head_name' => $officeHeadName,
                        ':sent_at' => dbNowUtcString(),
                        ':sent_by_user_id' => $sentByUserId,
                        ':sent_by_user_name' => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
                    ]);
                    $sentCount++;
                }
                if ($sentCount > 0) {
                    activityLog($config, 'document_send_to_department_heads', [
                        'module' => 'admin_documents',
                        'document_id' => $docId,
                        'target_count' => (string)$sentCount,
                    ]);
                    header('Location: documents.php?sent_head=1&count=' . (int)$sentCount);
                    exit;
                }
            }
        } catch (Exception $e) {
            error_log('[send_to_head] ' . $e->getMessage());
        }
    }
    header('Location: documents.php?send_error=1');
    exit;
}

// Send document to Super Admin Side with per-document stamp placement.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_to_super_admin') {
    $sendId = trim((string)($_POST['document_id'] ?? ''));
    $stampWidth = max(5, min(60, (float)($_POST['stamp_width_pct'] ?? 18)));
    $stampX = max(5, min(95, (float)($_POST['stamp_x_pct'] ?? 82)));
    $stampY = max(5, min(95, (float)($_POST['stamp_y_pct'] ?? 84)));
    $postedStampImage = trim((string)($_POST['stamp_image_data'] ?? ''));
    if (!preg_match('/^[a-f0-9]{24}$/i', $sendId)) {
        header('Location: documents.php?send_error=1');
        exit;
    }
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare('SELECT document_code, document_title, file_name FROM documents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $sendId]);
        $doc = $stmt->fetch();
        if ($doc) {
            ensureSuperAdminStampColumns($config);
            $docCode = $doc['document_code'] ?? '';
            $docTitle = $doc['document_title'] ?? '';
            $fileName = $doc['file_name'] ?? 'document.docx';
            $stampImage = '';
            if ($postedStampImage !== '' && preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', $postedStampImage)) {
                $stampImage = $postedStampImage;
            } else {
                $stampCfg = getUserStampConfig($_SESSION['user_id'] ?? '');
                $stampImage = trim((string)($stampCfg['stamp'] ?? ''));
            }
            if ($stampImage === '') {
                header('Location: documents.php?send_error=1');
                exit;
            }

            $insSent = $pdo->prepare(
                'INSERT INTO sent_to_super_admin
                    (document_id, document_code, document_title, file_name, sent_by_user_id, sent_by_user_name, sent_at, stamp_image, stamp_width_pct, stamp_x_pct, stamp_y_pct)
                 VALUES
                    (:document_id, :document_code, :document_title, :file_name, :sent_by_user_id, :sent_by_user_name, :sent_at, :stamp_image, :stamp_width_pct, :stamp_x_pct, :stamp_y_pct)'
            );
            $insSent->execute([
                ':document_id' => $sendId,
                ':document_code' => $docCode,
                ':document_title' => $docTitle,
                ':file_name' => $fileName,
                ':sent_by_user_id' => $_SESSION['user_id'] ?? '',
                ':sent_by_user_name' => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
                ':sent_at' => dbNowUtcString(),
                ':stamp_image' => $stampImage,
                ':stamp_width_pct' => $stampWidth,
                ':stamp_x_pct' => $stampX,
                ':stamp_y_pct' => $stampY,
            ]);
            createSuperAdminNotification($config, [
                'document_id' => $sendId,
                'document_code' => $docCode,
                'document_title' => $docTitle,
                'file_name' => $fileName,
                'sent_by_user_id' => $_SESSION['user_id'] ?? '',
                'sent_by_user_name' => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
                'stamp_image' => $stampImage,
                'stamp_width_pct' => $stampWidth,
                'stamp_x_pct' => $stampX,
                'stamp_y_pct' => $stampY,
            ]);
            activityLog($config, 'document_send_to_super_admin', [
                'module' => 'admin_documents',
                'document_id' => $sendId,
                'document_code' => (string)$docCode,
                'document_title' => (string)$docTitle,
            ]);
        }
    } catch (Exception $e) {}
    header('Location: documents.php?sent=1');
    exit;
}

// Add document (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_document') {
    $documentCode = trim($_POST['document_code'] ?? '');
    $documentTitle = trim($_POST['document_title'] ?? '');
    if ($documentCode === '' || $documentTitle === '') {
        $addError = 'Document code and title are required.';
    } elseif (empty($_FILES['document_file']['tmp_name']) || !is_uploaded_file($_FILES['document_file']['tmp_name'])) {
        $addError = 'Please select a DOCX file to upload.';
    } else {
        $file = $_FILES['document_file'];
        $fname = $file['name'] ?? '';
        if (!preg_match('/\.docx$/i', $fname)) {
            $addError = 'Only .docx files are allowed.';
        } else {
            $fileContent = base64_encode(file_get_contents($file['tmp_name']));
            if ($fileContent === false) {
                $addError = 'Could not read the uploaded file.';
            } else {
                try {
                    $pdo = dbPdo($config);
                    $newId = dbGenerateId24();
                    $now = dbNowUtcString();
                    $ins = $pdo->prepare(
                        'INSERT INTO documents
                            (id, document_code, document_title, file_name, file_content, created_at, created_by, status)
                         VALUES
                            (:id, :document_code, :document_title, :file_name, :file_content, :created_at, :created_by, :status)'
                    );
                    $ins->execute([
                        ':id' => $newId,
                        ':document_code' => $documentCode,
                        ':document_title' => $documentTitle,
                        ':file_name' => $fname,
                        ':file_content' => $fileContent,
                        ':created_at' => $now,
                        ':created_by' => $_SESSION['user_id'] ?? '',
                        ':status' => 'active',
                    ]);
                    $hist = $pdo->prepare(
                        'INSERT INTO document_history
                            (document_id, document_code, document_title, action, date_time, user_id, user_name)
                         VALUES
                            (:document_id, :document_code, :document_title, :action, :date_time, :user_id, :user_name)'
                    );
                    $hist->execute([
                        ':document_id' => $newId,
                        ':document_code' => $documentCode,
                        ':document_title' => $documentTitle,
                        ':action' => 'Added',
                        ':date_time' => $now,
                        ':user_id' => $_SESSION['user_id'] ?? '',
                        ':user_name' => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
                    ]);
                    activityLog($config, 'document_add', [
                        'module' => 'admin_documents',
                        'document_id' => $newId,
                        'document_code' => $documentCode,
                        'document_title' => $documentTitle,
                        'file_name' => (string)$fname,
                    ]);
                    header('Location: documents.php?added=1');
                    exit;
} catch (Exception $e) {
    $addError = 'Failed to save document: ' . $e->getMessage();
                }
            }
        }
    }
    if ($addError) {
        $_SESSION['documents_add_error'] = $addError;
        header('Location: documents.php?add_error=1');
        exit;
    }
}

// Fetch documents from database (active only; exclude archived)
try {
    $pdo = dbPdo($config);
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE status <> :status ORDER BY created_at DESC LIMIT 500');
    $stmt->execute([':status' => 'archived']);
    foreach ($stmt as $arr) {
        $arr['_id'] = (string)($arr['id'] ?? '');
        $arr['documentCode'] = $arr['document_code'] ?? '';
        $arr['documentTitle'] = $arr['document_title'] ?? '';
        $arr['fileName'] = $arr['file_name'] ?? 'document.docx';
        $arr['fileContent'] = $arr['file_content'] ?? '';
        $arr['createdAt'] = $arr['created_at'] ?? '';
        $arr['createdBy'] = $arr['created_by'] ?? '';
        $documentsList[] = $arr;
    }
} catch (Exception $e) {
    $documentsList = [];
}

// Department heads (offices with assigned head) for Send document modal
$departmentHeadsList = [];
$endorsementDepartments = [];
try {
    $pdo = dbPdo($config);
    $stmt = $pdo->query('SELECT * FROM offices ORDER BY office_name ASC');
    foreach ($stmt as $d) {
        $headId = trim($d['office_head_id'] ?? '');
        $headName = trim($d['office_head'] ?? '');
        if ($headId !== '' || $headName !== '') {
            $departmentHeadsList[] = [
                'id'             => (string)($d['id'] ?? ''),
                'office_name'    => $d['office_name'] ?? $d['office_code'] ?? '—',
                'office_head'    => $headName !== '' ? $headName : '—',
                'office_head_id' => $headId,
            ];
        }
    }
    $officeStmt = $pdo->query(
        'SELECT o.id, o.office_name, o.office_code, o.office_head, o.office_head_id,
                u.name AS head_name, u.username AS head_username, u.email AS head_email
         FROM offices o
         LEFT JOIN users u ON u.id = o.office_head_id
         ORDER BY o.office_name ASC, o.office_code ASC'
    );
    foreach ($officeStmt as $o) {
        $departmentLabel = trim((string)($o['office_name'] ?? ''));
        if ($departmentLabel === '') {
            $departmentLabel = trim((string)($o['office_code'] ?? ''));
        }
        if ($departmentLabel === '') {
            continue;
        }
        $headDisplay = trim((string)($o['head_name'] ?? ''));
        if ($headDisplay === '') {
            $headDisplay = trim((string)($o['head_username'] ?? ''));
        }
        if ($headDisplay === '') {
            $headDisplay = trim((string)($o['head_email'] ?? ''));
        }
        if ($headDisplay === '') {
            $headDisplay = trim((string)($o['office_head'] ?? ''));
        }
        $endorsementDepartments[] = [
            'id' => (string)($o['id'] ?? ''),
            'department' => $departmentLabel,
            'head' => $headDisplay,
        ];
    }
} catch (Exception $e) {
    $departmentHeadsList = [];
    $endorsementDepartments = [];
}
$currentUserStampCfg = getUserStampConfig($_SESSION['user_id'] ?? '');
$currentUserStamp = trim((string)($currentUserStampCfg['stamp'] ?? ''));

// Merge in documents sent from Super Admin: show them in the same Documents table and mark as "Received"
$idsInList = array_column($documentsList, '_id');
$idsInList = array_flip(array_filter($idsInList));
$sentFromSuperAdminStampCfg = [];
try {
    $pdo = dbPdo($config);
    $stmt = $pdo->query('SELECT * FROM sent_to_admin ORDER BY sent_at DESC LIMIT 500');
    foreach ($stmt as $arr) {
        $docId = (string)($arr['document_id'] ?? '');
        if ($docId === '') continue;
        $sentFromSuperAdminStampCfg[$docId] = [
            'image' => (string)($arr['stamp_image'] ?? ''),
            'width' => (string)($arr['stamp_width_pct'] ?? '18'),
            'x' => (string)($arr['stamp_x_pct'] ?? '82'),
            'y' => (string)($arr['stamp_y_pct'] ?? '84'),
        ];
        if (isset($idsInList[$docId])) continue;
        $idsInList[$docId] = true;
        $documentsList[] = [
            '_id'            => $docId,
            'documentCode'  => $arr['document_code'] ?? '—',
            'documentTitle' => $arr['document_title'] ?? '—',
            'fileName'       => $arr['file_name'] ?? 'document.docx',
            'status'        => 'received',
            'stamp_image' => (string)($arr['stamp_image'] ?? ''),
            'stamp_width_pct' => (string)($arr['stamp_width_pct'] ?? '18'),
            'stamp_x_pct' => (string)($arr['stamp_x_pct'] ?? '82'),
            'stamp_y_pct' => (string)($arr['stamp_y_pct'] ?? '84'),
        ];
    }
} catch (Exception $e) {}
// Mark documents already in the list that were sent from Super Admin with status "Received"
foreach ($documentsList as &$doc) {
    $id = (string)($doc['_id'] ?? '');
    if ($id !== '' && isset($sentFromSuperAdminStampCfg[$id])) {
        $doc['status'] = 'received';
        $cfg = $sentFromSuperAdminStampCfg[$id];
        $doc['stamp_image'] = (string)($cfg['image'] ?? '');
        $doc['stamp_width_pct'] = (string)($cfg['width'] ?? '18');
        $doc['stamp_x_pct'] = (string)($cfg['x'] ?? '82');
        $doc['stamp_y_pct'] = (string)($cfg['y'] ?? '84');
    }
}
unset($doc);

$added = isset($_GET['added']) && $_GET['added'] === '1';
$sent = isset($_GET['sent']) && $_GET['sent'] === '1';
$sentHead = isset($_GET['sent_head']) && $_GET['sent_head'] === '1';
$sentHeadCount = isset($_GET['count']) ? (int)$_GET['count'] : 0;
$sendError = isset($_GET['send_error']) && $_GET['send_error'] === '1';
if (isset($_GET['add_error']) && isset($_SESSION['documents_add_error'])) {
    $addError = $_SESSION['documents_add_error'];
    unset($_SESSION['documents_add_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Documents</title>
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
    .main-content { flex: 1; margin-left: 260px; padding: 0; background: #f8fafc; overflow-x: auto; display: flex; flex-direction: column; }
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
    .dept-page-title { margin: 0; font-size: 1.75rem; font-weight: 700; color: #1e293b; }
    .dept-page-subtitle { margin: 0.25rem 0 0 0; font-size: 0.95rem; color: #64748b; }
    /* Documents section – light container to match other admin pages */
    .documents-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    .documents-title { font-weight: 700; font-size: 1.15rem; color: #1e293b; margin: 0 0 1rem 0; }
    .documents-tools { display: grid; grid-template-columns: 1.4fr 1fr 1fr auto auto; gap: 12px; margin-bottom: 16px; }
    .documents-tools input { height: 42px; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0 12px; font-size: 14px; color: #1e293b; background: #fff; outline: none; font-family: inherit; }
    .documents-tools input:focus { border-color: #1A202C; box-shadow: 0 0 0 3px rgba(26,32,44,0.12); }
    .documents-btn { height: 42px; border: none; border-radius: 10px; padding: 0 16px; background: #1A202C; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-family: inherit; transition: background 0.2s ease; }
    .documents-btn:hover { background: #2d3748; color: #fff; }
    .documents-btn svg { width: 18px; height: 18px; flex-shrink: 0; }
    .documents-btn-secondary { background: #f1f5f9; color: #475569; }
    .documents-btn-secondary:hover { background: #e2e8f0; color: #1e293b; }
    .documents-table-frame { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; overflow: hidden; margin-top: 1rem; }
    .documents-table { width: 100%; border-collapse: collapse; }
    .documents-table thead th { text-align: left; padding: 14px 16px; font-size: 13px; font-weight: 600; letter-spacing: 0.03em; color: #475569; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
    .documents-table tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 14px; }
    .documents-empty { text-align: center; height: 200px; color: #64748b; vertical-align: middle; }
    .document-status { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; text-transform: capitalize; }
    .document-status-active { background: #d1fae5; color: #047857; }
    .document-status-archived { background: #f3f4f6; color: #6b7280; }
    .document-status-received { background: #dbeafe; color: #1d4ed8; }
    .documents-actions-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .documents-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px; border: none; font-size: 13px; font-weight: 500; cursor: pointer; font-family: inherit; transition: background 0.15s, color 0.15s; }
    .documents-action-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
    .documents-action-open { background: #dbeafe; color: #1d4ed8; }
    .documents-action-open:hover { background: #bfdbfe; color: #1d4ed8; }
    .documents-action-archive { background: #fef3c7; color: #b45309; }
    .documents-action-archive:hover { background: #fde68a; color: #b45309; }
    .documents-action-send { background: #d1fae5; color: #047857; }
    .documents-action-send:hover { background: #a7f3d0; color: #047857; }
    .documents-action-send-super { background: #dbeafe; color: #1d4ed8; text-decoration: none; }
    .documents-action-send-super:hover { background: #bfdbfe; color: #1d4ed8; }
    .documents-send-wrap { position: relative; display: inline-block; }
    .documents-send-trigger { text-decoration: underline; }
    .documents-send-trigger:hover { text-decoration: underline; }
    .documents-send-dropdown { position: fixed; min-width: 200px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 8px 20px rgba(0,0,0,0.12); padding: 6px 0; z-index: 1600; display: none; }
    .documents-send-dropdown.show { display: block; }
    .documents-send-dropdown-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 10px 14px; border: none; background: none; color: #1e293b; font-size: 13px; font-weight: 500; cursor: pointer; text-align: left; text-decoration: none; font-family: inherit; box-sizing: border-box; transition: background 0.15s; }
    .documents-send-dropdown-item:hover { background: #f1f5f9; }
    .documents-send-dropdown-item svg { width: 16px; height: 16px; flex-shrink: 0; }
    /* Send to Heads modal – match system design (doc-modal, admin-offices.css) */
    #send-document-modal .doc-modal-dialog { max-width: 480px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04); }
    #send-document-modal .doc-modal-header { padding: 14px 18px; border-bottom: 1px solid #e2e8f0; }
    #send-document-modal .doc-modal-header h2 { font-size: 1.35rem; font-weight: 700; color: #1e293b; }
    #send-document-modal .doc-modal-close { color: #475569; }
    #send-document-modal .doc-modal-close:hover { color: #1e293b; }
    .send-modal-subtitle { margin: 0; padding: 16px 18px 12px 18px; font-size: 14px; color: #64748b; line-height: 1.5; border-bottom: 1px solid #f1f5f9; }
    #send-document-modal .doc-modal-form { padding: 16px 18px 18px; gap: 12px; }
    .send-heads-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .send-heads-toolbar-links { display: flex; gap: 12px; font-size: 14px; }
    .send-heads-toolbar-links button { background: none; border: none; color: #2563eb; cursor: pointer; padding: 0; font-family: inherit; font-size: inherit; font-weight: 600; }
    .send-heads-toolbar-links button:hover { color: #1d4ed8; text-decoration: underline; }
    .send-heads-toolbar-count { font-size: 14px; color: #475569; font-weight: 500; }
    .send-heads-list { max-height: 280px; overflow-y: auto; padding-right: 4px; }
    .send-heads-list::-webkit-scrollbar { width: 6px; }
    .send-heads-list::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
    .send-heads-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
    .send-head-row { display: flex; align-items: center; gap: 14px; padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 8px; cursor: pointer; transition: background 0.2s, border-color 0.2s, box-shadow 0.2s; background: #fff; }
    .send-head-row:hover { background: #f8fafc; border-color: #cbd5e1; }
    .send-head-row.selected { background: #eff6ff; border-color: #2563eb; box-shadow: 0 0 0 1px rgba(37, 99, 235, 0.2); }
    .send-head-row input[type="checkbox"] { width: 18px; height: 18px; flex-shrink: 0; accent-color: #2563eb; cursor: pointer; }
    .send-head-row-content { flex: 1; min-width: 0; }
    .send-head-office { display: block; font-weight: 600; color: #1e293b; font-size: 14px; margin-bottom: 2px; }
    .send-head-name { display: block; color: #64748b; font-size: 13px; }
    .send-modal-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 4px; padding-top: 16px; border-top: 1px solid #e2e8f0; }
    .send-modal-actions .doc-btn { height: 38px; border-radius: 10px; font-size: 14px; font-weight: 600; }
    .send-modal-actions .doc-btn-save { min-width: 100px; background: #2563eb; color: #fff; }
    .send-modal-actions .doc-btn-save:hover { background: #1d4ed8; color: #fff; }
    .send-modal-actions .doc-btn-save:disabled { background: #94a3b8; color: #fff; cursor: not-allowed; }
    #send-document-modal .documents-empty { font-size: 14px; color: #64748b; }
    .doc-modal-dialog-view { max-width: 90%; width: 900px; max-height: 90vh; display: flex; flex-direction: column; }
    .document-view-body { flex: 1; min-height: 0; overflow: auto; padding: 1rem; background: #f8fafc; border-top: 1px solid #e2e8f0; }
    .document-view-container { overflow: auto; max-height: 65vh; padding: 1rem; background: #fff; border-radius: 8px; }
    .document-view-loading, .document-view-error { padding: 2rem; text-align: center; color: #64748b; }
    .document-view-error { color: #dc2626; }
    .send-stamp-overlay { position: absolute; transform: translate(-50%, -50%); pointer-events: auto; user-select: none; touch-action: none; cursor: move; max-width: none; max-height: none; z-index: 20; }
    .stamp-template-box { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; background: #fff; }
    .stamp-template-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 8px; }
    .stamp-template-btn { border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; border-radius: 8px; padding: 9px 12px; font-size: 12px; font-weight: 700; cursor: pointer; text-align: center; }
    .stamp-template-btn.active { background: #dbeafe; border-color: #60a5fa; color: #1d4ed8; box-shadow: inset 0 0 0 1px #93c5fd; }
    .stamp-template-current { font-size: 12px; color: #475569; margin: 0; line-height: 1.35; }
    .stamp-fields-grid { display: grid; gap: 8px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .stamp-fields-grid .full { grid-column: 1 / -1; }
    .stamp-fields-grid label { display: grid; gap: 4px; font-size: 12px; color: #334155; font-weight: 600; }
    .stamp-fields-grid input, .stamp-fields-grid select { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 7px 9px; font-size: 12px; font-family: inherit; }
    .stamp-auto-head { margin-top: 2px; font-size: 11px; color: #64748b; font-weight: 600; }
    .stamp-detail-note { margin: 0 0 12px 0; font-size: 12px; color: #64748b; }
    .stamp-adjust-wrap { display: flex; align-items: center; gap: 10px; margin-top: 10px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; padding: 9px 10px; }
    .stamp-adjust-wrap label { font-size: 13px; font-weight: 700; color: #334155; white-space: nowrap; }
    .stamp-adjust-wrap input[type="range"] { flex: 1; accent-color: #2563eb; }
    .stamp-adjust-wrap span { min-width: 46px; text-align: right; font-size: 13px; font-weight: 700; color: #334155; }
    .stamp-tilt-wrap { display: flex; align-items: center; gap: 10px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; padding: 9px 10px; }
    .stamp-tilt-wrap label { font-size: 13px; font-weight: 700; color: #334155; white-space: nowrap; }
    .stamp-tilt-wrap input[type="range"] { flex: 1; accent-color: #2563eb; }
    .stamp-tilt-wrap span { min-width: 52px; text-align: right; font-size: 13px; font-weight: 700; color: #334155; }
    .doc-modal-footer { flex-shrink: 0; padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; gap: 10px; display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; }
    .doc-modal-footer a.documents-action-btn { text-decoration: none; }
    @media (max-width: 980px) { .documents-tools { grid-template-columns: 1fr 1fr; } .stamp-template-row { grid-template-columns: 1fr; } .stamp-adjust-wrap { flex-wrap: wrap; } .stamp-adjust-wrap label { width: 100%; } }
    </style>
</head>
<body<?php if (!empty($addError)): ?> data-add-error="1"<?php endif; ?><?php if (!empty($added)): ?> data-added="1"<?php endif; ?><?php if (!empty($sent)): ?> data-sent="1"<?php endif; ?><?php if (!empty($sentHead)): ?> data-sent-head="1" data-sent-head-count="<?php echo (int)$sentHeadCount; ?>"<?php endif; ?><?php if (!empty($sendError)): ?> data-send-error="1"<?php endif; ?>>
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
                            <h1 class="dept-page-title">Documents</h1>
                            <p class="dept-page-subtitle">Create, track, and manage municipal documents across all departments</p>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
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
                <section class="documents-card">
                    <h2 class="documents-title">Documents</h2>
                    <div class="documents-tools">
                        <input type="text" id="search-documents" placeholder="Search by code or title" aria-label="Search by code or title">
                        <input type="date" id="documents-date-from" aria-label="From date">
                        <input type="date" id="documents-date-to" aria-label="To date">
                        <button type="button" class="documents-btn" id="open-add-document-modal">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                            Add Document
                        </button>
                        <button type="button" class="documents-btn documents-btn-secondary" id="edit-document-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                            Edit
                        </button>
                    </div>

                    <div class="documents-table-frame">
                        <table class="documents-table">
                            <thead>
                                <tr>
                                    <th>NO.</th>
                                    <th>DOCUMENT CODE</th>
                                    <th>DOCUMENT TITLE</th>
                                    <th>DOCX FILE</th>
                                    <th>STATUS</th>
                                    <th>ACTION</th>
                                </tr>
                            </thead>
                            <tbody id="documents-table-body">
                                <?php if (empty($documentsList)): ?>
                                <tr>
                                    <td colspan="6" class="documents-empty" id="no-documents-row">No documents yet.</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($documentsList as $idx => $doc): ?>
                                <?php
                                    $docId = $doc['_id'] ?? '';
                                    $docCode = htmlspecialchars($doc['documentCode'] ?? $doc['document_code'] ?? '—');
                                    $docTitle = htmlspecialchars($doc['documentTitle'] ?? $doc['document_title'] ?? '—');
                                    $docFileName = htmlspecialchars($doc['fileName'] ?? $doc['file_name'] ?? '—');
                                    $docStatus = isset($doc['status']) ? ucfirst(strtolower($doc['status'])) : 'Active';
                                ?>
                                <tr data-document-row data-document-id="<?php echo htmlspecialchars($docId); ?>">
                                    <td><?php echo (int)($idx + 1); ?></td>
                                    <td><?php echo $docCode; ?></td>
                                    <td><?php echo $docTitle; ?></td>
                                    <td><a href="documents.php?view=<?php echo urlencode($docId); ?>" class="doc-file-link document-view-trigger" data-doc-id="<?php echo htmlspecialchars($docId); ?>" data-doc-name="<?php echo htmlspecialchars($docFileName); ?>" data-stamp-image="<?php echo htmlspecialchars((string)($doc['stamp_image'] ?? '')); ?>" data-stamp-width="<?php echo htmlspecialchars((string)($doc['stamp_width_pct'] ?? '18')); ?>" data-stamp-x="<?php echo htmlspecialchars((string)($doc['stamp_x_pct'] ?? '82')); ?>" data-stamp-y="<?php echo htmlspecialchars((string)($doc['stamp_y_pct'] ?? '84')); ?>"><?php echo $docFileName; ?></a></td>
                                    <td><span class="document-status document-status-<?php echo strtolower(htmlspecialchars($docStatus)); ?>"><?php echo htmlspecialchars($docStatus); ?></span></td>
                                    <td>
                                        <div class="documents-actions-row">
                                            <a href="documents.php?view=<?php echo urlencode($docId); ?>" class="documents-action-btn documents-action-open document-view-trigger" data-doc-id="<?php echo htmlspecialchars($docId); ?>" data-doc-name="<?php echo htmlspecialchars($docFileName); ?>" data-stamp-image="<?php echo htmlspecialchars((string)($doc['stamp_image'] ?? '')); ?>" data-stamp-width="<?php echo htmlspecialchars((string)($doc['stamp_width_pct'] ?? '18')); ?>" data-stamp-x="<?php echo htmlspecialchars((string)($doc['stamp_x_pct'] ?? '82')); ?>" data-stamp-y="<?php echo htmlspecialchars((string)($doc['stamp_y_pct'] ?? '84')); ?>" title="View document"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</a>
                                            <div class="documents-send-wrap">
                                                <button type="button" class="documents-action-btn documents-action-send documents-send-trigger" data-document-id="<?php echo htmlspecialchars($docId); ?>" title="Send" aria-haspopup="true" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Send</button>
                                                <div class="documents-send-dropdown" role="menu" aria-label="Send options">
                                                    <button type="button" class="documents-send-dropdown-item" data-send-action="super" data-document-id="<?php echo htmlspecialchars($docId); ?>" data-doc-name="<?php echo htmlspecialchars($docFileName); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Send to Super Admin</button>
                                                    <button type="button" class="documents-send-dropdown-item" data-send-action="heads" data-document-id="<?php echo htmlspecialchars($docId); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Send to Heads</button>
                                                </div>
                                            </div>
                                            <a href="documents.php?archive=<?php echo urlencode($docId); ?>" class="documents-action-btn documents-action-archive" title="Archive document" onclick="return confirm('Archive this document?');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><path d="M1 3h22v5H1z"/><line x1="10" y1="12" x2="14" y2="12"/></svg>Archive</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <div class="doc-modal" id="add-document-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-add-document aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="add-document-title">
            <div class="doc-modal-header">
                <h2 id="add-document-title">Add Document</h2>
                <button type="button" class="doc-modal-close" data-close-add-document aria-label="Close">&times;</button>
            </div>
            <form id="add-document-form" class="doc-modal-form" method="post" action="documents.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_document">
                <div class="doc-form-field">
                    <label for="document-code">Document Code</label>
                    <input type="text" id="document-code" name="document_code" placeholder="e.g. DOC-001" required>
                </div>
                <div class="doc-form-field">
                    <label for="document-title">Document Title</label>
                    <input type="text" id="document-title" name="document_title" placeholder="Enter document title" required>
                </div>
                <div class="doc-form-field">
                    <label for="document-file">DOCX File</label>
                    <input type="file" id="document-file" name="document_file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                </div>
                <p class="doc-form-error" id="document-form-error" <?php if (empty($addError)): ?>hidden<?php endif; ?>><?php if (!empty($addError)): echo htmlspecialchars($addError); endif; ?></p>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-add-document>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Save Document</button>
                </div>
            </form>
        </div>
    </div>

    <div class="doc-modal" id="send-document-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-send-document aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="send-document-title">
            <div class="doc-modal-header">
                <h2 id="send-document-title">Send to Heads</h2>
                <button type="button" class="doc-modal-close" data-close-send-document aria-label="Close">&times;</button>
            </div>
            <p class="send-modal-subtitle">Select one or more department heads to send this document to.</p>
            <form method="post" action="documents.php" id="send-document-form" class="doc-modal-form">
                <input type="hidden" name="action" value="send_to_head">
                <input type="hidden" name="document_id" id="send-document-id" value="">
                <?php if (!empty($departmentHeadsList)): ?>
                <div class="send-heads-toolbar">
                    <span class="send-heads-toolbar-count" id="send-selection-count">0 selected</span>
                    <div class="send-heads-toolbar-links">
                        <button type="button" id="send-select-all" aria-label="Select all">Select all</button>
                        <button type="button" id="send-clear-all" aria-label="Clear selection">Clear</button>
                    </div>
                </div>
                <?php endif; ?>
                <div class="send-heads-list" id="send-heads-list">
                    <?php if (empty($departmentHeadsList)): ?>
                    <p class="documents-empty" style="padding: 1rem 0; text-align: center; margin: 0;">No department heads assigned yet. Assign heads in <strong>Departments</strong> first.</p>
                    <?php else: ?>
                    <?php foreach ($departmentHeadsList as $head): ?>
                    <label class="send-head-row" data-send-head>
                        <input type="checkbox" name="office_id[]" value="<?php echo htmlspecialchars($head['id']); ?>" class="send-head-cb">
                        <span class="send-head-row-content">
                            <span class="send-head-office"><?php echo htmlspecialchars($head['office_name']); ?></span>
                            <span class="send-head-name"><?php echo htmlspecialchars($head['office_head']); ?></span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="send-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-send-document>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save" id="send-submit-btn" <?php if (empty($departmentHeadsList)): ?>disabled<?php endif; ?>>Send</button>
                </div>
            </form>
        </div>
    </div>

    <div class="doc-modal" id="send-super-admin-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-send-super-admin aria-label="Close"></button>
        <div class="doc-modal-dialog doc-modal-dialog-view" role="dialog" aria-modal="true" aria-labelledby="send-super-admin-title">
            <div class="doc-modal-header">
                <h2 id="send-super-admin-title">Prepare Document for Super Admin</h2>
                <button type="button" class="doc-modal-close" data-close-send-super-admin aria-label="Close">&times;</button>
            </div>
            <div class="document-view-body">
                <div id="send-super-admin-loading" class="document-view-loading">Loading document...</div>
                <div id="send-super-admin-container" class="document-view-container" style="display:none; position: relative;"></div>
                <div id="send-super-admin-error" class="document-view-error" style="display:none;">Could not load document.</div>
                <div class="stamp-template-box">
                    <div class="stamp-template-row">
                        <button type="button" class="stamp-template-btn active" data-stamp-type="approved">Approved</button>
                        <button type="button" class="stamp-template-btn" data-stamp-type="received">Received</button>
                        <button type="button" class="stamp-template-btn" data-stamp-type="endorsement">Endorsement</button>
                    </div>
                    <p class="stamp-template-current">Selected stamp: <strong id="active-stamp-type-label">Approved</strong>. Click a stamp button to open input modal.</p>
                </div>
                <div class="stamp-adjust-wrap">
                    <label for="send-super-admin-stamp-size">Stamp size</label>
                    <input type="range" id="send-super-admin-stamp-size" min="5" max="60" step="1" value="18">
                    <span id="send-super-admin-stamp-size-label">18%</span>
                </div>
                <div class="stamp-tilt-wrap">
                    <label for="send-super-admin-stamp-tilt">Tilt</label>
                    <input type="range" id="send-super-admin-stamp-tilt" min="-35" max="35" step="1" value="0">
                    <span id="send-super-admin-stamp-tilt-label">0°</span>
                </div>
            </div>
            <div class="doc-modal-footer doc-modal-actions">
                <a id="send-super-admin-download-link" href="#" class="documents-action-btn documents-action-open" target="_blank" rel="noopener" download style="display:none;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Download</a>
                <button type="button" class="doc-btn doc-btn-cancel" data-close-send-super-admin>Cancel</button>
                <button type="button" class="doc-btn doc-btn-save" id="send-super-admin-submit">Send to Super Admin</button>
            </div>
        </div>
    </div>

    <div class="doc-modal" id="stamp-detail-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-stamp-detail aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="stamp-detail-title">
            <div class="doc-modal-header">
                <h2 id="stamp-detail-title">Stamp Details</h2>
                <button type="button" class="doc-modal-close" data-close-stamp-detail aria-label="Close">&times;</button>
            </div>
            <div class="doc-modal-form">
                <p class="stamp-detail-note">Fill in the fields for this stamp, then click Apply.</p>
                <div class="stamp-fields-grid" id="stamp-fields-grid">
                    <label data-for-types="approved,received,endorsement">Date
                        <input type="date" id="stamp-date-input">
                    </label>
                    <label data-for-types="received">Time
                        <input type="time" id="stamp-time-input">
                    </label>
                    <label data-for-types="approved,received">By
                        <input type="text" id="stamp-by-input" placeholder="Name">
                    </label>
                    <label class="full" data-for-types="endorsement">Department
                        <select id="stamp-to-input">
                            <option value="">Select department</option>
                            <?php foreach ($endorsementDepartments as $d): ?>
                            <option value="<?= htmlspecialchars($d['head']) ?>" data-head="<?= htmlspecialchars($d['head']) ?>" <?= $d['head'] === '' ? 'disabled' : '' ?>>
                                <?= htmlspecialchars($d['department']) ?><?= $d['head'] !== '' ? '' : ' (No assigned head)' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="stamp-auto-head" id="stamp-head-preview">Assigned head: —</small>
                    </label>
                </div>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-stamp-detail>Cancel</button>
                    <button type="button" class="doc-btn doc-btn-save" id="stamp-detail-apply">Apply</button>
                </div>
            </div>
        </div>
    </div>

    <form method="post" action="documents.php" id="send-super-admin-form" style="display:none;">
        <input type="hidden" name="action" value="send_to_super_admin">
        <input type="hidden" name="document_id" id="send-super-admin-doc-id" value="">
        <input type="hidden" name="stamp_image_data" id="send-super-admin-stamp-image-data" value="">
        <input type="hidden" name="stamp_width_pct" id="send-super-admin-width" value="18">
        <input type="hidden" name="stamp_x_pct" id="send-super-admin-x" value="82">
        <input type="hidden" name="stamp_y_pct" id="send-super-admin-y" value="84">
    </form>

    <div class="doc-modal" id="document-view-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-document-view aria-label="Close"></button>
        <div class="doc-modal-dialog doc-modal-dialog-view" role="dialog" aria-modal="true" aria-labelledby="document-view-title">
            <div class="doc-modal-header">
                <h2 id="document-view-title" class="document-view-title">Document</h2>
                <button type="button" class="doc-modal-close" data-close-document-view aria-label="Close">&times;</button>
            </div>
            <div class="document-view-body">
                <div id="document-view-loading" class="document-view-loading">Loading document…</div>
                <div id="document-view-container" class="document-view-container" style="display:none;"></div>
                <div id="document-view-error" class="document-view-error" style="display:none;">Could not load document.</div>
            </div>
            <div class="doc-modal-footer doc-modal-actions">
                <a id="document-view-download-link" href="#" class="documents-action-btn documents-action-open" target="_blank" rel="noopener" download style="display:none;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Download</a>
                <button type="button" class="documents-action-btn documents-action-open" data-close-document-view><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Close</button>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/_profile_modal_admin.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/jszip@3/dist/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.0/dist/docx-preview.min.js"></script>
    <script src="sidebar_admin.js"></script>
    <script>
    (function() {
        var uploadedStampData = <?php echo json_encode($currentUserStamp); ?> || '';
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

        var openAddModalBtn = document.getElementById('open-add-document-modal');
        var addModal = document.getElementById('add-document-modal');
        var addForm = document.getElementById('add-document-form');
        var errorEl = document.getElementById('document-form-error');
        var documentsTableBody = document.getElementById('documents-table-body');
        var editBtn = document.getElementById('edit-document-btn');

        function setFormError(message) {
            if (!errorEl) return;
            if (!message) {
                errorEl.hidden = true;
                errorEl.textContent = '';
                return;
            }
            errorEl.hidden = false;
            errorEl.textContent = message;
        }

        function openAddDocumentModal() {
            if (!addModal) return;
            addModal.hidden = false;
            document.body.classList.add('modal-open');
            setFormError('');
        }

        function closeAddDocumentModal() {
            if (!addModal) return;
            addModal.hidden = true;
            document.body.classList.remove('modal-open');
            setFormError('');
            if (addForm) addForm.reset();
        }

        if (openAddModalBtn) {
            openAddModalBtn.addEventListener('click', openAddDocumentModal);
        }

        document.querySelectorAll('[data-close-add-document]').forEach(function(closeBtn) {
            closeBtn.addEventListener('click', closeAddDocumentModal);
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && addModal && !addModal.hidden) {
                closeAddDocumentModal();
            }
        });

        if (editBtn) {
            editBtn.addEventListener('click', function() {
                alert('Select a document row to edit. (Edit function can be added next.)');
            });
        }

        // Open modal on load when there was an add error (after POST redirect)
        if (addModal && document.body.getAttribute('data-add-error') === '1') {
            addModal.hidden = false;
            document.body.classList.add('modal-open');
        }

        // Show success message when document was added
        if (document.body.getAttribute('data-added') === '1') {
            var toast = document.createElement('div');
            toast.className = 'documents-toast documents-toast-success';
            toast.setAttribute('role', 'status');
            toast.textContent = 'Document saved successfully.';
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#22c55e;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 4000);
        }
        // Show success message when document was sent to Super Admin
        if (document.body.getAttribute('data-sent') === '1') {
            var toast = document.createElement('div');
            toast.className = 'documents-toast documents-toast-success';
            toast.setAttribute('role', 'status');
            toast.textContent = 'Document sent to Super Admin.';
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#22c55e;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 4000);
        }
        // Show success when document was sent to department head(s)
        if (document.body.getAttribute('data-sent-head') === '1') {
            var count = parseInt(document.body.getAttribute('data-sent-head-count') || '1', 10);
            var toast = document.createElement('div');
            toast.className = 'documents-toast documents-toast-success';
            toast.setAttribute('role', 'status');
            toast.textContent = count === 1 ? 'Document sent to 1 department head.' : 'Document sent to ' + count + ' department heads.';
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#22c55e;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 4000);
        }
        if (document.body.getAttribute('data-send-error') === '1') {
            var errorToast = document.createElement('div');
            errorToast.className = 'documents-toast documents-toast-error';
            errorToast.setAttribute('role', 'status');
            errorToast.textContent = 'Could not send document. Try again.';
            errorToast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#ef4444;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(errorToast);
            setTimeout(function() { errorToast.remove(); }, 4500);
        }

        // Send document modal: open, multi-select, select all / clear
        var sendModal = document.getElementById('send-document-modal');
        var sendDocumentIdInput = document.getElementById('send-document-id');
        var sendHeadsList = document.getElementById('send-heads-list');
        var sendSelectionCount = document.getElementById('send-selection-count');
        var sendSubmitBtn = document.getElementById('send-submit-btn');
        var sendSelectAllBtn = document.getElementById('send-select-all');
        var sendClearAllBtn = document.getElementById('send-clear-all');

        function updateSendSelection() {
            if (!sendHeadsList) return;
            var cbs = sendHeadsList.querySelectorAll('.send-head-cb');
            var count = 0;
            cbs.forEach(function(cb) {
                if (cb.checked) count++;
                var row = cb.closest('.send-head-row');
                if (row) row.classList.toggle('selected', cb.checked);
            });
            if (sendSelectionCount) sendSelectionCount.textContent = count === 0 ? '0 selected' : count + ' selected';
            if (sendSubmitBtn) {
                sendSubmitBtn.disabled = count === 0;
                sendSubmitBtn.textContent = count === 0 ? 'Send' : (count === 1 ? 'Send to 1 head' : 'Send to ' + count + ' heads');
            }
        }

        function openSendModalForDoc(docId) {
            if (sendDocumentIdInput) sendDocumentIdInput.value = docId;
            if (sendHeadsList) sendHeadsList.querySelectorAll('.send-head-cb').forEach(function(cb) { cb.checked = false; });
            updateSendSelection();
            if (sendModal) {
                sendModal.hidden = false;
                document.body.classList.add('modal-open');
            }
        }

        function closeAllSendDropdowns() {
            document.querySelectorAll('.documents-send-wrap').forEach(function(wrap) {
                wrap.classList.remove('open');
                var dd = wrap.querySelector('.documents-send-dropdown');
                if (dd) dd.classList.remove('show');
                var trigger = wrap.querySelector('.documents-send-trigger');
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
            });
        }

        document.querySelectorAll('.documents-send-trigger').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var wrap = this.closest('.documents-send-wrap');
                var dropdown = wrap ? wrap.querySelector('.documents-send-dropdown') : null;
                var isOpen = wrap && wrap.classList.contains('open');
                closeAllSendDropdowns();
                if (!isOpen && wrap && dropdown) {
                    var rect = this.getBoundingClientRect();
                    dropdown.style.left = rect.left + 'px';
                    dropdown.style.top = (rect.bottom + 4) + 'px';
                    wrap.classList.add('open');
                    dropdown.classList.add('show');
                    this.setAttribute('aria-expanded', 'true');
                }
            });
        });

        document.querySelectorAll('.documents-send-dropdown').forEach(function(dd) {
            dd.addEventListener('click', function(e) { e.stopPropagation(); });
        });

        var sendSuperAdminModal = document.getElementById('send-super-admin-modal');
        var sendSuperAdminContainer = document.getElementById('send-super-admin-container');
        var sendSuperAdminLoading = document.getElementById('send-super-admin-loading');
        var sendSuperAdminError = document.getElementById('send-super-admin-error');
        var sendSuperAdminDownloadLink = document.getElementById('send-super-admin-download-link');
        var sendSuperAdminSizeRange = document.getElementById('send-super-admin-stamp-size');
        var sendSuperAdminSizeLabel = document.getElementById('send-super-admin-stamp-size-label');
        var sendSuperAdminTiltRange = document.getElementById('send-super-admin-stamp-tilt');
        var sendSuperAdminTiltLabel = document.getElementById('send-super-admin-stamp-tilt-label');
        var sendSuperAdminSubmit = document.getElementById('send-super-admin-submit');
        var sendSuperAdminForm = document.getElementById('send-super-admin-form');
        var sendSuperAdminDocId = document.getElementById('send-super-admin-doc-id');
        var sendSuperAdminStampImageData = document.getElementById('send-super-admin-stamp-image-data');
        var sendSuperAdminWidth = document.getElementById('send-super-admin-width');
        var sendSuperAdminX = document.getElementById('send-super-admin-x');
        var sendSuperAdminY = document.getElementById('send-super-admin-y');
        var activeStampTypeLabel = document.getElementById('active-stamp-type-label');
        var stampDetailModal = document.getElementById('stamp-detail-modal');
        var stampDetailTitle = document.getElementById('stamp-detail-title');
        var stampDetailApply = document.getElementById('stamp-detail-apply');
        var stampTypeButtons = Array.prototype.slice.call(document.querySelectorAll('[data-stamp-type]'));
        var stampFieldsGrid = document.getElementById('stamp-fields-grid');
        var stampDateInput = document.getElementById('stamp-date-input');
        var stampTimeInput = document.getElementById('stamp-time-input');
        var stampByInput = document.getElementById('stamp-by-input');
        var stampToInput = document.getElementById('stamp-to-input');
        var stampHeadPreview = document.getElementById('stamp-head-preview');
        var sendStampNode = null;
        var sendStampCfg = { width: 18, x: 82, y: 84, tilt: 0 };
        var activeStampType = 'approved';
        var generatedStampData = '';

        function clamp(value, min, max) {
            return Math.max(min, Math.min(max, value));
        }

        function toYmdLocal(dateObj) {
            var y = dateObj.getFullYear();
            var m = String(dateObj.getMonth() + 1).padStart(2, '0');
            var d = String(dateObj.getDate()).padStart(2, '0');
            return y + '-' + m + '-' + d;
        }

        function toHmLocal(dateObj) {
            var h = String(dateObj.getHours()).padStart(2, '0');
            var m = String(dateObj.getMinutes()).padStart(2, '0');
            return h + ':' + m;
        }

        function formatLongDate(ymd) {
            if (!ymd) return '';
            var dt = new Date(ymd + 'T00:00:00');
            if (isNaN(dt.getTime())) return ymd;
            return dt.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }).toUpperCase();
        }

        function formatDisplayTime(hm) {
            if (!hm || hm.indexOf(':') < 0) return hm || '';
            var parts = hm.split(':');
            var hh = parseInt(parts[0], 10);
            var mm = parts[1] || '00';
            if (!isFinite(hh)) return hm;
            var suffix = hh >= 12 ? 'PM' : 'AM';
            var h12 = hh % 12;
            if (h12 === 0) h12 = 12;
            return h12 + ':' + mm + ' ' + suffix;
        }

        function stampTypeLabel(type) {
            if (type === 'received') return 'Received';
            if (type === 'endorsement') return 'Endorsement';
            return 'Approved';
        }

        function updateEndorsementHeadPreview() {
            if (!stampToInput || !stampHeadPreview) return;
            var selectedOpt = stampToInput.options && stampToInput.selectedIndex >= 0
                ? stampToInput.options[stampToInput.selectedIndex]
                : null;
            var head = selectedOpt ? (selectedOpt.getAttribute('data-head') || stampToInput.value || '') : '';
            stampHeadPreview.textContent = 'Assigned head: ' + (head || '—');
        }

        function openStampDetailModal(type) {
            if (!stampDetailModal) return;
            activeStampType = type || activeStampType || 'approved';
            updateStampFieldVisibility();
            if (stampDetailTitle) stampDetailTitle.textContent = stampTypeLabel(activeStampType) + ' Stamp Details';
            stampDetailModal.hidden = false;
            document.body.classList.add('modal-open');
        }

        function closeStampDetailModal() {
            if (!stampDetailModal) return;
            stampDetailModal.hidden = true;
            if (!sendSuperAdminModal || sendSuperAdminModal.hidden) {
                document.body.classList.remove('modal-open');
            }
        }

        function drawLine(ctx, x1, y1, x2, y2, width) {
            ctx.save();
            ctx.strokeStyle = '#111111';
            ctx.lineWidth = width || 3;
            ctx.beginPath();
            ctx.moveTo(x1, y1);
            ctx.lineTo(x2, y2);
            ctx.stroke();
            ctx.restore();
        }

        function drawTemplateStamp() {
            var c = document.createElement('canvas');
            c.width = 1300;
            c.height = 760;
            var ctx = c.getContext('2d');
            ctx.clearRect(0, 0, c.width, c.height);
            ctx.fillStyle = 'rgba(255,255,255,0)';
            ctx.fillRect(0, 0, c.width, c.height);

            var dateText = formatLongDate(stampDateInput ? stampDateInput.value : '');
            var timeText = formatDisplayTime(stampTimeInput ? stampTimeInput.value : '');
            var byText = (stampByInput && stampByInput.value ? stampByInput.value : '').trim();
            var toText = (stampToInput && stampToInput.value ? stampToInput.value : '').trim();

            ctx.fillStyle = '#111111';
            ctx.textBaseline = 'middle';

            if (activeStampType === 'approved') {
                ctx.font = '700 48px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('Office of the Municipal Mayor', 650, 70);
                ctx.fillText('Solano, Nueva Vizcaya', 650, 120);
                ctx.font = '900 112px Georgia';
                ctx.fillText('APPROVED', 650, 250);
                drawLine(ctx, 110, 305, 1190, 305, 7);
                ctx.font = '700 64px Arial';
                ctx.textAlign = 'left';
                ctx.fillText('Date:', 120, 390);
                drawLine(ctx, 300, 400, 1180, 400, 5);
                ctx.font = '700 68px Arial';
                ctx.fillText(dateText || '__________', 330, 378);
                ctx.font = '700 64px Arial';
                ctx.fillText('By:', 120, 520);
                drawLine(ctx, 240, 530, 1180, 530, 5);
                ctx.font = 'italic 700 64px Georgia';
                ctx.fillText(byText || '__________', 270, 505);
                drawLine(ctx, 110, 600, 1190, 600, 6);
            } else if (activeStampType === 'received') {
                ctx.font = '700 44px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('MUNICIPAL MAYOR\'S OFFICE', 650, 80);
                ctx.font = '900 108px Georgia';
                ctx.fillText('RECEIVED', 650, 220);
                ctx.font = '700 54px Arial';
                ctx.textAlign = 'left';
                ctx.fillText('Date:', 130, 325);
                drawLine(ctx, 300, 335, 1160, 335, 5);
                ctx.font = '700 62px Arial';
                ctx.fillText(dateText || '__________', 330, 315);
                ctx.font = '700 54px Arial';
                ctx.fillText('Time:', 130, 440);
                drawLine(ctx, 300, 450, 1160, 450, 5);
                ctx.font = '700 62px Arial';
                ctx.fillText(timeText || '__________', 330, 430);
                ctx.font = '700 54px Arial';
                ctx.fillText('By:', 130, 555);
                drawLine(ctx, 300, 565, 1160, 565, 5);
                ctx.font = 'italic 700 60px Georgia';
                ctx.fillText(byText || '__________', 330, 540);
                drawLine(ctx, 100, 630, 1180, 630, 6);
                ctx.font = '700 48px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('SOLANO, NUEVA VIZCAYA', 650, 690);
            } else {
                ctx.font = '700 66px Arial';
                ctx.textAlign = 'left';
                ctx.fillText('To', 120, 100);
                drawLine(ctx, 200, 108, 1180, 108, 6);
                ctx.font = '700 60px Arial';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'alphabetic';
                ctx.fillText(toText || '____________________________', 690, 100);
                ctx.textBaseline = 'middle';
                ctx.font = '700 54px Arial';
                ctx.textAlign = 'left';
                ctx.fillText('for your info/comment/appropriate action.', 120, 190);
                ctx.font = '700 58px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('ATTY. PHILIP A. DACAYO', 650, 360);
                ctx.font = '700 52px Arial';
                ctx.fillText('Municipal Mayor', 650, 430);
                drawLine(ctx, 260, 520, 1040, 520, 6);
                ctx.font = '700 58px Arial';
                ctx.fillText('Date', 650, 590);
                ctx.font = '700 56px Arial';
                ctx.textAlign = 'left';
                ctx.fillText(dateText || '__________', 450, 660);
            }
            return c.toDataURL('image/png');
        }

        function updateStampFieldVisibility() {
            if (!stampFieldsGrid) return;
            stampFieldsGrid.querySelectorAll('[data-for-types]').forEach(function(node) {
                var types = (node.getAttribute('data-for-types') || '').split(',');
                var shown = types.indexOf(activeStampType) !== -1;
                node.style.display = shown ? '' : 'none';
            });
            if (stampHeadPreview) {
                stampHeadPreview.style.display = activeStampType === 'endorsement' ? 'block' : 'none';
                updateEndorsementHeadPreview();
            }
            stampTypeButtons.forEach(function(btn) {
                btn.classList.toggle('active', btn.getAttribute('data-stamp-type') === activeStampType);
            });
            if (activeStampTypeLabel) activeStampTypeLabel.textContent = stampTypeLabel(activeStampType);
        }

        function refreshGeneratedStamp() {
            generatedStampData = drawTemplateStamp();
            if (sendStampNode) {
                sendStampNode.src = generatedStampData;
            }
        }

        function getSendStampBounds() {
            if (!sendStampNode || !sendSuperAdminContainer) {
                return { minX: 5, maxX: 95, minY: 5, maxY: 95 };
            }
            var rect = sendSuperAdminContainer.getBoundingClientRect();
            if (!rect || rect.width <= 0 || rect.height <= 0) {
                return { minX: 5, maxX: 95, minY: 5, maxY: 95 };
            }
            var stampRect = sendStampNode.getBoundingClientRect();
            var stampW = stampRect.width || 0;
            var stampH = stampRect.height || 0;
            if (stampW <= 0 || stampH <= 0) {
                return { minX: 5, maxX: 95, minY: 5, maxY: 95 };
            }
            var rad = ((parseFloat(sendStampCfg.tilt || 0) || 0) * Math.PI) / 180;
            var cos = Math.abs(Math.cos(rad));
            var sin = Math.abs(Math.sin(rad));
            var rotW = (stampW * cos) + (stampH * sin);
            var rotH = (stampW * sin) + (stampH * cos);
            var halfWPercent = (rotW / 2 / rect.width) * 100;
            var halfHPercent = (rotH / 2 / rect.height) * 100;
            halfWPercent = clamp(halfWPercent, 1, 49);
            halfHPercent = clamp(halfHPercent, 1, 49);
            return { minX: halfWPercent, maxX: 100 - halfWPercent, minY: halfHPercent, maxY: 100 - halfHPercent };
        }

        function applySendStampStyles() {
            if (!sendStampNode) return;
            var bounds = getSendStampBounds();
            sendStampCfg.x = clamp(sendStampCfg.x, bounds.minX, bounds.maxX);
            sendStampCfg.y = clamp(sendStampCfg.y, bounds.minY, bounds.maxY);
            sendStampNode.style.width = sendStampCfg.width + '%';
            sendStampNode.style.left = sendStampCfg.x + '%';
            sendStampNode.style.top = sendStampCfg.y + '%';
            sendStampNode.style.transform = 'translate(-50%, -50%) rotate(' + sendStampCfg.tilt + 'deg)';
            if (sendSuperAdminSizeRange) sendSuperAdminSizeRange.value = String(Math.round(sendStampCfg.width));
            if (sendSuperAdminSizeLabel) sendSuperAdminSizeLabel.textContent = Math.round(sendStampCfg.width) + '%';
            if (sendSuperAdminTiltRange) sendSuperAdminTiltRange.value = String(Math.round(sendStampCfg.tilt));
            if (sendSuperAdminTiltLabel) sendSuperAdminTiltLabel.textContent = Math.round(sendStampCfg.tilt) + '°';
        }

        function ensureSendStampNode() {
            if (!sendSuperAdminContainer) return null;
            var src = generatedStampData;
            if (!src) return null;
            if (!sendStampNode) {
                sendStampNode = document.createElement('img');
                sendStampNode.className = 'send-stamp-overlay';
                sendStampNode.alt = 'Stamp';
                sendStampNode.src = src;
                sendSuperAdminContainer.appendChild(sendStampNode);
            }
            return sendStampNode;
        }

        function openSendSuperAdminModal(docId, docName) {
            if (!sendSuperAdminModal || !sendSuperAdminContainer) return;
            sendStampCfg = { width: 18, x: 82, y: 84, tilt: 0 };
            sendStampNode = null;
            generatedStampData = '';
            if (sendSuperAdminDocId) sendSuperAdminDocId.value = docId || '';
            if (sendSuperAdminStampImageData) sendSuperAdminStampImageData.value = '';
            activeStampType = 'approved';
            updateStampFieldVisibility();
            var now = new Date();
            if (stampDateInput) stampDateInput.value = toYmdLocal(now);
            if (stampTimeInput) stampTimeInput.value = toHmLocal(now);
            if (stampByInput) stampByInput.value = <?php echo json_encode((string)$userName); ?>;
            if (stampToInput) stampToInput.value = '';
            updateStampFieldVisibility();
            sendSuperAdminModal.hidden = false;
            document.body.classList.add('modal-open');
            sendSuperAdminLoading.style.display = 'block';
            sendSuperAdminError.style.display = 'none';
            sendSuperAdminContainer.style.display = 'none';
            sendSuperAdminContainer.innerHTML = '';
            if (sendSuperAdminDownloadLink) {
                sendSuperAdminDownloadLink.href = 'documents.php?download=' + encodeURIComponent(docId || '');
                sendSuperAdminDownloadLink.style.display = 'inline-flex';
            }
            var title = document.getElementById('send-super-admin-title');
            if (title) title.textContent = 'Prepare: ' + (docName || 'Document');

            fetch('documents.php?view=' + encodeURIComponent(docId || ''))
                .then(function(res) {
                    if (!res.ok) throw new Error('Load failed');
                    return res.blob();
                })
                .then(function(blob) {
                    sendSuperAdminLoading.style.display = 'none';
                    if (typeof docx !== 'undefined' && docx.renderAsync) {
                        return docx.renderAsync(blob, sendSuperAdminContainer).then(function() {
                            sendSuperAdminContainer.style.display = 'block';
                        });
                    }
                    sendSuperAdminError.textContent = 'Document viewer not available.';
                    sendSuperAdminError.style.display = 'block';
                })
                .catch(function() {
                    sendSuperAdminLoading.style.display = 'none';
                    sendSuperAdminError.textContent = 'Could not load document.';
                    sendSuperAdminError.style.display = 'block';
                });
        }

        function closeSendSuperAdminModal() {
            if (!sendSuperAdminModal) return;
            sendSuperAdminModal.hidden = true;
            document.body.classList.remove('modal-open');
            if (sendSuperAdminContainer) {
                sendSuperAdminContainer.innerHTML = '';
                sendSuperAdminContainer.style.display = 'none';
            }
            if (sendSuperAdminLoading) sendSuperAdminLoading.style.display = 'block';
            if (sendSuperAdminError) sendSuperAdminError.style.display = 'none';
            sendStampNode = null;
            closeStampDetailModal();
        }

        document.querySelectorAll('.documents-send-dropdown-item[data-send-action="super"]').forEach(function(item) {
            item.addEventListener('click', function() {
                var docId = this.getAttribute('data-document-id') || '';
                var docName = this.getAttribute('data-doc-name') || 'document.docx';
                closeAllSendDropdowns();
                openSendSuperAdminModal(docId, docName);
            });
        });

        document.querySelectorAll('[data-close-send-super-admin]').forEach(function(btn) {
            btn.addEventListener('click', closeSendSuperAdminModal);
        });

        stampTypeButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                openStampDetailModal(btn.getAttribute('data-stamp-type') || 'approved');
            });
        });

        document.querySelectorAll('[data-close-stamp-detail]').forEach(function(btn) {
            btn.addEventListener('click', closeStampDetailModal);
        });

        if (stampDetailApply) {
            stampDetailApply.addEventListener('click', function() {
                if (activeStampType === 'endorsement' && (!stampToInput || !stampToInput.value)) {
                    alert('Please select a department with an assigned head.');
                    return;
                }
                refreshGeneratedStamp();
                ensureSendStampNode();
                applySendStampStyles();
                closeStampDetailModal();
            });
        }

        if (sendSuperAdminSizeRange) {
            sendSuperAdminSizeRange.addEventListener('input', function() {
                sendStampCfg.width = clamp(parseFloat(sendSuperAdminSizeRange.value || '18') || 18, 5, 60);
                applySendStampStyles();
            });
        }
        if (sendSuperAdminTiltRange) {
            sendSuperAdminTiltRange.addEventListener('input', function() {
                sendStampCfg.tilt = clamp(parseFloat(sendSuperAdminTiltRange.value || '0') || 0, -35, 35);
                applySendStampStyles();
            });
        }
        if (stampToInput) {
            stampToInput.addEventListener('change', updateEndorsementHeadPreview);
            stampToInput.addEventListener('input', updateEndorsementHeadPreview);
        }

        if (sendSuperAdminContainer) {
            var draggingStamp = false;
            function moveSendStamp(clientX, clientY) {
                if (!sendStampNode || !sendSuperAdminContainer) return;
                var rect = sendSuperAdminContainer.getBoundingClientRect();
                if (rect.width <= 0 || rect.height <= 0) return;
                var bounds = getSendStampBounds();
                sendStampCfg.x = clamp(((clientX - rect.left) / rect.width) * 100, bounds.minX, bounds.maxX);
                sendStampCfg.y = clamp(((clientY - rect.top) / rect.height) * 100, bounds.minY, bounds.maxY);
                applySendStampStyles();
            }
            sendSuperAdminContainer.addEventListener('mousedown', function(e) {
                if (!sendStampNode) return;
                if (e.target === sendStampNode) {
                    draggingStamp = true;
                    e.preventDefault();
                }
            });
            window.addEventListener('mousemove', function(e) {
                if (!draggingStamp) return;
                moveSendStamp(e.clientX, e.clientY);
            });
            window.addEventListener('mouseup', function() { draggingStamp = false; });
        }

        if (sendSuperAdminSubmit) {
            sendSuperAdminSubmit.addEventListener('click', function() {
                if (!sendSuperAdminForm || !sendSuperAdminDocId || !sendSuperAdminDocId.value) return;
                if (!generatedStampData) {
                    alert('Please choose a stamp type and click Apply first.');
                    return;
                }
                sendSuperAdminWidth.value = String(sendStampCfg.width.toFixed(2));
                sendSuperAdminX.value = String(sendStampCfg.x.toFixed(2));
                sendSuperAdminY.value = String(sendStampCfg.y.toFixed(2));
                var tilt = parseFloat(sendStampCfg.tilt || 0) || 0;
                if (Math.abs(tilt) < 0.01) {
                    if (sendSuperAdminStampImageData) sendSuperAdminStampImageData.value = generatedStampData;
                    sendSuperAdminForm.submit();
                    return;
                }
                var img = new Image();
                img.onload = function() {
                    var rad = tilt * Math.PI / 180;
                    var sin = Math.abs(Math.sin(rad));
                    var cos = Math.abs(Math.cos(rad));
                    var srcW = img.width;
                    var srcH = img.height;
                    var outW = Math.ceil((srcW * cos) + (srcH * sin));
                    var outH = Math.ceil((srcW * sin) + (srcH * cos));
                    var c = document.createElement('canvas');
                    c.width = outW;
                    c.height = outH;
                    var ctx = c.getContext('2d');
                    ctx.clearRect(0, 0, outW, outH);
                    ctx.translate(outW / 2, outH / 2);
                    ctx.rotate(rad);
                    ctx.drawImage(img, -srcW / 2, -srcH / 2);
                    if (sendSuperAdminStampImageData) sendSuperAdminStampImageData.value = c.toDataURL('image/png');
                    sendSuperAdminForm.submit();
                };
                img.onerror = function() {
                    if (sendSuperAdminStampImageData) sendSuperAdminStampImageData.value = generatedStampData;
                    sendSuperAdminForm.submit();
                };
                img.src = generatedStampData;
            });
        }

        document.querySelectorAll('.documents-send-dropdown-item[data-send-action="heads"]').forEach(function(item) {
            item.addEventListener('click', function(e) {
                var docId = this.getAttribute('data-document-id') || '';
                closeAllSendDropdowns();
                openSendModalForDoc(docId);
            });
        });

        document.addEventListener('click', function() {
            closeAllSendDropdowns();
        });

        if (sendHeadsList) {
            sendHeadsList.addEventListener('change', function(e) {
                if (e.target.classList.contains('send-head-cb')) updateSendSelection();
            });
        }
        if (sendSelectAllBtn && sendHeadsList) {
            sendSelectAllBtn.addEventListener('click', function() {
                sendHeadsList.querySelectorAll('.send-head-cb').forEach(function(cb) { cb.checked = true; });
                updateSendSelection();
            });
        }
        if (sendClearAllBtn && sendHeadsList) {
            sendClearAllBtn.addEventListener('click', function() {
                sendHeadsList.querySelectorAll('.send-head-cb').forEach(function(cb) { cb.checked = false; });
                updateSendSelection();
            });
        }

        function closeSendDocumentModal() {
            if (sendModal) {
                sendModal.hidden = true;
                document.body.classList.remove('modal-open');
                if (sendDocumentIdInput) sendDocumentIdInput.value = '';
            }
        }
        document.querySelectorAll('[data-close-send-document]').forEach(function(btn) {
            btn.addEventListener('click', closeSendDocumentModal);
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sendModal && !sendModal.hidden) closeSendDocumentModal();
            if (e.key === 'Escape' && stampDetailModal && !stampDetailModal.hidden) {
                closeStampDetailModal();
                return;
            }
            if (e.key === 'Escape' && sendSuperAdminModal && !sendSuperAdminModal.hidden) closeSendSuperAdminModal();
        });

        // Document view modal (same as Super Admin)
        var documentViewModal = document.getElementById('document-view-modal');
        var documentViewTitle = document.getElementById('document-view-title');
        var documentViewContainer = document.getElementById('document-view-container');
        var documentViewLoading = document.getElementById('document-view-loading');
        var documentViewError = document.getElementById('document-view-error');
        var documentViewDownloadLink = document.getElementById('document-view-download-link');

        function applyStampOverlay(stampCfg) {
            if (!documentViewContainer || !stampCfg || !stampCfg.image) return;
            var stamp = document.createElement('img');
            stamp.className = 'send-stamp-overlay';
            stamp.style.pointerEvents = 'none';
            stamp.style.cursor = 'default';
            stamp.src = stampCfg.image;
            stamp.alt = 'Document stamp';
            var width = Math.max(5, Math.min(60, parseFloat(stampCfg.width) || 18));
            var x = Math.max(5, Math.min(95, parseFloat(stampCfg.x) || 82));
            var y = Math.max(5, Math.min(95, parseFloat(stampCfg.y) || 84));
            stamp.style.width = width + '%';
            stamp.style.left = x + '%';
            stamp.style.top = y + '%';
            documentViewContainer.appendChild(stamp);
        }

        function openDocumentViewModal(docId, docName, stampCfg) {
            if (!documentViewModal || !documentViewContainer) return;
            documentViewModal.hidden = false;
            document.body.classList.add('modal-open');
            documentViewTitle.textContent = docName || 'Document';
            documentViewLoading.style.display = 'block';
            documentViewContainer.style.display = 'none';
            documentViewContainer.innerHTML = '';
            documentViewError.style.display = 'none';
            if (documentViewDownloadLink) {
                documentViewDownloadLink.href = 'documents.php?download=' + encodeURIComponent(docId);
                documentViewDownloadLink.style.display = 'inline-flex';
            }

            var viewUrl = 'documents.php?view=' + encodeURIComponent(docId);
            fetch(viewUrl)
                .then(function(res) {
                    if (!res.ok) throw new Error('Load failed');
                    var ct = (res.headers.get('Content-Type') || '').toLowerCase();
                    if (ct.indexOf('wordprocessingml') === -1 && ct.indexOf('octet-stream') === -1) {
                        return res.text().then(function() { throw new Error('Invalid response'); });
                    }
                    return res.blob();
                })
                .then(function(blob) {
                    documentViewLoading.style.display = 'none';
                    if (typeof docx !== 'undefined' && docx.renderAsync) {
                        return docx.renderAsync(blob, documentViewContainer).then(function() {
                            applyStampOverlay(stampCfg);
                            documentViewContainer.style.display = 'block';
                        }).catch(function(err) {
                            documentViewError.textContent = 'Could not render document.';
                            documentViewError.style.display = 'block';
                        });
                    }
                    documentViewError.textContent = 'Document viewer not available.';
                    documentViewError.style.display = 'block';
                })
                .catch(function() {
                    documentViewLoading.style.display = 'none';
                    documentViewError.textContent = 'Could not load document.';
                    documentViewError.style.display = 'block';
                });
        }

        function closeDocumentViewModal() {
            if (!documentViewModal) return;
            documentViewModal.hidden = true;
            document.body.classList.remove('modal-open');
            if (documentViewContainer) {
                documentViewContainer.innerHTML = '';
                documentViewContainer.style.display = 'none';
            }
            if (documentViewLoading) documentViewLoading.style.display = 'block';
            if (documentViewError) documentViewError.style.display = 'none';
            if (documentViewDownloadLink) documentViewDownloadLink.style.display = 'none';
        }

        document.querySelectorAll('.document-view-trigger').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.preventDefault();
                var docId = el.getAttribute('data-doc-id');
                var docName = el.getAttribute('data-doc-name') || 'document.docx';
                var stampCfg = {
                    image: el.getAttribute('data-stamp-image') || '',
                    width: el.getAttribute('data-stamp-width') || '18',
                    x: el.getAttribute('data-stamp-x') || '82',
                    y: el.getAttribute('data-stamp-y') || '84'
                };
                if (docId) openDocumentViewModal(docId, docName, stampCfg);
            });
        });

        document.querySelectorAll('[data-close-document-view]').forEach(function(btn) {
            btn.addEventListener('click', closeDocumentViewModal);
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && documentViewModal && !documentViewModal.hidden) {
                closeDocumentViewModal();
            }
        });
    })();
    </script>
</body>
</html>
