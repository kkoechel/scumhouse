<?php
/**
 * OPTIONAL single sign-on: verifies a shared cookie issued by a portal that
 * fronts several games. Inert unless config()['sso_secret'] is set, which a
 * standalone install leaves null.
 *
 * Token format (must match portal/inc/sso.php exactly):
 *   <payload>.<hmac_hex>
 *   payload = base64url(json_encode(['email' => string, 'exp' => unix_timestamp]))
 *   hmac_hex = hash_hmac('sha256', <payload string, not decoded>, sso_secret)
 *
 * This only proves WHO the visitor is (an email the portal itself verified via its own
 * magic-link flow). It intentionally does NOT bypass this game's own per-game invite
 * allowlist -- callers must still check is_allowed_email() before treating this as a
 * valid login for this specific game.
 */
function verify_sso_cookie(): ?string
{
    // Optional feature. A standalone install leaves sso_secret null and this
    // returns immediately, before touching a cookie at all.
    $secret = config()['sso_secret'] ?? null;
    if (!is_string($secret) || $secret === '') {
        return null;
    }

    $raw = $_COOKIE[config()['sso_cookie'] ?? 'portal_sso'] ?? '';
    if ($raw === '') {
        return null;
    }

    $dot = strrpos($raw, '.');
    if ($dot === false) {
        return null;
    }
    $payload = substr($raw, 0, $dot);
    $sig = substr($raw, $dot + 1);

    $expected = hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, $sig)) {
        return null;
    }

    $json = base64_decode(strtr($payload, '-_', '+/'), true);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['email']) || empty($data['exp'])) {
        return null;
    }
    if ((int) $data['exp'] < time()) {
        return null;
    }

    $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
    return $email !== false ? strtolower($email) : null;
}
