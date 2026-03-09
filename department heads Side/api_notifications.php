<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

$role = (string)($_SESSION['user_role'] ?? '');
$allowedRoles = ['departmenthead', 'department_head', 'dept_head'];
if (!isset($_SESSION['user_id']) || !in_array($role, $allowedRoles, true)) {
    echo json_encode(['count' => 0, 'items' => []]);
    exit;
}

$config = require dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/_notifications_department_head.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $notificationId = trim($_POST['notification_id'] ?? '');
        if ($notificationId === '' || !ctype_digit($notificationId)) {
            echo json_encode(['success' => false, 'message' => 'Invalid notification id.']);
            exit;
        }
        try {
            if (!markDepartmentHeadNotificationRead($config, (int)$notificationId)) {
                throw new RuntimeException('Failed to update notification.');
            }
            $data = getDepartmentHeadNotifications($config);
            echo json_encode([
                'success' => true,
                'count' => (int)($data['count'] ?? 0),
                'items' => $data['items'] ?? [],
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Unable to mark notification as read.']);
            exit;
        }
    }
    if ($action === 'mark_all_read') {
        try {
            if (!markAllDepartmentHeadNotificationsRead($config)) {
                throw new RuntimeException('Failed to update notifications.');
            }
            $data = getDepartmentHeadNotifications($config);
            echo json_encode([
                'success' => true,
                'count' => (int)($data['count'] ?? 0),
                'items' => $data['items'] ?? [],
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Unable to mark all notifications as read.']);
            exit;
        }
    }
}

$data = getDepartmentHeadNotifications($config);
echo json_encode($data);
