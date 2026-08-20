<?php
/**
 * Step 5 of PROTOCOL.md sec 4: present an unblinded credential and claim an anon
 * identity. Unauthenticated on purpose -- the credential proves the holder is one
 * of this game's players without saying which one, and that is exactly as much as
 * the server is allowed to learn.
 */
require_once __DIR__ . '/_boot.php';

$in = sh_json_in();
$gameId = (int) ($in['game'] ?? 0);
$idkPub = (string) ($in['idk'] ?? '');
$sigkPub = (string) ($in['sigk'] ?? '');
$credential = (string) ($in['credential'] ?? '');

$game = sh_game($gameId);
if ($game === null || $game['status'] !== 'registration') {
    sh_bad('registration is not open');
}
if (sh_p256_pub_pem($idkPub) === null || sh_p256_pub_pem($sigkPub) === null) {
    sh_bad('malformed public key');
}

$anonPubJson = sh_anon_pub_json($idkPub, $sigkPub);
if (!sh_verify_credential($game['cred_d'], $anonPubJson, $credential)) {
    sh_bad('invalid credential', 403);
}

try {
    db()->prepare(
        'INSERT INTO anon_slots (game_id, idk_pub, sigk_pub, pub_hash, credential_sig) VALUES (?,?,?,?,?)'
    )->execute([$gameId, $idkPub, $sigkPub, sh_pub_hash($anonPubJson), $credential]);
} catch (PDOException $e) {
    // Same keys presented twice. Idempotent rather than an error: a client that
    // retried after a dropped response must not be locked out of its own slot.
    if ($e->getCode() !== '23000') {
        throw $e;
    }
}

// Deals as soon as the last identity lands, so nobody has to wait for a poll.
sh_close_registration($gameId);

sh_out(['ok' => true, 'registered' => sh_registered_count($gameId), 'needed' => (int) $game['num_seats']]);
