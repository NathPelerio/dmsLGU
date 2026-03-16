<?php
session_start();
$isLoggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Features - Municipal Document Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;margin:0;padding:0;font-family:'Poppins',sans-serif}
        body{background:#081b2e;color:#fff}
        .top-bar{height:8px;background:#D4AF37;width:100%}
        header{padding:18px 60px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:1002;background:rgba(8,27,46,0.95);backdrop-filter:blur(6px);}
        .logo{display:flex;align-items:center;gap:12px}
        .logo img{width:60px;height:60px;object-fit:contain;border-radius:8px}
        .logo-text strong{font-size:18px}
        .logo-text small{font-size:11px;color:#9ec6ef}
        nav a{color:#cfe6ff;text-decoration:none;margin:0 15px;font-size:14px}
        nav a:hover{color:#ffd400}
        .nav-btn{background:#ffd400;color:#000;padding:8px 14px;border-radius:20px;text-decoration:none;font-weight:600}

        /* Features styles (copied minimal subset) */
        .features{background:#0b1f3a;padding:36px 24px;text-align:center;min-height:60vh}
        .features h2{color:#D4AF37;font-size:26px;margin-bottom:20px}
        .feature-grid{max-width:1100px;margin:20px auto 0;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
        .feature{background:#1a2b4d;padding:16px;border-radius:12px;border:1px solid #2b3f66;transition:0.18s}
        .feature:hover{transform:translateY(-5px);box-shadow:0 10px 20px rgba(0,0,0,0.2)}
        .feature h3{margin-bottom:12px;color:#D4AF37}
        .feature p{font-size:14px;color:#cfd9eb}
        footer{padding:20px;text-align:center;background:#081625;font-size:13px;color:#8fb6dd}

        @media(max-width:900px){header{padding:12px} .logo img{width:48px;height:48px} nav a{margin:0 8px}}
    </style>
</head>
<body>

<div class="top-bar"></div>
<header>
    <div class="logo">
        <img src="img/logo.png" alt="Municipal Logo">
        <div class="logo-text">
            <strong>Municipality of Solano</strong>
            <small>Municipal Document Management System</small>
        </div>
    </div>

    <nav>
        <a href="features.php">Features</a>
        <a href="index.php">Home</a>
        <a href="#">Departments</a>
        <a href="#">About</a>
        <?php if ($isLoggedIn): ?>
            <a href="#" class="nav-btn"><?= htmlspecialchars($_SESSION['user_name']) ?></a>
            <a href="index.php?logout=1" class="nav-btn">Logout</a>
        <?php else: ?>
            <a href="index.php" class="nav-btn"> Login</a>
        <?php endif; ?>
    </nav>
</header>

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

    <div style="margin-top:28px;"><a href="index.php" style="color:#fff;background:#2563eb;padding:10px 16px;border-radius:8px;text-decoration:none;">Back to Home</a></div>
</section>

<footer>
    © <?php echo date("Y"); ?> Municipal Government Document Management System. All rights reserved.
</footer>

</body>
</html>
