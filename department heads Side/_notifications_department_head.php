<?php
/**
 * Department Head notifications backed by normalized notifications table.
 */

if (!isset($config)) {
    $config = require dirname(__DIR__) . '/config.php';
}
require_once dirname(__DIR__) . '/db.php';

function departmentHeadNotifPdo($config = null) {
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

function resolveDepartmentHeadSessionUserId($pdo) {
    $rawId = trim((string)($_SESSION['user_id'] ?? ''));
    if ($rawId !== '' && ctype_digit($rawId)) {
        return (int)$rawId;
    }
    $email = trim((string)($_SESSION['user_email'] ?? ''));
    $username = trim((string)($_SESSION['user_username'] ?? ''));
    $name = trim((string)($_SESSION['user_name'] ?? ''));
    try {
        if ($email !== '') {
            $stmt = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
            $stmt->execute([':email' => $email]);
            $row = $stmt->fetch();
            if ($row && !empty($row['user_id'])) {
                $_SESSION['user_id'] = (string)$row['user_id'];
                return (int)$row['user_id'];
            }
        }
        if ($username !== '') {
            $stmt = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1');
            $stmt->execute([':username' => $username]);
            $row = $stmt->fetch();
            if ($row && !empty($row['user_id'])) {
                $_SESSION['user_id'] = (string)$row['user_id'];
                return (int)$row['user_id'];
            }
        }
        if ($name !== '') {
            $stmt = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(name) = LOWER(:name) LIMIT 1');
            $stmt->execute([':name' => $name]);
            $row = $stmt->fetch();
            if ($row && !empty($row['user_id'])) {
                $_SESSION['user_id'] = (string)$row['user_id'];
                return (int)$row['user_id'];
            }
        }
    } catch (Exception $e) {
        return 0;
    }
    return 0;
}

function getDepartmentHeadNotifications($config = null, $ctx = null) {
    $pdo = departmentHeadNotifPdo($config);
    if (!$pdo) {
        return ['count' => 0, 'items' => []];
    }

    $currentUserId = resolveDepartmentHeadSessionUserId($pdo);
    if ($currentUserId <= 0) {
        return ['count' => 0, 'items' => []];
    }

    try {
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

            $sender = 'System';
            $msg = trim((string)($row['message'] ?? ''));
            if ($msg !== '') {
                $routePos = stripos($msg, ' routed ');
                $sentPos = stripos($msg, ' sent ');
                $pos = $routePos !== false ? $routePos : $sentPos;
                if ($pos !== false) {
                    $candidate = trim(substr($msg, 0, $pos));
                    if ($candidate !== '') {
                        $sender = $candidate;
                    }
                }
            }

            $items[] = [
                'notificationId' => (string)($row['notification_id'] ?? ''),
                'documentId' => (string)($row['document_id'] ?? ''),
                'documentTitle' => $documentTitle,
                'documentCode' => trim((string)($row['tracking_code'] ?? '')),
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

function markDepartmentHeadNotificationRead($config, $notificationId, $ctx = null) {
    $pdo = departmentHeadNotifPdo($config);
    if (!$pdo) {
        return false;
    }
    $currentUserId = resolveDepartmentHeadSessionUserId($pdo);
    if ($currentUserId <= 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE notifications
             SET is_read = 1
             WHERE notification_id = :id
               AND user_id = :user_id'
        );
        return $stmt->execute([
            ':id' => (int)$notificationId,
            ':user_id' => $currentUserId,
        ]);
    } catch (Exception $e) {
        return false;
    }
}

function markAllDepartmentHeadNotificationsRead($config, $ctx = null) {
    $pdo = departmentHeadNotifPdo($config);
    if (!$pdo) {
        return false;
    }
    $currentUserId = resolveDepartmentHeadSessionUserId($pdo);
    if ($currentUserId <= 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE notifications
             SET is_read = 1
             WHERE user_id = :user_id
               AND is_read = 0'
        );
        return $stmt->execute([':user_id' => $currentUserId]);
    } catch (Exception $e) {
        return false;
    }
}

