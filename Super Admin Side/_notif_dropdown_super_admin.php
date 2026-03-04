<?php
$notifCount = isset($notifCount) ? (int)$notifCount : 0;
$notifItems = isset($notifItems) && is_array($notifItems) ? $notifItems : [];
?>
<style>
    .notif-dropdown {
        position: absolute;
        right: 0;
        top: 54px;
        display: none;
        z-index: 1200;
    }
    .notif-dropdown-fb {
        width: min(390px, calc(100vw - 24px));
        min-width: 320px;
        padding: 0;
        border-radius: 16px;
        border: 1px solid #dbe3ef;
        box-shadow: 0 14px 34px rgba(15, 23, 42, 0.18);
        overflow: hidden;
        background: #fff;
        color: #0f172a;
    }
    .notif-dropdown-fb .notif-dropdown-head {
        padding: 14px 16px 10px;
        font-size: 1.15rem;
        font-weight: 700;
        color: #111827;
        border-bottom: 1px solid #eef2f7;
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }
    .notif-dropdown-fb .notif-dropdown-list { max-height: 410px; overflow-y: auto; padding: 8px; }
    .notif-dropdown-fb .notif-item-link {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin: 2px 0;
        border: 0;
        border-radius: 12px;
        padding: 10px;
        transition: background-color 0.18s ease;
        text-decoration: none;
        color: #1e293b;
    }
    .notif-dropdown-fb .notif-item-link:hover { background: #eef3ff; color: #0f172a; }
    .notif-dropdown-fb .notif-item-link.notif-read { opacity: 0.7; }
    .notif-dropdown-fb .notif-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 0.88rem;
        font-weight: 700;
        color: #1d4ed8;
        background: #dbeafe;
    }
    .notif-dropdown-fb .notif-item-content { min-width: 0; flex: 1; display: grid; gap: 3px; }
    .notif-dropdown-fb .notif-item-text {
        font-size: 0.9rem;
        line-height: 1.35;
        color: #0f172a;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .notif-dropdown-fb .notif-item-text strong { font-weight: 700; color: #0b1324; }
    .notif-dropdown-fb .notif-item-time { font-size: 0.76rem; color: #2563eb; font-weight: 600; }
    .notif-dropdown-fb .notif-unread-dot {
        width: 9px;
        height: 9px;
        margin-top: 6px;
        border-radius: 999px;
        background: #1877f2;
        flex-shrink: 0;
    }
    .notif-dropdown-fb .notif-item-link.notif-read .notif-unread-dot { display: none; }
    .notif-dropdown-fb .notif-empty {
        padding: 18px 10px;
        text-align: center;
        color: #64748b;
        font-size: 0.9rem;
    }
    .notif-dropdown-fb .notif-extra { display: none; }
    .notif-dropdown-fb[data-expanded="1"] .notif-extra { display: flex; }
    .notif-dropdown-fb .notif-dropdown-footer {
        border-top: 1px solid #eef2f7;
        padding: 8px;
        background: #fff;
    }
    .notif-dropdown-fb .notif-more-btn {
        width: 100%;
        border: 0;
        border-radius: 10px;
        padding: 9px 12px;
        background: #e7f3ff;
        color: #1877f2;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
    }
    .notif-dropdown-fb .notif-more-btn:hover { background: #dbeafe; }
</style>
<button class="icon-btn" id="notif-btn" aria-label="Notifications" title="Notifications">
    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0 1 18 14.158V11a6 6 0 1 0-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    <span class="notif-badge" id="notif-count" aria-hidden="true" style="<?= $notifCount === 0 ? 'display:none' : '' ?>"><?= $notifCount ?></span>
</button>
<div class="notif-dropdown notif-dropdown-fb" id="notif-dropdown" aria-hidden="true" data-expanded="0">
    <div class="notif-dropdown-head">Notifications</div>
    <div class="notif-dropdown-list">
    <?php if (count($notifItems) === 0): ?>
        <div class="notif-empty">No new notifications</div>
    <?php else: ?>
        <?php foreach ($notifItems as $idx => $ni):
            $sender = trim((string)($ni['sentByUserName'] ?? 'System'));
            if ($sender === '') $sender = 'System';
            $senderInitial = mb_strtoupper(mb_substr($sender, 0, 1));
            $docTitle = trim((string)($ni['documentTitle'] ?? 'Document'));
            if ($docTitle === '') $docTitle = 'Document';
            $notifHref = !empty($ni['documentId']) ? ('documents.php?highlight=' . urlencode((string)$ni['documentId'])) : 'documents.php';
            $isExtra = $idx >= 4;
            $notifId = trim((string)($ni['notificationId'] ?? ''));
            $isRead = !empty($ni['isRead']);
        ?>
        <a href="<?= htmlspecialchars($notifHref) ?>" class="notif-item notif-item-link<?= $isExtra ? ' notif-extra' : '' ?><?= $isRead ? ' notif-read' : '' ?>" data-notif-id="<?= htmlspecialchars($notifId) ?>">
            <span class="notif-avatar"><?= htmlspecialchars($senderInitial) ?></span>
            <span class="notif-item-content">
                <span class="notif-item-text"><strong><?= htmlspecialchars($sender) ?></strong> sent <strong><?= htmlspecialchars($docTitle) ?></strong></span>
                <span class="notif-item-time"><?= htmlspecialchars((string)($ni['sentAtFormatted'] ?? '')) ?></span>
            </span>
            <span class="notif-unread-dot" aria-hidden="true"></span>
        </a>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
    <?php if (count($notifItems) > 4): ?>
    <div class="notif-dropdown-footer">
        <button type="button" class="notif-more-btn" data-notif-toggle>Show more</button>
    </div>
    <?php endif; ?>
</div>
