<?php
/**
 * Spends a retrieval token for one player's inner envelope key.
 *
 * This endpoint carries NO slot index and NO session -- that absence is the whole
 * point. Every living client redeems a token every night, on a real target if it
 * has a question and a random living player if it does not, so the server sees N
 * requests naming N accounts and cannot tell which was the investigator's.
 *
 * The answer is sealed to a fresh ephemeral key supplied with the request rather
 * than published, so an investigator who later hands out their private key exposes
 * only the envelopes they actually opened -- not the whole table.
 *
 * NOTHING IS RETURNED HERE. The request is queued and every answer for the night
 * becomes readable together, at the fixed release point, from answers.php
 * (PROTOCOL.md sec 5.3). Answering inline would have handed back the one signal
 * the cover traffic could not hide: the investigator deliberates, everyone else
 * spends on autopilot, so the ORDER of redemptions was the tell.
 */
require_once __DIR__ . '/_boot.php';

$in = sh_json_in();
$gameId = (int) ($in['game'] ?? 0);
$night = (int) ($in['night'] ?? 0);
$nonce = (string) ($in['nonce'] ?? '');
$token = (string) ($in['token'] ?? '');
$target = (int) ($in['target'] ?? 0);
$ephPub = (string) ($in['ephemeral_pub'] ?? '');

$game = sh_game($gameId);
if ($game === null || $game['status'] !== 'active' || $game['phase'] !== 'night') {
    sh_bad('it is not night');
}
if ($night !== (int) $game['phase_no']) {
    sh_bad('wrong night number');
}
if (!preg_match('/^[A-Za-z0-9_-]{22,64}$/', $nonce)) {
    sh_bad('malformed nonce');
}
if (sh_keys_released($game)) {
    sh_bad('the retrieval window closed at this night\'s release point', 409);
}
if (sh_p256_pub_pem($ephPub) === null) {
    sh_bad('malformed ephemeral key');
}

// The token signs a game+night+nonce message, domain-separated from the
// registration credential so the two can share one RSA key without either being
// usable as the other.
if (!sh_verify_credential($game['cred_d'], sh_token_message($gameId, $night, $nonce), $token)) {
    sh_bad('invalid token', 403);
}

$stmt = db()->prepare('SELECT inner_key FROM envelope_keys WHERE game_id=? AND user_id=?');
$stmt->execute([$gameId, $target]);
$innerKey = $stmt->fetchColumn();
if ($innerKey === false) {
    sh_bad('that player has not published an envelope');
}

$db = db();
$db->beginTransaction();
try {
    // One redemption per token. The hash is of the token, so replaying the same
    // token for a second target fails -- but nothing here records WHO redeemed it,
    // and nothing ever should.
    $tokenHash = hash('sha256', $token);
    $stmt = $db->prepare('SELECT 1 FROM spent_tokens WHERE game_id=? AND token_hash=? FOR UPDATE');
    $stmt->execute([$gameId, $tokenHash]);
    if ($stmt->fetchColumn()) {
        $db->rollBack();
        sh_bad('token already spent', 409);
    }
    $db->prepare('INSERT INTO spent_tokens (game_id, token_hash, night_no, target_user_id, ephemeral_pub) VALUES (?,?,?,?,?)')
       ->execute([$gameId, $tokenHash, $night, $target, $ephPub]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

sh_out(['ok' => true, 'queued' => true]);
