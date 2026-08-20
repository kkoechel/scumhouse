<?php
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/render.php';

$user = require_admin();

$rows = db()->query(
    'SELECT b.*, u.display_name FROM bug_reports b JOIN users u ON u.id=b.reported_by_user_id ORDER BY b.id DESC LIMIT 200'
)->fetchAll();

sh_head('Bug reports', $user);
?>
<h1>Bug reports</h1>
<?php if (!$rows): ?><p>Nothing reported.</p><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <article class="sh-report">
    <h2>#<?= (int) $r['id'] ?> &middot; table <?= (int) $r['game_id'] ?> &middot; <?= sh_e($r['display_name']) ?></h2>
    <p class="sh-when"><?= sh_e($r['created_at']) ?></p>
    <p><?= nl2br(sh_e($r['description'])) ?></p>
    <details><summary>State snapshot</summary><pre><?= sh_e(json_encode(json_decode($r['state_snapshot_json'], true), JSON_PRETTY_PRINT)) ?></pre></details>
    <form method="post" action="<?= APP_PATH ?>/admin/delete-bug-report.php">
      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <button type="submit">Delete</button>
    </form>
  </article>
<?php endforeach; ?>
<?php sh_foot();
