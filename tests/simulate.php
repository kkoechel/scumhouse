<?php
/**
 * Pure-engine tests. No database, no crypto, no server -- inc/engine.php is
 * written as plain functions precisely so the rules can be exercised on their own.
 *
 * The cryptographic half is covered separately and for real by
 * tests/run_interop.sh, which drives the actual JS and PHP implementations
 * against each other.
 *
 * Run: php tests/simulate.php
 */

require_once dirname(__DIR__) . '/inc/engine.php';

$failed = 0;
function ok(string $name, bool $cond): void
{
    global $failed;
    if ($cond) {
        echo "  ok  $name\n";
    } else {
        echo "  FAIL $name\n";
        $failed++;
    }
}

echo "setups\n";
foreach ([5, 6, 7, 8, 9, 10] as $n) {
    $setup = sh_setup($n);
    ok("$n-player setup seats exactly $n", array_sum($setup) === $n);
    ok("$n-player setup gives town a majority", $setup['MAFIA'] * 2 < $n);
    ok("$n-player setup has exactly one cop", $setup['COP'] === 1);
    // One ciphertext per slot in reverse_envelopes only suffices while there is at
    // most one watcher; a second would need a row per (slot, watcher).
    ok("$n-player setup has at most one watcher", $setup['WATCHER'] <= 1);
    ok("$n-player setup names every role", count(array_diff(SH_ROLES, array_keys($setup))) === 0);
    foreach (array_keys($setup) as $role) {
        ok("$n-player setup role $role is known", in_array($role, SH_ROLES, true));
    }
    ok("$n-player role list is $n long", count(sh_setup_role_list($n)) === $n);
}

echo "night resolution\n";
$r = sh_resolve_night([
    ['slot' => 1, 'role' => 'MAFIA', 'action' => 'kill', 'target' => 7],
], null);
ok('an unprotected kill lands', $r['deaths'] === [7]);

$r = sh_resolve_night([
    ['slot' => 1, 'role' => 'MAFIA', 'action' => 'kill', 'target' => 7],
    ['slot' => 2, 'role' => 'DOCTOR', 'action' => 'protect', 'target' => 7],
], null);
ok('the doctor saves the target', $r['deaths'] === []);

$r = sh_resolve_night([
    ['slot' => 2, 'role' => 'DOCTOR', 'action' => 'protect', 'target' => 7],
], 7);
ok('protecting the same player twice running is rejected', count($r['rejected']) === 1);
ok('...and leaves nobody protected', $r['protected'] === null);

$r = sh_resolve_night([
    ['slot' => 1, 'role' => 'MAFIA', 'action' => 'kill', 'target' => 7],
    ['slot' => 3, 'role' => 'VIGILANTE', 'action' => 'vigkill', 'target' => 7],
    ['slot' => 2, 'role' => 'DOCTOR', 'action' => 'protect', 'target' => 7],
], null);
ok('one protect stops both shots at the same target', $r['deaths'] === []);

$r = sh_resolve_night([
    ['slot' => 1, 'role' => 'MAFIA', 'action' => 'kill', 'target' => 7],
    ['slot' => 3, 'role' => 'VIGILANTE', 'action' => 'vigkill', 'target' => 8],
], null);
ok('two different targets both die', $r['deaths'] === [7, 8]);

// Several mafia may submit; the team's real choice is the last one in.
$r = sh_resolve_night([
    ['slot' => 1, 'role' => 'MAFIA', 'action' => 'kill', 'target' => 7],
    ['slot' => 4, 'role' => 'MAFIA', 'action' => 'kill', 'target' => 8],
], null);
ok('the last mafia submission wins', $r['deaths'] === [8]);

echo "roleblocking\n";
$r = sh_resolve_night([
    ['slot' => 1, 'role' => 'MAFIA', 'action' => 'kill', 'target' => 7],
    ['slot' => 4, 'role' => 'ROLEBLOCKER', 'action' => 'block', 'target_slot' => 1],
], null);
ok('blocking the killing slot stops the kill', $r['deaths'] === [] && $r['blocked_an_action'] === true);

$r = sh_resolve_night([
    ['slot' => 1, 'role' => 'MAFIA', 'action' => 'kill', 'target' => 7],
    ['slot' => 4, 'role' => 'ROLEBLOCKER', 'action' => 'block', 'target_slot' => 6],
], null);
ok('blocking an idle slot changes nothing', $r['deaths'] === [7] && $r['blocked_an_action'] === false);

// The roleblocker cannot see roles, so blocking the doctor is a real risk.
$r = sh_resolve_night([
    ['slot' => 1, 'role' => 'MAFIA', 'action' => 'kill', 'target' => 7],
    ['slot' => 2, 'role' => 'DOCTOR', 'action' => 'protect', 'target' => 7],
    ['slot' => 4, 'role' => 'ROLEBLOCKER', 'action' => 'block', 'target_slot' => 2],
], null);
ok('blocking the doctor lets the kill through', $r['deaths'] === [7]);

$r = sh_resolve_night([
    ['slot' => 4, 'role' => 'ROLEBLOCKER', 'action' => 'block', 'target_slot' => 4],
], null);
ok('blocking yourself is legal and pointless', $r['blocked_an_action'] === false);

