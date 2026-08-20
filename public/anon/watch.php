<?php
/**
 * A watcher's question: "who visited this account tonight?"
 *
 * Naming the account is safe for the same reason the tracker's slot query is: the
 * server already knows which slots targeted which accounts, because it resolves
 * the night. What it never learns is whose slot any of them is.
 *
 * Answered at dawn, when the night's actions are final.
 */
require_once __DIR__ . '/_boot.php';

$in = sh_json_in();
$gameId = (int) ($in['game'] ?? 0);
$slot = (int) ($in['slot'] ?? -1);
$night = (int) ($in['night'] ?? 0);
$target = (int) ($in['target'] ?? 0);
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
    'game' => $gameId, 'slot' => $slot, 'night' => $night,
    'target' => $target, 'ephemeral_pub' => $ephPub,
], JSON_UNESCAPED_SLASHES);
sh_auth_slot($gameId, $slot, $payload, $sig);

if (sh_slot_role($gameId, $slot) !== 'WATCHER') {
    sh_bad('that slot does not watch', 403);
}

$stmt = db()->prepare('SELECT 1 FROM game_players WHERE game_id=? AND user_id=? AND is_alive=1');
$stmt->execute([$gameId, $target]);
if (!$stmt->fetchColumn()) {
    sh_bad('target is not a living player');
}

db()->prepare(
    'INSERT INTO watcher_queries (game_id, night_no, slot_index, target_user_id, ephemeral_pub, sig) VALUES (?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE target_user_id=VALUES(target_user_id), ephemeral_pub=VALUES(ephemeral_pub), sig=VALUES(sig)'
)->execute([$gameId, $night, $slot, $target, $ephPub, $sig]);

sh_out(['ok' => true]);
