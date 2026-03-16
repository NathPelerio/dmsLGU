/**
 * Super Admin real-time notifications: poll api_notifications.php and update badge + dropdown.
 * Expects #notif-count and #notif-dropdown on the page.
 */
(function() {
    var notifBtn = document.getElementById('notif-btn');
    var notifDropdown = document.getElementById('notif-dropdown');

    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s == null ? '' : s;
        return div.innerHTML;
    }
    function getSenderInitial(sender) {
        var name = (sender || '').trim();
        if (!name) return 'S';
        return name.charAt(0).toUpperCase();
    }
    function renderFacebookItem(ni, isExtra) {
        var href = (ni.documentId && ni.documentId.length > 0) ? ('documents.php?highlight=' + encodeURIComponent(ni.documentId)) : 'documents.php';
        var sender = (ni.sentByUserName || '').trim() || 'System';
        var title = (ni.documentTitle || '').trim() || 'Document';
        var sentAt = (ni.sentAtFormatted || '').trim();
        var notifId = (ni.notificationId || '').trim();
        var isRead = !!ni.isRead;
        return '<a href="' + href + '" class="notif-item notif-item-link' + (isExtra ? ' notif-extra' : '') + (isRead ? ' notif-read' : '') + '" data-notif-id="' + escapeHtml(notifId) + '">'
            + '<span class="notif-avatar">' + escapeHtml(getSenderInitial(sender)) + '</span>'
            + '<span class="notif-item-content">'
            + '<span class="notif-item-text"><strong>' + escapeHtml(sender) + '</strong> sent <strong>' + escapeHtml(title) + '</strong></span>'
            + '<span class="notif-item-time">' + escapeHtml(sentAt) + '</span>'
            + '</span>'
            + '<span class="notif-unread-dot" aria-hidden="true"></span>'
            + '</a>';
    }
    function renderLegacyNotifications(items) {
        if (items.length === 0) {
            return '<div class="notif-item">No new notifications</div>';
        }
        var html = '';
        items.forEach(function(ni) {
            var href = (ni.documentId && ni.documentId.length > 0) ? ('documents.php?highlight=' + encodeURIComponent(ni.documentId)) : 'documents.php';
            html += '<a href="' + href + '" class="notif-item notif-item-link">' + escapeHtml(ni.documentTitle || 'Document') + ' — from ' + escapeHtml(ni.sentByUserName || '') + ' (' + escapeHtml(ni.sentAtFormatted || '') + ')</a>';
        });
        return html;
    }
    function renderFacebookNotifications(items, expanded) {
        var previewLimit = 4;
        var html = '<div class="notif-dropdown-head">Notifications</div><div class="notif-dropdown-list">';
        if (items.length === 0) {
            html += '<div class="notif-empty">No new notifications</div>';
        } else {
            items.forEach(function(ni, idx) {
                html += renderFacebookItem(ni, idx >= previewLimit);
            });
        }
        html += '</div>';
        if (items.length > previewLimit) {
            html += '<div class="notif-dropdown-footer"><button type="button" class="notif-more-btn" data-notif-toggle>' + (expanded ? 'Show less' : 'Show more') + '</button></div>';
        }
        return html;
    }
    function updateNotifUI(data) {
        var count = parseInt(data.count, 10) || 0;
        var items = data.items || [];
        var notifCountEl = document.getElementById('notif-count');
        if (notifCountEl) {
            notifCountEl.textContent = count;
            notifCountEl.style.display = count > 0 ? '' : 'none';
        }
        if (notifDropdown) {
            var useFacebookDesign = notifDropdown.classList.contains('notif-dropdown-fb');
            var expanded = notifDropdown.getAttribute('data-expanded') === '1';
            notifDropdown.innerHTML = useFacebookDesign
                ? renderFacebookNotifications(items, expanded)
                : renderLegacyNotifications(items);
        }
    }
    function updateBadgeCount(count) {
        var notifCountEl = document.getElementById('notif-count');
        if (!notifCountEl) return;
        var c = parseInt(count, 10) || 0;
        notifCountEl.textContent = c;
        notifCountEl.style.display = c > 0 ? '' : 'none';
    }
    function markNotificationRead(notifId) {
        if (!notifId) return Promise.resolve(null);
        var body = 'action=mark_read&notification_id=' + encodeURIComponent(notifId);
        return fetch('api_notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body
        }).then(function(res) {
            return res.json();
        }).then(function(data) {
            if (data && typeof data.count !== 'undefined') {
                updateBadgeCount(data.count);
            }
            return data;
        }).catch(function() {
            return null;
        });
    }
    function closeNotifDropdown() {
        if (!notifDropdown) return;
        notifDropdown.style.display = 'none';
    }
    if (notifBtn) {
        notifBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!notifDropdown) return;
            var showing = notifDropdown.style.display === 'block';
            closeNotifDropdown();
            notifDropdown.style.display = showing ? 'none' : 'block';
        });
    }
    document.addEventListener('click', function(e) {
        var toggleBtn = e.target && e.target.closest ? e.target.closest('[data-notif-toggle]') : null;
        if (!toggleBtn) return;
        e.preventDefault();
        e.stopPropagation();
        var dropdown = toggleBtn.closest('.notif-dropdown-fb');
        if (!dropdown) return;
        var expanded = dropdown.getAttribute('data-expanded') === '1';
        dropdown.setAttribute('data-expanded', expanded ? '0' : '1');
        toggleBtn.textContent = expanded ? 'Show more' : 'Show less';
    });
    document.addEventListener('click', function(e) {
        var link = e.target && e.target.closest ? e.target.closest('.notif-item-link[data-notif-id]') : null;
        if (!link) return;
        var notifId = (link.getAttribute('data-notif-id') || '').trim();
        if (!notifId || link.classList.contains('notif-read')) return;

        // For a normal click, save "read" first then navigate.
        if (e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey) {
            e.preventDefault();
            var href = link.getAttribute('href') || 'documents.php';
            link.classList.add('notif-read');
            markNotificationRead(notifId).finally(function() {
                window.location.href = href;
            });
            return;
        }

        // For open-in-new-tab clicks, mark in background.
        markNotificationRead(notifId);
    });
    document.addEventListener('click', function(e) {
        if (!notifDropdown) return;
        if (notifDropdown.contains(e.target) || (notifBtn && notifBtn.contains(e.target))) return;
        closeNotifDropdown();
    });
    function fetchNotifications() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'api_notifications.php', true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            try {
                var data = JSON.parse(xhr.responseText || '{"count":0,"items":[]}');
                updateNotifUI(data);
            } catch (e) {}
        };
        xhr.send();
    }
    fetchNotifications();
    setInterval(fetchNotifications, 8000);
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') fetchNotifications();
    });
})();
