<?php
/**
 * Seeds a throwaway game for tests/play-game.sh: N users, an API token each, and
 * a table with everyone seated so registration opens immediately.
 *
 * Test-only -- it mints credentials directly, which nothing in the real app does.
 */
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/game_state.php';

$seats = (int) ($argv[1] ?? 5);
$out = $argv[2] ?? '/tmp/scumhouse-play/seats.json';

$db = db();
$db->exec('DELETE FROM api_tokens');

// Users first: games.created_by is a foreign key, so the table cannot exist
// before its creator does.
$users = [];
for ($i = 0; $i < $seats; $i++) {
    $email = "bot{$i}@example.com";
    $db->prepare('INSERT IGNORE INTO allowed_emails (email) VALUES (?)')->execute([$email]);
    $db->prepare('INSERT IGNORE INTO users (email, display_name) VALUES (?,?)')->execute([$email, 'bot' . $i]);
    $stmt = $db->prepare('SELECT id FROM users WHERE email=?');
    $stmt->execute([$email]);
    $users[] = ['name' => 'bot' . $i, 'user_id' => (int) $stmt->fetchColumn()];
}

$db->prepare('INSERT INTO games (status, num_seats, setup_json, day_hours, night_hours, created_by)
              VALUES (?,?,?,?,?,?)')
   ->execute(['lobby', $seats, json_encode(sh_setup($seats)), 48, 24, $users[0]['user_id']]);
$gameId = (int) $db->lastInsertId();

foreach ($users as $i => $u) {
    $db->prepare('INSERT INTO game_players (game_id, user_id, seat_order) VALUES (?,?,?)')
       ->execute([$gameId, $u['user_id'], $i]);
    $users[$i]['token'] = issue_api_token($u['user_id'], 'bot');
}

// Last seat filled: mint the credential key and open anonymous registration.
sh_open_registration($gameId);

@mkdir(dirname($out), 0777, true);
file_put_contents($out, json_encode(['game' => $gameId, 'seats' => $users], JSON_PRETTY_PRINT));
echo "seeded game $gameId with $seats seats\n";
