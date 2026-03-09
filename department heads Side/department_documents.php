<?php
session_start();
ob_start();

$role = (string)($_SESSION['user_role'] ?? '');
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}
if ($role === 'superadmin') {
    header('Location: ../Super%20Admin%20Side/dashboard.php');
    exit;
}
if ($role === 'admin') {
    header('Location: ../Admin%20Side/admin_dashboard.php');
    exit;
}
if (in_array($role, ['staff', 'user'], true)) {
    header('Location: ../Front%20Desk%20Side/staff_dashboard.php');
    exit;
}
if (!in_array($role, ['departmenthead', 'department_head', 'dept_head'], true)) {
    header('Location: ../index.php');
    exit;
}

$config = require dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../Super Admin Side/_account_helpers.php';
require_once __DIR__ . '/_notifications_department_head.php';

$currentUserId = (string)($_SESSION['user_id'] ?? '');
$currentUserName = (string)($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? '');
$currentUserEmail = (string)($_SESSION['user_email'] ?? '');
$currentUserDepartment = trim((string)($_SESSION['user_department'] ?? ''));
$currentUserUsername = trim((string)(getUserUsername($currentUserId) ?: ($_SESSION['user_username'] ?? '')));

$headNameCandidates = [];
foreach ([$currentUserUsername, $currentUserName, $currentUserEmail] as $candidate) {
    $v = trim((string)$candidate);
    if ($v !== '') $headNameCandidates[] = mb_strtolower($v);
}
$headNameCandidates = array_values(array_unique($headNameCandidates));
$headName1 = $headNameCandidates[0] ?? '';
$headName2 = $headNameCandidates[1] ?? $headName1;
$headName3 = $headNameCandidates[2] ?? $headName2;

