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
$userEmail = $_SESSION['user_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff – Settings</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="staff-dashboard.css">
    <style>
        /* Settings page – design styles */
        .settings-page {
            background: #f8fafc;
        }

        .settings-page .admin-content-header-row {
            background: #fff;
        }

        .settings-page .admin-main,
        .settings-page .admin-content-body {
            background: #f8fafc !important;
        }

        .settings-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 6px 0;
            letter-spacing: -0.02em;
        }

        .settings-header p {
            font-size: 0.95rem;
            color: #64748b;
            margin: 0;
        }

        /* Settings cards */
        .settings-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .settings-card h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 6px 0;
        }

        .settings-card-sub {
            font-size: 0.9rem;
            color: #94a3b8;
            margin: 0 0 20px 0;
        }

        .settings-form-group {
            margin-bottom: 20px;
        }

        .settings-form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 500;
            color: #334155;
            margin-bottom: 8px;
        }

        .settings-form-group label .required {
            color: #dc2626;
        }

        .settings-form-group input,
        .settings-form-group select {
            width: 100%;
            height: 44px;
            padding: 0 14px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            color: #1e293b;
            background: #fff;
            outline: none;
        }

        .settings-form-group input::placeholder {
            color: #94a3b8;
        }

        .settings-form-group input:focus {
            border-color: #64748b;
            box-shadow: 0 0 0 2px rgba(100, 116, 139, 0.15);
        }

        .settings-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 640px) {
            .settings-form-row {
                grid-template-columns: 1fr;
            }
        }

        .settings-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: opacity 0.2s, background 0.2s;
        }

        .settings-btn-primary {
            background: #4299e1;
            color: #fff;
        }

        .settings-btn-primary:hover {
            background: #3182ce;
        }
    </style>
</head>
<body class="admin-dashboard settings-page">
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
                    <a href="staff_dashboard.php" class="sidebar-link" data-section="home">
                        <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        Dashboard
                    </a>
                    <a href="documents.php" class="sidebar-link" data-section="documents">
                        <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                        Documents
                    </a>
                    <a href="upload_documents.php" class="sidebar-link" data-section="upload">
                        <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Upload Document
                    </a>
                </div>
                <div class="sidebar-section sidebar-section-account">
                    <span class="sidebar-section-title">ACCOUNT</span>
                    <a href="settings.php" class="sidebar-link sidebar-link-settings active" data-section="settings">
                        <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        Settings
                    </a>
                </div>
            </nav>
        </aside>

        <main class="admin-main" style="background:#f8fafc;">
            <div class="admin-content" id="admin-content" style="background:#f8fafc; color:#1e293b;">
                <div class="admin-content-header-row">
                    <header class="admin-content-header">
                        <div class="settings-header">
                            <h1>Settings</h1>
                            <p>Manage your account and preferences</p>
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
                                <a href="settings.php" class="dropdown-item">Profile</a>
                                <a href="../index.php?logout=1" class="dropdown-item dropdown-logout">Log out</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="admin-content-body" id="admin-content-body" style="background:#f8fafc;">
                <form action="#" method="post" id="settings-form">
                    <div class="settings-card">
                        <h2>Profile Information</h2>
                        <p class="settings-card-sub">Update your personal details</p>
                        <div class="settings-form-row">
                            <div class="settings-form-group">
                                <label for="user_name">Full Name <span class="required">*</span></label>
                                <input type="text" id="user_name" name="user_name" value="<?php echo htmlspecialchars($userName); ?>" placeholder="Enter your name" required>
                            </div>
                            <div class="settings-form-group">
                                <label for="user_email">Email Address <span class="required">*</span></label>
                                <input type="email" id="user_email" name="user_email" value="<?php echo htmlspecialchars($userEmail); ?>" placeholder="Enter your email" required>
                            </div>
                        </div>
                        <button type="submit" class="settings-btn settings-btn-primary">Save Changes</button>
                    </div>

                    <div class="settings-card">
                        <h2>Change Password</h2>
                        <p class="settings-card-sub">Update your password to keep your account secure</p>
                        <div class="settings-form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" placeholder="Enter current password">
                        </div>
                        <div class="settings-form-row">
                            <div class="settings-form-group">
                                <label for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" placeholder="Enter new password">
                            </div>
                            <div class="settings-form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password">
                            </div>
                        </div>
                        <button type="submit" class="settings-btn settings-btn-primary">Update Password</button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
    (function() {
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
    })();
    </script>
</body>
</html>
