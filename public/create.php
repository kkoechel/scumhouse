<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/game_state.php';

$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_PATH . '/lobby.php');
    exit;
}

$numSeats = (int) ($_POST['num_seats'] ?? 7);
$dayHours = (int) ($_POST['day_hours'] ?? 48);
$nightHours = (int) ($_POST['night_hours'] ?? 24);
if (!in_array($numSeats, [5, 6, 7, 8, 9, 10], true)) {
    http_response_code(400);
    exit('Unsupported table size.');
}
if (!in_array($dayHours, [24, 48, 72], true) || !in_array($nightHours, [12, 24, 48], true)) {
    http_response_code(400);
    exit('Unsupported phase length.');
}

$db = db();
$db->prepare(
    'INSERT INTO games (status, num_seats, setup_json, day_hours, night_hours, created_by)
     VALUES (?,?,?,?,?,?)'
)->execute(['lobby', $numSeats, json_encode(sh_setup($numSeats)), $dayHours, $nightHours, $user['id']]);
$gameId = (int) $db->lastInsertId();

$db->prepare('INSERT INTO game_players (game_id, user_id, seat_order) VALUES (?,?,0)')
   ->execute([$gameId, $user['id']]);

header('Location: ' . APP_PATH . '/game.php?game=' . $gameId);
