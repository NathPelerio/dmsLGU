<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User';
$userRole = $_SESSION['user_role'] ?? 'Super Admin';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$sidebar_active = 'settings';

require_once __DIR__ . '/_account_helpers.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_signature' && !empty($_SESSION['user_id']) && isset($_POST['signature'])) {
        $flash = updateUserSignature($_SESSION['user_id'], $_POST['signature']);
    } elseif ($action === 'update_stamp' && !empty($_SESSION['user_id']) && isset($_POST['stamp'])) {
        $flash = updateUserStamp($_SESSION['user_id'], $_POST['stamp']);
    } elseif ($action === 'update_photo' && !empty($_SESSION['user_id']) && isset($_POST['photo'])) {
        $flash = updateUserPhoto($_SESSION['user_id'], $_POST['photo']);
    }
    if ($flash) {
        header('Location: settings.php?msg=' . urlencode($flash['message']) . '&ok=' . ($flash['success'] ? '1' : '0'));
        exit;
    }
}

$msg = $_GET['msg'] ?? null;
$msgOk = isset($_GET['ok']) && $_GET['ok'] === '1';
$userSignature = isset($_SESSION['user_signature']) ? $_SESSION['user_signature'] : getUserSignature($_SESSION['user_id'] ?? '');
$userStampCfg = getUserStampConfig($_SESSION['user_id'] ?? '');
$userStamp = trim((string)($userStampCfg['stamp'] ?? ''));
$userPhotoForView = $_SESSION['user_photo'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Settings</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/sidebar_super_admin.css">
    <link rel="stylesheet" href="assets/css/profile_modal_super_admin.css">
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
        .main-content { background: #f1f5f9; }
        .content-header { background: #fff; padding: 1.5rem 2.2rem; border-bottom: 1px solid #e2e8f0; }
        .content-header h1 { margin: 0; font-size: 1.6rem; font-weight: 700; color: #1e293b; }
        .content-header small { display: block; color: #64748b; font-size: 0.95rem; margin-top: 6px; }
        .content-body { padding: 2rem 2.2rem; }
        .settings-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; }
        .settings-card h3 { margin: 0 0 0.25rem 0; font-size: 1.1rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 8px; }
        .settings-card h3 svg { width: 20px; height: 20px; color: #3B82F6; flex-shrink: 0; }
        .settings-card .card-desc { margin: 0 0 1rem 0; font-size: 0.9rem; color: #64748b; }
        .profile-photo-row { display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; }
        .profile-photo-avatar { width: 80px; height: 80px; border-radius: 50%; background: #63B3ED; color: #fff; font-size: 2rem; font-weight: 700; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .profile-photo-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-signature-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; background: #3B82F6; color: #fff; border: none; border-radius: 10px; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
        .profile-signature-btn:hover { background: #2563eb; color: #fff; }
        .profile-signature-btn svg { width: 18px; height: 18px; }
        .signature-current-label { font-size: 0.8rem; font-weight: 600; color: #64748b; margin-bottom: 0.5rem; display: block; }
        .signature-box { max-width: 320px; height: 120px; border: 1px dashed #cbd5e1; border-radius: 8px; background: #f8fafc; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .signature-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .signature-box.empty { color: #94a3b8; font-size: 0.9rem; }
        .settings-toast { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 1500; display: flex; align-items: center; gap: 12px; padding: 0.875rem 1rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(0,0,0,0.15); max-width: 360px; }
        .settings-toast.success { background: #22c55e; color: #fff; }
        .settings-toast.error { background: #ef4444; color: #fff; }
        .stamp-preview-wrap { margin-top: 0.75rem; display: grid; gap: 0.5rem; }
        .stamp-preview-label { font-size: 0.8rem; font-weight: 600; color: #64748b; }
        .stamp-preview-stage { position: relative; width: min(320px, 100%); aspect-ratio: 4 / 3; border-radius: 10px; border: 1px dashed #cbd5e1; background: linear-gradient(180deg,#fff,#f8fafc); overflow: hidden; }
        .stamp-preview-placeholder { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 0.9rem; }
        .stamp-preview-image { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; user-select: none; object-fit: contain; }
        .stamp-modal-overlay { position: fixed; inset: 0; z-index: 305; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(0,0,0,0.45); }
        .stamp-modal-overlay.stamp-modal-open { display: flex; }
        .stamp-modal { background: #fff; border-radius: 12px; width: 100%; max-width: 640px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 20px 40px rgba(0,0,0,0.22); }
        .stamp-modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; }
        .stamp-modal-header h3 { margin: 0; font-size: 1.15rem; color: #1e293b; }
        .stamp-modal-close { width: 36px; height: 36px; border: none; background: transparent; color: #64748b; border-radius: 8px; cursor: pointer; }
        .stamp-modal-close:hover { background: #f1f5f9; color: #1e293b; }
        .stamp-modal-body { padding: 1rem 1.25rem; display: grid; gap: 0.9rem; }
        .stamp-upload-zone { border: 2px dashed #cbd5e1; border-radius: 10px; padding: 1rem; text-align: center; background: #f8fafc; cursor: pointer; color: #475569; }
        .stamp-upload-zone.dragover, .stamp-upload-zone:hover { border-color: #3B82F6; background: rgba(59,130,246,0.05); }
        .stamp-upload-zone input[type="file"] { display: none; }
        .stamp-editor-stage { position: relative; width: min(440px, 100%); margin: 0 auto; aspect-ratio: 4 / 3; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; overflow: hidden; }
        .stamp-editor-hint { position: absolute; left: 0.75rem; top: 0.65rem; color: #94a3b8; font-size: 12px; pointer-events: none; }
        .stamp-editor-image { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; cursor: default; pointer-events: none; }
        .stamp-modal-footer { padding: 1rem 1.25rem; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; }
        .signature-modal-overlay { position: fixed; inset: 0; z-index: 300; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(0,0,0,0.4); }
        .signature-modal-overlay.signature-modal-open { display: flex; }
        .signature-modal { background: #fff; border-radius: 12px; width: 100%; max-width: 480px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 20px 40px rgba(0,0,0,0.2); }
        .signature-modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; }
        .signature-modal-header h3 { margin: 0; font-size: 1.2rem; font-weight: 700; color: #1e293b; }
        .signature-modal-close { width: 36px; height: 36px; border: none; background: transparent; color: #64748b; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .signature-modal-close:hover { background: #f1f5f9; color: #1e293b; }
        .signature-tabs { display: flex; border-bottom: 1px solid #e5e7eb; padding: 0 1rem; }
        .signature-tab { padding: 12px 20px; border: none; background: none; font-size: 0.95rem; font-weight: 500; color: #64748b; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: inherit; }
        .signature-tab.active { color: #3B82F6; border-bottom-color: #3B82F6; }
        .signature-modal-body { padding: 1.25rem; overflow-y: auto; flex: 1; min-height: 0; }
        .signature-pane { display: none; }
        .signature-pane.active { display: block; }
        .signature-upload-zone { border: 2px dashed #cbd5e1; border-radius: 10px; padding: 2rem; text-align: center; background: #f8fafc; cursor: pointer; }
        .signature-upload-zone:hover, .signature-upload-zone.dragover { border-color: #3B82F6; background: rgba(59,130,246,0.05); }
        .signature-upload-zone input[type="file"] { display: none; }
        .signature-upload-preview { max-width: 100%; max-height: 180px; margin-top: 1rem; display: none; }
        .signature-upload-preview.show { display: block; }
        .signature-canvas-wrap { border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
        #signature-pad { display: block; width: 100%; height: 200px; cursor: crosshair; touch-action: none; }
        .signature-actions { margin-top: 1rem; }
        .signature-actions .btn-clear { background: #64748b; color: #fff; }
        .signature-modal-footer { padding: 1rem 1.25rem; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; }
        .offices-btn { height: 42px; padding: 0 16px; border: none; border-radius: 10px; background: #3B82F6; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .offices-btn-secondary { background: #64748b; color: #fff; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/_sidebar_super_admin.php'; ?>
        <div class="main-content">
            <div class="content-header">
                <h1>Settings</h1>
                <small>E-signature and profile photo</small>
            </div>
            <div class="content-body">
                <?php if ($msg !== null): ?>
                <div id="settings-toast" class="settings-toast <?= $msgOk ? 'success' : 'error' ?>" role="alert"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>

                <div class="settings-card">
                    <h3>Profile Photo</h3>
                    <p class="card-desc">Your avatar shown in the sidebar and across the app</p>
                    <div class="profile-photo-row">
                        <div class="profile-photo-avatar profile-photo-view-trigger" role="button" tabindex="0" title="Click to view"><?php if (!empty($_SESSION['user_photo'])): ?><img src="<?= htmlspecialchars($_SESSION['user_photo']) ?>" alt=""><?php else: ?><?= htmlspecialchars($userInitial) ?><?php endif; ?></div>
                        <div>
                            <label class="profile-signature-btn" for="profile-photo-file-input"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Upload Photo</label>
                            <input type="file" id="profile-photo-file-input" accept="image/png,image/jpeg,image/jpg,image/gif" style="display:none;">
                        </div>
                    </div>
                </div>

                <div class="settings-card">
                    <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/></svg>E-Signature</h3>
                    <p class="card-desc">Your digital signature for document approvals</p>
                    <span class="signature-current-label">Current Signature:</span>
                    <div class="signature-box <?= $userSignature === '' ? 'empty' : '' ?>">
                        <?php if ($userSignature !== ''): ?><img src="<?= htmlspecialchars($userSignature) ?>" alt="Your signature"><?php else: ?><span>No signature set</span><?php endif; ?>
                    </div>
                    <button type="button" class="profile-signature-btn" id="profile-update-signature-btn" style="margin-top:1rem;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Update Signature</button>
                </div>

                <div class="settings-card">
                    <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v16H4z"/><path d="M4 16l4-4 4 4 5-5 3 3"/></svg>Send Stamp</h3>
                    <p class="card-desc">Upload the stamp image used when sending documents. Position and size are adjusted before each send.</p>
                    <button type="button" class="profile-signature-btn" id="open-stamp-modal-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                        Update Stamp
                    </button>
                    <div class="stamp-preview-wrap">
                        <span class="stamp-preview-label">Current Stamp Preview:</span>
                        <div class="stamp-preview-stage" id="stamp-preview-stage">
                            <?php if ($userStamp !== ''): ?>
                            <img id="stamp-preview-image" class="stamp-preview-image" src="<?= htmlspecialchars($userStamp) ?>" alt="Current stamp">
                            <?php else: ?>
                            <div class="stamp-preview-placeholder">No stamp set</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include __DIR__ . '/_profile_modal_super_admin.php'; ?>

    <div class="signature-modal-overlay" id="signature-modal-overlay">
        <div class="signature-modal">
            <div class="signature-modal-header">
                <h3>Update Signature</h3>
                <button type="button" class="signature-modal-close" id="signature-modal-close" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
            </div>
            <div class="signature-tabs">
                <button type="button" class="signature-tab active" data-pane="upload">Upload Picture</button>
                <button type="button" class="signature-tab" data-pane="draw">Draw Signature</button>
            </div>
            <div class="signature-modal-body">
                <div class="signature-pane active" id="signature-pane-upload">
                    <label class="signature-upload-zone" id="signature-upload-zone" for="signature-file-input">
                        <span>Click or drag an image here (PNG, JPG)</span>
                        <input type="file" id="signature-file-input" accept="image/png,image/jpeg,image/jpg,image/gif">
                        <img class="signature-upload-preview" id="signature-upload-preview" alt="Preview">
                    </label>
                </div>
                <div class="signature-pane" id="signature-pane-draw">
                    <div class="signature-canvas-wrap">
                        <canvas id="signature-pad" width="428" height="200"></canvas>
                    </div>
                    <div class="signature-actions">
                        <button type="button" class="profile-signature-btn btn-clear" id="signature-clear-btn">Clear</button>
                    </div>
                </div>
            </div>
            <div class="signature-modal-footer">
                <button type="button" class="offices-btn offices-btn-secondary" id="signature-modal-cancel">Cancel</button>
                <button type="button" class="offices-btn" id="signature-save-btn">Save Signature</button>
            </div>
        </div>
    </div>
    <div class="stamp-modal-overlay" id="stamp-modal-overlay">
        <div class="stamp-modal">
            <div class="stamp-modal-header">
                <h3>Update Send Stamp</h3>
                <button type="button" class="stamp-modal-close" id="stamp-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="stamp-modal-body">
                <label class="stamp-upload-zone" id="stamp-upload-zone" for="stamp-file-input">
                    Click or drag an image here (PNG/JPG)
                    <input type="file" id="stamp-file-input" accept="image/png,image/jpeg,image/jpg,image/gif">
                </label>
                <div class="stamp-editor-stage" id="stamp-editor-stage">
                    <span class="stamp-editor-hint">Preview fills the box. Move/resize is done before sending each document.</span>
                    <img id="stamp-editor-image" class="stamp-editor-image" alt="Stamp editor image" hidden>
                </div>
            </div>
            <div class="stamp-modal-footer">
                <button type="button" class="offices-btn offices-btn-secondary" id="stamp-modal-cancel">Cancel</button>
                <button type="button" class="offices-btn" id="stamp-save-btn">Save Stamp</button>
            </div>
        </div>
    </div>
    <form method="post" id="signature-update-form" action="settings.php" style="display:none;">
        <input type="hidden" name="action" value="update_signature">
        <input type="hidden" name="signature" id="signature-hidden-input">
    </form>
    <form method="post" id="stamp-update-form" action="settings.php" style="display:none;">
        <input type="hidden" name="action" value="update_stamp">
        <input type="hidden" name="stamp" id="stamp-hidden-input">
        <input type="hidden" name="stamp_width_pct" id="stamp-width-hidden-input" value="18">
        <input type="hidden" name="stamp_x_pct" id="stamp-x-hidden-input" value="82">
        <input type="hidden" name="stamp_y_pct" id="stamp-y-hidden-input" value="84">
    </form>
    <form method="post" id="profile-photo-form" action="settings.php" style="display:none;">
        <input type="hidden" name="action" value="update_photo">
        <input type="hidden" name="photo" id="profile-photo-hidden-input">
    </form>

    <script src="assets/js/sidebar_super_admin.js"></script>
    <script>
    (function(){
        var toast = document.getElementById('settings-toast');
        if (toast) setTimeout(function(){ toast.remove(); }, 5000);
    })();
    (function(){
        var overlay = document.getElementById('signature-modal-overlay');
        var openBtn = document.getElementById('profile-update-signature-btn');
        var closeBtn = document.getElementById('signature-modal-close');
        var cancelBtn = document.getElementById('signature-modal-cancel');
        var tabs = document.querySelectorAll('.signature-tab');
        var paneUpload = document.getElementById('signature-pane-upload');
        var paneDraw = document.getElementById('signature-pane-draw');
        var fileInput = document.getElementById('signature-file-input');
        var uploadZone = document.getElementById('signature-upload-zone');
        var uploadPreview = document.getElementById('signature-upload-preview');
        var canvas = document.getElementById('signature-pad');
        var clearBtn = document.getElementById('signature-clear-btn');
        var saveBtn = document.getElementById('signature-save-btn');
        var form = document.getElementById('signature-update-form');
        var hiddenInput = document.getElementById('signature-hidden-input');
        var currentSignatureData = '';

        function openSignatureModal() {
            currentSignatureData = '';
            if (uploadPreview) { uploadPreview.src = ''; uploadPreview.classList.remove('show'); }
            if (fileInput) fileInput.value = '';
            if (canvas) { var ctx = canvas.getContext('2d'); if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height); }
            tabs.forEach(function(t){ t.classList.toggle('active', t.getAttribute('data-pane') === 'upload'); });
            if (paneUpload) paneUpload.classList.add('active');
            if (paneDraw) paneDraw.classList.remove('active');
            if (overlay) overlay.classList.add('signature-modal-open');
        }
        function closeSignatureModal() {
            if (overlay) overlay.classList.remove('signature-modal-open');
        }
        if (openBtn) openBtn.addEventListener('click', openSignatureModal);
        if (closeBtn) closeBtn.addEventListener('click', closeSignatureModal);
        if (cancelBtn) cancelBtn.addEventListener('click', closeSignatureModal);
        if (overlay) overlay.addEventListener('click', function(e){ if (e.target === overlay) closeSignatureModal(); });

        tabs.forEach(function(tab){
            tab.addEventListener('click', function(){
                var pane = tab.getAttribute('data-pane');
                tabs.forEach(function(t){ t.classList.toggle('active', t.getAttribute('data-pane') === pane); });
                paneUpload.classList.toggle('active', pane === 'upload');
                paneDraw.classList.toggle('active', pane === 'draw');
            });
        });

        function setSignatureFromDataUrl(dataUrl) { currentSignatureData = dataUrl || ''; }
        if (uploadZone && fileInput) {
            uploadZone.addEventListener('click', function(e){ if (e.target !== fileInput) fileInput.click(); });
            uploadZone.addEventListener('dragover', function(e){ e.preventDefault(); uploadZone.classList.add('dragover'); });
            uploadZone.addEventListener('dragleave', function(){ uploadZone.classList.remove('dragover'); });
            uploadZone.addEventListener('drop', function(e){ e.preventDefault(); uploadZone.classList.remove('dragover'); if (e.dataTransfer.files.length && e.dataTransfer.files[0].type.indexOf('image/') === 0) { var r = new FileReader(); r.onload = function(){ setSignatureFromDataUrl(r.result); uploadPreview.src = r.result; uploadPreview.classList.add('show'); }; r.readAsDataURL(e.dataTransfer.files[0]); } });
            fileInput.addEventListener('change', function(){ if (fileInput.files.length) { var r = new FileReader(); r.onload = function(){ setSignatureFromDataUrl(r.result); uploadPreview.src = r.result; uploadPreview.classList.add('show'); }; r.readAsDataURL(fileInput.files[0]); } });
        }
        if (canvas) {
            var ctx = canvas.getContext('2d');
            var drawing = false;
            function getPos(e){ var rect = canvas.getBoundingClientRect(); var scaleX = canvas.width/rect.width, scaleY = canvas.height/rect.height; var x = e.touches ? e.touches[0].clientX : e.clientX; var y = e.touches ? e.touches[0].clientY : e.clientY; return { x: (x - rect.left)*scaleX, y: (y - rect.top)*scaleY }; }
            function start(e){ e.preventDefault(); drawing = true; var p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
            function move(e){ e.preventDefault(); if (!drawing) return; var p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
            function end(e){ e.preventDefault(); drawing = false; setSignatureFromDataUrl(canvas.toDataURL('image/png')); }
            ctx.strokeStyle = '#1e293b'; ctx.lineWidth = 2; ctx.lineCap = 'round';
            canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); canvas.addEventListener('mouseup', end); canvas.addEventListener('mouseleave', end);
            canvas.addEventListener('touchstart', start, { passive: false }); canvas.addEventListener('touchmove', move, { passive: false }); canvas.addEventListener('touchend', end, { passive: false });
        }
        if (clearBtn && canvas) clearBtn.addEventListener('click', function(){ canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height); setSignatureFromDataUrl(''); });
        if (saveBtn && form && hiddenInput) saveBtn.addEventListener('click', function(){
            var data = (paneDraw && paneDraw.classList.contains('active') && canvas) ? canvas.toDataURL('image/png') : currentSignatureData;
            if (!data) { alert('Please upload an image or draw your signature.'); return; }
            hiddenInput.value = data;
            form.submit();
        });
    })();
    (function(){
        var fileInput = document.getElementById('profile-photo-file-input');
        var form = document.getElementById('profile-photo-form');
        var hiddenInput = document.getElementById('profile-photo-hidden-input');
        if (fileInput && form && hiddenInput) fileInput.addEventListener('change', function(){
            if (!fileInput.files || !fileInput.files.length) return;
            if (fileInput.files[0].type.indexOf('image/') !== 0) { alert('Please choose an image file.'); return; }
            var r = new FileReader();
            r.onload = function(){ hiddenInput.value = r.result; form.submit(); };
            r.readAsDataURL(fileInput.files[0]);
        });
    })();
    (function(){
        var overlay = document.getElementById('stamp-modal-overlay');
        var openBtn = document.getElementById('open-stamp-modal-btn');
        var closeBtn = document.getElementById('stamp-modal-close');
        var cancelBtn = document.getElementById('stamp-modal-cancel');
        var uploadZone = document.getElementById('stamp-upload-zone');
        var fileInput = document.getElementById('stamp-file-input');
        var editorImage = document.getElementById('stamp-editor-image');
        var saveBtn = document.getElementById('stamp-save-btn');
        var form = document.getElementById('stamp-update-form');
        var stampHidden = document.getElementById('stamp-hidden-input');
        var wHidden = document.getElementById('stamp-width-hidden-input');
        var xHidden = document.getElementById('stamp-x-hidden-input');
        var yHidden = document.getElementById('stamp-y-hidden-input');

        var stampData = <?php echo json_encode($userStamp); ?>;

        function syncUi() {
            if (!editorImage) return;
            if (stampData) {
                editorImage.hidden = false;
                editorImage.src = stampData;
                editorImage.style.width = '100%';
                editorImage.style.height = '100%';
            } else {
                editorImage.hidden = true;
            }
        }
        function openModal(){
            if (!overlay) return;
            syncUi();
            overlay.classList.add('stamp-modal-open');
        }
        function closeModal(){
            if (!overlay) return;
            overlay.classList.remove('stamp-modal-open');
        }
        if (openBtn) openBtn.addEventListener('click', openModal);
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
        if (overlay) overlay.addEventListener('click', function(e){ if (e.target === overlay) closeModal(); });

        function consumeFile(file) {
            if (!file || file.type.indexOf('image/') !== 0) return;
            var r = new FileReader();
            r.onload = function() { stampData = String(r.result || ''); syncUi(); };
            r.readAsDataURL(file);
        }
        if (uploadZone && fileInput) {
            uploadZone.addEventListener('click', function(e){ if (e.target !== fileInput) fileInput.click(); });
            uploadZone.addEventListener('dragover', function(e){ e.preventDefault(); uploadZone.classList.add('dragover'); });
            uploadZone.addEventListener('dragleave', function(){ uploadZone.classList.remove('dragover'); });
            uploadZone.addEventListener('drop', function(e){
                e.preventDefault();
                uploadZone.classList.remove('dragover');
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) consumeFile(e.dataTransfer.files[0]);
            });
            fileInput.addEventListener('change', function(){ if (fileInput.files && fileInput.files.length) consumeFile(fileInput.files[0]); });
        }
        if (saveBtn && form && stampHidden && wHidden && xHidden && yHidden) {
            saveBtn.addEventListener('click', function(){
                if (!stampData) { alert('Please upload a stamp image first.'); return; }
                stampHidden.value = stampData;
                wHidden.value = '18';
                xHidden.value = '82';
                yHidden.value = '84';
                form.submit();
            });
        }
        syncUi();
    })();
    </script>
</body>
</html>
