<?php
session_start();

$role = $_SESSION['user_role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($role, ['admin', 'staff', 'departmenthead', 'department_head', 'dept_head'])) {
    header('Location: ../index.php');
    exit;
}

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'Admin';
$userDepartment = $_SESSION['user_department'] ?? 'Not Assigned';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$sidebar_active = 'document-history';

if (!function_exists('getUserPhoto')) require_once __DIR__ . '/../Super Admin Side/_account_helpers.php';
if (function_exists('getUserPhoto') && !empty($_SESSION['user_id'])) { $fp = getUserPhoto($_SESSION['user_id']); if ($fp !== '') $_SESSION['user_photo'] = $fp; }

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
$historyList = [];
try {
    $pdo = dbPdo($config);
    $stmt = $pdo->query('SELECT * FROM document_history ORDER BY date_time DESC LIMIT 500');
    foreach ($stmt as $arr) {
        $arr['_id'] = (string)($arr['id'] ?? '');
        $arr['documentId'] = $arr['document_id'] ?? '';
        $arr['documentCode'] = $arr['document_code'] ?? '';
        $arr['documentTitle'] = $arr['document_title'] ?? '';
        $arr['dateTime'] = $arr['date_time'] ?? null;
        $ts = dbToTimestamp($arr['dateTime']);
        $arr['dateTimeFormatted'] = $ts !== null
            ? (new DateTime('@' . $ts))->setTimezone(new DateTimeZone(date_default_timezone_get() ?: 'UTC'))->format('M j, Y g:i A')
            : '—';
        $historyList[] = $arr;
    }
} catch (Exception $e) {
    $historyList = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Documents History</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="assets/css/admin-offices.css">
    <link rel="stylesheet" href="assets/css/profile_modal_admin.css">
    <style>
    body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
    .dashboard-container { display: flex; min-height: 100vh; border-top: 3px solid #D4AF37; }
    .sidebar { width: 260px; height: 100vh; position: fixed; left: 0; top: 0; z-index: 100; background: #1A202C; color: #fff; display: flex; flex-direction: column; box-shadow: 2px 0 12px rgba(0,0,0,0.08); border-right: 1px solid rgba(255, 255, 255, 0.06); }
    .sidebar-header { padding: 1.25rem 1rem; border-bottom: 1px solid rgba(255, 255, 255, 0.06); display: flex; flex-direction: row; align-items: center; gap: 0.75rem; text-align: left; }
    .sidebar-logo { flex-shrink: 0; width: 44px; height: 44px; background: #63B3ED; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
    .sidebar-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; }
    .sidebar-header .sidebar-title { text-align: left; }
    .sidebar-header .sidebar-title h2 { margin: 0; font-size: 1.1rem; font-weight: 700; color: #fff; line-height: 1.3; text-transform: none; letter-spacing: 0.02em; }
    .sidebar-header .sidebar-title h2 span { font-size: 0.75rem; font-weight: 500; display: block; color: #A0AEC0; margin-top: 2px; letter-spacing: 0.02em; }
    .sidebar-nav { flex: 1; padding: 1rem 0.75rem; overflow-y: auto; }
    .sidebar-nav .nav-section-title { font-size: 0.7rem; font-weight: 600; letter-spacing: 0.08em; color: #718096; padding: 0.75rem 0.75rem 0.35rem; text-transform: uppercase; }
    .sidebar-nav ul { list-style: none; padding: 0; margin: 0; }
    .sidebar-nav li { margin: 0.2rem 0; }
    .sidebar-nav a { display: flex; align-items: center; gap: 10px; padding: 0.6rem 0.75rem; color: #fff; text-decoration: none; font-size: 0.95rem; font-weight: 500; border-radius: 8px; transition: background 0.15s ease, color 0.15s ease; letter-spacing: 0.02em; }
    .sidebar-nav a .nav-icon { width: 22px; height: 22px; flex-shrink: 0; color: #A0AEC0; transition: color 0.15s ease; }
    .sidebar-nav a:hover { background: rgba(255, 255, 255, 0.06); color: #fff; }
    .sidebar-nav a:hover .nav-icon { color: #fff; }
    .sidebar-nav a.active { background: #3B82F6; color: #fff; }
    .sidebar-nav a.active .nav-icon { color: #fff; }
    .sidebar-user-wrap { position: relative; padding: 0 1rem 1.25rem 1rem; border-top: 1px solid rgba(255, 255, 255, 0.06); }
    .sidebar-user { padding: 0.75rem; border-radius: 8px; display: flex; align-items: center; gap: 0.75rem; cursor: pointer; transition: background 0.2s ease, transform 0.2s ease; }
    .sidebar-user:hover { background: rgba(255, 255, 255, 0.08); }
    .sidebar-user:active { transform: scale(0.98); }
    .sidebar-user-avatar { width: 40px; height: 40px; border-radius: 50%; background: #63B3ED; color: #fff; font-size: 0.9rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .sidebar-user-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
    .sidebar-user-info { min-width: 0; }
    .sidebar-user-name { font-size: 0.95rem; font-weight: 600; color: #fff; margin: 0; }
    .sidebar-user-role { font-size: 0.8rem; color: #A0AEC0; margin: 2px 0 0 0; }
    .account-dropdown { position: absolute; left: 1rem; right: 1rem; bottom: 0; transform: translateY(calc(-100% - 10px)); background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); padding: 6px 0; min-width: 160px; z-index: 1100; display: none; overflow: hidden; }
    .account-dropdown.open { display: block; animation: account-dropdown-in 0.2s ease; }
    @keyframes account-dropdown-in { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(calc(-100% - 10px)); } }
    .account-dropdown-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 14px; border: none; background: none; color: #1e293b; font-size: 0.9rem; cursor: pointer; text-align: left; text-decoration: none; font-family: inherit; transition: background 0.15s ease, color 0.15s ease; box-sizing: border-box; }
    .account-dropdown-item:hover { background: #f1f5f9; }
    .account-dropdown-item.account-dropdown-profile:hover { background: rgba(59, 130, 246, 0.1); color: #3B82F6; }
    .account-dropdown-item.account-dropdown-profile:hover svg { color: #3B82F6; }
    .account-dropdown-item.account-dropdown-signout:hover { background: #dc2626; color: #fff; }
    .account-dropdown-item.account-dropdown-signout:hover svg { color: #fff; }
    .account-dropdown-item svg { width: 18px; height: 18px; flex-shrink: 0; color: #64748b; transition: color 0.15s ease; }
    .account-dropdown-item:hover svg { color: #3B82F6; }
    .main-content { flex: 1; margin-left: 260px; padding: 0; background: #f1f5f9; overflow-x: auto; display: flex; flex-direction: column; }
    .content-header { background: #fff; padding: 1.5rem 2.2rem; border-bottom: 1px solid #e2e8f0; }
    .dashboard-header { display: flex; justify-content: space-between; align-items: center; }
    .header-controls { position: relative; }
    .icon-btn { background: #f1f5f9; border: none; color: #475569; padding: 0; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; position: relative; width: 40px; height: 40px; }
    .icon-btn:hover { background: #e2e8f0; color: #1e293b; }
    .icon-btn svg { width: 22px; height: 22px; }
    .notif-badge { position: absolute; top: 8px; right: 8px; background: #ef4444; color: white; font-size: 12px; padding: 4px 8px; border-radius: 999px; line-height: 1; }
    .notif-dropdown { position: absolute; right: 0; top: 48px; background: white; color: #0b1720; min-width: 180px; border-radius: 6px; box-shadow: 0 8px 20px rgba(2,6,23,0.12); border: 1px solid #e6eef8; display: none; z-index: 1200; padding: 8px 0; }
    .notif-item { padding: 10px 12px; font-size: 0.95rem; color: #475569; }
    .content-body { padding: 2rem 2.2rem; }
    .dept-page-title { margin: 0; font-size: 1.75rem; font-weight: 700; color: #1e293b; }
    .dept-page-subtitle { margin: 0.25rem 0 0 0; font-size: 0.95rem; color: #64748b; }

    /* Document History – light container to match other admin pages */
    .history-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    .history-title { font-weight: 700; font-size: 1.15rem; color: #1e293b; margin: 0 0 1rem 0; font-family: inherit; }
    .history-tools { display: grid; grid-template-columns: 1.4fr 1fr 1fr auto; gap: 12px; margin-bottom: 16px; }
    .history-tools input { height: 42px; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0 12px; font-size: 14px; color: #1e293b; background: #fff; outline: none; font-family: inherit; }
    .history-tools input:focus { border-color: #1A202C; box-shadow: 0 0 0 3px rgba(26,32,44,0.12); }
    .history-btn { height: 42px; border: none; border-radius: 10px; padding: 0 16px; background: #1A202C; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-family: inherit; transition: background 0.2s ease; }
    .history-btn:hover { background: #2d3748; color: #fff; }
    .history-btn svg { width: 18px; height: 18px; flex-shrink: 0; }
    .history-btn-secondary { background: #f1f5f9; color: #475569; }
    .history-btn-secondary:hover { background: #e2e8f0; color: #1e293b; }
    .history-table-frame { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; overflow: hidden; margin-top: 1rem; }
    .history-table { width: 100%; border-collapse: collapse; }
    .history-table thead th { text-align: left; padding: 14px 16px; font-size: 13px; font-weight: 600; letter-spacing: 0.03em; color: #475569; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
    .history-table tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 14px; }
    .history-empty { text-align: center; height: 200px; color: #64748b; vertical-align: middle; }
    .history-export-wrap { margin-top: 1rem; }
    @media (max-width: 980px) { .history-tools { grid-template-columns: 1fr 1fr; } }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="../img/logo.png" alt="LGU Solano">
                </div>
                <div class="sidebar-title">
                    <h2>LGU Solano<span>Document Management</span></h2>
                </div>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-section-title">Main Menu</div>
                <ul>
                    <li><a href="admin_dashboard.php" class="<?php echo $sidebar_active === 'dashboard' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard</a></li>
                    <li><a href="documents.php" class="<?php echo $sidebar_active === 'documents' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>Documents</a></li>
                    <li><a href="admin_offices.php" class="<?php echo $sidebar_active === 'offices' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01"/><path d="M9 12v.01"/><path d="M9 15v.01"/><path d="M9 18v.01"/></svg>Departments</a></li>
                    <li><a href="document_history.php" class="<?php echo $sidebar_active === 'document-history' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Document History</a></li>
                </ul>
                <div class="nav-section-title">Account</div>
                <ul>
                    <li><a href="admin_settings.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Settings</a></li>
                </ul>
            </nav>
            <div class="sidebar-user-wrap">
                <div class="sidebar-user" id="sidebar-account-btn" role="button" tabindex="0" aria-label="Account menu" aria-haspopup="true" aria-expanded="false">
                    <div class="sidebar-user-avatar"><?php if (!empty($_SESSION['user_photo'])): ?><img src="<?php echo htmlspecialchars($_SESSION['user_photo']); ?>" alt=""><?php else: ?><?php echo htmlspecialchars($userInitial); ?><?php endif; ?></div>
                    <div class="sidebar-user-info">
                        <p class="sidebar-user-name"><?php echo htmlspecialchars($userName); ?></p>
                        <p class="sidebar-user-role"><?php echo htmlspecialchars($userRole); ?></p>
                    </div>
                </div>
                <div class="account-dropdown" id="account-dropdown" role="menu" aria-label="Account menu">
                    <button type="button" class="account-dropdown-item account-dropdown-profile" id="account-dropdown-profile" role="menuitem"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Profile</button>
                    <a href="../index.php?logout=1" class="account-dropdown-item account-dropdown-signout" role="menuitem"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Sign Out</a>
                </div>
            </div>
        </aside>

        <div class="main-content">
            <div class="content-header">
                <div class="dashboard-header">
                    <div class="dept-page-header" style="flex: 1; margin-bottom: 0;">
                        <div>
                            <h1 class="dept-page-title">Documents History</h1>
                            <p class="dept-page-subtitle">View and track document activity, changes, and audit history</p>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <div class="header-controls">
                            <button class="icon-btn" id="notif-btn" aria-label="Notifications" title="Notifications">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0 1 18 14.158V11a6 6 0 1 0-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                                <span class="notif-badge" id="notif-count" aria-hidden="true">3</span>
                            </button>
                            <div class="notif-dropdown" id="notif-dropdown" aria-hidden="true">
                                <div class="notif-item">No new notifications</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-body">
                <section class="history-card">
                    <h2 class="history-title">Document History &amp; Transactions</h2>
                    <div class="history-tools">
                        <input type="text" id="search-history" placeholder="Search by code or title" aria-label="Search by code or title">
                        <input type="date" id="history-date-from" aria-label="From date">
                        <input type="date" id="history-date-to" aria-label="To date">
                        <button type="button" class="history-btn" id="history-filter-btn" aria-label="Search">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                            Search
                        </button>
                    </div>

                    <div class="history-table-frame">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>NO.</th>
                                    <th>DOCUMENT CODE</th>
                                    <th>DOCUMENT TITLE</th>
                                    <th>DATE/TIME</th>
                                    <th>ACTION</th>
                                    <th>USER</th>
                                </tr>
                            </thead>
                            <tbody id="history-table-body">
                                <?php if (empty($historyList)): ?>
                                <tr>
                                    <td colspan="6" class="history-empty" id="no-history-row">No document history yet.</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($historyList as $idx => $h): ?>
                                <tr data-history-row>
                                    <td><?php echo (int)($idx + 1); ?></td>
                                    <td><?php echo htmlspecialchars($h['documentCode'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($h['documentTitle'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($h['dateTimeFormatted'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($h['action'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($h['userName'] ?? '—'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="history-export-wrap">
                        <button type="button" class="history-btn history-btn-secondary" id="export-history-btn">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                            Export
                        </button>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/_profile_modal_admin.php'; ?>
    <script src="assets/js/sidebar_admin.js"></script>
    <script>
    (function() {
        var notifBtn = document.getElementById('notif-btn');
        var notifDropdown = document.getElementById('notif-dropdown');
        function closeNotif() {
            if (notifDropdown) notifDropdown.style.display = 'none';
        }
        if (notifBtn) {
            notifBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (!notifDropdown) return;
                var showing = notifDropdown.style.display === 'block';
                closeNotif();
                notifDropdown.style.display = showing ? 'none' : 'block';
            });
            document.addEventListener('click', function() { closeNotif(); });
        }

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
                noHistoryRow.textContent = visibleCount === 0 && query ? 'No matching results.' : 'No document history yet.';
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', filterHistory);
            searchInput.addEventListener('keyup', filterHistory);
        }
        var searchBtn = document.getElementById('history-filter-btn');
        if (searchBtn) searchBtn.addEventListener('click', filterHistory);
    })();
    </script>
</body>
</html>
