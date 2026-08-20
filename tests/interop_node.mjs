/*
 * Client side of the JS<->PHP crypto interop test. Run via tests/run_interop.sh,
 * which shuttles /tmp/sh/state.json between this and interop_php.php on the
 * droplet. Each step reads the shared state, adds its outputs, writes it back.
 *
 * This exists because every value in PROTOCOL.md crosses a language boundary:
 * the FDH is computed on both sides, the blind signature is produced on one and
 * unblinded on the other, cards are sealed in PHP and opened in JS. A unit test
 * of either half alone would prove nothing.
 */
import fs from 'fs';
import vm from 'vm';
import path from 'path';
import { webcrypto } from 'node:crypto';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const STATE = process.env.SH_STATE || '/tmp/scumhouse-interop/state.json';

function loadNR() {
  const src = fs.readFileSync(path.join(ROOT, 'public/js/crypto.js'), 'utf8');
  const store = {};
  const sandbox = {
    crypto: globalThis.crypto ?? webcrypto, btoa, atob, TextEncoder, TextDecoder, console,
    localStorage: {
      getItem: (k) => (k in store ? store[k] : null),
      setItem: (k, v) => { store[k] = String(v); },
    },
  };
  sandbox.window = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(src, sandbox);
  return sandbox.SH;
}

const SH = loadNR();
const st = fs.existsSync(STATE) ? JSON.parse(fs.readFileSync(STATE, 'utf8')) : {};
const save = () => fs.writeFileSync(STATE, JSON.stringify(st, null, 1));
const step = process.argv[2];

const CARD_INFO = 'scumhouse/card/v1';
const ENVELOPE_INFO = 'scumhouse/envelope/v1';

if (step === 'identities') {
  // Two identities: one plays the mafia slot we seal a card to, the other is its
  // teammate, so the pairwise team key can be exercised from both directions.
  st.a = await SH.generateIdentity();
  st.b = await SH.generateIdentity();
  st.aPubJson = SH.anonPubJson(st.a);
  st.bPubJson = SH.anonPubJson(st.b);
  save();
  console.log('identities: ok');
}

if (step === 'blind') {
  const { blinded, r } = await SH.blindCredential(st.aPubJson, st.cred_n, st.cred_e);
  st.blinded = blinded;
  st.r = r;
  save();
  console.log('blind: ok');
}

if (step === 'unblind') {
  st.credential = SH.unblindCredential(st.blind_sig, st.r, st.cred_n);
  save();
  console.log('unblind: ok');
}

if (step === 'opencard') {
  const card = await SH.eciesOpen(st.sealed_card, st.a.idkPriv, CARD_INFO);
  if (card.role !== 'MAFIA' || card.slot !== 3) {
    throw new Error('card mismatch: ' + JSON.stringify(card));
  }
  if (JSON.stringify(card.team) !== JSON.stringify([3, 5])) {
    throw new Error('team mismatch: ' + JSON.stringify(card.team));
  }
  st.card_ok = true;
  // A card sealed to A must NOT open with B's key -- the whole deal depends on it.
  let leaked = false;
  try { await SH.eciesOpen(st.sealed_card, st.b.idkPriv, CARD_INFO); leaked = true; } catch (e) {}
  if (leaked) throw new Error('card opened with the wrong key');
  st.wrong_key_rejected = true;
  save();
  console.log('opencard: ok (and rejected the wrong key)');
}

if (step === 'sign') {
  st.action_payload = JSON.stringify({ game: 1, night: 2, slot: 3, action: 'kill', target: 9 });
  st.action_sig = await SH.signAnon(st.a.sigkPriv, st.action_payload);
  save();
  console.log('sign: ok');
}

