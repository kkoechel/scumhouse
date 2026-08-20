<?php
/**
 * Lets a stranger ask for an invite. Records the request for an admin to act on
 * and sends nothing -- there is no notification mail here, deliberately, so a
 * public instance cannot be turned into a mail relay by anyone who finds it.
 */
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/render.php';

session_init();
$sent = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $message = substr(trim((string) ($_POST['message'] ?? '')), 0, 500);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'That does not look like an email address.';
    } else {
        // Rate-limited by address so the table cannot be flooded from one form.
        $stmt = db()->prepare('SELECT COUNT(*) FROM access_requests WHERE email=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $stmt->execute([$email]);
        if ((int) $stmt->fetchColumn() === 0) {
            db()->prepare('INSERT INTO access_requests (email, message) VALUES (?,?)')
                ->execute([$email, $message ?: null]);
        }
        // Same response either way: whether an address already asked, or is
        // already invited, is not something a stranger gets to probe for.
        $sent = true;
    }
}

sh_head('Request access', null);
?>
<h1>Request access</h1>
<?php if ($sent): ?>
  <div class="sh-status ok">Noted. If someone recognises the address, they will add you.</div>
<?php else: ?>
  <p class="sh-lede">Scumhouse is invite-only &mdash; it is a game you play with people you
  know, and an open sign-up would let a stranger take a seat at somebody&rsquo;s table.</p>
  <?php if ($error): ?><div class="sh-status error"><?= sh_e($error) ?></div><?php endif; ?>
  <form method="post" class="sh-create">
    <label>Email <input type="email" name="email" required placeholder="you@example.com"></label>
    <label>Anything to say? <input type="text" name="message" placeholder="optional"></label>
    <button type="submit">Ask</button>
  </form>
<?php endif; ?>
<?php sh_foot();
