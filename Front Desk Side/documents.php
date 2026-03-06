<?php
session_start();

$userRole = $_SESSION['user_role'] ?? '';
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}
if ($userRole === 'admin') {
    header('Location: ../Admin%20Side/admin_dashboard.php');
    exit;
}
if ($userRole === 'superadmin') {
    header('Location: ../Super%20Admin%20Side/dashboard.php');
    exit;
}

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User';

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../Super Admin Side/_activity_logger.php';
require_once __DIR__ . '/../Super Admin Side/_notifications_super_admin.php';

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

// Download document
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

// Send document to Super Admin (with custom stamp placement)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_to_super_admin') {
    $sendId = trim((string)($_POST['document_id'] ?? ''));
    $stampWidth = max(5, min(60, (float)($_POST['stamp_width_pct'] ?? 18)));
    $stampX = max(5, min(95, (float)($_POST['stamp_x_pct'] ?? 82)));
    $stampY = max(5, min(95, (float)($_POST['stamp_y_pct'] ?? 84)));
    $postedStampImage = trim((string)($_POST['stamp_image_data'] ?? ''));
    if (!preg_match('/^[a-f0-9]{24}$/i', $sendId) || !preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', $postedStampImage)) {
        header('Location: documents.php?send_error=1');
        exit;
    }
    try {
        $pdo = dbPdo($config);
        ensureSuperAdminStampColumns($config);
        $stmt = $pdo->prepare('SELECT document_code, document_title, file_name FROM documents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $sendId]);
        $doc = $stmt->fetch();
        if ($doc) {
            $docCode = $doc['document_code'] ?? '';
            $docTitle = $doc['document_title'] ?? '';
            $fileName = $doc['file_name'] ?? 'document.docx';

            $insert = $pdo->prepare(
                'INSERT INTO sent_to_super_admin
                    (document_id, document_code, document_title, file_name, stamp_image, stamp_width_pct, stamp_x_pct, stamp_y_pct, sent_by_user_id, sent_by_user_name, sent_at)
                 VALUES
                    (:document_id, :document_code, :document_title, :file_name, :stamp_image, :stamp_width_pct, :stamp_x_pct, :stamp_y_pct, :sent_by_user_id, :sent_by_user_name, :sent_at)'
            );
            $insert->execute([
                ':document_id' => $sendId,
                ':document_code' => $docCode,
                ':document_title' => $docTitle,
                ':file_name' => $fileName,
                ':stamp_image' => $postedStampImage,
                ':stamp_width_pct' => $stampWidth,
                ':stamp_x_pct' => $stampX,
                ':stamp_y_pct' => $stampY,
                ':sent_by_user_id' => $_SESSION['user_id'] ?? '',
                ':sent_by_user_name' => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
                ':sent_at' => dbNowUtcString(),
            ]);

            createSuperAdminNotification($config, [
                'document_id' => $sendId,
                'document_code' => $docCode,
                'document_title' => $docTitle,
                'file_name' => $fileName,
                'stamp_image' => $postedStampImage,
                'stamp_width_pct' => $stampWidth,
                'stamp_x_pct' => $stampX,
                'stamp_y_pct' => $stampY,
                'sent_by_user_id' => $_SESSION['user_id'] ?? '',
                'sent_by_user_name' => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
            ]);
            activityLog($config, 'document_send_to_super_admin', [
                'module' => 'front_desk_documents',
                'document_id' => $sendId,
                'document_code' => (string)$docCode,
                'document_title' => (string)$docTitle,
                'stamp_width_pct' => (float)$stampWidth,
                'stamp_x_pct' => (float)$stampX,
                'stamp_y_pct' => (float)$stampY,
            ]);
        }
    } catch (Exception $e) {
        header('Location: documents.php?send_error=1');
        exit;
    }
    header('Location: documents.php?sent=1');
    exit;
}

// Fetch documents from database (active only; exclude archived)
$documentsList = [];
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

// Mark documents already sent to Super Admin as "received"
$sentToSuperAdminIds = [];
try {
    $pdo = dbPdo($config);
    $stmt = $pdo->query('SELECT document_id FROM sent_to_super_admin ORDER BY sent_at DESC LIMIT 500');
    foreach ($stmt as $arr) {
        $docId = (string)($arr['document_id'] ?? '');
        if ($docId !== '') {
            $sentToSuperAdminIds[$docId] = true;
        }
    }
} catch (Exception $e) {}

