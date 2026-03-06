<?php
session_start();

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Super Admin Side/_activity_logger.php';
require_once __DIR__ . '/Auth/_auth_rate_limiter.php';
$error = '';
$success = '';
$suspendErrorSeconds = 0;
$suspendErrorReason = '';

function formatRemainingSuspension($seconds) {
    $seconds = max(0, (int)$seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

function getAccountRestrictionMessage($user) {
    $state = strtolower(trim((string)($user['account_state'] ?? 'active')));
    if ($state === '' || $state === 'active') {
        return ['message' => '', 'remaining_seconds' => 0];
    }

    if ($state === 'disabled') {
        $reason = trim((string)($user['disabled_reason'] ?? ''));
        $msg = 'This account is disabled.';
        if ($reason !== '') {
            $msg .= ' Reason: ' . $reason;
        }
        return ['message' => $msg, 'remaining_seconds' => 0];
    }

    if ($state === 'suspended') {
        $remainingSeconds = dbSuspendRemainingSeconds($user);
        if ($remainingSeconds !== null && $remainingSeconds <= 0) {
            return ['message' => '', 'remaining_seconds' => 0];
        }

        $displayRemaining = 86400;
        if ($remainingSeconds !== null) {
            $displayRemaining = max(1, (int)$remainingSeconds);
        }
        $reason = trim((string)($user['suspend_reason'] ?? ''));
        $msg = 'This account is suspended for ' . formatRemainingSuspension($displayRemaining) . ' (HH:MM:SS).';
        if ($reason !== '') {
            $msg .= ' Reason: ' . $reason;
        }
        return ['message' => $msg, 'remaining_seconds' => $displayRemaining];
    }

    return ['message' => '', 'remaining_seconds' => 0];
}

if (isset($_GET['error'])) {
    $errMap = [
        'google_not_configured'   => 'Sign in with Google is not configured. Add Google Client ID and Secret in config.',
        'google_token_failed'    => 'Google sign-in failed. Please try again.',
        'google_userinfo_failed' => 'Could not get your Google profile. Please try again.',
        'google_no_email'        => 'Your Google account did not provide an email.',
        'google_not_authorized'  => 'This email is not authorized. Your account must exist in the system. Contact your administrator.',
        'google_otp_send_failed' => 'Google sign-in was verified, but we could not send your OTP email. Please check mail settings and try again.',
        'google_trusted_confirm_send_failed' => 'Google sign-in was verified, but we could not send the trusted-device confirmation email. Please try again.',
        'google_create_failed'   => 'Could not create your account. Please try again.',
        'google_login_failed'    => 'Login failed. Please try again.',
        'google_account_disabled' => 'Your account is disabled.',
        'google_account_suspended' => 'Your account is suspended.',
    ];
    $error = $errMap[$_GET['error']] ?? 'An error occurred. Please try again.';
    if ($_GET['error'] === 'google_account_disabled' && isset($_GET['reason'])) {
        $reason = trim((string)$_GET['reason']);
        if ($reason !== '') {
            $error .= ' Reason: ' . $reason;
        }
    } elseif ($_GET['error'] === 'google_account_suspended') {
        $rawSeconds = isset($_GET['seconds']) ? (int)$_GET['seconds'] : 0;
        $suspendErrorSeconds = max(0, $rawSeconds);
        if ($suspendErrorSeconds <= 0) {
            $days = max(1, (int)($_GET['days'] ?? 1));
            $suspendErrorSeconds = $days * 86400;
        }
        $error = 'Your account is suspended for ' . formatRemainingSuspension($suspendErrorSeconds) . ' (HH:MM:SS).';
        $reason = trim((string)($_GET['reason'] ?? ''));
        if ($reason !== '') {
            $error .= ' Reason: ' . $reason;
            $suspendErrorReason = $reason;
        }
    }
}
$emailError = false;
$passwordError = false;
$adminError = '';
$adminUsernameError = false;
$adminPasswordError = false;
$staffRateLimitSeconds = 0;
$staffRateLimitType = '';
$adminRateLimitSeconds = 0;
$adminRateLimitType = '';

if (isset($_GET['logout'])) {
    activityLog($config, 'logout', ['module' => 'auth', 'source' => 'index.php'], 'success');
    session_destroy();
    header('Location: ' . str_replace('?logout=1', '', $_SERVER['REQUEST_URI']));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_type']) && $_POST['login_type'] === 'admin') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $clientIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $adminRateScope = 'manual_admin_login';
    $adminRateId = strtolower($username);
    if ($username === '' || $password === '') {
        $adminError = 'Username and password are required.';
        if ($username === '') $adminUsernameError = true;
        if ($password === '') $adminPasswordError = true;
    } else {
        $rateStatus = authRateLimiterStatus($config, $adminRateScope, $adminRateId, $clientIp);
        if (!empty($rateStatus['blocked'])) {
            $adminError = authRateLimiterMessage($rateStatus);
            $adminRateLimitSeconds = max(1, (int)($rateStatus['seconds_left'] ?? 0));
            $adminRateLimitType = (string)($rateStatus['type'] ?? '');
            $adminPasswordError = true;
        } else {
        try {
            $pdo = dbPdo($config);
            $desiredRole = trim($_POST['role'] ?? 'admin');
            if (!in_array($desiredRole, ['admin', 'superadmin'])) $desiredRole = 'admin';
            $stmt = $pdo->prepare(
                'SELECT * FROM users
                 WHERE role = :role AND (email = :identifier OR username = :identifier)
                 LIMIT 1'
            );
            $stmt->execute([
                ':role' => $desiredRole,
                ':identifier' => $username,
            ]);
            $user = $stmt->fetch();
            if (!$user) {
                $failStatus = authRateLimiterFail($config, $adminRateScope, $adminRateId, $clientIp);
                $adminError = authRateLimiterMessage($failStatus);
                $adminRateLimitSeconds = max(1, (int)($failStatus['seconds_left'] ?? 0));
                $adminRateLimitType = (string)($failStatus['type'] ?? '');
                $adminUsernameError = true;
            } else {
                $storedPassword = $user['password'] ?? '';
                $passwordMatch = (isset($user['password']) && password_verify($password, $storedPassword)) || $storedPassword === $password;
                if ($passwordMatch) {
                    $restriction = getAccountRestrictionMessage($user);
                    if (($restriction['message'] ?? '') !== '') {
                        $adminError = (string)$restriction['message'];
                        $suspendErrorSeconds = max($suspendErrorSeconds, (int)($restriction['remaining_seconds'] ?? 0));
                        $suspendErrorReason = trim((string)($user['suspend_reason'] ?? ''));
                        activityLog($config, 'login_blocked', [
                            'module' => 'auth',
                            'login_type' => 'manual_admin',
                            'reason' => $adminError,
                            'target_username' => $username,
                        ], 'blocked');
                    } else {
                        authRateLimiterReset($config, $adminRateScope, $adminRateId, $clientIp);
                        $_SESSION['user_id'] = (string)($user['id'] ?? '');
                        $_SESSION['user_email'] = $user['email'] ?? $username;
                        $_SESSION['user_name'] = $user['name'] ?? $username;
                        $_SESSION['user_username'] = $user['username'] ?? '';
                        $sessionRole = $user['role'] ?? $desiredRole;
                        $_SESSION['user_role'] = $sessionRole;
                        if ($sessionRole === 'superadmin') {
                            header('Location: Super%20Admin%20Side/dashboard.php');
                        } else {
                            header('Location: Admin%20Side/admin_dashboard.php');
                        }
                        activityLog($config, 'login_success', [
                            'module' => 'auth',
                            'login_type' => 'manual_admin',
                            'role' => $sessionRole,
                        ], 'success');
                        exit;
                    }
                } else {
                    $failStatus = authRateLimiterFail($config, $adminRateScope, $adminRateId, $clientIp);
                    $adminError = authRateLimiterMessage($failStatus);
                    $adminRateLimitSeconds = max(1, (int)($failStatus['seconds_left'] ?? 0));
                    $adminRateLimitType = (string)($failStatus['type'] ?? '');
                    $adminPasswordError = true;
                }
            }
        } catch (Exception $e) {
            $adminError = 'Login error: ' . $e->getMessage();
        }
        }
    }
}

// Handle staff login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['login_type']) || $_POST['login_type'] === 'staff') && isset($_POST['email']) && isset($_POST['password'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $clientIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $staffRateScope = 'manual_staff_login';
    $staffRateId = strtolower($email);
    
    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
        if ($email === '') $emailError = true;
        if ($password === '') $passwordError = true;
    } else {
        $rateStatus = authRateLimiterStatus($config, $staffRateScope, $staffRateId, $clientIp);
        if (!empty($rateStatus['blocked'])) {
            $error = authRateLimiterMessage($rateStatus);
            $staffRateLimitSeconds = max(1, (int)($rateStatus['seconds_left'] ?? 0));
            $staffRateLimitType = (string)($rateStatus['type'] ?? '');
            $passwordError = true;
        } else {
        try {
            $pdo = dbPdo($config);
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $userArray = $stmt->fetch();

            if (!$userArray) {
                $failStatus = authRateLimiterFail($config, $staffRateScope, $staffRateId, $clientIp);
                $error = authRateLimiterMessage($failStatus);
                $staffRateLimitSeconds = max(1, (int)($failStatus['seconds_left'] ?? 0));
                $staffRateLimitType = (string)($failStatus['type'] ?? '');
                $emailError = true;
            } else {
                // Check password - try password_verify if hash exists, otherwise direct comparison
                $storedPassword = $userArray['password'] ?? '';
                $passwordMatch = false;
                
                if (isset($userArray['password']) && password_verify($password, $storedPassword)) {
                    $passwordMatch = true;
                } elseif ($storedPassword === $password) {
                    // Plain text password (not recommended but might be existing data)
                    $passwordMatch = true;
                }
                
                if ($passwordMatch) {
                    $restriction = getAccountRestrictionMessage($userArray);
                    if (($restriction['message'] ?? '') !== '') {
                        $error = (string)$restriction['message'];
                        $suspendErrorSeconds = max($suspendErrorSeconds, (int)($restriction['remaining_seconds'] ?? 0));
                        $suspendErrorReason = trim((string)($userArray['suspend_reason'] ?? ''));
                        activityLog($config, 'login_blocked', [
                            'module' => 'auth',
                            'login_type' => 'manual_user',
                            'reason' => $error,
                            'target_email' => $email,
                        ], 'blocked');
                    } else {
                        authRateLimiterReset($config, $staffRateScope, $staffRateId, $clientIp);
                        $_SESSION['user_id'] = (string)($userArray['id'] ?? '');
                        $_SESSION['user_email'] = $email;
                        $_SESSION['user_name'] = $userArray['name'] ?? $email;
                        $_SESSION['user_username'] = $userArray['username'] ?? '';
                        $_SESSION['user_role'] = $userArray['role'] ?? 'user';
                        $_SESSION['user_photo'] = $userArray['photo'] ?? '';
                        $_SESSION['user_signature'] = $userArray['signature'] ?? '';
                        $role = $_SESSION['user_role'] ?? '';
                        if ($role === 'superadmin') {
                            header('Location: Super%20Admin%20Side/dashboard.php');
                        } elseif ($role === 'admin') {
                            header('Location: Admin%20Side/admin_dashboard.php');
                        } elseif (in_array($role, ['departmenthead', 'department_head', 'dept_head'])) {
                            header('Location: Department%20Heads%20Side/department_dashboard.php');
                        } else {
                            header('Location: Front%20Desk%20Side/staff_dashboard.php');
                        }
                        activityLog($config, 'login_success', [
                            'module' => 'auth',
                            'login_type' => 'manual_user',
                            'role' => $role,
                        ], 'success');
                        exit;
                    }
                } else {
                    $failStatus = authRateLimiterFail($config, $staffRateScope, $staffRateId, $clientIp);
                    $error = authRateLimiterMessage($failStatus);
                    $staffRateLimitSeconds = max(1, (int)($failStatus['seconds_left'] ?? 0));
                    $staffRateLimitType = (string)($failStatus['type'] ?? '');
                    $passwordError = true;
                }
            }
        } catch (Exception $e) {
            $error = 'Login error: ' . $e->getMessage();
        }
        }
    }
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Municipal Document Management System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
<!-- <link rel="stylesheet" href="styles.css"> -->
 <style>
    *{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Poppins',sans-serif;
}

body{
    background:#081b2e;
    color:#fff;
}

header{
    padding:18px 60px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    position:sticky; 
    top:0;
    z-index:1002;
    width:100%;
    background:rgba(8,27,46,0.85);
    backdrop-filter:blur(6px);
    box-shadow:0 2px 8px rgba(0,0,0,0.12);
}

.logo{
    display:flex;
    align-items:center;
    gap:12px;
}

.logo img{
    width:70px;      
    height:70px;   
    object-fit:contain;
    border-radius:8px;
}


.logo-text{
    display:flex;
    flex-direction:column;
    line-height:1.1;
}

.logo-text strong{
    font-size:18px;
}

.logo-text small{
    font-size:11px;
    color:#9ec6ef;
}

nav a{
    color:#cfe6ff;
    text-decoration:none;
    margin:0 15px;
    font-size:14px;
}

nav a:hover{
    color:#ffd400;
}

.nav-btn{
    background:#ffd400;
    color:#000;
    padding:10px 20px;
    border-radius:20px;
    font-weight:600;
    text-decoration:none;
}

.nav-btn:hover{
    color:#000;
    background:#fbbf24;
}


.hero{
    min-height:88vh;
    padding:60px;
    display:grid;
    grid-template-columns:1.1fr 0.9fr;
    gap:40px;
    align-items:center;
    background-image: linear-gradient(rgba(0,0,0,0.35), rgba(0,0,0,0.35)), url('img/solano.jpg');
    background-position: center;
    background-size: cover;
    background-repeat: no-repeat;
    color:#ffffff; 
    position:relative;
}

.hero-text .badge{
    display:inline-block;
    background:#2b3f66;
    padding:6px 14px;
    border-radius:20px;
    font-size:12px;
    color:#f4f8fc;
    margin-bottom:20px;
}

.hero-text h1{
    font-size:64px;
    line-height:1.05;
    margin-bottom:18px;
    font-weight:700;
    text-shadow: 0 6px 18px rgba(2,6,23,0.6);
}

.hero-text h1 span{
    color:#D4AF37;
}

.hero-text p{
    max-width:720px;
    color:#f6fbff; 
    margin-bottom:30px;
    font-size:18px;
    line-height:1.6;
    text-shadow: 0 2px 8px rgba(2,6,23,0.45);
}

.hero-actions{
    display:flex;
    gap:15px;
}

.btn-primary{
    background:#D4AF37;
    color:#0b2545;
    padding:14px 28px;
    border-radius:30px;
    font-weight:600;
    text-decoration:none;
}

.btn-secondary{
    border:1px solid #D4AF37;
    color:#D4AF37;
    padding:14px 28px;
    border-radius:30px;
    text-decoration:none;
}

.login-panels{
    display:flex;
    gap:20px;
    align-items:stretch;
    max-width:700px;
}

.login-panel{
    flex:1;
    border-radius:18px;
    padding:40px 36px;
    box-shadow:0 20px 40px rgba(0,0,0,0.3);
}

.login-panel-admin{
    background:rgba(255,255,255,0.95);
    color:#1e293b;
}

.login-panel-admin .field-group label,
.login-panel-admin .login-subtitle{
    color:#475569;
}

.login-panel-admin .field-group input{
    background:#f1f5f9;
    color:#0f172a;
    border:1px solid #e2e8f0;
}

.login-panel-admin .field-group input::placeholder{
    color:#94a3b8;
}

.login-panel-admin .login-link{
    color:#166534;
}

.login-panel-admin button{
    background:#166534;
    color:#fff;
}

.login-panel-staff{
    background:rgba(11,31,58,0.9);
    backdrop-filter:blur(15px);
    border:1px solid rgba(212,175,55,0.3);
}

.login-panel-staff .field-group label,
.login-panel-staff .login-subtitle{
    color:#b8d4ee;
}

.login-panel-staff .field-group input{
    background:#081b2e;
    color:#fff;
}

.login-panel-staff .login-link{
    color:#ffd400;
}

.login-panel-staff button{
    background:#ffd400;
    color:#0b2545;
}

.login-panel h3{
    margin-bottom:8px;
    font-size:20px;
}

.login-subtitle{
    font-size:13px;
    margin-bottom:24px;
}

.login-panel .field-group{
    margin-bottom:18px;
}

.login-panel .field-group label{
    font-size:14px;
    display:block;
    margin-bottom:8px;
}

.login-panel .field-group input{
    width:100%;
    padding:12px 14px;
    margin-bottom:0;
    border-radius:10px;
    border:none;
    font-size:15px;
}

.login-panel .field-error-slot{
    min-height:22px;
    margin-top:6px;
}

.login-panel .field-error-slot .field-error{
    font-size:13px;
    color:#ef4444;
}

.login-panel input.input-error{
    border:2px solid #ef4444;
}

.login-panel .login-link{
    display:block;
    font-size:13px;
    margin-bottom:20px;
    text-decoration:none;
}

.login-panel .login-link:hover{
    text-decoration:underline;
}

.login-panel button{
    width:100%;
    padding:14px;
    border:none;
    border-radius:12px;
    font-weight:600;
    cursor:pointer;
    font-size:15px;
}

.hero-login-wrap{
    display:flex;
    justify-content:flex-end;
    align-items:center;
}
.hero-login-wrap .login-card{
    margin:0;
    margin-left:auto;
}

.login-card{
    position:relative;
    background:rgba(255,255,255,0.06);
    backdrop-filter:blur(15px);
    border-radius:18px;
    padding:48px;
    width:100%;
    max-width:600px;
    margin:auto;
    box-shadow:0 20px 40px rgba(0,0,0,0.4);
}

.login-card h3{
    margin-bottom:24px;
    text-align:center;
    font-size:22px;
}

.login-card .field-group{
    margin-bottom:20px;
}

.login-card .field-group label{
    font-size:14px;
    color:#b8d4ee;
    display:block;
    margin-bottom:8px;
}

.login-card .field-group input{
    width:100%;
    padding:14px;
    margin-bottom:0;
    border-radius:10px;
    border:none;
    background:#081b2e;
    color:#fff;
    font-size:15px;
}

.login-card .password-wrap{
    position:relative;
    display:block;
}

.login-card .password-wrap input{
    padding-right:48px;
}

.login-card .password-toggle{
    position:absolute;
    top:50%;
    right:10px;
    transform:translateY(-50%);
    width:36px;
    height:36px;
    padding:0;
    border:none;
    background:transparent;
    color:#9ec6ef;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:8px;
    transition:color 0.2s, background 0.2s;
}

.login-card .password-toggle:hover{
    color:#b8d4ee;
    background:rgba(255,255,255,0.06);
}

.login-card .password-toggle svg{
    flex-shrink:0;
}

.login-card .field-error-slot{
    min-height:22px;
    margin-top:6px;
}

.login-card input.input-error{
    border:2px solid #ef4444;
}

.login-card input.input-error::placeholder{
    color:#fecaca;
}

.login-card .field-error-slot .field-error{
    font-size:13px;
    color:#ef4444;
}

.login-card .btn-signin{
    width:100%;
    padding:14px;
    margin-bottom:0;
    background:#ffd400;
    color:#000;
    border:none;
    border-radius:12px;
    font-weight:600;
    cursor:pointer;
    font-size:15px;
}
.login-card .btn-signin:hover{
    background:#fbbf24;
}

.login-card .hint{
    text-align:center;
    font-size:13px;
    margin-top:20px;
    color:#9ec6ef;
}

.login-card .login-divider{
    display:flex;
    justify-content:center;
    align-items:center;
    width:100%;
    font-size:13px;
    margin:14px 0 14px;
    color:#9ec6ef;
    text-align:center;
}

.login-card .btn-google{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    width:100%;
    padding:12px 14px;
    margin-top:12px;
    background:#fff;
    color:#3c4043;
    border:1px solid #dadce0;
    border-radius:12px;
    font-family:inherit;
    font-size:15px;
    font-weight:500;
    cursor:pointer;
    transition:background 0.2s, box-shadow 0.2s;
}
.btn-google:hover{
    background:#f8f9fa;
    box-shadow:0 1px 3px rgba(0,0,0,0.1);
}
.btn-google .google-icon{
    flex-shrink:0;
}

.field-row{
    display:flex;
    align-items:flex-start;
    gap:12px;
}

.field-main{
    flex:1;
}

.field-error{
    font-size:12px;
    color:#fca5a5;
    white-space:nowrap;
    padding-top:32px;
}

.features{
    background:#0b1f3a;
    padding:120px 60px;
    text-align:center;
}

.features h2{
    color:#D4AF37;
    font-size:32px;
    margin-bottom:70px;
}

.feature-grid{
    max-width:1100px;
    margin:auto;
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
    gap:30px;
}

.feature{
    background:#1a2b4d;
    padding:30px;
    border-radius:18px;
    border:1px solid #2b3f66;
    transition:0.3s;
}

.feature:hover{
    transform:translateY(-5px);
    box-shadow:0 10px 20px rgba(0,0,0,0.2);
}

.feature h3{
    margin-bottom:12px;
    color:#D4AF37;
}

.feature p{
    font-size:14px;
    color:#cfd9eb;
}

footer{
    padding:20px;
    text-align:center;
    background:#081625;
    font-size:13px;
    color:#8fb6dd;
}

.modal{
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    z-index:1000;
    display:flex;
    align-items:center;
    justify-content:center;
}

.modal-overlay{
    position:absolute;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.5);
    backdrop-filter:blur(2px);
}

