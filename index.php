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
            $adminRateLimitSeconds = max(0, (int)($rateStatus['seconds_left'] ?? 0));
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
                $adminRateLimitSeconds = max(0, (int)($failStatus['seconds_left'] ?? 0));
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
                        $_SESSION['user_id'] = (string)($user['user_id'] ?? ($user['id'] ?? ''));
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
                    $adminRateLimitSeconds = max(0, (int)($failStatus['seconds_left'] ?? 0));
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
            $staffRateLimitSeconds = max(0, (int)($rateStatus['seconds_left'] ?? 0));
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
                $staffRateLimitSeconds = max(0, (int)($failStatus['seconds_left'] ?? 0));
                $staffRateLimitType = (string)($failStatus['type'] ?? '');
                $emailError = true;
            } else {
                $storedPassword = $userArray['password'] ?? '';
                $passwordMatch = false;
                
                if (isset($userArray['password']) && password_verify($password, $storedPassword)) {
                    $passwordMatch = true;
                } elseif ($storedPassword === $password) {
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
                        $_SESSION['user_id'] = (string)($userArray['user_id'] ?? ($userArray['id'] ?? ''));
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
                    $staffRateLimitSeconds = max(0, (int)($failStatus['seconds_left'] ?? 0));
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

$isLoggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Municipal Document Management System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --gold: #f2c94c;
  --gold-light: #f6d977;
  --gold-dim: rgba(47,126,216,0.16);
  --navy: #dbeeff;
  --navy-mid: #c7e2fb;
  --navy-card: rgba(255,255,255,0.78);
  --white: #123c68;
  --muted: rgba(18,60,104,0.65);
  --danger: #e05252;
  --success-bg: #0f3325;
  --success-text: #5ee89a;
  --radius: 16px;
  --radius-sm: 10px;
  --transition: 0.25s cubic-bezier(0.4,0,0.2,1);
}

*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

html { scroll-behavior: smooth; }

body {
  font-family: 'DM Sans', sans-serif;
  background: var(--navy);
  color: var(--white);
  min-height: 100vh;
  overflow-x: hidden;
}

/* ── ANIMATED BACKGROUND ── */
.bg-scene {
  position: fixed;
  inset: 0;
  z-index: 0;
  pointer-events: none;
  overflow: hidden;
}

.bg-scene::before {
  content: '';
  position: absolute;
  inset: 0;
  background: url('img/solano.jpg') center/cover no-repeat;
  filter: blur(4px);
  transform: none;
}

.bg-scene::after {
  content: '';
  position: absolute;
  inset: 0;
  background: rgba(191,222,251,0.58);
}

.bg-grid {
  position: absolute;
  inset: 0;
  background-image: none;
  background-size: 48px 48px;
  mask-image: none;
}

.orb {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  animation: orbFloat 14s ease-in-out infinite alternate;
}
.orb-1 { width: 600px; height: 600px; background: rgba(85,149,216,0.2); top: -200px; right: -100px; animation-delay: 0s; }
.orb-2 { width: 400px; height: 400px; background: rgba(126,180,236,0.24); bottom: -100px; left: -100px; animation-delay: -5s; }
.orb-3 { width: 300px; height: 300px; background: rgba(242,201,76,0.16); top: 40%; left: 20%; animation-delay: -9s; }

@keyframes orbFloat {
  from { transform: translate(0,0) scale(1); }
  to   { transform: translate(40px,60px) scale(1.12); }
}

/* ── LAYOUT ── */
.page-wrap {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-rows: auto 1fr auto;
  min-height: 100vh;
}

/* ── HEADER ── */
header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 48px;
  background: rgba(232,244,255,0.75);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid rgba(84,143,202,0.25);
  position: sticky;
  top: 0;
  z-index: 100;
  animation: slideDown 0.6s cubic-bezier(0.22,1,0.36,1) both;
}

