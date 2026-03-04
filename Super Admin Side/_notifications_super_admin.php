<?php
/**
 * Super Admin notifications backed by MySQL.
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
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $pdo = superAdminNotificationPdo($config);
    if (!$pdo) {
        return;
    }
    try {
        $tables = ['super_admin_notifications', 'sent_to_super_admin', 'sent_to_admin'];
        foreach ($tables as $table) {
            $cols = [];
            $stmt = $pdo->query('SHOW COLUMNS FROM ' . $table);
            foreach ($stmt as $row) {
                $name = strtolower((string)($row['Field'] ?? ''));
                if ($name !== '') $cols[$name] = true;
            }
            if (!isset($cols['stamp_image'])) {
                $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN stamp_image LONGTEXT NULL AFTER file_name');
            }
            if (!isset($cols['stamp_width_pct'])) {
                $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN stamp_width_pct DECIMAL(5,2) NOT NULL DEFAULT 18.00 AFTER stamp_image');
            }
            if (!isset($cols['stamp_x_pct'])) {
                $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN stamp_x_pct DECIMAL(5,2) NOT NULL DEFAULT 82.00 AFTER stamp_width_pct');
            }
            if (!isset($cols['stamp_y_pct'])) {
                $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN stamp_y_pct DECIMAL(5,2) NOT NULL DEFAULT 84.00 AFTER stamp_x_pct');
            }
        }
    } catch (Exception $e) {
        // Best-effort migration.
    }
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
    ensureSuperAdminStampColumns($config);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO super_admin_notifications
                (document_id, document_code, document_title, file_name, stamp_image, stamp_width_pct, stamp_x_pct, stamp_y_pct, sent_by_user_id, sent_by_user_name, sent_at)
             VALUES
                (:document_id, :document_code, :document_title, :file_name, :stamp_image, :stamp_width_pct, :stamp_x_pct, :stamp_y_pct, :sent_by_user_id, :sent_by_user_name, NOW())'
        );
        return $stmt->execute([
            ':document_id' => (string)($data['document_id'] ?? ''),
            ':document_code' => (string)($data['document_code'] ?? ''),
            ':document_title' => (string)($data['document_title'] ?? 'Document'),
            ':file_name' => (string)($data['file_name'] ?? 'document.docx'),
            ':stamp_image' => (string)($data['stamp_image'] ?? ''),
            ':stamp_width_pct' => (float)($data['stamp_width_pct'] ?? 18),
            ':stamp_x_pct' => (float)($data['stamp_x_pct'] ?? 82),
            ':stamp_y_pct' => (float)($data['stamp_y_pct'] ?? 84),
            ':sent_by_user_id' => (string)($data['sent_by_user_id'] ?? ''),
            ':sent_by_user_name' => (string)($data['sent_by_user_name'] ?? 'User'),
        ]);
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
            'UPDATE super_admin_notifications
             SET read_at = NOW()
             WHERE id = :id'
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
    ensureSuperAdminStampColumns($config);

    try {
        $unreadStmt = $pdo->query('SELECT COUNT(*) AS unread_count FROM super_admin_notifications WHERE read_at IS NULL');
        $unreadRow = $unreadStmt->fetch();
        $unreadCount = (int)($unreadRow['unread_count'] ?? 0);

        $stmt = $pdo->query(
            'SELECT id, document_id, document_code, document_title, sent_by_user_name, sent_at, read_at
             FROM super_admin_notifications
             ORDER BY sent_at DESC
             LIMIT 20'
        );

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
                'notificationId' => (string)($row['id'] ?? ''),
                'documentId' => (string)($row['document_id'] ?? ''),
                'documentTitle' => trim((string)($row['document_title'] ?? 'Document')),
                'documentCode' => trim((string)($row['document_code'] ?? '')),
                'sentByUserName' => trim((string)($row['sent_by_user_name'] ?? 'Someone')),
                'sentAtFormatted' => $sentAtFormatted,
                'isRead' => !empty($row['read_at']),
            ];
        }

        return ['count' => $unreadCount, 'items' => $items];
    } catch (Exception $e) {
        return ['count' => 0, 'items' => []];
    }
}
