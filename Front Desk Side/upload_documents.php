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

function frontDeskDecodeDataUrlImage($dataUrl) {
    $m = [];
    if (!preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUrl, $m)) {
        return null;
    }
    $raw = base64_decode((string)$m[2], true);
    if ($raw === false || $raw === '') {
        return null;
    }
    return $raw;
}

function frontDeskNextRelId(DOMDocument $relsDom) {
    $max = 0;
    foreach ($relsDom->getElementsByTagName('Relationship') as $rel) {
        $id = (string)$rel->getAttribute('Id');
        if (preg_match('/^rId(\d+)$/', $id, $m)) {
            $n = (int)$m[1];
            if ($n > $max) {
                $max = $n;
            }
        }
    }
    return 'rId' . ($max + 1);
}

function frontDeskFindAnchorParagraphForPage(DOMDocument $docDom, DOMXPath $docXPath, $targetPageNumber) {
    $bodyNodes = $docXPath->query('/w:document/w:body');
    if (!$bodyNodes || $bodyNodes->length < 1) {
        return null;
    }
    $body = $bodyNodes->item(0);
    if (!($body instanceof DOMElement)) {
        return null;
    }
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $target = max(1, (int)$targetPageNumber);
    $currentPage = 1;
    $lastElement = null;

    foreach ($body->childNodes as $child) {
        if (!($child instanceof DOMElement)) {
            continue;
        }
        if ($currentPage >= $target) {
            if ($child->namespaceURI === $wNs && $child->localName === 'p') {
                return $child;
            }
            $anchorP = $docDom->createElementNS($wNs, 'w:p');
            $body->insertBefore($anchorP, $child);
            return $anchorP;
        }
        $advance = 0;
        $advance += (int)$docXPath->evaluate('count(.//w:lastRenderedPageBreak)', $child);
        $advance += (int)$docXPath->evaluate('count(.//w:br[not(@w:type) or @w:type="page"])', $child);
        if ($advance > 0) {
            $currentPage += $advance;
        }
        $lastElement = $child;
    }

    if ($lastElement instanceof DOMElement && $lastElement->namespaceURI === $wNs && $lastElement->localName === 'p') {
        return $lastElement;
    }
    $anchorP = $docDom->createElementNS($wNs, 'w:p');
    $body->appendChild($anchorP);
    return $anchorP;
}

function frontDeskApplyReceivedStampToDocxViaCli($docxPath, $stampImageBinary, $xPct, $yPct, $widthPct, $pageNumber) {
    if (!function_exists('exec')) {
        throw new RuntimeException('ZipArchive is unavailable and CLI execution is disabled.');
    }
    $phpIni = (string)php_ini_loaded_file();
    $phpDir = $phpIni !== '' ? dirname($phpIni) : '';
    $phpCli = $phpDir !== '' ? $phpDir . DIRECTORY_SEPARATOR . 'php.exe' : '';
    if ($phpCli === '' || !is_file($phpCli)) {
        throw new RuntimeException('ZipArchive is unavailable and php.exe was not found for CLI fallback.');
    }
    $helperScript = __DIR__ . '/stamp_docx_cli.php';
    if (!is_file($helperScript)) {
        throw new RuntimeException('Stamp helper script is missing.');
    }

    $tmpBase = tempnam(sys_get_temp_dir(), 'fdstamp_');
    if ($tmpBase === false) {
        throw new RuntimeException('Could not create temporary file for stamp processing.');
    }
    $tmpStampPath = $tmpBase . '.png';
    @unlink($tmpBase);
    if (@file_put_contents($tmpStampPath, $stampImageBinary) === false) {
        @unlink($tmpStampPath);
        throw new RuntimeException('Could not write temporary stamp image.');
    }

    $cmd = escapeshellarg($phpCli)
        . ' ' . escapeshellarg($helperScript)
        . ' ' . escapeshellarg($docxPath)
        . ' ' . escapeshellarg($tmpStampPath)
        . ' ' . escapeshellarg((string)$xPct)
        . ' ' . escapeshellarg((string)$yPct)
        . ' ' . escapeshellarg((string)$widthPct)
        . ' ' . escapeshellarg((string)$pageNumber)
        . ' 2>&1';

    $output = [];
    $exitCode = 1;
    @exec($cmd, $output, $exitCode);
    @unlink($tmpStampPath);

    if ((int)$exitCode !== 0) {
        $msg = trim(implode("\n", $output));
        if ($msg === '') {
            $msg = 'Unknown CLI stamping failure.';
        }
        throw new RuntimeException($msg);
    }
}

