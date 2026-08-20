<?php
/**
 * Shared bootstrap for the anonymous endpoints.
 *
 * READ THIS BEFORE EDITING ANYTHING IN public/anon/.
 *
 * These four endpoints are the only ones a player's browser hits WITHOUT a
 * session cookie, and that absence is the entire privacy guarantee (PROTOCOL.md
 * sec 7). A single session_init(), a single $_SERVER['REMOTE_ADDR'], or a single
 * log line naming the requester turns the whole protocol into theatre.
 *
 * tests/privacy_check.php greps this directory for those patterns and fails the
 * build if any appear. Do not work around it -- if you need identity here, you
 * are writing the endpoint in the wrong place.
 */

require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/anon.php';
require_once __DIR__ . '/../../inc/game_state.php';

header('Content-Type: application/json');
// No caching layer should ever hold one of these, and no referrer should leave.
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

function sh_json_in(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function sh_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function sh_bad(string $msg, int $code = 400): void
{
    // Deliberately terse and identical in shape for every failure: a chatty error
    // is another side channel.
    sh_out(['ok' => false, 'error' => $msg], $code);
}

/** Looks up a published slot by index and checks the request really came from the
 * holder of that slot's signing key. This is the ONLY authentication these
 * endpoints have, and it authenticates a slot, never a person. */
function sh_auth_slot(int $gameId, int $slot, string $payload, string $sig): array
{
    $stmt = db()->prepare('SELECT slot_index, idk_pub, sigk_pub FROM anon_slots WHERE game_id=? AND slot_index=?');
    $stmt->execute([$gameId, $slot]);
    $row = $stmt->fetch();
    if (!$row) {
        sh_bad('unknown slot', 404);
    }
    if (!sh_verify_slot_sig($row['sigk_pub'], $payload, $sig)) {
        sh_bad('bad signature', 403);
    }
    return $row;
}
