<?php
/**
 * Submits a night action. The server checks that the SLOT's role permits it --
 * which it can, because it knows slot_roles -- and never learns which player
 * pressed the button.
 */
require_once __DIR__ . '/_boot.php';

$in = sh_json_in();
$gameId = (int) ($in['game'] ?? 0);
$slot = (int) ($in['slot'] ?? -1);
$night = (int) ($in['night'] ?? 0);
$action = (string) ($in['action'] ?? '');
// 'block' names a SLOT; every other action names an account. The roleblocker
// learned that slot from an envelope the server cannot open, and telling the
// server the account would hand it the link the envelope exists to withhold.
$isBlock = $action === 'block';
$target = $isBlock ? null : (int) ($in['target'] ?? 0);
$targetSlot = $isBlock ? (int) ($in['target_slot'] ?? -1) : null;
$sig = (string) ($in['sig'] ?? '');

$game = sh_game($gameId);
if ($game === null || $game['status'] !== 'active' || $game['phase'] !== 'night') {
    sh_bad('it is not night');
}
if ($night !== (int) $game['phase_no']) {
    sh_bad('wrong night number');
}

$payload = json_encode([
    'game' => $gameId,
    'slot' => $slot,
    'night' => $night,
    'action' => $action,
    'target' => $target,
    'target_slot' => $targetSlot,
], JSON_UNESCAPED_SLASHES);
sh_auth_slot($gameId, $slot, $payload, $sig);

$role = sh_slot_role($gameId, $slot);
if ($role === null) {
    sh_bad('slot has no role', 403);
}
$allowed = sh_allowed_action($role, $night, sh_vig_shots_used($gameId, $slot));
if ($allowed === null || $allowed !== $action) {
    sh_bad('that slot may not do that tonight', 403);
}

if ($isBlock) {
    // Any published slot is a legal block target -- including one whose holder is
    // already dead, because the server has no way to know which slots those are and
    // must not learn it here. A wasted block is the roleblocker's problem.
    $stmt = db()->prepare('SELECT 1 FROM anon_slots WHERE game_id=? AND slot_index=?');
    $stmt->execute([$gameId, $targetSlot]);
    if (!$stmt->fetchColumn()) {
        sh_bad('no such slot');
    }
} else {
    // Target must be a living player in this game. This is a public fact, so
    // checking it costs no privacy.
    $stmt = db()->prepare('SELECT 1 FROM game_players WHERE game_id=? AND user_id=? AND is_alive=1');
    $stmt->execute([$gameId, $target]);
    if (!$stmt->fetchColumn()) {
        sh_bad('target is not a living player');
    }
}

$db = db();
$db->beginTransaction();
// Last valid submission per slot per night wins; earlier ones are kept but marked
// so the audit trail survives without affecting resolution.
$db->prepare('UPDATE anon_actions SET superseded=1 WHERE game_id=? AND night_no=? AND slot_index=?')
   ->execute([$gameId, $night, $slot]);
$db->prepare(
    'INSERT INTO anon_actions (game_id, night_no, slot_index, action, target_user_id, target_slot, sig) VALUES (?,?,?,?,?,?,?)'
)->execute([$gameId, $night, $slot, $action, $target, $targetSlot, $sig]);
$db->commit();

sh_out(['ok' => true]);
