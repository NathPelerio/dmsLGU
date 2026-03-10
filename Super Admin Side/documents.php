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
    $postedNotes = trim((string)($_POST['notes'] ?? ''));
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
                ':sent_by_user_id' => (($_SESSION['user_id'] ?? '') !== '' ? $_SESSION['user_id'] : null),
                ':sent_by_user_name' => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
                ':sent_at' => dbNowUtcString(),
            ]);
            if ($postedNotes !== '') {
                $noteUp = $pdo->prepare('UPDATE documents SET notes = :notes, updated_at = :updated_at WHERE id = :id');
                $noteUp->execute([':notes' => $postedNotes, ':updated_at' => dbNowUtcString(), ':id' => $sendId]);
            }
            activityLog($config, 'document_send_to_admin', [
                'module' => 'super_admin_documents',
                'document_id' => $sendId,
                'document_code' => (string)$docCode,
                'document_title' => (string)$docTitle,
            ]);
        }
    } catch (Exception $e) {
        error_log('[send_to_admin] ' . $e->getMessage());
    }
    header('Location: documents.php?sent=1');
    exit;
}

// Send document to Department Head from Super Admin.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_to_head') {
    $docId = trim((string)($_POST['document_id'] ?? ''));
    $postedNotes = trim((string)($_POST['notes'] ?? ''));
    $officeIds = isset($_POST['office_id']) ? (is_array($_POST['office_id']) ? $_POST['office_id'] : [$_POST['office_id']]) : [];
    $officeIds = array_filter(array_map('trim', $officeIds));
    $officeIds = array_values(array_unique($officeIds));
    $sendHeadError = '';
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
                    if ($postedNotes !== '') {
                        $noteUp = $pdo->prepare('UPDATE documents SET notes = :notes, updated_at = :updated_at WHERE id = :id');
                        $noteUp->execute([':notes' => $postedNotes, ':updated_at' => dbNowUtcString(), ':id' => $docId]);
                    }
                    activityLog($config, 'document_send_to_department_heads', [
                        'module' => 'super_admin_documents',
                        'document_id' => $docId,
                        'target_count' => (string)$sentCount,
                    ]);
                    header('Location: documents.php?sent_head=1&count=' . (int)$sentCount);
                    exit;
                } else {
                    $sendHeadError = 'No valid department head found for the selected office.';
                }
            } else {
                $sendHeadError = 'Document not found.';
            }
        } catch (Exception $e) {
            $sendHeadError = 'Database error: ' . $e->getMessage();
            error_log('[send_to_head] ' . $e->getMessage());
        }
    } else {
        $sendHeadError = 'Missing document ID or office.';
    }
    header('Location: documents.php?send_error=1&detail=' . urlencode($sendHeadError));
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
$showSentHeadToast = isset($_GET['sent_head']) && $_GET['sent_head'] === '1';
$showSentHeadCount = isset($_GET['count']) ? (int)$_GET['count'] : 0;
$showAddedToast = isset($_GET['added']) && $_GET['added'] === '1';
$showSendErrorToast = isset($_GET['send_error']) && $_GET['send_error'] === '1';
$sendErrorDetail = isset($_GET['detail']) ? trim((string)$_GET['detail']) : '';

$selectedDocumentId = trim((string)($_GET['doc'] ?? ''));
$isViewMode = ($selectedDocumentId !== '' && preg_match('/^[a-f0-9]{24}$/i', $selectedDocumentId));
$highlightDocumentId = trim((string)($_GET['highlight'] ?? ''));

