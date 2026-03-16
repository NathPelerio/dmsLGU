/**
 * Admin sidebar behavior: account dropdown (Profile / Sign Out).
 * Clicking Profile opens the profile modal. Include on every Admin Side page that has the sidebar.
 */
(function() {
    function init() {
        var accountBtn = document.getElementById('sidebar-account-btn');
        var accountDropdown = document.getElementById('account-dropdown');
        var profileTrigger = document.getElementById('account-dropdown-profile');
        var profileOverlay = document.getElementById('profile-modal-overlay');

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
            if (e.key === 'Escape' && profileOverlay && profileOverlay.classList.contains('profile-modal-open')) closeProfileModal();
        });

        // Profile picture click to enlarge (zoom)
        var zoomOverlay = document.getElementById('profile-pic-zoom-overlay');
        var zoomContent = document.getElementById('profile-pic-zoom-content');
        var zoomCloseBtn = document.getElementById('profile-pic-zoom-close');
        var zoomTriggers = document.querySelectorAll('.profile-pic-zoom-trigger');

        function openProfilePicZoom(photoSrc, initial) {
            if (!zoomOverlay || !zoomContent) return;
            zoomContent.innerHTML = '';
            if (photoSrc) {
                var img = document.createElement('img');
                img.src = photoSrc;
                img.alt = 'Profile photo (enlarged)';
                zoomContent.appendChild(img);
            } else {
                var div = document.createElement('div');
                div.className = 'profile-pic-zoom-initial';
                div.textContent = initial || '?';
                zoomContent.appendChild(div);
            }
            zoomOverlay.hidden = false;
            zoomOverlay.classList.add('profile-pic-zoom-open');
            zoomOverlay.setAttribute('aria-hidden', 'false');
        }

        function closeProfilePicZoom() {
            if (!zoomOverlay) return;
            zoomOverlay.hidden = true;
            zoomOverlay.classList.remove('profile-pic-zoom-open');
            zoomOverlay.setAttribute('aria-hidden', 'true');
        }

        zoomTriggers.forEach(function(trigger) {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var photo = trigger.getAttribute('data-photo') || '';
                var initial = trigger.getAttribute('data-initial') || '?';
                openProfilePicZoom(photo, initial);
            });
        });

        if (zoomCloseBtn) zoomCloseBtn.addEventListener('click', closeProfilePicZoom);
        if (zoomOverlay) {
            zoomOverlay.addEventListener('click', function(e) {
                if (e.target === zoomOverlay) closeProfilePicZoom();
            });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && zoomOverlay && !zoomOverlay.hidden) closeProfilePicZoom();
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