@keyframes slideDown {
  from { opacity:0; transform:translateY(-20px); }
  to   { opacity:1; transform:translateY(0); }
}

.logo {
  display: flex;
  align-items: center;
  gap: 14px;
}

.logo img {
  width: 52px;
  height: 52px;
  object-fit: contain;
  border-radius: 10px;
  filter: drop-shadow(0 2px 8px rgba(84,143,202,0.3));
}

.logo-text strong {
  display: block;
  font-family: 'DM Serif Display', serif;
  font-size: 17px;
  color: var(--white);
  letter-spacing: 0.01em;
}

.logo-text small {
  font-size: 11px;
  color: var(--muted);
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.header-badge {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  background: var(--gold-dim);
  border: 1px solid rgba(84,143,202,0.28);
  border-radius: 20px;
  font-size: 12px;
  color: #d62828;
  letter-spacing: 0.04em;
}

.header-badge::before {
  content: '';
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #d62828;
  box-shadow: 0 0 8px rgba(214,40,40,0.5);
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0%,100% { opacity:1; transform:scale(1); }
  50% { opacity:0.6; transform:scale(0.85); }
}

/* ── MAIN ── */
main {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 480px;
  gap: 24px;
  align-items: center;
  padding: 32px 48px;
  max-width: 1240px;
  width: 100%;
  margin: 0 auto;
  min-height: calc(100vh - 80px - 56px);
}

/* ── HERO TEXT ── */
.hero-text {
  padding-right: 20px;
  max-width: 640px;
  animation: fadeInLeft 0.8s cubic-bezier(0.22,1,0.36,1) 0.2s both;
}

@keyframes fadeInLeft {
  from { opacity:0; transform:translateX(-30px); }
  to   { opacity:1; transform:translateX(0); }
}

.eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  font-size: 11px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: var(--gold);
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.35);
  margin-bottom: 28px;
}

.eyebrow-line {
  display: block;
  width: 32px;
  height: 1px;
  background: var(--gold);
  opacity: 0.7;
}

.hero-title {
  font-family: 'DM Serif Display', serif;
  font-size: clamp(36px, 4.1vw, 56px);
  line-height: 1.07;
  letter-spacing: -0.01em;
  margin-bottom: 18px;
  color: var(--white);
}

.hero-title em {
  font-style: italic;
  color: #2f7ed8;
}

.hero-title .hero-solano {
  font-size: 1.8em;
}

.hero-title em .ano {
  color: var(--gold);
}

.hero-desc {
  font-size: 17px;
  line-height: 1.65;
  color: #000000;
  max-width: 600px;
  margin-bottom: 22px;
}

.hero-stats {
  display: flex;
  gap: 40px;
}

.stat {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.stat-number {
  font-family: 'DM Serif Display', serif;
  font-size: 28px;
  color: var(--gold-light);
  line-height: 1;
}

.stat-label {
  font-size: 11px;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.1em;
}

.stat-divider {
  width: 1px;
  background: rgba(201,168,76,0.2);
  align-self: stretch;
}

/* ── LOGIN CARD ── */
.login-wrap {
  animation: fadeInRight 0.8s cubic-bezier(0.22,1,0.36,1) 0.35s both;
}

@keyframes fadeInRight {
  from { opacity:0; transform:translateX(30px); }
  to   { opacity:1; transform:translateX(0); }
}

.login-card {
  background: var(--navy-card);
  border: 1px solid rgba(84,143,202,0.2);
  border-radius: 24px;
  padding: 44px 40px;
  backdrop-filter: blur(20px);
  box-shadow:
    0 0 0 1px rgba(255,255,255,0.2),
    0 24px 64px rgba(23,74,124,0.18),
    0 0 80px rgba(84,143,202,0.08);
  position: relative;
  overflow: hidden;
}

.login-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 1px;
  background: rgba(84,143,202,0.35);
}

.card-header {
  margin-bottom: 32px;
}

