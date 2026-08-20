<?php
/** Public, attributed, and changeable until the day ends -- ordinary forum-mafia
 * voting. A null target is an explicit "no lynch", which is not the same as not
 * having voted and is counted separately by sh_tally_votes(). */
require_once __DIR__ . '/_boot.php';

$user = require_login();
$in = sh_api_in();
$gameId = (int) ($in['game'] ?? 0);
[$game, $seat] = sh_require_seat($user, $gameId);

if ($game['status'] !== 'active' || $game['phase'] !== 'day') {
    sh_api_fail('voting is closed');
}
if ((int) $seat['is_alive'] !== 1) {
    sh_api_fail('the dead do not vote', 403);
}

$hasTarget = array_key_exists('target', $in) && $in['target'] !== null;
$target = $hasTarget ? (int) $in['target'] : null;
if ($target !== null) {
    $stmt = db()->prepare('SELECT 1 FROM game_players WHERE game_id=? AND user_id=? AND is_alive=1');
    $stmt->execute([$gameId, $target]);
    if (!$stmt->fetchColumn()) {
        sh_api_fail('you can only vote for a living player');
    }
}

db()->prepare(
    'INSERT INTO votes (game_id, phase_no, voter_user_id, target_user_id) VALUES (?,?,?,?)
     ON DUPLICATE KEY UPDATE target_user_id=VALUES(target_user_id)'
)->execute([$gameId, (int) $game['phase_no'], $user['id'], $target]);

// A hammer ends the day immediately rather than waiting for the deadline.
sh_tick($gameId);

sh_api_out(['ok' => true]);
