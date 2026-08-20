/*
 * Scumhouse client crypto. Implements the client half of PROTOCOL.md.
 *
 * Everything here runs in the player's browser and the private key material it
 * produces must never leave it. If you are adding a fetch() to this file, stop
 * and check what you are sending -- this is the one file in the game where a
 * careless change is a privacy break rather than a bug.
 *
 * WebCrypto only, no dependencies. BigInt is used for exactly one thing: the RSA
 * blinding in blindCredential()/unblindCredential(), which crypto.subtle cannot
 * express because it refuses to do raw modular exponentiation.
 */
(function (global) {
  'use strict';

  const subtle = global.crypto.subtle;
  const enc = new TextEncoder();
  const dec = new TextDecoder();

  // Fixed plaintext size for every anon-channel blob, so blob length never
  // varies with message length. 480 leaves room for the 16-byte chunk header.
  const BLOB_PLAINTEXT = 480;
  const CHUNK_BODY = BLOB_PLAINTEXT - 16;

  /* ---------- encoding helpers ---------- */

  function b64u(bytes) {
    let s = '';
    const a = new Uint8Array(bytes);
    for (let i = 0; i < a.length; i++) s += String.fromCharCode(a[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function unb64u(str) {
    const s = str.replace(/-/g, '+').replace(/_/g, '/');
    const bin = atob(s + '='.repeat((4 - (s.length % 4)) % 4));
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  function concat(...arrays) {
    const total = arrays.reduce((n, a) => n + a.length, 0);
    const out = new Uint8Array(total);
    let o = 0;
    for (const a of arrays) { out.set(a, o); o += a.length; }
    return out;
  }

  function randomBytes(n) {
    return global.crypto.getRandomValues(new Uint8Array(n));
  }

  async function sha256(bytes) {
    return new Uint8Array(await subtle.digest('SHA-256', bytes));
  }

  function bytesToBigInt(bytes) {
    let hex = '0x';
    for (const b of bytes) hex += b.toString(16).padStart(2, '0');
    return BigInt(hex === '0x' ? '0x0' : hex);
  }

  function bigIntToBytes(v, len) {
    let hex = v.toString(16);
    if (hex.length % 2) hex = '0' + hex;
    const bytes = new Uint8Array(hex.length / 2);
    for (let i = 0; i < bytes.length; i++) bytes[i] = parseInt(hex.substr(i * 2, 2), 16);
    if (bytes.length === len) return bytes;
    if (bytes.length > len) throw new Error('integer wider than modulus');
    return concat(new Uint8Array(len - bytes.length), bytes);
  }

  /* ---------- identity ---------- */

  // Two keypairs, not one: WebCrypto will not let a single P-256 key both agree
  // (ECDH) and sign (ECDSA), and conflating them across algorithms is a bad idea
  // even where an implementation permits it.
  async function generateIdentity() {
    const idk = await subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
    const sigk = await subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, true, ['sign', 'verify']);
    return {
      idkPriv: await subtle.exportKey('jwk', idk.privateKey),
      idkPub: b64u(await subtle.exportKey('raw', idk.publicKey)),
      sigkPriv: await subtle.exportKey('jwk', sigk.privateKey),
      sigkPub: b64u(await subtle.exportKey('raw', sigk.publicKey)),
    };
  }

  // A standalone signing keypair. Used for the ACCOUNT key, which is published
  // while logged in and must therefore stay out of anonPubJson() below -- putting
  // it in the blind-signed value would link the account to the anon identity and
  // undo the whole registration dance.
  async function generateSigningKey() {
    const kp = await subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, true, ['sign', 'verify']);
    return {
      priv: await subtle.exportKey('jwk', kp.privateKey),
      pub: b64u(await subtle.exportKey('raw', kp.publicKey)),
    };
  }

  // Canonical, byte-stable serialisation -- the blind signature is over exactly
  // these bytes, so any reordering here invalidates every credential ever issued.
  function anonPubJson(identity) {
    return JSON.stringify({ idk: identity.idkPub, sigk: identity.sigkPub });
  }

  async function importIdkPriv(jwk) {
    return subtle.importKey('jwk', jwk, { name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits']);
  }
  async function importIdkPub(raw) {
    return subtle.importKey('raw', unb64u(raw), { name: 'ECDH', namedCurve: 'P-256' }, false, []);
  }
  async function importSigkPriv(jwk) {
    return subtle.importKey('jwk', jwk, { name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign']);
  }
  async function importSigkPub(raw) {
    return subtle.importKey('raw', unb64u(raw), { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify']);
  }

  /* ---------- RSA blind signatures (PROTOCOL.md sec 4) ---------- */

  async function mgf1(seed, length) {
    const out = new Uint8Array(length);
    let off = 0;
    for (let counter = 0; off < length; counter++) {
      const c = new Uint8Array([counter >>> 24, (counter >>> 16) & 255, (counter >>> 8) & 255, counter & 255]);
      const block = await sha256(concat(seed, c));
      const take = Math.min(block.length, length - off);
      out.set(block.subarray(0, take), off);
      off += take;
    }
    return out;
  }

  // Full-domain hash into [0, 2^(8*modBytes-1)). Clearing the top bit is what
  // keeps the result below n without a modular reduction -- which matters because
  // the SERVER has to compute this same value (inc/anon.php sh_fdh) and PHP would
  // otherwise need gmp/bcmath just to verify a credential.
  async function fdh(messageBytes, modBytes) {
    const out = await mgf1(messageBytes, modBytes);
    out[0] &= 0x7f;
    return bytesToBigInt(out);
  }

  function modPow(base, exp, mod) {
    let result = 1n;
    base %= mod;
    while (exp > 0n) {
      if (exp & 1n) result = (result * base) % mod;
      base = (base * base) % mod;
      exp >>= 1n;
    }
    return result;
  }

  function egcd(a, b) {
    if (b === 0n) return [a, 1n, 0n];
    const [g, x, y] = egcd(b, a % b);
    return [g, y, x - (a / b) * y];
  }

  function modInv(a, m) {
    const [g, x] = egcd(((a % m) + m) % m, m);
    if (g !== 1n) throw new Error('not invertible');
    return ((x % m) + m) % m;
  }

  // Returns the blinded value to send to the server plus the blinding factor to
  // keep. The server sees h*r^e mod n, which is uniform in Z_n* and carries no
  // information about h -- that is the entire point of this step.
  async function blindCredential(anonPubString, credN, credE) {
    const n = bytesToBigInt(unb64u(credN));
    const e = bytesToBigInt(unb64u(credE));
    const modBytes = unb64u(credN).length;
    const h = await fdh(enc.encode(anonPubString), modBytes);

    let r;
    for (;;) {
      r = bytesToBigInt(randomBytes(modBytes)) % n;
      if (r > 1n && egcd(r, n)[0] === 1n) break;
    }
    const blinded = (h * modPow(r, e, n)) % n;
    return { blinded: b64u(bigIntToBytes(blinded, modBytes)), r: r.toString(16), modBytes: modBytes };
  }

  function unblindCredential(blindSigB64, rHex, credN) {
    const n = bytesToBigInt(unb64u(credN));
    const modBytes = unb64u(credN).length;
    const sPrime = bytesToBigInt(unb64u(blindSigB64));
    const s = (sPrime * modInv(BigInt('0x' + rHex), n)) % n;
    return b64u(bigIntToBytes(s, modBytes));
  }

  /* ---------- ECIES seal / open (PROTOCOL.md sec 5) ---------- */

  async function deriveAes(privKey, pubKey, info) {
    const bits = await subtle.deriveBits({ name: 'ECDH', public: pubKey }, privKey, 256);
    const base = await subtle.importKey('raw', bits, 'HKDF', false, ['deriveKey']);
    return subtle.deriveKey(
      { name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(0), info: enc.encode(info) },
      base,
      { name: 'AES-GCM', length: 256 },
      false,
      ['encrypt', 'decrypt']
    );
  }

  // Opens a card (or any ECIES blob) addressed to our idk. Blob layout is
  // ephemeral_pub(65) || iv(12) || ciphertext.
  async function eciesOpen(blobB64, idkPrivJwk, info) {
    const blob = unb64u(blobB64);
    const ephPub = await importIdkPub(b64u(blob.subarray(0, 65)));
    const iv = blob.subarray(65, 77);
    const ct = blob.subarray(77);
    const key = await deriveAes(await importIdkPriv(idkPrivJwk), ephPub, info);
    const pt = await subtle.decrypt({ name: 'AES-GCM', iv: iv }, key, ct);
    return JSON.parse(dec.decode(pt));
  }

  /* ---------- the team key (PROTOCOL.md sec 5.1) ---------- */

  // Never generated by, sent to, or derivable by the server: it is the ECDH
  // secret between two private keys that were made in browsers and stayed there.
  async function pairKey(myIdkPrivJwk, theirIdkPub) {
    return deriveAes(await importIdkPriv(myIdkPrivJwk), await importIdkPub(theirIdkPub), 'scumhouse/team/v1');
  }

  /* ---------- fixed-size channel blobs (PROTOCOL.md sec 6) ---------- */

  // Chunk header: msgId(8) || total(2) || index(2) || bodyLen(2) || reserved(2).
  // A message longer than one chunk is split across several blobs sharing a msgId;
  // readers reassemble by msgId, and a chunk that never arrives just never renders.
  async function sealBlobs(key, text) {
    const body = enc.encode(text);
    const total = Math.max(1, Math.ceil(body.length / CHUNK_BODY));
    if (total > 255) throw new Error('message too long');
    const msgId = randomBytes(8);
    const blobs = [];
    for (let i = 0; i < total; i++) {
      const slice = body.subarray(i * CHUNK_BODY, (i + 1) * CHUNK_BODY);
      const header = new Uint8Array(16);
      header.set(msgId, 0);
      header[8] = total >> 8; header[9] = total & 255;
      header[10] = i >> 8; header[11] = i & 255;
      header[12] = slice.length >> 8; header[13] = slice.length & 255;
      // Random tail, not zeroes: a zero-padded plaintext leaks its true length
      // to anyone who ever recovers the key, and costs nothing to avoid.
      const pad = randomBytes(CHUNK_BODY - slice.length);
      const pt = concat(header, slice, pad);
      const iv = randomBytes(12);
      const ct = new Uint8Array(await subtle.encrypt({ name: 'AES-GCM', iv: iv }, key, pt));
      blobs.push(b64u(concat(iv, ct)));
    }
    return blobs;
  }

  // Trial-decrypts one blob. A failure is the normal case, not an error: most
  // blobs on the channel are other people's cover traffic or another team's.
  async function openBlob(key, blobB64) {
    try {
      const raw = unb64u(blobB64);
      const pt = new Uint8Array(await subtle.decrypt(
        { name: 'AES-GCM', iv: raw.subarray(0, 12) }, key, raw.subarray(12)
      ));
      const bodyLen = (pt[12] << 8) | pt[13];
      if (bodyLen > CHUNK_BODY) return null;
      return {
        msgId: b64u(pt.subarray(0, 8)),
        total: (pt[8] << 8) | pt[9],
        index: (pt[10] << 8) | pt[11],
        body: dec.decode(pt.subarray(16, 16 + bodyLen)),
      };
    } catch (e) {
      return null;
    }
  }

  // Indistinguishable from a real blob without the key: same length, uniform bytes.
  function coverBlob() {
    return b64u(randomBytes(12 + BLOB_PLAINTEXT + 16));
  }


  /* ---------- ECIES seal + the two-lock envelope (PROTOCOL.md sec 5.2) ---------- */

  // The client seals as well as opens now: role envelopes are sealed to an
  // investigator slot's key, and only that slot can ever strip this layer.
  async function eciesSeal(recipientPubB64, payload, info) {
    const eph = await subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
    const key = await deriveAes(eph.privateKey, await importIdkPub(recipientPubB64), info);
    const iv = randomBytes(12);
    const ct = new Uint8Array(await subtle.encrypt(
      { name: 'AES-GCM', iv: iv }, key, enc.encode(JSON.stringify(payload))
    ));
    const ephPub = new Uint8Array(await subtle.exportKey('raw', eph.publicKey));
    return b64u(concat(ephPub, iv, ct));
  }

  // A throwaway keypair used once, to receive a single sealed answer. Fresh every
  // redemption so that two answers to the same investigator cannot be linked to
  // each other by the key they were sealed to.
  async function ephemeralKeyPair() {
    const kp = await subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
    return {
      priv: await subtle.exportKey('jwk', kp.privateKey),
      pub: b64u(await subtle.exportKey('raw', kp.publicKey)),
    };
  }

  function randomAesKey() {
    return b64u(randomBytes(32));
  }

  async function importAesKey(rawB64) {
    return subtle.importKey('raw', unb64u(rawB64), { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
  }

  // The INNER lock of an envelope. Fixed 256-byte plaintext: the payload is a
  // short record and its true length must not vary, or the ciphertext size would
  // leak something about the slot index or signature encoding to anyone holding
  // the outer key but not the inner one.
  const INNER_PLAINTEXT = 256;

  async function innerSeal(rawKeyB64, payload) {
    const body = enc.encode(JSON.stringify(payload));
    if (body.length > INNER_PLAINTEXT - 2) throw new Error('envelope payload too long');
    const pt = new Uint8Array(INNER_PLAINTEXT);
    pt[0] = body.length >> 8;
    pt[1] = body.length & 255;
    pt.set(body, 2);
    pt.set(randomBytes(INNER_PLAINTEXT - 2 - body.length), 2 + body.length);
    const iv = randomBytes(12);
    const key = await importAesKey(rawKeyB64);
    const ct = new Uint8Array(await subtle.encrypt({ name: 'AES-GCM', iv: iv }, key, pt));
    return b64u(concat(iv, ct));
  }

  async function innerOpen(rawKeyB64, blobB64) {
    const raw = unb64u(blobB64);
    const key = await importAesKey(rawKeyB64);
    const pt = new Uint8Array(await subtle.decrypt(
      { name: 'AES-GCM', iv: raw.subarray(0, 12) }, key, raw.subarray(12)
    ));
    const len = (pt[0] << 8) | pt[1];
    if (len > INNER_PLAINTEXT - 2) throw new Error('corrupt envelope');
    return JSON.parse(dec.decode(pt.subarray(2, 2 + len)));
  }

  // Must match inc/game_state.php sh_token_message() byte-for-byte -- it is what
  // the blind signature covers. Domain-separated from the registration credential
  // so neither can be redeemed as the other.
  function tokenMessage(gameId, nightNo, nonce) {
    return JSON.stringify({ t: 'scumhouse/retrieval/v1', game: gameId, night: nightNo, nonce: nonce });
  }


  /* ---------- Shamir secret sharing over GF(256) (PROTOCOL.md sec 9) ---------- */

  // Used to force open the card of a dead player who will not open it themselves.
  // Byte-wise: a 32-byte secret is 32 independent sharings sharing one x-coordinate
  // per holder, which is the standard construction and keeps every share the same
  // length as the secret.
  //
  // GF(256) with the AES polynomial 0x11b. Tables are built once; the field is far
  // too small for timing to matter here and the values are not secret anyway --
  // what is secret is the polynomial's constant term.
  const GF_EXP = new Uint8Array(512);
  const GF_LOG = new Uint8Array(256);
  (function buildTables() {
    let x = 1;
    for (let i = 0; i < 255; i++) {
      GF_EXP[i] = x;
      GF_LOG[x] = i;
      x ^= (x << 1) ^ ((x & 0x80) ? 0x11b : 0);
      x &= 0xff;
    }
    for (let i = 255; i < 512; i++) GF_EXP[i] = GF_EXP[i - 255];
  })();

  function gfMul(a, b) {
    if (a === 0 || b === 0) return 0;
    return GF_EXP[GF_LOG[a] + GF_LOG[b]];
  }

  function gfDiv(a, b) {
    if (b === 0) throw new Error('division by zero in GF(256)');
    if (a === 0) return 0;
    return GF_EXP[GF_LOG[a] + 255 - GF_LOG[b]];
  }

  /**
   * Splits `secret` (Uint8Array) into `n` shares, `threshold` of which reconstruct
   * it. Share x-coordinates are 1..n; 0 is reserved for the secret itself.
   *
   * Returns an array of {x, y} where y is a Uint8Array the same length as secret.
   */
  function shamirSplit(secret, n, threshold) {
    if (threshold < 2 || threshold > n || n > 255) {
      throw new Error('bad shamir parameters');
    }
    const shares = [];
    for (let x = 1; x <= n; x++) shares.push({ x: x, y: new Uint8Array(secret.length) });

    for (let byte = 0; byte < secret.length; byte++) {
      // Random coefficients for terms 1..threshold-1; constant term is the secret.
      const coeffs = randomBytes(threshold - 1);
      for (const share of shares) {
        let acc = 0;
        // Horner from the highest coefficient down to the secret byte.
        for (let k = threshold - 2; k >= 0; k--) acc = gfMul(acc, share.x) ^ coeffs[k];
        acc = gfMul(acc, share.x) ^ secret[byte];
        share.y[byte] = acc;
      }
    }
    return shares;
  }

  /** Lagrange interpolation at x=0. Needs at least `threshold` distinct shares;
   * fewer yields a wrong value rather than an error, which is why the recovered
   * secret is always checked by using it (AES-GCM either authenticates or does not). */
  function shamirCombine(shares) {
    if (!shares.length) throw new Error('no shares');
    const len = shares[0].y.length;
    const out = new Uint8Array(len);
    const xs = shares.map((s) => s.x);
    if (new Set(xs).size !== xs.length) throw new Error('duplicate share x-coordinates');

    for (let byte = 0; byte < len; byte++) {
      let acc = 0;
      for (let i = 0; i < shares.length; i++) {
        let num = 1, den = 1;
        for (let j = 0; j < shares.length; j++) {
          if (i === j) continue;
          num = gfMul(num, xs[j]);
          den = gfMul(den, xs[i] ^ xs[j]);
        }
        acc ^= gfMul(shares[i].y[byte], gfDiv(num, den));
      }
      out[byte] = acc;
    }
    return out;
  }

  function shareToB64(share) {
    return share.x + ':' + b64u(share.y);
  }

  function shareFromB64(str) {
    const i = str.indexOf(':');
    if (i < 1) throw new Error('malformed share');
    return { x: parseInt(str.slice(0, i), 10), y: unb64u(str.slice(i + 1)) };
  }

  /* ---------- signing ---------- */

  async function signAnon(sigkPrivJwk, payloadString) {
    const key = await importSigkPriv(sigkPrivJwk);
    const sig = await subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, key, enc.encode(payloadString));
    return b64u(sig);
  }

  async function verifyAnon(sigkPub, payloadString, sigB64) {
    const key = await importSigkPub(sigkPub);
    return subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, key, unb64u(sigB64), enc.encode(payloadString));
  }

  /* ---------- local storage + recovery (PROTOCOL.md sec 10) ---------- */

  function storeKey(gameId) { return 'scumhouse/identity/' + gameId; }

  function saveIdentity(gameId, identity) {
    localStorage.setItem(storeKey(gameId), JSON.stringify(identity));
  }

  function loadIdentity(gameId) {
    const raw = localStorage.getItem(storeKey(gameId));
    return raw ? JSON.parse(raw) : null;
  }

  function recoveryCode(identity) {
    return b64u(enc.encode(JSON.stringify(identity)));
  }

  function fromRecoveryCode(code) {
    return JSON.parse(dec.decode(unb64u(code)));
  }

  async function passphraseKey(passphrase, salt) {
    const base = await subtle.importKey('raw', enc.encode(passphrase), 'PBKDF2', false, ['deriveKey']);
    return subtle.deriveKey(
      { name: 'PBKDF2', hash: 'SHA-256', salt: salt, iterations: 600000 },
      base,
      { name: 'AES-GCM', length: 256 },
      false,
      ['encrypt', 'decrypt']
    );
  }

  async function wrapIdentity(identity, passphrase) {
    const salt = randomBytes(16);
    const iv = randomBytes(12);
    const key = await passphraseKey(passphrase, salt);
    const ct = new Uint8Array(await subtle.encrypt(
      { name: 'AES-GCM', iv: iv }, key, enc.encode(JSON.stringify(identity))
    ));
    return b64u(concat(salt, iv, ct));
  }

  async function unwrapIdentity(blobB64, passphrase) {
    const blob = unb64u(blobB64);
    const key = await passphraseKey(passphrase, blob.subarray(0, 16));
    const pt = await subtle.decrypt({ name: 'AES-GCM', iv: blob.subarray(16, 28) }, key, blob.subarray(28));
    return JSON.parse(dec.decode(pt));
  }

  /* ---------- anonymous transport ---------- */

  // The ONLY way this file talks to /anon/*. credentials:'omit' is what keeps the
  // session cookie off the request; without it every anonymous post would carry
  // the player's identity in a header and the whole protocol would be theatre.
  async function anonPost(path, body) {
    const res = await fetch(path, {
      method: 'POST',
      credentials: 'omit',
      referrerPolicy: 'no-referrer',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error('anon request failed: ' + res.status);
    return res.json();
  }

  global.SH = {
    b64u, unb64u, randomBytes, sha256,
    generateIdentity, generateSigningKey, anonPubJson,
    blindCredential, unblindCredential,
    eciesOpen, eciesSeal, pairKey,
    ephemeralKeyPair, randomAesKey, innerSeal, innerOpen, tokenMessage,
    shamirSplit, shamirCombine, shareToB64, shareFromB64,
    sealBlobs, openBlob, coverBlob,
    signAnon, verifyAnon,
    saveIdentity, loadIdentity, recoveryCode, fromRecoveryCode,
    wrapIdentity, unwrapIdentity,
    anonPost,
    BLOB_PLAINTEXT, CHUNK_BODY,
  };
})(window);
