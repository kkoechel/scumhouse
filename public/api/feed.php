<?php
/**
 * Everything the client needs to render, in one poll.
 *
 * Reading this is safe for anyone in the game: it is public state plus opaque
 * ciphertext. It must NEVER include slot_roles -- the server's copy of the deal
 * is the one thing that, combined with a leaked slot index, would deanonymise a
 * live player.
 */
require_once __DIR__ . '/_boot.php';

$user = require_api_login();
$gameId = (int) ($_GET['game'] ?? 0);
[$game] = sh_require_seat($user, $gameId);

sh_tick($gameId);
$game = sh_game($gameId);

$players = [];
foreach (sh_players($gameId) as $p) {
    $players[] = [
        'user_id' => (int) $p['user_id'],
        'name' => $p['display_name'],
        'seat' => (int) $p['seat_order'],
        'alive' => (int) $p['is_alive'] === 1,
        'died_phase_no' => $p['died_phase_no'] === null ? null : (int) $p['died_phase_no'],
        'died_cause' => $p['died_cause'],
    ];
}

$stmt = db()->prepare('SELECT slot_index, ciphertext, phase_no, phase FROM anon_posts WHERE game_id=? AND visible_at <= NOW() ORDER BY id ASC');
$stmt->execute([$gameId]);
$channel = $stmt->fetchAll();

$stmt = db()->prepare('SELECT slot_index, ciphertext FROM sealed_cards WHERE game_id=? ORDER BY slot_index');
$stmt->execute([$gameId]);
$cards = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT d.phase_no, d.body, d.created_at, u.display_name, d.user_id
       FROM day_posts d JOIN users u ON u.id=d.user_id
      WHERE d.game_id=? ORDER BY d.id ASC'
);
$stmt->execute([$gameId]);
$thread = $stmt->fetchAll();

$stmt = db()->prepare('SELECT voter_user_id, target_user_id FROM votes WHERE game_id=? AND phase_no=?');
$stmt->execute([$gameId, (int) $game['phase_no']]);
$votes = $stmt->fetchAll();

$stmt = db()->prepare('SELECT slot_index, user_id, role FROM flips WHERE game_id=?');
$stmt->execute([$gameId]);
$flips = $stmt->fetchAll();

// Envelopes are opaque to everyone but an investigator, so shipping them all to
// everyone costs nothing and saves a second round trip. The INNER keys are not
// here and never will be -- those come one at a time, through a spent token.
$stmt = db()->prepare('SELECT user_id, investigator_slot, ciphertext FROM role_envelopes WHERE game_id=?');
$stmt->execute([$gameId]);
$envelopes = $stmt->fetchAll();

$stmt = db()->prepare('SELECT slot_index, ciphertext FROM sealed_role_table WHERE game_id=?');
$stmt->execute([$gameId]);
$roleTable = $stmt->fetchAll();

// Reverse envelopes and account keys are public: the envelopes are opaque without
// the watcher's key AND an inner key, and an account key names no slot.
$stmt = db()->prepare('SELECT slot_index, ciphertext FROM reverse_envelopes WHERE game_id=?');
$stmt->execute([$gameId]);
$reverseEnvelopes = $stmt->fetchAll();

$stmt = db()->prepare('SELECT user_id, sigk_pub FROM account_keys WHERE game_id=?');
$stmt->execute([$gameId]);
$accountKeys = $stmt->fetchAll();

$stmt = db()->prepare('SELECT slot_index, night_no, ciphertext FROM watcher_reports WHERE game_id=?');
$stmt->execute([$gameId]);
$watcherReports = $stmt->fetchAll();

// Forced-flip escrow. All opaque without a slot private key, so shipping it to
// everyone costs nothing and lets any client reconstruct once enough shares open.
$stmt = db()->prepare('SELECT user_id, ciphertext FROM flip_blobs WHERE game_id=?');
$stmt->execute([$gameId]);
$flipBlobs = $stmt->fetchAll();

$stmt = db()->prepare('SELECT subject_user_id, holder_slot, ciphertext FROM flip_shares WHERE game_id=?');
$stmt->execute([$gameId]);
$flipShares = $stmt->fetchAll();

$stmt = db()->prepare('SELECT subject_user_id, holder_slot, share FROM flip_share_reveals WHERE game_id=?');
$stmt->execute([$gameId]);
$flipReveals = $stmt->fetchAll();

$stmt = db()->prepare('SELECT 1 FROM flip_blobs WHERE game_id=? AND user_id=?');
$stmt->execute([$gameId, $user['id']]);
$myFlipEscrowed = (bool) $stmt->fetchColumn();

$stmt = db()->prepare('SELECT 1 FROM account_keys WHERE game_id=? AND user_id=?');
$stmt->execute([$gameId, $user['id']]);
$myAccountKeyPublished = (bool) $stmt->fetchColumn();

$stmt = db()->prepare('SELECT slot_index, night_no, ciphertext FROM tracker_reports WHERE game_id=?');
$stmt->execute([$gameId]);
$trackerReports = $stmt->fetchAll();

$stmt = db()->prepare('SELECT 1 FROM envelope_keys WHERE game_id=? AND user_id=?');
$stmt->execute([$gameId, $user['id']]);
$myEnvelopePublished = (bool) $stmt->fetchColumn();

$stmt = db()->prepare('SELECT wrapped_blob FROM key_backups WHERE game_id=? AND user_id=?');
$stmt->execute([$gameId, $user['id']]);
$backup = $stmt->fetchColumn();

sh_api_out([
    'ok' => true,
    'me' => (int) $user['id'],
    'game' => [
        'id' => (int) $game['id'],
        'status' => $game['status'],
        'num_seats' => (int) $game['num_seats'],
        'setup' => json_decode($game['setup_json'], true),
        'phase' => $game['phase'],
        'phase_no' => (int) $game['phase_no'],
        'phase_ends_at' => $game['phase_ends_at'],
        'key_release_at' => $game['key_release_at'],
        'keys_released' => sh_keys_released($game),
        'winner' => $game['winner_faction'],
        'cred_n' => $game['cred_n'],
        'cred_e' => $game['cred_e'],
    ],
    'registered' => sh_registered_count($gameId),
    'players' => $players,
    'slots' => sh_slots($gameId),
    'cards' => $cards,
    'channel' => $channel,
    'thread' => $thread,
    'votes' => $votes,
    'flips' => $flips,
    'investigator_slots' => sh_investigator_slot_list($gameId),
    'envelopes' => $envelopes,
    'role_table' => $roleTable,
    'my_envelope_published' => $myEnvelopePublished,
    'tracker_reports' => $trackerReports,
    'reverse_slots' => sh_reverse_slot_list($gameId),
    'reverse_envelopes' => $reverseEnvelopes,
    'account_keys' => $accountKeys,
    'watcher_reports' => $watcherReports,
    'my_account_key_published' => $myAccountKeyPublished,
    'flip_blobs' => $flipBlobs,
    'flip_shares' => $flipShares,
    'flip_reveals' => $flipReveals,
    'my_flip_escrowed' => $myFlipEscrowed,
    'pending_flips' => sh_pending_flips($gameId),
    'key_backup' => $backup === false ? null : $backup,
]);
