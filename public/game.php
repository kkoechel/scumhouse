<?php
/**
 * The game screen. Deliberately a thin shell: everything that depends on a role
 * is rendered by public/js/game.js from a card only the browser can open. The
 * server literally cannot fill this page in -- see PROTOCOL.md.
 */
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/game_state.php';
require_once __DIR__ . '/../inc/render.php';

$user = require_login();
$gameId = (int) ($_GET['game'] ?? 0);

$game = sh_game($gameId);
if ($game === null) {
    http_response_code(404);
    exit('No such table.');
}
$stmt = db()->prepare('SELECT 1 FROM game_players WHERE game_id=? AND user_id=?');
$stmt->execute([$gameId, $user['id']]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    exit("You're not seated at this table.");
}
sh_tick($gameId);

sh_head('Table #' . $gameId, $user, ['/js/crypto.js', '/js/game.js']);
?>
<script>
  window.SH_APP_PATH = <?= json_encode(APP_PATH) ?>;
  window.SH_GAME_ID = <?= (int) $gameId ?>;
</script>

<!-- The game screen is rendered by public/js/game.js, not here. It has to be:
     the server cannot render a card it is not allowed to know, and the same
     markup has to serve the locally-run client (PROTOCOL.md sec 8.1), which has
     no PHP at all. One source, injected into this root. -->
<div id="sh-root"></div>

<form class="sh-bug" method="post" action="<?= APP_PATH ?>/api/report-bug.php">
  <input type="hidden" name="game_id" value="<?= (int) $gameId ?>">
  <label>Report a bug<textarea name="description" rows="2" placeholder="What went wrong?"></textarea></label>
  <button type="submit">Send</button>
</form>

<?php sh_foot();
