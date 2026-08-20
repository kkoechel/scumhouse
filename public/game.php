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

<div class="sh-head">
  <h1 id="sh-phase">Loading...</h1>
  <span id="sh-clock" class="sh-clock"></span>
</div>
<div id="sh-status" class="sh-status">Opening your card in this browser...</div>

<div class="sh-cols">
  <section class="sh-main">
    <div id="sh-card" class="sh-card"></div>

    <h2>Town square</h2>
    <div id="sh-thread" class="sh-thread"></div>
    <div class="sh-composer">
      <textarea id="sh-say-body" rows="3" placeholder="Say something to the whole table..."></textarea>
      <button id="sh-say" type="button">Post</button>
    </div>

    <section id="sh-vote" class="sh-panel" hidden>
      <h2>Vote to lynch</h2>
      <p class="sh-hint">A strict majority ends the day at once. At the deadline the leader is
      lynched; a tie is no lynch.</p>
      <div class="sh-targets"></div>
    </section>

    <section id="sh-investigate" class="sh-panel" hidden>
      <h2>Investigate</h2>
      <p class="sh-hint" id="sh-investigate-label"></p>
      <div class="sh-targets"></div>
      <p class="sh-result" id="sh-investigate-result"></p>
    </section>

    <section id="sh-night" class="sh-panel" hidden>
      <h2>Night action</h2>
      <p class="sh-hint" id="sh-night-label"></p>
      <div class="sh-targets"></div>
    </section>
  </section>

  <aside class="sh-side">
    <h2>Table</h2>
    <ul id="sh-players" class="sh-players"></ul>

    <section id="sh-team" class="sh-team" hidden>
      <h2>Private channel</h2>
      <p class="sh-hint">Encrypted in your browser to your partner's key. The server stores it
      as fixed-size ciphertext it has no key for.</p>
      <div id="sh-team-log" class="sh-team-log"></div>
      <textarea id="sh-team-body" rows="3" placeholder="Only your partners can read this."></textarea>
      <button id="sh-team-send" type="button">Send</button>
    </section>

    <section id="sh-recovery" class="sh-recovery" hidden>
      <h2>Don't lose your keys</h2>
      <p class="sh-hint">Your card lives only in this browser. Clear its storage and you are
      out of the game. Copy this code somewhere safe.</p>
      <textarea id="sh-recovery-code" rows="3" readonly></textarea>
      <details>
        <summary>Or store an encrypted backup on the server</summary>
        <p class="sh-hint">Wrapped in your browser with a passphrase we never receive. Note the
        trade-off: this is the one row that ties your account to your (encrypted) identity,
        so a weak passphrase is a real risk. Optional on purpose.</p>
        <input id="sh-backup-pass" type="password" placeholder="Passphrase (12+ characters)" autocomplete="new-password">
        <button id="sh-backup-save" type="button">Store backup</button>
      </details>
      <button id="sh-restore-backup" type="button" hidden>Restore from server backup</button>
      <button id="sh-restore-code" type="button">Restore from a recovery code</button>
    </section>

    <form class="sh-bug" method="post" action="<?= APP_PATH ?>/api/report-bug.php">
      <input type="hidden" name="game_id" value="<?= (int) $gameId ?>">
      <label>Report a bug<textarea name="description" rows="2" placeholder="What went wrong?"></textarea></label>
      <button type="submit">Send</button>
    </form>
  </aside>
</div>
<?php sh_foot();
