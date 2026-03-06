<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/_account_helpers.php';
require_once __DIR__ . '/_activity_logger.php';

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'Super Admin';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$sidebar_active = 'documents';
$welcomeUsername = getUserUsername($_SESSION['user_id'] ?? '') ?: ($_SESSION['user_username'] ?? $userName) ?: 'User';

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_notifications_super_admin.php';
$notifData = getSuperAdminNotifications($config);
$notifCount = $notifData['count'];
$notifItems = $notifData['items'];

// View document (return docx for in-browser viewer – no download)
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
                header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                header('Content-Disposition: inline; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName) . '"');
                echo base64_decode($fileContent, true) ?: $fileContent;
                exit;
            }
        }
    } catch (Exception $e) {}
    header('HTTP/1.1 404 Not Found');
    exit;
}

// Download document (file stored in documents collection)
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
                header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName) . '"');
                echo base64_decode($fileContent, true) ?: $fileContent;
                exit;
            }
        }
    } catch (Exception $e) {}
    header('HTTP/1.1 404 Not Found');
    exit;
}

// Fetch exact saved stamp config for a sent record.
if (isset($_GET['stamp_for_sent'])) {
    header('Content-Type: application/json; charset=utf-8');
    $sentRecordId = trim((string)($_GET['stamp_for_sent'] ?? ''));
    if ($sentRecordId === '' || !ctype_digit($sentRecordId)) {
        echo json_encode(['ok' => false]);
        exit;
    }
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare(
            'SELECT stamp_image, stamp_width_pct, stamp_x_pct, stamp_y_pct
             FROM sent_to_super_admin
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => (int)$sentRecordId]);
        $row = $stmt->fetch();
        if ($row) {
            echo json_encode([
                'ok' => true,
                'image' => (string)($row['stamp_image'] ?? ''),
                'width' => (string)($row['stamp_width_pct'] ?? ''),
                'x' => (string)($row['stamp_x_pct'] ?? ''),
                'y' => (string)($row['stamp_y_pct'] ?? ''),
            ]);
            exit;
        }
    } catch (Exception $e) {
        // Fall through to not-found response.
    }
    echo json_encode(['ok' => false]);
    exit;
}