.modal-content{
    position:relative;
    z-index:1001;
    width:100%;
    max-width:680px;
    margin:0 16px;
}

.modal-close{
    position:absolute;
    top:16px;
    right:16px;
    background:rgba(255,255,255,0.1);
    border:none;
    color:#b8d4ee;
    font-size:24px;
    cursor:pointer;
    line-height:1;
    padding:0;
    width:36px;
    height:36px;
    border-radius:10px;
    display:flex;
    align-items:center;
    justify-content:center;
    transition:background 0.2s, color 0.2s;
}

.modal-close:hover{
    background:rgba(255,255,255,0.15);
    color:#fff;
}

@media(max-width:900px){
    .hero{
        grid-template-columns:1fr;
        text-align:center;
    }
    .hero-actions{
        justify-content:center;
    }
    .login-panels{
        flex-direction:column;
        max-width:400px;
        margin:0 auto;
    }
    .hero-login-wrap{
        justify-content:center;
    }
    .hero-login-wrap .login-card{
        margin-left:0;
    }
    .hero-text h1{ font-size:36px; }
    .hero-text p{ font-size:15px; max-width:100%; }
}

 </style>
</head>
<body>

<header>
    <div class="logo">
        <img src="img/logo.png" alt="Municipal Logo">
        <div class="logo-text">
            <strong>Municipality of Solano</strong>
            <small>Municipal Document Management System</small>
        </div>
    </div>

    <nav>
        <a href="#">Features</a>
        <a href="#">Departments</a>
        <a href="#">About</a>
    </nav>
