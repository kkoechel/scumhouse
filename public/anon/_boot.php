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

// CORS for clients this server does not host (PROTOCOL.md sec 8.1).
//
// Access-Control-Allow-Origin: * is safe here ONLY because there is deliberately
// no Access-Control-Allow-Credentials. Without it a browser will not attach
// cookies to a cross-origin request, so there are no ambient credentials for a
// hostile page to ride on -- a locally-run client must present a bearer token,
// which it can only have because its owner pasted it in.
//
// Never add Allow-Credentials here. Doing so alongside a wildcard origin is
// rejected by browsers, and doing so with a reflected origin would hand every
// site on the internet the ability to act as a logged-in player.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Max-Age: 600');
header('Vary: Origin');

// Preflight, which carries no credentials and must be answered before any
// authentication check.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}


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
