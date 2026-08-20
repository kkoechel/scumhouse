<?php
require_once __DIR__ . '/../../inc/auth.php';

session_init();

$token = trim($_GET['token'] ?? '');
$error = '';

if (!$token || strlen($token) !== 64 || !ctype_xdigit($token)) {
    $error = 'Invalid or missing login link.';
} else {
    $db = db();
    $stmt = $db->prepare(
        'SELECT id, email, used_at, (expires_at <= NOW()) AS is_expired
         FROM magic_link_tokens WHERE token=? LIMIT 1'
    );
    $stmt->execute([$token]);
    $tok = $stmt->fetch();

    if (!$tok) {
        $error = 'Login link not found — it may have already been used or never existed.';
    } elseif ($tok['used_at'] !== null) {
        $error = 'This login link has already been used. Request a new one.';
    } elseif ((int) $tok['is_expired'] === 1) {
        $error = 'This login link has expired (links are valid for 15 minutes). Request a new one.';
    } else {
        $db->prepare('UPDATE magic_link_tokens SET used_at=NOW() WHERE id=?')->execute([$tok['id']]);

        provision_and_login($tok['email']);

        header('Location: ' . safe_next_path($_GET['next'] ?? null));
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Scumhouse — Sign in</title>
<link rel="stylesheet" href="<?= APP_PATH ?>/css/style.css">
</head>
<body>
<div class="wrap">
  <h1>🧩 Scumhouse</h1>
  <div class="card">
    <div class="error"><?= htmlspecialchars($error) ?></div>
    <a class="back" href="<?= APP_PATH ?>/auth/magic-request.php">← Request a new login link</a>
  </div>
</div>
</body>
</html>
