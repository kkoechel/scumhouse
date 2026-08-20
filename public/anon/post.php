<?php
/**
 * Appends one fixed-size blob to the anonymous channel. Cover traffic and real
 * mafia chat take exactly this path and are indistinguishable to the server.
 */
require_once __DIR__ . '/_boot.php';

const SH_BLOB_B64_LEN = 678; // b64u of iv(12) + ct(480) + tag(16)

$in = sh_json_in();
$gameId = (int) ($in['game'] ?? 0);
$slot = (int) ($in['slot'] ?? -1);
$ciphertext = (string) ($in['ct'] ?? '');
$sig = (string) ($in['sig'] ?? '');

$game = sh_game($gameId);
if ($game === null || $game['status'] !== 'active') {
    sh_bad('game is not running');
}
// Every blob is the same length by construction. Enforcing it server-side stops
// a modified client from leaking its message length onto a shared channel.
if (strlen($ciphertext) !== SH_BLOB_B64_LEN) {
    sh_bad('blob must be exactly ' . SH_BLOB_B64_LEN . ' base64url characters');
}

$payload = json_encode([
    'game' => $gameId,
    'slot' => $slot,
    'phase_no' => (int) $game['phase_no'],
    'phase' => $game['phase'],
    'ct' => $ciphertext,
], JSON_UNESCAPED_SLASHES);
sh_auth_slot($gameId, $slot, $payload, $sig);

db()->prepare(
    'INSERT INTO anon_posts (game_id, phase_no, phase, slot_index, ciphertext, sig, visible_at)
     VALUES (?,?,?,?,?,?, NOW())'
)->execute([$gameId, (int) $game['phase_no'], $game['phase'], $slot, $ciphertext, $sig]);

sh_out(['ok' => true]);
