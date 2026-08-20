<?php
/**
 * Publishes an account-bound signing key.
 *
 * The one thing here the server DOES attest: this request is authenticated, so the
 * binding between the account and the key is server-verified and public. It names
 * no slot and reveals nothing by itself -- its only job is to let a watcher check
 * that whoever wrote a slot-indexed reverse envelope also holds the account named
 * inside it (PROTOCOL.md sec 5.4).
 */
require_once __DIR__ . '/_boot.php';

$user = require_login();
$in = sh_api_in();
$gameId = (int) ($in['game'] ?? 0);
sh_require_seat($user, $gameId);

$pub = (string) ($in['sigk_pub'] ?? '');
if (sh_p256_pub_pem($pub) === null) {
    sh_api_fail('malformed public key');
}

// Write-once: a rotated account key would invalidate every reverse envelope that
// ever named this account, which is indistinguishable from repudiating them.
try {
    db()->prepare('INSERT INTO account_keys (game_id, user_id, sigk_pub) VALUES (?,?,?)')
        ->execute([$gameId, $user['id'], $pub]);
} catch (PDOException $e) {
    if ($e->getCode() !== '23000') {
        throw $e;
    }
}

sh_api_out(['ok' => true]);
