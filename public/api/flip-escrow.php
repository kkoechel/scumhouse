<?php
/**
 * Publishes this player's forced-flip escrow (PROTOCOL.md sec 9): their own flip,
 * sealed under a key that is then Shamir-split among every slot.
 *
 * Authenticated, because the account label needs to be server-attested -- the
 * whole point is to prove later that THIS account held THAT slot. The shares
 * themselves are sealed to slot keys the server does not have, so publishing them
 * here reveals nothing.
 */
require_once __DIR__ . '/_boot.php';

$user = require_api_login();
$in = sh_api_in();
$gameId = (int) ($in['game'] ?? 0);
[$game] = sh_require_seat($user, $gameId);

if ($game['status'] !== 'active') {
    sh_api_fail('game is not running');
}

$blob = (string) ($in['blob'] ?? '');
$shares = $in['shares'] ?? [];
if (strlen($blob) < 50 || strlen($blob) > 4000) {
    sh_api_fail('malformed flip blob');
}
if (!is_array($shares) || count($shares) !== (int) $game['num_seats']) {
    sh_api_fail('expected exactly one share per slot');
}

$db = db();
$db->beginTransaction();
try {
    // Write-once. A second escrow would let a player swap in a different card
    // after learning what an investigation was about to prove.
    $stmt = $db->prepare('SELECT 1 FROM flip_blobs WHERE game_id=? AND user_id=? FOR UPDATE');
    $stmt->execute([$gameId, $user['id']]);
    if ($stmt->fetchColumn()) {
        $db->rollBack();
        sh_api_out(['ok' => true, 'already' => true]);
    }
    $db->prepare('INSERT INTO flip_blobs (game_id, user_id, ciphertext) VALUES (?,?,?)')
       ->execute([$gameId, $user['id'], $blob]);

    $ins = $db->prepare('INSERT INTO flip_shares (game_id, subject_user_id, holder_slot, ciphertext) VALUES (?,?,?,?)');
    foreach ($shares as $slot => $ciphertext) {
        if (!is_string($ciphertext) || strlen($ciphertext) < 50 || strlen($ciphertext) > 4000) {
            $db->rollBack();
            sh_api_fail('malformed share');
        }
        $ins->execute([$gameId, $user['id'], (int) $slot, $ciphertext]);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

sh_api_out(['ok' => true]);
