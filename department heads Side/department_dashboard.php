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
if (in_array($userRole, ['staff', 'user'])) {
    header('Location: ../Front%20Desk%20Side/staff_dashboard.php');
    exit;
}

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'Department Head';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Head – Department Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Admin%20Side/admin-dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body class="admin-dashboard">
    <div class="admin-body">
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="../img/logo.png" alt="Municipal Logo">
                </div>
                <div class="sidebar-title">
                    <h2>LGU SOLANO<br><span>DEPARTMENT DASHBOARD</span></h2>
                </div>
            </div>
            <nav class="sidebar-nav">
                <a href="department_dashboard.php" class="sidebar-link active" data-section="home">
                    <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
                    Home
                </a>
            </nav>
        </aside>

        <main class="admin-main" style="background:#fff;">
            <div class="admin-content" id="admin-content" style="background:#fff; color:#1e293b;">
                <div class="admin-content-header-row">
                    <header class="admin-content-header">
                        <div class="admin-header-icon">
                            <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                            </svg>
                        </div>
                        <div class="admin-header-text">
                            <h1 class="admin-content-title" id="admin-content-title">Department Dashboard</h1>
                            <p class="admin-content-subtitle">Welcome to the department head control panel for managing documents and operations</p>
                        </div>
                    </header>
                    <div class="admin-content-icons">
                        <button type="button" class="admin-icon-btn" id="notif-btn" title="Notifications" aria-label="Notifications">
                            <svg class="icon-bell" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        </button>
                        <div class="admin-profile-wrap">
                            <button type="button" class="admin-icon-btn" id="profile-logout-btn" title="Profile and log out" aria-haspopup="true" aria-expanded="false" aria-label="Profile">
                                <svg class="icon-person" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
                            </button>
                            <div class="profile-dropdown" id="profile-dropdown" hidden>
                                <a href="#" class="dropdown-item">Profile</a>
                                <a href="../index.php?logout=1" class="dropdown-item dropdown-logout">Log out</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="admin-content-body" id="admin-content-body">
                <div class="dashboard-welcome-card">
                    <div class="dashboard-welcome-header">
                        <div class="dashboard-welcome-text">
                            <h2 class="dashboard-welcome-title">Welcome, <?php echo htmlspecialchars($userName); ?>!</h2>
                            <p class="dashboard-welcome-quote">"Lead with clarity, manage with confidence."</p>
                        </div>
                    </div>
                    <div class="dashboard-datetime" id="dashboard-datetime">Feb 13, 2026, 8:51 AM</div>
                </div>

                <div class="charts-dashboard" id="charts-dashboard">
                    <div class="chart-card chart-card-wide">
                        <h3 class="chart-title">Document volume over time</h3>
                        <div class="chart-wrap">
                            <canvas id="chart-trend" width="800" height="320"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h3 class="chart-title">Documents by status</h3>
                        <div class="chart-wrap chart-wrap-center">
                            <canvas id="chart-status" width="320" height="320"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h3 class="chart-title">Documents by office</h3>
                        <div class="chart-wrap">
                            <canvas id="chart-offices" width="400" height="280"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
    (function() {
        function updateDateTime() {
            var el = document.getElementById('dashboard-datetime');
            if (el) {
                var now = new Date();
                el.textContent = now.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
            }
        }
        updateDateTime();
        setInterval(updateDateTime, 60000);

        var btn = document.getElementById('profile-logout-btn');
        var dropdown = document.getElementById('profile-dropdown');
        if (btn && dropdown) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var open = dropdown.hidden;
                dropdown.hidden = !open;
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function() {
                dropdown.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            });
            dropdown.addEventListener('click', function(e) { e.stopPropagation(); });
        }

        var chartInstances = [];

        function renderHomeCharts() {
            if (typeof Chart === 'undefined') return;
            var trendCtx = document.getElementById('chart-trend');
            var statusCtx = document.getElementById('chart-status');
            var officesCtx = document.getElementById('chart-offices');
            if (!trendCtx || !statusCtx || !officesCtx) return;
            chartInstances.forEach(function(c) { if (c && c.destroy) c.destroy(); });
            chartInstances = [];
            try {
                chartInstances.push(new Chart(trendCtx, {
                    type: 'line',
                    data: {
                        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                        datasets: [{
                            label: 'Documents processed',
                            data: [42, 58, 51, 72, 65, 88],
                            borderColor: '#D4AF37',
                            backgroundColor: 'rgba(212, 175, 55, 0.15)',
                            borderWidth: 3,
                            pointBackgroundColor: '#D4AF37',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 5,
                            pointHoverRadius: 7,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        aspectRatio: 2.5,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { color: '#64748b' } },
                            x: { grid: { display: false }, ticks: { color: '#64748b' } }
                        }
                    }
                }));
                chartInstances.push(new Chart(statusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Incoming', 'Outgoing', 'Archived', 'Pending'],
                        datasets: [{
                            data: [85, 62, 120, 34],
                            backgroundColor: ['#0b1f3a', '#D4AF37', '#2563eb', '#64748b'],
                            borderWidth: 3,
                            borderColor: '#fff',
                            hoverBorderWidth: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        aspectRatio: 1,
                        plugins: {
                            legend: { position: 'bottom', labels: { padding: 12, usePointStyle: true, color: '#1e293b' } }
                        },
                        cutout: '65%'
                    }
                }));
                chartInstances.push(new Chart(officesCtx, {
                    type: 'bar',
                    data: {
                        labels: ['Mayor', 'Treasury', 'Planning', 'Health', 'Engineering'],
                        datasets: [{
                            label: 'Documents',
                            data: [45, 38, 52, 28, 41],
                            backgroundColor: function(context) {
                                var max = Math.max(...context.dataset.data);
                                var value = context.dataset.data[context.dataIndex];
                                return value === max ? '#D4AF37' : '#0b1f3a';
                            },
                            borderRadius: 8,
                            borderSkipped: false
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        aspectRatio: 1.2,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { color: '#64748b' } },
                            y: { grid: { display: false }, ticks: { color: '#64748b' } }
                        }
                    }
                }));
            } catch (error) { console.error('Error rendering charts:', error); }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() { setTimeout(renderHomeCharts, 500); });
        } else {
            setTimeout(renderHomeCharts, 500);
        }
        window.addEventListener('load', function() { setTimeout(renderHomeCharts, 300); });
    })();
    </script>
</body>
</html>