</header>

<section class="hero">

    <div class="hero-text">
        <!-- <div class="badge">Municipal Government Digital Solution</div> -->

        <h1>Solano Document <span>Management System</span></h1>

        <p>
            A centralized and secure digital platform developed for the Municipality of Solano, Nueva Vizcaya to efficiently manage, 
            archive, monitor, and retrieve official documents. The system is designed to enhance transparency, minimize paperwork, 
            streamline records management, and strengthen coordination among municipal offices and departments.
        </p>

    </div>

    <?php if (!$isLoggedIn): ?>
    <!-- Login fixed to the right -->
    <div class="hero-login-wrap">
            <div class="login-card">
                <h3>Login</h3>
                <?php if ($error): ?>
                    <div class="field-error-slot" style="margin-bottom: 1rem;">
                        <span
                            class="field-error"
                            <?php if ($staffRateLimitSeconds > 0): ?>
                                data-rate-limit-seconds="<?= (int)$staffRateLimitSeconds ?>"
                                data-rate-limit-type="<?= htmlspecialchars($staffRateLimitType) ?>"
                            <?php endif; ?>
                            <?php if ($suspendErrorSeconds > 0): ?>
                                data-suspend-seconds="<?= (int)$suspendErrorSeconds ?>"
                                data-suspend-reason="<?= htmlspecialchars($suspendErrorReason) ?>"
                            <?php endif; ?>
                        ><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>
                <form method="post">
                    <div class="field-group">
                        <label>Email</label>
                        <input type="email" name="email" placeholder="Enter your email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" class="<?= $emailError ? 'input-error' : '' ?>" required>
                        <div class="field-error-slot">
                            <?php if ($emailError): ?><span class="field-error">Invalid email or account not found.</span><?php endif; ?>
                        </div>
                    </div>

                    <div class="field-group">
                        <label>Password</label>
                        <div class="password-wrap">
                            <input type="password" name="password" id="login-password" placeholder="Enter your password" class="<?= $passwordError ? 'input-error' : '' ?>" required>
                            <button type="button" class="password-toggle" onclick="togglePassword(this)" aria-label="Show password" title="Show password">
                                <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="icon-eye-off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                        <div class="field-error-slot">
                            <?php if ($passwordError): ?><span class="field-error">Wrong password.</span><?php endif; ?>
                        </div>
                    </div>

                    <button type="submit" class="btn-signin">Sign In</button>

                    <div class="login-divider">Or</div>

                    <button type="button" class="btn-google" title="Sign in with Google" data-google-login-url="Auth/auth-google.php">
                        <svg class="google-icon" viewBox="0 0 24 24" width="20" height="20">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                        </svg>
                        Sign In with Google
                    </button>
                </form>
                <?php if ($success): ?>
                    <div style="background: #dcfce7; color: #166534; padding: 0.75rem; border-radius: 6px; margin-top: 1rem; font-size: 0.9rem;">
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                <div class="hint">Authorized personnel access only</div>
            </div>
    </div>
    <?php else: ?>
    <div class="hero-login-wrap">
        <div class="login-card" style="background: #dcfce7; padding: 2rem; text-align: center;">
            <h3 style="color: #166534; margin-bottom: 1rem;">Welcome, <?= htmlspecialchars($_SESSION['user_name']) ?>!</h3>
            <p style="color: #166534; margin-bottom: 1.5rem;">You are successfully logged in.</p>
            <a href="?logout=1" style="display: inline-block; padding: 0.75rem 1.5rem; background: #2563eb; color: white; text-decoration: none; border-radius: 6px;">Logout</a>
        </div>
    </div>
    <?php endif; ?>

