<?php
/**
 * Retention for finished games.
 *
 * About three quarters of a finished game's storage is `anon_posts`, and most of
 * that is cover traffic -- random bytes every client is required to post so that
 * real mafia chat has somewhere to hide. Once a game is over that traffic has
 * served its entire purpose and is worth exactly nothing.
 *
 * The server cannot tell cover from chat (that is the point), so this is
 * all-or-nothing per game: after the retention window, a finished game's channel
 * goes away and its page shows an empty private channel. What is KEPT is the
 * story -- the day thread, the votes, the deaths, the flips, the result.
 *
 * DRY RUN BY DEFAULT. Pass --apply to actually delete.
 *
 *   php tools/prune.php                 # report only
 *   php tools/prune.php --days=60       # different window
 *   php tools/prune.php --apply
 */

require_once __DIR__ . '/../inc/db.php';

$apply = in_array('--apply', $argv, true);
$days = 30;
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = (int) $m[1];
    }
}
if ($days < 1) {
    fwrite(STDERR, "--days must be at least 1\n");
    exit(1);
}

$db = db();

// Only games that are genuinely over AND settled. An abandoned game still gets
// pruned; a game merely marked finished a moment ago does not.
$stmt = $db->prepare(
    "SELECT id, status, finished_at, num_seats
       FROM games
      WHERE status IN ('finished','abandoned')
        AND finished_at IS NOT NULL
        AND finished_at < DATE_SUB(NOW(), INTERVAL ? DAY)
   ORDER BY id"
);
$stmt->execute([$days]);
$games = $stmt->fetchAll();

if (!$games) {
    echo "nothing older than $days days to prune\n";
    exit(0);
}

// Ephemeral tables only. `flips`, `day_posts`, `votes`, `game_players` and
// `games` are the permanent record and are never touched here.
$tables = ['anon_posts', 'spent_tokens', 'retrieval_tokens', 'anon_actions',
           'tracker_reports', 'watcher_reports', 'tracker_queries', 'watcher_queries'];

$totalRows = 0;
$totalGames = 0;

foreach ($games as $game) {
    $gameId = (int) $game['id'];
    $counts = [];
    $rows = 0;
    foreach ($tables as $table) {
        $c = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE game_id=?");
        $c->execute([$gameId]);
        $n = (int) $c->fetchColumn();
        if ($n > 0) {
            $counts[$table] = $n;
            $rows += $n;
        }
    }
    if ($rows === 0) {
        continue;
    }
    $totalRows += $rows;
    $totalGames++;

    printf(
        "game #%-5d %-10s finished %s  %6d rows  (%s)\n",
        $gameId, $game['status'], substr($game['finished_at'], 0, 10), $rows,
        implode(', ', array_map(fn($t, $n) => "$t:$n", array_keys($counts), $counts))
    );

    if ($apply) {
        $db->beginTransaction();
        try {
            foreach (array_keys($counts) as $table) {
                $db->prepare("DELETE FROM `$table` WHERE game_id=?")->execute([$gameId]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}

echo "\n";
if ($totalGames === 0) {
    echo "nothing to prune\n";
    exit(0);
}
printf(
    "%s %d rows across %d game(s)\n",
    $apply ? 'DELETED' : 'would delete',
    $totalRows,
    $totalGames
);
if (!$apply) {
    echo "dry run -- pass --apply to actually delete\n";
}
