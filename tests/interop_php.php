<?php
/**
 * Server side of the JS<->PHP crypto interop test. Pairs with
 * tests/interop_node.mjs; tests/run_interop.sh drives both and shuttles
 * /tmp/sh/state.json between this machine and the droplet.
 *
 * Requires nothing but ext/openssl -- if this ever starts needing gmp or bcmath,
 * the protocol has drifted from PROTOCOL.md sec 4 and the drift is the bug.
 */

// Resolved two ways because this script is invoked from two layouts: from the
// repository (where anon.php is in ../inc/), and from a flat directory when the
// PHP half is shuttled to another host because the dev machine has no PHP.
require_once is_file(__DIR__ . '/anon.php') ? __DIR__ . '/anon.php' : __DIR__ . '/../inc/anon.php';

define('STATE', getenv('SH_STATE') ?: '/tmp/scumhouse-interop/state.json');
const CARD_INFO = 'scumhouse/card/v1';

$st = json_decode(file_get_contents(STATE), true);
$step = $argv[1] ?? '';

function save(array $st): void
{
    file_put_contents(STATE, json_encode($st, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

if ($step === 'credkey') {
    $key = sh_new_credential_key();
    $st['cred_n'] = $key['n'];
    $st['cred_e'] = $key['e'];
    $st['cred_pem'] = $key['pem'];

    // The canonical anon_pub string must be byte-identical on both sides, because
    // the FDH -- and therefore every credential -- is computed over those bytes.
    $php = sh_anon_pub_json($st['a']['idkPub'], $st['a']['sigkPub']);
    if ($php !== $st['aPubJson']) {
        fail("canonical anon_pub differs:\n  js : {$st['aPubJson']}\n  php: {$php}");
    }
    save($st);
    echo "credkey: ok (canonical anon_pub matches JS byte-for-byte)\n";
}

if ($step === 'blindsign') {
    $sig = sh_blind_sign($st['cred_pem'], $st['blinded']);
    if ($sig === null) {
        fail('sh_blind_sign returned null');
    }
    $st['blind_sig'] = $sig;
    save($st);
    echo "blindsign: ok\n";
}

if ($step === 'verifycred') {
    if (!sh_verify_credential($st['cred_pem'], $st['aPubJson'], $st['credential'])) {
        fail('unblinded credential did not verify -- FDH or blinding disagree across languages');
    }
    // A credential is a signature over THESE public keys; it must not carry over
    // to a different identity, or one player could register N slots.
    if (sh_verify_credential($st['cred_pem'], $st['bPubJson'], $st['credential'])) {
        fail('credential verified against a DIFFERENT anon_pub');
    }
    echo "verifycred: ok (valid accepted, replayed-onto-other-key rejected)\n";
}

if ($step === 'seal') {
    $card = ['role' => 'MAFIA', 'slot' => 3, 'team' => [3, 5]];
    $blob = sh_ecies_seal($st['a']['idkPub'], $card, CARD_INFO);
    if ($blob === null) {
        fail('sh_ecies_seal returned null');
    }
    $st['sealed_card'] = $blob;
    save($st);
    echo "seal: ok\n";
}

if ($step === 'verifysig') {
    if (!sh_verify_slot_sig($st['a']['sigkPub'], $st['action_payload'], $st['action_sig'])) {
        fail('valid slot signature rejected');
    }
    // Flip one character of the payload: the signature must stop verifying, or
    // an operator could rewrite a night action in flight.
    $tampered = str_replace('"target":9', '"target":4', $st['action_payload']);
    if ($tampered === $st['action_payload']) {
        fail('tamper fixture did not actually change the payload');
    }
    if (sh_verify_slot_sig($st['a']['sigkPub'], $tampered, $st['action_sig'])) {
        fail('tampered payload still verified');
    }
    // And it must not verify under a different slot's key.
    if (sh_verify_slot_sig($st['b']['sigkPub'], $st['action_payload'], $st['action_sig'])) {
        fail('signature verified under the wrong slot key');
    }
    echo "verifysig: ok (valid accepted; tampered payload and wrong key rejected)\n";
}

/* ---- the two-lock envelope (PROTOCOL.md sec 5.2) ---- */

if ($step === 'tokensign') {
    $sig = sh_blind_sign($st['cred_pem'], $st['tok_blinded']);
    if ($sig === null) {
        fail('sh_blind_sign refused the retrieval token');
    }
    $st['tok_blind_sig'] = $sig;
    save($st);
    echo "tokensign: ok\n";
}

if ($step === 'tokenredeem') {
    $msg = sh_token_message(1, 2, $st['nonce']);
    if (!sh_verify_credential($st['cred_pem'], $msg, $st['token'])) {
        fail('retrieval token did not verify -- tokenMessage() differs across languages');
    }
    // Domain separation: registration credentials and retrieval tokens share one
    // RSA key, so neither may be redeemable as the other.
    if (sh_verify_credential($st['cred_pem'], $st['aPubJson'], $st['token'])) {
        fail('a retrieval token verified as a registration credential');
    }
    if (sh_verify_credential($st['cred_pem'], $msg, $st['credential'])) {
        fail('a registration credential verified as a retrieval token');
    }
    // Replaying the token for a different night must fail too.
    if (sh_verify_credential($st['cred_pem'], sh_token_message(1, 3, $st['nonce']), $st['token'])) {
        fail('a night-2 token verified for night 3');
    }

    // The server holds every inner key and every envelope, and still cannot read
    // one: it has no investigator private key to strip the outer seal with.
    $sealed = sh_ecies_seal($st['eph_pub'], ['inner_key' => $st['inner_key'], 'target' => 42], 'scumhouse/innerkey/v1');
    if ($sealed === null) {
        fail('could not seal the inner key to the ephemeral key');
    }
    $st['sealed_key'] = $sealed;
    save($st);
    echo "tokenredeem: ok (token valid; not interchangeable with a credential; night-bound)\n";
}

if ($step === 'batchseal') {
    // Stands in for public/anon/answers.php: one sealed answer per redemption,
    // each to the one-use key its asker supplied.
    $batch = [];
    foreach ($st['batch_pubs'] as $i => $pub) {
        $sealed = sh_ecies_seal($pub, ['inner_key' => str_repeat('x', 43), 'target' => $i === 1 ? 77 : 11], 'scumhouse/innerkey/v1');
        if ($sealed === null) {
            fail("could not seal batch entry $i");
        }
        $batch[] = $sealed;
    }
    // answers.php shuffles for the same reason: row order must not track the order
    // questions arrived in.
    shuffle($batch);
    $st['batch'] = $batch;
    save($st);
    echo "batchseal: ok (" . count($batch) . " answers, order shuffled)\n";
}
