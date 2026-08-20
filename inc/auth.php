<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sso.php';

const SESSION_COOKIE_LIFETIME = 60 * 60 * 24 * 30; // 30 days

function session_init(): void
{
    static $started = false;
    if ($started) {
        return;
    }
    // Own save path + re-enabled per-request GC -- same reasoning as every
    // other game in this repo: this droplet's phpsessionclean systemd timer
    // sweeps /var/lib/php/sessions on its own static cutoff, completely
    // outside any PHP request, and would silently log players out way
    // sooner than the 30-day cookie implies if sessions lived there.
    $sessionPath = __DIR__ . '/../storage/sessions';
    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0700, true);
    }
    ini_set('session.save_path', $sessionPath);
    ini_set('session.gc_maxlifetime', (string) SESSION_COOKIE_LIFETIME);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_name(config()['session_name']);
    session_set_cookie_params([
        'lifetime' => SESSION_COOKIE_LIFETIME,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $started = true;
}

function current_user(): ?array
{
    session_init();
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        // Optional single sign-on. Skipped entirely unless a shared secret is
        // configured, so a standalone install never touches this path.
        //
        // Deliberately does NOT bypass is_allowed_email() -- SSO proves only WHO
        // the visitor is, never that they are allowed into this particular game.
        $ssoEmail = verify_sso_cookie();
        if ($ssoEmail !== null && is_allowed_email($ssoEmail)) {
            $user = provision_and_login($ssoEmail);
        }
    }
    if ($user === null) {
        // $_SERVER['REQUEST_URI'] is the UPSTREAM view (nginx strips the
        // /scumhouse/ prefix before proxying here) -- always prepend it
        // back so the post-login redirect lands on the real client-facing URL.
        $next = APP_PATH . ($_SERVER['REQUEST_URI'] ?? '/lobby.php');
        header('Location: ' . APP_PATH . '/auth/magic-request.php?next=' . urlencode($next));
        exit;
    }
    return $user;
}

/** Shared by the magic-link callback and the SSO auto-login path: find-or-create the
 * local user row for $email, log them in, and return the session user array. */
function provision_and_login(string $email): array
{
    $db = db();
    $userStmt = $db->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();

    if (!$user) {
        $displayName = strstr($email, '@', true);
        $displayName = $displayName !== false ? $displayName : $email;
        $db->prepare('INSERT INTO users (email, display_name, last_login_at) VALUES (?, ?, NOW())')
           ->execute([$email, $displayName]);
        $newId = (int) $db->lastInsertId();
        $userStmt2 = $db->prepare('SELECT * FROM users WHERE id=?');
        $userStmt2->execute([$newId]);
        $user = $userStmt2->fetch();
    } else {
        $db->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);
    }

    $sessionUser = [
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'display_name' => $user['display_name'],
    ];
    log_in_user($sessionUser);
    return $sessionUser;
}

/** Only ever redirect to a same-site relative path -- never trust a raw "next" param (open-redirect vector); "//evil.com" is protocol-relative despite the single leading slash. */
function safe_next_path(?string $next): string
{
    if ($next && str_starts_with($next, APP_PATH . '/') && !str_starts_with($next, '//')) {
        return $next;
    }
    return APP_PATH . '/lobby.php';
}

function log_in_user(array $user): void
{
    session_init();
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
}

function logout(): void
{
    session_init();
    $_SESSION = [];
    session_destroy();
}

function is_admin(array $user): bool
{
    return in_array($user['email'], config()['admin_emails'] ?? [], true);
}

function require_admin(): array
{
    $user = require_login();
    if (!is_admin($user)) {
        http_response_code(403);
        exit("You don't have access to this page.");
    }
    return $user;
}

/**
 * Is this address invited?
 *
 * Two modes, chosen by config()['allowlist']['mode']:
 *
 *   'local'  -- this app owns its allowed_emails table, managed from
 *               public/admin/access.php. The default, and the only one a
 *               standalone install needs.
 *   'portal' -- defer to a shared table in ANOTHER database on the same MySQL
 *               server, so several games can sit behind one invite list. Needs
 *               a read grant on that table:
 *                 GRANT SELECT ON <portal_db>.allowed_emails TO '<user>'@'localhost';
 *
 * Deliberately fails CLOSED: an unreachable or misconfigured allowlist denies
 * everyone rather than admitting everyone.
 */
function is_allowed_email(string $email): bool
{
    $cfg = config()['allowlist'] ?? ['mode' => 'local'];
    $mode = $cfg['mode'] ?? 'local';

    if ($mode === 'portal') {
        $portalDb = $cfg['portal_db'] ?? null;
        if (!is_string($portalDb) || !preg_match('/^[A-Za-z0-9_]+$/', $portalDb)) {
            // Never interpolate an unvalidated identifier into SQL, and never
            // silently fall through to letting everyone in.
            error_log('scumhouse: allowlist mode is "portal" but portal_db is not a valid identifier');
            return false;
        }
        $stmt = db()->prepare("SELECT 1 FROM `{$portalDb}`.allowed_emails WHERE email=?");
        $stmt->execute([$email]);
        return (bool) $stmt->fetchColumn();
    }

    $stmt = db()->prepare('SELECT 1 FROM allowed_emails WHERE email=?');
    $stmt->execute([$email]);
    return (bool) $stmt->fetchColumn();
}
