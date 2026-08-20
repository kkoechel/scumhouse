<?php
/**
 * Publishes one slot's REVERSE envelope: slot -> account, sealed to the watcher.
 *
 * Anonymous and slot-signed, and it has to be: a logged-in POST of a row labelled
 * "slot 3" would hand the server the exact mapping this whole protocol exists to
 * withhold. The cost of posting anonymously is that the server cannot attest the
 * account named inside -- which is why the payload carries a second signature from
 * that account's own key (see public/api/account-key.php), letting the watcher
 * check the binding without the server ever seeing either half.
 */
require_once __DIR__ . '/_boot.php';

$in = sh_json_in();
$gameId = (int) ($in['game'] ?? 0);
$slot = (int) ($in['slot'] ?? -1);
$ciphertext = (string) ($in['ct'] ?? '');
$innerKey = (string) ($in['inner_key'] ?? '');
$sig = (string) ($in['sig'] ?? '');

$game = sh_game($gameId);
if ($game === null || $game['status'] !== 'active') {
    sh_bad('game is not running');
}
if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $innerKey)) {
    sh_bad('inner key must be 32 base64url bytes');
}
if (strlen($ciphertext) < 100 || strlen($ciphertext) > 4000) {
    sh_bad('malformed envelope');
}

$payload = json_encode([
    'game' => $gameId, 'slot' => $slot, 'ct' => $ciphertext, 'inner_key' => $innerKey,
], JSON_UNESCAPED_SLASHES);
sh_auth_slot($gameId, $slot, $payload, $sig);

// Write-once, like the forward envelope: republishing later would let a slot swap
// its claimed account once it knew what a watcher was about to see.
try {
    db()->prepare('INSERT INTO reverse_envelopes (game_id, slot_index, ciphertext, inner_key) VALUES (?,?,?,?)')
        ->execute([$gameId, $slot, $ciphertext, $innerKey]);
} catch (PDOException $e) {
    if ($e->getCode() !== '23000') {
        throw $e;
    }
}

sh_out(['ok' => true]);
