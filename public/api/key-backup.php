<?php
/**
 * Stores an opt-in, password-wrapped copy of a player's anon private keys, for
 * the "I cleared my browser mid-game" case.
 *
 * This row DOES tie a user_id to their (encrypted) identity, and is the weakest
 * link in the design -- a weak passphrase plus an offline attack deanonymises
 * that player. The client wraps with PBKDF2-SHA256 at 600k iterations and the
 * passphrase never reaches here, but the honest framing is that this trades a
 * little privacy for recoverability and the UI says so.
 */
require_once __DIR__ . '/_boot.php';

$user = require_api_login();
$in = sh_api_in();
$gameId = (int) ($in['game'] ?? 0);
sh_require_seat($user, $gameId);

$blob = (string) ($in['wrapped'] ?? '');
// A plausible wrapped identity is well over 300 bytes of base64url; anything
// short is a bug or a client sending the wrong field.
if (strlen($blob) < 300 || strlen($blob) > 8000 || !preg_match('/^[A-Za-z0-9_-]+$/', $blob)) {
    sh_api_fail('malformed backup blob');
}

db()->prepare(
    'INSERT INTO key_backups (game_id, user_id, wrapped_blob) VALUES (?,?,?)
     ON DUPLICATE KEY UPDATE wrapped_blob=VALUES(wrapped_blob)'
)->execute([$gameId, $user['id'], $blob]);

sh_api_out(['ok' => true]);
