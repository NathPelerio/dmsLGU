<?php
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
$message = isset($_GET['added']) ? 'Office added successfully.' : '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if ($code === '') {
        $error = 'Code is required.';
    } else {
        try {
            $pdo = dbPdo($config);
            $stmt = $pdo->prepare(
                'INSERT INTO offices (id, office_code, office_name, office_head, office_head_id, description, created_at)
                 VALUES (:id, :office_code, :office_name, :office_head, :office_head_id, :description, :created_at)'
            );
            $stmt->execute([
                ':id' => dbGenerateId24(),
                ':office_code' => $code,
                ':office_name' => $department,
                ':office_head' => '',
                ':office_head_id' => '',
                ':description' => '',
                ':created_at' => dbNowUtcString(),
            ]);
            header('Location: ?added=1');
            exit;
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DMS LGU – Add Office</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; max-width: 420px; margin: 2rem auto; padding: 0 1rem; }
        h1 { font-size: 1.25rem; margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.25rem; font-weight: 500; }
        input, textarea { width: 100%; padding: 0.5rem; margin-bottom: 1rem; border: 1px solid #ccc; border-radius: 4px; }
        textarea { min-height: 80px; resize: vertical; }
        button { background: #2563eb; color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 4px; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .message { padding: 0.5rem; margin-bottom: 1rem; border-radius: 4px; }
        .message.success { background: #dcfce7; color: #166534; }
        .message.error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <h1>Add Office</h1>

    <?php if ($message): ?>
        <p class="message success"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p class="message error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" action="">
        <label for="code">Code *</label>
        <input type="text" id="code" name="code" required placeholder="e.g. MMO"
               value="<?= htmlspecialchars($_POST['code'] ?? '') ?>">

        <label for="department">Department</label>
        <input type="text" id="department" name="department" placeholder="e.g. Municipal Mayors Office"
               value="<?= htmlspecialchars($_POST['department'] ?? '') ?>">

        <button type="submit">Add</button>
    </form>
</body>
</html>
