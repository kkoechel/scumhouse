<?php
/**
 * Admin view of the tables. Deliberately thin, and deliberately unable to show
 * anything interesting: there is no query here that could reveal a living
 * player's role, because there is no such query anywhere.
 *
 * The one real power is "abandon", which exists for the failure mode documented
 * in PROTOCOL.md sec 9 -- a dead player who never opens their card leaves a
 * pending flip, and a pending flip stops the clock for everyone else.
 */
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/game_state.php';
require_once __DIR__ . '/../../inc/render.php';

$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'abandon') {
    $gameId = (int) ($_POST['game_id'] ?? 0);
    db()->prepare("UPDATE games SET status='abandoned', finished_at=NOW(), phase_ends_at=NULL WHERE id=? AND status IN ('lobby','registration','active')")
        ->execute([$gameId]);
    header('Location: ' . APP_PATH . '/admin/games.php');
    exit;
}

$games = db()->query(
    'SELECT g.*, (SELECT COUNT(*) FROM game_players gp WHERE gp.game_id=g.id) AS seated
       FROM games g ORDER BY g.id DESC LIMIT 100'
)->fetchAll();

sh_head('Tables', $user);
?>
<h1>Tables</h1>
<p class="sh-hint">Abandoning a table ends it for everyone. It is the escape hatch for a
game stalled on a player who died and never opened their card &mdash; there is no way to
force that flip, by design.</p>
<ul class="sh-games">
<?php foreach ($games as $g):
    $pending = sh_pending_flips((int) $g['id']); ?>
  <li>
    <span class="sh-game-id">#<?= (int) $g['id'] ?></span>
    <span class="sh-game-size"><?= (int) $g['seated'] ?>/<?= (int) $g['num_seats'] ?></span>
    <span class="sh-game-state">
      <?= sh_e($g['status']) ?><?= $g['phase'] ? ' &middot; ' . sh_e($g['phase']) . ' ' . (int) $g['phase_no'] : '' ?>
      <?php if ($pending): ?>
        &middot; stalled on <?= sh_e(implode(', ', array_column($pending, 'display_name'))) ?>
      <?php endif; ?>
    </span>
    <?php if (in_array($g['status'], ['lobby', 'registration', 'active'], true)): ?>
      <form method="post" class="sh-inline"
            onsubmit="return confirm('Abandon table #<?= (int) $g['id'] ?>? This cannot be undone.')">
        <input type="hidden" name="game_id" value="<?= (int) $g['id'] ?>">
        <input type="hidden" name="action" value="abandon">
        <button type="submit">Abandon</button>
      </form>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
<?php sh_foot();
