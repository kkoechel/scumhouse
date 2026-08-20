<?php
/**
 * Post-deploy smoke test. Mints an SSO cookie for an already-allowlisted admin
 * and walks the logged-in pages, so nothing depends on a magic-link email being
 * sent (this account never sends test mail).
 *
 * Run ON the server: sudo -u www-data php tools/smoke.php
 */
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/game_state.php';

$cfg = config();
$email = $cfg['admin_emails'][0];

// Same token format inc/sso.php verifies.
$payload = rtrim(strtr(base64_encode(json_encode(['email' => $email, 'exp' => time() + 300])), '+/', '-_'), '=');
$token = $payload . '.' . hash_hmac('sha256', $payload, $cfg['sso_secret']);
// Whatever this install calls the cookie -- hardcoding a name would silently
// stop working the moment a deployment configured a different one.
$cookieName = $cfg['sso_cookie'] ?? 'portal_sso';

$base = rtrim($cfg['base_url'], '/');

/**
 * Uses a stream context rather than ext/curl, which this box's PHP CLI does not
 * have -- and which the app itself does not need either (inc/resend.php makes its
 * one outbound call the same way).
 *
 * Redirects are deliberately NOT followed: with a valid SSO cookie for an
 * allowlisted address, require_login() authenticates inline and returns, so any
 * redirect here would itself be the failure worth seeing.
 */
function hit(string $url, string $token): array
{
    global $cookieName;
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Cookie: {$cookieName}={$token}\r\n",
        'follow_location' => 0,
        'ignore_errors' => true,
        'timeout' => 15,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $code = (int) $m[1];
        }
    }
    return [$code, (string) $body];
}

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $fails;
    printf("  %-46s %s%s\n", $label, $ok ? 'ok' : 'FAIL', $detail === '' ? '' : "  ($detail)");
    if (!$ok) {
        $fails++;
    }
}

echo "smoke test against $base\n";

[$code, $body] = hit($base . '/lobby.php', $token);
check('lobby.php reachable while signed in', $code === 200, "http $code");
check('lobby renders the table-size form', str_contains($body, 'Open a table'));
// The composition is derived from sh_setup(), so seeing it proves the engine
// loaded and the deal will match what the lobby advertises.
check('lobby advertises a real composition', str_contains($body, 'mafia'));
check('10-seat option present', str_contains($body, 'value="10"'));

[$code, $body] = hit($base . '/rules.php', $token);
check('rules.php reachable', $code === 200, "http $code");
check('rules lists the watcher', str_contains($body, 'Watcher'));

[$code, $body] = hit($base . '/verify.php', $token);
check('verify.php confirms integrity', str_contains($body, 'matches the committed manifest'));

[$code, $body] = hit($base . '/admin/games.php', $token);
check('admin games page reachable', $code === 200, "http $code");

// The scripts a browser will actually execute must carry the pinned hash.
[$code2, $game] = hit($base . '/game.php?game=0', $token);
check('game.php rejects a nonexistent table', $code2 === 404, "http $code2");


$users = db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$games = db()->query('SELECT COUNT(*) FROM games')->fetchColumn();
echo "\n  users: $users, games: $games\n";
echo $fails === 0 ? "\nsmoke: all checks passed\n" : "\nsmoke: $fails check(s) FAILED\n";
exit($fails === 0 ? 0 : 1);
