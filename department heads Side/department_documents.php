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
require_once __DIR__ . '/../Super Admin Side/_notifications_super_admin.php';

$currentUserId = (string)($_SESSION['user_id'] ?? '');
$currentUserName = (string)($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? '');

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
                        (sth.office_head_id IS NULL OR sth.office_head_id = \'\')
                        AND sth.office_head_name = :user_name
                    )
               )
             ORDER BY sth.sent_at DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':document_id' => $docId,
            ':user_id' => $currentUserId,
            ':user_name' => $currentUserName,
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
        // Continue to 404.
    }
    if (ob_get_level()) ob_end_clean();
    header('HTTP/1.1 404 Not Found');
    exit;
}

$notifData = getSuperAdminNotifications($config);
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
                (sth.office_head_id IS NULL OR sth.office_head_id = \'\')
                AND sth.office_head_name = :user_name
            )
         ORDER BY sth.sent_at DESC
         LIMIT 250'
    );
    $stmt->execute([
        ':user_id' => $currentUserId,
        ':user_name' => $currentUserName,
    ]);
    $documentsList = $stmt->fetchAll() ?: [];
} catch (Exception $e) {
    $documentsList = [];
}

$selectedDocumentId = trim((string)($_GET['doc'] ?? ''));
if ($selectedDocumentId === '' && !empty($documentsList)) {
    $selectedDocumentId = (string)($documentsList[0]['document_id'] ?? '');
}

$selectedDocument = null;
foreach ($documentsList as $row) {
    if ((string)($row['document_id'] ?? '') === $selectedDocumentId) {
        $selectedDocument = $row;
        break;
    }
}
if ($selectedDocument === null && !empty($documentsList)) {
    $selectedDocument = $documentsList[0];
    $selectedDocumentId = (string)($selectedDocument['document_id'] ?? '');
}

