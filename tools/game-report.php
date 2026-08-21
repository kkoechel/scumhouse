<?php
/**
 * Post-mortem telemetry for one finished game, as a single TSV row.
 *
 * A win rate says who won and nothing about why. These are the numbers that
 * distinguish "the town played badly" from "the town never had the tempo":
 * how often a lynch actually found a mafia, how often the day produced no lynch
 * at all, and whether the power roles ever landed on anything.
 *
 * Lynch accuracy is the one to watch, because it has a known baseline: a town
 * with no information lynches a mafia at roughly (mafia alive / players alive).
 * A strategy that is deducing anything at all beats that number. One that does
 * not is choosing at random in a nicer-looking way.
 *
 *   SH_TEST_DB=... SH_TEST_DB_USER=... SH_TEST_DB_PASS=... php tools/game-report.php <game_id>
 */
$db   = getenv('SH_TEST_DB') ?: 'scumhouse_play_test';
$host = getenv('SH_TEST_DB_HOST') ?: '127.0.0.1';
$user = getenv('SH_TEST_DB_USER') ?: 'root';
$pass = getenv('SH_TEST_DB_PASS') ?: '';
$gameId = (int) ($argv[1] ?? 1);

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass,
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$q = function (string $sql, array $a = []) use ($pdo) {
    $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC);
};

$seats = (int) $q('SELECT COUNT(*) c FROM game_players WHERE game_id=?', [$gameId])[0]['c'];
$roleOf = [];   // user_id => role, for everyone who flipped
foreach ($q('SELECT user_id, role FROM flips WHERE game_id=?', [$gameId]) as $r) {
    $roleOf[(int) $r['user_id']] = $r['role'];
}

$lynchHit = $lynchMiss = $nightKills = 0;
foreach ($q('SELECT user_id, died_cause FROM game_players WHERE game_id=? AND died_cause IS NOT NULL', [$gameId]) as $p) {
    $role = $roleOf[(int) $p['user_id']] ?? null;
    if ($p['died_cause'] === 'lynch') {
        if ($role === 'MAFIA') $lynchHit++; else $lynchMiss++;
    } else {
        $nightKills++;
    }
}

// Days that reached a vote but produced no death: the tie rule biting.
$dayPhases = (int) ($q('SELECT COALESCE(MAX(phase_no),0) m FROM votes WHERE game_id=?', [$gameId])[0]['m']);
$lynches   = $lynchHit + $lynchMiss;
$noLynch   = max(0, $dayPhases - $lynches);

$acts = [];
foreach ($q("SELECT action, COUNT(*) c FROM anon_actions WHERE game_id=? AND superseded=0 GROUP BY action", [$gameId]) as $r) {
    $acts[$r['action']] = (int) $r['c'];
}

// A vigilante shot that found a mafia, versus one that did not.
$vigHit = $vigMiss = 0;
foreach ($q("SELECT target_user_id FROM anon_actions WHERE game_id=? AND action='vigkill' AND superseded=0", [$gameId]) as $r) {
    $role = $roleOf[(int) $r['target_user_id']] ?? null;
    if ($role === null) continue;                 // survived, so never flipped
    if ($role === 'MAFIA') $vigHit++; else $vigMiss++;
}

$mafiaTotal = (int) $q("SELECT COUNT(*) c FROM slot_roles WHERE game_id=? AND role='MAFIA'", [$gameId])[0]['c'];

// seats  days  lynches  lynch_hit  lynch_miss  no_lynch  night_kills  vigkills  vig_hit  vig_miss  protects  blocks  mafia_total
printf("%d\t%d\t%d\t%d\t%d\t%d\t%d\t%d\t%d\t%d\t%d\t%d\t%d\n",
    $seats, $dayPhases, $lynches, $lynchHit, $lynchMiss, $noLynch, $nightKills,
    $acts['vigkill'] ?? 0, $vigHit, $vigMiss, $acts['protect'] ?? 0, $acts['block'] ?? 0, $mafiaTotal);