// Send document to Admin Side with per-document stamp placement.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_to_admin') {
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
        ensureSuperAdminStampColumns($config);
        $docStmt = $pdo->prepare('SELECT document_code, document_title, file_name FROM documents WHERE id = :id LIMIT 1');
        $docStmt->execute([':id' => $sendId]);
        $doc = $docStmt->fetch();
        if ($doc) {
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
            $ins = $pdo->prepare(
                'INSERT INTO sent_to_admin
                    (document_id, document_code, document_title, file_name, stamp_image, stamp_width_pct, stamp_x_pct, stamp_y_pct, sent_by_user_id, sent_by_user_name, sent_at)
                 VALUES
                    (:document_id, :document_code, :document_title, :file_name, :stamp_image, :stamp_width_pct, :stamp_x_pct, :stamp_y_pct, :sent_by_user_id, :sent_by_user_name, :sent_at)'
            );
            $ins->execute([
                ':document_id' => $sendId,
                ':document_code' => $docCode,
                ':document_title' => $docTitle,
                ':file_name' => $fileName,
                ':stamp_image' => $stampImage,
                ':stamp_width_pct' => $stampWidth,
                ':stamp_x_pct' => $stampX,
                ':stamp_y_pct' => $stampY,
                ':sent_by_user_id' => $_SESSION['user_id'] ?? '',
                ':sent_by_user_name' => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
                ':sent_at' => dbNowUtcString(),
            ]);
            activityLog($config, 'document_send_to_admin', [
                'module' => 'super_admin_documents',
                'document_id' => $sendId,
                'document_code' => (string)$docCode,
                'document_title' => (string)$docTitle,
            ]);
        }
    } catch (Exception $e) {}
    header('Location: documents.php?sent=1');
    exit;
}

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
            $del = $pdo->prepare('DELETE FROM sent_to_super_admin WHERE document_id = :document_id');
            $del->execute([':document_id' => $archiveId]);
            activityLog($config, 'document_archive', [
                'module' => 'super_admin_documents',
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

// Add document (POST) – save to database
$addError = null;
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
                    $ins = $pdo->prepare(
                        'INSERT INTO documents
                            (id, document_code, document_title, file_name, file_content, created_at, created_by, status)
                         VALUES
                            (:id, :document_code, :document_title, :file_name, :file_content, :created_at, :created_by, :status)'
                    );
                    $ins->execute([
                        ':id' => dbGenerateId24(),
                        ':document_code' => $documentCode,
                        ':document_title' => $documentTitle,
                        ':file_name' => $fname,
                        ':file_content' => $fileContent,
                        ':created_at' => dbNowUtcString(),
                        ':created_by' => $_SESSION['user_id'] ?? '',
                        ':status' => 'active',
                    ]);
                    activityLog($config, 'document_add', [
                        'module' => 'super_admin_documents',
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
        $_SESSION['super_admin_doc_add_error'] = $addError;
        header('Location: documents.php?add_error=1');
        exit;
    }
}

if (isset($_GET['add_error']) && isset($_SESSION['super_admin_doc_add_error'])) {
    $addError = $_SESSION['super_admin_doc_add_error'];
    unset($_SESSION['super_admin_doc_add_error']);
}

$sentList = [];
$idsInList = [];
$endorsementDepartments = [];
try {
    $pdo = dbPdo($config);
    // Load every sent record (one row per send – no deduplication by documentId)
    // Deterministic ordering: when multiple sends share the same second,
    // always keep the latest inserted row first.
    $stmt = $pdo->query('SELECT * FROM sent_to_super_admin ORDER BY sent_at DESC, id DESC LIMIT 500');
    foreach ($stmt as $arr) {
        $arr['documentId'] = (string)($arr['document_id'] ?? '');
        $arr['sentRecordId'] = (string)($arr['id'] ?? uniqid('sent-', true));
        $arr['documentCode'] = $arr['document_code'] ?? '';
        $arr['documentTitle'] = $arr['document_title'] ?? '';
        $arr['fileName'] = $arr['file_name'] ?? 'document.docx';
        $idsInList[$arr['documentId']] = true;
        $dtTs = dbToTimestamp($arr['sent_at'] ?? null);
        if ($dtTs !== null) {
            $arr['sentAtFormatted'] = (new DateTime('@' . $dtTs))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A');
        } else {
            $arr['sentAtFormatted'] = '—';
        }
        $sentList[] = $arr;
    }
    // Add documents created by this Super Admin (saved via Add Document)
    $currentUserId = $_SESSION['user_id'] ?? '';
    if ($currentUserId !== '') {
        $docStmt = $pdo->prepare(
            'SELECT * FROM documents WHERE created_by = :created_by AND status <> :status ORDER BY created_at DESC LIMIT 500'
        );
        $docStmt->execute([':created_by' => $currentUserId, ':status' => 'archived']);
        foreach ($docStmt as $d) {
            $docId = (string)($d['id'] ?? '');
            if ($docId === '' || isset($idsInList[$docId])) continue;
            $idsInList[$docId] = true;
            $sentList[] = [
                'documentId'       => $docId,
                'sentRecordId'     => $docId,
                'documentCode'    => $d['document_code'] ?? '—',
                'documentTitle'   => $d['document_title'] ?? '—',
                'fileName'        => $d['file_name'] ?? 'document.docx',
                'status'          => $d['status'] ?? 'active',
                'sentAtFormatted' => '—',
                'stamp_image' => '',
                'stamp_width_pct' => 18,
                'stamp_x_pct' => 82,
                'stamp_y_pct' => 84,
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
    $sentList = [];
    $endorsementDepartments = [];
}

$currentUserStampCfg = getUserStampConfig($_SESSION['user_id'] ?? '');
$currentUserStamp = trim((string)($currentUserStampCfg['stamp'] ?? ''));
$showSentToast = isset($_GET['sent']) && $_GET['sent'] === '1';
$showAddedToast = isset($_GET['added']) && $_GET['added'] === '1';
$showSendErrorToast = isset($_GET['send_error']) && $_GET['send_error'] === '1';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Documents</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="profile_modal_super_admin.css">
    <link rel="stylesheet" href="../Admin Side/admin-dashboard.css">
    <link rel="stylesheet" href="../Admin Side/admin-offices.css">
    <link rel="stylesheet" href="sidebar_super_admin.css">
    <style>
        body { margin: 0; background: #f8fafc; color: #0f172a; }
        .main-content { display: flex; flex-direction: column; flex: 1; min-height: 0; background: #fff; }
        .content-header { background: #fff; padding: 1.5rem 2.2rem; border-bottom: 1px solid #e2e8f0; flex-shrink: 0; }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; }
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
        .documents-actions-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .documents-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px; border: none; font-size: 13px; font-weight: 500; cursor: pointer; font-family: inherit; transition: background 0.15s, color 0.15s; text-decoration: none; color: inherit; }
        .documents-action-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
        .documents-action-open { background: #dbeafe; color: #1d4ed8; }
        .documents-action-open:hover { background: #bfdbfe; color: #1d4ed8; }
        .documents-action-send { background: #d1fae5; color: #047857; }
        .documents-action-send:hover { background: #a7f3d0; color: #047857; }
        .document-status { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; text-transform: capitalize; }
        .document-status-active { background: #d1fae5; color: #047857; }
        .document-status-archived { background: #f3f4f6; color: #6b7280; }
        tr.doc-row-highlight { background: #dbeafe !important; box-shadow: inset 0 0 0 2px #3b82f6; }
        .doc-modal-dialog-view { max-width: 94%; width: 980px; max-height: 92vh; display: flex; flex-direction: column; }
        .document-view-body { flex: 1; min-height: 0; overflow: hidden; padding: 0.9rem; background: #f8fafc; border-top: 1px solid #e2e8f0; display: grid; grid-template-rows: minmax(0, 1fr) auto auto; gap: 10px; }
        .document-view-container { overflow: auto; max-height: none; min-height: 360px; padding: 1rem; background: #fff; border-radius: 10px; border: 1px solid #e5e7eb; position: relative; }
        .document-stamp-overlay { position: absolute; pointer-events: none; user-select: none; transform: translate(-50%, -50%); z-index: 20; object-fit: contain; max-width: none; max-height: none; opacity: 0.95; }
        .send-stamp-overlay { position: absolute; pointer-events: auto; user-select: none; transform: translate(-50%, -50%); z-index: 25; object-fit: contain; max-width: none; max-height: none; cursor: move; touch-action: none; opacity: 0.95; }
        .stamp-template-box { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; background: #fff; }
        .stamp-template-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 8px; }
        .stamp-template-btn { border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; border-radius: 8px; padding: 9px 12px; font-size: 12px; font-weight: 700; cursor: pointer; text-align: center; }
        .stamp-template-btn.active { background: #dbeafe; border-color: #60a5fa; color: #1d4ed8; box-shadow: inset 0 0 0 1px #93c5fd; }
        .stamp-template-current { font-size: 12px; color: #475569; margin: 0; line-height: 1.35; }
        .stamp-fields-grid { display: grid; gap: 8px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .stamp-fields-grid .full { grid-column: 1 / -1; }
        .stamp-fields-grid label { display: grid; gap: 4px; font-size: 12px; color: #334155; font-weight: 600; }
        .stamp-fields-grid input, .stamp-fields-grid textarea, .stamp-fields-grid select { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 7px 9px; font-size: 12px; font-family: inherit; }
        .stamp-fields-grid textarea { resize: vertical; min-height: 56px; }
        .stamp-auto-head { margin-top: 2px; font-size: 11px; color: #64748b; font-weight: 600; }
        .stamp-detail-note { margin: 0 0 12px 0; font-size: 12px; color: #64748b; }
        .stamp-adjust-wrap { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
        .stamp-adjust-wrap label { font-size: 13px; font-weight: 700; color: #334155; white-space: nowrap; }
        .stamp-adjust-wrap input[type="range"] { flex: 1; accent-color: #2563eb; }
        .stamp-adjust-wrap span { min-width: 46px; text-align: right; font-size: 13px; font-weight: 700; color: #334155; }
        .stamp-tilt-wrap { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
        .stamp-tilt-wrap label { font-size: 13px; font-weight: 700; color: #334155; white-space: nowrap; }
        .stamp-tilt-wrap input[type="range"] { flex: 1; accent-color: #2563eb; }
        .stamp-tilt-wrap span { min-width: 52px; text-align: right; font-size: 13px; font-weight: 700; color: #334155; }
        .document-view-loading, .document-view-error { padding: 2rem; text-align: center; color: #64748b; }
        .document-view-error { color: #dc2626; }
        .doc-modal-footer { flex-shrink: 0; padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; gap: 10px; }
        .doc-btn-download { display: inline-flex; align-items: center; gap: 6px; text-decoration: none; color: #fff; background: #1d4ed8; border-radius: 8px; padding: 8px 16px; font-size: 14px; font-weight: 500; cursor: pointer; border: none; }
        .doc-btn-download:hover { background: #1e40af; color: #fff; }
        @media (max-width: 900px) {
            .doc-modal-dialog-view { width: 96vw; max-width: 96vw; }
            .document-view-container { min-height: 280px; }
            .stamp-template-row { grid-template-columns: 1fr; }
            .stamp-adjust-wrap { flex-wrap: wrap; }
            .stamp-adjust-wrap label { width: 100%; }
        }
    </style>
</head>
<body<?php if (!empty($showSentToast)): ?> data-sent="1"<?php endif; ?><?php if (!empty($showAddedToast)): ?> data-added="1"<?php endif; ?><?php if (!empty($addError)): ?> data-add-error="1"<?php endif; ?><?php if (!empty($showSendErrorToast)): ?> data-send-error="1"<?php endif; ?>>
    <div class="dashboard-container">
        <?php include __DIR__ . '/_sidebar_super_admin.php'; ?>

        <div class="main-content">
            <div class="content-header">
                <div class="dashboard-header">
                    <div>
                        <h1>Welcome, <?php echo htmlspecialchars($welcomeUsername); ?>!</h1>
                        <small>Municipal Document Management System – Documents</small>
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
                    <div class="offices-tools doc-filter-row">
                        <input type="text" placeholder="Search" aria-label="Search document">
                        <input type="date" aria-label="From date">
                        <input type="date" aria-label="To date">
                        <button type="button" class="offices-btn" id="open-add-document-modal">
                            <svg class="offices-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                            Add Document
                        </button>
                        <button type="button" class="offices-btn offices-btn-secondary" id="edit-document-btn">
                            <svg class="offices-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            Edit
                        </button>
                    </div>

                    <div class="offices-table-frame">
                        <table class="offices-table">
                            <thead>
                                <tr>
                                    <th>NO.</th>
                                    <th>DOCUMENT CODE</th>
                                    <th>DOCUMENT TITLE</th>
                                    <th>DOCX FILE</th>
                                    <th>STATUS</th>
                                    <th>SENT AT</th>
                                    <th>ACTION</th>
                                </tr>
                            </thead>
                            <tbody id="documents-table-body">
                                <?php if (empty($sentList)): ?>
                                <tr>
                                    <td colspan="7" class="offices-empty" id="no-documents-row">No documents yet.</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($sentList as $idx => $sent):
                                    $docId = $sent['documentId'];
                                    $sentRecordId = $sent['sentRecordId'] ?? $docId;
                                    $sentStatus = isset($sent['status']) ? ucfirst(strtolower($sent['status'])) : 'Active';
                                ?>
                                <tr data-document-row id="doc-row-<?= htmlspecialchars($sentRecordId) ?>" data-document-id="<?= htmlspecialchars($docId) ?>">
                                    <td><?= (int)($idx + 1) ?></td>
                                    <td><?= htmlspecialchars($sent['documentCode'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($sent['documentTitle'] ?? '—') ?></td>
                                    <td><a href="documents.php?view=<?= urlencode($docId) ?>" class="doc-file-link document-view-trigger" data-doc-id="<?= htmlspecialchars($docId) ?>" data-doc-name="<?= htmlspecialchars($sent['fileName'] ?? 'document.docx') ?>" data-sent-record-id="<?= htmlspecialchars((string)($sent['sentRecordId'] ?? '')) ?>" data-stamp-image="<?= htmlspecialchars((string)($sent['stamp_image'] ?? '')) ?>" data-stamp-width="<?= htmlspecialchars((string)($sent['stamp_width_pct'] ?? '18')) ?>" data-stamp-x="<?= htmlspecialchars((string)($sent['stamp_x_pct'] ?? '82')) ?>" data-stamp-y="<?= htmlspecialchars((string)($sent['stamp_y_pct'] ?? '84')) ?>"><?= htmlspecialchars($sent['fileName'] ?? 'document.docx') ?></a></td>
                                    <td><span class="document-status document-status-<?= strtolower(htmlspecialchars($sentStatus)) ?>"><?= htmlspecialchars($sentStatus) ?></span></td>
                                    <td><?= htmlspecialchars($sent['sentAtFormatted'] ?? '—') ?></td>
                                    <td>
                                        <div class="documents-actions-row">
                                            <a href="documents.php?view=<?= urlencode($docId) ?>" class="documents-action-btn documents-action-open document-view-trigger" data-doc-id="<?= htmlspecialchars($docId) ?>" data-doc-name="<?= htmlspecialchars($sent['fileName'] ?? 'document.docx') ?>" data-sent-record-id="<?= htmlspecialchars((string)($sent['sentRecordId'] ?? '')) ?>" data-stamp-image="<?= htmlspecialchars((string)($sent['stamp_image'] ?? '')) ?>" data-stamp-width="<?= htmlspecialchars((string)($sent['stamp_width_pct'] ?? '18')) ?>" data-stamp-x="<?= htmlspecialchars((string)($sent['stamp_x_pct'] ?? '82')) ?>" data-stamp-y="<?= htmlspecialchars((string)($sent['stamp_y_pct'] ?? '84')) ?>" title="View document"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</a>
                                            <button type="button" class="documents-action-btn documents-action-send send-admin-trigger" data-doc-id="<?= htmlspecialchars($docId) ?>" data-doc-name="<?= htmlspecialchars($sent['fileName'] ?? 'document.docx') ?>" title="Send to Admin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Send to Admin</button>
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

    <div class="doc-modal" id="send-admin-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-send-admin aria-label="Close"></button>
        <div class="doc-modal-dialog doc-modal-dialog-view" role="dialog" aria-modal="true" aria-labelledby="send-admin-title">
            <div class="doc-modal-header">
                <h2 id="send-admin-title">Prepare Document for Admin</h2>
                <button type="button" class="doc-modal-close" data-close-send-admin aria-label="Close">&times;</button>
            </div>
            <div class="document-view-body">
                <div id="send-admin-loading" class="document-view-loading">Loading document...</div>
                <div id="send-admin-container" class="document-view-container" style="display:none;"></div>
                <div id="send-admin-error" class="document-view-error" style="display:none;">Could not load document.</div>
                <div class="stamp-template-box">
                    <div class="stamp-template-row">
                        <button type="button" class="stamp-template-btn active" data-stamp-type="approved">Approved</button>
                        <button type="button" class="stamp-template-btn" data-stamp-type="received">Received</button>
                        <button type="button" class="stamp-template-btn" data-stamp-type="endorsement">Endorsement</button>
                    </div>
                    <p class="stamp-template-current">Selected stamp: <strong id="active-stamp-type-label">Approved</strong>. Click a stamp button to open input modal.</p>
                </div>
                <div class="stamp-adjust-wrap">
                    <label for="send-admin-stamp-size">Stamp size</label>
                    <input type="range" id="send-admin-stamp-size" min="5" max="60" step="1" value="18">
                    <span id="send-admin-stamp-size-label">18%</span>
                </div>
                <div class="stamp-tilt-wrap">
                    <label for="send-admin-stamp-tilt">Tilt</label>
                    <input type="range" id="send-admin-stamp-tilt" min="-35" max="35" step="1" value="0">
                    <span id="send-admin-stamp-tilt-label">0°</span>
                </div>
            </div>
            <div class="doc-modal-footer doc-modal-actions">
                <a id="send-admin-download-link" href="#" class="doc-btn doc-btn-download" style="display:none;" target="_blank" rel="noopener" download>Download</a>
                <button type="button" class="doc-btn doc-btn-cancel" data-close-send-admin>Cancel</button>
                <button type="button" class="doc-btn doc-btn-save" id="send-admin-submit">Send to Admin</button>
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
                            <option
                                value="<?= htmlspecialchars($d['head']) ?>"
                                data-head="<?= htmlspecialchars($d['head']) ?>"
                                <?= $d['head'] === '' ? 'disabled' : '' ?>
                            >
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

    <form method="post" action="documents.php" id="send-admin-form" style="display:none;">
        <input type="hidden" name="action" value="send_to_admin">
        <input type="hidden" name="document_id" id="send-admin-doc-id" value="">
        <input type="hidden" name="stamp_image_data" id="send-admin-stamp-image-data" value="">
        <input type="hidden" name="stamp_width_pct" id="send-admin-width" value="18">
        <input type="hidden" name="stamp_x_pct" id="send-admin-x" value="82">
        <input type="hidden" name="stamp_y_pct" id="send-admin-y" value="84">
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
                <a id="document-view-download-link" href="#" class="doc-btn doc-btn-download" style="display:none;" target="_blank" rel="noopener" download>Download</a>
                <button type="button" class="doc-btn doc-btn-save" data-close-document-view>Close</button>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/_profile_modal_super_admin.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/jszip@3/dist/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.0/dist/docx-preview.min.js"></script>
    <script>
    (function() {
        var uploadedStampData = <?php echo json_encode($currentUserStamp); ?> || '';
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

        if (document.body.getAttribute('data-sent') === '1') {
            var toast = document.createElement('div');
            toast.setAttribute('role', 'status');
            toast.textContent = 'Document sent to Admin.';
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#22c55e;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 4000);
        }

        if (document.body.getAttribute('data-added') === '1') {
            var toast = document.createElement('div');
            toast.setAttribute('role', 'status');
            toast.textContent = 'Document saved successfully.';
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#22c55e;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 4000);
        }
        if (document.body.getAttribute('data-send-error') === '1') {
            var errToast = document.createElement('div');
            errToast.setAttribute('role', 'status');
            errToast.textContent = 'Could not send document. Try again.';
            errToast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#ef4444;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(errToast);
            setTimeout(function() { errToast.remove(); }, 4500);
        }

        if (addModal && document.body.getAttribute('data-add-error') === '1') {
            addModal.hidden = false;
            document.body.classList.add('modal-open');
        }

        var highlightId = (function() {
            var m = /[?&]highlight=([^&]+)/.exec(window.location.search);
            return m ? decodeURIComponent(m[1]) : null;
        })();
        if (highlightId) {
            var rows = document.querySelectorAll('tr[data-document-id]');
            var row = null;
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].getAttribute('data-document-id') === highlightId) { row = rows[i]; break; }
            }
            if (row) {
                row.classList.add('doc-row-highlight');
                row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                setTimeout(function() { row.classList.remove('doc-row-highlight'); }, 4000);
            }
        }

        var sendAdminModal = document.getElementById('send-admin-modal');
        var sendAdminContainer = document.getElementById('send-admin-container');
        var sendAdminLoading = document.getElementById('send-admin-loading');
        var sendAdminError = document.getElementById('send-admin-error');
        var sendAdminDownloadLink = document.getElementById('send-admin-download-link');
        var sendAdminSizeRange = document.getElementById('send-admin-stamp-size');
        var sendAdminSizeLabel = document.getElementById('send-admin-stamp-size-label');
        var sendAdminTiltRange = document.getElementById('send-admin-stamp-tilt');
        var sendAdminTiltLabel = document.getElementById('send-admin-stamp-tilt-label');
        var sendAdminSubmit = document.getElementById('send-admin-submit');
        var sendAdminForm = document.getElementById('send-admin-form');
        var sendAdminDocId = document.getElementById('send-admin-doc-id');
        var sendAdminStampImageData = document.getElementById('send-admin-stamp-image-data');
        var sendAdminWidth = document.getElementById('send-admin-width');
        var sendAdminX = document.getElementById('send-admin-x');
        var sendAdminY = document.getElementById('send-admin-y');
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
            if (!sendAdminModal || sendAdminModal.hidden) {
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

        function clamp(value, min, max) {
            return Math.max(min, Math.min(max, value));
        }

        function getStampTargetElement(containerEl) {
            if (!containerEl) return null;
            // Keep one coordinate system across sender/receiver views.
            // Anchor to the rendered DOCX page itself to avoid wrapper padding drift.
            var page = containerEl.querySelector('.docx');
            if (!page) page = containerEl.querySelector('.docx-wrapper');
            if (!page) page = containerEl.firstElementChild;
            if (!page) page = containerEl;
            if (page && (!page.style.position || page.style.position === 'static')) {
                page.style.position = 'relative';
            }
            return page;
        }

        function getSendStampBounds() {
            var targetEl = getStampTargetElement(sendAdminContainer);
            if (!sendStampNode || !targetEl) {
                return { minX: 5, maxX: 95, minY: 5, maxY: 95 };
            }
            var rect = targetEl.getBoundingClientRect();
            if (!rect || rect.width <= 0 || rect.height <= 0) {
                return { minX: 5, maxX: 95, minY: 5, maxY: 95 };
            }

            var stampRect = sendStampNode.getBoundingClientRect();
            var stampW = stampRect.width || 0;
            var stampH = stampRect.height || 0;
            if (stampW <= 0 || stampH <= 0) {
                return { minX: 5, maxX: 95, minY: 5, maxY: 95 };
            }

            // Use rotated box size so the tilted stamp never clips outside the page.
            var rad = ((parseFloat(sendStampCfg.tilt || 0) || 0) * Math.PI) / 180;
            var cos = Math.abs(Math.cos(rad));
            var sin = Math.abs(Math.sin(rad));
            var rotW = (stampW * cos) + (stampH * sin);
            var rotH = (stampW * sin) + (stampH * cos);

            var halfWPercent = (rotW / 2 / rect.width) * 100;
            var halfHPercent = (rotH / 2 / rect.height) * 100;
            halfWPercent = clamp(halfWPercent, 1, 49);
            halfHPercent = clamp(halfHPercent, 1, 49);

            return {
                minX: halfWPercent,
                maxX: 100 - halfWPercent,
                minY: halfHPercent,
                maxY: 100 - halfHPercent
            };
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
            if (sendAdminSizeRange) sendAdminSizeRange.value = String(Math.round(sendStampCfg.width));
            if (sendAdminSizeLabel) sendAdminSizeLabel.textContent = Math.round(sendStampCfg.width) + '%';
            if (sendAdminTiltRange) sendAdminTiltRange.value = String(Math.round(sendStampCfg.tilt));
            if (sendAdminTiltLabel) sendAdminTiltLabel.textContent = Math.round(sendStampCfg.tilt) + '°';
        }

        function ensureSendStampNode() {
            if (!sendAdminContainer) return null;
            var src = generatedStampData;
            if (!src) return null;
            var targetEl = getStampTargetElement(sendAdminContainer);
            if (!targetEl) return null;
            if (!sendStampNode) {
                sendStampNode = document.createElement('img');
                sendStampNode.className = 'send-stamp-overlay';
                sendStampNode.alt = 'Stamp';
                sendStampNode.src = src;
                targetEl.appendChild(sendStampNode);
            } else if (sendStampNode.parentNode !== targetEl) {
                targetEl.appendChild(sendStampNode);
            }
            return sendStampNode;
        }

        function openSendAdminModal(docId, docName) {
            if (!sendAdminModal || !sendAdminContainer) return;
            sendStampCfg = { width: 18, x: 82, y: 84, tilt: 0 };
            sendStampNode = null;
            generatedStampData = '';
            if (sendAdminDocId) sendAdminDocId.value = docId || '';
            if (sendAdminStampImageData) sendAdminStampImageData.value = '';
            activeStampType = 'approved';
            updateStampFieldVisibility();
            var now = new Date();
            if (stampDateInput) stampDateInput.value = toYmdLocal(now);
            if (stampTimeInput) stampTimeInput.value = toHmLocal(now);
            if (stampByInput) stampByInput.value = <?php echo json_encode((string)$welcomeUsername); ?>;
            if (stampToInput) stampToInput.value = '';
            updateStampFieldVisibility();
            sendAdminModal.hidden = false;
            document.body.classList.add('modal-open');
            sendAdminLoading.style.display = 'block';
            sendAdminError.style.display = 'none';
            sendAdminContainer.style.display = 'none';
            sendAdminContainer.innerHTML = '';
            if (sendAdminDownloadLink) {
                sendAdminDownloadLink.href = 'documents.php?download=' + encodeURIComponent(docId || '');
                sendAdminDownloadLink.style.display = 'inline-flex';
            }
            var title = document.getElementById('send-admin-title');
            if (title) title.textContent = 'Prepare: ' + (docName || 'Document');

            fetch('documents.php?view=' + encodeURIComponent(docId || ''))
                .then(function(res) {
                    if (!res.ok) throw new Error('Load failed');
                    return res.blob();
                })
                .then(function(blob) {
                    sendAdminLoading.style.display = 'none';
                    if (typeof docx !== 'undefined' && docx.renderAsync) {
                        return docx.renderAsync(blob, sendAdminContainer).then(function() {
                            sendAdminContainer.style.display = 'block';
                            // Stamp appears only after clicking Apply in the stamp details modal.
                        });
                    }
                    sendAdminError.textContent = 'Document viewer not available.';
                    sendAdminError.style.display = 'block';
                })
                .catch(function() {
                    sendAdminLoading.style.display = 'none';
                    sendAdminError.textContent = 'Could not load document.';
                    sendAdminError.style.display = 'block';
                });
        }

        function closeSendAdminModal() {
            if (!sendAdminModal) return;
            sendAdminModal.hidden = true;
            document.body.classList.remove('modal-open');
            if (sendAdminContainer) {
                sendAdminContainer.innerHTML = '';
                sendAdminContainer.style.display = 'none';
            }
            if (sendAdminLoading) sendAdminLoading.style.display = 'block';
            if (sendAdminError) sendAdminError.style.display = 'none';
            sendStampNode = null;
            closeStampDetailModal();
        }

        document.querySelectorAll('.send-admin-trigger').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var docId = btn.getAttribute('data-doc-id') || '';
                var docName = btn.getAttribute('data-doc-name') || 'document.docx';
                openSendAdminModal(docId, docName);
            });
        });

        document.querySelectorAll('[data-close-send-admin]').forEach(function(btn) {
            btn.addEventListener('click', closeSendAdminModal);
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

        if (sendAdminSizeRange) {
            sendAdminSizeRange.addEventListener('input', function() {
                sendStampCfg.width = clamp(parseFloat(sendAdminSizeRange.value || '18') || 18, 5, 60);
                applySendStampStyles();
            });
        }

        if (sendAdminTiltRange) {
            sendAdminTiltRange.addEventListener('input', function() {
                sendStampCfg.tilt = clamp(parseFloat(sendAdminTiltRange.value || '0') || 0, -35, 35);
                applySendStampStyles();
            });
        }

        if (stampToInput) {
            stampToInput.addEventListener('change', updateEndorsementHeadPreview);
            stampToInput.addEventListener('input', updateEndorsementHeadPreview);
        }

        if (sendAdminContainer) {
            var draggingStamp = false;
            function moveSendStamp(clientX, clientY) {
                if (!sendStampNode || !sendAdminContainer) return;
                var targetEl = getStampTargetElement(sendAdminContainer);
                if (!targetEl) return;
                var rect = targetEl.getBoundingClientRect();
                if (rect.width <= 0 || rect.height <= 0) return;
                var bounds = getSendStampBounds();
                sendStampCfg.x = clamp(((clientX - rect.left) / rect.width) * 100, bounds.minX, bounds.maxX);
                sendStampCfg.y = clamp(((clientY - rect.top) / rect.height) * 100, bounds.minY, bounds.maxY);
                applySendStampStyles();
            }
            sendAdminContainer.addEventListener('mousedown', function(e) {
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

        function buildStampImageForSubmit(done) {
            var baseData = generatedStampData || '';
            var tilt = parseFloat(sendStampCfg.tilt || 0) || 0;
            if (!baseData) {
                done('');
                return;
            }
            if (Math.abs(tilt) < 0.01) {
                done(baseData);
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
                done(c.toDataURL('image/png'));
            };
            img.onerror = function() {
                done(baseData);
            };
            img.src = baseData;
        }

        if (sendAdminSubmit) {
            sendAdminSubmit.addEventListener('click', function() {
                if (!sendAdminForm || !sendAdminDocId || !sendAdminDocId.value) return;
                if (!generatedStampData) {
                    alert('Please choose a stamp type and click Apply first.');
                    return;
                }
                sendAdminWidth.value = String(sendStampCfg.width.toFixed(2));
                sendAdminX.value = String(sendStampCfg.x.toFixed(2));
                sendAdminY.value = String(sendStampCfg.y.toFixed(2));
                buildStampImageForSubmit(function(finalStampData) {
                    if (sendAdminStampImageData) sendAdminStampImageData.value = finalStampData || generatedStampData;
                    sendAdminForm.submit();
                });
            });
        }

        var documentViewModal = document.getElementById('document-view-modal');
        var documentViewTitle = document.getElementById('document-view-title');
        var documentViewContainer = document.getElementById('document-view-container');
        var documentViewLoading = document.getElementById('document-view-loading');
        var documentViewError = document.getElementById('document-view-error');
        var documentViewDownloadLink = document.getElementById('document-view-download-link');

        function applyStampOverlay(stampCfg) {
            if (!documentViewContainer || !stampCfg || !stampCfg.image) return;
            var targetEl = getStampTargetElement(documentViewContainer);
            if (!targetEl) return;
            var stamp = document.createElement('img');
            stamp.className = 'document-stamp-overlay';
            stamp.src = stampCfg.image;
            stamp.alt = 'Document stamp';
            // Render exactly what Front Desk saved; do not auto-adjust on view.
            var width = parseFloat(stampCfg.width);
            var x = parseFloat(stampCfg.x);
            var y = parseFloat(stampCfg.y);
            if (!isFinite(width)) width = 18;
            if (!isFinite(x)) x = 82;
            if (!isFinite(y)) y = 84;
            stamp.style.width = width + '%';
            stamp.style.left = x + '%';
            stamp.style.top = y + '%';
            targetEl.appendChild(stamp);
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
                documentViewDownloadLink.style.display = 'none';
                documentViewDownloadLink.href = 'documents.php?download=' + encodeURIComponent(docId);
            }

            fetch('documents.php?view=' + encodeURIComponent(docId))
                .then(function(res) {
                    if (!res.ok) throw new Error('Load failed');
                    return res.blob();
                })
                .then(function(blob) {
                    documentViewLoading.style.display = 'none';
                    if (typeof docx !== 'undefined' && docx.renderAsync) {
                        return docx.renderAsync(blob, documentViewContainer).then(function() {
                            applyStampOverlay(stampCfg);
                            documentViewContainer.style.display = 'block';
                            if (documentViewDownloadLink) documentViewDownloadLink.style.display = 'inline-flex';
                        });
                    }
                    documentViewError.textContent = 'Document viewer not available.';
                    documentViewError.style.display = 'block';
                })
                .catch(function() {
                    documentViewLoading.style.display = 'none';
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
                var sentRecordId = (el.getAttribute('data-sent-record-id') || '').trim();
                var stampCfg = {
                    image: el.getAttribute('data-stamp-image') || '',
                    width: el.getAttribute('data-stamp-width') || '18',
                    x: el.getAttribute('data-stamp-x') || '82',
                    y: el.getAttribute('data-stamp-y') || '84'
                };
                if (!docId) return;
                if (/^\d+$/.test(sentRecordId)) {
                    fetch('documents.php?stamp_for_sent=' + encodeURIComponent(sentRecordId), {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store'
                    }).then(function(resp) {
                        return resp.ok ? resp.json() : null;
                    }).then(function(data) {
                        if (data && data.ok) {
                            stampCfg = {
                                image: data.image || stampCfg.image,
                                width: data.width || stampCfg.width,
                                x: data.x || stampCfg.x,
                                y: data.y || stampCfg.y
                            };
                        }
                    }).catch(function() {
                        // Fallback to row values if fetch fails.
                    }).finally(function() {
                        openDocumentViewModal(docId, docName, stampCfg);
                    });
                    return;
                }
                openDocumentViewModal(docId, docName, stampCfg);
            });
        });

        document.querySelectorAll('[data-close-document-view]').forEach(function(btn) {
            btn.addEventListener('click', closeDocumentViewModal);
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && stampDetailModal && !stampDetailModal.hidden) {
                closeStampDetailModal();
                return;
            }
            if (e.key === 'Escape' && sendAdminModal && !sendAdminModal.hidden) {
                closeSendAdminModal();
            }
            if (e.key === 'Escape' && documentViewModal && !documentViewModal.hidden) {
                closeDocumentViewModal();
            }
        });
    })();
    </script>
    <script src="sidebar_super_admin.js"></script>
    <?php $notifJsVer = @filemtime(__DIR__ . '/super_admin_notifications.js') ?: time(); ?>
    <script src="super_admin_notifications.js?v=<?= (int)$notifJsVer ?>"></script>
</body>
</html>