function frontDeskApplyReceivedStampToDocx($docxPath, $stampImageBinary, $xPct, $yPct, $widthPct, $pageNumber = 1) {
    if (!class_exists('ZipArchive')) {
        frontDeskApplyReceivedStampToDocxViaCli($docxPath, $stampImageBinary, $xPct, $yPct, $widthPct, $pageNumber);
        return;
    }
    $zip = new ZipArchive();
    if ($zip->open($docxPath) !== true) {
        throw new RuntimeException('Could not open DOCX for stamping.');
    }

    $documentXml = (string)$zip->getFromName('word/document.xml');
    $documentRelsXml = (string)$zip->getFromName('word/_rels/document.xml.rels');
    if ($documentXml === '' || $documentRelsXml === '') {
        $zip->close();
        throw new RuntimeException('DOCX structure is invalid.');
    }

    $sizeInfo = @getimagesizefromstring($stampImageBinary);
    if (!$sizeInfo || (int)($sizeInfo[0] ?? 0) <= 0 || (int)($sizeInfo[1] ?? 0) <= 0) {
        $zip->close();
        throw new RuntimeException('Generated stamp image is invalid.');
    }
    $imgW = (int)$sizeInfo[0];
    $imgH = (int)$sizeInfo[1];

    $docDom = new DOMDocument('1.0', 'UTF-8');
    $docDom->preserveWhiteSpace = false;
    $docDom->formatOutput = false;
    if (!$docDom->loadXML($documentXml)) {
        $zip->close();
        throw new RuntimeException('Could not parse DOCX document.xml.');
    }
    $docXPath = new DOMXPath($docDom);
    $docXPath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $docXPath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $sectPrNodes = $docXPath->query('//w:sectPr');
    if (!$sectPrNodes || $sectPrNodes->length < 1) {
        $zip->close();
        throw new RuntimeException('No section properties found in DOCX.');
    }
    $pageWPt = 595.0;
    $pageHPt = 842.0;
    $marginLeftPt = 72.0;
    $marginRightPt = 72.0;
    $marginTopPt = 72.0;
    $marginBottomPt = 72.0;
    $firstSect = $sectPrNodes->item(0);
    if ($firstSect instanceof DOMElement) {
        $pgSzNodes = $docXPath->query('./w:pgSz', $firstSect);
        if ($pgSzNodes && $pgSzNodes->length > 0) {
            $pgSz = $pgSzNodes->item(0);
            if ($pgSz instanceof DOMElement) {
                $wTwipsRaw = trim((string)$pgSz->getAttribute('w:w'));
                $hTwipsRaw = trim((string)$pgSz->getAttribute('w:h'));
                $wTwips = is_numeric($wTwipsRaw) ? (float)$wTwipsRaw : 0.0;
                $hTwips = is_numeric($hTwipsRaw) ? (float)$hTwipsRaw : 0.0;
                if ($wTwips > 0.0 && $hTwips > 0.0) {
                    $pageWPt = $wTwips / 20.0;
                    $pageHPt = $hTwips / 20.0;
                }
            }
        }
        $pgMarNodes = $docXPath->query('./w:pgMar', $firstSect);
        if ($pgMarNodes && $pgMarNodes->length > 0) {
            $pgMar = $pgMarNodes->item(0);
            if ($pgMar instanceof DOMElement) {
                $leftRaw = trim((string)$pgMar->getAttribute('w:left'));
                $rightRaw = trim((string)$pgMar->getAttribute('w:right'));
                $topRaw = trim((string)$pgMar->getAttribute('w:top'));
                $bottomRaw = trim((string)$pgMar->getAttribute('w:bottom'));
                if (is_numeric($leftRaw) && (float)$leftRaw >= 0.0) $marginLeftPt = ((float)$leftRaw) / 20.0;
                if (is_numeric($rightRaw) && (float)$rightRaw >= 0.0) $marginRightPt = ((float)$rightRaw) / 20.0;
                if (is_numeric($topRaw) && (float)$topRaw >= 0.0) $marginTopPt = ((float)$topRaw) / 20.0;
                if (is_numeric($bottomRaw) && (float)$bottomRaw >= 0.0) $marginBottomPt = ((float)$bottomRaw) / 20.0;
            }
        }
    }

    // FIX: Use full page dimensions for position calculation (matching what the browser preview measures),
    // then subtract margins since DOCX anchor offsets are relative to the margin edge.
    $stampWPt = max(70.0, min(260.0, $pageWPt * (max(5.0, min(60.0, (float)$widthPct)) / 100.0)));
    $stampHPt = $stampWPt * ($imgH / max(1, $imgW));
    $leftPt = ($pageWPt * (max(0.0, min(100.0, (float)$xPct)) / 100.0)) - ($stampWPt / 2.0) - $marginLeftPt;
    $topPt  = ($pageHPt * (max(0.0, min(100.0, (float)$yPct)) / 100.0)) - ($stampHPt / 2.0) - $marginTopPt;

    $relsDom = new DOMDocument('1.0', 'UTF-8');
    $relsDom->preserveWhiteSpace = false;
    $relsDom->formatOutput = false;
    if (!$relsDom->loadXML($documentRelsXml)) {
        $zip->close();
        throw new RuntimeException('Could not parse DOCX relationship file.');
    }

    $imgName = 'received_stamp_' . substr(bin2hex(random_bytes(5)), 0, 10) . '.png';
    $imgPath = 'word/media/' . $imgName;

    $imageRelId = frontDeskNextRelId($relsDom);
    $relsRoot = $relsDom->documentElement;
    $newDocRel = $relsDom->createElement('Relationship');
    $newDocRel->setAttribute('Id', $imageRelId);
    $newDocRel->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image');
    $newDocRel->setAttribute('Target', 'media/' . $imgName);
    $relsRoot->appendChild($newDocRel);

    $targetPara = frontDeskFindAnchorParagraphForPage($docDom, $docXPath, $pageNumber);
    if (!($targetPara instanceof DOMElement)) {
        $zip->close();
        throw new RuntimeException('Could not determine page location for stamping.');
    }
    $emuPerPt = 12700;
    $xEmu = (int)round($leftPt * $emuPerPt);
    $yEmu = (int)round($topPt * $emuPerPt);
    $cxEmu = (int)round($stampWPt * $emuPerPt);
    $cyEmu = (int)round($stampHPt * $emuPerPt);
    $docPrId = random_int(1000, 999999);
    $stampRunXml = '<w:r xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
        . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<w:drawing>'
        . '<wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0"'
        . ' relativeHeight="251658240" behindDoc="0" locked="0" layoutInCell="1" allowOverlap="1">'
        . '<wp:simplePos x="0" y="0"/>'
        . '<wp:positionH relativeFrom="margin"><wp:posOffset>' . $xEmu . '</wp:posOffset></wp:positionH>'
        . '<wp:positionV relativeFrom="margin"><wp:posOffset>' . $yEmu . '</wp:posOffset></wp:positionV>'
        . '<wp:extent cx="' . $cxEmu . '" cy="' . $cyEmu . '"/>'
        . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
        . '<wp:wrapNone/>'
        . '<wp:docPr id="' . $docPrId . '" name="FDReceivedStamp" descr="FDReceivedStamp"/>'
        . '<wp:cNvGraphicFramePr/>'
        . '<a:graphic>'
        . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:pic>'
        . '<pic:nvPicPr><pic:cNvPr id="0" name="FDReceivedStamp" descr="FDReceivedStamp"/><pic:cNvPicPr/></pic:nvPicPr>'
        . '<pic:blipFill><a:blip r:embed="' . $imageRelId . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr>'
        . '<a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cxEmu . '" cy="' . $cyEmu . '"/></a:xfrm>'
        . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
        . '</pic:spPr>'
        . '</pic:pic>'
        . '</a:graphicData>'
        . '</a:graphic>'
        . '</wp:anchor>'
        . '</w:drawing>'
        . '</w:r>';
    $stampRunNode = $docDom->createDocumentFragment();
    if (!$stampRunNode->appendXML($stampRunXml)) {
        $zip->close();
        throw new RuntimeException('Failed to build stamp XML.');
    }
    $targetPara->appendChild($stampRunNode);

    $zip->addFromString('word/_rels/document.xml.rels', $relsDom->saveXML());
    $zip->addFromString('word/document.xml', $docDom->saveXML());
    $zip->addFromString($imgPath, $stampImageBinary);
    $zip->close();
}

