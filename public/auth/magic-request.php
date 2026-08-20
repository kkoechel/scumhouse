<?php
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/resend.php';

session_init();
$next = safe_next_path($_GET['next'] ?? $_POST['next'] ?? null);
if (current_user()) {
    header('Location: ' . $next);
    exit;
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!is_allowed_email($email)) {
        $error = "That email isn't invited to this game yet.";
    } else {
        $db = db();
        $recent = $db->prepare(
            'SELECT COUNT(*) FROM magic_link_tokens
             WHERE email=? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)'
        );
        $recent->execute([$email]);
        if ((int) $recent->fetchColumn() >= 3) {
            $error = 'Too many requests — please wait a few minutes before trying again.';
        } else {
            $db->prepare('UPDATE magic_link_tokens SET used_at=NOW() WHERE email=? AND used_at IS NULL')
               ->execute([$email]);

            $token = bin2hex(random_bytes(32));
            $db->prepare(
                'INSERT INTO magic_link_tokens (email, token, expires_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))'
            )->execute([$email, $token]);

            $link = rtrim(config()['base_url'], '/') . '/auth/magic-callback.php?token=' . $token . '&next=' . urlencode($next);
            $html = '
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#181a20;font-family:system-ui,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#181a20;padding:40px 20px">
<tr><td align="center">
  <table width="480" cellpadding="0" cellspacing="0" style="background:#22242c;border:1px solid #3a3d4a;border-radius:12px;overflow:hidden">
    <tr><td style="background:#282b35;padding:20px 32px">
      <span style="font-size:1.3rem;color:#fff;letter-spacing:.03em">🧩 Scumhouse</span>
    </td></tr>
    <tr><td style="padding:32px">
      <p style="margin:0 0 8px;font-size:1rem;font-weight:700;color:#eee">Your login link</p>
      <p style="margin:0 0 24px;font-size:.88rem;color:#b3b6c3;line-height:1.6">
        Click the button below to sign in. This link expires in <strong>15 minutes</strong> and can only be used once.
      </p>
      <a href="' . htmlspecialchars($link) . '"
         style="display:inline-block;background:#8e4ec6;color:#fff;border-radius:8px;
                padding:12px 28px;font-size:1rem;text-decoration:none">
        Sign in to Scumhouse
      </a>
      <p style="margin:28px 0 0;font-size:.75rem;color:#8a8da0;line-height:1.6">
        If you did not request this, you can safely ignore this email.<br>
        Link: <a href="' . htmlspecialchars($link) . '" style="color:#8a8da0">' . htmlspecialchars($link) . '</a>
      </p>
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>';

            $sent = resend_send($email, 'Your Scumhouse login link', $html);
            if ($sent) {
                $success = true;
            } else {
                $error = 'Failed to send email — please try again.';
            }
        }
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
  <a class="back" href="/">← Board Dames</a>
  <h1>🧩 Scumhouse</h1>
  <div class="card">
    <?php if ($success): ?>
      <div class="success">
        <strong>Check your inbox.</strong><br>
        A login link was sent to <strong><?= htmlspecialchars($email) ?></strong>.<br>
        It expires in 15 minutes.
      </div>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" placeholder="you@example.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
        <button type="submit">Send login link</button>
      </form>
      <div style="text-align:center;margin-top:1rem">
        <a class="back" href="<?= APP_PATH ?>/request-access.php">Request access</a>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