</section>

<section class="features">
    <h2>System Features</h2>

    <div class="feature-grid">
        <div class="feature">
            <h3>Centralized Document Repository</h3>
            <p>Store and manage municipal records in one secure digital archive.</p>
        </div>

        <div class="feature">
            <h3>Department-Based Access Control</h3>
            <p>Ensure data privacy with role-based permissions for different offices.</p>
        </div>

        <div class="feature">
            <h3>Document Tracking & Monitoring</h3>
            <p>Track incoming, outgoing, and archived documents with real-time status updates.</p>
        </div>

        <div class="feature">
            <h3>Secure Digital Archiving</h3>
            <p>Protect important municipal records with encryption and secure backups.</p>
        </div>

        <div class="feature">
            <h3>Advanced Search & Retrieval</h3>
            <p>Quickly locate documents using filters, categories, and reference numbers.</p>
        </div>

        <div class="feature">
            <h3>Audit Logs & Transparency</h3>
            <p>Maintain accountability with detailed activity logs and document history tracking.</p>
        </div>
    </div>
</section>

<footer>
    © <?php echo date("Y"); ?> Municipal Government Document Management System. All rights reserved.
</footer>

<script>
document.querySelectorAll('.btn-google').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var url = this.getAttribute('data-google-login-url') || 'Auth/auth-google.php';
        if (url) window.location.href = url;
    });
});

