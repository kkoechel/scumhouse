<?php
/**
 * Endpoint authorisation tests.
 *
 * The interop suite proves the crypto agrees across languages. It says nothing
 * about whether the ENDPOINTS enforce who may do what -- and those checks are the
 * ones standing between a modified client and someone else's night action.
 *
 * This spins a throwaway database and a PHP built-in server, builds game state
 * directly (P-256 keys and all, via ext-openssl), and then attacks the endpoints
 * with correctly and incorrectly signed requests.
 *
 * Needs a MySQL account that can create and drop its own test database:
 *
 *   CREATE USER 'sh_test'@'%' IDENTIFIED BY 'sh_test';
 *   GRANT ALL PRIVILEGES ON `scumhouse_endpoint_test`.* TO 'sh_test'@'%';
 *
 * Run: SH_TEST_DB_USER=sh_test SH_TEST_DB_PASS=sh_test php tests/endpoints.php
 *
 * The credentials are environment-driven rather than assumed to be root: many
 * MySQL installs authenticate root over a unix socket only, which PDO's TCP
 * connection cannot use.
 */

require_once dirname(__DIR__) . '/inc/anon.php';

$root = dirname(__DIR__);
$db = getenv('SH_TEST_DB') ?: 'scumhouse_endpoint_test';
$dbHost = getenv('SH_TEST_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('SH_TEST_DB_USER') ?: 'root';
$dbPass = getenv('SH_TEST_DB_PASS') ?: '';
$port = (int) (getenv('SH_TEST_PORT') ?: 8199);
$tree = sys_get_temp_dir() . '/scumhouse-endpoint-test';

$failed = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $failed;
    printf("  %-58s %s%s\n", $name, $cond ? 'ok' : 'FAIL', $detail === '' ? '' : "  ($detail)");
    if (!$cond) {
        $failed++;
    }
}

/* ---------- a client that can sign like a browser ---------- */

/** WebCrypto emits raw r||s; openssl emits DER. The server expects raw, so the
 * test client has to convert -- the mirror of sh_p256_sig_der(). */
function der_to_raw(string $der): string
{
    $off = 2;
    if (ord($der[1]) > 0x80) {
        $off += ord($der[1]) - 0x80;
    }
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$off + 1]);
        $v = substr($der, $off + 2, $len);
        $v = ltrim($v, "\x00");
        $parts[] = str_pad($v, 32, "\x00", STR_PAD_LEFT);
        $off += 2 + $len;
    }
    return $parts[0] . $parts[1];
}

function new_p256(): array
{
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $d = openssl_pkey_get_details($k);
    $point = "\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    return ['key' => $k, 'pub' => sh_b64u($point)];
}

function sign_as(array $kp, string $payload): string
{
    openssl_sign($payload, $der, $kp['key'], OPENSSL_ALGO_SHA256);
    return sh_b64u(der_to_raw($der));
}

function post_json(int $port, string $path, array $body): array
{
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($body),
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $out = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $code = (int) $m[1];
        }
    }
    return [$code, json_decode((string) $out, true) ?: []];
}

/* ---------- build a throwaway install ---------- */

echo "endpoint tests\n";

// This script DROPs its database. A contributor who points SH_TEST_DB at a real
// one would lose every game in it, so refuse anything that is not obviously
// disposable. Cheap, and the failure it prevents is unrecoverable.
if (!str_contains($db, 'test')) {
    fwrite(STDERR, "refusing to run: SH_TEST_DB ('$db') must contain 'test' -- this script drops it\n");
    exit(2);
}

$mysqlArgs = '-h' . escapeshellarg($dbHost) . ' -u' . escapeshellarg($dbUser)
    . ($dbPass === '' ? '' : ' -p' . escapeshellarg($dbPass));

exec("mysql $mysqlArgs -e " . escapeshellarg("DROP DATABASE IF EXISTS `$db`; CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;") . " 2>&1", $o, $rc);
if ($rc !== 0) {
    fwrite(STDERR, "could not create test database: " . implode("\n", $o) . "\n");
    exit(2);
}
exec("mysql $mysqlArgs " . escapeshellarg($db) . " < " . escapeshellarg("$root/schema.sql") . " 2>&1", $o, $rc);
if ($rc !== 0) {
    fwrite(STDERR, "could not load schema: " . implode("\n", $o) . "\n");
    exit(2);
}

