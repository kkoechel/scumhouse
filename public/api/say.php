<?php
/** A public day-thread post. Attributed, unencrypted, and that is the point --
 * this is the game being played out loud. */
require_once __DIR__ . '/_boot.php';

$user = require_login();
$in = sh_api_in();
$gameId = (int) ($in['game'] ?? 0);
[$game, $seat] = sh_require_seat($user, $gameId);

$body = trim((string) ($in['body'] ?? ''));
if ($body === '') {
    sh_api_fail('empty post');
}
if (mb_strlen($body) > 4000) {
    sh_api_fail('post too long (4000 characters max)');
}
if ($game['status'] !== 'active' || $game['phase'] !== 'day') {
    sh_api_fail('the town only talks during the day');
}
if ((int) $seat['is_alive'] !== 1) {
    sh_api_fail('the dead do not speak', 403);
}

db()->prepare('INSERT INTO day_posts (game_id, phase_no, user_id, body) VALUES (?,?,?,?)')
    ->execute([$gameId, (int) $game['phase_no'], $user['id'], $body]);

sh_api_out(['ok' => true]);
