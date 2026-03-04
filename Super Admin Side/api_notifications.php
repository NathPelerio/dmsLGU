<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'superadmin') {
    echo json_encode(['count' => 0, 'items' => []]);
    exit;
}

$config = require dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/_notifications_super_admin.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $notificationId = trim($_POST['notification_id'] ?? '');
        if ($notificationId === '' || !ctype_digit($notificationId)) {
            echo json_encode(['success' => false, 'message' => 'Invalid notification id.']);
            exit;
        }
        try {
            if (!markSuperAdminNotificationRead($config, (int)$notificationId)) {
                throw new RuntimeException('Failed to update notification.');
            }
            $data = getSuperAdminNotifications($config);
            echo json_encode(['success' => true, 'count' => (int)($data['count'] ?? 0)]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Unable to mark notification as read.']);
            exit;
        }
    }
}

$data = getSuperAdminNotifications($config);
echo json_encode($data);
