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
$sidebar_active = 'dashboard';
// Welcome header uses username from DB (stays in sync when updated in database)
$welcomeUsername = getUserUsername($_SESSION['user_id'] ?? '') ?: ($_SESSION['user_username'] ?? $userName) ?: 'User';
$config = require dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/_notifications_super_admin.php';
$notifData = getSuperAdminNotifications($config);
$notifCount = $notifData['count'];
$notifItems = $notifData['items'];

// Fetch document counts and recent docs (same logic as Admin dashboard)
$totalDocuments = 0;
$pendingCount = 0;
$approvedCount = 0;
$completedCount = 0;
$recentDocuments = [];
$statusBreakdown = ['Archived' => 0, 'Pending Admin' => 0, 'Pending Department' => 0];
try {
    require_once __DIR__ . '/../db.php';
    $pdo = dbPdo($config);
    $stmt = $pdo->query('SELECT * FROM documents ORDER BY created_at DESC LIMIT 200');
    $docs = $stmt->fetchAll();
    $totalDocuments = count($docs);
    foreach ($docs as $d) {
        $arr = $d;
        $status = isset($arr['status']) ? (string)$arr['status'] : 'Pending';
        $s = strtolower($status);
        if (strpos($s, 'pending') !== false) $pendingCount++;
        elseif (strpos($s, 'approved') !== false) $approvedCount++;
        elseif (strpos($s, 'completed') !== false) $completedCount++;
        if (strpos($s, 'archived') !== false) {
            $statusBreakdown['Archived']++;
        } elseif (strpos($s, 'admin') !== false) {
            $statusBreakdown['Pending Admin']++;
        } else {
            $statusBreakdown['Pending Department']++;
        }
    }
    $recentDocuments = array_slice($docs, 0, 5);
} catch (Exception $e) {
    $totalDocuments = 0;
    $pendingCount = 0;
    $approvedCount = 0;
    $completedCount = 0;
    $statusBreakdown = ['Archived' => 0, 'Pending Admin' => 0, 'Pending Department' => 0];
    $recentDocuments = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Super Admin Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/profile_modal_super_admin.css">
    <link rel="stylesheet" href="../Admin%20Side/assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="assets/css/sidebar_super_admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        /* Same font as sidebar: Inter for full dashboard consistency */
        body, .dashboard-container, .main-content {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Open Sans', sans-serif;
        }
        body { margin: 0; background: #f8fafc; color: #0f172a; }
        .main-content { display: flex; flex-direction: column; background: #fff; min-height: 0; flex: 1; }
        .main-content .admin-content-header-row { flex-shrink: 0; }
        .main-content .admin-content-body { flex: 1; min-height: 0; overflow: auto; padding: 32px 35px; }
        .main-content .profile-dropdown[hidden] { display: none !important; }
        .main-content .admin-content-header-row { padding-right: 35.2px; }
        .main-content .admin-content-actions { margin-left: auto; }
        .admin-content-actions .header-controls { position: relative; }
        .admin-content-actions .icon-btn {
            background: #f1f5f9;
            border: none;
            color: #475569;
            padding: 0;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
        }
        .admin-content-actions .icon-btn:hover { background: #e2e8f0; color: #1e293b; }
        .admin-content-actions .icon-btn svg { width: 22px; height: 22px; }
        .admin-content-actions .notif-badge {
            position: absolute;
            top: 4px;
            right: 4px;
            background: #ef4444;
            color: #fff;
            font-size: 12px;
            line-height: 1;
            padding: 4px 7px;
            border-radius: 999px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/_sidebar_super_admin.php'; ?>

        <div class="main-content">
            <div class="admin-content" id="admin-content">
                <div class="admin-content-header-row">
                    <header class="admin-content-header">
                        <div class="admin-header-text">
                            <h1 class="admin-content-title">Welcome back, <?php echo htmlspecialchars($welcomeUsername); ?></h1>
                            <p class="admin-content-subtitle" id="dashboard-subtitle">Super Admin Dashboard • </p>
                        </div>
                    </header>
                    <div class="admin-content-actions">
                        <div class="header-controls">
                            <?php include __DIR__ . '/_notif_dropdown_super_admin.php'; ?>
                        </div>
                    </div>
                </div>
                <div class="admin-content-body" id="admin-content-body">
                    <div class="dashboard-upload-row">
                        <a href="documents.php" class="btn-upload-document">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            Upload Document
                        </a>
                    </div>
                    <div class="dashboard-metrics">
                        <div class="metric-card">
                            <span class="metric-label">TOTAL DOCUMENTS</span>
                            <span class="metric-value"><?php echo (int)$totalDocuments; ?></span>
                            <svg class="metric-icon metric-icon-doc" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                        </div>
                        <div class="metric-card">
                            <span class="metric-label">PENDING</span>
                            <span class="metric-value"><?php echo (int)$pendingCount; ?></span>
                            <svg class="metric-icon metric-icon-pending" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div class="metric-card">
                            <span class="metric-label">APPROVED</span>
                            <span class="metric-value"><?php echo (int)$approvedCount; ?></span>
                            <svg class="metric-icon metric-icon-approved" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <div class="metric-card">
                            <span class="metric-label">COMPLETED</span>
                            <span class="metric-value"><?php echo (int)$completedCount; ?></span>
                            <svg class="metric-icon metric-icon-completed" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                        </div>
                    </div>

                    <div class="dashboard-middle">
                        <div class="dashboard-card my-tasks-card">
                            <div class="card-head">
                                <h3 class="card-title">My Tasks</h3>
                                <a href="documents.php" class="card-link">View All →</a>
                            </div>
                            <div class="my-tasks-empty">
                                <svg class="tasks-check-icon" viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                <p class="tasks-empty-title">All caught up!</p>
                                <p class="tasks-empty-sub">No pending tasks at the moment</p>
                            </div>
                        </div>
                        <div class="dashboard-card status-breakdown-card">
                            <h3 class="card-title">Status Breakdown</h3>
                            <div class="status-chart-wrap">
                                <canvas id="chart-status-breakdown" width="280" height="280"></canvas>
                            </div>
                            <div class="status-legend">
                                <span class="legend-item"><i class="legend-dot" style="background:#2563eb"></i> Archived</span>
                                <span class="legend-item"><i class="legend-dot" style="background:#ea580c"></i> Pending Admin</span>
                                <span class="legend-item"><i class="legend-dot" style="background:#16a34a"></i> Pending Department</span>
                            </div>
                        </div>
                    </div>

                    <div class="dashboard-card recent-docs-card">
                        <div class="card-head">
                            <h3 class="card-title">Recent Documents</h3>
                            <a href="documents.php" class="card-link">View All →</a>
                        </div>
                        <div class="recent-docs-table-wrap">
                            <table class="recent-docs-table">
                                <thead>
                                    <tr>
                                        <th>CONTROL NO.</th>
                                        <th>TITLE</th>
                                        <th>STATUS</th>
                                        <th>DATE</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentDocuments)): ?>
                                    <tr>
                                        <td colspan="4" class="recent-docs-empty">No recent documents</td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($recentDocuments as $doc): ?>
                                    <?php
                                        $controlNo = isset($doc['controlNo']) ? htmlspecialchars($doc['controlNo']) : (isset($doc['control_no']) ? htmlspecialchars($doc['control_no']) : '—');
                                        $title = isset($doc['title']) ? htmlspecialchars($doc['title']) : (isset($doc['subject']) ? htmlspecialchars($doc['subject']) : '—');
                                        $status = isset($doc['status']) ? htmlspecialchars($doc['status']) : 'Pending';
                                        $date = '—';
                                        if (isset($doc['createdAt'])) {
                                            $dt = $doc['createdAt'];
                                            if ($dt instanceof DateTimeInterface) {
                                                $date = $dt->format('M j, Y');
                                            } elseif (is_string($dt)) {
                                                $date = date('M j, Y', strtotime($dt));
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td><a href="documents.php" class="control-no-link"><?php echo $controlNo; ?></a></td>
                                        <td><?php echo $title; ?></td>
                                        <td><span class="status-badge status-pending"><?php echo $status; ?></span></td>
                                        <td><?php echo $date; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
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
        function updateSubtitle() {
            var el = document.getElementById('dashboard-subtitle');
            if (el) {
                var now = new Date();
                var day = now.toLocaleDateString('en-US', { weekday: 'long' });
                var date = now.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                el.textContent = 'Super Admin Dashboard • ' + day + ', ' + date;
            }
        }
        updateSubtitle();
        setInterval(updateSubtitle, 60000);

        var profileBtn = document.getElementById('profile-logout-btn');
        var profileDropdown = document.getElementById('profile-dropdown');
        var profileLink = document.getElementById('header-profile-link');
        var profileOverlay = document.getElementById('profile-modal-overlay');
        if (profileBtn && profileDropdown) {
            profileBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                var open = profileDropdown.hidden;
                profileDropdown.hidden = !open;
                profileBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function() {
                profileDropdown.hidden = true;
                profileBtn.setAttribute('aria-expanded', 'false');
            });
            profileDropdown.addEventListener('click', function(e) { e.stopPropagation(); });
        }
        if (profileLink && profileOverlay) {
            profileLink.addEventListener('click', function(e) {
                e.preventDefault();
                if (profileDropdown) profileDropdown.hidden = true;
                if (profileBtn) profileBtn.setAttribute('aria-expanded', 'false');
                profileOverlay.classList.add('profile-modal-open');
                profileOverlay.setAttribute('aria-hidden', 'false');
            });
        }

        var statusData = {
            labels: ['Archived', 'Pending Admin', 'Pending Department'],
            datasets: [{
                data: [<?php echo (int)($statusBreakdown['Archived'] ?? 0); ?>, <?php echo (int)($statusBreakdown['Pending Admin'] ?? 0); ?>, <?php echo (int)($statusBreakdown['Pending Department'] ?? 0); ?>],
                backgroundColor: ['#2563eb', '#ea580c', '#16a34a'],
                borderWidth: 0,
                hoverOffset: 4
            }]
        };
        var statusCtx = document.getElementById('chart-status-breakdown');
        if (statusCtx && typeof Chart !== 'undefined') {
            new Chart(statusCtx, {
                type: 'doughnut',
                data: statusData,
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '60%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                    var pct = total ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                    return ctx.label + ': ' + ctx.raw + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }
    })();
    </script>
</body>
</html>