if (step === 'team') {
  // Both directions of the pairwise ECDH must land on the same AES key, and a
  // cover blob must be indistinguishable garbage to the holder of that key.
  const kA = await SH.pairKey(st.a.idkPriv, st.b.idkPub);
  const kB = await SH.pairKey(st.b.idkPriv, st.a.idkPub);

  const long = 'x'.repeat(1500) + ' -- lynch the quiet one at dawn';
  const blobs = await SH.sealBlobs(kA, long);
  if (blobs.length !== 4) throw new Error('expected 4 chunks, got ' + blobs.length);

  const sizes = new Set(blobs.map((b) => b.length));
  sizes.add(SH.coverBlob().length);
  if (sizes.size !== 1) throw new Error('blob sizes differ: ' + [...sizes].join(','));

  const chunks = [];
  for (const b of blobs.concat([SH.coverBlob(), SH.coverBlob()])) {
    const c = await SH.openBlob(kB, b);
    if (c) chunks.push(c);
  }
  if (chunks.length !== 4) throw new Error('teammate recovered ' + chunks.length + ' chunks');
  chunks.sort((x, y) => x.index - y.index);
  const recovered = chunks.map((c) => c.body).join('');
  if (recovered !== long) throw new Error('message did not round-trip');

  // A third party (a fresh identity standing in for a town player) must recover
  // nothing at all from the same blobs.
  const c = await SH.generateIdentity();
  const kOutsider = await SH.pairKey(c.idkPriv, st.a.idkPub);
  for (const b of blobs) {
    if (await SH.openBlob(kOutsider, b)) throw new Error('outsider decrypted a team blob');
  }
  st.team_ok = true;
  save();
  console.log('team: ok (4 chunks, uniform size, outsider blind)');
}

if (step === 'recovery') {
  const code = SH.recoveryCode(st.a);
  if (JSON.stringify(SH.fromRecoveryCode(code)) !== JSON.stringify(st.a)) {
    throw new Error('recovery code did not round-trip');
  }
  const wrapped = await SH.wrapIdentity(st.a, 'correct horse battery staple');
  const back = await SH.unwrapIdentity(wrapped, 'correct horse battery staple');
  if (JSON.stringify(back) !== JSON.stringify(st.a)) throw new Error('wrap did not round-trip');
  let opened = false;
  try { await SH.unwrapIdentity(wrapped, 'wrong passphrase'); opened = true; } catch (e) {}
  if (opened) throw new Error('wrong passphrase opened the backup');
  console.log('recovery: ok');
}

/* ---- the two-lock envelope (PROTOCOL.md sec 5.2) ----
 * st.a plays the investigated player; st.b plays the investigator. */

if (step === 'envelope') {
  st.inner_key = SH.randomAesKey();
  const claim = JSON.stringify({ game: 1, account: 42, slot: 3 });
  const payload = { game: 1, account: 42, slot: 3, sig: await SH.signAnon(st.a.sigkPriv, claim) };
  const inner = await SH.innerSeal(st.inner_key, payload);
  st.envelope = await SH.eciesSeal(st.b.idkPub, { inner: inner }, ENVELOPE_INFO);
  st.nonce = SH.b64u(SH.randomBytes(16));
  const eph = await SH.ephemeralKeyPair();
  st.eph_priv = eph.priv;
  st.eph_pub = eph.pub;
  save();
  console.log('envelope: ok (sealed to the investigator, locked with an inner key)');
}

if (step === 'tokenblind') {
  const msg = SH.tokenMessage(1, 2, st.nonce);
  const { blinded, r } = await SH.blindCredential(msg, st.cred_n, st.cred_e);
  st.tok_blinded = blinded;
  st.tok_r = r;
  save();
  console.log('tokenblind: ok');
}

if (step === 'tokenunblind') {
  st.token = SH.unblindCredential(st.tok_blind_sig, st.tok_r, st.cred_n);
  save();
  console.log('tokenunblind: ok');
}

if (step === 'investigate') {
  // 1. open the answer the server sealed to our one-use ephemeral key
  const answer = await SH.eciesOpen(st.sealed_key, st.eph_priv, 'scumhouse/innerkey/v1');
  if (answer.inner_key !== st.inner_key) throw new Error('server returned the wrong inner key');

  // 2. strip the OUTER lock with the investigator's own key
  const outer = await SH.eciesOpen(st.envelope, st.b.idkPriv, ENVELOPE_INFO);

  // 3. strip the INNER lock with the key we just spent a token on
  const payload = await SH.innerOpen(answer.inner_key, outer.inner);
  if (payload.account !== 42 || payload.slot !== 3) {
    throw new Error('envelope payload wrong: ' + JSON.stringify(payload));
  }

  // 4. the target cannot lie: the claim is signed by the slot they claim to hold
  const claim = JSON.stringify({ game: payload.game, account: payload.account, slot: payload.slot });
  if (!await SH.verifyAnon(st.a.sigkPub, claim, payload.sig)) {
    throw new Error('slot signature did not verify');
  }
  const forged = JSON.stringify({ game: 1, account: 42, slot: 5 });
  if (await SH.verifyAnon(st.a.sigkPub, forged, payload.sig)) {
    throw new Error('a forged slot claim verified');
  }

  // 5. holding the inner key is NOT enough without the outer key -- this is the
  //    split that makes it safe to hand every inner key to the server.
  const outsider = await SH.generateIdentity();
  let leaked = false;
  try { await SH.eciesOpen(st.envelope, outsider.idkPriv, ENVELOPE_INFO); leaked = true; } catch (e) {}
  if (leaked) throw new Error('a non-investigator opened the envelope');

  // 6. ...and holding the outer key is not enough without the inner one.
  let innerLeaked = false;
  try { await SH.innerOpen(SH.randomAesKey(), outer.inner); innerLeaked = true; } catch (e) {}
  if (innerLeaked) throw new Error('the inner lock opened with the wrong key');

  console.log('investigate: ok (both locks required, target cannot lie)');
}

