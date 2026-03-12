<?php
/**
 * Super Admin notifications backed by the normalized notifications table.
 * Returns count and list for badge + dropdown.
 */

if (!isset($config)) {
    $config = require dirname(__DIR__) . '/config.php';
}
require_once dirname(__DIR__) . '/db.php';

/**
 * Build PDO connection for notification queries.
 * @param array|null $config
 * @return PDO|null
 */
function superAdminNotificationPdo($config = null) {
    $c = $config;
    if ($c === null) {
        global $config;
        $c = isset($config) ? $config : null;
    }
    if (!$c) {
        return null;
    }

    try {
        return dbPdo($c);
    } catch (Exception $e) {
        return null;
    }
}

function ensureSuperAdminStampColumns($config = null) {
    // Kept as a no-op for backward compatibility with existing callers.
}

/**
 * Insert a notification row.
 * @param array $config
 * @param array $data
 * @return bool
 */
function createSuperAdminNotification($config, $data) {
    $pdo = superAdminNotificationPdo($config);
    if (!$pdo) {
        return false;
    }

    try {
        $documentId = (int)($data['document_id'] ?? 0);
        $documentTitle = trim((string)($data['document_title'] ?? 'Document'));
        if ($documentTitle === '') {
            $documentTitle = 'Document';
        }
        $sender = trim((string)($data['sent_by_user_name'] ?? 'User'));
        if ($sender === '') {
            $sender = 'User';
        }
        $message = trim((string)($data['message'] ?? ''));
        if ($message === '') {
            $message = $sender . ' sent ' . $documentTitle;
        }
        $link = trim((string)($data['link'] ?? ''));
        if ($link === '') {
            $link = 'documents.php?highlight=' . $documentId;
        }
        $notificationType = trim((string)($data['notification_type'] ?? ''));
        if ($notificationType === '') {
            $notificationType = 'to_super_admin';
        }

        // Fan out to all super admin users.
        $superAdminIds = [];
        $uStmt = $pdo->query(
            "SELECT user_id
             FROM users
             WHERE LOWER(TRIM(role)) IN ('superadmin', 'super_admin')
             ORDER BY user_id ASC"
        );
        foreach ($uStmt as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            if ($uid > 0) {
                $superAdminIds[] = $uid;
            }
        }
        if (empty($superAdminIds)) {
            return false;
        }

        $ins = $pdo->prepare(
            'INSERT INTO notifications
                (user_id, document_id, notification_type, message, link, is_read, created_at)
             VALUES
                (:user_id, :document_id, :notification_type, :message, :link, 0, NOW())'
        );
        foreach ($superAdminIds as $superAdminId) {
            $ins->execute([
                ':user_id' => $superAdminId,
                ':document_id' => $documentId > 0 ? $documentId : null,
                ':notification_type' => $notificationType,
                ':message' => $message,
                ':link' => $link,
            ]);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Mark one notification as read.
 * @param array $config
 * @param int $notificationId
 * @return bool
 */
function markSuperAdminNotificationRead($config, $notificationId) {
    $pdo = superAdminNotificationPdo($config);
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE notifications
             SET is_read = 1
             WHERE notification_id = :id'
        );
        return $stmt->execute([':id' => (int)$notificationId]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get latest notifications for dropdown.
 * @param array|null $config
 * @return array ['count' => int, 'items' => array]
 */
function getSuperAdminNotifications($config = null) {
    $pdo = superAdminNotificationPdo($config);
    if (!$pdo) {
        return ['count' => 0, 'items' => []];
    }

    try {
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        if ($currentUserId <= 0) {
            return ['count' => 0, 'items' => []];
        }

        $unreadStmt = $pdo->prepare(
            'SELECT COUNT(*) AS unread_count
             FROM notifications
             WHERE user_id = :user_id
               AND is_read = 0'
        );
        $unreadStmt->execute([':user_id' => $currentUserId]);
        $unreadRow = $unreadStmt->fetch();
        $unreadCount = (int)($unreadRow['unread_count'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT
                n.notification_id,
                n.document_id,
                n.notification_type,
                n.message,
                n.link,
                n.is_read,
                n.created_at,
                d.subject,
                d.tracking_code
             FROM notifications n
             LEFT JOIN documents d ON d.document_id = n.document_id
             WHERE n.user_id = :user_id
             ORDER BY n.created_at DESC
             LIMIT 20'
        );
        $stmt->execute([':user_id' => $currentUserId]);

        $items = [];
        foreach ($stmt as $row) {
            $sentAtFormatted = '—';
            if (!empty($row['created_at'])) {
                try {
                    $dt = new DateTime((string)$row['created_at']);
                    $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                    $sentAtFormatted = $dt->format('M j, Y g:i A');
                } catch (Exception $e) {
                    $sentAtFormatted = '—';
                }
            }

            $documentTitle = trim((string)($row['subject'] ?? ''));
            if ($documentTitle === '') {
                $documentTitle = trim((string)($row['message'] ?? 'Document'));
            }
            if ($documentTitle === '') {
                $documentTitle = 'Document';
            }

            $trackingCode = trim((string)($row['tracking_code'] ?? ''));
            $sender = 'System';
            $msg = trim((string)($row['message'] ?? ''));
            if ($msg !== '') {
                $sentPos = stripos($msg, ' sent ');
                if ($sentPos !== false) {
                    $candidate = trim(substr($msg, 0, $sentPos));
                    if ($candidate !== '') {
                        $sender = $candidate;
                    }
                }
            }

            $items[] = [
                'notificationId' => (string)($row['notification_id'] ?? ''),
                'documentId' => (string)($row['document_id'] ?? ''),
                'documentTitle' => $documentTitle,
                'documentCode' => $trackingCode,
                'sentByUserName' => $sender,
                'sentAtFormatted' => $sentAtFormatted,
                'isRead' => !empty($row['is_read']),
            ];
        }

        return ['count' => $unreadCount, 'items' => $items];
    } catch (Exception $e) {
        return ['count' => 0, 'items' => []];
    }
}
