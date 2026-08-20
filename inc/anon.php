<?php
/**
 * Server half of the Scumhouse protocol (PROTOCOL.md). Pure functions -- no
 * database, no session, no superglobals. Kept separate from the endpoints so
 * tests/crypto_test.php can exercise it directly, and so it is easy to audit
 * that nothing in here reaches for a user identity.
 *
 * Everything is stock ext/openssl plus hash(). No gmp, no bcmath: the FDH is
 * defined to land below the modulus by construction (top bit cleared) precisely
 * so this file never has to do bignum arithmetic.
 */

const SH_MOD_BYTES = 256; // RSA-2048

/* ---------------- base64url ---------------- */

function sh_b64u(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function sh_unb64u(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $out = base64_decode($s, true);
    return $out === false ? '' : $out;
}

/* ---------------- RSA blind-signature credentials ---------------- */

/** Generates the per-game credential key. cred_d is the one server secret whose
 * leak would let someone MINT extra anon identities -- it can never de-anonymise
 * an existing one, because no blinded value is ever stored. */
function sh_new_credential_key(): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($key === false) {
        throw new RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
    }
    openssl_pkey_export($key, $pem);
    $d = openssl_pkey_get_details($key);
    return [
        'n' => sh_b64u(str_pad($d['rsa']['n'], SH_MOD_BYTES, "\x00", STR_PAD_LEFT)),
        'e' => sh_b64u($d['rsa']['e']),
        'pem' => $pem,
    ];
}

function sh_mgf1(string $seed, int $length): string
{
    $out = '';
    for ($counter = 0; strlen($out) < $length; $counter++) {
        $out .= hash('sha256', $seed . pack('N', $counter), true);
    }
    return substr($out, 0, $length);
}

/** Must byte-for-byte match SH.fdh() in public/js/crypto.js. The cleared top bit
 * is what guarantees the result is below any 2048-bit modulus. */
function sh_fdh(string $message, int $modBytes = SH_MOD_BYTES): string
{
    $out = sh_mgf1($message, $modBytes);
    $out[0] = chr(ord($out[0]) & 0x7f);
    return $out;
}

/** Raw RSA on the client's blinded value: s' = m^d mod n, no padding.
 * The server cannot recover the underlying message and must not try to. */
function sh_blind_sign(string $pem, string $blindedB64): ?string
{
    $blinded = sh_unb64u($blindedB64);
    if (strlen($blinded) !== SH_MOD_BYTES) {
        return null;
    }
    $key = openssl_pkey_get_private($pem);
    if ($key === false) {
        return null;
    }
    if (!openssl_private_encrypt($blinded, $sig, $key, OPENSSL_NO_PADDING)) {
        return null;
    }
    return sh_b64u(str_pad($sig, SH_MOD_BYTES, "\x00", STR_PAD_LEFT));
}

/** Verifies an UNBLINDED credential: s^e mod n == FDH(anon_pub). */
function sh_verify_credential(string $pem, string $anonPubJson, string $sigB64): bool
{
    $sig = sh_unb64u($sigB64);
    if (strlen($sig) !== SH_MOD_BYTES) {
        return false;
    }
    $key = openssl_pkey_get_private($pem);
    if ($key === false) {
        return false;
    }
    $pub = openssl_pkey_get_details($key)['key'];
    if (!openssl_public_decrypt($sig, $recovered, $pub, OPENSSL_NO_PADDING)) {
        return false;
    }
    $recovered = str_pad($recovered, SH_MOD_BYTES, "\x00", STR_PAD_LEFT);
    return hash_equals(sh_fdh($anonPubJson), $recovered);
}

/* ---------------- P-256 ---------------- */

// DER SubjectPublicKeyInfo prefix for id-ecPublicKey / prime256v1, followed by
// the 65-byte uncompressed point. Fixed bytes -- there is nothing to compute.
const SH_P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

