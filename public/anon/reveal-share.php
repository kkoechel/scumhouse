<?php
/**
 * Opens one Shamir share of a dead player's flip key, in the clear.
 *
 * Anonymous and slot-signed: a logged-in reveal would hand the server
 * account->slot for every player who helps, which would leak the whole table over
 * the course of a game.
 *
 * Gated server-side rather than left to client politeness. A share is only
 * accepted once the subject is dead, has NOT flipped, and the phase deadline has
 * actually passed -- so shares cannot be farmed early against a living player.
 */
require_once __DIR__ . '/_boot.php';

$in = sh_json_in();
$gameId = (int) ($in['game'] ?? 0);
$slot = (int) ($in['slot'] ?? -1);
$subject = (int) ($in['subject'] ?? 0);
$share = (string) ($in['share'] ?? '');

$game = sh_game($gameId);
if ($game === null || !in_array($game['status'], ['active', 'finished'], true)) {
    sh_bad('game is not running');
}
if (!preg_match('/^[0-9]{1,3}:[A-Za-z0-9_-]{10,120}$/', $share)) {
    sh_bad('malformed share');
}

$payload = json_encode([
    'game' => $gameId, 'slot' => $slot, 'subject' => $subject, 'share' => $share,
], JSON_UNESCAPED_SLASHES);
sh_auth_slot($gameId, $slot, $payload, (string) ($in['sig'] ?? ''));

// The subject must be dead...
$stmt = db()->prepare('SELECT is_alive FROM game_players WHERE game_id=? AND user_id=?');
$stmt->execute([$gameId, $subject]);
$alive = $stmt->fetchColumn();
if ($alive === false) {
    sh_bad('not a player in this game');
}
if ((int) $alive === 1) {
    sh_bad('that player is alive -- shares are not opened against the living', 403);
}
// ...and must not have flipped already, or there is nothing to force.
$stmt = db()->prepare('SELECT 1 FROM flips WHERE game_id=? AND user_id=?');
$stmt->execute([$gameId, $subject]);
if ($stmt->fetchColumn()) {
    sh_bad('that player has already flipped', 409);
}
// ...and the clock must actually be stalled, so a slow flip is not treated as a
// refusal and opened out from under someone who was merely asleep.
if ($game['phase_ends_at'] !== null && strtotime($game['phase_ends_at']) > time()) {
    sh_bad('the phase deadline has not passed yet', 409);
}

try {
    db()->prepare('INSERT INTO flip_share_reveals (game_id, subject_user_id, holder_slot, share) VALUES (?,?,?,?)')
        ->execute([$gameId, $subject, $slot, $share]);
} catch (PDOException $e) {
    if ($e->getCode() !== '23000') {
        throw $e;
    }
}

$stmt = db()->prepare('SELECT COUNT(*) FROM flip_share_reveals WHERE game_id=? AND subject_user_id=?');
$stmt->execute([$gameId, $subject]);
sh_out(['ok' => true, 'revealed' => (int) $stmt->fetchColumn(), 'needed' => (int) $game['num_seats'] - 1]);