foreach ($documentsList as &$doc) {
    $id = (string)($doc['_id'] ?? '');
    if ($id !== '' && isset($sentToSuperAdminIds[$id])) {
        $doc['status'] = 'received';
    }
}
unset($doc);

$showSentToast = isset($_GET['sent']) && $_GET['sent'] === '1';
$showSendErrorToast = isset($_GET['send_error']) && $_GET['send_error'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff – Documents</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="staff-dashboard.css">
    <style>
        /* Documents page – design styles */
        .documents-page {
            background: #f8fafc;
        }

        .documents-page .admin-content-header-row {
            background: #fff;
        }

        .documents-header {
            margin-bottom: 24px;
        }

        .documents-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 6px 0;
            letter-spacing: -0.02em;
        }

        .documents-header p {
            font-size: 0.95rem;
            color: #64748b;
            margin: 0;
        }

        /* Search and filter card */
        .documents-search-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .documents-search-row {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .documents-search-wrap {
            flex: 1;
            min-width: 220px;
            position: relative;
        }

        .documents-search-wrap svg {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
        }

        .documents-search-wrap input {
            width: 100%;
            height: 44px;
            padding: 0 16px 0 44px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            color: #1e293b;
            background: #fff;
            outline: none;
        }

        .documents-search-wrap input::placeholder {
            color: #94a3b8;
        }

        .documents-search-wrap input:focus {
            border-color: #64748b;
            box-shadow: 0 0 0 2px rgba(100, 116, 139, 0.15);
        }

        .documents-filter-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 44px;
            padding: 0 16px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
            color: #475569;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
        }

        .documents-filter-btn:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .documents-filter-btn svg {
            flex-shrink: 0;
            color: #94a3b8;
        }

        /* Document list card */
        .documents-list-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .documents-list-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .documents-list-header svg {
            color: #64748b;
            flex-shrink: 0;
        }

        .documents-list-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
        }

        .documents-list-count {
            font-size: 0.9rem;
            font-weight: 400;
            color: #94a3b8;
        }

        .documents-table-wrap {
            overflow-x: auto;
        }

        .documents-table {
            width: 100%;
            border-collapse: collapse;
        }

        .documents-table th {
            text-align: left;
            padding: 14px 20px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            color: #64748b;
            text-transform: uppercase;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .documents-table td {
            padding: 16px 20px;
            font-size: 14px;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
        }

        /* Empty state */
        .documents-empty-state {
            text-align: center;
            padding: 60px 24px;
        }

        .documents-empty-state svg {
            color: #cbd5e1;
            margin-bottom: 16px;
        }

        .documents-empty-state h4 {
            font-size: 1rem;
            font-weight: 600;
            color: #334155;
            margin: 0 0 8px 0;
        }

        .documents-empty-state p {
            font-size: 0.9rem;
            color: #94a3b8;
            margin: 0;
        }

        .documents-actions-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .documents-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px; border: none; font-size: 13px; font-weight: 500; cursor: pointer; font-family: inherit; transition: background 0.15s, color 0.15s; text-decoration: none; color: inherit; }
        .documents-action-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
        .documents-action-send-super { background: #dbeafe; color: #1d4ed8; }
        .documents-action-send-super:hover { background: #bfdbfe; color: #1d4ed8; }
        .document-status { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; text-transform: capitalize; }
        .document-status-active { background: #d1fae5; color: #047857; }
        .document-status-archived { background: #f3f4f6; color: #6b7280; }
        .document-status-received { background: #dbeafe; color: #1d4ed8; }
        .doc-modal[hidden] { display: none !important; }
        .doc-modal { position: fixed; inset: 0; z-index: 1400; display: flex; align-items: center; justify-content: center; padding: 18px; }
        .doc-modal-overlay { position: absolute; inset: 0; border: 0; background: rgba(15, 23, 42, 0.45); cursor: pointer; }
        .doc-modal-dialog { position: relative; z-index: 2; width: min(1020px, 96vw); max-height: 92vh; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 18px 50px rgba(15, 23, 42, 0.25); display: flex; flex-direction: column; overflow: hidden; }
        .doc-modal-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid #e2e8f0; }
        .doc-modal-header h2 { margin: 0; font-size: 1.05rem; color: #0f172a; }
        .doc-modal-close { border: 0; background: transparent; color: #64748b; font-size: 28px; line-height: 1; cursor: pointer; }
        .doc-modal-close:hover { color: #0f172a; }
        .doc-modal-body { padding: 14px 16px; overflow: hidden; background: #f8fafc; display: flex; flex-direction: column; gap: 10px; min-height: 0; }
        .document-view-loading, .document-view-error { margin: 8px 0; font-size: 14px; color: #64748b; }
        .document-view-error { color: #b91c1c; }
        .document-view-container { min-height: 180px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; padding: 12px; overflow: auto; position: relative; flex: 1; }
        .received-stamp-overlay { position: absolute; transform: translate(-50%, -50%); z-index: 20; object-fit: contain; max-width: none; max-height: none; cursor: move; opacity: 0.9; user-select: none; touch-action: none; }
        .stamp-template-box { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; background: #fff; margin-top: 12px; }
        .stamp-template-row { display: grid; grid-template-columns: 1fr; gap: 8px; margin-bottom: 8px; }
        .stamp-template-btn { border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; border-radius: 8px; padding: 9px 12px; font-size: 12px; font-weight: 700; cursor: pointer; text-align: center; }
        .stamp-template-btn.active { background: #dbeafe; border-color: #60a5fa; color: #1d4ed8; box-shadow: inset 0 0 0 1px #93c5fd; }
        .stamp-template-current { font-size: 12px; color: #475569; margin: 0; line-height: 1.35; }
        .send-stamp-controls { border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; padding: 10px; }
        .stamp-adjust-wrap { display: flex; align-items: center; gap: 10px; margin-top: 12px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
        .stamp-adjust-wrap label { font-size: 13px; font-weight: 600; color: #334155; white-space: nowrap; }
        .stamp-adjust-wrap input[type="range"] { flex: 1; accent-color: #2563eb; }
        .stamp-adjust-wrap span { min-width: 44px; text-align: right; font-size: 13px; font-weight: 700; color: #334155; }
        .stamp-tilt-wrap { display: flex; align-items: center; gap: 10px; margin-top: 12px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
        .stamp-tilt-wrap label { font-size: 13px; font-weight: 600; color: #334155; white-space: nowrap; }
        .stamp-tilt-wrap input[type="range"] { flex: 1; accent-color: #2563eb; }
        .stamp-tilt-wrap span { min-width: 44px; text-align: right; font-size: 13px; font-weight: 700; color: #334155; }
        .stamp-detail-note { margin: 0 0 12px 0; font-size: 12px; color: #64748b; }
        .stamp-fields-grid { display: grid; gap: 8px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .stamp-fields-grid .full { grid-column: 1 / -1; }
        .stamp-fields-grid label { display: grid; gap: 4px; font-size: 12px; color: #334155; font-weight: 600; }
        .stamp-fields-grid input { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 7px 9px; font-size: 12px; font-family: inherit; }
        .doc-modal-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 12px 16px; border-top: 1px solid #e2e8f0; background: #fff; flex-wrap: wrap; }
        .doc-btn { border: 0; border-radius: 8px; padding: 10px 14px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .doc-btn-cancel { background: #e2e8f0; color: #334155; }
        .doc-btn-cancel:hover { background: #cbd5e1; }
        .doc-btn-save { background: #2563eb; color: #fff; }
        .doc-btn-save:hover { background: #1d4ed8; }
    </style>
</head>
<body class="admin-dashboard documents-page"<?php if ($showSentToast): ?> data-sent="1"<?php endif; ?><?php if ($showSendErrorToast): ?> data-send-error="1"<?php endif; ?>>
    <div class="admin-body">
        <aside class="admin-sidebar staff-sidebar">
            <div class="sidebar-header staff-sidebar-header">
                <div class="sidebar-logo staff-sidebar-logo">
                    <img src="../img/logo.png" alt="LGU Solano Logo">
                </div>
                <div class="sidebar-title staff-sidebar-title">
                    <h2>LGU Solano</h2>
                    <span class="staff-sidebar-subtitle">Document Management</span>
                </div>
            </div>
            <nav class="sidebar-nav staff-sidebar-nav">
                <div class="sidebar-section">
                    <span class="sidebar-section-title">MAIN MENU</span>
                    <a href="staff_dashboard.php" class="sidebar-link" data-section="home">
                        <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        Dashboard
                    </a>
                    <a href="documents.php" class="sidebar-link active" data-section="documents">
                        <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                        Documents
                    </a>
                    <a href="upload_documents.php" class="sidebar-link" data-section="upload">
                        <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Upload Document
                    </a>
                </div>
                <div class="sidebar-section sidebar-section-account">
                    <span class="sidebar-section-title">ACCOUNT</span>
                    <a href="settings.php" class="sidebar-link sidebar-link-settings" data-section="settings">
                        <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        Settings
                    </a>
                </div>
            </nav>
        </aside>

        <main class="admin-main" style="background:#f8fafc;">
            <div class="admin-content" id="admin-content" style="background:#f8fafc; color:#1e293b;">
                <div class="admin-content-header-row">
                    <header class="admin-content-header">
                        <div class="documents-header">
                            <h1>Documents</h1>
                            <p>Manage and track all documents in the system.</p>
                        </div>
                    </header>
                    <div class="admin-content-icons">
                        <button type="button" class="admin-icon-btn" id="notif-btn" title="Notifications" aria-label="Notifications">
                            <svg class="icon-bell" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        </button>
                        <div class="admin-profile-wrap">
                            <button type="button" class="admin-icon-btn" id="profile-logout-btn" title="Profile and log out" aria-haspopup="true" aria-expanded="false" aria-label="Profile">
                                <svg class="icon-person" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
                            </button>
                            <div class="profile-dropdown" id="profile-dropdown" hidden>
                                <a href="#" class="dropdown-item">Profile</a>
                                <a href="../index.php?logout=1" class="dropdown-item dropdown-logout">Log out</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="admin-content-body" id="admin-content-body" style="background:#f8fafc;">
                <div class="documents-search-card">
                    <div class="documents-search-row">
                        <div class="documents-search-wrap">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" placeholder="Search by title, control number, or sender..." aria-label="Search documents">
                        </div>
                        <button type="button" class="documents-filter-btn" aria-label="Filter">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
                            All Status
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <button type="button" class="documents-filter-btn" aria-label="Department filter">
                            All Departments
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                    </div>
                </div>

                <div class="documents-list-card">
                    <div class="documents-list-header">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><polyline points="16 13 12 13 12 17"/><line x1="16 17" y1="17" x2="8" y2="17"/></svg>
                        <h3>Document List</h3>
                        <span class="documents-list-count"><?php echo count($documentsList); ?></span>
                    </div>
                    <div class="documents-table-wrap">
                        <table class="documents-table">
                            <thead>
                                <tr>
                                    <th>Control No.</th>
                                    <th>Title</th>
                                    <th>File</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($documentsList)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="documents-empty-state">
                                            <svg viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                                            <h4>No documents found</h4>
                                            <p>Documents will appear here once uploaded.</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($documentsList as $idx => $doc): ?>
                                <?php
                                    $docId = $doc['_id'] ?? '';
                                    $docCode = $doc['documentCode'] ?? $doc['document_code'] ?? '—';
                                    $docTitle = $doc['documentTitle'] ?? $doc['document_title'] ?? '—';
                                    $fileName = $doc['fileName'] ?? $doc['file_name'] ?? 'document.docx';
                                    $docStatus = $doc['status'] ?? 'active';
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($docCode); ?></td>
                                    <td><?php echo htmlspecialchars($docTitle); ?></td>
                                    <td><?php echo htmlspecialchars($fileName); ?></td>
                                    <td><span class="document-status document-status-<?php echo strtolower(htmlspecialchars($docStatus)); ?>"><?php echo htmlspecialchars(ucfirst($docStatus)); ?></span></td>
                                    <td>
                                        <div class="documents-actions-row">
                                            <button type="button" class="documents-action-btn documents-action-send-super send-super-trigger" data-doc-id="<?php echo htmlspecialchars($docId); ?>" data-doc-name="<?php echo htmlspecialchars($fileName); ?>" title="Prepare and send to Super Admin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Send to Super Admin</button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div class="doc-modal" id="send-super-admin-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-send-super-admin aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="send-super-admin-title">
            <div class="doc-modal-header">
                <h2 id="send-super-admin-title">Prepare Document for Super Admin</h2>
                <button type="button" class="doc-modal-close" data-close-send-super-admin aria-label="Close">&times;</button>
            </div>
            <div class="doc-modal-body">
                <div id="send-super-admin-loading" class="document-view-loading">Loading document...</div>
                <div id="send-super-admin-container" class="document-view-container" style="display:none;"></div>
                <div id="send-super-admin-error" class="document-view-error" style="display:none;">Could not load document.</div>
                <div class="send-stamp-controls">
                    <div class="stamp-template-box" style="margin-top:0;">
                        <div class="stamp-template-row">
                        <button type="button" class="stamp-template-btn active" id="send-super-admin-stamp-type-received">Received</button>
                        </div>
                    <p class="stamp-template-current" id="send-super-admin-stamp-note">Selected stamp: <strong>Received</strong>. Click the button to enter stamp details first.</p>
                    </div>
                    <div class="stamp-adjust-wrap">
                        <label for="send-super-admin-stamp-size">Stamp size</label>
                        <input type="range" id="send-super-admin-stamp-size" min="5" max="60" step="1" value="13">
                        <span id="send-super-admin-stamp-size-label">13%</span>
                    </div>
                    <div class="stamp-tilt-wrap">
                        <label for="send-super-admin-stamp-tilt">Tilt</label>
                        <input type="range" id="send-super-admin-stamp-tilt" min="-35" max="35" step="1" value="0">
                        <span id="send-super-admin-stamp-tilt-label">0°</span>
                    </div>
                </div>
            </div>
            <div class="doc-modal-actions">
                <a id="send-super-admin-download-link" href="#" class="documents-action-btn documents-action-send-super" target="_blank" rel="noopener" download style="display:none;">Download</a>
                <button type="button" class="doc-btn doc-btn-cancel" data-close-send-super-admin>Cancel</button>
                <button type="button" class="doc-btn doc-btn-save" id="send-super-admin-submit">Send to Super Admin</button>
            </div>
        </div>
    </div>

    <div class="doc-modal" id="send-super-admin-stamp-detail-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-send-super-admin-stamp-detail aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="send-super-admin-stamp-detail-title">
            <div class="doc-modal-header">
                <h2 id="send-super-admin-stamp-detail-title">Received Stamp Details</h2>
                <button type="button" class="doc-modal-close" data-close-send-super-admin-stamp-detail aria-label="Close">&times;</button>
            </div>
            <div class="doc-modal-form">
                <p class="stamp-detail-note">Fill in date/time/by, then click Apply before sending.</p>
                <div class="stamp-fields-grid">
                    <label>Date
                        <input type="date" id="send-super-admin-stamp-date">
                    </label>
                    <label>Time
                        <input type="time" id="send-super-admin-stamp-time">
                    </label>
                    <label class="full">By
                        <input type="text" id="send-super-admin-stamp-by" placeholder="Name">
                    </label>
                </div>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-send-super-admin-stamp-detail>Cancel</button>
                    <button type="button" class="doc-btn doc-btn-save" id="send-super-admin-stamp-apply">Apply</button>
                </div>
            </div>
        </div>
    </div>

    <form method="post" action="documents.php" id="send-super-admin-form" style="display:none;">
        <input type="hidden" name="action" value="send_to_super_admin">
        <input type="hidden" name="document_id" id="send-super-admin-doc-id" value="">
        <input type="hidden" name="stamp_image_data" id="send-super-admin-stamp-image-data" value="">
        <input type="hidden" name="stamp_width_pct" id="send-super-admin-width" value="13">
        <input type="hidden" name="stamp_x_pct" id="send-super-admin-x" value="92">
        <input type="hidden" name="stamp_y_pct" id="send-super-admin-y" value="6">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/jszip@3/dist/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.0/dist/docx-preview.min.js"></script>
    <script>
    (function() {
        var btn = document.getElementById('profile-logout-btn');
        var dropdown = document.getElementById('profile-dropdown');
        if (btn && dropdown) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var open = dropdown.hidden;
                dropdown.hidden = !open;
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function() {
                dropdown.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            });
            dropdown.addEventListener('click', function(e) { e.stopPropagation(); });
        }

        if (document.body.getAttribute('data-sent') === '1') {
            var toast = document.createElement('div');
            toast.setAttribute('role', 'status');
            toast.textContent = 'Document sent to Super Admin.';
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#22c55e;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 4000);
        }

        if (document.body.getAttribute('data-send-error') === '1') {
            var errorToast = document.createElement('div');
            errorToast.setAttribute('role', 'status');
            errorToast.textContent = 'Unable to send document. Please try again.';
            errorToast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#dc2626;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(errorToast);
            setTimeout(function() { errorToast.remove(); }, 4500);
        }

        var sendModal = document.getElementById('send-super-admin-modal');
        var sendContainer = document.getElementById('send-super-admin-container');
        var sendLoading = document.getElementById('send-super-admin-loading');
        var sendError = document.getElementById('send-super-admin-error');
        var sendTitle = document.getElementById('send-super-admin-title');
        var sendDownloadLink = document.getElementById('send-super-admin-download-link');
        var sendSizeRange = document.getElementById('send-super-admin-stamp-size');
        var sendSizeLabel = document.getElementById('send-super-admin-stamp-size-label');
        var sendTiltRange = document.getElementById('send-super-admin-stamp-tilt');
        var sendTiltLabel = document.getElementById('send-super-admin-stamp-tilt-label');
        var sendStampTypeReceivedBtn = document.getElementById('send-super-admin-stamp-type-received');
        var sendStampNote = document.getElementById('send-super-admin-stamp-note');
        var sendStampDetailModal = document.getElementById('send-super-admin-stamp-detail-modal');
        var sendStampDateInput = document.getElementById('send-super-admin-stamp-date');
        var sendStampTimeInput = document.getElementById('send-super-admin-stamp-time');
        var sendStampByInput = document.getElementById('send-super-admin-stamp-by');
        var sendStampApplyBtn = document.getElementById('send-super-admin-stamp-apply');
        var sendSubmit = document.getElementById('send-super-admin-submit');
        var sendForm = document.getElementById('send-super-admin-form');
        var sendDocId = document.getElementById('send-super-admin-doc-id');
        var sendStampImageData = document.getElementById('send-super-admin-stamp-image-data');
        var sendWidth = document.getElementById('send-super-admin-width');
        var sendX = document.getElementById('send-super-admin-x');
        var sendY = document.getElementById('send-super-admin-y');

        var sendStampCfg = { width: 13, x: 92, y: 6, tilt: 0 };
        var sendStampNode = null;
        var generatedStampData = '';
        var draggingStamp = false;
        var sendStampPlaced = false;

        function clamp(val, min, max) { return Math.max(min, Math.min(max, val)); }

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

        function generateReceivedStampImage() {
            var c = document.createElement('canvas');
            c.width = 1300;
            c.height = 760;
            var ctx = c.getContext('2d');
            if (!ctx) return '';
            var dateText = formatLongDate(sendStampDateInput ? sendStampDateInput.value : '');
            var timeText = formatDisplayTime(sendStampTimeInput ? sendStampTimeInput.value : '');
            var byText = (sendStampByInput && sendStampByInput.value ? sendStampByInput.value : '').trim();
            ctx.clearRect(0, 0, c.width, c.height);
            ctx.fillStyle = '#111111';
            ctx.textBaseline = 'middle';
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
            return c.toDataURL('image/png');
        }

        function openSendStampDetailModal() {
            if (!sendStampDetailModal) return;
            sendStampDetailModal.hidden = false;
            document.body.classList.add('modal-open');
        }

        function closeSendStampDetailModal() {
            if (!sendStampDetailModal) return;
            sendStampDetailModal.hidden = true;
            if (!sendModal || sendModal.hidden) {
                document.body.classList.remove('modal-open');
            }
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
            var targetEl = getStampTargetElement(sendContainer);
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
            var rad = ((parseFloat(sendStampCfg.tilt || 0) || 0) * Math.PI) / 180;
            var cos = Math.abs(Math.cos(rad));
            var sin = Math.abs(Math.sin(rad));
            var rotW = (stampW * cos) + (stampH * sin);
            var rotH = (stampW * sin) + (stampH * cos);
            var halfWPercent = clamp((rotW / 2 / rect.width) * 100, 1, 49);
            var halfHPercent = clamp((rotH / 2 / rect.height) * 100, 1, 49);
            return {
                minX: halfWPercent,
                maxX: 100 - halfWPercent,
                minY: halfHPercent,
                maxY: 100 - halfHPercent
            };
        }

        function applySendStampStyles() {
            if (!sendStampNode || !sendContainer) return;
            var bounds = getSendStampBounds();
            sendStampCfg.x = clamp(sendStampCfg.x, bounds.minX, bounds.maxX);
            sendStampCfg.y = clamp(sendStampCfg.y, bounds.minY, bounds.maxY);
            sendStampNode.style.width = sendStampCfg.width + '%';
            sendStampNode.style.left = sendStampCfg.x + '%';
            sendStampNode.style.top = sendStampCfg.y + '%';
            sendStampNode.style.transform = 'translate(-50%, -50%) rotate(' + sendStampCfg.tilt + 'deg)';
            if (sendSizeRange) sendSizeRange.value = String(sendStampCfg.width);
            if (sendSizeLabel) sendSizeLabel.textContent = String(Math.round(sendStampCfg.width)) + '%';
            if (sendTiltRange) sendTiltRange.value = String(Math.round(sendStampCfg.tilt));
            if (sendTiltLabel) sendTiltLabel.textContent = String(Math.round(sendStampCfg.tilt)) + '°';
        }

        function ensureSendStampNode() {
            if (!sendContainer || !generatedStampData) return null;
            var targetEl = getStampTargetElement(sendContainer);
            if (!targetEl) return null;
            if (!sendStampNode) {
                sendStampNode = document.createElement('img');
                sendStampNode.className = 'received-stamp-overlay';
                sendStampNode.alt = 'Received stamp';
                sendStampNode.src = generatedStampData;
                targetEl.appendChild(sendStampNode);
            } else if (sendStampNode.parentNode !== targetEl) {
                targetEl.appendChild(sendStampNode);
            }
            sendStampNode.style.display = sendStampPlaced ? '' : 'none';
            return sendStampNode;
        }

        function updateSendStampNote(textHtml) {
            if (!sendStampNote) return;
            sendStampNote.innerHTML = textHtml;
        }

        function placeStampAt(clientX, clientY) {
            ensureSendStampNode();
            if (!sendStampNode || !sendContainer) return;
            var targetEl = getStampTargetElement(sendContainer);
            if (!targetEl) return;
            var rect = targetEl.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) return;
            var bounds = getSendStampBounds();
            sendStampCfg.x = clamp(((clientX - rect.left) / rect.width) * 100, bounds.minX, bounds.maxX);
            sendStampCfg.y = clamp(((clientY - rect.top) / rect.height) * 100, bounds.minY, bounds.maxY);
            sendStampPlaced = true;
            sendStampNode.style.display = '';
            applySendStampStyles();
            updateSendStampNote('Selected stamp: <strong>Received</strong>. Stamp placed. You can drag to adjust.');
        }

        function openSendModal(docId, docName) {
            if (!sendModal || !sendContainer || !docId) return;
            // Default stamp placement: upper-right corner of document page.
            sendStampCfg = { width: 13, x: 92, y: 6, tilt: 0 };
            sendStampNode = null;
            generatedStampData = '';
            sendStampPlaced = false;
            updateSendStampNote('Selected stamp: <strong>Received</strong>. Click the button to enter stamp details first.');
            if (sendDocId) sendDocId.value = docId;
            if (sendStampImageData) sendStampImageData.value = '';
            if (sendTitle) sendTitle.textContent = 'Prepare: ' + (docName || 'Document');
            if (sendDownloadLink) {
                sendDownloadLink.href = 'documents.php?download=' + encodeURIComponent(docId);
                sendDownloadLink.style.display = 'inline-flex';
            }
            sendModal.hidden = false;
            document.body.classList.add('modal-open');
            sendLoading.style.display = 'block';
            sendContainer.style.display = 'none';
            sendError.style.display = 'none';
            sendContainer.innerHTML = '';
            var now = new Date();
            if (sendStampDateInput) sendStampDateInput.value = toYmdLocal(now);
            if (sendStampTimeInput) sendStampTimeInput.value = toHmLocal(now);
            if (sendStampByInput) sendStampByInput.value = <?php echo json_encode((string)$userName); ?>;
            fetch('documents.php?view=' + encodeURIComponent(docId))
                .then(function(res) {
                    if (!res.ok) throw new Error('Load failed');
                    return res.blob();
                })
                .then(function(blob) {
                    sendLoading.style.display = 'none';
                    if (typeof docx !== 'undefined' && docx.renderAsync) {
                        return docx.renderAsync(blob, sendContainer).then(function() {
                            sendContainer.style.display = 'block';
                            // Stamp appears after Apply on stamp detail modal.
                        });
                    }
                    sendError.textContent = 'Document viewer not available.';
                    sendError.style.display = 'block';
                })
                .catch(function() {
                    sendLoading.style.display = 'none';
                    sendError.textContent = 'Could not load document.';
                    sendError.style.display = 'block';
                });
        }

        function closeSendModal() {
            if (!sendModal) return;
            sendModal.hidden = true;
            document.body.classList.remove('modal-open');
            draggingStamp = false;
            if (sendContainer) {
                sendContainer.innerHTML = '';
                sendContainer.style.display = 'none';
            }
            if (sendLoading) sendLoading.style.display = 'block';
            if (sendError) sendError.style.display = 'none';
            if (sendDownloadLink) sendDownloadLink.style.display = 'none';
            sendStampNode = null;
            sendStampPlaced = false;
            closeSendStampDetailModal();
        }

        document.querySelectorAll('.send-super-trigger').forEach(function(btnEl) {
            btnEl.addEventListener('click', function() {
                var docId = btnEl.getAttribute('data-doc-id') || '';
                var docName = btnEl.getAttribute('data-doc-name') || 'document.docx';
                openSendModal(docId, docName);
            });
        });

        document.querySelectorAll('[data-close-send-super-admin]').forEach(function(el) {
            el.addEventListener('click', closeSendModal);
        });

        if (sendSizeRange) {
            sendSizeRange.addEventListener('input', function() {
                sendStampCfg.width = clamp(parseFloat(sendSizeRange.value || '13') || 13, 5, 60);
                applySendStampStyles();
            });
        }

        if (sendTiltRange) {
            sendTiltRange.addEventListener('input', function() {
                sendStampCfg.tilt = clamp(parseFloat(sendTiltRange.value || '0') || 0, -35, 35);
                applySendStampStyles();
            });
        }

        if (sendStampTypeReceivedBtn) {
            sendStampTypeReceivedBtn.addEventListener('click', openSendStampDetailModal);
        }

        document.querySelectorAll('[data-close-send-super-admin-stamp-detail]').forEach(function(el) {
            el.addEventListener('click', closeSendStampDetailModal);
        });

        if (sendStampApplyBtn) {
            sendStampApplyBtn.addEventListener('click', function() {
                generatedStampData = generateReceivedStampImage();
                ensureSendStampNode();
                sendStampPlaced = false;
                if (sendStampNode) sendStampNode.style.display = 'none';
                applySendStampStyles();
                updateSendStampNote('Selected stamp: <strong>Received</strong>. Click anywhere on the document to place the stamp.');
                closeSendStampDetailModal();
            });
        }

        function moveStamp(clientX, clientY) {
            if (!sendStampNode || !sendContainer) return;
            var targetEl = getStampTargetElement(sendContainer);
            if (!targetEl) return;
            var rect = targetEl.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) return;
            var bounds = getSendStampBounds();
            sendStampCfg.x = clamp(((clientX - rect.left) / rect.width) * 100, bounds.minX, bounds.maxX);
            sendStampCfg.y = clamp(((clientY - rect.top) / rect.height) * 100, bounds.minY, bounds.maxY);
            applySendStampStyles();
        }

        if (sendContainer) {
            sendContainer.addEventListener('mousedown', function(e) {
                if (!sendStampNode) return;
                if (e.target === sendStampNode) {
                    draggingStamp = true;
                    e.preventDefault();
                }
            });
            window.addEventListener('mousemove', function(e) {
                if (!draggingStamp) return;
                moveStamp(e.clientX, e.clientY);
            });
            window.addEventListener('mouseup', function() { draggingStamp = false; });
            sendContainer.addEventListener('click', function(e) {
                if (!generatedStampData) return;
                if (e.target === sendStampNode) return;
                placeStampAt(e.clientX, e.clientY);
            });
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
            img.onerror = function() { done(baseData); };
            img.src = baseData;
        }

        if (sendSubmit) {
            sendSubmit.addEventListener('click', function() {
                if (!sendForm || !sendDocId || !sendDocId.value) return;
                if (!generatedStampData) {
                    alert('Please click Received and Apply the stamp details first.');
                    return;
                }
                if (!sendStampPlaced) {
                    alert('Please click on the document to place the stamp before sending.');
                    return;
                }
                if (sendWidth) sendWidth.value = String(sendStampCfg.width.toFixed(2));
                if (sendX) sendX.value = String(sendStampCfg.x.toFixed(2));
                if (sendY) sendY.value = String(sendStampCfg.y.toFixed(2));
                buildStampImageForSubmit(function(finalStampData) {
                    if (sendStampImageData) sendStampImageData.value = finalStampData || generatedStampData;
                    sendForm.submit();
                });
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sendStampDetailModal && !sendStampDetailModal.hidden) {
                closeSendStampDetailModal();
                return;
            }
            if (e.key === 'Escape' && sendModal && !sendModal.hidden) {
                closeSendModal();
            }
        });
    })();
    </script>
</body>
</html>