function togglePassword(btn) {
    var wrap = btn.closest('.password-wrap');
    var input = wrap && wrap.querySelector('input');
    var eye = wrap && wrap.querySelector('.icon-eye');
    var eyeOff = wrap && wrap.querySelector('.icon-eye-off');
    if (!input || !eye || !eyeOff) return;
    if (input.type === 'password') {
        input.type = 'text';
        eye.style.display = 'none';
        eyeOff.style.display = 'block';
        btn.setAttribute('aria-label', 'Hide password');
        btn.setAttribute('title', 'Hide password');
    } else {
        input.type = 'password';
        eye.style.display = 'block';
        eyeOff.style.display = 'none';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('title', 'Show password');
    }
}

// Show the password-eye toggle only when the user types something into the password field.
(function() {
    var modalForm = document.querySelector('.hero-login-wrap form');
    if (!modalForm) return;
    var passwordInput = modalForm.querySelector('input[name="password"]');
    if (!passwordInput) return;
    var wrap = passwordInput.closest('.password-wrap');
    if (!wrap) return;
    var toggleBtn = wrap.querySelector('.password-toggle');
    var eye = wrap.querySelector('.icon-eye');
    var eyeOff = wrap.querySelector('.icon-eye-off');

    function updateToggleVisibility() {
        if (passwordInput.value && passwordInput.value.length > 0) {
            // show toggle button
            if (toggleBtn) toggleBtn.style.display = '';
        } else {
            // hide toggle button and reset to password mode
            if (toggleBtn) toggleBtn.style.display = 'none';
            passwordInput.type = 'password';
            if (eye) eye.style.display = 'block';
            if (eyeOff) eyeOff.style.display = 'none';
            if (toggleBtn) {
                toggleBtn.setAttribute('aria-label', 'Show password');
                toggleBtn.setAttribute('title', 'Show password');
            }
        }
    }

    // update on input (typing/paste/backspace)
    passwordInput.addEventListener('input', updateToggleVisibility);

    // initialize visibility on load
    updateToggleVisibility();
})();