.card-title-row {
  display: flex;
  align-items: center;
  gap: 130px;
  margin-bottom: -10px;
}

.card-icon {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  flex-shrink: 0;
  margin-bottom: -32px;
}

.card-icon svg { color: #2f7ed8; }
.card-icon img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.card-header h2 {
  font-family: 'DM Serif Display', serif;
  font-size: 26px;
  color: var(--white);
  margin-bottom: 0;
}

.card-header p {
  font-size: 13px;
  color: var(--muted);
}

/* ── ERROR BANNER ── */
.error-banner {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  background: rgba(224,82,82,0.12);
  border: 1px solid rgba(224,82,82,0.3);
  border-radius: var(--radius-sm);
  padding: 12px 14px;
  margin-bottom: 22px;
  font-size: 13px;
  color: #fca5a5;
  line-height: 1.5;
}

.error-banner svg { flex-shrink: 0; margin-top: 1px; }

/* ── FIELD ── */
.field {
  margin-bottom: 18px;
}

.field label {
  display: block;
  font-size: 12px;
  font-weight: 500;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: 8px;
}

.input-wrap {
  position: relative;
}

.input-wrap svg.field-icon {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: rgba(47,126,216,0.45);
  pointer-events: none;
  transition: color var(--transition);
}

.input-wrap:focus-within svg.field-icon {
  color: #2f7ed8;
}

.input-wrap input {
  width: 100%;
  padding: 13px 14px 13px 42px;
  background: rgba(255,255,255,0.65);
  border: 1px solid rgba(84,143,202,0.25);
  border-radius: var(--radius-sm);
  color: var(--white);
  font-family: 'DM Sans', sans-serif;
  font-size: 14px;
  outline: none;
  transition: border-color var(--transition), background var(--transition), box-shadow var(--transition);
}

.input-wrap input::placeholder { color: rgba(18,60,104,0.4); }

.input-wrap input:focus {
  border-color: rgba(47,126,216,0.5);
  background: rgba(255,255,255,0.9);
  box-shadow: 0 0 0 3px rgba(47,126,216,0.12);
}

.input-wrap input.input-error {
  border-color: rgba(224,82,82,0.6);
  background: rgba(224,82,82,0.06);
}

.input-wrap input.input-error:focus {
  box-shadow: 0 0 0 3px rgba(224,82,82,0.12);
}

.field-error-slot { min-height: 20px; margin-top: 5px; }
.field-error { font-size: 12px; color: #fca5a5; }

/* ── PASSWORD TOGGLE ── */
.password-toggle {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  background: transparent;
  border: none;
  color: rgba(18,60,104,0.45);
  cursor: pointer;
  padding: 4px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  transition: color var(--transition);
}
.password-toggle:hover { color: var(--white); }

/* ── SIGN IN BUTTON ── */
.btn-signin {
  width: 100%;
  padding: 14px;
  background: #2f7ed8;
  color: #ffffff;
  font-family: 'DM Sans', sans-serif;
  font-size: 14px;
  font-weight: 600;
  letter-spacing: 0.04em;
  border: none;
  border-radius: var(--radius-sm);
  cursor: pointer;
  position: relative;
  overflow: hidden;
  transition: transform var(--transition), box-shadow var(--transition);
  box-shadow: 0 4px 20px rgba(47,126,216,0.28);
}

.btn-signin::after {
  content: '';
  position: absolute;
  inset: 0;
  background: rgba(255,255,255,0.12);
  opacity: 0;
  transition: opacity var(--transition);
}

.btn-signin:hover {
  transform: translateY(-1px);
  box-shadow: 0 8px 28px rgba(47,126,216,0.35);
}
.btn-signin:hover::after { opacity: 1; }
.btn-signin:active { transform: translateY(0); }

/* ── DIVIDER ── */
.divider {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 20px 0;
  color: rgba(245,240,232,0.25);
  font-size: 12px;
  letter-spacing: 0.05em;
}
.divider::before, .divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: rgba(255,255,255,0.08);
}

/* ── GOOGLE BUTTON ── */
.btn-google {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  width: 100%;
  padding: 13px;
  background: rgba(255,255,255,0.58);
  border: 1px solid rgba(84,143,202,0.2);
  border-radius: var(--radius-sm);
  color: var(--white);
  font-family: 'DM Sans', sans-serif;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition: background var(--transition), border-color var(--transition), transform var(--transition);
}
.btn-google:hover {
  background: rgba(255,255,255,0.82);
  border-color: rgba(84,143,202,0.32);
  transform: translateY(-1px);
}

.card-footer {
  margin-top: 24px;
  text-align: center;
  font-size: 12px;
  color: rgba(18,60,104,0.78);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.card-footer svg { color: rgba(47,126,216,0.72); }

/* ── LOGGED IN STATE ── */
.logged-in-card {
  background: rgba(15,51,37,0.5);
  border: 1px solid rgba(94,232,154,0.2);
  border-radius: 24px;
  padding: 44px 40px;
  backdrop-filter: blur(20px);
  text-align: center;
}

.logged-in-card .welcome-icon {
  width: 64px;
  height: 64px;
  background: rgba(94,232,154,0.1);
  border: 1px solid rgba(94,232,154,0.25);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 20px;
  color: var(--success-text);
}

.logged-in-card h3 {
  font-family: 'DM Serif Display', serif;
  font-size: 22px;
  color: var(--white);
  margin-bottom: 8px;
}

.logged-in-card p { font-size: 14px; color: var(--muted); margin-bottom: 28px; }

.btn-logout {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 12px 28px;
  background: rgba(255,255,255,0.58);
  border: 1px solid rgba(84,143,202,0.24);
  border-radius: 10px;
  color: var(--white);
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  transition: background var(--transition), border-color var(--transition);
}
.btn-logout:hover { background: rgba(255,255,255,0.8); border-color: rgba(84,143,202,0.35); }

/* ── FOOTER ── */
footer {
  padding: 20px 48px;
  border-top: 1px solid rgba(84,143,202,0.2);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: #000000;
  letter-spacing: 0.03em;
  animation: fadeUp 0.8s cubic-bezier(0.22,1,0.36,1) 0.6s both;
}

@keyframes fadeUp {
  from { opacity:0; transform:translateY(12px); }
  to   { opacity:1; transform:translateY(0); }
}

/* ── RESPONSIVE ── */
@media (max-width: 960px) {
  main {
    grid-template-columns: 1fr;
    padding: 40px 24px;
    gap: 48px;
    align-items: start;
  }
  .hero-text {
    padding-right: 0;
    text-align: center;
    animation-name: fadeUp;
  }
  .eyebrow, .hero-stats { justify-content: center; }
  .hero-desc { margin-left: auto; margin-right: auto; }
  .login-wrap { animation-name: fadeUp; }
  header { padding: 16px 24px; }
  .header-badge { display: none; }
  footer { padding: 16px 24px; }
}

@media (min-width: 961px) {
  body {
    overflow: hidden;
  }
  .page-wrap {
    height: 100vh;
  }
  header {
    padding: 14px 40px;
  }
  main {
    min-height: 0;
    padding-top: 20px;
    padding-bottom: 20px;
  }
  footer {
    padding: 12px 40px;
  }
}
</style>
</head>
<body>

<div class="bg-scene">
  <div class="bg-grid"></div>
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <div class="orb orb-3"></div>
</div>

<div class="page-wrap">

  <!-- HEADER -->
  <header>
    <div class="logo">
      <img src="img/logo.png" alt="Municipal Logo">
      <div class="logo-text">
        <strong>Municipality of Solano</strong>
        <small>Document Management System</small>
      </div>
    </div>
    <div class="header-badge">Authorized Personnel Only</div>
  </header>

  <!-- MAIN -->
  <main>

    <!-- LEFT: Hero Text -->
    <div class="hero-text">


      <h1 class="hero-title">
        <em class="hero-solano">Sol<span class="ano">ano</span></em><br>
        Document<br>
        Management<br>
        System<br>
      </h1>

      <p class="hero-desc">
      A secure and shared digital system for the 
      Municipality of Solano that helps manage, store, 
      track, and find official documents easily. 
      Improves transparency and coordination among all municipal offices.
      </p>

    </div>

    <!-- RIGHT: Login / Logged In -->
    <div class="login-wrap">

      <?php if (!$isLoggedIn): ?>
      <div class="login-card">

        <div class="card-header">
          <div class="card-title-row">
            <h2>Welcome back</h2>
            <div class="card-icon">
            <img src="img/image.png" alt="OGT1 Logo">
            </div>
          </div>
          <p>Sign in to your account to continue</p>
        </div>

        <?php if ($error): ?>
        <div class="error-banner">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span
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
          <!-- Email -->
          <div class="field">
            <label>Email address</label>
            <div class="input-wrap">
              <svg class="field-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
              <input type="email" name="email" placeholder="you@solano.gov.ph"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                class="<?= $emailError ? 'input-error' : '' ?>" required>
            </div>
            <div class="field-error-slot">
              <?php if ($emailError): ?><span class="field-error">Invalid email or account not found.</span><?php endif; ?>
            </div>
          </div>

          <!-- Password -->
          <div class="field">
            <label>Password</label>
            <div class="input-wrap" style="position:relative;">
              <svg class="field-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <input type="password" name="password" id="login-password" placeholder="Enter your password"
                class="<?= $passwordError ? 'input-error' : '' ?>" style="padding-right:44px;" required>
              <button type="button" class="password-toggle" onclick="togglePassword(this)" aria-label="Show password" title="Show password">
                <svg class="icon-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="icon-eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
            <div class="field-error-slot">
              <?php if ($passwordError): ?><span class="field-error">Wrong password.</span><?php endif; ?>
            </div>
          </div>

          <button type="submit" class="btn-signin">Sign In</button>

          <div class="divider">or continue with</div>

          <button type="button" class="btn-google" data-google-login-url="Auth/auth-google.php">
            <svg viewBox="0 0 24 24" width="18" height="18">
              <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
              <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
              <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
              <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Sign in with Google
          </button>
        </form>

        <?php if ($success): ?>
        <div style="background:rgba(94,232,154,0.1);border:1px solid rgba(94,232,154,0.25);border-radius:10px;padding:12px 14px;margin-top:16px;font-size:13px;color:#5ee89a;">
          <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <div class="card-footer">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Authorized personnel access only
        </div>
      </div>

      <?php else: ?>
      <div class="logged-in-card">
        <div class="welcome-icon">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <h3>Welcome back!</h3>
        <p>Signed in as <strong style="color:var(--white);"><?= htmlspecialchars($_SESSION['user_name']) ?></strong></p>
        <a href="?logout=1" class="btn-logout">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Sign out
        </a>
      </div>
      <?php endif; ?>

    </div>
  </main>

  <!-- FOOTER -->
  <footer>
    © <?php echo date("Y"); ?> Municipal Government of Solano · Document Management System · All rights reserved.
  </footer>

</div><!-- end .page-wrap -->

<script>
/* ── Google button ── */
document.querySelectorAll('.btn-google').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var url = this.getAttribute('data-google-login-url') || 'Auth/auth-google.php';
    if (url) window.location.href = url;
  });
});

