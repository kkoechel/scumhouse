<?php
/**
 * Scumhouse rules engine. Pure functions -- no database, no session, no clock.
 * Everything it needs is passed in, so tests/simulate.php can drive whole games
 * without a server.
 *
 * The engine deliberately reasons in terms of ACCOUNTS for anything public
 * (who voted, who died) and SLOTS for anything hidden (who may act at night).
 * It never needs the join between them; see PROTOCOL.md sec 5.2 for why that
 * shapes the role list.
 */

const SH_ROLES = ['MAFIA', 'COP', 'DOCTOR', 'VIGILANTE', 'ROLEBLOCKER', 'TRACKER', 'WATCHER', 'TOWN'];

/** Roles that can open a role envelope. Their SLOT INDICES are published (not who
 * holds them) -- that is what stops every slot from being able to investigate,
 * while disclosing nothing about people. See PROTOCOL.md sec 5.2. */
const SH_INVESTIGATOR_ROLES = ['COP', 'ROLEBLOCKER', 'TRACKER'];

/** Roles that read the REVERSE envelope (slot -> account) rather than the forward
 * one. Only the watcher needs it, because only the watcher gets an answer phrased
 * in slots. Kept as a separate list because the two envelopes are published to
 * different keys and confusing them would hand the wrong role the wrong direction. */
const SH_REVERSE_ROLES = ['WATCHER'];

/** Public role composition per player count. Printed in the lobby before anyone
 * commits -- several integrity checks depend on it being fixed and public. */
function sh_setup(int $numSeats): array
{
    $table = [
        5 => ['MAFIA' => 1, 'COP' => 1, 'DOCTOR' => 0, 'VIGILANTE' => 0, 'ROLEBLOCKER' => 0, 'TRACKER' => 0, 'TOWN' => 3],
        6 => ['MAFIA' => 1, 'COP' => 1, 'DOCTOR' => 1, 'VIGILANTE' => 0, 'ROLEBLOCKER' => 0, 'TRACKER' => 0, 'TOWN' => 3],
        7 => ['MAFIA' => 2, 'COP' => 1, 'DOCTOR' => 1, 'VIGILANTE' => 0, 'ROLEBLOCKER' => 0, 'TRACKER' => 0, 'TOWN' => 3],
        8 => ['MAFIA' => 2, 'COP' => 1, 'DOCTOR' => 1, 'VIGILANTE' => 1, 'ROLEBLOCKER' => 0, 'TRACKER' => 0, 'TOWN' => 3],
        9 => ['MAFIA' => 3, 'COP' => 1, 'DOCTOR' => 1, 'VIGILANTE' => 0, 'ROLEBLOCKER' => 1, 'TRACKER' => 0, 'WATCHER' => 0, 'TOWN' => 3],
        10 => ['MAFIA' => 3, 'COP' => 1, 'DOCTOR' => 1, 'VIGILANTE' => 0, 'ROLEBLOCKER' => 0, 'TRACKER' => 1, 'WATCHER' => 1, 'TOWN' => 3],
    ];
    foreach ($table as $n => &$setup) {
        // Every setup must name every role, so nothing silently reads as absent
        // when a role is added. Cheaper than remembering to backfill six rows.
        $setup += array_fill_keys(SH_ROLES, 0);
        $setup = array_merge(array_fill_keys(SH_ROLES, 0), $setup);
    }
    unset($setup);
    if (!isset($table[$numSeats])) {
        throw new InvalidArgumentException("no setup for $numSeats players");
    }
    return $table[$numSeats];
}

function sh_setup_role_list(int $numSeats): array
{
    $roles = [];
    foreach (sh_setup($numSeats) as $role => $count) {
        for ($i = 0; $i < $count; $i++) {
            $roles[] = $role;
        }
    }
    return $roles;
}

/** Which actions a slot's role may submit, and on which nights. */
function sh_allowed_action(string $role, int $nightNo, int $vigShotsUsed): ?string
{
    switch ($role) {
        case 'MAFIA':
            return 'kill';
        case 'DOCTOR':
            return 'protect';
        case 'ROLEBLOCKER':
            return 'block';
        case 'VIGILANTE':
            // No night-1 shot (nobody has information yet, and a night-1 vig kill
            // is just a coin flip that ends someone's game), and two shots total.
            return ($nightNo >= 2 && $vigShotsUsed < 2) ? 'vigkill' : null;
        case 'WATCHER':
            // Same shape as the tracker: no night ACTION, a question to its own
            // endpoint, answered at dawn once the night's actions are final.
            return null;
        case 'TRACKER':
            // Like the cop, submits no night ACTION -- the tracker's question goes
            // to its own endpoint and is answered at dawn, once the night's actions
            // are final and there is something to report.
            return null;
        case 'COP':
            // The cop submits nothing. Their whole night happens client-side:
            // redeem a retrieval token, open one envelope, read the answer. It is
            // the only role that leaks literally nothing to the server.
            return null;
        default:
            return null;
    }
}

/**
 * Resolves one night.
 *
 * $actions: list of ['slot'=>int,'role'=>string,'action'=>string,'target'=>int]
 *           already filtered to the latest valid submission per slot.
 * $prevProtect: the doctor's previous-night target, or null.
 *
 * Returns ['deaths'=>int[], 'protected'=>?int, 'rejected'=>string[]].
 */
