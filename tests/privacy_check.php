<?php
/**
 * Static guard on the privacy invariants in PROTOCOL.md sec 7.
 *
 * These are the mistakes that would silently un-anonymise the game without
 * breaking a single feature -- no test would fail, no page would look wrong, and
 * the database would quietly start recording who the mafia are. So they get
 * checked mechanically instead of remembered.
 *
 * Run: php tests/privacy_check.php
 */

$root = dirname(__DIR__);
$failures = [];

function check(array &$failures, bool $ok, string $msg): void
{
    if (!$ok) {
        $failures[] = $msg;
    }
}

/**
 * Returns a file's PHP with all comments removed.
 *
 * Every check below has to run against code only. This file's own rules are
 * documented in the very files it inspects -- _boot.php's warning block names
 * every forbidden token, game_state.php's header explains which joins are
 * allowed -- so matching raw text makes the checker fire on its own
 * documentation and, worse, rewards deleting the explanation.
 */
function sh_code_only(string $file): string
{
    $out = '';
    foreach (token_get_all(file_get_contents($file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }
    return $out;
}

/* --- 1. The anonymous endpoints must not be able to identify anyone --- */

$forbidden = [
    'session_init' => 'starts a session',
    'current_user' => 'resolves the logged-in user',
    'require_login' => 'requires a login',
    'require_admin' => 'requires an admin login',
    '$_COOKIE' => 'reads a cookie',
    '$_SESSION' => 'reads the session',
    'REMOTE_ADDR' => 'reads the client IP',
    'HTTP_X_FORWARDED_FOR' => 'reads the forwarded client IP',
    'HTTP_USER_AGENT' => 'reads the user agent',
    'HTTP_REFERER' => 'reads the referrer',
];

foreach (glob($root . '/public/anon/*.php') as $file) {
    $stripped = sh_code_only($file);
    foreach ($forbidden as $needle => $what) {
        check($failures, !str_contains($stripped, $needle),
            basename($file) . " $what (found '$needle') -- see public/anon/_boot.php");
    }
}

/* --- 2. The client must not attach credentials to anonymous requests --- */

$cryptoJs = file_get_contents($root . '/public/js/crypto.js');
check($failures, str_contains($cryptoJs, "credentials: 'omit'"),
    "crypto.js anonPost() must send credentials:'omit'");
check($failures, str_contains($cryptoJs, "referrerPolicy: 'no-referrer'"),
    "crypto.js anonPost() must send referrerPolicy:'no-referrer'");

$gameJs = file_get_contents($root . '/public/js/game.js');
// Every /anon/ call in the client has to go through SH.anonPost, which is the
// one place the omit-credentials policy lives.
preg_match_all("#/anon/[a-z-]+\.php#", $gameJs, $paths);
foreach (array_unique($paths[0]) as $path) {
    $quoted = preg_quote($path, '#');
    check($failures, (bool) preg_match("#SH\.anonPost\(APP \+ '" . $quoted . "'#", $gameJs),
        "game.js reaches $path without going through SH.anonPost()");
}
// crypto.js holds the private keys, so it gets the strictest rule in the file:
// exactly one outbound call, the awaited fetch() in anonPost() already asserted
// above to omit credentials. Counting call sites is far less brittle than trying
// to parse each one, and it catches the case that actually matters -- a second
// network path appearing in the one file that can see key material.
//
// (Counting bare 'fetch(' would also match this file's own header comment
// warning people not to add one, which is why the awaited form is counted.)
check($failures, substr_count($cryptoJs, 'await fetch(') === 1,
    'crypto.js must contain exactly one awaited fetch() -- the one in anonPost()');

// The other ways a page can talk to a server, none of which anonPost() polices.
foreach (['XMLHttpRequest', 'sendBeacon', 'WebSocket', 'EventSource', 'new Image('] as $vector) {
    check($failures, !str_contains($cryptoJs, $vector),
        "crypto.js must not use $vector -- it is an outbound path that bypasses anonPost()");
}

/* --- 3. No query may join a slot to a user, except the flip --- */

// PHP's glob() has no true recursive wildcard, so the two levels are listed.
$phpFiles = array_merge(
    glob($root . '/inc/*.php'),
    glob($root . '/public/*.php'),
    glob($root . '/public/*/*.php')
);
foreach ($phpFiles as $file) {
    $src = sh_code_only($file);
    if (!preg_match('/anon_slots|slot_roles/i', $src)) {
        continue;
    }
    // A statement naming both an anon table and the players/users tables is the
    // exact shape of the join this protocol forbids.
    foreach (preg_split('/;\s*\n/', $src) as $stmt) {
        if (!preg_match('/anon_slots|slot_roles/i', $stmt)) {
            continue;
        }
        // `flips` is the one sanctioned bridge (PROTOCOL.md sec 9), so a statement
        // writing to it is allowed to name both sides.
        if (preg_match('/\bflips\b/i', $stmt)) {
            continue;
        }
        if (preg_match('/\bgame_players\b|\busers\b/i', $stmt)) {
            check($failures, false,
                basename($file) . ' has a statement mentioning both an anon table and users/game_players -- '
                . 'if that is a JOIN it breaks PROTOCOL.md sec 5');
        }
    }
}

/* --- 4. feed.php must never ship the deal --- */

$feed = sh_code_only($root . '/public/api/feed.php');
check($failures, !preg_match('/slot_roles/i', $feed),
    'feed.php must not read slot_roles -- that is the server-only half of the deal');

/* --- 5. The SRI manifest must match the files it pins --- */

// A stale manifest is worse than none: the page would refuse to load its own
// scripts, and the temptation would be to "fix" it by dropping the integrity
// attribute. Catching drift here keeps that from ever being the easy option.
$manifestRaw = @file_get_contents($root . '/public/integrity.json');
$manifest = $manifestRaw === false ? null : json_decode($manifestRaw, true);
check($failures, is_array($manifest) && !empty($manifest['files']),
    'public/integrity.json is missing or empty -- run tools/integrity.sh');

if (is_array($manifest) && !empty($manifest['files'])) {
    foreach ($manifest['files'] as $file => $expected) {
        $path = $root . '/public/' . $file;
        $actual = is_readable($path) ? 'sha384-' . base64_encode(hash_file('sha384', $path, true)) : null;
        check($failures, $actual !== null && hash_equals($expected, $actual),
            "integrity.json is stale for $file -- run tools/integrity.sh");
    }
    // Anything that can see key material must be pinned, not just present.
    foreach (['js/crypto.js', 'js/game.js'] as $required) {
        check($failures, isset($manifest['files'][$required]),
            "$required must be pinned in integrity.json");
    }
}

/* --- report --- */

if ($failures) {
    fwrite(STDERR, "PRIVACY CHECK FAILED\n\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    fwrite(STDERR, "\n" . count($failures) . " problem(s).\n");
    exit(1);
}
echo "privacy check: all invariants hold\n";
