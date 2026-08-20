<?php
/**
 * Standard bug report for this repo, with one game-specific rule: the snapshot is
 * built HERE, from server state only. A client-supplied snapshot would happily
 * include the reporter's decrypted card, turning the bug form into a role leak
 * and an admin into an accidental spectator.
 */
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/game_state.php';

$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_PATH . '/lobby.php');
    exit;
}

$gameId = (int) ($_POST['game_id'] ?? 0);
$description = trim((string) ($_POST['description'] ?? ''));

$stmt = db()->prepare('SELECT 1 FROM game_players WHERE game_id=? AND user_id=?');
$stmt->execute([$gameId, $user['id']]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    exit("You're not a player in this game.");
}
if ($description === '') {
    header('Location: ' . APP_PATH . '/game.php?game=' . $gameId);
    exit;
}
$description = mb_substr($description, 0, 2000);

$game = sh_game($gameId);
$snapshot = [
    'status' => $game['status'],
    'phase' => $game['phase'],
    'phase_no' => (int) $game['phase_no'],
    'num_seats' => (int) $game['num_seats'],
    'phase_ends_at' => $game['phase_ends_at'],
    'registered' => sh_registered_count($gameId),
    'pending_flips' => array_column(sh_pending_flips($gameId), 'user_id'),
    'players' => array_map(fn($p) => [
        'user_id' => (int) $p['user_id'],
        'alive' => (int) $p['is_alive'],
        'died_phase_no' => $p['died_phase_no'],
        'died_cause' => $p['died_cause'],
    ], sh_players($gameId)),
];

db()->prepare(
    'INSERT INTO bug_reports (game_id, reported_by_user_id, description, state_snapshot_json) VALUES (?,?,?,?)'
)->execute([$gameId, $user['id'], $description, json_encode($snapshot)]);

header('Location: ' . APP_PATH . '/game.php?game=' . $gameId);
