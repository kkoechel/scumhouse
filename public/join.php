<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/game_state.php';

$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_PATH . '/lobby.php');
    exit;
}
$gameId = (int) ($_POST['game_id'] ?? 0);

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('SELECT * FROM games WHERE id=? FOR UPDATE');
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game || $game['status'] !== 'lobby') {
        $db->rollBack();
        header('Location: ' . APP_PATH . '/lobby.php');
        exit;
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM game_players WHERE game_id=?');
    $stmt->execute([$gameId]);
    $seated = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT 1 FROM game_players WHERE game_id=? AND user_id=?');
    $stmt->execute([$gameId, $user['id']]);
    if (!$stmt->fetchColumn()) {
        if ($seated >= (int) $game['num_seats']) {
            $db->rollBack();
            header('Location: ' . APP_PATH . '/lobby.php');
            exit;
        }
        $db->prepare('INSERT INTO game_players (game_id, user_id, seat_order) VALUES (?,?,?)')
           ->execute([$gameId, $user['id'], $seated]);
        $seated++;
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

// Last seat filled: mint the credential key and open anonymous registration.
if ($seated >= (int) $game['num_seats']) {
    sh_open_registration($gameId);
}

header('Location: ' . APP_PATH . '/game.php?game=' . $gameId);
