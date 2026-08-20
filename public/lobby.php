<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/game_state.php';
require_once __DIR__ . '/../inc/render.php';

$user = require_login();

// Lazily advance any game this player is in, so deadlines pass without a cron.
$stmt = db()->prepare('SELECT game_id FROM game_players WHERE user_id=?');
$stmt->execute([$user['id']]);
foreach ($stmt->fetchAll() as $row) {
    sh_tick((int) $row['game_id']);
}

$stmt = db()->prepare(
    'SELECT g.*,
            (SELECT COUNT(*) FROM game_players gp WHERE gp.game_id = g.id) AS seated,
            (SELECT COUNT(*) FROM game_players gp2 WHERE gp2.game_id = g.id AND gp2.user_id = ?) AS mine
       FROM games g
      WHERE g.status <> ? OR g.finished_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
   ORDER BY g.id DESC LIMIT 40'
);
$stmt->execute([$user['id'], 'finished']);
$games = $stmt->fetchAll();

sh_head('Lobby', $user);
?>
<h1>Scumhouse</h1>
<p class="sh-lede">Play-by-post mafia where the people running the server cannot read the
mafia's chat and cannot tell who they are.
<a href="<?= APP_PATH ?>/rules.php#privacy">How that works, and what it does not protect against.</a></p>

<form method="post" action="<?= APP_PATH ?>/create.php" class="sh-create">
  <label>Table size
    <select name="num_seats">
      <?php foreach ([5, 6, 7, 8, 9, 10] as $n): $s = sh_setup($n); ?>
        <?php
          // Derived, never hand-listed: a setup change must not be able to leave
          // the lobby advertising a composition the deal will not produce.
          $parts = [];
          foreach ($s as $role => $count) {
              if ($count > 0) {
                  $parts[] = $count . ' ' . strtolower($role) . ($count > 1 && $role !== 'MAFIA' ? 's' : '');
              }
          }
        ?>
        <option value="<?= $n ?>"<?= $n === 7 ? ' selected' : '' ?>>
          <?= $n ?> players &mdash; <?= sh_e(implode(', ', $parts)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Day length <select name="day_hours"><option value="24">24h</option><option value="48" selected>48h</option><option value="72">72h</option></select></label>
  <label>Night length <select name="night_hours"><option value="12">12h</option><option value="24" selected>24h</option><option value="48">48h</option></select></label>
  <button type="submit">Open a table</button>
</form>

<h2>Tables</h2>
<?php if (!$games): ?>
  <p>No tables yet. Open one.</p>
<?php endif; ?>
<ul class="sh-games">
<?php foreach ($games as $g):
    $setup = sh_setup((int) $g['num_seats']);
    $label = match ($g['status']) {
        'lobby' => $g['seated'] . ' of ' . $g['num_seats'] . ' seated',
        'registration' => 'setting up',
        'active' => ucfirst($g['phase']) . ' ' . $g['phase_no'],
        'finished' => ($g['winner_faction'] === 'MAFIA' ? 'mafia won' : 'town won'),
        default => $g['status'],
    };
?>
  <li>
    <span class="sh-game-id">#<?= (int) $g['id'] ?></span>
    <span class="sh-game-size"><?= (int) $g['num_seats'] ?>p &middot; <?= $setup['MAFIA'] ?> mafia</span>
    <span class="sh-game-state"><?= sh_e($label) ?></span>
    <?php if ((int) $g['mine'] === 1): ?>
      <a class="sh-go" href="<?= APP_PATH ?>/game.php?game=<?= (int) $g['id'] ?>">Open</a>
    <?php elseif ($g['status'] === 'lobby'): ?>
      <form method="post" action="<?= APP_PATH ?>/join.php" class="sh-inline">
        <input type="hidden" name="game_id" value="<?= (int) $g['id'] ?>">
        <button type="submit">Take a seat</button>
      </form>
    <?php else: ?>
      <span class="sh-closed">closed</span>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
<?php sh_foot();