(function() {
    function setLoginControlsDisabled(errorEl, disabled) {
        if (!errorEl) return;
        var form = errorEl.closest('.login-card') && errorEl.closest('.login-card').querySelector('form');
        if (!form) return;
        var controls = form.querySelectorAll('input[name="email"], input[name="username"], input[name="password"], button[type="submit"]');
        controls.forEach(function(ctrl) {
            ctrl.disabled = !!disabled;
        });
    }

    function formatLongCountdown(secondsLeft) {
        var minutes = Math.floor(secondsLeft / 60);
        var seconds = secondsLeft % 60;
        return minutes + ' minute(s) ' + seconds + ' second(s)';
    }

    function applyCountdownMessage(el, secondsLeft, type) {
        if (!el) return;
        if (secondsLeft <= 0) {
            el.textContent = 'You can try signing in again now.';
            return;
        }
        if (type === 'long') {
            el.textContent = 'Too many failed attempts. Please wait ' + formatLongCountdown(secondsLeft) + ' before trying again.';
        } else {
            el.textContent = 'Incorrect credentials. Please wait ' + secondsLeft + ' second(s) before trying again.';
        }
    }

    var nodes = document.querySelectorAll('[data-rate-limit-seconds]');
    if (!nodes.length) return;

    nodes.forEach(function(el) {
        var left = parseInt(el.getAttribute('data-rate-limit-seconds') || '0', 10);
        var type = (el.getAttribute('data-rate-limit-type') || 'short').toLowerCase();
        if (!left || left <= 0) return;
        setLoginControlsDisabled(el, true);
        applyCountdownMessage(el, left, type);
        var timer = setInterval(function() {
            left -= 1;
            if (left <= 0) {
                clearInterval(timer);
                setLoginControlsDisabled(el, false);
                applyCountdownMessage(el, 0, type);
                return;
            }
            applyCountdownMessage(el, left, type);
        }, 1000);
    });
})();

