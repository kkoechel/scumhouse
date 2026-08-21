<?php
/**
 * Search for role tables that give both sides a real chance.
 *
 * tools/balance-floor.php showed the shipped setups pay the mafia 64.8% at five
 * seats and 94.8% at ten, before anyone plays well. tools/vote-rule.php showed
 * the town's best available rule is to agree on a shared target no player picks.
 * This puts those together: hold the town to that rule, then sweep compositions
 * and report which ones land near an even game.
 *
 * The cop is modelled, and has to be. With every decision random, an
 * information role changes nothing that happens, so a pure-chance sweep prices
 * COP, TRACKER and WATCHER at exactly zero and would happily recommend deleting
 * them. So the cop here does what a real one does: investigates a living player
 * it has not checked, and if it finds a mafia the town lynches that player the
 * next day. That is precisely what bot/strategy.mjs does with a read.
 *
 * The tracker and watcher are still NOT modelled, so any composition leaning on
 * them is scored as if those seats were vanilla town. Read their rows as a lower
 * bound rather than a verdict.
 *
 * The engine's own sh_tally_votes and sh_resolve_night do the work, so the tie
 * rule and night resolution are the live ones. The win check is reimplemented
 * only because sh_check_winner reads the mafia count from sh_setup(), which is
 * the very thing being varied here -- the logic below is otherwise identical,
 * including mafia winning on parity rather than on elimination.
 *
 *   php tools/balance-search.php [games-per-candidate]
 */
require __DIR__ . '/../inc/engine.php';

$games = (int) ($argv[1] ?? 1200);
mt_srand(20260820);

const POWERS = ['COP', 'DOCTOR', 'VIGILANTE', 'ROLEBLOCKER', 'TRACKER', 'WATCHER'];

function winner(int $mafiaTotal, int $living, int $flippedMafia): ?string
{
    $livingMafia = $mafiaTotal - $flippedMafia;
    if ($livingMafia <= 0) return 'TOWN';
    if ($livingMafia >= $living - $livingMafia) return 'MAFIA';
    return null;
}

