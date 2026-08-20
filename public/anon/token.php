<?php
/**
 * Issues one blind-signed retrieval token per slot per night (PROTOCOL.md sec 5.2).
 *
 * Authenticated by SLOT SIGNATURE, not by session -- so the server can enforce
 * "one per slot per night" while still not knowing which player that slot is. As
 * with registration, it records only THAT a token was drawn; storing the blinded
 * value would let anyone holding cred_d re-link a later redemption back to the
 * slot that asked, which is the one thing the blinding exists to prevent.
 */
require_once __DIR__ . '/_boot.php';

$in = sh_json_in();
$gameId = (int) ($in['game'] ?? 0);
$slot = (int) ($in['slot'] ?? -1);
$night = (int) ($in['night'] ?? 0);
$blinded = (string) ($in['blinded'] ?? '');
$sig = (string) ($in['sig'] ?? '');

$game = sh_game($gameId);
if ($game === null || $game['status'] !== 'active' || $game['phase'] !== 'night') {
    sh_bad('it is not night');
}
if ($night !== (int) $game['phase_no']) {
    sh_bad('wrong night number');
}

$payload = json_encode(['game' => $gameId, 'slot' => $slot, 'night' => $night, 'blinded' => $blinded], JSON_UNESCAPED_SLASHES);
sh_auth_slot($gameId, $slot, $payload, $sig);

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('SELECT 1 FROM retrieval_tokens WHERE game_id=? AND night_no=? AND slot_index=? FOR UPDATE');
    $stmt->execute([$gameId, $night, $slot]);
    if ($stmt->fetchColumn()) {
        $db->rollBack();
        sh_bad('this slot already drew its token tonight', 409);
    }
    $signed = sh_blind_sign($game['cred_d'], $blinded);
    if ($signed === null) {
        $db->rollBack();
        sh_bad('malformed blinded value');
    }
    $db->prepare('INSERT INTO retrieval_tokens (game_id, night_no, slot_index) VALUES (?,?,?)')
       ->execute([$gameId, $night, $slot]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

sh_out(['ok' => true, 'blind_sig' => $signed]);
