<?php
/**
 * Publishes this player's role envelopes plus the inner key (PROTOCOL.md sec 5.2).
 *
 * The two things arriving in this one request are the two halves of a deliberate
 * split: the envelopes are sealed to investigator keys the server does not have,
 * and the inner key is handed over precisely BECAUSE the server can never get past
 * that outer seal to use it. Withholding the inner key from everyone but a
 * token-bearer is the entire rate limit on investigation.
 */
require_once __DIR__ . '/_boot.php';

$user = require_login();
$in = sh_api_in();
$gameId = (int) ($in['game'] ?? 0);
[$game] = sh_require_seat($user, $gameId);

if ($game['status'] !== 'active') {
    sh_api_fail('game is not running');
}

$innerKey = (string) ($in['inner_key'] ?? '');
$envelopes = $in['envelopes'] ?? [];
if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $innerKey)) {
    sh_api_fail('inner key must be 32 base64url bytes');
}
if (!is_array($envelopes) || !$envelopes) {
    sh_api_fail('no envelopes supplied');
}

$expected = sh_investigator_slot_list($gameId);
sort($expected);
$got = array_map('intval', array_keys($envelopes));
sort($got);
// One envelope per investigator slot, no more and no fewer. A player who omits one
// silently disables that role against themselves.
if ($got !== $expected) {
    sh_api_fail('expected exactly one envelope per investigator slot');
}

$db = db();
$db->beginTransaction();
try {
    // Write-once: re-publishing after the fact would let a player swap in a new
    // envelope once they knew what an investigator was about to find.
    $stmt = $db->prepare('SELECT 1 FROM envelope_keys WHERE game_id=? AND user_id=? FOR UPDATE');
    $stmt->execute([$gameId, $user['id']]);
    if ($stmt->fetchColumn()) {
        $db->rollBack();
        sh_api_out(['ok' => true, 'already' => true]);
    }
    $ins = $db->prepare('INSERT INTO role_envelopes (game_id, user_id, investigator_slot, ciphertext) VALUES (?,?,?,?)');
    foreach ($envelopes as $slot => $ciphertext) {
        if (!is_string($ciphertext) || strlen($ciphertext) < 100 || strlen($ciphertext) > 4000) {
            $db->rollBack();
            sh_api_fail('malformed envelope');
        }
        $ins->execute([$gameId, $user['id'], (int) $slot, $ciphertext]);
    }
    $db->prepare('INSERT INTO envelope_keys (game_id, user_id, inner_key) VALUES (?,?,?)')
       ->execute([$gameId, $user['id'], $innerKey]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

sh_api_out(['ok' => true]);
