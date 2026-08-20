<?php
/**
 * API tokens, for running a client this server does not host (PROTOCOL.md sec 8.1).
 *
 * A token is displayed exactly once. It is stored only as a SHA-256 hash, so
 * neither this page nor a database leak can produce it again -- if it is lost,
 * revoke it and make another.
 */
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/render.php';

$user = require_login();
$fresh = null;
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $fresh = issue_api_token((int) $user['id'], substr(trim((string) ($_POST['label'] ?? '')), 0, 64));
    } elseif ($action === 'revoke') {
        // Scoped to the owner: a token id from someone else's account must not be
        // revocable by guessing the number.
        db()->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE id=? AND user_id=? AND revoked_at IS NULL')
            ->execute([(int) ($_POST['id'] ?? 0), $user['id']]);
        $notice = 'Token revoked. Any client using it stops working immediately.';
    }
}

$stmt = db()->prepare('SELECT * FROM api_tokens WHERE user_id=? ORDER BY created_at DESC');
$stmt->execute([$user['id']]);
$tokens = $stmt->fetchAll();

sh_head('Account', $user);
?>
<h1>API tokens</h1>
<p class="sh-lede">For running Scumhouse from a client this server does not host &mdash;
which is the only way to be sure the code holding your keys was not swapped for you
specifically. <a href="<?= APP_PATH ?>/rules.php#privacy">Why that matters.</a></p>

<?php if ($notice): ?><div class="sh-status ok"><?= sh_e($notice) ?></div><?php endif; ?>

<?php if ($fresh !== null): ?>
  <div class="sh-status warn">
    <strong>Copy this now &mdash; it is shown once and cannot be recovered.</strong>
  </div>
  <textarea class="sh-token" rows="2" readonly onclick="this.select()"><?= sh_e($fresh) ?></textarea>
<?php endif; ?>

<form method="post" class="sh-create">
  <input type="hidden" name="action" value="create">
  <label>Label <input type="text" name="label" placeholder="laptop" maxlength="64"></label>
  <button type="submit">Create a token</button>
</form>

<h2>Your tokens</h2>
<?php if (!$tokens): ?><p class="sh-hint">None yet.</p><?php endif; ?>
<ul class="sh-games">
  <?php foreach ($tokens as $t): ?>
    <li>
      <span class="sh-name"><?= sh_e($t['label'] ?? 'unlabelled') ?></span>
      <span class="sh-hint">
        created <?= sh_e(substr($t['created_at'], 0, 10)) ?>
        <?= $t['last_used_at'] ? ' &middot; last used ' . sh_e(substr($t['last_used_at'], 0, 10)) : ' &middot; never used' ?>
      </span>
      <?php if ($t['revoked_at']): ?>
        <span class="sh-game-state">revoked</span>
      <?php else: ?>
        <form method="post" class="sh-inline" onsubmit="return confirm('Revoke this token?')">
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <button type="submit">Revoke</button>
        </form>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
</ul>

<h2>Using one</h2>
<ol class="sh-steps">
  <li>Clone the source: <code>git clone https://github.com/kkoechel/scumhouse.git</code></li>
  <li>Serve the repository root, e.g. <code>cd scumhouse &amp;&amp; python3 -m http.server 8787</code>
      &mdash; the root, not <code>client/</code>, since the client loads shared code from
      <code>../public/js/</code>.</li>
  <li>Open <code>http://localhost:8787/client/</code>, paste this server's address and your token.</li>
</ol>
<p class="sh-hint">Your card lives in the browser storage of whichever origin you use, so a
local client starts without one. Move an existing identity across with the recovery code
from the game screen, or start a new game from the local client.</p>
<?php sh_foot();
