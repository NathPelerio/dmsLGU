<?php
/**
 * Department Head sidebar using the same UI structure as Super Admin sidebar.
 * Expects in scope: $sidebar_active, $userName, $userRole, $userInitial.
 */
?>
<div class="sidebar">
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
            <li>
                <a href="department_dashboard.php" class="<?php echo $sidebar_active === 'dashboard' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="7"/>
                        <rect x="14" y="3" width="7" height="7"/>
                        <rect x="14" y="14" width="7" height="7"/>
                        <rect x="3" y="14" width="7" height="7"/>
                    </svg>
                    Dashboard
                </a>
            </li>
            <li>
                <a href="department_documents.php" class="<?php echo $sidebar_active === 'documents' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                    Documents
                </a>
            </li>
        </ul>
    </nav>
    <div class="sidebar-user-wrap">
        <div class="sidebar-user" id="sidebar-account-btn" role="button" tabindex="0" aria-label="Account menu" aria-haspopup="true" aria-expanded="false">
            <div class="sidebar-user-avatar profile-photo-view-trigger" role="button" tabindex="0" title="Click to view">
                <?php if (!empty($_SESSION['user_photo'])): ?>
                    <img src="<?php echo htmlspecialchars($_SESSION['user_photo']); ?>" alt="">
                <?php else: ?>
                    <?php echo htmlspecialchars($userInitial); ?>
                <?php endif; ?>
            </div>
            <div class="sidebar-user-info">
                <p class="sidebar-user-name"><?php echo htmlspecialchars($userName); ?></p>
                <p class="sidebar-user-role"><?php echo htmlspecialchars($userRole); ?></p>
            </div>
        </div>
        <div class="account-dropdown" id="account-dropdown" role="menu" aria-label="Account menu">
            <button type="button" class="account-dropdown-item account-dropdown-profile" id="account-dropdown-profile" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                Profile
            </button>
            <a href="../index.php?logout=1" class="account-dropdown-item account-dropdown-signout" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                Sign Out
            </a>
        </div>
    </div>
</div>