/* ── Password toggle ── */
function togglePassword(btn) {
  var wrap = btn.closest('.input-wrap');
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

/* ── Show toggle only when field has value ── */
(function() {
  var passwordInput = document.getElementById('login-password');
  if (!passwordInput) return;
  var wrap = passwordInput.closest('.input-wrap');
  if (!wrap) return;
  var toggleBtn = wrap.querySelector('.password-toggle');
  var eye = wrap.querySelector('.icon-eye');
  var eyeOff = wrap.querySelector('.icon-eye-off');
  function updateVisibility() {
    if (passwordInput.value.length > 0) {
      if (toggleBtn) toggleBtn.style.display = '';
    } else {
      if (toggleBtn) toggleBtn.style.display = 'none';
      passwordInput.type = 'password';
      if (eye) eye.style.display = 'block';
      if (eyeOff) eyeOff.style.display = 'none';
      if (toggleBtn) { toggleBtn.setAttribute('aria-label','Show password'); toggleBtn.setAttribute('title','Show password'); }
    }
  }
  passwordInput.addEventListener('input', updateVisibility);
  updateVisibility();
})();

/* ── Rate-limit countdown ── */
(function() {
  function setLoginControlsDisabled(errorEl, disabled) {
    if (!errorEl) return;
    var card = errorEl.closest('.login-card');
    if (!card) return;
    var form = card.querySelector('form');
    if (!form) return;
    form.querySelectorAll('input[name="email"], input[name="password"], button[type="submit"]').forEach(function(c) {
      c.disabled = !!disabled;
    });
  }
  function applyCountdownMessage(el, secondsLeft, type) {
    if (!el) return;
    if (secondsLeft <= 0) { el.textContent = 'You can try signing in again now.'; return; }
    var minutes = Math.floor(secondsLeft / 60);
    var secs = secondsLeft % 60;
    if (type === 'long') {
      el.textContent = 'Too many failed attempts. Please wait ' + minutes + 'm ' + secs + 's before trying again.';
    } else {
      el.textContent = 'Incorrect credentials. Please wait ' + secondsLeft + 's before trying again.';
    }
  }
  document.querySelectorAll('[data-rate-limit-seconds]').forEach(function(el) {
    var left = parseInt(el.getAttribute('data-rate-limit-seconds') || '0', 10);
    var type = (el.getAttribute('data-rate-limit-type') || 'short').toLowerCase();
    if (!left || left <= 0) return;
    setLoginControlsDisabled(el, true);
    applyCountdownMessage(el, left, type);
    var timer = setInterval(function() {
      left -= 1;
      if (left <= 0) { clearInterval(timer); setLoginControlsDisabled(el, false); applyCountdownMessage(el, 0, type); return; }
      applyCountdownMessage(el, left, type);
    }, 1000);
  });
})();

/* ── Suspension countdown ── */
(function() {
  function pad(n) { return String(n).padStart(2,'0'); }
  function fmt(s) { return pad(Math.floor(s/3600))+':'+pad(Math.floor((s%3600)/60))+':'+pad(s%60); }
  function msg(el, s, reason) {
    if (!el) return;
    if (s <= 0) { el.textContent = 'Suspension ended. You can sign in now.'; return; }
    var m = 'Your account is suspended for ' + fmt(s) + ' (HH:MM:SS).';
    if (reason) m += ' Reason: ' + reason;
    el.textContent = m;
  }
  document.querySelectorAll('[data-suspend-seconds]').forEach(function(el) {
    var left = parseInt(el.getAttribute('data-suspend-seconds') || '0', 10);
    var reason = (el.getAttribute('data-suspend-reason') || '').trim();
    if (!left || left <= 0) return;
    msg(el, left, reason);
    var timer = setInterval(function() {
      left -= 1;
      if (left <= 0) { clearInterval(timer); msg(el, 0, reason); return; }
      msg(el, left, reason);
    }, 1000);
  });
})();

<?php if ($error): ?>
document.addEventListener('DOMContentLoaded', function() {
  var wrap = document.querySelector('.login-wrap');
  if (wrap) wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
});
<?php endif; ?>
</script>
</body>
</html>