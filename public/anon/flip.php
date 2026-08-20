<?php
/**
 * A dead player's client publishes its card. This is the ONE place where a
 * user_id and a slot_index are written to the same row, and it happens only
 * after that player is already out of the game (PROTOCOL.md sec 9).
 *
 * Still unauthenticated: the slot signature proves the claim, and routing it
 * through a logged-in request would leak the account/slot link for every player
 * who dies -- including the ones whose flips arrive while others are still alive.
 */
require_once __DIR__ . '/_boot.php';

$in = sh_json_in();
$gameId = (int) ($in['game'] ?? 0);
$slot = (int) ($in['slot'] ?? -1);
$userId = (int) ($in['user'] ?? 0);
$role = (string) ($in['role'] ?? '');
$sig = (string) ($in['sig'] ?? '');

$game = sh_game($gameId);
if ($game === null || !in_array($game['status'], ['active', 'finished'], true)) {
    sh_bad('game is not running');
}
if (!in_array($role, SH_ROLES, true)) {
    sh_bad('unknown role');
}

$payload = json_encode([
    'game' => $gameId,
    'slot' => $slot,
    'user' => $userId,
    'role' => $role,
], JSON_UNESCAPED_SLASHES);
sh_auth_slot($gameId, $slot, $payload, $sig);

// A player cannot flip a role they were not dealt: the server holds slot_roles
// and the signature proves which slot is speaking.
if (sh_slot_role($gameId, $slot) !== $role) {
    sh_bad('claimed role does not match that slot', 403);
}
// ...nor flip while still alive, which would be a free confirmed claim.
$stmt = db()->prepare('SELECT is_alive FROM game_players WHERE game_id=? AND user_id=?');
$stmt->execute([$gameId, $userId]);
$alive = $stmt->fetchColumn();
if ($alive === false) {
    sh_bad('not a player in this game');
}
if ((int) $alive === 1) {
    sh_bad('living players do not flip', 403);
}

try {
    db()->prepare('INSERT INTO flips (game_id, slot_index, user_id, role, sig) VALUES (?,?,?,?,?)')
        ->execute([$gameId, $slot, $userId, $role, $sig]);
} catch (PDOException $e) {
    if ($e->getCode() !== '23000') {
        throw $e;
    }
}

// A pending flip is what stops the clock, so resolving one may release it.
sh_tick($gameId);

sh_out(['ok' => true]);
