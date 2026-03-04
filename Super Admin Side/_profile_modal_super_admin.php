<?php
/**
 * Super Admin profile modal – include on every page that has the sidebar.
 * Expects: $userName, $userEmail, $userRole, $userInitial (and $_SESSION['user_photo']).
 * Optional: $userDepartment; falls back to $_SESSION['user_department'] or 'Not Assigned'.
 * Username is always read from DB so profile stays in sync when username is updated in database.
 */
$userDepartment = $userDepartment ?? $_SESSION['user_department'] ?? 'Not Assigned';
$profileUsername = (function_exists('getUserUsername') ? getUserUsername($_SESSION['user_id'] ?? '') : '') ?: ($_SESSION['user_username'] ?? '');
if ($profileUsername !== '') $_SESSION['user_username'] = $profileUsername;
if ($profileUsername === '') $profileUsername = 'User';
?>
<div class="profile-modal-overlay" id="profile-modal-overlay" aria-hidden="true">
    <div class="profile-modal" id="profile-modal" role="dialog" aria-labelledby="profile-modal-title">
        <div class="profile-modal-header">
            <button type="button" class="profile-modal-close-btn" id="profile-modal-close" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"></path><path d="M6 6l12 12"></path></svg></button>
            <h2 class="profile-modal-title" id="profile-modal-title">Profile</h2>
            <p class="profile-modal-subtitle">Your account information and password</p>
        </div>
        <div class="profile-modal-body">
            <div class="profile-info-card">
                <h3>Profile Information</h3>
                <p class="profile-info-desc">Your account details and role in the system</p>
                <div class="profile-info-grid">
                    <div class="profile-info-avatar profile-photo-view-trigger" role="button" tabindex="0" title="Click to view"><?php if (!empty($_SESSION['user_photo'])): ?><img src="<?php echo htmlspecialchars($_SESSION['user_photo']); ?>" alt=""><?php else: ?><?php echo htmlspecialchars($userInitial); ?><?php endif; ?></div>
                    <span class="profile-info-label">Username</span>
                    <p class="profile-info-value"><?php echo htmlspecialchars($profileUsername); ?></p>
                    <span class="profile-info-label">Role</span>
                    <p class="profile-info-value"><?php echo htmlspecialchars($userRole); ?></p>
                    <span class="profile-info-label">Email</span>
                    <p class="profile-info-value"><?php echo htmlspecialchars($userEmail); ?></p>
                    <span class="profile-info-label">Department</span>
                    <p class="profile-info-value"><?php echo htmlspecialchars($userDepartment); ?></p>
                </div>
            </div>
            <div class="profile-password-card">
                <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>Change Password</h3>
                <p class="profile-info-desc">Update your account password</p>
                <form method="post" id="profile-change-password-form" action="offices-department.php">
                    <input type="hidden" name="action" value="change_password">
                    <div class="offices-field">
                        <label for="profile-current-password">Current Password</label>
                        <input type="password" name="current_password" id="profile-current-password" placeholder="Enter current password" autocomplete="current-password">
                    </div>
                    <div class="offices-field">
                        <label for="profile-new-password">New Password</label>
                        <input type="password" name="new_password" id="profile-new-password" placeholder="Enter new password" autocomplete="new-password">
                    </div>
                    <div class="offices-field">
                        <label for="profile-confirm-password">Confirm New Password</label>
                        <input type="password" name="confirm_password" id="profile-confirm-password" placeholder="Confirm new password" autocomplete="new-password">
                    </div>
                    <button type="submit" class="profile-modal-btn-update" style="margin-top:0.75rem;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- View Profile Photo Modal -->
<div class="profile-photo-view-overlay" id="profile-photo-view-overlay" aria-hidden="true">
    <div class="profile-photo-view-modal">
        <button type="button" class="profile-photo-view-close" id="profile-photo-view-close" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
        <h3 class="profile-photo-view-title">Profile Photo</h3>
        <div class="profile-photo-view-body">
            <div class="profile-photo-view-img-wrap" id="profile-photo-view-img-wrap">
                <img id="profile-photo-view-img" src="" alt="Profile photo" style="display:none;">
            </div>
            <p class="profile-photo-view-empty" id="profile-photo-view-empty" style="display:none;">No profile photo set</p>
        </div>
    </div>
</div>
<script>
(function(){
    function initProfilePhotoView() {
        var overlay = document.getElementById('profile-photo-view-overlay');
        var imgWrap = document.getElementById('profile-photo-view-img-wrap');
        var img = document.getElementById('profile-photo-view-img');
        var emptyMsg = document.getElementById('profile-photo-view-empty');
        var closeBtn = document.getElementById('profile-photo-view-close');
        if (!overlay) return;
        function openViewModal(photoSrc) {
            if (photoSrc && typeof photoSrc === 'string' && photoSrc.trim() !== '') {
                if (img) { img.src = photoSrc; img.style.display = ''; }
                if (imgWrap) imgWrap.style.display = '';
                if (emptyMsg) emptyMsg.style.display = 'none';
            } else {
                if (img) { img.src = ''; img.style.display = 'none'; }
                if (imgWrap) imgWrap.style.display = 'none';
                if (emptyMsg) emptyMsg.style.display = 'block';
            }
            overlay.classList.add('profile-photo-view-open');
            overlay.setAttribute('aria-hidden', 'false');
        }
        function closeViewModal() {
            overlay.classList.remove('profile-photo-view-open');
            overlay.setAttribute('aria-hidden', 'true');
        }
        document.addEventListener('click', function(e) {
            var trigger = e.target.closest('.profile-photo-view-trigger');
            if (trigger) {
                e.preventDefault();
                e.stopPropagation();
                var imgEl = trigger.querySelector('img');
                var photo = (imgEl && imgEl.src) ? imgEl.src : (trigger.getAttribute('data-photo') || '');
                openViewModal(photo);
            }
        }, true);
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (overlay.classList.contains('profile-photo-view-open')) closeViewModal();
                return;
            }
            if (e.key === 'Enter' && document.activeElement && document.activeElement.classList.contains('profile-photo-view-trigger')) {
                e.preventDefault();
                var imgEl = document.activeElement.querySelector('img');
                var photo = (imgEl && imgEl.src) ? imgEl.src : (document.activeElement.getAttribute('data-photo') || '');
                openViewModal(photo);
            }
        });
        if (closeBtn) closeBtn.addEventListener('click', closeViewModal);
        overlay.addEventListener('click', function(e) { if (e.target === overlay) closeViewModal(); });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initProfilePhotoView);
    } else {
        initProfilePhotoView();
    }
})();
</script>