function sh_p256_pub_pem(string $rawPointB64): ?string
{
    $point = sh_unb64u($rawPointB64);
    if (strlen($point) !== 65 || $point[0] !== "\x04") {
        return null;
    }
    $der = hex2bin(SH_P256_SPKI_PREFIX) . $point;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function sh_der_int(string $v): string
{
    $v = ltrim($v, "\x00");
    if ($v === '') {
        $v = "\x00";
    }
    if (ord($v[0]) & 0x80) {
        $v = "\x00" . $v;
    }
    return "\x02" . chr(strlen($v)) . $v;
}

/** WebCrypto emits P-256 signatures as raw r||s; openssl_verify wants DER. */
function sh_p256_sig_der(string $raw): ?string
{
    if (strlen($raw) !== 64) {
        return null;
    }
    $seq = sh_der_int(substr($raw, 0, 32)) . sh_der_int(substr($raw, 32, 32));
    return "\x30" . chr(strlen($seq)) . $seq;
}

/** The authorisation check for every anonymous request: does this payload really
 * come from the holder of slot N's signing key? Nothing else authenticates an
 * /anon/ request -- there is no session, by design. */
function sh_verify_slot_sig(string $sigkPubB64, string $payload, string $sigB64): bool
{
    $pem = sh_p256_pub_pem($sigkPubB64);
    $der = sh_p256_sig_der(sh_unb64u($sigB64));
    if ($pem === null || $der === null) {
        return false;
    }
    return openssl_verify($payload, $der, $pem, OPENSSL_ALGO_SHA256) === 1;
}

/* ---------------- ECIES (server seals, client opens) ---------------- */

/** Seals a payload to a slot's idk public key. Blob layout matches
 * SH.eciesOpen(): ephemeral_pub(65) || iv(12) || ciphertext||tag.
 *
 * The server knows what it sealed -- it composed the deck. What it cannot do is
 * tell WHO holds the key it sealed to, which is the property that matters. */
function sh_ecies_seal(string $recipientIdkPubB64, array $payload, string $info): ?string
{
    $recipientPem = sh_p256_pub_pem($recipientIdkPubB64);
    if ($recipientPem === null) {
        return null;
    }
    $eph = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if ($eph === false) {
        return null;
    }
    $shared = openssl_pkey_derive($recipientPem, $eph, 32);
    if ($shared === false) {
        return null;
    }
    $aesKey = hash_hkdf('sha256', $shared, 32, $info, '');

    $details = openssl_pkey_get_details($eph);
    $ephPoint = "\x04"
        . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    $iv = random_bytes(12);
    $ct = openssl_encrypt(
        json_encode($payload),
        'aes-256-gcm',
        $aesKey,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );
    if ($ct === false) {
        return null;
    }
    return sh_b64u($ephPoint . $iv . $ct . $tag);
}

/* ---------------- canonical ordering ---------------- */

/** Slot order is sha256(anon_pub) ascending. Neither the server nor a player can
 * steer it: a player would have to grind keypairs to land on a chosen index, and
 * the server does not get to choose at all. */
function sh_anon_pub_json(string $idkPub, string $sigkPub): string
{
    return json_encode(['idk' => $idkPub, 'sigk' => $sigkPub], JSON_UNESCAPED_SLASHES);
}

function sh_pub_hash(string $anonPubJson): string
{
    return hash('sha256', $anonPubJson);
}

/** The message a retrieval token signs. Domain-separated from the registration
 * credential (which signs an anon_pub JSON) so the two can share one RSA key
 * without a token ever being redeemable as a credential, or vice versa. */
function sh_token_message(int $gameId, int $nightNo, string $nonce): string
{
    return json_encode([
        't' => 'scumhouse/retrieval/v1',
        'game' => $gameId,
        'night' => $nightNo,
        'nonce' => $nonce,
    ], JSON_UNESCAPED_SLASHES);
}
