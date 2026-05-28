<?php
// test.php
// Form sederhana untuk test REST API create device WA

$apiUrl = 'http://localhost/templatemo/api/wa-account.php'; // Ganti sesuai domain/path Anda
$error = null; // Inisialisasi agar tidak warning
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ambil data dari form
    $data = [
        'username' => $_POST['username'] ?? '',
        'password' => $_POST['password'] ?? '',
        'label'    => $_POST['label'] ?? '',
        'device_id'=> $_POST['device_id'] ?? '',
    ];

    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data),
        ],
    ];

    $context  = stream_context_create($options);
    $apiResult = @file_get_contents($apiUrl, false, $context);
    if ($apiResult === FALSE) {
        $lastError = error_get_last();
        $error = "Gagal request ke API";
        if ($lastError && isset($lastError['message'])) {
            $error .= ': ' . $lastError['message'];
        }
    } else {
        $result = $apiResult;
    }
}

?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Test Create WA Device API</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2em; }
        form { max-width: 400px; margin-bottom: 2em; }
        label { display: block; margin-top: 1em; }
        input[type=text], input[type=password] { width: 100%; padding: 8px; }
        button { margin-top: 1em; padding: 8px 16px; }
        pre { background: #f4f4f4; padding: 1em; border-radius: 6px; }
    </style>
</head>
<body>
    <h2>Test Create WA Device API</h2>
    <form method="post">
        <label>Username (email/user login):
            <input type="text" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
        </label>
        <label>Password:
            <input type="password" name="password" required value="<?php echo htmlspecialchars($_POST['password'] ?? ''); ?>">
        </label>
        <label>Label Device:
            <input type="text" name="label" required value="<?php echo htmlspecialchars($_POST['label'] ?? ''); ?>">
        </label>
        <label>Device ID (opsional):
            <input type="text" name="device_id" value="<?php echo htmlspecialchars($_POST['device_id'] ?? ''); ?>">
        </label>
        <button type="submit">Create Device</button>
    </form>

    <?php if ($error): ?>
        <div style="color: red; font-weight: bold;">Error: <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($result): ?>
        <h3>API Response:</h3>
        <pre><?php echo htmlspecialchars($result); ?></pre>
    <?php endif; ?>
</body>
</html>