$selectedFileName = (string)($selectedDocument['file_name'] ?? '');
$selectedExt = strtolower((string)pathinfo($selectedFileName, PATHINFO_EXTENSION));
$isSelectedImage = in_array($selectedExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
$isSelectedPdf = ($selectedExt === 'pdf');
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
            background: #f1f5f9;
            border: none;
            color: #475569;
            padding: 0;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
        }
        .admin-content-actions .icon-btn:hover { background: #e2e8f0; color: #1e293b; }
        .admin-content-actions .icon-btn svg { width: 22px; height: 22px; }
        .admin-content-actions .notif-badge {
            position: absolute;
            top: 4px;
            right: 4px;
            background: #ef4444;
            color: #fff;
            font-size: 12px;
            line-height: 1;
            padding: 4px 7px;
            border-radius: 999px;
        }
        .dept-docs-layout {
            display: grid;
            grid-template-columns: minmax(360px, 44%) minmax(420px, 56%);
            gap: 14px;
            min-height: calc(100vh - 170px);
        }
        .docs-card {
            background: #fff;
            border: 1px solid #dbe3ef;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .docs-card-head {
            padding: 12px 14px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
        }
        .docs-list-wrap { padding: 10px; overflow: auto; }
        .docs-table { width: 100%; border-collapse: collapse; }
        .docs-table th {
            text-align: left;
            font-size: 0.74rem;
            color: #64748b;
            font-weight: 700;
            letter-spacing: 0.03em;
            padding: 8px 6px;
            border-bottom: 1px solid #e2e8f0;
            text-transform: uppercase;
        }
        .docs-table td {
            padding: 10px 6px;
            border-bottom: 1px solid #eef2f7;
            color: #1e293b;
            font-size: 0.88rem;
            vertical-align: top;
        }
        .docs-table tr.is-active { background: #eef6ff; }
        .doc-title { font-weight: 600; color: #0f172a; margin-bottom: 2px; }
        .doc-meta { color: #64748b; font-size: 0.8rem; }
        .view-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #93c5fd;
            color: #1d4ed8;
            background: #eff6ff;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            padding: 6px 10px;
        }
        .view-btn:hover { background: #dbeafe; }
        .docs-empty {
            padding: 24px;
            text-align: center;
            color: #64748b;
            font-size: 0.92rem;
        }
        .viewer-toolbar {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .toolbar-btn {
            border: 1px solid #dbe3ef;
            background: #fff;
            color: #1e293b;
            border-radius: 8px;
            font-size: 0.82rem;
            padding: 7px 10px;
            text-decoration: none;
            cursor: pointer;
            font-weight: 600;
        }
        .toolbar-btn:hover { background: #f8fafc; }
        .viewer-panel {
            padding: 12px;
            min-height: 340px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .viewer-image {
            max-width: 100%;
            max-height: 500px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
        }
        .viewer-frame {
            width: 100%;
            height: 500px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #fff;
        }
        .viewer-fallback {
            width: 100%;
            border: 1px dashed #94a3b8;
            border-radius: 10px;
            background: #fff;
            padding: 18px;
            text-align: center;
            color: #475569;
        }
        .viewer-fallback p { margin: 0 0 10px; }
        .comments-wrap { padding: 10px 12px; display: flex; flex-direction: column; gap: 8px; }
        .comments-title {
            margin: 0;
            font-size: 0.85rem;
            color: #334155;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .comments-list {
            border: 1px solid #dbe3ef;
            border-radius: 10px;
            background: #fff;
            padding: 8px;
            max-height: 165px;
            overflow: auto;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .comment-item {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px;
            font-size: 0.82rem;
            color: #1e293b;
            background: #f8fafc;
        }
        .comment-meta { font-size: 0.74rem; color: #64748b; margin-bottom: 4px; }
        .comment-compose {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
        }
        .comment-input {
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            padding: 10px 11px;
            font-size: 0.86rem;
            font-family: inherit;
        }
        .comment-send {
            border: 1px solid #2563eb;
            background: #2563eb;
            color: #fff;
            border-radius: 9px;
            padding: 0 14px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
        }
        .comment-send:hover { background: #1d4ed8; }
        @media (max-width: 1200px) {
            .dept-docs-layout { grid-template-columns: 1fr; }
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
                    <div class="dept-docs-layout">
                        <section class="docs-card">
                            <div class="docs-card-head">Received Documents</div>
                            <div class="docs-list-wrap">
                                <?php if (empty($documentsList)): ?>
                                    <div class="docs-empty">No documents endorsed to you yet.</div>
                                <?php else: ?>
                                    <table class="docs-table">
                                        <thead>
                                            <tr>
                                                <th>Document</th>
                                                <th>From</th>
                                                <th>Sent</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($documentsList as $doc): ?>
                                                <?php
                                                    $docId = (string)($doc['document_id'] ?? '');
                                                    $isActive = $docId === $selectedDocumentId;
                                                ?>
                                                <tr class="<?php echo $isActive ? 'is-active' : ''; ?>">
                                                    <td>
                                                        <div class="doc-title"><?php echo htmlspecialchars((string)($doc['document_title'] ?? 'Document')); ?></div>
                                                        <div class="doc-meta"><?php echo htmlspecialchars((string)($doc['document_code'] ?? '')); ?> • <?php echo htmlspecialchars((string)($doc['file_name'] ?? '')); ?></div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars((string)($doc['sent_by_user_name'] ?? 'User')); ?></td>
                                                    <td><?php echo htmlspecialchars((string)($doc['sent_at'] ?? '')); ?></td>
                                                    <td>
                                                        <a class="view-btn" href="department_documents.php?doc=<?php echo urlencode($docId); ?>">
                                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
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

                        <section class="docs-card">
                            <div class="viewer-toolbar">
                                <button type="button" class="toolbar-btn" id="viewer-back-btn">Back</button>
                                <?php if ($selectedDocumentId !== ''): ?>
                                    <button type="button" class="toolbar-btn" id="viewer-print-btn">Print</button>
                                    <a class="toolbar-btn" href="department_documents.php?download=<?php echo urlencode($selectedDocumentId); ?>">Download</a>
                                <?php endif; ?>
                            </div>
                            <div class="viewer-panel">
                                <?php if ($selectedDocumentId === ''): ?>
                                    <div class="viewer-fallback">
                                        <p>Select a document from the left list.</p>
                                    </div>
                                <?php elseif ($isSelectedImage): ?>
                                    <img class="viewer-image" id="viewer-image" src="department_documents.php?view=<?php echo urlencode($selectedDocumentId); ?>" alt="Selected document">
                                <?php elseif ($isSelectedPdf): ?>
                                    <iframe class="viewer-frame" id="viewer-frame" src="department_documents.php?view=<?php echo urlencode($selectedDocumentId); ?>"></iframe>
                                <?php else: ?>
                                    <div class="viewer-fallback">
                                        <p>This file type is not previewed inline yet.</p>
                                        <p><strong><?php echo htmlspecialchars($selectedFileName); ?></strong></p>
                                        <a class="toolbar-btn" href="department_documents.php?download=<?php echo urlencode($selectedDocumentId); ?>">Download file</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="comments-wrap">
                                <p class="comments-title">Comments</p>
                                <div class="comments-list" id="comments-list">
                                    <div class="comment-item">
                                        <div class="comment-meta">System • Just now</div>
                                        <div class="comment-text">Use this section for review notes while viewing the document.</div>
                                    </div>
                                </div>
                                <div class="comment-compose">
                                    <input type="text" class="comment-input" id="comment-input" placeholder="Write a comment...">
                                    <button type="button" class="comment-send" id="comment-send-btn">Send</button>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../Super%20Admin%20Side/sidebar_super_admin.js"></script>
    <script src="department_notifications.js"></script>
    <script>
    (function() {
        var backBtn = document.getElementById('viewer-back-btn');
        if (backBtn) {
            backBtn.addEventListener('click', function() {
                if (window.history.length > 1) window.history.back();
                else window.location.href = 'department_dashboard.php';
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
                if (frame && frame.src) {
                    window.open(frame.src, '_blank');
                }
            });
        }

        var commentInput = document.getElementById('comment-input');
        var commentSendBtn = document.getElementById('comment-send-btn');
        var commentsList = document.getElementById('comments-list');
        if (commentInput && commentSendBtn && commentsList) {
            commentSendBtn.addEventListener('click', function() {
                var txt = (commentInput.value || '').trim();
                if (!txt) return;
                var now = new Date();
                var row = document.createElement('div');
                row.className = 'comment-item';
                row.innerHTML =
                    '<div class="comment-meta">Department Head • ' + now.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true }) + '</div>' +
                    '<div class="comment-text"></div>';
                row.querySelector('.comment-text').textContent = txt;
                commentsList.appendChild(row);
                commentsList.scrollTop = commentsList.scrollHeight;
                commentInput.value = '';
            });
            commentInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    commentSendBtn.click();
                }
            });
        }
    })();
    </script>
</body>
</html>
