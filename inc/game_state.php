<?php
/**
 * Database layer + phase clock for Scumhouse.
 *
 * Phases advance lazily: sh_tick() runs on every page load and every poll, so a
 * forum game with 48-hour days needs no cron. That is the same pattern the other
 * games in this repo use.
 *
 * PRIVACY INVARIANT for anything added to this file: a query may join
 * game_players to users freely (that is all public), and may read slot_roles
 * freely (the server is allowed to know that), but it must NEVER join the two.
 * The only bridge is the `flips` table, written by a dead player's own client.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/anon.php';

const SH_CARD_INFO = 'scumhouse/card/v1';
const SH_TABLE_INFO = 'scumhouse/roletable/v1';
const SH_KEY_INFO = 'scumhouse/innerkey/v1';
const SH_TRACK_INFO = 'scumhouse/trackreport/v1';
const SH_WATCH_INFO = 'scumhouse/watchreport/v1';
const SH_REVERSE_INFO = 'scumhouse/reverse/v1';

function sh_game(int $gameId): ?array
{
    $stmt = db()->prepare('SELECT * FROM games WHERE id=?');
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    return $g ?: null;
}

function sh_players(int $gameId): array
{
    $stmt = db()->prepare(
        'SELECT gp.*, u.display_name, u.email
           FROM game_players gp JOIN users u ON u.id = gp.user_id
          WHERE gp.game_id = ? ORDER BY gp.seat_order'
    );
    $stmt->execute([$gameId]);
    return $stmt->fetchAll();
}

function sh_living(int $gameId): array
{
    return array_values(array_filter(sh_players($gameId), fn($p) => (int) $p['is_alive'] === 1));
}

/** The published anon slots. Never carries a user_id -- see the header note. */
function sh_slots(int $gameId): array
{
    $stmt = db()->prepare(
        'SELECT slot_index, idk_pub, sigk_pub FROM anon_slots
          WHERE game_id=? AND slot_index IS NOT NULL ORDER BY slot_index'
    );
    $stmt->execute([$gameId]);
    return $stmt->fetchAll();
}

function sh_slot_role(int $gameId, int $slot): ?string
{
    $stmt = db()->prepare('SELECT role FROM slot_roles WHERE game_id=? AND slot_index=?');
    $stmt->execute([$gameId, $slot]);
    $r = $stmt->fetchColumn();
    return $r === false ? null : $r;
}

/* ---------------- lobby -> registration ---------------- */

/** Called when the last seat fills. Mints the per-game credential key and opens
 * anonymous registration. */
function sh_open_registration(int $gameId): void
{
    $game = sh_game($gameId);
    if ($game === null || $game['status'] !== 'lobby') {
        return;
    }
    $key = sh_new_credential_key();
    db()->prepare(
        "UPDATE games SET status='registration', cred_n=?, cred_e=?, cred_d=? WHERE id=? AND status='lobby'"
    )->execute([$key['n'], $key['e'], $key['pem'], $gameId]);
}

