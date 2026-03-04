<?php
session_start();
// Get email that tried to log in (set by auth-google.php before redirect)
$unauthorizedEmail = $_SESSION['unauthorized_email'] ?? '';
if (isset($_GET['google']) && $_GET['google'] === '1') {
    unset($_SESSION['unauthorized_email']);
    session_destroy();
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>No Access - Solano Document Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body {
            background: #081b2e;
            color: #fff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            text-align: center;
        }
        .no-access-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(15px);
            border-radius: 18px;
            padding: 3rem;
            max-width: 480px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
            border: 1px solid rgba(212,175,55,0.2);
        }
        .no-access-card .icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 1.5rem;
            background: rgba(239,68,68,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #f87171;
        }
        .no-access-card h1 {
            font-size: 1.5rem;
            margin-bottom: 0.75rem;
            color: #fef2f2;
        }
        .no-access-card p {
            color: #b8d4ee;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        .no-access-card a {
            display: inline-block;
            background: #ffd400;
            color: #0b2545;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s;
        }
        .no-access-card a:hover {
            background: #fbbf24;
        }
    </style>
</head>
<body>
    <div class="no-access-card">
        <div class="icon" aria-hidden="true">&#10007;</div>
        <h1>No Access to System</h1>
        <p>
            <?php if ($unauthorizedEmail !== ''): ?>
                <strong><?= htmlspecialchars($unauthorizedEmail) ?></strong> doesn't have access to the system.
            <?php else: ?>
                Your account is not authorized to use this system.
            <?php endif; ?>
            If you believe you should have access, please contact your administrator.
        </p>
        <a href="../index.php">Back to Login</a>
    </div>
</body>
</html>
