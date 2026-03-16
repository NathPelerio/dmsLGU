/**
 * Super Admin sidebar behavior: account dropdown (Profile / Sign Out). Settings links to settings.php.
 * Include this script on any page that includes _sidebar_super_admin.php.
 */
(function() {
    function init() {
        var sidebar = document.getElementById('sidebar-super-admin') || document.querySelector('.sidebar');
        var sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
        var sidebarMobileOverlay = document.getElementById('sidebar-mobile-overlay');
        var accountBtn = document.getElementById('sidebar-account-btn');
        var accountDropdown = document.getElementById('account-dropdown');
        var profileTrigger = document.getElementById('account-dropdown-profile');
        var profileOverlay = document.getElementById('profile-modal-overlay');

        function isMobileSidebar() {
            return window.matchMedia('(max-width: 1200px)').matches;
        }
        function openSidebar() {
            if (!sidebar || !isMobileSidebar()) return;
            sidebar.classList.add('sidebar-open');
            if (sidebarMobileOverlay) {
                sidebarMobileOverlay.hidden = false;
                sidebarMobileOverlay.classList.add('show');
            }
            if (sidebarToggleBtn) sidebarToggleBtn.setAttribute('aria-expanded', 'true');
            document.body.classList.add('sidebar-mobile-lock');
        }
        function closeSidebar() {
            if (!sidebar) return;
            sidebar.classList.remove('sidebar-open');
            if (sidebarMobileOverlay) {
                sidebarMobileOverlay.classList.remove('show');
                sidebarMobileOverlay.hidden = true;
            }
            if (sidebarToggleBtn) sidebarToggleBtn.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('sidebar-mobile-lock');
        }
        if (sidebarToggleBtn) {
            sidebarToggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (!sidebar) return;
                if (sidebar.classList.contains('sidebar-open')) closeSidebar();
                else openSidebar();
            });
        }
        if (sidebarMobileOverlay) {
            sidebarMobileOverlay.addEventListener('click', closeSidebar);
        }
        if (sidebar) {
            sidebar.querySelectorAll('a').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (isMobileSidebar()) closeSidebar();
                });
            });
        }
        window.addEventListener('resize', function() {
            if (!isMobileSidebar()) closeSidebar();
        });
        closeSidebar();

        function closeAccountDropdown() {
            if (accountDropdown) {
                accountDropdown.classList.remove('open');
                if (accountBtn) accountBtn.setAttribute('aria-expanded', 'false');
            }
        }
        function openProfileModal() {
            if (profileOverlay) {
                profileOverlay.classList.add('profile-modal-open');
                profileOverlay.setAttribute('aria-hidden', 'false');
            }
        }
        if (accountBtn && accountDropdown) {
            accountBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                accountDropdown.classList.toggle('open');
                accountBtn.setAttribute('aria-expanded', accountDropdown.classList.contains('open'));
            });
            accountBtn.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    accountDropdown.classList.toggle('open');
                    accountBtn.setAttribute('aria-expanded', accountDropdown.classList.contains('open'));
                }
            });
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.sidebar-user-wrap')) closeAccountDropdown();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeAccountDropdown();
            });
        }
        if (profileTrigger) {
            profileTrigger.addEventListener('click', function(e) {
                e.preventDefault();
                closeAccountDropdown();
                openProfileModal();
            });
        }
        var profileCloseBtn = document.getElementById('profile-modal-close');
        function closeProfileModal() {
            if (profileOverlay) {
                profileOverlay.classList.remove('profile-modal-open');
                profileOverlay.setAttribute('aria-hidden', 'true');
            }
        }
        if (profileCloseBtn) profileCloseBtn.addEventListener('click', closeProfileModal);
        if (profileOverlay) {
            profileOverlay.addEventListener('click', function(e) {
                if (e.target === profileOverlay) closeProfileModal();
            });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (profileOverlay && profileOverlay.classList.contains('profile-modal-open')) closeProfileModal();
                if (sidebar && sidebar.classList.contains('sidebar-open')) closeSidebar();
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
