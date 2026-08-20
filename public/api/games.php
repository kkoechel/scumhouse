<?php
/**
 * The games this player is seated in. Exists so a client that did not come from
 * this server has somewhere to start -- the hosted lobby is a rendered page, and
 * a local client needs the same information as data.
 */
require_once __DIR__ . '/_boot.php';

$user = require_api_login();

$stmt = db()->prepare(
    'SELECT g.id, g.status, g.num_seats, g.phase, g.phase_no, g.phase_ends_at,
            gp.is_alive,
            (SELECT COUNT(*) FROM game_players x WHERE x.game_id = g.id) AS seated
       FROM games g
       JOIN game_players gp ON gp.game_id = g.id AND gp.user_id = ?
   ORDER BY g.id DESC LIMIT 50'
);
$stmt->execute([$user['id']]);

$games = [];
foreach ($stmt->fetchAll() as $g) {
    $games[] = [
        'id' => (int) $g['id'],
        'status' => $g['status'],
        'num_seats' => (int) $g['num_seats'],
        'seated' => (int) $g['seated'],
        'phase' => $g['phase'],
        'phase_no' => (int) $g['phase_no'],
        'alive' => (int) $g['is_alive'] === 1,
    ];
}

sh_api_out(['ok' => true, 'me' => (int) $user['id'], 'games' => $games]);
