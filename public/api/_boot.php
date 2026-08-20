<?php
/**
 * Bootstrap for the ORDINARY, logged-in endpoints -- the public half of the game
 * (day thread, votes, lobby) plus the one authenticated step of the credential
 * dance. Anything that must not know who is calling belongs in public/anon/.
 */
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/game_state.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

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


function sh_api_in(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function sh_api_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function sh_api_fail(string $msg, int $code = 400): void
{
    sh_api_out(['ok' => false, 'error' => $msg], $code);
}

/** Loads a game the current user is actually seated in. */
function sh_require_seat(array $user, int $gameId): array
{
    $game = sh_game($gameId);
    if ($game === null) {
        sh_api_fail('no such game', 404);
    }
    $stmt = db()->prepare('SELECT * FROM game_players WHERE game_id=? AND user_id=?');
    $stmt->execute([$gameId, $user['id']]);
    $seat = $stmt->fetch();
    if (!$seat) {
        sh_api_fail('you are not in this game', 403);
    }
    return [$game, $seat];
}
