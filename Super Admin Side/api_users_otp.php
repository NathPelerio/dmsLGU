<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized session. Please log in again.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));
if ($action !== 'send_add_user_otp') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid OTP action.']);
    exit;
}

$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/Auth/smtp_mailer.php';

$email = trim((string)($_POST['email'] ?? ''));
$name = trim((string)($_POST['name'] ?? $_POST['username'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a valid Gmail/email address first.']);
    exit;
}

$nextResendAt = (int)($_SESSION['add_user_otp_resend_at'] ?? 0);
$now = time();
if ($nextResendAt > $now) {
    $retryAfter = $nextResendAt - $now;
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Please wait ' . $retryAfter . ' second(s) before resending OTP.',
        'retry_after' => $retryAfter,
    ]);
    exit;
}

$expiryMinutes = (int)($config['otp_expiry_minutes'] ?? 5);
if ($expiryMinutes <= 0) {
    $expiryMinutes = 5;
}

$otp = (string)random_int(100000, 999999);
$_SESSION['add_user_otp_code_hash'] = password_hash($otp, PASSWORD_DEFAULT);
$_SESSION['add_user_otp_email'] = strtolower($email);
$_SESSION['add_user_otp_expires_at'] = time() + ($expiryMinutes * 60);
$_SESSION['add_user_otp_resend_at'] = time() + 30;

$displayName = trim($name) !== '' ? trim($name) : 'User';
$subject = 'DMS LGU Solano - Add User OTP Code';
$message = "Good day, {$displayName}.\n\n"
    . "A request was made to add a user account in DMS LGU Solano.\n"
    . "Your one-time verification code is: {$otp}\n\n"
    . "This code expires in {$expiryMinutes} minute(s).\n"
    . "If you did not request this, please ignore this message.\n\n"
    . "Regards,\nDMS LGU Solano";

$mailError = '';
if (!sendEmailViaSmtp($email, $subject, $message, $config, '', $mailError)) {
    http_response_code(500);
    $safeReason = trim((string)$mailError) !== '' ? trim((string)$mailError) : 'Unknown SMTP error.';
    error_log('Add user OTP send failed (api_users_otp.php): ' . $safeReason);
    echo json_encode(['success' => false, 'message' => 'Failed to send OTP. ' . $safeReason]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'OTP has been sent to the provided Gmail/email.']);
