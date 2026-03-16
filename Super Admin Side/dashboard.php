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
    <link rel="stylesheet" href="../Admin%20Side/assets/css/admin-dashboard.css">
    <?php $sidebarCssVer = @filemtime(__DIR__ . '/assets/css/sidebar_super_admin.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/css/sidebar_super_admin.css?v=<?= (int)$sidebarCssVer ?>">
    <?php $profileModalCssVer = @filemtime(__DIR__ . '/assets/css/profile_modal_super_admin.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/css/profile_modal_super_admin.css?v=<?= (int)$profileModalCssVer ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        /* Same font as sidebar: Inter for full dashboard consistency */
        body, .dashboard-container, .main-content { font-family: var(--font-sans); }
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
        .dashboard-metrics {
            position: relative;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            border: 1px solid #dbe3ef;
            border-radius: 14px;
            overflow: hidden;
            background: #ffffff;
            margin-bottom: 18px;
        }
        .dashboard-metrics::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            z-index: 3;
            pointer-events: none;
            background: linear-gradient(
                to right,
                #65a30d 0 25%,
                #c47a10 25% 50%,
                #2563eb 50% 75%,
                #0f766e 75% 100%
            );
        }
        .metrics-segment {
            position: relative;
            padding: 14px 20px 12px;
            min-height: 184px;
            display: flex;
            flex-direction: column;
            background: #fff;
        }
        .metrics-segment:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 3px;
            right: 0;
            bottom: 0;
            width: 1px;
            background: #e2e8f0;
        }
        .metrics-segment-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }
        .metrics-segment-title {
            margin: 0;
            font-size: 22px;
            line-height: 1;
            font-weight: 500;
            letter-spacing: 0.07em;
            color: #475569;
            text-transform: uppercase;
        }
        .metrics-segment-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 12px;
            line-height: 1;
            font-weight: 600;
            color: var(--segment-accent, #64748b);
            background: var(--segment-soft, #f1f5f9);
            border: 1px solid var(--segment-border, #cbd5e1);
            white-space: nowrap;
        }
        .metrics-segment-value {
            margin: 0;
            font-size: 36px;
            line-height: 1.05;
            font-weight: 700;
            color: var(--segment-value, var(--segment-accent, #0f172a));
        }
        .metrics-segment-sub {
            margin: 6px 0 0;
            font-size: 14px;
            color: #475569;
        }
        .metrics-segment-spark {
            margin-top: auto;
            display: flex;
            align-items: flex-end;
            gap: 4px;
            min-height: 38px;
            padding-top: 10px;
        }
        .metrics-segment-spark span {
            width: 32px;
            max-width: 100%;
            border-radius: 2px 2px 0 0;
            background: color-mix(in srgb, var(--segment-accent, #64748b) 24%, transparent);
        }
        .metrics-segment-spark span:nth-child(1) { height: 8px; }
        .metrics-segment-spark span:nth-child(2) { height: 13px; }
        .metrics-segment-spark span:nth-child(3) { height: 18px; }
        .metrics-segment-spark span:nth-child(4) { height: 24px; }
        .metrics-segment-spark span:nth-child(5) {
            height: 32px;
            background: var(--segment-accent, #64748b);
        }
        .metrics-segment.is-zero .metrics-segment-spark span {
            height: 4px;
            background: color-mix(in srgb, var(--segment-accent, #64748b) 35%, transparent);
        }
        .metrics-segment-total {
            --segment-accent: #65a30d;
            --segment-soft: #ecfccb;
            --segment-border: #bbf7d0;
            --segment-value: #0f172a;
        }
        .metrics-segment-pending {
            --segment-accent: #c47a10;
            --segment-soft: #fef3c7;
            --segment-border: #fde68a;
        }
        .metrics-segment-approved {
            --segment-accent: #2563eb;
            --segment-soft: #dbeafe;
            --segment-border: #bfdbfe;
        }
        .metrics-segment-completed {
            --segment-accent: #0f766e;
            --segment-soft: #ccfbf1;
            --segment-border: #99f6e4;
        }
        .status-breakdown-card {
            border: 1px solid #e2e8f0 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06) !important;
            background: #ffffff !important;
            border-radius: 14px;
            padding: 18px 20px !important;
        }
        .my-tasks-card,
        .status-breakdown-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        @media (hover: hover) and (pointer: fine) {
            .my-tasks-card:hover,
            .status-breakdown-card:hover {
                transform: translateY(-3px);
                box-shadow: 0 12px 24px rgba(15, 23, 42, 0.1) !important;
                border-color: #d6deeb !important;
            }
        }
        .status-breakdown-card .card-title { margin-bottom: 12px; }
        .status-chart-wrap {
            position: relative;
            width: 140px;
            height: 140px;
            margin: 0 auto;
        }
        .status-chart-wrap canvas {
            width: 140px !important;
            height: 140px !important;
        }
        .status-center-label {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            text-align: center;
        }
        .status-center-value {
            font-size: 22px;
            line-height: 1;
            font-weight: 700;
            color: #0f172a;
        }
        .status-center-text {
            margin-top: 3px;
            font-size: 14px;
            color: #64748b;
            text-transform: lowercase;
            letter-spacing: .01em;
        }
        .status-legend {
            margin-top: 16px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            column-gap: 18px;
            row-gap: 8px;
        }
        .status-legend-item {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            column-gap: 8px;
            min-width: 0;
            font-size: 14px;
            color: #334155;
        }
        .status-legend-swatch {
            width: 11px;
            height: 11px;
            border-radius: 3px;
            flex-shrink: 0;
        }
        .status-legend-name {
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .status-legend-value {
            justify-self: end;
            font-weight: 600;
        }
        .status-legend-value-archived { color: #639922; }
        .status-legend-value-pending { color: #EF9F27; }
        .status-legend-value-approved { color: #378ADD; }
        .status-legend-value-completed { color: #1D9E75; }

        /* Force mobile sidebar behavior directly on this page */
        @media (max-width: 1400px), (hover: none) and (pointer: coarse) {
            .dashboard-container {
                overflow-x: hidden;
            }

            .sidebar-toggle-btn {
                display: inline-flex !important;
                position: fixed !important;
                top: 10px !important;
                right: 10px !important;
                z-index: 2000 !important;
            }

            .sidebar {
                transform: translateX(-100%) !important;
                transition: transform 0.24s ease !important;
                z-index: 1900 !important;
            }

            .sidebar.sidebar-open {
                transform: translateX(0) !important;
            }

            .sidebar-mobile-overlay.show {
                display: block !important;
            }

            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
        }
        @media (max-width: 1180px) {
            .dashboard-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .metrics-segment::after { display: none; }
            .metrics-segment:nth-child(2n+1):not(:last-child)::after {
                content: '';
                position: absolute;
                top: 3px;
                right: 0;
                bottom: 0;
                width: 1px;
                background: #e2e8f0;
            }
            .metrics-segment:nth-child(-n+2) { border-bottom: 1px solid #e2e8f0; }
        }
        @media (max-width: 700px) {
            .dashboard-metrics { grid-template-columns: 1fr; }
            .metrics-segment { border-bottom: 1px solid #e2e8f0; }
            .metrics-segment::after { display: none !important; }
            .metrics-segment:last-child { border-bottom: none; }
            .metrics-segment-title { font-size: 18px; }
            .metrics-segment-value { font-size: 34px; }
            .metrics-segment-sub { font-size: 14px; }
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
                    <div class="dashboard-metrics">
                        <div class="metrics-segment metrics-segment-total<?= ((int)$totalDocuments <= 0) ? ' is-zero' : '' ?>">
                            <div class="metrics-segment-head">
                                <h2 class="metrics-segment-title">TOTAL DOCS</h2>
                                <span class="metrics-segment-badge">All time</span>
                            </div>
                            <p class="metrics-segment-value"><?php echo (int)$totalDocuments; ?></p>
                            <p class="metrics-segment-sub">documents on record</p>
                            <div class="metrics-segment-spark" aria-hidden="true">
                                <span></span><span></span><span></span><span></span><span></span>
                            </div>
                        </div>
                        <div class="metrics-segment metrics-segment-pending<?= ((int)$pendingCount <= 0) ? ' is-zero' : '' ?>">
                            <div class="metrics-segment-head">
                                <h2 class="metrics-segment-title">PENDING</h2>
                                <span class="metrics-segment-badge">Review</span>
                            </div>
                            <p class="metrics-segment-value"><?php echo (int)$pendingCount; ?></p>
                            <p class="metrics-segment-sub">awaiting action</p>
                            <div class="metrics-segment-spark" aria-hidden="true">
                                <span></span><span></span><span></span><span></span><span></span>
                            </div>
                        </div>
                        <div class="metrics-segment metrics-segment-approved<?= ((int)$approvedCount <= 0) ? ' is-zero' : '' ?>">
                            <div class="metrics-segment-head">
                                <h2 class="metrics-segment-title">APPROVED</h2>
                                <span class="metrics-segment-badge">Cleared</span>
                            </div>
                            <p class="metrics-segment-value"><?php echo (int)$approvedCount; ?></p>
                            <p class="metrics-segment-sub">ready for release</p>
                            <div class="metrics-segment-spark" aria-hidden="true">
                                <span></span><span></span><span></span><span></span><span></span>
                            </div>
                        </div>
                        <div class="metrics-segment metrics-segment-completed<?= ((int)$completedCount <= 0) ? ' is-zero' : '' ?>">
                            <div class="metrics-segment-head">
                                <h2 class="metrics-segment-title">COMPLETED</h2>
                                <span class="metrics-segment-badge">Done</span>
                            </div>
                            <p class="metrics-segment-value"><?php echo (int)$completedCount; ?></p>
                            <p class="metrics-segment-sub">fully processed</p>
                            <div class="metrics-segment-spark" aria-hidden="true">
                                <span></span><span></span><span></span><span></span><span></span>
                            </div>
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
                        <?php
                            $chartArchived = (int)($statusBreakdown['Archived'] ?? 0);
                            $chartPendingAdmin = (int)$pendingCount;
                            $chartApproved = (int)$approvedCount;
                            $chartCompleted = (int)$completedCount;
                            $chartTotal = (int)$totalDocuments;
                            $chartSeriesTotal = $chartArchived + $chartPendingAdmin + $chartApproved + $chartCompleted;
                            if ($chartTotal > 0 && $chartSeriesTotal === 0) {
                                // Keep colored donut state when total exists but category mapping yields zero.
                                $chartPendingAdmin = $chartTotal;
                                $chartSeriesTotal = $chartTotal;
                            }
                            $chartHasSeriesData = $chartSeriesTotal > 0;
                            $percentDenominator = $chartTotal > 0 ? $chartTotal : $chartSeriesTotal;
                            $pctArchived = $percentDenominator > 0 ? (int)round(($chartArchived / $percentDenominator) * 100) : 0;
                            $pctPending = $percentDenominator > 0 ? (int)round(($chartPendingAdmin / $percentDenominator) * 100) : 0;
                            $pctApproved = $percentDenominator > 0 ? (int)round(($chartApproved / $percentDenominator) * 100) : 0;
                            $pctCompleted = $percentDenominator > 0 ? (int)round(($chartCompleted / $percentDenominator) * 100) : 0;
                        ?>
                        <div class="dashboard-card status-breakdown-card">
                            <h3 class="card-title">Status Breakdown</h3>
                            <div class="status-chart-wrap">
                                <canvas id="chart-status-breakdown" width="140" height="140"></canvas>
                                <div class="status-center-label" aria-hidden="true">
                                    <span class="status-center-value"><?= (int)$chartTotal ?></span>
                                    <span class="status-center-text">total</span>
                                </div>
                            </div>
                            <div class="status-legend">
                                <div class="status-legend-item">
                                    <span class="status-legend-swatch" style="background:#639922"></span>
                                    <span class="status-legend-name">Archived</span>
                                    <span class="status-legend-value status-legend-value-archived"><?= (int)$pctArchived ?>%</span>
                                </div>
                                <div class="status-legend-item">
                                    <span class="status-legend-swatch" style="background:#EF9F27"></span>
                                    <span class="status-legend-name">Pending admin</span>
                                    <span class="status-legend-value status-legend-value-pending"><?= (int)$pctPending ?>%</span>
                                </div>
                                <div class="status-legend-item">
                                    <span class="status-legend-swatch" style="background:#378ADD"></span>
                                    <span class="status-legend-name">Approved</span>
                                    <span class="status-legend-value status-legend-value-approved"><?= (int)$pctApproved ?>%</span>
                                </div>
                                <div class="status-legend-item">
                                    <span class="status-legend-swatch" style="background:#1D9E75"></span>
                                    <span class="status-legend-name">Completed</span>
                                    <span class="status-legend-value status-legend-value-completed"><?= (int)$pctCompleted ?>%</span>
                                </div>
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
                                        $controlNo = isset($doc['tracking_code']) ? htmlspecialchars($doc['tracking_code']) : (isset($doc['controlNo']) ? htmlspecialchars($doc['controlNo']) : (isset($doc['control_no']) ? htmlspecialchars($doc['control_no']) : '—'));
                                        $title = isset($doc['title']) ? htmlspecialchars($doc['title']) : (isset($doc['subject']) ? htmlspecialchars($doc['subject']) : '—');
                                        $status = isset($doc['status']) ? htmlspecialchars($doc['status']) : 'Pending';
                                        $date = '—';
                                        if (isset($doc['created_at'])) {
                                            $dt = $doc['created_at'];
                                            if (is_string($dt)) {
                                                $date = date('M j, Y', strtotime($dt));
                                            }
                                        } elseif (isset($doc['createdAt'])) {
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
    <?php $sidebarJsVer = @filemtime(__DIR__ . '/assets/js/sidebar_super_admin.js') ?: time(); ?>
    <script src="assets/js/sidebar_super_admin.js?v=<?= (int)$sidebarJsVer ?>"></script>
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

        var statusData = <?= $chartHasSeriesData ? json_encode([
            'labels' => ['Archived', 'Pending admin', 'Approved', 'Completed'],
            'datasets' => [[
                'data' => [(int)$chartArchived, (int)$chartPendingAdmin, (int)$chartApproved, (int)$chartCompleted],
                'backgroundColor' => ['#639922', '#EF9F27', '#378ADD', '#1D9E75'],
                'borderColor' => '#ffffff',
                'borderWidth' => 2,
                'hoverOffset' => 2,
            ]],
        ], JSON_UNESCAPED_SLASHES) : json_encode([
            'labels' => ['No categorized data'],
            'datasets' => [[
                'data' => [1],
                'backgroundColor' => ['#e2e8f0'],
                'borderColor' => '#ffffff',
                'borderWidth' => 2,
                'hoverOffset' => 0,
            ]],
        ], JSON_UNESCAPED_SLASHES) ?>;
        var statusCtx = document.getElementById('chart-status-breakdown');
        if (statusCtx && typeof Chart !== 'undefined') {
            new Chart(statusCtx, {
                type: 'doughnut',
                data: statusData,
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '70%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    if (!<?= $chartHasSeriesData ? 'true' : 'false' ?>) {
                                        return 'No categorized data';
                                    }
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