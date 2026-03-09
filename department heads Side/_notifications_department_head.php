<?php
/**
 * Department Head notifications based on sent_to_department_heads rows.
 * A notification exists only when a document is endorsed to a department head.
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

function ensureDepartmentHeadNotifColumns($config = null) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $pdo = departmentHeadNotifPdo($config);
    if (!$pdo) {
        return;
    }
    try {
        $cols = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM sent_to_department_heads');
        foreach ($stmt as $row) {
            $name = strtolower((string)($row['Field'] ?? ''));
            if ($name !== '') {
                $cols[$name] = true;
            }
        }
        if (!isset($cols['read_at'])) {
            $pdo->exec('ALTER TABLE sent_to_department_heads ADD COLUMN read_at DATETIME NULL DEFAULT NULL AFTER sent_at');
        }
    } catch (Exception $e) {
        // Best-effort migration.
    }
}

function departmentHeadNotifContextFromSession() {
    $userId = trim((string)($_SESSION['user_id'] ?? ''));
    $userName = trim((string)($_SESSION['user_name'] ?? ''));
    $userEmail = trim((string)($_SESSION['user_email'] ?? ''));
    $userUsername = trim((string)($_SESSION['user_username'] ?? ''));
    $userDepartment = trim((string)($_SESSION['user_department'] ?? ''));

    $candidates = [];
    foreach ([$userUsername, $userName, $userEmail] as $candidate) {
        $v = mb_strtolower(trim((string)$candidate));
        if ($v !== '') {
            $candidates[] = $v;
        }
    }
    $candidates = array_values(array_unique($candidates));

    return [
        'user_id' => $userId,
        'head_name_1' => $candidates[0] ?? '',
        'head_name_2' => $candidates[1] ?? ($candidates[0] ?? ''),
        'head_name_3' => $candidates[2] ?? ($candidates[1] ?? ($candidates[0] ?? '')),
        'user_department' => $userDepartment,
    ];
}

function departmentHeadNotifBindParams($ctx) {
    return [
        ':user_id' => (string)($ctx['user_id'] ?? ''),
        ':head_name_1' => (string)($ctx['head_name_1'] ?? ''),
        ':head_name_2' => (string)($ctx['head_name_2'] ?? ''),
        ':head_name_3' => (string)($ctx['head_name_3'] ?? ''),
        ':user_department_check' => (string)($ctx['user_department'] ?? ''),
        ':user_department_value' => (string)($ctx['user_department'] ?? ''),
    ];
}

function departmentHeadRecipientSql($alias = 'sth') {
    return '(' . $alias . '.office_head_id = :user_id
        OR (
            ' . $alias . '.office_head_id IS NULL
            AND (
                LOWER(TRIM(' . $alias . '.office_head_name)) IN (:head_name_1, :head_name_2, :head_name_3)
                OR (:user_department_check <> \'\' AND LOWER(TRIM(' . $alias . '.office_name)) = LOWER(TRIM(:user_department_value)))
            )
        ))';
}

function getDepartmentHeadNotifications($config = null, $ctx = null) {
    $pdo = departmentHeadNotifPdo($config);
    if (!$pdo) {
        return ['count' => 0, 'items' => []];
    }
    ensureDepartmentHeadNotifColumns($config);
    if (!is_array($ctx)) {
        $ctx = departmentHeadNotifContextFromSession();
    }
    $params = departmentHeadNotifBindParams($ctx);

    try {
        $unreadStmt = $pdo->prepare(
            'SELECT COUNT(*) AS unread_count
             FROM sent_to_department_heads sth
             WHERE ' . departmentHeadRecipientSql('sth') . '
               AND sth.read_at IS NULL'
        );
        $unreadStmt->execute($params);
        $unreadRow = $unreadStmt->fetch();
        $unreadCount = (int)($unreadRow['unread_count'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT
                sth.id AS notification_id,
                sth.document_id,
                sth.sent_by_user_name,
                sth.sent_at,
                sth.read_at,
                d.document_code,
                d.document_title
             FROM sent_to_department_heads sth
             LEFT JOIN documents d ON d.id = sth.document_id
             WHERE ' . departmentHeadRecipientSql('sth') . '
               AND sth.read_at IS NULL
             ORDER BY sth.sent_at DESC, sth.id DESC
             LIMIT 20'
        );
        $stmt->execute($params);

        $items = [];
        foreach ($stmt as $row) {
            $sentAtFormatted = '—';
            if (!empty($row['sent_at'])) {
                try {
                    $dt = new DateTime((string)$row['sent_at']);
                    $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                    $sentAtFormatted = $dt->format('M j, Y g:i A');
                } catch (Exception $e) {
                    $sentAtFormatted = '—';
                }
            }
            $items[] = [
                'notificationId' => (string)($row['notification_id'] ?? ''),
                'documentId' => (string)($row['document_id'] ?? ''),
                'documentTitle' => trim((string)($row['document_title'] ?? 'Document')),
                'documentCode' => trim((string)($row['document_code'] ?? '')),
                'sentByUserName' => trim((string)($row['sent_by_user_name'] ?? 'System')),
                'sentAtFormatted' => $sentAtFormatted,
                'isRead' => false,
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
    ensureDepartmentHeadNotifColumns($config);
    if (!is_array($ctx)) {
        $ctx = departmentHeadNotifContextFromSession();
    }
    $params = departmentHeadNotifBindParams($ctx);
    $params[':id'] = (int)$notificationId;

    try {
        $stmt = $pdo->prepare(
            'UPDATE sent_to_department_heads sth
             SET sth.read_at = NOW()
             WHERE sth.id = :id
               AND ' . departmentHeadRecipientSql('sth')
        );
        return $stmt->execute($params);
    } catch (Exception $e) {
        return false;
    }
}

function markAllDepartmentHeadNotificationsRead($config, $ctx = null) {
    $pdo = departmentHeadNotifPdo($config);
    if (!$pdo) {
        return false;
    }
    ensureDepartmentHeadNotifColumns($config);
    if (!is_array($ctx)) {
        $ctx = departmentHeadNotifContextFromSession();
    }
    $params = departmentHeadNotifBindParams($ctx);

    try {
        $stmt = $pdo->prepare(
            'UPDATE sent_to_department_heads sth
             SET sth.read_at = NOW()
             WHERE ' . departmentHeadRecipientSql('sth') . '
               AND sth.read_at IS NULL'
        );
        return $stmt->execute($params);
    } catch (Exception $e) {
        return false;
    }
}

