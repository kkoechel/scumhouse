<?php
/**
 * What does each setup give the mafia before anyone plays well?
 *
 * A bot win rate on its own says nothing, because it has no scale. If a table of
 * bots hands the mafia 82% of games, that is either a strategy problem or it is
 * simply what the role table pays out when nobody deduces anything -- and those
 * two have completely different fixes. This measures the second one.
 *
 * Every seat here decides uniformly at random: town lynches a random living
 * player, the mafia kill a random living townie, the doctor protects at random,
 * the vigilante shoots at random, the roleblocker blocks a random slot. Nobody
 * investigates, because a floor is defined by information not being used. What
 * is NOT random is the machinery -- sh_setup, sh_tally_votes, sh_resolve_night
 * and sh_check_winner are the real ones, so the composition, the tie rule and the
 * parity condition are exactly what a live game uses.
 *
 * Read the output as: this is what a strategy has to beat to be worth anything.
 *
 *   php tools/balance-floor.php [games-per-seatcount]
 */
require __DIR__ . '/../inc/engine.php';

$games = (int) ($argv[1] ?? 20000);
mt_srand(20260820);

/**
 * One fully random game. Returns 'MAFIA' or 'TOWN'.
 *
 * $consolidated picks apart the two things a scattered vote does at once. When
 * false, every seat votes independently at random and a tie is a no-lynch --
 * so the town both picks badly AND often fails to lynch at all. When true the
 * town still picks at random but agrees, so a lynch always lands. The gap
 * between the two columns is the entire value of agreeing, with the quality of
 * the choice held fixed.
 */
function play(int $n, bool $consolidated = false, ?array &$stats = null): string
{
    $roles = sh_setup_role_list($n);
    shuffle($roles);
    $role = [];                                   // slot => role
    foreach ($roles as $i => $r) $role[$i + 1] = $r;

    $alive = range(1, $n);
    $deadMafia = 0;
    $prevProtect = null;

    for ($phase = 1; $phase <= 60; $phase++) {
        /* ---- day: everyone votes at random ---- */
        if (count($alive) > 1) {
            $votes = [];
            if ($consolidated) {
                $pick = $alive[array_rand($alive)];
                foreach ($alive as $v) $votes[$v] = ($v === $pick) ? $alive[array_rand($alive)] : $pick;
            } else {
                foreach ($alive as $v) {
                    $others = array_values(array_diff($alive, [$v]));
                    $votes[$v] = $others[array_rand($others)];
                }
            }
            $t = sh_tally_votes($votes, count($alive));
            $lynch = $t['hammered'] ? $t['leader'] : $t['deadline_lynch'];
            if ($lynch !== null) {
                $alive = array_values(array_diff($alive, [$lynch]));
                if ($stats !== null) {
                    $stats['lynches']++;
                    if ($role[$lynch] === 'MAFIA') $stats['hits']++;
                }
                if ($role[$lynch] === 'MAFIA') $deadMafia++;
            } elseif ($stats !== null) {
                $stats['nolynch']++;
            }
        }
        $w = sh_check_winner($n, count($alive), $deadMafia);
        if ($w !== null) return $w;

        /* ---- night: every role acts at random ---- */
        $actions = [];
        $mafia = array_values(array_filter($alive, fn($s) => $role[$s] === 'MAFIA'));
        $prey  = array_values(array_filter($alive, fn($s) => $role[$s] !== 'MAFIA'));
        if ($mafia && $prey) {
            $actions[] = ['slot' => $mafia[0], 'role' => 'MAFIA', 'action' => 'kill',
                          'target' => $prey[array_rand($prey)]];
        }
        foreach ($alive as $s) {
            $others = array_values(array_diff($alive, [$s]));
            if ($role[$s] === 'DOCTOR') {
                $actions[] = ['slot' => $s, 'role' => 'DOCTOR', 'action' => 'protect',
                              'target' => $alive[array_rand($alive)]];
            } elseif ($role[$s] === 'VIGILANTE' && $others) {
                $actions[] = ['slot' => $s, 'role' => 'VIGILANTE', 'action' => 'vigkill',
                              'target' => $others[array_rand($others)]];
            } elseif ($role[$s] === 'ROLEBLOCKER' && $others) {
                $actions[] = ['slot' => $s, 'role' => 'ROLEBLOCKER', 'action' => 'block',
                              'target' => null, 'target_slot' => $others[array_rand($others)]];
            }
        }
        $r = sh_resolve_night($actions, $prevProtect);
        $prevProtect = $r['protected'];
        foreach ($r['deaths'] as $d) {
            if (!in_array($d, $alive, true)) continue;
            $alive = array_values(array_diff($alive, [$d]));
            if ($role[$d] === 'MAFIA') $deadMafia++;
        }
        $w = sh_check_winner($n, count($alive), $deadMafia);
        if ($w !== null) return $w;
    }
    return 'MAFIA';   // a stalled game is a mafia win by parity eventually
}

printf("random-play floor, %d games per seat count\n\n", $games);
$acc = [];
printf("%-6s %-46s %-11s %-13s %s\n", 'seats', 'composition', 'mafia% (scattered)', 'mafia% (agreed)', 'agreeing gains town');
foreach ([5, 6, 7, 8, 9, 10] as $n) {
    $ms = 0; $mc = 0;
    $st = ['lynches' => 0, 'hits' => 0, 'nolynch' => 0];
    for ($i = 0; $i < $games; $i++) if (play($n, false, $st) === 'MAFIA') $ms++;
    for ($i = 0; $i < $games; $i++) if (play($n, true) === 'MAFIA') $mc++;
    $acc[$n] = $st;
    $setup = array_filter(sh_setup($n));
    $desc = [];
    foreach ($setup as $r => $c) $desc[] = "$c " . strtolower($r);
    printf("%-6d %-46s %-18.1f %-15.1f %+.1f pp\n",
        $n, implode(', ', $desc), 100 * $ms / $games, 100 * $mc / $games,
        100 * ($ms - $mc) / $games);
}

/* The number a strategy has to beat. A town choosing at random still finds a
 * mafia this often, simply because some of the people it can pick are mafia.
 * Scoring BELOW this line does not mean a strategy is merely weak -- it means
 * something is steering the vote away from the mafia, and the obvious candidate
 * is the mafia, who vote too. */
echo "\nrandom lynch accuracy (the line a strategy must beat)\n\n";
printf("%-6s %-10s %-12s %s\n", 'seats', 'lynches', 'hit mafia', 'no-lynch days');
foreach ($acc as $n => $st) {
    printf("%-6d %-10d %-12s %d\n", $n, $st['lynches'],
        sprintf('%.1f%%', $st['lynches'] ? 100 * $st['hits'] / $st['lynches'] : 0), $st['nolynch']);
}