$selectedDocument = null;
if ($isViewMode) {
    foreach ($sentList as $row) {
        if ((string)($row['documentId'] ?? '') === $selectedDocumentId) {
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

$progressEvents = [];
$currentHolderLabel = 'No holder yet';
$superAdminNote = '';
if ($isViewMode) {
    try {
        $pdo = dbPdo($config);
        $noteStmt = $pdo->prepare('SELECT notes FROM documents WHERE id = :id LIMIT 1');
        $noteStmt->execute([':id' => $selectedDocumentId]);
        $noteRow = $noteStmt->fetch();
        if ($noteRow) {
            $superAdminNote = trim((string)($noteRow['notes'] ?? ''));
        }
        $routeStmt = $pdo->prepare(
            'SELECT sth.office_name, sth.sent_by_user_name, sth.sent_at, sth.read_at
             FROM sent_to_department_heads sth
             WHERE sth.document_id = :document_id
             ORDER BY sth.sent_at ASC, sth.id ASC'
        );
        $routeStmt->execute([':document_id' => $selectedDocumentId]);
        $routeRows = $routeStmt->fetchAll() ?: [];
        foreach ($routeRows as $row) {
            $deptName = trim((string)($row['office_name'] ?? 'Unknown Department'));
            $progressEvents[] = [
                'department' => ($deptName !== '' ? $deptName : 'Unknown Department'),
                'action' => 'routed',
                'received_at' => (string)($row['sent_at'] ?? ''),
                'processed_at' => (string)($row['read_at'] ?? ''),
                'notes' => 'Sent by ' . trim((string)($row['sent_by_user_name'] ?? 'User')),
            ];
        }
        $hasProgressTable = false;
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'document_progress'");
        if ($tableCheck && $tableCheck->fetch()) {
            $hasProgressTable = true;
        }
        if ($hasProgressTable) {
            $dpStmt = $pdo->prepare(
                'SELECT department, action, received_at, processed_at, notes
                 FROM document_progress
                 WHERE document_id = :document_id
                 ORDER BY received_at ASC, id ASC'
            );
            $dpStmt->execute([':document_id' => $selectedDocumentId]);
            $dpRows = $dpStmt->fetchAll() ?: [];
            foreach ($dpRows as $row) {
                $deptName = trim((string)($row['department'] ?? 'Unknown Department'));
                $progressEvents[] = [
                    'department' => ($deptName !== '' ? $deptName : 'Unknown Department'),
                    'action' => trim((string)($row['action'] ?? 'received')),
                    'received_at' => (string)($row['received_at'] ?? ''),
                    'processed_at' => (string)($row['processed_at'] ?? ''),
                    'notes' => trim((string)($row['notes'] ?? '')),
                ];
            }
        }
        usort($progressEvents, static function ($a, $b) {
            $timeA = strtotime((string)($a['received_at'] ?? '')) ?: 0;
            $timeB = strtotime((string)($b['received_at'] ?? '')) ?: 0;
            return $timeA <=> $timeB;
        });
        if (!empty($progressEvents)) {
            $latest = $progressEvents[count($progressEvents) - 1];
            $latestDepartment = trim((string)($latest['department'] ?? ''));
            $latestAction = trim((string)($latest['action'] ?? 'received'));
            $currentHolderLabel = ($latestDepartment !== '' ? $latestDepartment : 'Unknown Department') . ' (' . $latestAction . ')';
        }
    } catch (Exception $e) {
        error_log('[super_admin_documents progress] ' . $e->getMessage());
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Documents</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/profile_modal_super_admin.css">
    <link rel="stylesheet" href="../Admin%20Side/assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="../Admin%20Side/assets/css/admin-offices.css">
    <link rel="stylesheet" href="assets/css/sidebar_super_admin.css">
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

        /* ── DETAIL VIEW (left viewer + right comments) ─────── */
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
            height: 100%; min-height: 0; overflow: auto;
        }
        .detail-toolbar {
            padding: 10px 14px; border-bottom: 1px solid #e2e8f0;
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
            background: #fff; flex-shrink: 0;
            position: sticky; top: 0; z-index: 4;
        }
        .detail-toolbar-btn {
            border: 1px solid #dbe3ef; background: #fff; color: #1e293b;
            border-radius: 8px; font-size: 0.82rem; padding: 7px 12px;
            text-decoration: none; cursor: pointer; font-weight: 600;
            display: inline-flex; align-items: center; gap: 5px;
            transition: background 0.12s; font-family: inherit;
        }
        .detail-toolbar-btn:hover { background: #f1f5f9; }
        .detail-toolbar-btn svg { width: 15px; height: 15px; }
        .detail-toolbar-select {
            border: 1px solid #dbe3ef; background: #fff; color: #1e293b;
            border-radius: 8px; font-size: 0.82rem; padding: 7px 10px;
            font-weight: 600; min-width: 210px; max-width: 280px;
        }
        .detail-toolbar-page-count {
            margin-left: auto; font-size: 0.8rem; color: #475569;
            font-weight: 700; background: #f8fafc; border: 1px solid #dbe3ef;
            border-radius: 8px; padding: 7px 10px; white-space: nowrap;
        }
        .detail-toolbar-btn.btn-send-admin { border-color: #86efac; color: #047857; background: #f0fdf4; }
        .detail-toolbar-btn.btn-send-admin:hover { background: #dcfce7; }
        .detail-toolbar-btn.btn-send-head { border-color: #c4b5fd; color: #6d28d9; background: #f5f3ff; }
        .detail-toolbar-btn.btn-send-head:hover { background: #ede9fe; }
        .detail-toolbar-btn.btn-view-progress { border-color: #fcd34d; color: #92400e; background: #fffbeb; }
        .detail-toolbar-btn.btn-view-progress:hover { background: #fef3c7; }
        .detail-viewer {
            background: #f1f5f9; padding: 16px; overflow: visible;
            flex: 1; min-height: 0;
        }
        .viewer-image { max-width: 100%; max-height: 100%; border-radius: 6px; border: 1px solid #e2e8f0; background: #fff; }
        .viewer-frame { width: 100%; min-height: 78vh; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; }
        .viewer-fallback {
            border: 1px dashed #94a3b8; border-radius: 10px; background: #fff;
            padding: 24px; text-align: center; color: #475569; max-width: 420px;
        }
        .viewer-fallback p { margin: 0 0 10px; }
        #detail-docx-container .docx-wrapper { background: #e2e8f0; padding: 18px 12px; }
        #detail-docx-container .docx-wrapper > section.docx {
            margin: 0 auto 18px; box-shadow: 0 6px 18px rgba(15, 23, 42, 0.14);
            border: 1px solid #dbe3ef;
        }
        #detail-docx-container .docx-wrapper > section.docx:last-child { margin-bottom: 0; }

        /* ── COMMENTS PANEL (right side) ──────────────── */
        .detail-right {
            display: flex; flex-direction: column; background: #fff;
            height: 100%; min-height: 420px; overflow: hidden;
        }
        .comments-header {
            padding: 14px 16px; border-bottom: 1px solid #e2e8f0;
            font-size: 0.92rem; font-weight: 700; color: #0f172a;
        }
        .superadmin-note-box {
            margin: 12px 14px 0;
            border: 1px solid #fde68a;
            background: #fffbeb;
            border-radius: 10px;
            padding: 10px 11px;
        }
        .superadmin-note-label {
            font-size: 0.7rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #92400e;
            font-weight: 800;
            margin-bottom: 5px;
        }
        .superadmin-note-text {
            font-size: 0.82rem;
            color: #78350f;
            line-height: 1.45;
            white-space: pre-wrap;
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
        .comment-meta { display: flex; align-items: center; gap: 8px; }
        .comment-name { font-size: 0.82rem; font-weight: 700; color: #0f172a; }
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
        .comment-send-btn {
            border: none; background: #2563eb; color: #fff; border-radius: 10px;
            padding: 0 16px; font-size: 0.84rem; font-weight: 700; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            transition: background 0.15s;
        }
        .comment-send-btn:hover { background: #1d4ed8; }
        .comment-send-btn svg { width: 18px; height: 18px; }

        /* ── OFF-CANVAS PROGRESS TRACKER ──────────────── */
        .progress-offcanvas-backdrop {
            position: fixed; inset: 0;
            background: rgba(15, 23, 42, 0.35);
            opacity: 0; visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            z-index: 2147483000;
        }
        .progress-offcanvas-backdrop.open { opacity: 1; visibility: visible; }
        .progress-offcanvas {
            position: fixed; top: 0; right: 0;
            width: min(420px, 95vw); height: 100vh;
            background: #f8fafc; border-left: 1px solid #dbe3ef;
            box-shadow: -18px 0 36px rgba(15, 23, 42, 0.18);
            transform: translateX(104%);
            transition: transform 0.24s ease;
            z-index: 2147483001;
            display: flex; flex-direction: column; isolation: isolate;
        }
        .progress-offcanvas.open { transform: translateX(0); }
        .progress-offcanvas-head {
            padding: 14px 16px; border-bottom: 1px solid #e2e8f0; background: #fff;
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
        }
        .progress-offcanvas-title { margin: 0; font-size: 0.96rem; font-weight: 700; color: #0f172a; }
        .progress-offcanvas-sub { margin: 2px 0 0; font-size: 0.74rem; color: #64748b; }
        .progress-close-btn {
            border: none; background: #e2e8f0; color: #334155;
            width: 30px; height: 30px; border-radius: 999px;
            cursor: pointer; font-size: 1rem; line-height: 1;
        }
        .progress-close-btn:hover { background: #cbd5e1; }
        .progress-offcanvas-body { padding: 14px 16px 18px; overflow-y: auto; flex: 1; }
        .progress-current-holder {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 6px 10px; border: 1px solid #cbd5e1; background: #fff;
            border-radius: 999px; font-size: 0.78rem; color: #0f172a; margin-bottom: 12px;
        }
        .progress-timeline {
            position: relative; margin: 0; padding: 0 0 0 28px; list-style: none;
        }
        .progress-timeline::before {
            content: ''; position: absolute; left: 11px; top: 2px; bottom: 2px;
            width: 2px; background: #cbd5e1;
        }
        .progress-item { position: relative; margin-bottom: 12px; }
        .progress-item:last-child { margin-bottom: 0; }
        .progress-dot {
            position: absolute; left: -21px; top: 6px;
            width: 12px; height: 12px; border-radius: 999px;
            background: #2563eb; border: 2px solid #fff; box-shadow: 0 0 0 1px #93c5fd;
        }
        .progress-card {
            background: #fff; border: 1px solid #e2e8f0;
            border-radius: 10px; padding: 9px 10px;
        }
        .progress-item-head {
            display: flex; align-items: baseline; justify-content: space-between;
            gap: 8px; margin-bottom: 4px;
        }
        .progress-item-dept { font-size: 0.82rem; font-weight: 700; color: #0f172a; }
        .progress-item-action {
            font-size: 0.72rem; color: #1d4ed8; background: #dbeafe;
            border-radius: 999px; padding: 2px 8px; text-transform: capitalize;
        }
        .progress-meta { font-size: 0.74rem; color: #475569; margin: 2px 0; }
        .progress-note {
            margin-top: 5px; font-size: 0.74rem; color: #334155;
            background: #f8fafc; border-radius: 8px; padding: 5px 7px;
        }
        .progress-empty {
            font-size: 0.8rem; color: #64748b; background: #fff;
            border: 1px dashed #cbd5e1; border-radius: 8px; padding: 10px;
        }

        @media (max-width: 900px) {
            .detail-layout { grid-template-columns: 1fr; height: auto; overflow: visible; }
            .detail-left { border-right: none; border-bottom: 1px solid #e2e8f0; height: auto; overflow: visible; }
            .detail-right { position: static; height: auto; min-height: 0; overflow: visible; }
            .viewer-frame { min-height: 520px; }
            .detail-toolbar-page-count { margin-left: 0; }
        }
    </style>
</head>
<body<?php if (!empty($showSentToast)): ?> data-sent="1"<?php endif; ?><?php if (!empty($showSentHeadToast)): ?> data-sent-head="1" data-sent-head-count="<?= (int)$showSentHeadCount ?>"<?php endif; ?><?php if (!empty($showAddedToast)): ?> data-added="1"<?php endif; ?><?php if (!empty($addError)): ?> data-add-error="1"<?php endif; ?><?php if (!empty($showSendErrorToast)): ?> data-send-error="1"<?php endif; ?><?php if ($sendErrorDetail !== ''): ?> data-send-error-detail="<?= htmlspecialchars($sendErrorDetail) ?>"<?php endif; ?>>
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
                <?php if (!$isViewMode): ?>
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
                                    <td><a href="documents.php?doc=<?= urlencode($docId) ?>" class="doc-file-link"><?= htmlspecialchars($sent['fileName'] ?? 'document.docx') ?></a></td>
                                    <td><span class="document-status document-status-<?= strtolower(htmlspecialchars($sentStatus)) ?>"><?= htmlspecialchars($sentStatus) ?></span></td>
                                    <td><?= htmlspecialchars($sent['sentAtFormatted'] ?? '—') ?></td>
                                    <td>
                                        <div class="documents-actions-row">
                                            <a href="documents.php?doc=<?= urlencode($docId) ?>" class="documents-action-btn documents-action-open" title="View document"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</a>
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

                <?php else: ?>
                <!-- ═══ DETAIL VIEW ═══ -->
                <div class="detail-layout">
                    <div class="detail-left">
                        <div class="detail-toolbar">
                            <a class="detail-toolbar-btn" href="documents.php">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                                Back
                            </a>
                            <?php if (!empty($sentList)): ?>
                            <select class="detail-toolbar-select" id="detail-doc-switch" aria-label="Select document">
                                <?php foreach ($sentList as $listDoc): ?>
                                    <?php $listDocId = (string)($listDoc['documentId'] ?? ''); ?>
                                    <option value="<?php echo htmlspecialchars($listDocId); ?>" <?php echo $listDocId === $selectedDocumentId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(($listDoc['documentCode'] ?? 'DOC') . ' - ' . ($listDoc['documentTitle'] ?? 'Document')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                            <?php if ($isSelectedDocx): ?>
                            <span class="detail-toolbar-page-count" id="detail-page-count">Pages: --</span>
                            <?php endif; ?>
                            <button type="button" class="detail-toolbar-btn" id="detail-print-btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                Print
                            </button>
                            <a class="detail-toolbar-btn" href="documents.php?download=<?php echo urlencode($selectedDocumentId); ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                Download
                            </a>
                            <button type="button" class="detail-toolbar-btn btn-send-admin detail-send-admin-trigger" data-doc-id="<?php echo htmlspecialchars($selectedDocumentId); ?>" data-doc-name="<?php echo htmlspecialchars($selectedFileName); ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                Send to Admin
                            </button>
                            <button type="button" class="detail-toolbar-btn btn-view-progress" id="open-progress-btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                                View Progress
                            </button>
                        </div>
                        <div class="detail-viewer">
                            <?php if ($isSelectedImage): ?>
                                <img class="viewer-image" id="detail-viewer-image" src="documents.php?view=<?php echo urlencode($selectedDocumentId); ?>" alt="<?php echo htmlspecialchars($selectedFileName); ?>">
                            <?php elseif ($isSelectedPdf): ?>
                                <iframe class="viewer-frame" id="detail-viewer-frame" src="documents.php?view=<?php echo urlencode($selectedDocumentId); ?>"></iframe>
                            <?php elseif ($isSelectedDocx): ?>
                                <div id="detail-docx-container" style="width:100%;min-height:520px;background:#fff;border-radius:6px;border:1px solid #e2e8f0;overflow:visible;"></div>
                            <?php else: ?>
                                <div class="viewer-fallback">
                                    <p>Preview not available for this file type.</p>
                                    <p><strong><?php echo htmlspecialchars($selectedFileName); ?></strong></p>
                                    <a class="detail-toolbar-btn" href="documents.php?download=<?php echo urlencode($selectedDocumentId); ?>">Download file</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="detail-right">
                        <div class="comments-header">Comments &amp; Notes</div>
                        <div class="superadmin-note-box">
                            <div class="superadmin-note-label">Super Admin Note</div>
                            <div class="superadmin-note-text"><?php echo $superAdminNote !== '' ? nl2br(htmlspecialchars($superAdminNote)) : 'No note provided.'; ?></div>
                        </div>
                        <div class="comments-list" id="detail-comments-list">
                            <?php if ($selectedDocument): ?>
                            <div class="comment-item">
                                <div class="comment-avatar sa"><?php echo mb_strtoupper(mb_substr((string)($selectedDocument['sent_by_user_name'] ?? $userName ?? 'S'), 0, 1)); ?></div>
                                <div class="comment-body">
                                    <div class="comment-meta">
                                        <div class="comment-name"><?php echo htmlspecialchars((string)($selectedDocument['sent_by_user_name'] ?? $userName ?? 'Super Admin')); ?></div>
                                    </div>
                                    <div class="comment-text">Document "<strong><?php echo htmlspecialchars((string)($selectedDocument['documentTitle'] ?? '')); ?></strong>" — <?php echo htmlspecialchars((string)($selectedDocument['documentCode'] ?? '')); ?></div>
                                    <div class="comment-time"><?php echo htmlspecialchars((string)($selectedDocument['sentAtFormatted'] ?? '')); ?></div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="comments-empty">No comments yet.</div>
                            <?php endif; ?>
                        </div>
                        <div class="comment-compose">
                            <input type="text" class="comment-input" id="detail-comment-input" placeholder="Write a comment...">
                            <button type="button" class="comment-send-btn" id="detail-comment-send">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="progress-offcanvas-backdrop" id="progress-offcanvas-backdrop"></div>
                <aside class="progress-offcanvas" id="progress-offcanvas" aria-hidden="true">
                    <div class="progress-offcanvas-head">
                        <div>
                            <h3 class="progress-offcanvas-title">Document Progress Tracker</h3>
                            <p class="progress-offcanvas-sub">Track where and when this document moved.</p>
                        </div>
                        <button type="button" class="progress-close-btn" id="close-progress-btn" aria-label="Close progress tracker">&times;</button>
                    </div>
                    <div class="progress-offcanvas-body">
                        <div class="progress-current-holder">
                            <strong>Current Holder:</strong>
                            <span><?php echo htmlspecialchars($currentHolderLabel); ?></span>
                        </div>
                        <?php if (!empty($progressEvents)): ?>
                            <ol class="progress-timeline">
                                <?php foreach ($progressEvents as $event): ?>
                                    <?php
                                        $receivedAt = trim((string)($event['received_at'] ?? ''));
                                        $processedAt = trim((string)($event['processed_at'] ?? ''));
                                        $receivedDisplay = '—';
                                        $processedDisplay = '—';
                                        if ($receivedAt !== '') {
                                            try { $dt = new DateTime($receivedAt); $dt->setTimezone(new DateTimeZone('Asia/Manila')); $receivedDisplay = $dt->format('M j, Y g:i A'); } catch (Exception $e) {}
                                        }
                                        if ($processedAt !== '') {
                                            try { $dt2 = new DateTime($processedAt); $dt2->setTimezone(new DateTimeZone('Asia/Manila')); $processedDisplay = $dt2->format('M j, Y g:i A'); } catch (Exception $e) {}
                                        }
                                    ?>
                                    <li class="progress-item">
                                        <span class="progress-dot"></span>
                                        <div class="progress-card">
                                            <div class="progress-item-head">
                                                <span class="progress-item-dept"><?php echo htmlspecialchars((string)($event['department'] ?? '')); ?></span>
                                                <span class="progress-item-action"><?php echo htmlspecialchars((string)($event['action'] ?? '')); ?></span>
                                            </div>
                                            <div class="progress-meta"><strong>Received:</strong> <?php echo htmlspecialchars($receivedDisplay); ?></div>
                                            <div class="progress-meta"><strong>Processed:</strong> <?php echo htmlspecialchars($processedDisplay); ?></div>
                                            <?php if (trim((string)($event['notes'] ?? '')) !== ''): ?>
                                                <div class="progress-note"><?php echo htmlspecialchars((string)($event['notes'] ?? '')); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            <div class="progress-empty">No progress events yet for this document.</div>
                        <?php endif; ?>
                    </div>
                </aside>
                <?php endif; ?>
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
                                data-office-id="<?= htmlspecialchars($d['id']) ?>"
                                data-head="<?= htmlspecialchars($d['head']) ?>"
                                <?= $d['head'] === '' ? 'disabled' : '' ?>
                            >
                                <?= htmlspecialchars($d['department']) ?><?= $d['head'] !== '' ? '' : ' (No assigned head)' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="stamp-auto-head" id="stamp-head-preview">Assigned head: —</small>
                    </label>
                    <label class="full" data-for-types="approved,received,endorsement">Notes <small style="font-weight:400;color:#64748b;">(optional)</small>
                        <textarea id="stamp-notes-input" placeholder="Add notes or remarks..." rows="2"></textarea>
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
        <input type="hidden" name="action" id="send-admin-action" value="send_to_admin">
        <input type="hidden" name="document_id" id="send-admin-doc-id" value="">
        <input type="hidden" name="office_id" id="send-admin-office-id" value="">
        <input type="hidden" name="stamp_image_data" id="send-admin-stamp-image-data" value="">
        <input type="hidden" name="stamp_width_pct" id="send-admin-width" value="18">
        <input type="hidden" name="stamp_x_pct" id="send-admin-x" value="82">
        <input type="hidden" name="stamp_y_pct" id="send-admin-y" value="84">
        <input type="hidden" name="notes" id="send-admin-notes" value="">
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

    <?php if ($isViewMode && $isSelectedDocx): ?>
    <script>
    (function() {
        var container = document.getElementById('detail-docx-container');
        var pageCountLabel = document.getElementById('detail-page-count');
        if (!container) return;
        function updatePageCount() {
            if (!pageCountLabel) return;
            var pageNodes = container.querySelectorAll('.docx-wrapper > section.docx');
            if (!pageNodes || pageNodes.length < 1) pageNodes = container.querySelectorAll('section.docx');
            var total = pageNodes ? pageNodes.length : 0;
            pageCountLabel.textContent = 'Pages: ' + (total > 0 ? total : 1);
        }
        fetch('documents.php?view=<?php echo urlencode($selectedDocumentId); ?>')
            .then(function(r) { if (!r.ok) throw new Error(); return r.blob(); })
            .then(function(blob) {
                if (typeof docx !== 'undefined' && docx.renderAsync) {
                    docx.renderAsync(blob, container, null, { breakPages: true, ignoreLastRenderedPageBreak: false }).then(function() { updatePageCount(); });
                }
            })
            .catch(function() {
                container.innerHTML = '<p style="padding:20px;color:#64748b;text-align:center;">Could not load document preview.</p>';
                if (pageCountLabel) pageCountLabel.textContent = 'Pages: --';
            });
    })();
    </script>
    <?php endif; ?>

    <?php if ($isViewMode): ?>
    <script>
    (function() {
        var docSwitch = document.getElementById('detail-doc-switch');
        if (docSwitch) {
            docSwitch.addEventListener('change', function() {
                var id = (docSwitch.value || '').trim();
                if (id) window.location.href = 'documents.php?doc=' + encodeURIComponent(id);
            });
        }

        var printBtn = document.getElementById('detail-print-btn');
        if (printBtn) {
            printBtn.addEventListener('click', function() {
                var img = document.getElementById('detail-viewer-image');
                if (img && img.src) {
                    var w = window.open('', '_blank');
                    if (w) { w.document.write('<html><head><title>Print</title></head><body style="margin:0;text-align:center;"><img src="' + img.src + '" style="max-width:100%;"></body></html>'); w.document.close(); w.focus(); setTimeout(function() { w.print(); }, 350); }
                    return;
                }
                var frame = document.getElementById('detail-viewer-frame');
                if (frame) { window.open(frame.src, '_blank'); return; }
                var docxC = document.getElementById('detail-docx-container');
                if (docxC) {
                    var w = window.open('', '_blank');
                    if (w) { w.document.write('<html><head><title>Print</title></head><body>' + docxC.innerHTML + '</body></html>'); w.document.close(); w.focus(); setTimeout(function() { w.print(); }, 400); }
                }
            });
        }

        var openProgressBtn = document.getElementById('open-progress-btn');
        var closeProgressBtn = document.getElementById('close-progress-btn');
        var progressOffcanvas = document.getElementById('progress-offcanvas');
        var progressBackdrop = document.getElementById('progress-offcanvas-backdrop');
        if (progressOffcanvas && progressOffcanvas.parentNode !== document.body) document.body.appendChild(progressOffcanvas);
        if (progressBackdrop && progressBackdrop.parentNode !== document.body) document.body.appendChild(progressBackdrop);
        function closeProgressPanel() {
            if (!progressOffcanvas || !progressBackdrop) return;
            progressOffcanvas.classList.remove('open');
            progressBackdrop.classList.remove('open');
            progressOffcanvas.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }
        function openProgressPanel() {
            if (!progressOffcanvas || !progressBackdrop) return;
            progressOffcanvas.classList.add('open');
            progressBackdrop.classList.add('open');
            progressOffcanvas.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }
        if (openProgressBtn) openProgressBtn.addEventListener('click', openProgressPanel);
        if (closeProgressBtn) closeProgressBtn.addEventListener('click', closeProgressPanel);
        if (progressBackdrop) progressBackdrop.addEventListener('click', closeProgressPanel);
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeProgressPanel(); });

        var commentInput = document.getElementById('detail-comment-input');
        var commentSendBtn = document.getElementById('detail-comment-send');
        var commentsList = document.getElementById('detail-comments-list');
        if (commentInput && commentSendBtn && commentsList) {
            function addComment() {
                var txt = (commentInput.value || '').trim();
                if (!txt) return;
                var now = new Date();
                var ts = now.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
                var item = document.createElement('div');
                item.className = 'comment-item';
                item.innerHTML = '<div class="comment-avatar sa"><?php echo mb_strtoupper(mb_substr($userName, 0, 1)); ?></div><div class="comment-body"><div class="comment-name"><?php echo htmlspecialchars($userName); ?></div><div class="comment-text"></div><div class="comment-time">' + ts + '</div></div>';
                item.querySelector('.comment-text').textContent = txt;
                commentsList.appendChild(item);
                commentsList.scrollTop = commentsList.scrollHeight;
                commentInput.value = '';
            }
            commentSendBtn.addEventListener('click', addComment);
            commentInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); addComment(); } });
        }

        document.querySelectorAll('.detail-send-admin-trigger').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var docId = btn.getAttribute('data-doc-id') || '';
                var docName = btn.getAttribute('data-doc-name') || 'document.docx';
                if (typeof openSendAdminModal === 'function') {
                    openSendAdminModal(docId, docName);
                }
            });
        });
    })();
    </script>
    <?php endif; ?>

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
        if (document.body.getAttribute('data-sent-head') === '1') {
            var sentHeadCount = parseInt(document.body.getAttribute('data-sent-head-count') || '0', 10) || 0;
            var sentHeadToast = document.createElement('div');
            sentHeadToast.setAttribute('role', 'status');
            sentHeadToast.textContent = sentHeadCount === 1 ? 'Document sent to 1 department head.' : 'Document sent to ' + sentHeadCount + ' department heads.';
            sentHeadToast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#22c55e;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(sentHeadToast);
            setTimeout(function() { sentHeadToast.remove(); }, 4200);
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
            var errDetail = document.body.getAttribute('data-send-error-detail') || '';
            var errMsg = 'Could not send document.';
            if (errDetail) errMsg += ' ' + errDetail;
            var errToast = document.createElement('div');
            errToast.setAttribute('role', 'status');
            errToast.textContent = errMsg;
            errToast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#ef4444;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);max-width:480px;';
            document.body.appendChild(errToast);
            setTimeout(function() { errToast.remove(); }, 6000);
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
        var sendAdminAction = document.getElementById('send-admin-action');
        var sendAdminOfficeId = document.getElementById('send-admin-office-id');
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
        var stampNotesInput = document.getElementById('stamp-notes-input');
        var sendAdminNotesField = document.getElementById('send-admin-notes');
        var sendStampNode = null;
        var sendStampCfg = { width: 18, x: 82, y: 84, tilt: 0 };
        var sendStampAwaitingPlacement = false;
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
            if (sendAdminSubmit) {
                sendAdminSubmit.textContent = activeStampType === 'endorsement' ? 'Send to Department Head' : 'Send to Admin';
            }
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

        function updateSendStampPlacementCursor() {
            if (!sendAdminContainer) return;
            sendAdminContainer.style.cursor = sendStampAwaitingPlacement ? 'crosshair' : '';
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

        function clearSendStampNode() {
            if (sendStampNode && sendStampNode.parentNode) {
                sendStampNode.parentNode.removeChild(sendStampNode);
            }
            sendStampNode = null;
        }

        function placeSendStampAtClientPoint(clientX, clientY) {
            if (!generatedStampData || !sendAdminContainer) return false;
            ensureSendStampNode();
            if (!sendStampNode) return false;
            var targetEl = getStampTargetElement(sendAdminContainer);
            if (!targetEl) return false;
            var rect = targetEl.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) return false;
            sendStampCfg.x = ((clientX - rect.left) / rect.width) * 100;
            sendStampCfg.y = ((clientY - rect.top) / rect.height) * 100;
            applySendStampStyles();
            sendStampAwaitingPlacement = false;
            updateSendStampPlacementCursor();
            return true;
        }

        window.openSendAdminModal = openSendAdminModal;
        function openSendAdminModal(docId, docName) {
            if (!sendAdminModal || !sendAdminContainer) return;
            sendStampCfg = { width: 18, x: 82, y: 84, tilt: 0 };
            sendStampNode = null;
            sendStampAwaitingPlacement = false;
            generatedStampData = '';
            updateSendStampPlacementCursor();
            if (sendAdminDocId) sendAdminDocId.value = docId || '';
            if (sendAdminStampImageData) sendAdminStampImageData.value = '';
            activeStampType = 'approved';
            updateStampFieldVisibility();
            var now = new Date();
            if (stampDateInput) stampDateInput.value = toYmdLocal(now);
            if (stampTimeInput) stampTimeInput.value = toHmLocal(now);
            if (stampByInput) stampByInput.value = <?php echo json_encode((string)$welcomeUsername); ?>;
            if (stampToInput) stampToInput.value = '';
            if (stampNotesInput) stampNotesInput.value = '';
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
            sendStampAwaitingPlacement = false;
            updateSendStampPlacementCursor();
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
                clearSendStampNode();
                sendStampAwaitingPlacement = true;
                updateSendStampPlacementCursor();
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
                if (sendStampAwaitingPlacement && generatedStampData) {
                    if (placeSendStampAtClientPoint(e.clientX, e.clientY)) {
                        e.preventDefault();
                    }
                    return;
                }
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
                if (!sendStampNode || sendStampAwaitingPlacement) {
                    alert('Please click on the document preview to place the stamp first.');
                    return;
                }
                if (sendAdminAction) sendAdminAction.value = (activeStampType === 'endorsement') ? 'send_to_head' : 'send_to_admin';
                if (sendAdminOfficeId) sendAdminOfficeId.value = '';
                if (activeStampType === 'endorsement') {
                    var selectedOpt = stampToInput && stampToInput.options && stampToInput.selectedIndex >= 0
                        ? stampToInput.options[stampToInput.selectedIndex]
                        : null;
                    var selectedOfficeId = selectedOpt ? (selectedOpt.getAttribute('data-office-id') || '') : '';
                    if (!selectedOfficeId) {
                        alert('Please select a department with an assigned head.');
                        return;
                    }
                    if (sendAdminOfficeId) sendAdminOfficeId.value = selectedOfficeId;
                }
                sendAdminWidth.value = String(sendStampCfg.width.toFixed(2));
                sendAdminX.value = String(sendStampCfg.x.toFixed(2));
                sendAdminY.value = String(sendStampCfg.y.toFixed(2));
                if (sendAdminNotesField) sendAdminNotesField.value = (stampNotesInput ? stampNotesInput.value : '').trim();
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
    <script src="assets/js/sidebar_super_admin.js"></script>
    <?php $notifJsVer = @filemtime(__DIR__ . '/assets/js/super_admin_notifications.js') ?: time(); ?>
    <script src="assets/js/super_admin_notifications.js?v=<?= (int)$notifJsVer ?>"></script>
</body>
</html>