echo "investigator slots\n";
$slots = sh_investigator_slots([0 => 'TOWN', 1 => 'MAFIA', 2 => 'COP', 3 => 'DOCTOR', 4 => 'ROLEBLOCKER']);
ok('cop and roleblocker are the investigator slots', $slots === [2, 4]);
$slots = sh_investigator_slots([0 => 'TRACKER', 1 => 'MAFIA', 2 => 'COP', 3 => 'DOCTOR']);
ok('the tracker is an investigator too', $slots === [0, 2]);
ok('the tracker submits no night action', sh_allowed_action('TRACKER', 3, 0) === null);
ok('the watcher submits no night action', sh_allowed_action('WATCHER', 3, 0) === null);
$rev = sh_reverse_slots([0 => 'TOWN', 1 => 'WATCHER', 2 => 'COP', 3 => 'MAFIA']);
ok('the watcher is the reverse-envelope slot', $rev === [1]);
// The two directions must not overlap: they are sealed to different keys, and a
// role in both lists would be handed an envelope it cannot use.
ok('no role reads both envelope directions',
    array_intersect(SH_INVESTIGATOR_ROLES, SH_REVERSE_ROLES) === []);
// Every investigative role must be one the deal can actually produce.
foreach (SH_INVESTIGATOR_ROLES as $role) {
    ok("investigator role $role is a real role", in_array($role, SH_ROLES, true));
}
ok('a setup with no investigators yields none', sh_investigator_slots([0 => 'TOWN', 1 => 'MAFIA']) === []);

echo "vigilante limits\n";
ok('no vigilante shot on night 1', sh_allowed_action('VIGILANTE', 1, 0) === null);
ok('a shot on night 2', sh_allowed_action('VIGILANTE', 2, 0) === 'vigkill');
ok('no third shot', sh_allowed_action('VIGILANTE', 5, 2) === null);
ok('town has no night action', sh_allowed_action('TOWN', 3, 0) === null);
ok('mafia may always kill', sh_allowed_action('MAFIA', 1, 0) === 'kill');
ok('the roleblocker blocks', sh_allowed_action('ROLEBLOCKER', 1, 0) === 'block');
// The cop's entire night is client-side; submitting nothing is what makes it the
// only role that tells the server nothing at all.
ok('the cop submits no night action', sh_allowed_action('COP', 3, 0) === null);

echo "vote tallies\n";
$t = sh_tally_votes([1 => 9, 2 => 9, 3 => 9, 4 => 5], 6);
ok('no hammer below a majority', $t['hammered'] === false);
$t = sh_tally_votes([1 => 9, 2 => 9, 3 => 9, 4 => 9, 5 => 5], 6);
ok('a strict majority hammers', $t['hammered'] === true && $t['leader'] === 9);
$t = sh_tally_votes([1 => 9, 2 => 5], 6);
ok('a tie lynches nobody at the deadline', $t['deadline_lynch'] === null);
$t = sh_tally_votes([1 => 9, 2 => 9, 3 => 5], 6);
ok('a plurality lynches at the deadline', $t['deadline_lynch'] === 9);
$t = sh_tally_votes([1 => null, 2 => null, 3 => 9], 6);
ok('no-lynch votes beat a lone accuser', $t['deadline_lynch'] === null);

echo "win conditions\n";
ok('town wins once every mafia has flipped', sh_check_winner(7, 4, 2) === 'TOWN');
ok('nothing decided at 5 alive, 1 mafia down', sh_check_winner(7, 5, 1) === null);
ok('mafia win on parity', sh_check_winner(7, 2, 0) === 'MAFIA');
ok('mafia win when they outnumber', sh_check_winner(7, 3, 0) === 'MAFIA');
ok('3 town vs 1 mafia is still live', sh_check_winner(5, 4, 0) === null);

echo "full-game invariant sweep\n";
// Random games driven only through the engine: whatever happens, the game must
// terminate and never report both factions winning.
mt_srand(20260819);
$bad = 0;
for ($game = 0; $game < 400; $game++) {
    $n = [5, 6, 7, 8, 9, 10][mt_rand(0, 5)];
    $mafiaTotal = sh_setup($n)['MAFIA'];
    $living = $n;
    $flippedMafia = 0;
    $winner = null;
    for ($phase = 1; $phase <= 40 && $winner === null; $phase++) {
        // Someone dies most phases; sometimes it is a mafia member.
        if ($living > 1 && mt_rand(0, 4) > 0) {
            $living--;
            if ($flippedMafia < $mafiaTotal && mt_rand(0, 2) === 0) {
                $flippedMafia++;
            }
        }
        $winner = sh_check_winner($n, $living, $flippedMafia);
    }
    if ($winner === null) {
        $bad++;
    }
    if ($flippedMafia === $mafiaTotal && $winner !== 'TOWN') {
        $bad++;
    }
}
ok('400 random games all terminate with a coherent winner', $bad === 0);

echo "\n";
if ($failed > 0) {
    echo "$failed assertion(s) failed\n";
    exit(1);
}
echo "all engine tests passed\n";