(function() {
    function formatCountdown(secondsLeft) {
        var hrs = Math.floor(secondsLeft / 3600);
        var mins = Math.floor((secondsLeft % 3600) / 60);
        var secs = secondsLeft % 60;
        var pad = function(n) { return String(n).padStart(2, '0'); };
        return pad(hrs) + ':' + pad(mins) + ':' + pad(secs);
    }

    function applySuspendMessage(el, secondsLeft, reason) {
        if (!el) return;
        if (secondsLeft <= 0) {
            el.textContent = 'Suspension ended. You can sign in now.';
            return;
        }
        var msg = 'Your account is suspended for ' + formatCountdown(secondsLeft) + ' (HH:MM:SS).';
        if (reason) {
            msg += ' Reason: ' + reason;
        }
        el.textContent = msg;
    }

    var nodes = document.querySelectorAll('[data-suspend-seconds]');
    if (!nodes.length) return;

    nodes.forEach(function(el) {
        var left = parseInt(el.getAttribute('data-suspend-seconds') || '0', 10);
        var reason = (el.getAttribute('data-suspend-reason') || '').trim();
        if (!left || left <= 0) return;
        applySuspendMessage(el, left, reason);
        var timer = setInterval(function() {
            left -= 1;
            if (left <= 0) {
                clearInterval(timer);
                applySuspendMessage(el, 0, reason);
                return;
            }
            applySuspendMessage(el, left, reason);
        }, 1000);
    });
})();

<?php if ($error): ?>
// Scroll login card into view when there is an error (form is already visible on the right)
document.addEventListener('DOMContentLoaded', function() {
    var wrap = document.querySelector('.hero-login-wrap');
    if (wrap) wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
});
<?php endif; ?>
</script>

</body>
</html>
    