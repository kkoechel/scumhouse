<?php
/**
 * A tracker's question: "what did slot N target tonight?"
 *
 * This one names a SLOT in the clear and that is fine -- the server already knows
 * every slot's action, because it has to resolve the night. What it does not know
 * is whose slot that is, and it does not learn it here. The tracker found the slot
 * through an anonymous envelope redemption the server cannot connect to this
 * query, which is what keeps the two halves apart.
 *
 * Answered at dawn (inc/game_state.php sh_seal_tracker_reports), because until the
 * night resolves there is nothing final to report.
 */
require_once __DIR__ . '/_boot.php';

$in = sh_json_in();
$gameId = (int) ($in['game'] ?? 0);
$slot = (int) ($in['slot'] ?? -1);
$night = (int) ($in['night'] ?? 0);
$targetSlot = (int) ($in['target_slot'] ?? -1);
$ephPub = (string) ($in['ephemeral_pub'] ?? '');
$sig = (string) ($in['sig'] ?? '');

$game = sh_game($gameId);
if ($game === null || $game['status'] !== 'active' || $game['phase'] !== 'night') {
    sh_bad('it is not night');
}
if ($night !== (int) $game['phase_no']) {
    sh_bad('wrong night number');
}
if (sh_p256_pub_pem($ephPub) === null) {
    sh_bad('malformed ephemeral key');
}

$payload = json_encode([
    'game' => $gameId,
    'slot' => $slot,
    'night' => $night,
    'target_slot' => $targetSlot,
    'ephemeral_pub' => $ephPub,
], JSON_UNESCAPED_SLASHES);
sh_auth_slot($gameId, $slot, $payload, $sig);

if (sh_slot_role($gameId, $slot) !== 'TRACKER') {
    sh_bad('that slot does not track', 403);
}

$stmt = db()->prepare('SELECT 1 FROM anon_slots WHERE game_id=? AND slot_index=?');
$stmt->execute([$gameId, $targetSlot]);
if (!$stmt->fetchColumn()) {
    sh_bad('no such slot');
}

// One question per night. Replacing it is allowed until the deadline -- the
// tracker may learn a better target after the release point -- but there is only
// ever one row, so only ever one answer.
db()->prepare(
    'INSERT INTO tracker_queries (game_id, night_no, slot_index, target_slot, ephemeral_pub, sig) VALUES (?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE target_slot=VALUES(target_slot), ephemeral_pub=VALUES(ephemeral_pub), sig=VALUES(sig)'
)->execute([$gameId, $night, $slot, $targetSlot, $ephPub, $sig]);

sh_out(['ok' => true]);
