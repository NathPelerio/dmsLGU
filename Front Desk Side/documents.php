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

function frontDeskDocumentTableColumns($pdo) {
    static $cols = null;
    if (is_array($cols)) {
        return $cols;
    }
    $cols = [];
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM documents');
        foreach ($stmt as $row) {
            $field = strtolower((string)($row['Field'] ?? ''));
            if ($field !== '') {
                $cols[$field] = true;
            }
        }
    } catch (Exception $e) {
        $cols = [];
    }
    return $cols;
}

function frontDeskMimeFromName($name) {
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

function frontDeskDocumentPayload($pdo, $documentId) {
    $cols = frontDeskDocumentTableColumns($pdo);
    if (empty($cols)) {
        return null;
    }
    $idCol = isset($cols['document_id']) ? 'document_id' : 'id';
    $selectCols = [];
    foreach (['file_name', 'mime_type', 'storage_path', 'file_content'] as $col) {
        if (isset($cols[$col])) {
            $selectCols[] = $col;
        }
    }
    if (empty($selectCols)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $selectCols) . ' FROM documents WHERE ' . $idCol . ' = :id LIMIT 1');
    $stmt->execute([':id' => (int)$documentId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'file_name' => (string)($row['file_name'] ?? 'document.bin'),
        'mime_type' => (string)($row['mime_type'] ?? ''),
        'storage_path' => (string)($row['storage_path'] ?? ''),
        'file_content' => (string)($row['file_content'] ?? ''),
    ];
}

if (!empty($_GET['view']) && preg_match('/^\d+$/', (string)$_GET['view'])) {
    try {
        $pdo = dbPdo($config);
        $doc = frontDeskDocumentPayload($pdo, (int)$_GET['view']);
        if ($doc) {
            $fileName = (string)($doc['file_name'] ?? 'document.bin');
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
            $mimeType = trim((string)($doc['mime_type'] ?? ''));
            if ($mimeType === '') {
                $mimeType = frontDeskMimeFromName($fileName);
            }
            $storagePath = trim((string)($doc['storage_path'] ?? ''));
            if ($storagePath !== '') {
                $absPath = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $storagePath), '/');
                if (is_file($absPath) && is_readable($absPath)) {
                    header('Content-Type: ' . $mimeType);
                    header('Content-Disposition: inline; filename="' . $safeName . '"');
                    readfile($absPath);
                    exit;
                }
            }
            $fileContent = (string)($doc['file_content'] ?? '');
            if ($fileContent !== '') {
                header('Content-Type: ' . $mimeType);
                header('Content-Disposition: inline; filename="' . $safeName . '"');
                echo base64_decode($fileContent, true) ?: $fileContent;
                exit;
            }
        }
    } catch (Exception $e) {}
    header('HTTP/1.1 404 Not Found');
    exit;
}

if (!empty($_GET['download']) && preg_match('/^\d+$/', (string)$_GET['download'])) {
    try {
        $pdo = dbPdo($config);
        $doc = frontDeskDocumentPayload($pdo, (int)$_GET['download']);
        if ($doc) {
            $fileName = (string)($doc['file_name'] ?? 'document.bin');
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
            $mimeType = trim((string)($doc['mime_type'] ?? ''));
            if ($mimeType === '') {
                $mimeType = frontDeskMimeFromName($fileName);
            }
            $storagePath = trim((string)($doc['storage_path'] ?? ''));
            if ($storagePath !== '') {
                $absPath = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $storagePath), '/');
                if (is_file($absPath) && is_readable($absPath)) {
                    header('Content-Type: ' . $mimeType);
                    header('Content-Disposition: attachment; filename="' . $safeName . '"');
                    readfile($absPath);
                    exit;
                }
            }
            $fileContent = (string)($doc['file_content'] ?? '');
            if ($fileContent !== '') {
                header('Content-Type: ' . $mimeType);
                header('Content-Disposition: attachment; filename="' . $safeName . '"');
                echo base64_decode($fileContent, true) ?: $fileContent;
                exit;
            }
        }
    } catch (Exception $e) {}
    header('HTTP/1.1 404 Not Found');
    exit;
}

$documentsList = [];
try {
    $pdo = dbPdo($config);
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE status <> :status ORDER BY created_at DESC LIMIT 500');
    $stmt->execute([':status' => 'archived']);
    foreach ($stmt as $arr) {
        $arr['_id'] = (string)($arr['document_id'] ?? $arr['id'] ?? '');
        $arr['documentCode'] = $arr['tracking_code'] ?? $arr['document_code'] ?? '';
        $arr['documentTitle'] = $arr['subject'] ?? $arr['document_title'] ?? '';
        $arr['fileName'] = $arr['file_name'] ?? 'document.docx';
        $documentsList[] = $arr;
    }
} catch (Exception $e) {
    $documentsList = [];
}