function sh_registered_count(int $gameId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM anon_slots WHERE game_id=?');
    $stmt->execute([$gameId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Closes registration once all N identities are in: assigns slot indices in
 * canonical sha256(anon_pub) order, deals, and seals a card to each slot.
 *
 * Canonical ordering matters more than it looks -- if the server got to choose
 * the index, it could deal slot 0 the mafia card and then watch which client
 * fetched card 0 first. Sorting on a hash of keys nobody controls removes the
 * choice entirely.
 */
function sh_close_registration(int $gameId): void
{
    $db = db();
    $game = sh_game($gameId);
    if ($game === null || $game['status'] !== 'registration') {
        return;
    }
    $n = (int) $game['num_seats'];
    if (sh_registered_count($gameId) < $n) {
        return;
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT id, idk_pub, sigk_pub, pub_hash FROM anon_slots WHERE game_id=? ORDER BY pub_hash ASC FOR UPDATE');
        $stmt->execute([$gameId]);
        $slots = $stmt->fetchAll();
        if (count($slots) !== $n) {
            $db->rollBack();
            return;
        }

        $roles = sh_setup_role_list($n);
        shuffle($roles);

        $mafiaSlots = [];
        foreach ($roles as $i => $role) {
            if ($role === 'MAFIA') {
                $mafiaSlots[] = $i;
            }
        }

        $setSlot = $db->prepare('UPDATE anon_slots SET slot_index=? WHERE id=?');
        $setRole = $db->prepare('INSERT INTO slot_roles (game_id, slot_index, role) VALUES (?,?,?)');
        $setCard = $db->prepare('INSERT INTO sealed_cards (game_id, slot_index, ciphertext) VALUES (?,?,?)');

        foreach ($slots as $i => $slot) {
            $role = $roles[$i];
            $setSlot->execute([$i, $slot['id']]);
            $setRole->execute([$gameId, $i, $role]);

            $card = ['role' => $role, 'slot' => $i, 'game' => $gameId];
            if ($role === 'MAFIA') {
                // Slot indices, not accounts -- the server has no accounts to give.
                // Teammates find each other by ECDH against these slots' published
                // idk_pub, producing a key the server cannot compute.
                $card['team'] = array_values(array_diff($mafiaSlots, [$i]));
            }
            $blob = sh_ecies_seal($slot['idk_pub'], $card, SH_CARD_INFO);
            if ($blob === null) {
                throw new RuntimeException("failed to seal card for slot $i");
            }
            $setCard->execute([$gameId, $i, $blob]);
        }

        // The cop -- and ONLY the cop -- gets the slot -> role table, sealed to
        // their key. It is inert alone (it names slots, not people); combined with
        // one envelope a night it yields one player's alignment a night. The
        // roleblocker is deliberately excluded: with this table they could block a
        // known mafia slot every night without identifying anyone.
        $setTable = $db->prepare('INSERT INTO sealed_role_table (game_id, slot_index, ciphertext) VALUES (?,?,?)');
        foreach ($slots as $i => $slot) {
            if ($roles[$i] !== 'COP') {
                continue;
            }
            $sealed = sh_ecies_seal($slot['idk_pub'], ['roles' => $roles], SH_TABLE_INFO);
            if ($sealed === null) {
                throw new RuntimeException("failed to seal the role table for slot $i");
            }
            $setTable->execute([$gameId, $i, $sealed]);
        }

        $db->prepare(
            "UPDATE games SET status='active', phase='day', phase_no=1,
                    started_at=NOW(), phase_ends_at=DATE_ADD(NOW(), INTERVAL day_hours HOUR)
              WHERE id=?"
        )->execute([$gameId]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/* ---------------- phase clock ---------------- */

function sh_pending_flips(int $gameId): array
{
    $stmt = db()->prepare(
        'SELECT gp.user_id, u.display_name
           FROM game_players gp
           JOIN users u ON u.id = gp.user_id
      LEFT JOIN flips f ON f.game_id = gp.game_id AND f.user_id = gp.user_id
          WHERE gp.game_id = ? AND gp.is_alive = 0 AND f.user_id IS NULL'
    );
    $stmt->execute([$gameId]);
    return $stmt->fetchAll();
}

function sh_flipped_mafia(int $gameId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM flips WHERE game_id=? AND role='MAFIA'");
    $stmt->execute([$gameId]);
    return (int) $stmt->fetchColumn();
}

/** Advances the game if its deadline has passed. Safe to call on every request;
 * it is a no-op unless something is actually due. */
function sh_tick(int $gameId): void
{
    $game = sh_game($gameId);
    if ($game === null) {
        return;
    }
    if ($game['status'] === 'registration') {
        sh_close_registration($gameId);
        return;
    }
    if ($game['status'] !== 'active') {
        return;
    }

    // A death nobody has flipped leaves the win check unable to count the mafia,
    // so the clock stops rather than guessing. This is the documented weak point
    // in PROTOCOL.md sec 9; the admin page has an abandon button for the case
    // where a player simply never comes back.
    if (sh_pending_flips($gameId)) {
        return;
    }

    if (strtotime($game['phase_ends_at']) > time()) {
        // The day can still end early on a hammer.
        if ($game['phase'] === 'day' && sh_day_hammered($gameId, (int) $game['phase_no'])) {
            sh_end_day($gameId);
        }
        return;
    }

    if ($game['phase'] === 'day') {
        sh_end_day($gameId);
    } else {
        sh_end_night($gameId);
    }
}

function sh_day_votes(int $gameId, int $phaseNo): array
{
    $stmt = db()->prepare(
        'SELECT v.voter_user_id, v.target_user_id
           FROM votes v JOIN game_players gp
             ON gp.game_id = v.game_id AND gp.user_id = v.voter_user_id
          WHERE v.game_id=? AND v.phase_no=? AND gp.is_alive=1'
    );
    $stmt->execute([$gameId, $phaseNo]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['voter_user_id']] = $row['target_user_id'] === null ? null : (int) $row['target_user_id'];
    }
    return $out;
}

function sh_day_hammered(int $gameId, int $phaseNo): bool
{
    $living = count(sh_living($gameId));
    $tally = sh_tally_votes(sh_day_votes($gameId, $phaseNo), $living);
    return $tally['hammered'];
}

function sh_kill_player(int $gameId, int $userId, int $phaseNo, string $cause): void
{
    db()->prepare(
        'UPDATE game_players SET is_alive=0, died_phase_no=?, died_cause=? WHERE game_id=? AND user_id=? AND is_alive=1'
    )->execute([$phaseNo, $cause, $gameId, $userId]);
}

function sh_end_day(int $gameId): void
{
    $game = sh_game($gameId);
    $phaseNo = (int) $game['phase_no'];
    $living = count(sh_living($gameId));
    $tally = sh_tally_votes(sh_day_votes($gameId, $phaseNo), $living);

    $lynched = $tally['hammered'] ? $tally['leader'] : $tally['deadline_lynch'];
    if ($lynched !== null) {
        sh_kill_player($gameId, (int) $lynched, $phaseNo, 'lynch');
    }

    if (sh_finish_if_over($gameId)) {
        return;
    }
    // Retrieval tokens are accepted until the halfway mark and every answer
    // becomes readable at exactly that moment (PROTOCOL.md sec 5.3), which leaves
    // the second half of the night for anyone who has to ACT on what they learned.
    db()->prepare(
        "UPDATE games SET phase='night',
                phase_ends_at=DATE_ADD(NOW(), INTERVAL night_hours HOUR),
                key_release_at=DATE_ADD(NOW(), INTERVAL night_hours * 30 MINUTE)
          WHERE id=?"
    )->execute([$gameId]);
}

/** Latest non-superseded action per slot for the night, joined to the slot's role
 * so the engine can adjudicate. Reads slot_roles but never touches users. */
function sh_night_actions(int $gameId, int $nightNo): array
{
    $stmt = db()->prepare(
        'SELECT a.slot_index, a.action, a.target_user_id, a.target_slot, r.role
           FROM anon_actions a
           JOIN slot_roles r ON r.game_id = a.game_id AND r.slot_index = a.slot_index
          WHERE a.game_id=? AND a.night_no=? AND a.superseded=0
       ORDER BY a.submitted_at ASC'
    );
    $stmt->execute([$gameId, $nightNo]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'slot' => (int) $row['slot_index'],
            'role' => $row['role'],
            'action' => $row['action'],
            'target' => $row['target_user_id'] === null ? null : (int) $row['target_user_id'],
            'target_slot' => $row['target_slot'] === null ? null : (int) $row['target_slot'],
        ];
    }
    return $out;
}

function sh_prev_protect(int $gameId, int $nightNo): ?int
{
    if ($nightNo <= 1) {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT target_user_id FROM anon_actions
          WHERE game_id=? AND night_no=? AND action='protect' AND superseded=0
       ORDER BY submitted_at DESC LIMIT 1"
    );
    $stmt->execute([$gameId, $nightNo - 1]);
    $t = $stmt->fetchColumn();
    return $t === false ? null : (int) $t;
}

function sh_end_night(int $gameId): void
{
    $game = sh_game($gameId);
    $nightNo = (int) $game['phase_no'];

    sh_seal_tracker_reports($gameId, $nightNo);
    sh_seal_watcher_reports($gameId, $nightNo);

    $result = sh_resolve_night(sh_night_actions($gameId, $nightNo), sh_prev_protect($gameId, $nightNo));
    foreach ($result['deaths'] as $userId) {
        sh_kill_player($gameId, $userId, $nightNo, 'kill');
    }

    if (sh_finish_if_over($gameId)) {
        return;
    }
    db()->prepare(
        "UPDATE games SET phase='day', phase_no=phase_no+1, key_release_at=NULL,
                phase_ends_at=DATE_ADD(NOW(), INTERVAL day_hours HOUR) WHERE id=?"
    )->execute([$gameId]);
}

function sh_finish_if_over(int $gameId): bool
{
    $game = sh_game($gameId);
    // The composition this game was dealt from, not today's table.
    $dealt = json_decode($game['setup_json'], true);
    $winner = sh_check_winner(
        (int) $game['num_seats'],
        count(sh_living($gameId)),
        sh_flipped_mafia($gameId),
        isset($dealt['MAFIA']) ? (int) $dealt['MAFIA'] : null
    );
    if ($winner === null) {
        return false;
    }
    db()->prepare("UPDATE games SET status='finished', winner_faction=?, finished_at=NOW(), phase_ends_at=NULL WHERE id=?")
        ->execute([$winner, $gameId]);
    return true;
}

/** Vigilante shots already spent, so sh_allowed_action() can cap them. Counts
 * actions by slot, which needs no account knowledge. */
function sh_vig_shots_used(int $gameId, int $slot): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(DISTINCT night_no) FROM anon_actions
          WHERE game_id=? AND slot_index=? AND action='vigkill' AND superseded=0"
    );
    $stmt->execute([$gameId, $slot]);
    return (int) $stmt->fetchColumn();
}

/** Slot indices holding investigative roles, published to everyone. Reads
 * slot_roles, which the server is allowed to know, and returns only indices --
 * never a hint about who holds them. */
function sh_investigator_slot_list(int $gameId): array
{
    $stmt = db()->prepare('SELECT slot_index, role FROM slot_roles WHERE game_id=? ORDER BY slot_index');
    $stmt->execute([$gameId]);
    $roles = [];
    foreach ($stmt->fetchAll() as $row) {
        $roles[(int) $row['slot_index']] = $row['role'];
    }
    return sh_investigator_slots($roles);
}

/**
 * What did this slot target tonight? The server has always known this -- it holds
 * every (slot, action, target) triple in order to resolve the night at all. What
 * it does not know is whose slot that is, and answering by slot index never tells
 * it. That asymmetry is the entire reason a tracker can exist here.
 *
 * Returns the account visited, or null for "did not visit a player" -- which
 * covers both doing nothing and acting on something that is not a person (a
 * roleblock names a slot, not an account).
 */
function sh_night_visit(int $gameId, int $nightNo, int $slot): ?int
{
    $stmt = db()->prepare(
        'SELECT target_user_id FROM anon_actions
          WHERE game_id=? AND night_no=? AND slot_index=? AND superseded=0
       ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$gameId, $nightNo, $slot]);
    $target = $stmt->fetchColumn();
    return ($target === false || $target === null) ? null : (int) $target;
}

/** Answers every tracker query for the night, sealed to the one-use key supplied
 * with the question. Called at dawn, before the phase advances, because until the
 * night resolves there is nothing final to report. */
function sh_seal_tracker_reports(int $gameId, int $nightNo): void
{
    $stmt = db()->prepare('SELECT slot_index, target_slot, ephemeral_pub FROM tracker_queries WHERE game_id=? AND night_no=?');
    $stmt->execute([$gameId, $nightNo]);
    $queries = $stmt->fetchAll();
    if (!$queries) {
        return;
    }
    $ins = db()->prepare('INSERT IGNORE INTO tracker_reports (game_id, night_no, slot_index, ciphertext) VALUES (?,?,?,?)');
    foreach ($queries as $q) {
        $visited = sh_night_visit($gameId, $nightNo, (int) $q['target_slot']);
        $sealed = sh_ecies_seal($q['ephemeral_pub'], [
            'night' => $nightNo,
            'target_slot' => (int) $q['target_slot'],
            'visited' => $visited,
        ], SH_TRACK_INFO);
        if ($sealed !== null) {
            $ins->execute([$gameId, $nightNo, (int) $q['slot_index'], $sealed]);
        }
    }
}

/** True once the night's fixed release point has passed. */
function sh_keys_released(array $game): bool
{
    return $game['key_release_at'] !== null && strtotime($game['key_release_at']) <= time();
}

/** Slot indices that read the reverse (slot -> account) envelope. */
function sh_reverse_slot_list(int $gameId): array
{
    $stmt = db()->prepare('SELECT slot_index, role FROM slot_roles WHERE game_id=? ORDER BY slot_index');
    $stmt->execute([$gameId]);
    $roles = [];
    foreach ($stmt->fetchAll() as $row) {
        $roles[(int) $row['slot_index']] = $row['role'];
    }
    return sh_reverse_slots($roles);
}

/**
 * Which SLOTS targeted this account tonight. The server has always been able to
 * answer this -- actions name their target account in the clear, because the
 * night cannot be resolved otherwise. What it cannot do is put names to the
 * slots, which is exactly the half the watcher supplies for itself.
 */
function sh_night_visitors(int $gameId, int $nightNo, int $targetUserId): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT slot_index FROM anon_actions
          WHERE game_id=? AND night_no=? AND target_user_id=? AND superseded=0
       ORDER BY slot_index'
    );
    $stmt->execute([$gameId, $nightNo, $targetUserId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'slot_index'));
}

