<?php
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(2);
}

if ($argc < 7) {
    fwrite(STDERR, "Usage: php stamp_docx_cli.php <docx_path> <stamp_png_path> <x_pct> <y_pct> <width_pct> <page_number>\n");
    exit(2);
}

$docxPath = (string)$argv[1];
$stampPath = (string)$argv[2];
$xPct = (float)$argv[3];
$yPct = (float)$argv[4];
$widthPct = (float)$argv[5];
$pageNumber = max(1, (int)$argv[6]);

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive is not available in CLI PHP.\n");
    exit(3);
}
if (!is_file($docxPath)) {
    fwrite(STDERR, "DOCX file was not found.\n");
    exit(3);
}
if (!is_file($stampPath)) {
    fwrite(STDERR, "Stamp image file was not found.\n");
    exit(3);
}

$stampImageBinary = @file_get_contents($stampPath);
if ($stampImageBinary === false || $stampImageBinary === '') {
    fwrite(STDERR, "Could not read stamp image.\n");
    exit(3);
}

function frontDeskCliNextRelId(DOMDocument $relsDom) {
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

function frontDeskCliFindAnchorParagraphForPage(DOMDocument $docDom, DOMXPath $docXPath, $targetPageNumber) {
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

function frontDeskCliApplyReceivedStamp($docxPath, $stampImageBinary, $xPct, $yPct, $widthPct, $pageNumber) {
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
    $contentWPt = max(120.0, $pageWPt - $marginLeftPt - $marginRightPt);
    $contentHPt = max(120.0, $pageHPt - $marginTopPt - $marginBottomPt);
    $stampWPt = max(70.0, min(260.0, $contentWPt * (max(5.0, min(60.0, (float)$widthPct)) / 100.0)));
    $stampHPt = $stampWPt * ($imgH / max(1, $imgW));
    $leftPt = ($contentWPt * (max(0.0, min(100.0, (float)$xPct)) / 100.0)) - ($stampWPt / 2.0);
    $topPt = ($contentHPt * (max(0.0, min(100.0, (float)$yPct)) / 100.0)) - ($stampHPt / 2.0);

    $relsDom = new DOMDocument('1.0', 'UTF-8');
    $relsDom->preserveWhiteSpace = false;
    $relsDom->formatOutput = false;
    if (!$relsDom->loadXML($documentRelsXml)) {
        $zip->close();
        throw new RuntimeException('Could not parse DOCX relationship file.');
    }

    $imgName = 'received_stamp_' . substr(bin2hex(random_bytes(5)), 0, 10) . '.png';
    $imgPath = 'word/media/' . $imgName;

    $imageRelId = frontDeskCliNextRelId($relsDom);
    $relsRoot = $relsDom->documentElement;
    $newDocRel = $relsDom->createElement('Relationship');
    $newDocRel->setAttribute('Id', $imageRelId);
    $newDocRel->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image');
    $newDocRel->setAttribute('Target', 'media/' . $imgName);
    $relsRoot->appendChild($newDocRel);

    $targetPara = frontDeskCliFindAnchorParagraphForPage($docDom, $docXPath, $pageNumber);
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

try {
    frontDeskCliApplyReceivedStamp($docxPath, $stampImageBinary, $xPct, $yPct, $widthPct, $pageNumber);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