function docMimeFromName($name) {
    $ext = strtolower((string)pathinfo((string)$name, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg'], true)) return 'image/jpeg';
    if ($ext === 'png') return 'image/png';
    if ($ext === 'gif') return 'image/gif';
    if ($ext === 'webp') return 'image/webp';
    if ($ext === 'pdf') return 'application/pdf';
    if ($ext === 'doc') return 'application/msword';
    if ($ext === 'docx') return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    return 'application/octet-stream';
}

// Serve file content only if this department head received the document.
if (
    (!empty($_GET['view']) && preg_match('/^[a-f0-9]{24}$/i', (string)$_GET['view'])) ||
    (!empty($_GET['download']) && preg_match('/^[a-f0-9]{24}$/i', (string)$_GET['download']))
) {
    $docId = !empty($_GET['view']) ? (string)$_GET['view'] : (string)$_GET['download'];
    $isDownload = !empty($_GET['download']);
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare(
            'SELECT d.file_name, d.file_content
             FROM sent_to_department_heads sth
             INNER JOIN documents d ON d.id = sth.document_id
             WHERE sth.document_id = :document_id
               AND (
                    sth.office_head_id = :user_id
                    OR (
                        sth.office_head_id IS NULL
                        AND (
                            LOWER(TRIM(sth.office_head_name)) IN (:head_name_1, :head_name_2, :head_name_3)
                            OR (:user_department_check <> \'\' AND LOWER(TRIM(sth.office_name)) = LOWER(TRIM(:user_department_value)))
                        )
                    )
               )
             ORDER BY sth.sent_at DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':document_id' => $docId,
            ':user_id' => $currentUserId,
            ':head_name_1' => $headName1,
            ':head_name_2' => $headName2,
            ':head_name_3' => $headName3,
            ':user_department_check' => $currentUserDepartment,
            ':user_department_value' => $currentUserDepartment,
        ]);
        $doc = $stmt->fetch();
        if ($doc) {
            $fileName = (string)($doc['file_name'] ?? 'document.bin');
            $fileContent = (string)($doc['file_content'] ?? '');
            if ($fileContent !== '') {
                if (ob_get_level()) ob_end_clean();
                header('Content-Type: ' . docMimeFromName($fileName));
                header(
                    'Content-Disposition: ' . ($isDownload ? 'attachment' : 'inline') .
                    '; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName) . '"'
                );
                $decoded = base64_decode($fileContent, true);
                echo ($decoded !== false) ? $decoded : $fileContent;
                exit;
            }
        }
    } catch (Exception $e) {
        error_log('[department_documents view] ' . $e->getMessage());
        // Continue to 404.
    }
    if (ob_get_level()) ob_end_clean();
    header('HTTP/1.1 404 Not Found');
    exit;
}

$notifData = getDepartmentHeadNotifications($config);
$notifCount = $notifData['count'];
$notifItems = $notifData['items'];

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'Department Head';
$userRole = 'Department Head';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$sidebar_active = 'documents';

$documentsList = [];
try {
    $pdo = dbPdo($config);
    $stmt = $pdo->prepare(
        'SELECT
            sth.document_id,
            sth.sent_by_user_name,
            sth.sent_at,
            sth.office_name,
            d.document_code,
            d.document_title,
            d.file_name,
            d.status
         FROM sent_to_department_heads sth
         INNER JOIN documents d ON d.id = sth.document_id
         WHERE
            sth.office_head_id = :user_id
            OR (
                sth.office_head_id IS NULL
                AND (
                    LOWER(TRIM(sth.office_head_name)) IN (:head_name_1, :head_name_2, :head_name_3)
                    OR (:user_department_check <> \'\' AND LOWER(TRIM(sth.office_name)) = LOWER(TRIM(:user_department_value)))
                )
            )
         ORDER BY sth.sent_at DESC
         LIMIT 250'
    );
    $stmt->execute([
        ':user_id' => $currentUserId,
        ':head_name_1' => $headName1,
        ':head_name_2' => $headName2,
        ':head_name_3' => $headName3,
        ':user_department_check' => $currentUserDepartment,
        ':user_department_value' => $currentUserDepartment,
    ]);
    $documentsList = $stmt->fetchAll() ?: [];
} catch (Exception $e) {
    error_log('[department_documents list] ' . $e->getMessage());
    $documentsList = [];
}

$selectedDocumentId = trim((string)($_GET['doc'] ?? ''));
$isViewMode = ($selectedDocumentId !== '');
$highlightDocumentId = trim((string)($_GET['highlight'] ?? ''));

$selectedDocument = null;
if ($isViewMode) {
    foreach ($documentsList as $row) {
        if ((string)($row['document_id'] ?? '') === $selectedDocumentId) {
            $selectedDocument = $row;
            break;
        }
    }
}

$selectedFileName = (string)($selectedDocument['file_name'] ?? '');
$selectedExt = strtolower((string)pathinfo($selectedFileName, PATHINFO_EXTENSION));
$isSelectedImage = in_array($selectedExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
$isSelectedPdf = ($selectedExt === 'pdf');
$isSelectedDocx = in_array($selectedExt, ['doc', 'docx'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU - Department Documents</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Super%20Admin%20Side/sidebar_super_admin.css">
    <link rel="stylesheet" href="../Admin%20Side/admin-dashboard.css">
    <style>
        body, .dashboard-container, .main-content {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Open Sans', sans-serif;
        }
        body { margin: 0; background: #f8fafc; color: #0f172a; }
        .main-content { display: flex; flex-direction: column; background: #fff; min-height: 0; flex: 1; }
        .main-content .admin-content-header-row { flex-shrink: 0; padding-right: 35px; }
        .main-content .admin-content-body { flex: 1; min-height: 0; overflow: auto; padding: 24px 24px 30px; }
        .main-content .admin-content-actions { margin-left: auto; }
        .admin-content-actions .header-controls { position: relative; }
        .admin-content-actions .icon-btn {
            background: #f1f5f9; border: none; color: #475569; padding: 0;
            border-radius: 10px; cursor: pointer; display: inline-flex;
            align-items: center; justify-content: center; width: 44px; height: 44px;
        }
        .admin-content-actions .icon-btn:hover { background: #e2e8f0; color: #1e293b; }
        .admin-content-actions .icon-btn svg { width: 22px; height: 22px; }
        .admin-content-actions .notif-badge {
            position: absolute; top: 4px; right: 4px; background: #ef4444; color: #fff;
            font-size: 12px; line-height: 1; padding: 4px 7px; border-radius: 999px;
        }

        /* ── TABLE VIEW (default) ─────────────────────── */
        .docs-card {
            background: #fff; border: 1px solid #dbe3ef; border-radius: 12px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
        }
        .docs-card-head {
            padding: 14px 18px; border-bottom: 1px solid #e2e8f0;
            font-size: 1rem; font-weight: 700; color: #0f172a;
        }
        .docs-list-wrap { padding: 0; overflow: auto; }
        .docs-table { width: 100%; border-collapse: collapse; }
        .docs-table th {
            text-align: left; font-size: 0.74rem; color: #64748b; font-weight: 700;
            letter-spacing: 0.04em; padding: 10px 14px; border-bottom: 1px solid #e2e8f0;
            text-transform: uppercase; background: #f8fafc;
        }
        .docs-table td {
            padding: 12px 14px; border-bottom: 1px solid #eef2f7;
            color: #1e293b; font-size: 0.88rem; vertical-align: middle;
        }
        .docs-table tbody tr:hover { background: #f1f5f9; }
        .docs-table tbody tr.row-highlight {
            background: #fff7cc !important;
            transition: background-color 0.2s ease;
        }
        .doc-title-cell { font-weight: 600; color: #0f172a; }
        .doc-code-cell { color: #64748b; font-size: 0.82rem; }
        .view-btn {
            display: inline-flex; align-items: center; gap: 6px;
            border: 1px solid #93c5fd; color: #1d4ed8; background: #eff6ff;
            border-radius: 8px; font-size: 0.8rem; font-weight: 600;
            text-decoration: none; padding: 6px 12px; transition: background 0.15s;
        }
        .view-btn:hover { background: #dbeafe; }
        .view-btn svg { width: 15px; height: 15px; flex-shrink: 0; }
        .docs-empty { padding: 32px; text-align: center; color: #64748b; font-size: 0.92rem; }

        /* ── DETAIL VIEW (after clicking View) ────────── */
        .detail-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 0;
            align-items: start;
            height: calc(100vh - 170px);
            border: 1px solid #dbe3ef;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
        }
        .detail-left {
            display: flex; flex-direction: column; background: #fff;
            border-right: 1px solid #e2e8f0;
            height: 100%;
            min-height: 0;
            overflow: auto;
        }
        .detail-toolbar {
            padding: 10px 14px; border-bottom: 1px solid #e2e8f0;
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
            background: #fff; flex-shrink: 0;
            position: sticky; top: 0; z-index: 4;
        }
        .toolbar-btn {
            border: 1px solid #dbe3ef; background: #fff; color: #1e293b;
            border-radius: 8px; font-size: 0.82rem; padding: 7px 12px;
            text-decoration: none; cursor: pointer; font-weight: 600;
            display: inline-flex; align-items: center; gap: 5px;
            transition: background 0.12s;
        }
        .toolbar-btn:hover { background: #f1f5f9; }
        .toolbar-btn svg { width: 15px; height: 15px; }
        .toolbar-select {
            border: 1px solid #dbe3ef;
            background: #fff;
            color: #1e293b;
            border-radius: 8px;
            font-size: 0.82rem;
            padding: 7px 10px;
            font-weight: 600;
            min-width: 210px;
            max-width: 280px;
        }
        .toolbar-page-count {
            margin-left: auto;
            font-size: 0.8rem;
            color: #475569;
            font-weight: 700;
            background: #f8fafc;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            padding: 7px 10px;
            white-space: nowrap;
        }
        .detail-viewer {
            background: #f1f5f9;
            padding: 16px;
            overflow: visible;
            flex: 1;
            min-height: 0;
        }
        .viewer-image { max-width: 100%; max-height: 100%; border-radius: 6px; border: 1px solid #e2e8f0; background: #fff; }
        .viewer-frame { width: 100%; min-height: 78vh; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; }
        .viewer-fallback {
            border: 1px dashed #94a3b8; border-radius: 10px; background: #fff;
            padding: 24px; text-align: center; color: #475569; max-width: 420px;
        }
        .viewer-fallback p { margin: 0 0 10px; }
        #docx-container .docx-wrapper {
            background: #e2e8f0;
            padding: 18px 12px;
        }
        #docx-container .docx-wrapper > section.docx {
            margin: 0 auto 18px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.14);
            border: 1px solid #dbe3ef;
        }
        #docx-container .docx-wrapper > section.docx:last-child {
            margin-bottom: 0;
        }

        /* ── COMMENTS PANEL (right side) ──────────────── */
        .detail-right {
            display: flex; flex-direction: column; background: #fff;
            height: 100%;
            min-height: 420px;
            overflow: hidden;
        }
        .comments-header {
            padding: 14px 16px; border-bottom: 1px solid #e2e8f0;
            font-size: 0.92rem; font-weight: 700; color: #0f172a;
        }
        .comments-list {
            flex: 1; min-height: 0; overflow-y: auto; padding: 12px 14px;
            display: flex; flex-direction: column; gap: 10px;
        }
        .comment-item { display: flex; gap: 10px; align-items: flex-start; }
        .comment-avatar {
            width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.78rem; font-weight: 700; color: #fff;
        }
        .comment-avatar.sa { background: #1e40af; }
        .comment-avatar.dh { background: #0f766e; }
        .comment-body { flex: 1; min-width: 0; }
        .comment-meta { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .comment-name { font-size: 0.82rem; font-weight: 700; color: #0f172a; }
        .comment-menu-wrap { position: relative; }
        .comment-menu-btn {
            width: 26px;
            height: 26px;
            border: none;
            border-radius: 999px;
            background: transparent;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .comment-menu-btn:hover { background: #e2e8f0; color: #334155; }
        .comment-menu-btn svg { width: 16px; height: 16px; }
        .comment-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            min-width: 140px;
            background: #fff;
            border: 1px solid #dbe3ef;
            border-radius: 10px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.12);
            padding: 6px;
            display: none;
            z-index: 20;
        }
        .comment-menu.open { display: block; }
        .comment-menu-item {
            width: 100%;
            border: none;
            background: transparent;
            color: #1e293b;
            font-size: 0.82rem;
            text-align: left;
            border-radius: 8px;
            padding: 7px 8px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
        }
        .comment-menu-item:hover { background: #f1f5f9; }
        .comment-menu-item.danger { color: #b91c1c; }
        .comment-menu-item svg { width: 14px; height: 14px; }
        .comment-text {
            margin-top: 3px; font-size: 0.84rem; color: #334155; line-height: 1.45;
            background: #f1f5f9; border-radius: 10px; padding: 8px 10px;
        }
        .comment-time { font-size: 0.7rem; color: #94a3b8; margin-top: 3px; text-align: right; }
        .comments-empty { padding: 20px; text-align: center; color: #94a3b8; font-size: 0.86rem; }
        .comment-compose {
            padding: 10px 14px; border-top: 1px solid #e2e8f0;
            display: flex; gap: 8px; flex-shrink: 0;
        }
        .comment-input {
            flex: 1; border: 1px solid #cbd5e1; border-radius: 10px;
            padding: 10px 12px; font-size: 0.86rem; font-family: inherit;
            outline: none; transition: border-color 0.15s;
        }
        .comment-input:focus { border-color: #93c5fd; }
        .comment-send {
            border: none; background: #2563eb; color: #fff; border-radius: 10px;
            padding: 0 16px; font-size: 0.84rem; font-weight: 700; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            transition: background 0.15s;
        }
        .comment-send:hover { background: #1d4ed8; }
        .comment-send svg { width: 18px; height: 18px; }

        @media (max-width: 900px) {
            .detail-layout { grid-template-columns: 1fr; height: auto; overflow: visible; }
            .detail-left { border-right: none; border-bottom: 1px solid #e2e8f0; height: auto; overflow: visible; }
            .detail-right { position: static; height: auto; min-height: 0; overflow: visible; }
            .viewer-frame { min-height: 520px; }
            .toolbar-page-count { margin-left: 0; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/_sidebar_department_head.php'; ?>
        <div class="main-content">
            <div class="admin-content" id="admin-content">
                <div class="admin-content-header-row">
                    <header class="admin-content-header">
                        <div class="admin-header-text">
                            <h1 class="admin-content-title">Department Documents</h1>
                            <p class="admin-content-subtitle">View and review documents endorsed to your office.</p>
                        </div>
                    </header>
                    <div class="admin-content-actions">
                        <div class="header-controls">
                            <?php include __DIR__ . '/_notif_dropdown_department_head.php'; ?>
                        </div>
                    </div>
                </div>
                <div class="admin-content-body">

                <?php if (!$isViewMode): ?>
                    <!-- ═══ TABLE VIEW ═══ -->
                    <section class="docs-card">
                        <div class="docs-card-head">Received Documents</div>
                        <div class="docs-list-wrap">
                            <?php if (empty($documentsList)): ?>
                                <div class="docs-empty">No documents endorsed to you yet.</div>
                            <?php else: ?>
                                <table class="docs-table">
                                    <thead>
                                        <tr>
                                            <th>No.</th>
                                            <th>Document Code</th>
                                            <th>Document Title</th>
                                            <th>File</th>
                                            <th>From</th>
                                            <th>Sent</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($documentsList as $idx => $doc): ?>
                                            <?php $docId = (string)($doc['document_id'] ?? ''); ?>
                                            <tr data-doc-id="<?php echo htmlspecialchars($docId); ?>">
                                                <td><?php echo (int)($idx + 1); ?></td>
                                                <td class="doc-code-cell"><?php echo htmlspecialchars((string)($doc['document_code'] ?? '-')); ?></td>
                                                <td class="doc-title-cell"><?php echo htmlspecialchars((string)($doc['document_title'] ?? 'Document')); ?></td>
                                                <td class="doc-code-cell"><?php echo htmlspecialchars((string)($doc['file_name'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string)($doc['sent_by_user_name'] ?? 'User')); ?></td>
                                                <td class="doc-code-cell"><?php echo htmlspecialchars((string)($doc['sent_at'] ?? '')); ?></td>
                                                <td>
                                                    <a class="view-btn" href="department_documents.php?doc=<?php echo urlencode($docId); ?>">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                                        View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </section>

                <?php else: ?>
                    <!-- ═══ DETAIL VIEW ═══ -->
                    <div class="detail-layout">
                        <div class="detail-left">
                            <div class="detail-toolbar">
                                <a class="toolbar-btn" href="department_documents.php">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                                    Back
                                </a>
                                <?php if (!empty($documentsList)): ?>
                                <select class="toolbar-select" id="doc-switch-select" aria-label="Select document">
                                    <?php foreach ($documentsList as $listDoc): ?>
                                        <?php $listDocId = (string)($listDoc['document_id'] ?? ''); ?>
                                        <option value="<?php echo htmlspecialchars($listDocId); ?>" <?php echo $listDocId === $selectedDocumentId ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string)($listDoc['document_code'] ?? 'DOC') . ' - ' . (string)($listDoc['document_title'] ?? 'Document')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                                <?php if ($isSelectedDocx): ?>
                                <span class="toolbar-page-count" id="docx-page-count">Pages: --</span>
                                <?php endif; ?>
                                <button type="button" class="toolbar-btn" id="viewer-print-btn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                    Print
                                </button>
                                <a class="toolbar-btn" href="department_documents.php?download=<?php echo urlencode($selectedDocumentId); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    Download
                                </a>
                            </div>
                            <div class="detail-viewer">
                                <?php if ($isSelectedImage): ?>
                                    <img class="viewer-image" id="viewer-image" src="department_documents.php?view=<?php echo urlencode($selectedDocumentId); ?>" alt="<?php echo htmlspecialchars($selectedFileName); ?>">
                                <?php elseif ($isSelectedPdf): ?>
                                    <iframe class="viewer-frame" id="viewer-frame" src="department_documents.php?view=<?php echo urlencode($selectedDocumentId); ?>"></iframe>
                                <?php elseif ($isSelectedDocx): ?>
                                    <div id="docx-container" style="width:100%;min-height:520px;background:#fff;border-radius:6px;border:1px solid #e2e8f0;overflow:visible;"></div>
                                <?php else: ?>
                                    <div class="viewer-fallback">
                                        <p>Preview not available for this file type.</p>
                                        <p><strong><?php echo htmlspecialchars($selectedFileName); ?></strong></p>
                                        <a class="toolbar-btn" href="department_documents.php?download=<?php echo urlencode($selectedDocumentId); ?>">Download file</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="detail-right">
                            <div class="comments-header">Comments &amp; Notes</div>
                            <div class="comments-list" id="comments-list">
                                <?php if ($selectedDocument): ?>
                                <div class="comment-item" data-author-role="admin">
                                    <div class="comment-avatar sa"><?php echo mb_strtoupper(mb_substr((string)($selectedDocument['sent_by_user_name'] ?? 'S'), 0, 1)); ?></div>
                                    <div class="comment-body">
                                        <div class="comment-meta">
                                            <div class="comment-name"><?php echo htmlspecialchars((string)($selectedDocument['sent_by_user_name'] ?? 'Super Admin')); ?></div>
                                            <div class="comment-menu-wrap">
                                                <button type="button" class="comment-menu-btn" aria-label="Comment options" title="Comment options">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <circle cx="12" cy="5" r="1.8"></circle>
                                                        <circle cx="12" cy="12" r="1.8"></circle>
                                                        <circle cx="12" cy="19" r="1.8"></circle>
                                                    </svg>
                                                </button>
                                                <div class="comment-menu">
                                                    <button type="button" class="comment-menu-item" data-comment-action="edit">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M12 20h9"></path>
                                                            <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path>
                                                        </svg>
                                                        Update
                                                    </button>
                                                    <button type="button" class="comment-menu-item danger" data-comment-action="delete">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <polyline points="3 6 5 6 21 6"></polyline>
                                                            <path d="M19 6l-1 14H6L5 6"></path>
                                                            <path d="M10 11v6"></path>
                                                            <path d="M14 11v6"></path>
                                                            <path d="M9 6V4h6v2"></path>
                                                        </svg>
                                                        Delete
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="comment-text">Endorsed document "<strong><?php echo htmlspecialchars((string)($selectedDocument['document_title'] ?? '')); ?></strong>" to your office for review.</div>
                                        <div class="comment-time"><?php echo htmlspecialchars((string)($selectedDocument['sent_at'] ?? '')); ?></div>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="comments-empty">No comments yet.</div>
                                <?php endif; ?>
                            </div>
                            <div class="comment-compose">
                                <input type="text" class="comment-input" id="comment-input" placeholder="Write a comment...">
                                <button type="button" class="comment-send" id="comment-send-btn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <?php if ($isViewMode && $isSelectedDocx): ?>
    <script src="https://cdn.jsdelivr.net/npm/jszip@3/dist/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.0/dist/docx-preview.min.js"></script>
    <script>
    (function() {
        var container = document.getElementById('docx-container');
        var pageCountLabel = document.getElementById('docx-page-count');
        if (!container) return;

        function updatePageCount() {
            if (!pageCountLabel) return;
            var pageNodes = container.querySelectorAll('.docx-wrapper > section.docx');
            if (!pageNodes || pageNodes.length < 1) {
                pageNodes = container.querySelectorAll('section.docx');
            }
            var total = pageNodes ? pageNodes.length : 0;
            pageCountLabel.textContent = 'Pages: ' + (total > 0 ? total : 1);
        }

        fetch('department_documents.php?view=<?php echo urlencode($selectedDocumentId); ?>')
            .then(function(r) { if (!r.ok) throw new Error(); return r.blob(); })
            .then(function(blob) {
                if (typeof docx !== 'undefined' && docx.renderAsync) {
                    docx.renderAsync(blob, container, null, {
                        breakPages: true,
                        ignoreLastRenderedPageBreak: false
                    }).then(function() {
                        updatePageCount();
                    });
                }
            })
            .catch(function() {
                container.innerHTML = '<p style="padding:20px;color:#64748b;text-align:center;">Could not load document preview.</p>';
                if (pageCountLabel) {
                    pageCountLabel.textContent = 'Pages: --';
                }
            });
    })();
    </script>
    <?php endif; ?>

    <script src="../Super%20Admin%20Side/sidebar_super_admin.js"></script>
    <script src="department_notifications.js"></script>
    <script>
    (function() {
        var highlightedDocId = <?php echo json_encode($highlightDocumentId); ?>;
        if (highlightedDocId && !<?php echo $isViewMode ? 'true' : 'false'; ?>) {
            var row = null;
            document.querySelectorAll('tr[data-doc-id]').forEach(function(tr) {
                if (!row && (tr.getAttribute('data-doc-id') || '') === highlightedDocId) {
                    row = tr;
                }
            });
            if (row) {
                row.classList.add('row-highlight');
                try {
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } catch (e) {}
                setTimeout(function() {
                    row.classList.remove('row-highlight');
                }, 2000);
            }
            if (window.history && window.history.replaceState) {
                var cleanUrl = window.location.pathname;
                window.history.replaceState(null, '', cleanUrl);
            }
        }

        var docSwitch = document.getElementById('doc-switch-select');
        if (docSwitch) {
            docSwitch.addEventListener('change', function() {
                var selectedId = (docSwitch.value || '').trim();
                if (!selectedId) return;
                window.location.href = 'department_documents.php?doc=' + encodeURIComponent(selectedId);
            });
        }

        var printBtn = document.getElementById('viewer-print-btn');
        if (printBtn) {
            printBtn.addEventListener('click', function() {
                var img = document.getElementById('viewer-image');
                if (img && img.src) {
                    var w = window.open('', '_blank');
                    if (w) {
                        w.document.write('<html><head><title>Print</title></head><body style="margin:0;text-align:center;"><img src="' + img.src + '" style="max-width:100%;"></body></html>');
                        w.document.close();
                        w.focus();
                        setTimeout(function() { w.print(); }, 350);
                    }
                    return;
                }
                var frame = document.getElementById('viewer-frame');
                if (frame) { window.open(frame.src, '_blank'); return; }
                var docxC = document.getElementById('docx-container');
                if (docxC) {
                    var w = window.open('', '_blank');
                    if (w) {
                        w.document.write('<html><head><title>Print</title></head><body>' + docxC.innerHTML + '</body></html>');
                        w.document.close();
                        w.focus();
                        setTimeout(function() { w.print(); }, 400);
                    }
                }
            });
        }

        var commentInput = document.getElementById('comment-input');
        var commentSendBtn = document.getElementById('comment-send-btn');
        var commentsList = document.getElementById('comments-list');
        if (commentInput && commentSendBtn && commentsList) {
            function closeAllCommentMenus() {
                commentsList.querySelectorAll('.comment-menu.open').forEach(function(menu) {
                    menu.classList.remove('open');
                });
            }

            commentsList.addEventListener('click', function(e) {
                var menuBtn = e.target.closest('.comment-menu-btn');
                if (menuBtn) {
                    e.preventDefault();
                    var wrap = menuBtn.closest('.comment-menu-wrap');
                    var menu = wrap ? wrap.querySelector('.comment-menu') : null;
                    if (!menu) return;
                    var willOpen = !menu.classList.contains('open');
                    closeAllCommentMenus();
                    if (willOpen) menu.classList.add('open');
                    return;
                }

                var actionBtn = e.target.closest('[data-comment-action]');
                if (!actionBtn) return;
                e.preventDefault();
                var action = actionBtn.getAttribute('data-comment-action') || '';
                var commentItem = actionBtn.closest('.comment-item');
                var textEl = commentItem ? commentItem.querySelector('.comment-text') : null;
                closeAllCommentMenus();
                if (!commentItem || !textEl) return;

                if (action === 'edit') {
                    var oldText = (textEl.textContent || '').trim();
                    var nextText = window.prompt('Update comment:', oldText);
                    if (nextText === null) return;
                    nextText = nextText.trim();
                    if (!nextText) return;
                    textEl.textContent = nextText;
                    return;
                }
                if (action === 'delete') {
                    if (!window.confirm('Delete this comment?')) return;
                    commentItem.remove();
                }
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest('.comment-menu-wrap')) {
                    closeAllCommentMenus();
                }
            });

            function addComment() {
                var txt = (commentInput.value || '').trim();
                if (!txt) return;
                var now = new Date();
                var ts = now.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
                var item = document.createElement('div');
                item.className = 'comment-item';
                item.innerHTML =
                    '<div class="comment-avatar dh"><?php echo mb_strtoupper(mb_substr($userName, 0, 1)); ?></div>' +
                    '<div class="comment-body">' +
                    '<div class="comment-name"><?php echo htmlspecialchars($userName); ?></div>' +
                    '<div class="comment-text"></div>' +
                    '<div class="comment-time">' + ts + '</div>' +
                    '</div>';
                item.querySelector('.comment-text').textContent = txt;
                commentsList.appendChild(item);
                commentsList.scrollTop = commentsList.scrollHeight;
                commentInput.value = '';
            }
            commentSendBtn.addEventListener('click', addComment);
            commentInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); addComment(); }
            });
        }
    })();
    </script>
</body>
</html>
