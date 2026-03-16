<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/_account_helpers.php';

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'Super Admin';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$sidebar_active = 'activitylogs';
$welcomeUsername = getUserUsername($_SESSION['user_id'] ?? '') ?: ($_SESSION['user_username'] ?? $userName) ?: 'User';

$config = require dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/_activity_logger.php';
require_once __DIR__ . '/_notifications_super_admin.php';
$notifData = getSuperAdminNotifications($config);
$notifCount = $notifData['count'];
$notifItems = $notifData['items'];

$search = trim((string)($_GET['search'] ?? ''));
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$page = (int)($_GET['page'] ?? 1);
if ($page <= 0) {
    $page = 1;
}
$perPage = 20;
$activityPage = getActivityLogsPage($config, $search, $fromDate, $toDate, $page, $perPage);
$logs = $activityPage['rows'] ?? [];
$total = (int)($activityPage['total'] ?? 0);
$currentPage = (int)($activityPage['page'] ?? 1);
$totalPages = (int)($activityPage['total_pages'] ?? 1);
$rowStart = (($currentPage - 1) * $perPage) + 1;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Activity Logs</title>
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
        .activity-user-meta { display: block; color: #64748b; font-size: 12px; margin-top: 2px; }
        #logs-filter-form {
            display: grid !important;
            grid-template-columns: minmax(240px, 1fr) repeat(2, minmax(160px, 180px));
            align-items: end;
            justify-content: start;
            column-gap: 10px;
            row-gap: 6px;
            margin-bottom: 8px;
        }
        #logs-filter-form #search-logs { width: 100%; min-width: 0; }
        .activity-filter-field { display: inline-flex; flex-direction: column; align-items: flex-start; gap: 3px; }
        .activity-filter-field label { font-size: 12px; color: #475569; font-weight: 600; white-space: nowrap; line-height: 1; }
        .activity-filter-field input[type="date"] { width: 100%; min-width: 160px; }
        #logs-filter-form #search-logs,
        #logs-filter-form .activity-filter-field input[type="date"] { height: 38px; }
        .offices-card .offices-table thead th {
            font-size: 12px;
            padding-top: 10px;
            padding-bottom: 10px;
        }
        .offices-card .offices-table tbody td {
            font-size: 13px;
            padding-top: 9px;
            padding-bottom: 9px;
        }
        .offices-card .offices-table tbody td:first-child { width: 60px; }
        .offices-card .offices-table tbody tr.activity-row:nth-child(odd) { background: #ffffff; }
        .offices-card .offices-table tbody tr.activity-row:nth-child(even) { background: #eef4ff; }
        .offices-card .offices-table tbody tr.activity-row:hover { background: #dbeafe; }
        .activity-pager { margin-top: 10px; }
        .activity-pager-info { font-size: 12px; }
        .activity-pager-btn { height: 30px; min-width: 74px; font-size: 12px; }
        @media (max-width: 980px) {
            #logs-filter-form {
                grid-template-columns: minmax(0, 1fr) minmax(150px, 1fr) minmax(150px, 1fr);
            }
        }
        @media (max-width: 760px) {
            #logs-filter-form {
                grid-template-columns: 1fr;
                row-gap: 10px;
            }
            .activity-filter-field input[type="date"] { min-width: 0; }
        }
        .activity-row { cursor: pointer; }
        .activity-pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 14px; }
        .activity-pager-info { color: #64748b; font-size: 13px; }
        .activity-pager-actions { display: inline-flex; align-items: center; gap: 8px; }
        .activity-pager-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 84px;
            height: 34px;
            border-radius: 8px;
            border: 1px solid #dbe2ea;
            background: #fff;
            color: #334155;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            padding: 0 12px;
        }
        .activity-pager-btn:hover { border-color: #93c5fd; color: #1d4ed8; background: #eff6ff; }
        .activity-pager-btn.disabled,
        .activity-pager-btn.disabled:hover { opacity: 0.5; pointer-events: none; }
        .activity-detail-overlay { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; padding: 16px; background: rgba(15, 23, 42, 0.5); z-index: 1400; }
        .activity-detail-overlay.open { display: flex; }
        .activity-detail-modal { width: min(640px, 95vw); max-height: 90vh; overflow: auto; background: #fff; border-radius: 12px; box-shadow: 0 20px 40px rgba(2, 6, 23, 0.25); border: 1px solid #e2e8f0; }
        .activity-detail-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid #e2e8f0; }
        .activity-detail-head h3 { margin: 0; font-size: 1.05rem; color: #1e293b; }
        .activity-detail-close { border: none; background: transparent; color: #64748b; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; font-size: 20px; line-height: 1; }
        .activity-detail-close:hover { background: #f1f5f9; color: #0f172a; }
        .activity-detail-body { padding: 14px 16px 16px; display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; }
        .activity-detail-item { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; background: #f8fafc; }
        .activity-detail-item:last-child { grid-column: 1 / -1; }
        .activity-detail-item strong { display: block; font-size: 12px; color: #64748b; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .03em; }
        .activity-detail-item span { color: #1e293b; font-size: 14px; word-break: break-word; overflow-wrap: anywhere; }
        .activity-detail-list { margin: 0; padding-left: 18px; color: #334155; font-size: 13px; }
        .activity-detail-list li { margin-bottom: 4px; overflow-wrap: anywhere; word-break: break-word; }
        @media (max-width: 760px) {
            .activity-detail-body { grid-template-columns: 1fr; }
            .activity-detail-item:last-child { grid-column: auto; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/_sidebar_super_admin.php'; ?>

        <div class="main-content">
            <div class="content-header">
                <div class="dashboard-header">
                    <div>
                        <h1>Welcome, <?php echo htmlspecialchars($welcomeUsername); ?>!</h1>
                        <small>Municipal Document Management System – Activity Logs</small>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div class="header-controls">
                            <?php include __DIR__ . '/_notif_dropdown_super_admin.php'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admin-content-body">
                <section class="chart-card chart-card-wide offices-card">
                    <form method="get" class="offices-tools doc-filter-row" id="logs-filter-form" autocomplete="off">
                        <input type="text" id="search-logs" name="search" placeholder="Search by user or action" aria-label="Search" value="<?= htmlspecialchars($search) ?>">
                        <div class="activity-filter-field">
                            <label for="logs-from-date">From</label>
                            <input type="date" id="logs-from-date" name="from_date" aria-label="From date" value="<?= htmlspecialchars($fromDate) ?>">
                        </div>
                        <div class="activity-filter-field">
                            <label for="logs-to-date">To</label>
                            <input type="date" id="logs-to-date" name="to_date" aria-label="To date" value="<?= htmlspecialchars($toDate) ?>">
                        </div>
                    </form>

                    <div class="offices-table-frame">
                        <table class="offices-table">
                            <thead>
                                <tr>
                                    <th>NO.</th>
                                    <th>USER</th>
                                    <th>ACTION</th>
                                    <th>MODULE</th>
                                    <th>STATUS</th>
                                    <th>DATE/TIME</th>
                                    <th>IP ADDRESS</th>
                                </tr>
                            </thead>
                            <tbody id="logs-table-body">
                                <?php if (!empty($logs)): ?>
                                <?php foreach ($logs as $idx => $row): ?>
                                <?php
                                    $rowPayload = [
                                        'actor_name' => (string)($row['actor_name'] ?? 'Unknown'),
                                        'actor_role_text' => (string)($row['actor_role_text'] ?? ''),
                                        'action_text' => (string)($row['action_text'] ?? '—'),
                                        'module_text' => (string)($row['module_text'] ?? '—'),
                                        'status_text' => (string)($row['status_text'] ?? '—'),
                                        'created_at_formatted' => (string)($row['created_at_formatted'] ?? '—'),
                                        'ip_address' => (string)($row['ip_address'] ?? '—'),
                                        'details_summary' => (string)($row['details_summary'] ?? ''),
                                    ];
                                    $rowSearchText = trim(implode(' ', [
                                        (string)($row['actor_name'] ?? ''),
                                        (string)($row['actor_role_text'] ?? ''),
                                        (string)($row['action_text'] ?? ''),
                                        (string)($row['module_text'] ?? ''),
                                        (string)($row['status_text'] ?? ''),
                                        (string)($row['ip_address'] ?? ''),
                                        (string)($row['details_summary'] ?? ''),
                                    ]));
                                    $rowCreatedDate = trim((string)($row['created_at_date'] ?? ''));
                                ?>
                                <tr class="activity-row"
                                    data-log='<?= htmlspecialchars(json_encode($rowPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>'
                                    data-search="<?= htmlspecialchars(strtolower($rowSearchText), ENT_QUOTES, 'UTF-8') ?>"
                                    data-created-date="<?= htmlspecialchars($rowCreatedDate, ENT_QUOTES, 'UTF-8') ?>">
                                    <td><?= (int)($rowStart + $idx) ?></td>
                                    <td>
                                        <?= htmlspecialchars((string)($row['actor_name'] ?? 'Unknown')) ?>
                                        <?php $roleText = trim((string)($row['actor_role_text'] ?? '')); ?>
                                        <?php if ($roleText !== ''): ?>
                                        <span class="activity-user-meta"><?= htmlspecialchars($roleText) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars((string)($row['action_text'] ?? '—')) ?>
                                    </td>
                                    <td><?= htmlspecialchars((string)($row['module_text'] ?? '—')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['status_text'] ?? '—')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['created_at_formatted'] ?? '—')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['ip_address'] ?? '—')) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="7" class="offices-empty" id="no-logs-row"><?= ($search !== '' || $fromDate !== '' || $toDate !== '') ? 'No activity logs found for the selected filters.' : 'No activity logs yet.' ?></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="activity-pager">
                        <div class="activity-pager-info">
                            Showing <?= empty($logs) ? 0 : (int)$rowStart ?>-<?= empty($logs) ? 0 : (int)($rowStart + count($logs) - 1) ?> of <?= (int)$total ?>
                        </div>
                        <div class="activity-pager-actions">
                            <?php
                                $baseQs = [
                                    'search' => $search,
                                    'from_date' => $fromDate,
                                    'to_date' => $toDate,
                                ];
                                $prevQs = $baseQs;
                                $prevQs['page'] = max(1, $currentPage - 1);
                                $nextQs = $baseQs;
                                $nextQs['page'] = min(max(1, $totalPages), $currentPage + 1);
                            ?>
                            <a class="activity-pager-btn <?= $currentPage <= 1 ? 'disabled' : '' ?>" href="?<?= htmlspecialchars(http_build_query($prevQs)) ?>">Previous</a>
                            <span class="activity-pager-info">Page <?= (int)$currentPage ?> of <?= (int)max(1, $totalPages) ?></span>
                            <a class="activity-pager-btn <?= $currentPage >= $totalPages ? 'disabled' : '' ?>" href="?<?= htmlspecialchars(http_build_query($nextQs)) ?>">Next</a>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <div class="activity-detail-overlay" id="activity-detail-overlay" aria-hidden="true">
        <div class="activity-detail-modal" role="dialog" aria-modal="true" aria-labelledby="activity-detail-title">
            <div class="activity-detail-head">
                <h3 id="activity-detail-title">Activity Details</h3>
                <button type="button" class="activity-detail-close" id="activity-detail-close" aria-label="Close">&times;</button>
            </div>
            <div class="activity-detail-body">
                <div class="activity-detail-item"><strong>User</strong><span id="detail-user">—</span></div>
                <div class="activity-detail-item"><strong>Action</strong><span id="detail-action">—</span></div>
                <div class="activity-detail-item"><strong>Module</strong><span id="detail-module">—</span></div>
                <div class="activity-detail-item"><strong>Status</strong><span id="detail-status">—</span></div>
                <div class="activity-detail-item"><strong>Date/Time</strong><span id="detail-time">—</span></div>
                <div class="activity-detail-item"><strong>IP Address</strong><span id="detail-ip">—</span></div>
                <div class="activity-detail-item">
                    <strong>Detailed Data</strong>
                    <ul class="activity-detail-list" id="detail-list"><li>No extra details.</li></ul>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/_profile_modal_super_admin.php'; ?>

    <script src="assets/js/sidebar_super_admin.js"></script>
    <?php $notifJsVer = @filemtime(__DIR__ . '/assets/js/super_admin_notifications.js') ?: time(); ?>
    <script src="assets/js/super_admin_notifications.js?v=<?= (int)$notifJsVer ?>"></script>
    <script>
    (function() {
        var filterForm = document.getElementById('logs-filter-form');
        if (!filterForm) return;

        var searchInput = document.getElementById('search-logs');
        var fromDateInput = filterForm.querySelector('input[name="from_date"]');
        var toDateInput = filterForm.querySelector('input[name="to_date"]');
        var tableBody = document.getElementById('logs-table-body');
        var dataRows = tableBody ? Array.prototype.slice.call(tableBody.querySelectorAll('.activity-row')) : [];
        var debounceTimer = null;

        function ensureNoLogsRow() {
            if (!tableBody) return null;
            var existing = document.getElementById('no-logs-row');
            if (existing) return existing;
            var row = document.createElement('tr');
            var cell = document.createElement('td');
            cell.colSpan = 7;
            cell.className = 'offices-empty';
            cell.id = 'no-logs-row';
            row.appendChild(cell);
            tableBody.appendChild(row);
            return cell;
        }

        function rowMatchesDate(rowDate, fromDate, toDate) {
            if (!rowDate) return (!fromDate && !toDate);
            if (fromDate && rowDate < fromDate) return false;
            if (toDate && rowDate > toDate) return false;
            return true;
        }

        function applyLiveLogsFilter() {
            if (!tableBody || dataRows.length === 0) return;

            var query = String(searchInput ? searchInput.value : '').toLowerCase().trim();
            var terms = query ? query.split(/\s+/).filter(Boolean) : [];
            var fromDate = String(fromDateInput ? fromDateInput.value : '').trim();
            var toDate = String(toDateInput ? toDateInput.value : '').trim();
            var visibleCount = 0;
            var visibleNo = 1;

            dataRows.forEach(function(row) {
                var haystack = String(row.getAttribute('data-search') || '').toLowerCase();
                var rowDate = String(row.getAttribute('data-created-date') || '').trim();
                var textMatched = terms.every(function(term) {
                    return haystack.indexOf(term) !== -1;
                });
                var dateMatched = rowMatchesDate(rowDate, fromDate, toDate);
                var matched = textMatched && dateMatched;

                row.style.display = matched ? '' : 'none';
                if (matched) {
                    visibleCount += 1;
                    var numberCell = row.querySelector('td');
                    if (numberCell) numberCell.textContent = String(visibleNo++);
                }
            });

            var noLogsCell = ensureNoLogsRow();
            if (noLogsCell) {
                noLogsCell.textContent = (query || fromDate || toDate)
                    ? 'No activity logs found for the selected filters.'
                    : 'No activity logs yet.';
                noLogsCell.parentElement.style.display = visibleCount === 0 ? '' : 'none';
            }
        }

        filterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            applyLiveLogsFilter();
        });

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                if (debounceTimer) window.clearTimeout(debounceTimer);
                debounceTimer = window.setTimeout(applyLiveLogsFilter, 180);
            });
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (debounceTimer) window.clearTimeout(debounceTimer);
                    applyLiveLogsFilter();
                }
            });
        }

        [fromDateInput, toDateInput].forEach(function(input) {
            if (!input) return;
            input.addEventListener('change', applyLiveLogsFilter);
        });

        applyLiveLogsFilter();
    })();

    (function() {
        var overlay = document.getElementById('activity-detail-overlay');
        var closeBtn = document.getElementById('activity-detail-close');
        var rows = document.querySelectorAll('.activity-row');
        if (!overlay || rows.length === 0) return;

        function byId(id) { return document.getElementById(id); }
        function openModal(payload) {
            byId('detail-user').textContent = ((payload.actor_name || 'Unknown') + (payload.actor_role_text ? (' (' + payload.actor_role_text + ')') : ''));
            byId('detail-action').textContent = payload.action_text || '—';
            byId('detail-module').textContent = payload.module_text || '—';
            byId('detail-status').textContent = payload.status_text || '—';
            byId('detail-time').textContent = payload.created_at_formatted || '—';
            byId('detail-ip').textContent = payload.ip_address || '—';

            var detailList = byId('detail-list');
            detailList.innerHTML = '';
            var summary = String(payload.details_summary || '').trim();
            if (summary === '') {
                detailList.innerHTML = '<li>No extra details.</li>';
            } else {
                summary.split(' | ').forEach(function(item) {
                    var li = document.createElement('li');
                    li.textContent = item;
                    detailList.appendChild(li);
                });
            }

            overlay.classList.add('open');
            overlay.setAttribute('aria-hidden', 'false');
        }
        function closeModal() {
            overlay.classList.remove('open');
            overlay.setAttribute('aria-hidden', 'true');
        }

        rows.forEach(function(row) {
            row.addEventListener('click', function() {
                var raw = row.getAttribute('data-log') || '{}';
                try {
                    openModal(JSON.parse(raw));
                } catch (e) {
                    openModal({});
                }
            });
        });
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlay.classList.contains('open')) closeModal();
        });
    })();
    </script>
</body>
</html>