$selectedDocumentId = trim((string)($_GET['doc'] ?? ''));
$isViewMode = ($selectedDocumentId !== '' && preg_match('/^\d+$/', $selectedDocumentId));
$selectedDocument = null;
if ($isViewMode) {
    foreach ($documentsList as $row) {
        if ((string)($row['_id'] ?? '') === $selectedDocumentId) {
            $selectedDocument = $row;
            break;
        }
    }
}

$selectedFileName = (string)($selectedDocument['fileName'] ?? '');
$selectedExt = strtolower((string)pathinfo($selectedFileName, PATHINFO_EXTENSION));
$isSelectedImage = in_array($selectedExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
$isSelectedPdf = ($selectedExt === 'pdf');
$isSelectedDocx = in_array($selectedExt, ['doc', 'docx'], true);

$receivedStampMeta = null;
if ($selectedDocument && !empty($selectedDocument['details'])) {
    $parsed = @json_decode((string)$selectedDocument['details'], true);
    if (is_array($parsed) && !empty($parsed['received_stamp'])) {
        $receivedStampMeta = $parsed['received_stamp'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff - Documents</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/staff-dashboard.css">
    <style>
        .documents-page { background: #f8fafc; }
        .documents-page .admin-content-header-row { background: #fff; }
        .documents-header { margin-bottom: 24px; }
        .documents-header h1 { font-size: 1.75rem; font-weight: 700; color: #1e293b; margin: 0 0 6px 0; letter-spacing: -0.02em; }
        .documents-header p { font-size: 0.95rem; color: #64748b; margin: 0; }
        .documents-list-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); }
        .documents-list-header { display: flex; align-items: center; gap: 10px; padding: 20px; border-bottom: 1px solid #e2e8f0; }
        .documents-list-header svg { color: #64748b; flex-shrink: 0; }
        .documents-list-header h3 { font-size: 1rem; font-weight: 600; color: #1e293b; margin: 0; }
        .documents-list-count { font-size: 0.9rem; font-weight: 400; color: #94a3b8; }
        .documents-table-wrap { overflow-x: auto; }
        .documents-table { width: 100%; border-collapse: collapse; }
        .documents-table th { text-align: left; padding: 14px 20px; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.06em; color: #64748b; text-transform: uppercase; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .documents-table td { padding: 16px 20px; font-size: 14px; color: #334155; border-bottom: 1px solid #f1f5f9; }
        .documents-empty-state { text-align: center; padding: 60px 24px; color: #94a3b8; }
        .documents-actions-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .documents-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px; border: none; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; background: #dbeafe; color: #1d4ed8; }
        .documents-action-btn:hover { background: #bfdbfe; color: #1d4ed8; }
        .detail-layout { background: #fff; border: 1px solid #dbe3ef; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06); min-height: calc(100vh - 220px); }
        .detail-toolbar { padding: 10px 14px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; background: #fff; }
        .toolbar-btn { border: 1px solid #dbe3ef; background: #fff; color: #1e293b; border-radius: 8px; font-size: 0.82rem; padding: 7px 12px; text-decoration: none; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .toolbar-btn:hover { background: #f1f5f9; }
        .toolbar-select { border: 1px solid #dbe3ef; background: #fff; color: #1e293b; border-radius: 8px; font-size: 0.82rem; padding: 7px 10px; font-weight: 600; min-width: 240px; max-width: 320px; }
        .toolbar-page-count { margin-left: auto; font-size: 0.8rem; color: #475569; font-weight: 700; background: #f8fafc; border: 1px solid #dbe3ef; border-radius: 8px; padding: 7px 10px; white-space: nowrap; }
        .detail-viewer { background: #f1f5f9; padding: 16px; min-height: calc(100vh - 320px); }
        .viewer-image { max-width: 100%; max-height: 100%; border-radius: 6px; border: 1px solid #e2e8f0; background: #fff; }
        .viewer-frame { width: 100%; min-height: 78vh; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; }
        .viewer-fallback { border: 1px dashed #94a3b8; border-radius: 10px; background: #fff; padding: 24px; text-align: center; color: #475569; max-width: 420px; }
        #docx-container .docx-wrapper { background: #e2e8f0; padding: 18px 12px; }
        #docx-container .docx-wrapper > section.docx { margin: 0 auto 18px; box-shadow: 0 6px 18px rgba(15, 23, 42, 0.14); border: 1px solid #dbe3ef; }
        .fd-stamp-overlay { position: absolute; z-index: 20; object-fit: contain; max-width: none; max-height: none; pointer-events: none; opacity: 0.92; transform: translate(-50%, -50%); }
        @media (max-width: 900px) { .viewer-frame { min-height: 520px; } .toolbar-page-count { margin-left: 0; } }
    </style>
</head>
<body class="admin-dashboard documents-page">
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
                    <a href="staff_dashboard.php" class="sidebar-link" data-section="home">Dashboard</a>
                    <a href="documents.php" class="sidebar-link active" data-section="documents">Documents</a>
                    <a href="upload_documents.php" class="sidebar-link" data-section="upload">Upload Document</a>
                </div>
                <div class="sidebar-section sidebar-section-account">
                    <span class="sidebar-section-title">ACCOUNT</span>
                    <a href="settings.php" class="sidebar-link sidebar-link-settings" data-section="settings">Settings</a>
                </div>
            </nav>
        </aside>

        <main class="admin-main" style="background:#f8fafc;">
            <div class="admin-content" id="admin-content" style="background:#f8fafc; color:#1e293b;">
                <div class="admin-content-header-row">
                    <header class="admin-content-header">
                        <div class="documents-header">
                            <h1>Documents</h1>
                            <p>View uploaded documents.</p>
                        </div>
                    </header>
                    <div class="admin-content-icons">
                        <div class="admin-profile-wrap">
                            <button type="button" class="admin-icon-btn" id="profile-logout-btn" title="Profile and log out" aria-haspopup="true" aria-expanded="false" aria-label="Profile">
                                <svg class="icon-person" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
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
                <?php if (!$isViewMode): ?>
                <div class="documents-list-card">
                    <div class="documents-list-header">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
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
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($documentsList)): ?>
                                <tr><td colspan="4"><div class="documents-empty-state">No documents found.</div></td></tr>
                                <?php else: ?>
                                <?php foreach ($documentsList as $doc): ?>
                                <?php
                                    $docId = (string)($doc['_id'] ?? '');
                                    $docCode = (string)($doc['documentCode'] ?? '—');
                                    $docTitle = (string)($doc['documentTitle'] ?? '—');
                                    $fileName = (string)($doc['fileName'] ?? 'document.docx');
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($docCode); ?></td>
                                    <td><?php echo htmlspecialchars($docTitle); ?></td>
                                    <td><?php echo htmlspecialchars($fileName); ?></td>
                                    <td>
                                        <div class="documents-actions-row">
                                            <a class="documents-action-btn" href="documents.php?doc=<?php echo urlencode($docId); ?>">
                                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                                View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="detail-layout">
                    <div class="detail-toolbar">
                        <a class="toolbar-btn" href="documents.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                            Back
                        </a>
                        <?php if (!empty($documentsList)): ?>
                        <select class="toolbar-select" id="doc-switch-select" aria-label="Select document">
                            <?php foreach ($documentsList as $listDoc): ?>
                            <?php $listDocId = (string)($listDoc['_id'] ?? ''); ?>
                            <option value="<?php echo htmlspecialchars($listDocId); ?>" <?php echo $listDocId === $selectedDocumentId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)($listDoc['documentCode'] ?? 'DOC') . ' - ' . (string)($listDoc['documentTitle'] ?? 'Document')); ?>
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
                        <a class="toolbar-btn" href="documents.php?download=<?php echo urlencode($selectedDocumentId); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Download
                        </a>
                    </div>
                    <div class="detail-viewer">
                        <?php if ($isSelectedImage): ?>
                        <img class="viewer-image" id="viewer-image" src="documents.php?view=<?php echo urlencode($selectedDocumentId); ?>" alt="<?php echo htmlspecialchars($selectedFileName); ?>">
                        <?php elseif ($isSelectedPdf): ?>
                        <iframe class="viewer-frame" id="viewer-frame" src="documents.php?view=<?php echo urlencode($selectedDocumentId); ?>"></iframe>
                        <?php elseif ($isSelectedDocx): ?>
                        <div id="docx-container" style="width:100%;min-height:520px;background:#fff;border-radius:6px;border:1px solid #e2e8f0;overflow:visible;"></div>
                        <?php else: ?>
                        <div class="viewer-fallback">
                            <p>Preview not available for this file type.</p>
                            <p><strong><?php echo htmlspecialchars($selectedFileName); ?></strong></p>
                            <a class="toolbar-btn" href="documents.php?download=<?php echo urlencode($selectedDocumentId); ?>">Download file</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <?php if ($isViewMode && $isSelectedDocx): ?>
    <script src="https://cdn.jsdelivr.net/npm/jszip@3/dist/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.0/dist/docx-preview.min.js"></script>
    <script>
    (function() {
        var container = document.getElementById('docx-container');
        var pageCountLabel = document.getElementById('docx-page-count');
        var stampMeta = <?php echo $receivedStampMeta ? json_encode($receivedStampMeta, JSON_UNESCAPED_SLASHES) : 'null'; ?>;
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

        function hideEmbeddedStamp() {
            var imgs = container.querySelectorAll('img');
            for (var i = 0; i < imgs.length; i++) {
                var alt = (imgs[i].alt || '').toLowerCase();
                var title = (imgs[i].title || '').toLowerCase();
                if (alt.indexOf('fdreceivedstamp') !== -1 || title.indexOf('fdreceivedstamp') !== -1) {
                    var wrapper = imgs[i].closest('span, div') || imgs[i].parentNode;
                    if (wrapper && wrapper !== container) {
                        wrapper.style.display = 'none';
                    } else {
                        imgs[i].style.display = 'none';
                    }
                    return;
                }
            }
        }

        function applyReceivedStampOverlay() {
            if (!stampMeta || !stampMeta.image) return;
            var pages = container.querySelectorAll('.docx-wrapper > section.docx, .docx-wrapper > section, .docx > section');
            if (!pages.length) {
                var fb = container.querySelector('.docx-wrapper') || container.querySelector('.docx') || container.firstElementChild;
                if (fb) pages = [fb];
            }
            if (!pages.length) return;
            var pageIdx = Math.max(0, (parseInt(stampMeta.page, 10) || 1) - 1);
            var targetPage = pages[pageIdx] || pages[0];
            if (!targetPage) return;
            if (!targetPage.style.position || targetPage.style.position === 'static') {
                targetPage.style.position = 'relative';
            }

            hideEmbeddedStamp();

            var overlay = document.createElement('img');
            overlay.className = 'fd-stamp-overlay';
            overlay.src = stampMeta.image;
            overlay.alt = 'Receiving stamp';
            var w = Math.max(5, Math.min(60, parseFloat(stampMeta.width) || 18));
            var x = Math.max(1, Math.min(99, parseFloat(stampMeta.x) || 14));
            var y = Math.max(1, Math.min(99, parseFloat(stampMeta.y) || 14));
            overlay.style.width = w + '%';
            overlay.style.left = x + '%';
            overlay.style.top = y + '%';
            targetPage.appendChild(overlay);
        }

        fetch('documents.php?view=<?php echo urlencode($selectedDocumentId); ?>')
            .then(function(r) { if (!r.ok) throw new Error(); return r.blob(); })
            .then(function(blob) {
                if (typeof docx !== 'undefined' && docx.renderAsync) {
                    docx.renderAsync(blob, container, null, { breakPages: true, ignoreLastRenderedPageBreak: false }).then(function() {
                        updatePageCount();
                        applyReceivedStampOverlay();
                    });
                }
            })
            .catch(function() {
                container.innerHTML = '<p style="padding:20px;color:#64748b;text-align:center;">Could not load document preview.</p>';
                if (pageCountLabel) pageCountLabel.textContent = 'Pages: --';
            });
    })();
    </script>
    <?php endif; ?>

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

        var docSwitch = document.getElementById('doc-switch-select');
        if (docSwitch) {
            docSwitch.addEventListener('change', function() {
                var selectedId = (docSwitch.value || '').trim();
                if (!selectedId) return;
                window.location.href = 'documents.php?doc=' + encodeURIComponent(selectedId);
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
                if (frame) {
                    window.open(frame.src, '_blank');
                    return;
                }
                var docxC = document.getElementById('docx-container');
                if (docxC) {
                    var w2 = window.open('', '_blank');
                    if (w2) {
                        w2.document.write('<html><head><title>Print</title></head><body>' + docxC.innerHTML + '</body></html>');
                        w2.document.close();
                        w2.focus();
                        setTimeout(function() { w2.print(); }, 400);
                    }
                }
            });
        }
    })();
    </script>
</body>
</html>