function sh_resolve_night(array $actions, ?int $prevProtect): array
{
    $rejected = [];
    $mafiaTarget = null;
    $vigTarget = null;
    $protect = null;

    // The roleblocker names a SLOT, because that is all an opened envelope tells
    // them and all the server is allowed to know. Resolve it first, then drop that
    // slot's own action -- a blocked slot simply did not act tonight.
    $blockedSlot = null;
    foreach ($actions as $a) {
        if ($a['action'] === 'block') {
            $blockedSlot = $a['target_slot'] ?? null;
        }
    }
    $blocked = false;
    if ($blockedSlot !== null) {
        $before = count($actions);
        $actions = array_values(array_filter(
            $actions,
            fn($a) => $a['action'] === 'block' || $a['slot'] !== $blockedSlot
        ));
        $blocked = count($actions) < $before;
    }

    foreach ($actions as $a) {
        switch ($a['action']) {
            case 'kill':
                // Several mafia may submit; they coordinate in their own channel
                // and the last valid submission before the deadline is the team's.
                $mafiaTarget = $a['target'];
                break;
            case 'vigkill':
                $vigTarget = $a['target'];
                break;
            case 'block':
                break; // already applied above
            case 'protect':
                if ($prevProtect !== null && $a['target'] === $prevProtect) {
                    // Blocking the repeat is what stops a doctor from making one
                    // player permanently unkillable.
                    $rejected[] = 'doctor may not protect the same player two nights running';
                    break;
                }
                $protect = $a['target'];
                break;
        }
    }

    $deaths = [];
    foreach ([$mafiaTarget, $vigTarget] as $target) {
        if ($target !== null && $target !== $protect && !in_array($target, $deaths, true)) {
            $deaths[] = $target;
        }
    }

    return [
        'deaths' => $deaths,
        'protected' => $protect,
        'blocked_slot' => $blockedSlot,
        'blocked_an_action' => $blocked,
        'rejected' => $rejected,
    ];
}

/**
 * Tallies a day. $votes maps voter_user_id => target_user_id (or null for an
 * explicit "no lynch"). Only living voters should be passed in.
 *
 * A strict majority ends the day immediately (the "hammer"). At the deadline the
 * plurality leader is lynched, and a tie is no lynch -- ties favour the mafia,
 * which is the standard and keeps the town from stalling for free.
 */
function sh_tally_votes(array $votes, int $livingCount): array
{
    $counts = [];
    $noLynch = 0;
    foreach ($votes as $target) {
        if ($target === null) {
            $noLynch++;
        } else {
            $counts[$target] = ($counts[$target] ?? 0) + 1;
        }
    }
    arsort($counts);

    $majority = intdiv($livingCount, 2) + 1;
    $leader = null;
    $leaderVotes = 0;
    $tied = false;
    foreach ($counts as $target => $n) {
        if ($leader === null) {
            $leader = $target;
            $leaderVotes = $n;
        } elseif ($n === $leaderVotes) {
            $tied = true;
        }
    }

    $hammered = ($leader !== null && $leaderVotes >= $majority);
    return [
        'counts' => $counts,
        'no_lynch' => $noLynch,
        'majority' => $majority,
        'leader' => $leader,
        'leader_votes' => $leaderVotes,
        'tied' => $tied,
        'hammered' => $hammered,
        // At the deadline: plurality wins, a tie is no lynch.
        'deadline_lynch' => ($leader !== null && !$tied && $leaderVotes > $noLynch) ? $leader : null,
    ];
}

/**
 * Win check.
 *
 * The server cannot see who is mafia, so it counts them the only way it can:
 * total mafia in the published setup, minus the mafia that have publicly flipped.
 * That is exact as long as every death is flipped -- which is why a pending flip
 * blocks phase advancement (PROTOCOL.md sec 9).
 */
function sh_check_winner(int $numSeats, int $livingCount, int $flippedMafia): ?string
{
    $mafiaTotal = sh_setup($numSeats)['MAFIA'];
    $livingMafia = $mafiaTotal - $flippedMafia;

    if ($livingMafia <= 0) {
        return 'TOWN';
    }
    // Mafia win on parity, not on elimination: once they match the town they can
    // no longer be out-voted, and playing it out is a formality.
    if ($livingMafia >= $livingCount - $livingMafia) {
        return 'MAFIA';
    }
    return null;
}

/** Which slot indices hold investigative roles. Published to everyone -- it names
 * slots, never people -- so that each player knows which keys to seal an envelope
 * to, and so that non-investigator slots cannot open one. */
function sh_investigator_slots(array $slotRoles): array
{
    $out = [];
    foreach ($slotRoles as $slot => $role) {
        if (in_array($role, SH_INVESTIGATOR_ROLES, true)) {
            $out[] = (int) $slot;
        }
    }
    sort($out);
    return $out;
}

/** Slot indices holding roles that read the reverse (slot -> account) envelope. */
function sh_reverse_slots(array $slotRoles): array
{
    $out = [];
    foreach ($slotRoles as $slot => $role) {
        if (in_array($role, SH_REVERSE_ROLES, true)) {
            $out[] = (int) $slot;
        }
    }
    sort($out);
    return $out;
}