/** One game under the shared-rule town. $comp maps role => count. */
function playComp(array $comp): string
{
    $roles = [];
    foreach ($comp as $r => $c) for ($i = 0; $i < $c; $i++) $roles[] = $r;
    $n = count($roles);
    shuffle($roles);
    $role = [];
    foreach ($roles as $i => $r) $role[$i + 1] = $r;

    $mafiaTotal = $comp['MAFIA'];
    $alive = range(1, $n);
    $deadMafia = 0;
    $prevProtect = null;
    $known = [];          // slots the cop has proved mafia
    $checked = [];        // slots the cop has already spent a night on

    for ($phase = 1; $phase <= 60; $phase++) {
        if (count($alive) > 1) {
            $mafia = array_values(array_filter($alive, fn($s) => $role[$s] === 'MAFIA'));
            $town  = array_values(array_filter($alive, fn($s) => $role[$s] !== 'MAFIA'));
            $votes = [];
            if ($town) { $bloc = $town[array_rand($town)]; foreach ($mafia as $m) $votes[$m] = $bloc; }
            // A proved mafia outranks the shared rule: this is the cop claiming
            // and the town acting on it.
            $proved = array_values(array_intersect($known, $alive));
            $pick = $proved ? $proved[0] : $alive[array_rand($alive)];
            foreach ($town as $t) {
                if ($pick !== $t) { $votes[$t] = $pick; continue; }
                $o = array_values(array_diff($alive, [$t]));
                $votes[$t] = $o[array_rand($o)];
            }
            $tal = sh_tally_votes($votes, count($alive));
            $lynch = $tal['hammered'] ? $tal['leader'] : $tal['deadline_lynch'];
            if ($lynch !== null) {
                $alive = array_values(array_diff($alive, [$lynch]));
                if ($role[$lynch] === 'MAFIA') $deadMafia++;
            }
        }
        $w = winner($mafiaTotal, count($alive), $deadMafia);
        if ($w !== null) return $w;

        $actions = [];
        $mafia = array_values(array_filter($alive, fn($s) => $role[$s] === 'MAFIA'));
        $prey  = array_values(array_filter($alive, fn($s) => $role[$s] !== 'MAFIA'));
        if ($mafia && $prey) $actions[] = ['slot' => $mafia[0], 'role' => 'MAFIA', 'action' => 'kill', 'target' => $prey[array_rand($prey)]];
        foreach ($alive as $s) {
            $o = array_values(array_diff($alive, [$s]));
            if ($role[$s] === 'COP') {
                $pool = array_values(array_diff($alive, $checked, [$s]));
                if ($pool) {
                    $t = $pool[array_rand($pool)];
                    $checked[] = $t;
                    if ($role[$t] === 'MAFIA') $known[] = $t;
                }
            }
            elseif ($role[$s] === 'DOCTOR')      $actions[] = ['slot' => $s, 'role' => 'DOCTOR', 'action' => 'protect', 'target' => $alive[array_rand($alive)]];
            elseif ($role[$s] === 'VIGILANTE' && $o) $actions[] = ['slot' => $s, 'role' => 'VIGILANTE', 'action' => 'vigkill', 'target' => $o[array_rand($o)]];
            elseif ($role[$s] === 'ROLEBLOCKER' && $o) $actions[] = ['slot' => $s, 'role' => 'ROLEBLOCKER', 'action' => 'block', 'target' => null, 'target_slot' => $o[array_rand($o)]];
        }
        $r = sh_resolve_night($actions, $prevProtect);
        $prevProtect = $r['protected'];
        foreach ($r['deaths'] as $d) {
            if (!in_array($d, $alive, true)) continue;
            $alive = array_values(array_diff($alive, [$d]));
            if ($role[$d] === 'MAFIA') $deadMafia++;
        }
        $w = winner($mafiaTotal, count($alive), $deadMafia);
        if ($w !== null) return $w;
    }
    return 'MAFIA';
}

function score(array $comp, int $games): float
{
    $m = 0;
    for ($i = 0; $i < $games; $i++) if (playComp($comp) === 'MAFIA') $m++;
    return 100.0 * $m / $games;
}

function label(array $comp): string
{
    $p = [];
    foreach ($comp as $r => $c) if ($c > 0) $p[] = "$c " . strtolower($r);
    return implode(', ', $p);
}

echo "town uses the shared-rule vote; $games games per candidate\n";
echo "target is 50% -- an even game\n\n";

foreach ([5, 6, 7, 8, 9, 10] as $n) {
    $current = sh_setup($n);
    $curPct = score($current, $games);
    $results = [];
    for ($mask = 0; $mask < (1 << count(POWERS)); $mask++) {
        $powers = [];
        foreach (POWERS as $bit => $p) if ($mask & (1 << $bit)) $powers[] = $p;
        for ($maf = 1; $maf <= 3; $maf++) {
            $vanilla = $n - $maf - count($powers);
            if ($vanilla < 1) continue;                 // always keep some plain town
            if ($maf * 2 >= $n) continue;               // mafia already at parity on day one
            $comp = array_fill_keys(SH_ROLES, 0);
            $comp['MAFIA'] = $maf;
            foreach ($powers as $p) $comp[$p] = 1;
            $comp['TOWN'] = $vanilla;
            $results[] = ['pct' => score($comp, $games), 'comp' => $comp];
        }
    }
    usort($results, fn($a, $b) => abs($a['pct'] - 50) <=> abs($b['pct'] - 50));
    printf("== %d seats ==\n", $n);
    printf("   shipped   %-52s %.1f%% mafia\n", label($current), $curPct);
    foreach (array_slice($results, 0, 3) as $r) {
        printf("   candidate %-52s %.1f%% mafia\n", label($r['comp']), $r['pct']);
    }
    echo "\n";
}
