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

function frontDeskUploadDocumentColumns($pdo) {
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

function frontDeskUploadUserOfficeId($pdo, $userId) {
    if ((int)$userId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT office_id FROM users WHERE user_id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => (int)$userId]);
        $row = $stmt->fetch();
        if ($row && !empty($row['office_id'])) {
            return (int)$row['office_id'];
        }
    } catch (Exception $e) {
        return null;
    }
    return null;
}

$uploadError = trim((string)($_SESSION['frontdesk_upload_error'] ?? ''));
if ($uploadError !== '') {
    unset($_SESSION['frontdesk_upload_error']);
}
$showUploaded = isset($_GET['uploaded']) && $_GET['uploaded'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $documentCode = trim((string)($_POST['document_code'] ?? ''));
    $documentTitle = trim((string)($_POST['document_title'] ?? ''));
    $file = $_FILES['document_file'] ?? null;

    if ($documentCode === '' || $documentTitle === '') {
        $_SESSION['frontdesk_upload_error'] = 'Document code and title are required.';
        header('Location: upload_documents.php?error=1');
        exit;
    }
    if (!$file || empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
        $_SESSION['frontdesk_upload_error'] = 'Please choose a valid file.';
        header('Location: upload_documents.php?error=1');
        exit;
    }
    if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $_SESSION['frontdesk_upload_error'] = 'Upload failed. Please try again.';
        header('Location: upload_documents.php?error=1');
        exit;
    }
    if ((int)($file['size'] ?? 0) <= 0 || (int)($file['size'] ?? 0) > 10 * 1024 * 1024) {
        $_SESSION['frontdesk_upload_error'] = 'File must be greater than 0 bytes and at most 10MB.';
        header('Location: upload_documents.php?error=1');
        exit;
    }

    $origName = (string)($file['name'] ?? 'document.bin');
    $ext = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== 'docx') {
        $_SESSION['frontdesk_upload_error'] = 'Only .docx files are allowed.';
        header('Location: upload_documents.php?error=1');
        exit;
    }

    $mimeType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    $rootDir = dirname(__DIR__);
    $storageDir = $rootDir . '/storage/documents';
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true)) {
        $_SESSION['frontdesk_upload_error'] = 'Could not create document storage directory.';
        header('Location: upload_documents.php?error=1');
        exit;
    }

    $safeOriginal = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
    $storedFileName = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)), 0, 12) . '_' . $safeOriginal;
    $absolutePath = $storageDir . '/' . $storedFileName;
    $relativePath = 'storage/documents/' . $storedFileName;
    if (!move_uploaded_file((string)$file['tmp_name'], $absolutePath)) {
        $_SESSION['frontdesk_upload_error'] = 'Failed to store uploaded file.';
        header('Location: upload_documents.php?error=1');
        exit;
    }

    try {
        $pdo = dbPdo($config);
        $cols = frontDeskUploadDocumentColumns($pdo);
        if (empty($cols)) {
            throw new Exception('Documents table is unavailable.');
        }

        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        $officeId = frontDeskUploadUserOfficeId($pdo, $currentUserId);
        $insertCols = [];
        $insertVals = [];
        $params = [];

        $assignMap = [
            'tracking_code' => $documentCode,
            'subject' => $documentTitle,
            'details' => '',
            'document_code' => $documentCode,
            'document_title' => $documentTitle,
            'file_name' => $origName,
            'mime_type' => $mimeType,
            'file_size_bytes' => (int)$file['size'],
            'file_checksum_sha256' => hash_file('sha256', $absolutePath) ?: null,
            'storage_path' => $relativePath,
            'status' => 'active',
            'is_outgoing' => 0,
            'created_by' => $currentUserId > 0 ? $currentUserId : null,
            'requestor_office_id' => $officeId,
            'created_at' => dbNowUtcString(),
        ];

        foreach ($assignMap as $col => $value) {
            if (!isset($cols[$col])) {
                continue;
            }
            $insertCols[] = $col;
            $insertVals[] = ':' . $col;
            $params[':' . $col] = $value;
        }

        if (empty($insertCols)) {
            throw new Exception('No compatible columns found for documents insert.');
        }
        $sql = 'INSERT INTO documents (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertVals) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $docId = (int)$pdo->lastInsertId();

        activityLog($config, 'document_upload_front_desk', [
            'module' => 'front_desk_upload',
            'document_id' => (string)$docId,
            'tracking_code' => $documentCode,
            'subject' => $documentTitle,
        ]);
        createSuperAdminNotification($config, [
            'document_id' => $docId,
            'document_title' => $documentTitle,
            'sent_by_user_name' => $userName,
            'notification_type' => 'document_upload',
            'message' => $userName . ' uploaded a new document: ' . $documentTitle . ' (' . $documentCode . ')',
            'link' => 'documents.php?highlight=' . $docId,
        ]);
        header('Location: upload_documents.php?uploaded=1');
        exit;
    } catch (Exception $e) {
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
        $_SESSION['frontdesk_upload_error'] = 'Failed to save upload: ' . $e->getMessage();
        header('Location: upload_documents.php?error=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff – Upload Document</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/staff-dashboard.css">
    <style>
        /* Upload document page – design styles */
        .upload-page {
            background: #f5f7fa;
        }

        .upload-page .admin-content-header-row {
            background: #fff;
        }

        .upload-page .admin-main,
        .upload-page .admin-content-body {
            background: #f5f7fa !important;
        }

        .upload-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 6px 0;
            letter-spacing: -0.02em;
        }

        .upload-header p {
            font-size: 0.95rem;
            color: #64748b;
            margin: 0;
        }

        /* Upload cards */
        .upload-card {
            background: #fff;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .upload-card h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 6px 0;
        }

        .upload-card-sub {
            font-size: 0.9rem;
            color: #94a3b8;
            margin: 0 0 20px 0;
        }

        /* Document file drop zone */
        .upload-drop-zone {
            border: 2px dashed #ccc;
            border-radius: 8px;
            background: #fff;
            padding: 48px 24px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
        }

        .upload-drop-zone:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }

        .upload-drop-zone.dragover {
            border-color: #3498db;
            background: #f0f9ff;
        }

        .upload-drop-icon {
            color: #475569;
            margin-bottom: 12px;
        }

        .upload-drop-zone strong {
            display: block;
            font-size: 1rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }

        .upload-drop-hint {
            font-size: 0.85rem;
            color: #94a3b8;
        }

        .upload-drop-zone input[type="file"] {
            display: none;
        }

        /* Document details form */
        .upload-form-group {
            margin-bottom: 20px;
        }

        .upload-form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 500;
            color: #334155;
            margin-bottom: 8px;
        }

        .upload-form-group label .required {
            color: #dc2626;
        }

        .upload-form-group input,
        .upload-form-group select,
        .upload-form-group textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
            color: #1e293b;
            background: #fff;
            outline: none;
        }

        .upload-form-group input::placeholder,
        .upload-form-group textarea::placeholder {
            color: #94a3b8;
        }

        .upload-form-group input:focus,
        .upload-form-group select:focus,
        .upload-form-group textarea:focus {
            border-color: #64748b;
            box-shadow: 0 0 0 2px rgba(100, 116, 139, 0.15);
        }

        .upload-form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .upload-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 640px) {
            .upload-form-row {
                grid-template-columns: 1fr;
            }
        }

        /* Form buttons – at top */
        .upload-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-bottom: 24px;
        }

        .upload-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: opacity 0.2s, background 0.2s;
        }

        .upload-btn-cancel {
            background: #fff;
            border: 1px solid #ccc;
            color: #334155;
        }

        .upload-btn-cancel:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }

        .upload-btn-submit {
            background: #3498db;
            color: #fff;
        }

        .upload-btn-submit:hover {
            background: #2980b9;
        }
    </style>
