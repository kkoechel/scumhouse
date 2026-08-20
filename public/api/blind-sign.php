<?php
/**
 * Step 3 of PROTOCOL.md sec 4. The ONE authenticated step of the credential
 * dance: the server confirms this account is a seated player and has not drawn
 * a credential yet, then signs a value it cannot read.
 *
 * What it stores is only THAT the credential was issued -- never the blinded
 * value. Storing that value would let anyone holding cred_d re-derive the
 * account/identity link later, which is precisely what blinding prevents.
 */
require_once __DIR__ . '/_boot.php';

$user = require_api_login();
$in = sh_api_in();
$gameId = (int) ($in['game'] ?? 0);
[$game] = sh_require_seat($user, $gameId);

if ($game['status'] !== 'registration') {
    sh_api_fail('registration is not open');
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('SELECT 1 FROM game_credentials WHERE game_id=? AND user_id=? FOR UPDATE');
    $stmt->execute([$gameId, $user['id']]);
    if ($stmt->fetchColumn()) {
        $db->rollBack();
        // One per player, ever. A second signature would let one person claim two
        // anon slots and read a team channel they were never dealt into.
        sh_api_fail('you have already drawn your credential for this game', 409);
    }
    $sig = sh_blind_sign($game['cred_d'], (string) ($in['blinded'] ?? ''));
    if ($sig === null) {
        $db->rollBack();
        sh_api_fail('malformed blinded value');
    }
    $db->prepare('INSERT INTO game_credentials (game_id, user_id) VALUES (?,?)')->execute([$gameId, $user['id']]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

sh_api_out(['ok' => true, 'blind_sig' => $sig]);