/* ---- the fixed release point (PROTOCOL.md sec 5.3) ----
 * Answers are published as one batch and each client finds its own by trial
 * decryption, so fetching the batch says nothing about which answer is yours. */

if (step === 'batchprep') {
  const mine = await SH.ephemeralKeyPair();
  st.batch_mine_priv = mine.priv;
  st.batch_pubs = [ (await SH.ephemeralKeyPair()).pub, mine.pub, (await SH.ephemeralKeyPair()).pub ];
  st.batch_mine_index = 1;
  save();
  console.log('batchprep: ok');
}

if (step === 'batchcollect') {
  let opened = 0;
  let got = null;
  for (const sealed of st.batch) {
    try {
      got = await SH.eciesOpen(sealed, st.batch_mine_priv, 'scumhouse/innerkey/v1');
      opened++;
    } catch (e) { /* somebody else's answer, which is the normal case */ }
  }
  if (opened !== 1) throw new Error('expected to open exactly 1 answer, opened ' + opened);
  if (got.target !== 77) throw new Error('opened the wrong answer: ' + JSON.stringify(got));
  console.log('batchcollect: ok (exactly one of ' + st.batch.length + ' answers opened)');
}

/* ---- the reverse envelope (PROTOCOL.md sec 5.4) ----
 * All client-side: the server only stores this one, because it can neither read
 * it nor attest what is inside. The double signature is what replaces the
 * server-attested account label the forward envelope enjoys. */

if (step === 'reverse') {
  const watcher = await SH.generateIdentity();
  const acct = await SH.generateSigningKey();
  const claim = JSON.stringify({ game: 1, slot: 3, account: 42 });
  const payload = {
    game: 1, slot: 3, account: 42,
    slot_sig: await SH.signAnon(st.a.sigkPriv, claim),   // holder of slot 3
    acct_sig: await SH.signAnon(acct.priv, claim),        // holder of account 42
  };
  const innerKey = SH.randomAesKey();
  const inner = await SH.innerSeal(innerKey, payload);
  const ct = await SH.eciesSeal(watcher.idkPub, { inner: inner }, 'scumhouse/reverse/v1');

  // The watcher, holding both locks, resolves slot 3 to account 42.
  const outer = await SH.eciesOpen(ct, watcher.idkPriv, 'scumhouse/reverse/v1');
  const got = await SH.innerOpen(innerKey, outer.inner);
  if (got.account !== 42 || got.slot !== 3) throw new Error('reverse payload wrong');
  if (!await SH.verifyAnon(st.a.sigkPub, claim, got.slot_sig)) throw new Error('slot signature failed');
  if (!await SH.verifyAnon(acct.pub, claim, got.acct_sig)) throw new Error('account signature failed');

  // A lone forger fails: claiming someone else's account needs THEIR key.
  const victim = await SH.generateSigningKey();
  const lie = JSON.stringify({ game: 1, slot: 3, account: 99 });
  const forged = { slot_sig: await SH.signAnon(st.a.sigkPriv, lie), acct_sig: await SH.signAnon(acct.priv, lie) };
  if (await SH.verifyAnon(victim.pub, lie, forged.acct_sig)) {
    throw new Error('a forged account claim verified against the victim key');
  }
  if (await SH.verifyAnon(st.b.sigkPub, claim, got.slot_sig)) {
    throw new Error('the slot signature verified under the wrong slot');
  }

  // Neither lock alone opens it, same as the forward direction.
  let leaked = false;
  try { await SH.eciesOpen(ct, st.a.idkPriv, 'scumhouse/reverse/v1'); leaked = true; } catch (e) {}
  if (leaked) throw new Error('a non-watcher opened the reverse envelope');
  try { await SH.innerOpen(SH.randomAesKey(), outer.inner); leaked = true; } catch (e) {}
  if (leaked) throw new Error('the inner lock opened with the wrong key');

  console.log('reverse: ok (both signatures required, both locks required)');
}