</head>
<body class="admin-dashboard upload-page"<?php if ($showUploaded): ?> data-uploaded="1"<?php endif; ?><?php if ($uploadError !== ''): ?> data-upload-error="<?php echo htmlspecialchars($uploadError, ENT_QUOTES); ?>"<?php endif; ?>>
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
                    <a href="documents.php" class="sidebar-link" data-section="documents">
                        <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                        Documents
                    </a>
                    <a href="upload_documents.php" class="sidebar-link active" data-section="upload">
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

        <main class="admin-main" style="background:#f5f7fa;">
            <div class="admin-content" id="admin-content" style="background:#f5f7fa; color:#1e293b;">
                <div class="admin-content-header-row">
                    <header class="admin-content-header">
                        <div class="upload-header">
                            <h1>Upload Document</h1>
                            <p>Add new documents to the system</p>
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
            <div class="admin-content-body" id="admin-content-body" style="background:#f5f7fa;">
                <form action="#" method="post" enctype="multipart/form-data" id="upload-form">
                    <div class="upload-form-actions">
                        <a href="documents.php" class="upload-btn upload-btn-cancel">Cancel</a>
                        <button type="submit" class="upload-btn upload-btn-submit">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            Upload Document
                        </button>
                    </div>
                    <div class="upload-card">
                        <h2>Document File</h2>
                        <p class="upload-card-sub">Upload a DOCX file (max 10MB)</p>
                        <div class="upload-drop-zone" id="drop-zone" tabindex="0">
                            <input type="file" name="document_file" id="document_file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                            <svg class="upload-drop-icon" viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="2" stroke-dasharray="4 2"/>
                                <path d="M12 16V8M9 11l3-3 3 3"/>
                            </svg>
                            <strong>Drop your file here, or click to browse</strong>
                            <span class="upload-drop-hint">Supports Word (.docx) only</span>
                        </div>
                    </div>

                    <div class="upload-card">
                        <h2>Document Details</h2>
                        <p class="upload-card-sub">Provide required document information</p>
                        <div class="upload-form-group">
                            <label for="document_code">Document Code <span class="required">*</span></label>
                            <input type="text" id="document_code" name="document_code" placeholder="e.g., DOC-001" required>
                        </div>
                        <div class="upload-form-group">
                            <label for="document_title">Document Title <span class="required">*</span></label>
                            <input type="text" id="document_title" name="document_title" placeholder="Enter document title" required>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
    (function() {
        if (document.body.getAttribute('data-uploaded') === '1') {
            var okToast = document.createElement('div');
            okToast.setAttribute('role', 'status');
            okToast.textContent = 'Document uploaded successfully.';
            okToast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#22c55e;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(okToast);
            setTimeout(function() { okToast.remove(); }, 4000);
        }
        var uploadErr = document.body.getAttribute('data-upload-error') || '';
        if (uploadErr) {
            var errToast = document.createElement('div');
            errToast.setAttribute('role', 'status');
            errToast.textContent = uploadErr;
            errToast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#dc2626;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);max-width:420px;';
            document.body.appendChild(errToast);
            setTimeout(function() { errToast.remove(); }, 5000);
        }

        var btn = document.getElementById('profile-logout-btn');
        var dropdown = document.getElementById('profile-dropdown');
        if (btn && dropdown) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdown.hidden = !dropdown.hidden;
                btn.setAttribute('aria-expanded', dropdown.hidden ? 'false' : 'true');
            });
            document.addEventListener('click', function() {
                dropdown.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            });
            dropdown.addEventListener('click', function(e) { e.stopPropagation(); });
        }

        var dropZone = document.getElementById('drop-zone');
        var fileInput = document.getElementById('document_file');
        if (dropZone && fileInput) {
            dropZone.addEventListener('click', function() { fileInput.click(); });
            dropZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });
            dropZone.addEventListener('dragleave', function() {
                dropZone.classList.remove('dragover');
            });
            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                dropZone.classList.remove('dragover');
                if (e.dataTransfer.files.length) fileInput.files = e.dataTransfer.files;
            });
        }
    })();
    </script>
</body>
</html>