/**
 * Answers every watcher query for the night.
 *
 * Releases the inner key for each VISITING slot's reverse envelope and nothing
 * else. That is the whole rate limit: the watcher can only ever unlock the slots
 * their chosen target actually drew, which is typically none or one or two.
 */
function sh_seal_watcher_reports(int $gameId, int $nightNo): void
{
    $stmt = db()->prepare('SELECT slot_index, target_user_id, ephemeral_pub FROM watcher_queries WHERE game_id=? AND night_no=?');
    $stmt->execute([$gameId, $nightNo]);
    $queries = $stmt->fetchAll();
    if (!$queries) {
        return;
    }

    $keyStmt = db()->prepare('SELECT inner_key FROM reverse_envelopes WHERE game_id=? AND slot_index=?');
    $ins = db()->prepare('INSERT IGNORE INTO watcher_reports (game_id, night_no, slot_index, ciphertext) VALUES (?,?,?,?)');

    foreach ($queries as $q) {
        $visitors = [];
        foreach (sh_night_visitors($gameId, $nightNo, (int) $q['target_user_id']) as $slot) {
            $keyStmt->execute([$gameId, $slot]);
            $innerKey = $keyStmt->fetchColumn();
            // A slot that never published a reverse envelope simply cannot be
            // named. The refusal is visible to the watcher and is its own tell.
            $visitors[] = ['slot' => $slot, 'inner_key' => $innerKey === false ? null : $innerKey];
        }
        $sealed = sh_ecies_seal($q['ephemeral_pub'], [
            'night' => $nightNo,
            'target' => (int) $q['target_user_id'],
            'visitors' => $visitors,
        ], SH_WATCH_INFO);
        if ($sealed !== null) {
            $ins->execute([$gameId, $nightNo, (int) $q['slot_index'], $sealed]);
        }
    }
}
