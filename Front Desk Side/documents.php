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

// Send document to Super Admin (record in sent_to_super_admin)
if (!empty($_GET['send']) && preg_match('/^[a-f0-9]{24}$/i', $_GET['send'])) {
    $sendId = $_GET['send'];
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare('SELECT document_code, document_title, file_name FROM documents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $sendId]);
        $doc = $stmt->fetch();
        if ($doc) {
            $docCode = $doc['document_code'] ?? '';
            $docTitle = $doc['document_title'] ?? '';
            $fileName = $doc['file_name'] ?? 'document.docx';
            createSuperAdminNotification($config, [
                'document_id' => $sendId,
                'document_code' => $docCode,
                'document_title' => $docTitle,
                'file_name' => $fileName,
                'sent_by_user_id' => $_SESSION['user_id'] ?? '',
                'sent_by_user_name' => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
            ]);
            activityLog($config, 'document_send_to_super_admin', [
                'module' => 'front_desk_documents',
                'document_id' => $sendId,
                'document_code' => (string)$docCode,
                'document_title' => (string)$docTitle,
            ]);
        }
    } catch (Exception $e) {}
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

$showSentToast = isset($_GET['sent']) && $_GET['sent'] === '1';
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
    </style>
</head>
<body class="admin-dashboard documents-page"<?php if ($showSentToast): ?> data-sent="1"<?php endif; ?>>
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
                                            <a href="documents.php?send=<?php echo urlencode($docId); ?>" class="documents-action-btn documents-action-send-super" title="Send to Super Admin" onclick="return confirm('Send this document to Super Admin?');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Send to Super Admin</a>
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
    })();
    </script>
</body>
</html>
