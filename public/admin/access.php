<?php
/**
 * The invite list, for allowlist.mode = 'local'.
 *
 * Under mode = 'portal' this page is inert on purpose: the list lives in another
 * database that this app only has read access to, and pretending otherwise would
 * produce buttons that silently fail.
 */
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/render.php';

$user = require_admin();
$mode = config()['allowlist']['mode'] ?? 'local';
$local = $mode === 'local';
$notice = null;

if ($local && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $valid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

    if ($action === 'add' && $valid) {
        db()->prepare('INSERT IGNORE INTO allowed_emails (email, note) VALUES (?,?)')
            ->execute([$email, substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: null]);
        db()->prepare('UPDATE access_requests SET handled_at=NOW() WHERE email=? AND handled_at IS NULL')
            ->execute([$email]);
        $notice = 'Invited.';
    } elseif ($action === 'remove' && $valid) {
        // Removing an invite stops future sign-ins; it does not eject anyone from
        // a game in progress, which would strand their table mid-night.
        db()->prepare('DELETE FROM allowed_emails WHERE email=?')->execute([$email]);
        $notice = 'Removed. Games already in progress are unaffected.';
    } elseif ($action === 'dismiss') {
        db()->prepare('UPDATE access_requests SET handled_at=NOW() WHERE id=?')
            ->execute([(int) ($_POST['id'] ?? 0)]);
        $notice = 'Dismissed.';
    } else {
        $notice = 'That is not a valid email address.';
    }
    if ($notice !== null && $action !== '') {
        header('Location: ' . APP_PATH . '/admin/access.php?m=' . urlencode($notice));
        exit;
    }
}

$notice = $notice ?? ($_GET['m'] ?? null);
$allowed = $local ? db()->query('SELECT * FROM allowed_emails ORDER BY added_at DESC')->fetchAll() : [];
$requests = $local
    ? db()->query('SELECT * FROM access_requests WHERE handled_at IS NULL ORDER BY created_at')->fetchAll()
    : [];

sh_head('Access', $user);
?>
<h1>Access</h1>
<?php if ($notice): ?><div class="sh-status ok"><?= sh_e($notice) ?></div><?php endif; ?>

<?php if (!$local): ?>
  <p class="sh-hint">This install defers to a shared invite list
  (<code>allowlist.mode = "portal"</code>), which lives in another database and is
  managed there. Nothing on this page would take effect.</p>
<?php else: ?>

  <?php if ($requests): ?>
    <h2>Requests</h2>
    <ul class="sh-games">
      <?php foreach ($requests as $r): ?>
        <li>
          <span class="sh-name"><?= sh_e($r['email']) ?></span>
          <span class="sh-hint"><?= sh_e($r['message'] ?? '') ?></span>
          <form method="post" class="sh-inline">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="email" value="<?= sh_e($r['email']) ?>">
            <button type="submit">Invite</button>
          </form>
          <form method="post" class="sh-inline">
            <input type="hidden" name="action" value="dismiss">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button type="submit">Dismiss</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2>Invite someone</h2>
  <form method="post" class="sh-create">
    <input type="hidden" name="action" value="add">
    <label>Email <input type="email" name="email" required placeholder="player@example.com"></label>
    <label>Note <input type="text" name="note" placeholder="optional"></label>
    <button type="submit">Invite</button>
  </form>

  <h2>Invited (<?= count($allowed) ?>)</h2>
  <ul class="sh-games">
    <?php foreach ($allowed as $a): ?>
      <li>
        <span class="sh-name"><?= sh_e($a['email']) ?></span>
        <span class="sh-hint"><?= sh_e($a['note'] ?? '') ?></span>
        <span class="sh-game-state"><?= sh_e(substr($a['added_at'], 0, 10)) ?></span>
        <form method="post" class="sh-inline"
              onsubmit="return confirm('Remove <?= sh_e($a['email']) ?> from the invite list?')">
          <input type="hidden" name="action" value="remove">
          <input type="hidden" name="email" value="<?= sh_e($a['email']) ?>">
          <button type="submit">Remove</button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php sh_foot();