$uploadError = trim((string)($_SESSION['frontdesk_upload_error'] ?? ''));
if ($uploadError !== '') {
    unset($_SESSION['frontdesk_upload_error']);
}
$showUploaded = isset($_GET['uploaded']) && $_GET['uploaded'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $documentCode = trim((string)($_POST['document_code'] ?? ''));
    $documentTitle = trim((string)($_POST['document_title'] ?? ''));
    $stampImageData = trim((string)($_POST['stamp_image_data'] ?? ''));
    $stampWidthPct = (float)($_POST['stamp_width_pct'] ?? 18);
    $stampXPct = (float)($_POST['stamp_x_pct'] ?? 82);
    $stampYPct = (float)($_POST['stamp_y_pct'] ?? 84);
    $stampPageNumber = max(1, (int)($_POST['stamp_page_number'] ?? 1));
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

    $stampBinary = frontDeskDecodeDataUrlImage($stampImageData);
    if ($stampBinary === null) {
        $_SESSION['frontdesk_upload_error'] = 'Please place a receiving stamp before uploading.';
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
        frontDeskApplyReceivedStampToDocx($absolutePath, $stampBinary, $stampXPct, $stampYPct, $stampWidthPct, $stampPageNumber);
    } catch (Exception $e) {
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
        $_SESSION['frontdesk_upload_error'] = 'Failed to apply receiving stamp: ' . $e->getMessage();
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

        $stampDetails = json_encode([
            'received_stamp' => [
                'x' => round($stampXPct, 2),
                'y' => round($stampYPct, 2),
                'width' => round($stampWidthPct, 2),
                'page' => $stampPageNumber,
                'image' => $stampImageData,
            ],
        ], JSON_UNESCAPED_SLASHES);

        $assignMap = [
            'tracking_code' => $documentCode,
            'subject' => $documentTitle,
            'details' => $stampDetails,
            'document_code' => $documentCode,
            'document_title' => $documentTitle,
            'file_name' => $origName,
            'mime_type' => $mimeType,
            'file_size_bytes' => @filesize($absolutePath) ?: (int)$file['size'],
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
            'message' => $userName . ' uploaded a stamped document: ' . $documentTitle . ' (' . $documentCode . ')',
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
    <title>Staff - Upload Document</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/staff-dashboard.css">
    <style>
        .upload-page { background: #f5f7fa; }
        .upload-page .admin-content-header-row { background: #fff; }
        .upload-page .admin-main, .upload-page .admin-content-body { background: #f5f7fa !important; }
        .upload-header h1 { font-size: 1.75rem; font-weight: 700; color: #1e293b; margin: 0 0 6px 0; letter-spacing: -0.02em; }
        .upload-header p { font-size: 0.95rem; color: #64748b; margin: 0; }
        .upload-card { background: #fff; border-radius: 8px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); }
        .upload-card h2 { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin: 0 0 6px 0; }
        .upload-card-sub { font-size: 0.9rem; color: #94a3b8; margin: 0 0 20px 0; }
        .upload-drop-zone { border: 2px dashed #ccc; border-radius: 8px; background: #fff; padding: 48px 24px; text-align: center; cursor: pointer; transition: border-color 0.2s, background 0.2s; }
        .upload-drop-zone:hover { border-color: #94a3b8; background: #f8fafc; }
        .upload-drop-zone.dragover { border-color: #3498db; background: #f0f9ff; }
        .upload-drop-icon { color: #475569; margin-bottom: 12px; }
        .upload-drop-zone strong { display: block; font-size: 1rem; font-weight: 600; color: #334155; margin-bottom: 6px; }
        .upload-drop-hint { font-size: 0.85rem; color: #94a3b8; }
        .upload-drop-zone input[type="file"] { display: none; }
        .upload-form-group { margin-bottom: 20px; }
        .upload-form-group label { display: block; font-size: 0.9rem; font-weight: 500; color: #334155; margin-bottom: 8px; }
        .upload-form-group label .required { color: #dc2626; }
        .upload-form-group input { width: 100%; padding: 12px 14px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; color: #1e293b; background: #fff; outline: none; }
        .upload-form-group input::placeholder { color: #94a3b8; }
        .upload-form-group input:focus { border-color: #64748b; box-shadow: 0 0 0 2px rgba(100, 116, 139, 0.15); }
        .upload-form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-bottom: 24px; }
        .upload-btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; border: none; transition: opacity 0.2s, background 0.2s; }
        .upload-btn-cancel { background: #fff; border: 1px solid #ccc; color: #334155; }
        .upload-btn-cancel:hover { background: #f8fafc; border-color: #94a3b8; }
        .upload-btn-submit { background: #3498db; color: #fff; }
        .upload-btn-submit:hover { background: #2980b9; }
        .stamp-controls-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        .stamp-controls-grid label { display: grid; gap: 6px; font-size: 12px; color: #334155; font-weight: 600; }
        .stamp-controls-grid input { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 10px; font-size: 13px; }
        .stamp-controls-grid select { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 10px; font-size: 13px; background: #fff; }
        .stamp-actions { margin-top: 12px; display: flex; gap: 10px; flex-wrap: wrap; }
        .stamp-preview-wrap { margin-top: 14px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; padding: 10px; }
        .stamp-preview-note { margin: 0 0 8px 0; font-size: 12px; color: #475569; }
        #docx-upload-preview { min-height: 300px; border: 1px solid #dbe3ef; border-radius: 8px; background: #fff; overflow: auto; position: relative; }
        .received-stamp-overlay { position: absolute; transform: translate(-50%, -50%); z-index: 20; object-fit: contain; max-width: none; max-height: none; cursor: move; opacity: 0.9; user-select: none; touch-action: none; }
        .stamp-adjust-wrap { display: flex; align-items: center; gap: 10px; margin-top: 10px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
        .stamp-adjust-wrap label { font-size: 13px; font-weight: 600; color: #334155; white-space: nowrap; }
        .stamp-adjust-wrap input[type="range"] { flex: 1; accent-color: #2563eb; }
        .stamp-adjust-wrap span { min-width: 44px; text-align: right; font-size: 13px; font-weight: 700; color: #334155; }
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
                    <a href="staff_dashboard.php" class="sidebar-link" data-section="home">Dashboard</a>
                    <a href="documents.php" class="sidebar-link" data-section="documents">Documents</a>
                    <a href="upload_documents.php" class="sidebar-link active" data-section="upload">Upload Document</a>
                </div>
                <div class="sidebar-section sidebar-section-account">
                    <span class="sidebar-section-title">ACCOUNT</span>
                    <a href="settings.php" class="sidebar-link sidebar-link-settings" data-section="settings">Settings</a>
                </div>
            </nav>
        </aside>

        <main class="admin-main" style="background:#f5f7fa;">
            <div class="admin-content" id="admin-content" style="background:#f5f7fa; color:#1e293b;">
                <div class="admin-content-header-row">
                    <header class="admin-content-header">
                        <div class="upload-header">
                            <h1>Upload Document</h1>
                            <p>Preview, stamp, and upload a document</p>
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
            <div class="admin-content-body" id="admin-content-body" style="background:#f5f7fa;">
                <form action="#" method="post" enctype="multipart/form-data" id="upload-form">
                    <input type="hidden" name="stamp_image_data" id="stamp-image-data" value="">
                    <input type="hidden" name="stamp_width_pct" id="stamp-width-pct" value="18">
                    <input type="hidden" name="stamp_x_pct" id="stamp-x-pct" value="82">
                    <input type="hidden" name="stamp_y_pct" id="stamp-y-pct" value="14">
                    <input type="hidden" name="stamp_page_number" id="stamp-page-number" value="1">
                    <div class="upload-form-actions">
                        <a href="documents.php" class="upload-btn upload-btn-cancel">Cancel</a>
                        <button type="submit" class="upload-btn upload-btn-submit">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            Upload Document
                        </button>
                    </div>
                    <div class="upload-card">
                        <h2>Document File</h2>
                        <p class="upload-card-sub">Upload a DOCX file (max 10MB), then place receiving stamp</p>
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

                    <div class="upload-card" id="stamp-card" style="display:none;">
                        <h2>Receiving Stamp</h2>
                        <p class="upload-card-sub">Generate the receiving stamp, click document to place it, then drag to adjust.</p>
                        <div class="stamp-controls-grid">
                            <label>Date
                                <input type="date" id="stamp-date">
                            </label>
                            <label>Time
                                <input type="time" id="stamp-time">
                            </label>
                            <label>By
                                <input type="text" id="stamp-by" value="<?php echo htmlspecialchars((string)$userName); ?>">
                            </label>
                            <label>Font Color
                                <select id="stamp-font-color">
                                    <option value="black" selected>Black</option>
                                    <option value="blue">Blue</option>
                                </select>
                            </label>
                        </div>
                        <div class="stamp-actions">
                            <button type="button" class="upload-btn upload-btn-submit" id="generate-stamp-btn" style="padding:10px 14px;">Generate Receiving Stamp</button>
                        </div>
                        <div class="stamp-adjust-wrap">
                            <label for="stamp-size-range">Stamp size</label>
                            <input type="range" id="stamp-size-range" min="5" max="60" step="1" value="18">
                            <span id="stamp-size-label">18%</span>
                        </div>
                        <div class="stamp-preview-wrap">
                            <p class="stamp-preview-note" id="stamp-note">Upload a DOCX, generate stamp, then click preview to place stamp.</p>
                            <div id="docx-upload-preview"></div>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jszip@3/dist/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.0/dist/docx-preview.min.js"></script>
    <script>
    (function() {
        function toast(msg, bg) {
            var el = document.createElement('div');
            el.setAttribute('role', 'status');
            el.textContent = msg;
            el.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:' + bg + ';color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);max-width:460px;';
            document.body.appendChild(el);
            setTimeout(function() { el.remove(); }, 4800);
        }
        if (document.body.getAttribute('data-uploaded') === '1') {
            toast('Document uploaded successfully.', '#22c55e');
        }
        var uploadErr = document.body.getAttribute('data-upload-error') || '';
        if (uploadErr) {
            toast(uploadErr, '#dc2626');
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

        var form = document.getElementById('upload-form');
        var dropZone = document.getElementById('drop-zone');
        var fileInput = document.getElementById('document_file');
        var stampCard = document.getElementById('stamp-card');
        var previewContainer = document.getElementById('docx-upload-preview');
        var stampDate = document.getElementById('stamp-date');
        var stampTime = document.getElementById('stamp-time');
        var stampBy = document.getElementById('stamp-by');
        var stampFontColor = document.getElementById('stamp-font-color');
        var generateBtn = document.getElementById('generate-stamp-btn');
        var stampSizeRange = document.getElementById('stamp-size-range');
        var stampSizeLabel = document.getElementById('stamp-size-label');
        var stampNote = document.getElementById('stamp-note');
        var hiddenStampImage = document.getElementById('stamp-image-data');
        var hiddenWidth = document.getElementById('stamp-width-pct');
        var hiddenX = document.getElementById('stamp-x-pct');
        var hiddenY = document.getElementById('stamp-y-pct');
        var hiddenPage = document.getElementById('stamp-page-number');

        var stampCfg = { width: 18, x: 82, y: 14, page: 1 };
        var generatedStampData = '';
        var stampNode = null;
        var stampPlaced = false;
        var dragging = false;

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
            ctx.strokeStyle = resolveStampColor();
            ctx.lineWidth = width || 3;
            ctx.beginPath();
            ctx.moveTo(x1, y1);
            ctx.lineTo(x2, y2);
            ctx.stroke();
            ctx.restore();
        }
        function resolveStampColor() {
            var color = (stampFontColor && stampFontColor.value ? stampFontColor.value : 'black').toLowerCase();
            return color === 'blue' ? '#1d4ed8' : '#111111';
        }
        function generateReceivedStampImage() {
            var c = document.createElement('canvas');
            c.width = 1300;
            c.height = 760;
            var ctx = c.getContext('2d');
            if (!ctx) return '';
            var dateText = formatLongDate(stampDate ? stampDate.value : '');
            var timeText = formatDisplayTime(stampTime ? stampTime.value : '');
            var byText = (stampBy && stampBy.value ? stampBy.value : '').trim();
            ctx.clearRect(0, 0, c.width, c.height);
            ctx.fillStyle = resolveStampColor();
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

        function getPreviewPages() {
            if (!previewContainer) return [];
            var pages = Array.prototype.slice.call(previewContainer.querySelectorAll('.docx-wrapper > section, .docx > section, .docx-page, .page'));
            if (!pages.length) {
                var fallback = previewContainer.querySelector('.docx') || previewContainer.querySelector('.docx-wrapper') || previewContainer.firstElementChild || previewContainer;
                if (fallback) pages = [fallback];
            }
            return pages;
        }

        function getPageFromPoint(clientX, clientY) {
            var pages = getPreviewPages();
            if (!pages.length) return { node: null, index: 1 };
            for (var i = 0; i < pages.length; i += 1) {
                var r = pages[i].getBoundingClientRect();
                if (clientX >= r.left && clientX <= r.right && clientY >= r.top && clientY <= r.bottom) {
                    return { node: pages[i], index: i + 1 };
                }
            }
            return { node: pages[0], index: 1 };
        }

        function getStampTarget() {
            var pages = getPreviewPages();
            var idx = Math.max(1, parseInt(String(stampCfg.page || 1), 10) || 1) - 1;
            var page = pages[idx] || pages[0] || null;
            if (!page) return null;
            if (!page.style.position || page.style.position === 'static') page.style.position = 'relative';
            return page;
        }

        function ensureStampNode() {
            if (!generatedStampData) return null;
            var target = getStampTarget();
            if (!target) return null;
            if (!stampNode) {
                stampNode = document.createElement('img');
                stampNode.className = 'received-stamp-overlay';
                stampNode.alt = 'Receiving stamp';
                stampNode.src = generatedStampData;
                target.appendChild(stampNode);
            } else if (stampNode.parentNode !== target) {
                target.appendChild(stampNode);
            }
            stampNode.style.display = stampPlaced ? '' : 'none';
            return stampNode;
        }

        function getBounds() {
            var target = getStampTarget();
            if (!target || !stampNode) return { minX: 5, maxX: 95, minY: 5, maxY: 95 };
            var rect = target.getBoundingClientRect();
            var stampRect = stampNode.getBoundingClientRect();
            if (!rect.width || !rect.height || !stampRect.width || !stampRect.height) return { minX: 5, maxX: 95, minY: 5, maxY: 95 };
            var halfW = clamp((stampRect.width / 2 / rect.width) * 100, 1, 49);
            var halfH = clamp((stampRect.height / 2 / rect.height) * 100, 1, 49);
            return { minX: halfW, maxX: 100 - halfW, minY: halfH, maxY: 100 - halfH };
        }

        function applyStampStyles() {
            if (!stampNode) return;
            var b = getBounds();
            stampCfg.x = clamp(stampCfg.x, b.minX, b.maxX);
            stampCfg.y = clamp(stampCfg.y, b.minY, b.maxY);
            stampNode.style.width = stampCfg.width + '%';
            stampNode.style.left = stampCfg.x + '%';
            stampNode.style.top = stampCfg.y + '%';
            hiddenWidth.value = String(stampCfg.width.toFixed(2));
            hiddenX.value = String(stampCfg.x.toFixed(2));
            hiddenY.value = String(stampCfg.y.toFixed(2));
            hiddenPage.value = String(Math.max(1, parseInt(String(stampCfg.page || 1), 10) || 1));
            stampSizeRange.value = String(Math.round(stampCfg.width));
            stampSizeLabel.textContent = String(Math.round(stampCfg.width)) + '%';
        }

        function placeStamp(clientX, clientY) {
            var pageHit = getPageFromPoint(clientX, clientY);
            if (pageHit.node) {
                stampCfg.page = pageHit.index;
            }
            ensureStampNode();
            var target = getStampTarget();
            if (!target || !stampNode) return;
            var rect = target.getBoundingClientRect();
            if (!rect.width || !rect.height) return;
            var b = getBounds();
            stampCfg.x = clamp(((clientX - rect.left) / rect.width) * 100, b.minX, b.maxX);
            stampCfg.y = clamp(((clientY - rect.top) / rect.height) * 100, b.minY, b.maxY);
            stampPlaced = true;
            stampNode.style.display = '';
            applyStampStyles();
            hiddenStampImage.value = generatedStampData;
            stampNote.textContent = 'Stamp placed. Drag to adjust, then upload.';
        }

        function loadDocxPreview(file) {
            if (!previewContainer) return;
            previewContainer.innerHTML = '';
            stampNode = null;
            stampPlaced = false;
            stampCfg.page = 1;
            hiddenStampImage.value = '';
            hiddenPage.value = '1';
            if (!file) return;
            if (typeof docx === 'undefined' || !docx.renderAsync) {
                previewContainer.innerHTML = '<p style="padding:18px;color:#b91c1c;">DOCX preview library failed to load.</p>';
                return;
            }
            docx.renderAsync(file, previewContainer, null, { breakPages: true, ignoreLastRenderedPageBreak: false })
                .then(function() {
                    stampCard.style.display = '';
                    stampNote.textContent = 'Generate stamp, then click preview to place it.';
                })
                .catch(function() {
                    previewContainer.innerHTML = '<p style="padding:18px;color:#b91c1c;">Could not render DOCX preview.</p>';
                });
        }

        var now = new Date();
        if (stampDate) stampDate.value = toYmdLocal(now);
        if (stampTime) stampTime.value = toHmLocal(now);

        if (dropZone && fileInput) {
            dropZone.addEventListener('click', function() { fileInput.click(); });
            dropZone.addEventListener('dragover', function(e) { e.preventDefault(); dropZone.classList.add('dragover'); });
            dropZone.addEventListener('dragleave', function() { dropZone.classList.remove('dragover'); });
            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                dropZone.classList.remove('dragover');
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    fileInput.dispatchEvent(new Event('change'));
                }
            });
            fileInput.addEventListener('change', function() {
                var f = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
                if (!f) return;
                if (!/\.docx$/i.test(f.name || '')) {
                    toast('Only .docx files are allowed.', '#dc2626');
                    fileInput.value = '';
                    return;
                }
                loadDocxPreview(f);
            });
        }

        if (generateBtn) {
            generateBtn.addEventListener('click', function() {
                generatedStampData = generateReceivedStampImage();
                ensureStampNode();
                stampPlaced = false;
                if (stampNode) stampNode.style.display = 'none';
                hiddenStampImage.value = generatedStampData;
                stampNote.textContent = 'Stamp generated. Click on the document preview to place it.';
                applyStampStyles();
            });
        }

        if (stampSizeRange) {
            stampSizeRange.addEventListener('input', function() {
                stampCfg.width = clamp(parseFloat(stampSizeRange.value || '18') || 18, 5, 60);
                applyStampStyles();
            });
        }

        if (previewContainer) {
            previewContainer.addEventListener('mousedown', function(e) {
                if (!stampNode) return;
                if (e.target === stampNode) {
                    dragging = true;
                    e.preventDefault();
                }
            });
            window.addEventListener('mousemove', function(e) {
                if (!dragging || !stampNode) return;
                placeStamp(e.clientX, e.clientY);
            });
            window.addEventListener('mouseup', function() { dragging = false; });
            previewContainer.addEventListener('click', function(e) {
                if (!generatedStampData) return;
                if (e.target === stampNode) return;
                placeStamp(e.clientX, e.clientY);
            });
        }

        if (form) {
            form.addEventListener('submit', function(e) {
                if (!hiddenStampImage.value || !stampPlaced) {
                    e.preventDefault();
                    toast('Please generate and place the receiving stamp before upload.', '#dc2626');
                }
            });
        }
    })();
    </script>
</body>
</html>