exec("rm -rf " . escapeshellarg($tree));
exec("cp -r " . escapeshellarg($root) . " " . escapeshellarg($tree));
file_put_contents("$tree/inc/config.php", "<?php\nreturn " . var_export([
    'db' => ['host' => $dbHost, 'name' => $db, 'user' => $dbUser, 'pass' => $dbPass],
    'admin_emails' => ['admin@example.com'],
    'session_name' => 'sh_test',
    'base_url' => "http://127.0.0.1:$port",
    'resend_api_key' => 'unused',
    'resend_from' => 'x <x@example.com>',
    'allowlist' => ['mode' => 'local', 'portal_db' => null],
    'sso_secret' => null,
    'sso_cookie' => 'portal_sso',
], true) . ";\n");

// Deliberately the APPLICATION's own connection helper, not a hand-rolled PDO.
// The timezone assertion below is only a real regression guard if it exercises
// the same setup the app uses -- a private connection here could pass while the
// app's silently drifted.
require_once "$tree/inc/db.php";
$pdo = db();

// A minimal live game: two players, two slots, roles assigned by hand.
$pdo->exec("INSERT INTO users (id,email,display_name) VALUES (1,'a@example.com','ann'),(2,'b@example.com','bob')");
// A real per-game credential key, so the forged-token check below exercises
// actual RSA verification rather than an absent key.
$cred = sh_new_credential_key();
$g = $pdo->prepare("INSERT INTO games (id,status,num_seats,setup_json,phase,phase_no,day_hours,night_hours,created_by,phase_ends_at,key_release_at,cred_n,cred_e,cred_d)
                    VALUES (1,'active',5,'{}','night',1,48,24,1,DATE_ADD(NOW(),INTERVAL 12 HOUR),DATE_ADD(NOW(),INTERVAL 6 HOUR),?,?,?)");
$g->execute([$cred['n'], $cred['e'], $cred['pem']]);
$pdo->exec("INSERT INTO game_players (game_id,user_id,seat_order,is_alive) VALUES (1,1,0,1),(1,2,1,1)");

$mafia = new_p256();
$town = new_p256();
$idk = new_p256();
$ins = $pdo->prepare('INSERT INTO anon_slots (game_id,slot_index,idk_pub,sigk_pub,pub_hash,credential_sig) VALUES (1,?,?,?,?,?)');
$ins->execute([0, $idk['pub'], $mafia['pub'], str_repeat('a', 64), 'x']);
$ins->execute([1, $idk['pub'], $town['pub'], str_repeat('b', 64), 'x']);
$pdo->exec("INSERT INTO slot_roles (game_id,slot_index,role) VALUES (1,0,'MAFIA'),(1,1,'TOWN')");

$server = proc_open(
    "php -S 127.0.0.1:$port -t " . escapeshellarg("$tree/public") . " >/dev/null 2>&1",
    [], $pipes, $tree
);
for ($i = 0; $i < 40; $i++) {
    usleep(100000);
    if (@fsockopen('127.0.0.1', $port, $e, $s, 0.2)) {
        break;
    }
}

/* ---------- the tests ---------- */

// Before anything else: the two clocks must agree. Every deadline in this game is
// written by MySQL and compared in PHP, so a timezone mismatch skews all of them
// at once -- silently, and only visibly once a phase ends at the wrong hour.
$mysqlNow = $pdo->query('SELECT NOW()')->fetchColumn();
$skew = abs(strtotime($mysqlNow) - time());
ok('MySQL and PHP agree on the time (every deadline depends on it)', $skew <= 2,
   $skew > 2 ? "skew {$skew}s -- inc/db.php should be pinning the session to UTC" : '');

$blob = sh_b64u(random_bytes(508));   // exactly the pinned size
$mkPost = fn(int $slot, string $ct) => json_encode(
    ['game' => 1, 'slot' => $slot, 'phase_no' => 1, 'phase' => 'night', 'ct' => $ct],
    JSON_UNESCAPED_SLASHES
);

[$code] = post_json($port, '/anon/post.php', ['game' => 1, 'slot' => 0, 'ct' => $blob, 'sig' => sign_as($mafia, $mkPost(0, $blob))]);
ok('a correctly signed channel post is accepted', $code === 200, "http $code");

[$code] = post_json($port, '/anon/post.php', ['game' => 1, 'slot' => 0, 'ct' => $blob, 'sig' => sign_as($town, $mkPost(0, $blob))]);
ok('a post signed by the WRONG slot key is rejected', $code === 403, "http $code");

$short = sh_b64u(random_bytes(200));
[$code] = post_json($port, '/anon/post.php', ['game' => 1, 'slot' => 0, 'ct' => $short, 'sig' => sign_as($mafia, $mkPost(0, $short))]);
ok('a wrong-size blob is rejected (length must not vary)', $code === 400, "http $code");

$mkAct = fn(int $slot, string $action, $target, $tslot) => json_encode(
    ['game' => 1, 'slot' => $slot, 'night' => 1, 'action' => $action, 'target' => $target, 'target_slot' => $tslot],
    JSON_UNESCAPED_SLASHES
);

[$code] = post_json($port, '/anon/action.php', ['game' => 1, 'slot' => 0, 'night' => 1, 'action' => 'kill', 'target' => 2, 'sig' => sign_as($mafia, $mkAct(0, 'kill', 2, null))]);
ok('the mafia slot may kill', $code === 200, "http $code");

[$code] = post_json($port, '/anon/action.php', ['game' => 1, 'slot' => 1, 'night' => 1, 'action' => 'kill', 'target' => 1, 'sig' => sign_as($town, $mkAct(1, 'kill', 1, null))]);
ok('a TOWN slot may not kill, even signed correctly', $code === 403, "http $code");

[$code] = post_json($port, '/anon/action.php', ['game' => 1, 'slot' => 0, 'night' => 1, 'action' => 'protect', 'target' => 2, 'sig' => sign_as($mafia, $mkAct(0, 'protect', 2, null))]);
ok('the mafia slot may not use the doctor action', $code === 403, "http $code");

$mkFlip = fn(int $slot, int $user, string $role) => json_encode(
    ['game' => 1, 'slot' => $slot, 'user' => $user, 'role' => $role], JSON_UNESCAPED_SLASHES
);
[$code] = post_json($port, '/anon/flip.php', ['game' => 1, 'slot' => 0, 'user' => 1, 'role' => 'MAFIA', 'sig' => sign_as($mafia, $mkFlip(0, 1, 'MAFIA'))]);
ok('a LIVING player may not flip', $code === 403, "http $code");

$pdo->exec("UPDATE game_players SET is_alive=0, died_phase_no=1, died_cause='kill' WHERE game_id=1 AND user_id=1");
[$code] = post_json($port, '/anon/flip.php', ['game' => 1, 'slot' => 0, 'user' => 1, 'role' => 'TOWN', 'sig' => sign_as($mafia, $mkFlip(0, 1, 'TOWN'))]);
ok('a dead player may not flip a role they were not dealt', $code === 403, "http $code");

[$code] = post_json($port, '/anon/flip.php', ['game' => 1, 'slot' => 0, 'user' => 1, 'role' => 'MAFIA', 'sig' => sign_as($mafia, $mkFlip(0, 1, 'MAFIA'))]);
ok('a dead player may flip their real role', $code === 200, "http $code");

$eph = new_p256();
$mkTrack = fn(int $slot) => json_encode(['game' => 1, 'slot' => $slot, 'night' => 1, 'target_slot' => 1, 'ephemeral_pub' => $eph['pub']], JSON_UNESCAPED_SLASHES);
[$code] = post_json($port, '/anon/track.php', ['game' => 1, 'slot' => 0, 'night' => 1, 'target_slot' => 1, 'ephemeral_pub' => $eph['pub'], 'sig' => sign_as($mafia, $mkTrack(0))]);
ok('a non-tracker slot may not submit a tracker query', $code === 403, "http $code");

$mkWatch = fn(int $slot) => json_encode(['game' => 1, 'slot' => $slot, 'night' => 1, 'target' => 2, 'ephemeral_pub' => $eph['pub']], JSON_UNESCAPED_SLASHES);
[$code] = post_json($port, '/anon/watch.php', ['game' => 1, 'slot' => 0, 'night' => 1, 'target' => 2, 'ephemeral_pub' => $eph['pub'], 'sig' => sign_as($mafia, $mkWatch(0))]);
ok('a non-watcher slot may not submit a watcher query', $code === 403, "http $code");

// Random bytes of the right length: a well-formed token that is not a signature.
[$code] = post_json($port, '/anon/redeem.php', ['game' => 1, 'night' => 1, 'nonce' => str_repeat('n', 24), 'token' => sh_b64u(random_bytes(256)), 'target' => 2, 'ephemeral_pub' => $eph['pub']]);
ok('a forged retrieval token is rejected', $code === 403, "http $code");

// ...and a token that IS a valid signature, but over a different night.
$otherNight = sh_blind_sign($cred['pem'], sh_b64u(sh_fdh(sh_token_message(1, 2, str_repeat('n', 24)))));
[$code] = post_json($port, '/anon/redeem.php', ['game' => 1, 'night' => 1, 'nonce' => str_repeat('n', 24), 'token' => $otherNight, 'target' => 2, 'ephemeral_pub' => $eph['pub']]);
ok('a token valid for another night is rejected', $code === 403, "http $code");

[$code, $body] = post_json($port, '/anon/answers.php', ['game' => 1, 'night' => 1]);
ok('answers are withheld before the release point', ($body['released'] ?? true) === false, "released=" . var_export($body['released'] ?? null, true));

$pdo->exec("UPDATE games SET key_release_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=1");
[$code, $body] = post_json($port, '/anon/answers.php', ['game' => 1, 'night' => 1]);
ok('answers open after the release point', ($body['released'] ?? false) === true);

/* ---------- forced flips (PROTOCOL.md sec 9) ---------- */

// user 2 (bob) is still alive at this point.
$mkReveal = fn(int $slot, int $subject, string $share) => json_encode(
    ['game' => 1, 'slot' => $slot, 'subject' => $subject, 'share' => $share], JSON_UNESCAPED_SLASHES
);
$share = '3:' . sh_b64u(random_bytes(32));

[$code] = post_json($port, '/anon/reveal-share.php', ['game' => 1, 'slot' => 0, 'subject' => 2, 'share' => $share, 'sig' => sign_as($mafia, $mkReveal(0, 2, $share))]);
ok('shares are NOT opened against a living player', $code === 403, "http $code");

$pdo->exec("UPDATE game_players SET is_alive=0, died_phase_no=1, died_cause='kill' WHERE game_id=1 AND user_id=2");

// Deadline is still in the future: a slow flip is not a refusal.
[$code] = post_json($port, '/anon/reveal-share.php', ['game' => 1, 'slot' => 0, 'subject' => 2, 'share' => $share, 'sig' => sign_as($mafia, $mkReveal(0, 2, $share))]);
ok('shares are withheld until the clock has actually stalled', $code === 409, "http $code");

$pdo->exec("UPDATE games SET phase_ends_at=DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id=1");

[$code] = post_json($port, '/anon/reveal-share.php', ['game' => 1, 'slot' => 0, 'subject' => 2, 'share' => $share, 'sig' => sign_as($town, $mkReveal(0, 2, $share))]);
ok('a reveal signed by the wrong slot is rejected', $code === 403, "http $code");

[$code, $body] = post_json($port, '/anon/reveal-share.php', ['game' => 1, 'slot' => 0, 'subject' => 2, 'share' => $share, 'sig' => sign_as($mafia, $mkReveal(0, 2, $share))]);
ok('a valid reveal is accepted once the game is genuinely stalled', $code === 200, "http $code");
ok('...and reports the threshold', ($body['needed'] ?? 0) === 4, 'needed=' . ($body['needed'] ?? '?'));

$bad = 'not-a-share';
[$code] = post_json($port, '/anon/reveal-share.php', ['game' => 1, 'slot' => 1, 'subject' => 2, 'share' => $bad, 'sig' => sign_as($town, $mkReveal(1, 2, $bad))]);
ok('a malformed share is rejected', $code === 400, "http $code");

// user 1 already flipped earlier in this file.
[$code] = post_json($port, '/anon/reveal-share.php', ['game' => 1, 'slot' => 0, 'subject' => 1, 'share' => $share, 'sig' => sign_as($mafia, $mkReveal(0, 1, $share))]);
ok('no shares are opened against someone who already flipped', $code === 409, "http $code");

/* ---------- teardown ---------- */

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
exec("pkill -f " . escapeshellarg("php -S 127.0.0.1:$port") . " 2>/dev/null");
exec("rm -rf " . escapeshellarg($tree));
exec("mysql $mysqlArgs -e " . escapeshellarg("DROP DATABASE IF EXISTS `$db`;") . " 2>/dev/null");

echo "\n";
if ($failed > 0) {
    echo "$failed endpoint check(s) FAILED\n";
    exit(1);
}
echo "all endpoint checks passed\n";
