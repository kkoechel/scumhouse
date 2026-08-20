<?php
/**
 * Fast-forwards a test game's clock.
 *
 *   tick-game.php <gameId> release   -- expire the night's key release point
 *   tick-game.php <gameId> phase     -- expire the phase deadline, then tick
 *   tick-game.php <gameId> status    -- print one line of state
 *
 * Test-only. Real games advance on wall-clock deadlines; a test cannot wait
 * forty-eight hours for a day phase.
 */
require_once dirname(__DIR__) . '/inc/game_state.php';

$gameId = (int) ($argv[1] ?? 0);
$what = $argv[2] ?? 'status';

if ($what === 'release') {
    db()->prepare('UPDATE games SET key_release_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id=?')->execute([$gameId]);
} elseif ($what === 'phase') {
    db()->prepare('UPDATE games SET phase_ends_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id=?')->execute([$gameId]);
    sh_tick($gameId);
}

$g = sh_game($gameId);
$living = count(sh_living($gameId));
$pending = array_column(sh_pending_flips($gameId), 'display_name');
printf(
    "%s%s living=%d flipped=%d%s%s\n",
    $g['status'],
    $g['phase'] ? " {$g['phase']}{$g['phase_no']}" : '',
    $living,
    (int) db()->query("SELECT COUNT(*) FROM flips WHERE game_id={$gameId}")->fetchColumn(),
    $g['winner_faction'] ? " winner={$g['winner_faction']}" : '',
    $pending ? ' PENDING_FLIP=' . implode(',', $pending) : ''
);
