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
$sidebar_active = 'document-history';
$welcomeUsername = getUserUsername($_SESSION['user_id'] ?? '') ?: ($_SESSION['user_username'] ?? $userName) ?: 'User';

$config = require dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/_notifications_super_admin.php';
$notifData = getSuperAdminNotifications($config);
$notifCount = $notifData['count'];
$notifItems = $notifData['items'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Documents History</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="profile_modal_super_admin.css">
    <link rel="stylesheet" href="../Admin Side/admin-dashboard.css">
    <link rel="stylesheet" href="../Admin Side/admin-offices.css">
    <link rel="stylesheet" href="sidebar_super_admin.css">
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
                        <small>Municipal Document Management System – Documents History</small>
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
                    <div class="offices-tools doc-filter-row">
                        <input type="text" id="search-history" placeholder="Search" aria-label="Search history">
                        <input type="date" aria-label="From date">
                        <input type="date" aria-label="To date">
                        <button type="button" class="offices-btn offices-btn-secondary" id="export-history-btn">
                            <svg class="offices-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                            Export
                        </button>
                    </div>

                    <div class="offices-table-frame">
                        <table class="offices-table">
                            <thead>
                                <tr>
                                    <th>NO.</th>
                                    <th>DATE & TIME</th>
                                    <th>ACTION</th>
                                    <th>DOCUMENT CODE</th>
                                    <th>DOCUMENT TITLE</th>
                                    <th>USER</th>
                                </tr>
                            </thead>
                            <tbody id="history-table-body">
                                <tr>
                                    <td colspan="6" class="offices-empty" id="no-history-row">No history yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/_profile_modal_super_admin.php'; ?>

    <script>
    (function() {
        var exportBtn = document.getElementById('export-history-btn');
        if (exportBtn) {
            exportBtn.addEventListener('click', function() {
                alert('Export history. (Export function can be wired to backend later.)');
            });
        }

        var searchInput = document.getElementById('search-history');
        var historyTableBody = document.getElementById('history-table-body');
        var noHistoryRow = document.getElementById('no-history-row');

        function filterHistory() {
            var query = (searchInput && searchInput.value || '').trim().toLowerCase();
            var dataRows = historyTableBody ? historyTableBody.querySelectorAll('tr[data-history-row]') : [];
            var hasDataRows = dataRows.length > 0;

            if (!hasDataRows) {
                if (noHistoryRow) noHistoryRow.style.display = '';
                return;
            }

            var visibleCount = 0;
            dataRows.forEach(function(row) {
                var text = row.textContent || '';
                var match = !query || text.toLowerCase().indexOf(query) !== -1;
                row.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });

            if (noHistoryRow) {
                noHistoryRow.style.display = visibleCount === 0 ? '' : 'none';
                noHistoryRow.textContent = visibleCount === 0 && query ? 'No matching results.' : 'No history yet.';
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', filterHistory);
            searchInput.addEventListener('keyup', filterHistory);
        }
    })();
    </script>
    <script src="sidebar_super_admin.js"></script>
    <?php $notifJsVer = @filemtime(__DIR__ . '/super_admin_notifications.js') ?: time(); ?>
    <script src="super_admin_notifications.js?v=<?= (int)$notifJsVer ?>"></script>
</body>
</html>
