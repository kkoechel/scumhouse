<?php
/**
 * Does the town's voting RULE matter, and by how much?
 *
 * tools/balance-floor.php answers "what does the setup pay before anyone plays
 * well". This answers the next question: given that the mafia vote as a bloc --
 * which they do for free, since each of them simply avoids its own partners --
 * which town rule survives it?
 *
 * Three rules, same engine, same setups, everything else uniformly random:
 *
 *   scatter    every townie votes at random. Ties are no-lynch.
 *   plurality  townies pile onto whoever already leads. This is what the bots
 *              did, and it hands the mafia the agenda: the bloc moves first,
 *              so the bloc IS the early plurality.
 *   rule       every townie votes the same shared, arbitrary target -- one no
 *              player chooses, derived from data they all see. The mafia can
 *              join it or not; either way they cannot move it.
 *
 * The measure that matters is lynch accuracy, not the win rate: it says
 * directly how often the town's vote found a mafia.
 *
 *   php tools/vote-rule.php [games-per-seatcount]
 */
require __DIR__ . '/../inc/engine.php';

$games = (int) ($argv[1] ?? 20000);
mt_srand(20260820);

function playRule(int $n, string $rule, array &$st): string
{
    $roles = sh_setup_role_list($n);
    shuffle($roles);
    $role = [];
    foreach ($roles as $i => $r) $role[$i + 1] = $r;

    $alive = range(1, $n);
    $deadMafia = 0;
    $prevProtect = null;

    for ($phase = 1; $phase <= 60; $phase++) {
        if (count($alive) > 1) {
            $mafia = array_values(array_filter($alive, fn($s) => $role[$s] === 'MAFIA'));
            $town  = array_values(array_filter($alive, fn($s) => $role[$s] !== 'MAFIA'));
            $votes = [];

            // The mafia bloc: one town target, every mafia on it. Free to them.
            $blocTarget = $town ? $town[array_rand($town)] : null;
            foreach ($mafia as $m) if ($blocTarget !== null) $votes[$m] = $blocTarget;

            if ($rule === 'scatter') {
                foreach ($town as $t) {
                    $o = array_values(array_diff($alive, [$t]));
                    $votes[$t] = $o[array_rand($o)];
                }
            } elseif ($rule === 'plurality') {
                // Pile onto the standing leader -- which, this early, is the bloc's.
                $lead = $blocTarget;
                foreach ($town as $t) {
                    if ($lead !== null && $lead !== $t) { $votes[$t] = $lead; continue; }
                    $o = array_values(array_diff($alive, [$t]));
                    $votes[$t] = $o[array_rand($o)];
                }
            } else { // 'rule'
                // One shared arbitrary target nobody chose. Ties broken the same
                // way in every seat, which is what makes it un-steerable.
                $pick = $alive[array_rand($alive)];
                foreach ($town as $t) {
                    if ($pick !== $t) { $votes[$t] = $pick; continue; }
                    $o = array_values(array_diff($alive, [$t]));
                    $votes[$t] = $o[array_rand($o)];
                }
            }

            $tal = sh_tally_votes($votes, count($alive));
            $lynch = $tal['hammered'] ? $tal['leader'] : $tal['deadline_lynch'];
            if ($lynch !== null) {
                $st['lynches']++;
                if ($role[$lynch] === 'MAFIA') { $st['hits']++; $deadMafia++; }
                $alive = array_values(array_diff($alive, [$lynch]));
            } else {
                $st['nolynch']++;
            }
        }
        $w = sh_check_winner($n, count($alive), $deadMafia);
        if ($w !== null) return $w;

        $actions = [];
        $mafia = array_values(array_filter($alive, fn($s) => $role[$s] === 'MAFIA'));
        $prey  = array_values(array_filter($alive, fn($s) => $role[$s] !== 'MAFIA'));
        if ($mafia && $prey) {
            $actions[] = ['slot' => $mafia[0], 'role' => 'MAFIA', 'action' => 'kill', 'target' => $prey[array_rand($prey)]];
        }
        foreach ($alive as $s) {
            $o = array_values(array_diff($alive, [$s]));
            if ($role[$s] === 'DOCTOR') {
                $actions[] = ['slot' => $s, 'role' => 'DOCTOR', 'action' => 'protect', 'target' => $alive[array_rand($alive)]];
            } elseif ($role[$s] === 'VIGILANTE' && $o) {
                $actions[] = ['slot' => $s, 'role' => 'VIGILANTE', 'action' => 'vigkill', 'target' => $o[array_rand($o)]];
            } elseif ($role[$s] === 'ROLEBLOCKER' && $o) {
                $actions[] = ['slot' => $s, 'role' => 'ROLEBLOCKER', 'action' => 'block', 'target' => null, 'target_slot' => $o[array_rand($o)]];
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
    return 'MAFIA';
}

printf("mafia always vote as a bloc; %d games per seat count per rule\n\n", $games);
printf("%-6s %-12s %-11s %-16s %s\n", 'seats', 'town rule', 'mafia win%', 'lynch accuracy', 'no-lynch days');
foreach ([5, 7, 8, 9, 10] as $n) {
    foreach (['scatter', 'plurality', 'rule'] as $rule) {
        $st = ['lynches' => 0, 'hits' => 0, 'nolynch' => 0];
        $m = 0;
        for ($i = 0; $i < $games; $i++) if (playRule($n, $rule, $st) === 'MAFIA') $m++;
        printf("%-6d %-12s %-11.1f %-16s %d\n", $n, $rule, 100 * $m / $games,
            sprintf('%.1f%%', $st['lynches'] ? 100 * $st['hits'] / $st['lynches'] : 0), $st['nolynch']);
    }
    echo "\n";
}
