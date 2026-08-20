<?php
/**
 * Releases every envelope-key answer for the night, all at once, once the fixed
 * release point has passed (PROTOCOL.md sec 5.3).
 *
 * Deliberately returns the WHOLE batch to whoever asks. Each answer is sealed to
 * the one-use key its asker supplied, so a client learns only its own by trial
 * decryption -- and fetching the batch says nothing about which one is yours.
 * Handing each client "its" answer would have required knowing which one that was.
 */
require_once __DIR__ . '/_boot.php';

$in = sh_json_in();
$gameId = (int) ($in['game'] ?? 0);
$night = (int) ($in['night'] ?? 0);

$game = sh_game($gameId);
if ($game === null || $game['status'] !== 'active') {
    sh_bad('game is not running');
}
if ($night !== (int) $game['phase_no'] || $game['phase'] !== 'night') {
    sh_bad('that night is not in progress');
}
if (!sh_keys_released($game)) {
    // Not an error -- clients poll for this. Say when to come back.
    sh_out(['ok' => true, 'released' => false, 'release_at' => $game['key_release_at'], 'answers' => []]);
}

$stmt = db()->prepare(
    'SELECT s.target_user_id, s.ephemeral_pub, k.inner_key
       FROM spent_tokens s
       JOIN envelope_keys k ON k.game_id = s.game_id AND k.user_id = s.target_user_id
      WHERE s.game_id = ? AND s.night_no = ?'
);
$stmt->execute([$gameId, $night]);

// Sealed on read rather than stored sealed: the ciphertext is deterministic in
// nothing an observer can use, and this keeps a night's answers out of the
// database entirely until somebody actually asks for them.
$answers = [];
foreach ($stmt->fetchAll() as $row) {
    $sealed = sh_ecies_seal($row['ephemeral_pub'], [
        'inner_key' => $row['inner_key'],
        'target' => (int) $row['target_user_id'],
    ], SH_KEY_INFO);
    if ($sealed !== null) {
        $answers[] = $sealed;
    }
}
// Order must not track redemption order -- spent_tokens deliberately has no
// timestamp, but MySQL is free to return rows in insertion order regardless.
shuffle($answers);

sh_out(['ok' => true, 'released' => true, 'answers' => $answers]);
