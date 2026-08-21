/*
 * Headless Scumhouse client: everything a player's browser does, with no browser.
 *
 * WHY THIS SHAPE. A bot needs a dealt card, which is an anonymous identity, which
 * is a private key -- so whoever runs the bot holds it. Running one on the game
 * server would hand the operator a card, and if it were dealt mafia they would
 * read that team's channel, where the humans introduce themselves by name. So a
 * bot is not a server feature. It is a client, run by a player, exactly like the
 * one in client/.
 *
 * It loads public/js/crypto.js UNMODIFIED. Reimplementing ECDH, blind signatures
 * or Shamir here would be wasted work and a second place for the two to disagree
 * about a wire format. Everything security-critical is the same code a human runs;
 * only the decision-making differs, and that lives behind strategy.mjs.
 *
 * The bot cannot cheat. It talks to the same endpoints under the same rules, and
 * the protocol simply never hands it anything a human would not get -- fairness
 * here is enforced by the cryptography rather than by trusting the bot.
 */
import fs from 'fs';
import vm from 'vm';
import path from 'path';
import { webcrypto } from 'node:crypto';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));

export const CARD_INFO = 'scumhouse/card/v1';
export const ENVELOPE_INFO = 'scumhouse/envelope/v1';
export const TABLE_INFO = 'scumhouse/roletable/v1';
export const KEY_INFO = 'scumhouse/innerkey/v1';
export const TRACK_INFO = 'scumhouse/trackreport/v1';
export const WATCH_INFO = 'scumhouse/watchreport/v1';
export const REVERSE_INFO = 'scumhouse/reverse/v1';
export const FLIP_INFO = 'scumhouse/flipshare/v1';

/** Loads the real client crypto into this process. The shims are only the
 * browser globals it expects to exist; nothing in crypto.js is patched. */
export function loadCrypto(root = path.join(HERE, '..')) {
  const src = fs.readFileSync(path.join(root, 'public/js/crypto.js'), 'utf8');
  const store = {};
  const sandbox = {
    crypto: globalThis.crypto ?? webcrypto,
    btoa, atob, TextEncoder, TextDecoder, console, fetch,
    setTimeout, clearTimeout,
    localStorage: {
      getItem: (k) => (k in store ? store[k] : null),
      setItem: (k, v) => { store[k] = String(v); },
      removeItem: (k) => { delete store[k]; },
    },
  };
  sandbox.window = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(src, sandbox);
  return sandbox.SH;
}

/**
 * One seat at one table.
 *
 * State (keys, card, what has already been done this phase) persists to a JSON
 * file, because a forum game runs for days and the process will be restarted.
 * Losing that file loses the card, exactly as clearing browser storage would.
 */
export class Seat {
  constructor({ base, token, gameId, statePath, strategy, log = () => {} }) {
    this.SH = loadCrypto();
    this.base = base.replace(/\/+$/, '');
    this.token = token;
    this.gameId = gameId;
    this.statePath = statePath;
    this.strategy = strategy;
    this.log = log;
    this.state = fs.existsSync(statePath) ? JSON.parse(fs.readFileSync(statePath, 'utf8')) : {};
    this.feed = null;
  }

  save() {
    fs.mkdirSync(path.dirname(this.statePath), { recursive: true });
    fs.writeFileSync(this.statePath, JSON.stringify(this.state, null, 1));
  }

  /* ---------- transport ---------- */

  async api(pathname, body) {
    const headers = { Authorization: 'Bearer ' + this.token };
    if (body) headers['Content-Type'] = 'application/json';
    const res = await fetch(this.base + '/api/' + pathname, {
      method: body ? 'POST' : 'GET',
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'bad response' }));
    if (!data.ok) throw new Error(pathname + ': ' + (data.error || res.status));
    return data;
  }

  // Anonymous endpoints take no credential and must not be sent one -- the same
  // rule the browser client follows, for the same reason.
  async anon(endpoint, body) {
    return this.SH.anonPost(this.base + '/anon/' + endpoint, body);
  }

  async refresh() {
    this.feed = await this.api('feed.php?game=' + this.gameId);
    return this.feed;
  }

  get card() { return this.state.card || null; }
  get me() { return this.feed.me; }

  nameOf(userId) {
    const p = this.feed.players.find((x) => x.user_id === Number(userId));
    return p ? p.name : 'someone';
  }

  isAlive(userId) {
    const p = this.feed.players.find((x) => x.user_id === Number(userId));
    return p ? p.alive : false;
  }

  /* ---------- registration and dealing ---------- */

  async ensureIdentity() {
    if (this.state.identity) return;
    const g = this.feed.game;
    if (g.status !== 'registration') return;

    this.log('generating an anonymous identity');
    const identity = await this.SH.generateIdentity();
    const anonPub = this.SH.anonPubJson(identity);
    const { blinded, r } = await this.SH.blindCredential(anonPub, g.cred_n, g.cred_e);
    const signed = await this.api('blind-sign.php', { game: this.gameId, blinded });
    const credential = this.SH.unblindCredential(signed.blind_sig, r, g.cred_n);

    // Stored BEFORE redeeming: the credential is one-per-player and is spent by
    // the call below, so losing the keys after it succeeds strands the seat.
    this.state.identity = identity;
    this.save();

    await this.anon('register.php', {
      game: this.gameId, idk: identity.idkPub, sigk: identity.sigkPub, credential,
    });
    this.log('registered');
  }

  async ensureCard() {
    if (this.state.card || !this.state.identity) return;
    for (const c of this.feed.cards || []) {
      try {
        const card = await this.SH.eciesOpen(c.ciphertext, this.state.identity.idkPriv, CARD_INFO);
        this.state.card = card;
        this.save();
        this.log('dealt ' + card.role + ' in slot ' + card.slot);
        return;
      } catch (e) { /* not ours; the GCM tag failing IS the answer */ }
    }
  }

  async teamKeys() {
    if (!this.card || this.card.role !== 'MAFIA' || !this.card.team) return [];
    const out = [];
    for (const mate of this.card.team) {
      const slot = this.feed.slots.find((s) => Number(s.slot_index) === Number(mate));
      if (slot) out.push({ slot: Number(mate), key: await this.SH.pairKey(this.state.identity.idkPriv, slot.idk_pub) });
    }
    return out;
  }

  /* ---------- the once-per-game publications ---------- */

  async ensureAccountKey() {
    if (this.feed.my_account_key_published) return;
    if (!this.state.acct) {
      this.state.acct = await this.SH.generateSigningKey();
      this.save();
    }
    await this.api('account-key.php', { game: this.gameId, sigk_pub: this.state.acct.pub });
  }

  async ensureEnvelopes() {
    if (!this.card || this.feed.my_envelope_published) return;
    const investigators = this.feed.investigator_slots || [];
    if (!investigators.length) return;

    const claim = JSON.stringify({ game: this.gameId, account: this.me, slot: this.card.slot });
    const payload = {
      game: this.gameId, account: this.me, slot: this.card.slot,
      sig: await this.SH.signAnon(this.state.identity.sigkPriv, claim),
    };
    const innerKey = this.SH.randomAesKey();
    const inner = await this.SH.innerSeal(innerKey, payload);

    const envelopes = {};
    for (const v of investigators) {
      const slot = this.feed.slots.find((x) => Number(x.slot_index) === Number(v));
      if (!slot) return;
      envelopes[v] = await this.SH.eciesSeal(slot.idk_pub, { inner }, ENVELOPE_INFO);
    }
    await this.api('envelope.php', { game: this.gameId, inner_key: innerKey, envelopes });
  }

  async ensureReverseEnvelope() {
    if (!this.card || !this.state.acct) return;
    const watchers = this.feed.reverse_slots || [];
    if (!watchers.length) return;
    if ((this.feed.reverse_envelopes || []).some((r) => Number(r.slot_index) === this.card.slot)) return;

    const claim = JSON.stringify({ game: this.gameId, slot: this.card.slot, account: this.me });
    const payload = {
      game: this.gameId, slot: this.card.slot, account: this.me,
      slot_sig: await this.SH.signAnon(this.state.identity.sigkPriv, claim),
      acct_sig: await this.SH.signAnon(this.state.acct.priv, claim),
    };
    const innerKey = this.SH.randomAesKey();
    const inner = await this.SH.innerSeal(innerKey, payload);
    const slot = this.feed.slots.find((x) => Number(x.slot_index) === Number(watchers[0]));
    if (!slot) return;
    const ct = await this.SH.eciesSeal(slot.idk_pub, { inner }, REVERSE_INFO);

    const sigPayload = JSON.stringify({ game: this.gameId, slot: this.card.slot, ct, inner_key: innerKey });
    await this.anon('reverse.php', {
      game: this.gameId, slot: this.card.slot, ct, inner_key: innerKey,
      sig: await this.SH.signAnon(this.state.identity.sigkPriv, sigPayload),
    });
  }

  async ensureFlipEscrow() {
    if (!this.card || this.feed.my_flip_escrowed) return;
    if (!this.feed.slots || this.feed.slots.length !== this.feed.game.num_seats) return;

    // Byte-identical to what a voluntary flip signs, so the escrowed copy can be
    // relayed by anyone and still verify.
    const claim = JSON.stringify({ game: this.gameId, slot: this.card.slot, user: this.me, role: this.card.role });
    const payload = {
      game: this.gameId, slot: this.card.slot, user: this.me, role: this.card.role,
      sig: await this.SH.signAnon(this.state.identity.sigkPriv, claim),
    };
    const key = this.SH.randomAesKey();
    const blob = await this.SH.innerSeal(key, payload);
    const threshold = this.feed.game.num_seats - 1;
    const parts = this.SH.shamirSplit(this.SH.unb64u(key), this.feed.game.num_seats, threshold);

    const shares = {};
    for (let i = 0; i < this.feed.slots.length; i++) {
      const slot = this.feed.slots[i];
      shares[Number(slot.slot_index)] = await this.SH.eciesSeal(
        slot.idk_pub, { share: this.SH.shareToB64(parts[i]) }, FLIP_INFO
      );
    }
    await this.api('flip-escrow.php', { game: this.gameId, blob, shares });
  }

  /* ---------- the channel ---------- */

  async postBlob(ct) {
    const payload = JSON.stringify({
      game: this.gameId, slot: this.card.slot,
      phase_no: this.feed.game.phase_no, phase: this.feed.game.phase, ct,
    });
    await this.anon('post.php', {
      game: this.gameId, slot: this.card.slot, ct,
      sig: await this.SH.signAnon(this.state.identity.sigkPriv, payload),
    });
  }

  async postCover(count = 4) {
    const tag = this.feed.game.phase + this.feed.game.phase_no;
    this.state.covered = this.state.covered || {};
    if (this.state.covered[tag] || !this.card || !this.isAlive(this.me)) return;
    this.state.covered[tag] = true;
    this.save();
    for (let i = 0; i < count; i++) await this.postBlob(this.SH.coverBlob());
  }

  async readTeam() {
    const keys = await this.teamKeys();
    if (!keys.length) return [];
    const chunks = new Map();
    for (const entry of this.feed.channel || []) {
      for (const mate of keys) {
        const c = await this.SH.openBlob(mate.key, entry.ciphertext);
        if (!c) continue;
        if (!chunks.has(c.msgId)) chunks.set(c.msgId, { slot: Number(entry.slot_index), total: c.total, parts: new Map() });
        chunks.get(c.msgId).parts.set(c.index, c.body);
        break;
      }
    }
    const out = [];
    for (const [, m] of chunks) {
      if (m.parts.size !== m.total) continue;
      let body = '';
      for (let i = 0; i < m.total; i++) body += m.parts.get(i);
      out.push({ slot: m.slot, body, mine: m.slot === this.card.slot });
    }
    return out;
  }

  async sayToTeam(text) {
    for (const mate of await this.teamKeys()) {
      for (const b of await this.SH.sealBlobs(mate.key, text)) await this.postBlob(b);
    }
  }

  /* ---------- public play ---------- */

  async say(body) { await this.api('say.php', { game: this.gameId, body }); }
  async vote(target) { await this.api('vote.php', { game: this.gameId, target }); }

  async nightAction(action, target) {
    const payload = JSON.stringify({
      game: this.gameId, slot: this.card.slot, night: this.feed.game.phase_no,
      action, target, target_slot: null,
    });
    await this.anon('action.php', {
      game: this.gameId, slot: this.card.slot, night: this.feed.game.phase_no,
      action, target, sig: await this.SH.signAnon(this.state.identity.sigkPriv, payload),
    });
  }

  /* ---------- investigation (PROTOCOL.md sec 5.2-5.4) ---------- */

  amInvestigator() { return this.card && (this.feed.investigator_slots || []).includes(this.card.slot); }
  amWatcher() { return this.card && (this.feed.reverse_slots || []).includes(this.card.slot); }

  // Stage 1: draw a blind-signed token and queue a question. Nothing comes back
  // now -- answers open together at the night's fixed release point, so that
  // deliberating is not itself a signal.
  async queueQuestion(target) {
    const night = this.feed.game.phase_no;
    const nonce = this.SH.b64u(this.SH.randomBytes(16));
    const msg = this.SH.tokenMessage(this.gameId, night, nonce);
    const { blinded, r } = await this.SH.blindCredential(msg, this.feed.game.cred_n, this.feed.game.cred_e);

    const tokenPayload = JSON.stringify({ game: this.gameId, slot: this.card.slot, night, blinded });
    const issued = await this.anon('token.php', {
      game: this.gameId, slot: this.card.slot, night, blinded,
      sig: await this.SH.signAnon(this.state.identity.sigkPriv, tokenPayload),
    });
    const token = this.SH.unblindCredential(issued.blind_sig, r, this.feed.game.cred_n);

    const eph = await this.SH.ephemeralKeyPair();
    // Persisted before redeeming: the token is one per night and the answer is
    // sealed to this key, so losing it wastes the whole night's question.
    this.state.eph = this.state.eph || {};
    this.state.eph[night] = { priv: eph.priv, target };
    this.save();

    await this.anon('redeem.php', {
      game: this.gameId, night, nonce, token, target, ephemeral_pub: eph.pub,
    });
    this.log('queued a question about ' + this.nameOf(target));
  }

  // Stage 2: after the release point the whole night's batch is public; ours is
  // whichever entry our one-use key opens.
  async collectAnswer() {
    const night = this.feed.game.phase_no;
    if (!this.feed.game.keys_released) return null;
    const mine = (this.state.eph || {})[night];
    if (!mine || (this.state.answered || {})[night]) return null;

    const batch = await this.anon('answers.php', { game: this.gameId, night });
    if (!batch.released) return null;

    for (const sealed of batch.answers) {
      let answer;
      try { answer = await this.SH.eciesOpen(sealed, mine.priv, KEY_INFO); } catch (e) { continue; }
      const env = (this.feed.envelopes || []).find(
        (e) => Number(e.user_id) === Number(answer.target) && Number(e.investigator_slot) === this.card.slot
      );
      if (!env) return null;
      const outer = await this.SH.eciesOpen(env.ciphertext, this.state.identity.idkPriv, ENVELOPE_INFO);
      const payload = await this.SH.innerOpen(answer.inner_key, outer.inner);

      // The target cannot lie: the claim is signed by the slot it names.
      const claim = JSON.stringify({ game: payload.game, account: payload.account, slot: payload.slot });
      const slot = this.feed.slots.find((x) => Number(x.slot_index) === Number(payload.slot));
      if (!slot || !await this.SH.verifyAnon(slot.sigk_pub, claim, payload.sig)) {
        throw new Error('an envelope failed its signature check');
      }
      this.state.answered = this.state.answered || {};
      this.state.answered[night] = { account: Number(payload.account), slot: Number(payload.slot) };
      this.save();
      return this.state.answered[night];
    }
    return null;
  }

  /** Records what an investigation established, so a strategy can act on it
   * later. Kept here rather than in the strategy because it survives restarts
   * and because a strategy should not own persistence. */
  rememberRead(account, role) {
    this.state.reads = this.state.reads || {};
    this.state.reads[account] = role;
    this.save();
  }

  /** The cop's sealed slot->role table, opened once and kept. */
  async roleTable() {
    if (this.state.roleTable) return this.state.roleTable;
    if (!this.card || this.card.role !== 'COP') return null;
    const row = (this.feed.role_table || []).find((r) => Number(r.slot_index) === this.card.slot);
    if (!row) return null;
    const opened = await this.SH.eciesOpen(row.ciphertext, this.state.identity.idkPriv, TABLE_INFO);
    this.state.roleTable = opened.roles;
    this.save();
    return this.state.roleTable;
  }

  async submitBlock(targetSlot) {
    const payload = JSON.stringify({
      game: this.gameId, slot: this.card.slot, night: this.feed.game.phase_no,
      action: 'block', target: null, target_slot: targetSlot,
    });
    await this.anon('action.php', {
      game: this.gameId, slot: this.card.slot, night: this.feed.game.phase_no,
      action: 'block', target_slot: targetSlot,
      sig: await this.SH.signAnon(this.state.identity.sigkPriv, payload),
    });
  }

  async submitTrack(targetSlot, targetAccount = null) {
    const night = this.feed.game.phase_no;
    const eph = await this.SH.ephemeralKeyPair();
    this.state.trackEph = this.state.trackEph || {};
    // The key alone is not enough to read the answer later: the report says who
    // the SUBJECT visited, and it is only meaningful next to who was asked about.
    this.state.trackEph[night] = { priv: eph.priv, slot: targetSlot, account: targetAccount };
    this.save();
    const payload = JSON.stringify({
      game: this.gameId, slot: this.card.slot, night, target_slot: targetSlot, ephemeral_pub: eph.pub,
    });
    await this.anon('track.php', {
      game: this.gameId, slot: this.card.slot, night, target_slot: targetSlot,
      ephemeral_pub: eph.pub, sig: await this.SH.signAnon(this.state.identity.sigkPriv, payload),
    });
  }

  async submitWatch(target) {
    const night = this.feed.game.phase_no;
    const eph = await this.SH.ephemeralKeyPair();
    this.state.watchEph = this.state.watchEph || {};
    this.state.watchEph[night] = { priv: eph.priv, account: target };
    this.save();
    const payload = JSON.stringify({
      game: this.gameId, slot: this.card.slot, night, target, ephemeral_pub: eph.pub,
    });
    await this.anon('watch.php', {
      game: this.gameId, slot: this.card.slot, night, target,
      ephemeral_pub: eph.pub, sig: await this.SH.signAnon(this.state.identity.sigkPriv, payload),
    });
    this.log('watching ' + this.nameOf(target));
  }


  /* ---------- tracker and watcher answers ----------
   *
   * Both roles seal their answer at dawn, to an ephemeral key the client kept
   * from the moment it asked. Without these two methods a bot dealt TRACKER or
   * WATCHER queries every night and never opens the reply -- the same waste the
   * cop's reads would be if nothing consulted them. public/js/game.js has done
   * this from the start; the bot simply never did. */

  /** The private half of a stored ephemeral, tolerating the older bare-string shape. */
  _eph(bucket, night) {
    const v = (this.state[bucket] || {})[night];
    if (!v) return null;
    return typeof v === 'string' ? { priv: v } : v;
  }

  /** "The player you followed visited X" -- X is an account, or null for nobody.
   *
   * Scans every night this seat still holds a key for, not just the current one:
   * the report is sealed at DAWN, so the night it belongs to is already over by
   * the time it can be read, and a collector keyed on the current phase would
   * never once fire. */
  async collectTrackerReport() {
    if (!this.card || this.card.role !== 'TRACKER') return null;
    this.state.trackResults = this.state.trackResults || {};
    let latest = null;
    for (const night of Object.keys(this.state.trackEph || {})) {
      if (this.state.trackResults[night]) continue;
      const eph = this._eph('trackEph', night);
      if (!eph) continue;
      for (const r of this.feed.tracker_reports || []) {
        if (Number(r.slot_index) !== this.card.slot || Number(r.night_no) !== Number(night)) continue;
        try {
          const report = await this.SH.eciesOpen(r.ciphertext, eph.priv, TRACK_INFO);
          const out = { night: Number(night), subject: eph.account ?? null, visited: report.visited ?? null };
          this.state.trackResults[night] = out;
          this.save();
          this.log(out.visited === null
            ? `${this.nameOf(out.subject)} visited nobody on night ${night}`
            : `${this.nameOf(out.subject)} visited ${this.nameOf(out.visited)} on night ${night}`);
          latest = out;
        } catch (e) { /* not ours, or the key is gone */ }
      }
    }
    return latest;
  }

  /** Resolve one visitor slot to an account, or null if it cannot be verified.
   *
   * A visitor row is posted anonymously, so it carries TWO signatures -- the
   * slot's and the account's -- and both must check out against the same claim.
   * Accepting an unverified one would let a player name anybody as a visitor. */
  async _reverseAccount(slotIndex, innerKey) {
    if (!innerKey) return null;
    const row = (this.feed.reverse_envelopes || []).find((x) => Number(x.slot_index) === Number(slotIndex));
    if (!row) return null;
    try {
      const outer = await this.SH.eciesOpen(row.ciphertext, this.state.identity.idkPriv, REVERSE_INFO);
      const payload = await this.SH.innerOpen(innerKey, outer.inner);
      const claim = JSON.stringify({ game: payload.game, slot: payload.slot, account: payload.account });
      const slot = (this.feed.slots || []).find((x) => Number(x.slot_index) === Number(payload.slot));
      const acct = (this.feed.account_keys || []).find((k) => Number(k.user_id) === Number(payload.account));
      if (!slot || !acct) return null;
      const slotOk = await this.SH.verifyAnon(slot.sigk_pub, claim, payload.slot_sig);
      const acctOk = await this.SH.verifyAnon(acct.sigk_pub, claim, payload.acct_sig);
      if (!slotOk || !acctOk || Number(payload.slot) !== Number(slotIndex)) return null;
      return Number(payload.account);
    } catch (e) { return null; }
  }

  /** "These accounts visited the player you watched." Same dawn-timing rule as
   * the tracker, so this also sweeps every night it still holds a key for. */
  async collectWatcherReport() {
    if (!this.card || !this.amWatcher()) return null;
    this.state.watchResults = this.state.watchResults || {};
    let latest = null;
    for (const night of Object.keys(this.state.watchEph || {})) {
      if (this.state.watchResults[night]) continue;
      const eph = this._eph('watchEph', night);
      if (!eph) continue;
      for (const r of this.feed.watcher_reports || []) {
        if (Number(r.slot_index) !== this.card.slot || Number(r.night_no) !== Number(night)) continue;
        let report;
        try {
          report = await this.SH.eciesOpen(r.ciphertext, eph.priv, WATCH_INFO);
        } catch (e) { continue; }
        const visitors = [];
        for (const v of report.visitors || []) {
          const acct = await this._reverseAccount(v.slot, v.inner_key);
          if (acct !== null) visitors.push(acct);
        }
        const out = { night: Number(night), subject: eph.account ?? null, visitors };
        this.state.watchResults[night] = out;
        this.save();
        this.log(`night ${night}: ${visitors.length} identified visitor(s) to ${this.nameOf(out.subject)}`);
        latest = out;
      }
    }
    return latest;
  }

  /* ---------- flips ---------- */

  async autoFlip() {
    if (!this.card || this.isAlive(this.me)) return;
    if ((this.feed.flips || []).some((f) => Number(f.user_id) === this.me)) return;
    const payload = JSON.stringify({
      game: this.gameId, slot: this.card.slot, user: this.me, role: this.card.role,
    });
    await this.anon('flip.php', {
      game: this.gameId, slot: this.card.slot, user: this.me, role: this.card.role,
      sig: await this.SH.signAnon(this.state.identity.sigkPriv, payload),
    });
    this.log('flipped ' + this.card.role);
  }

  // Helping open a refuser's card is not optional politeness: withholding is
  // public, and a bot that never helps would look exactly like a stalling mafia.
  async revealShares() {
    if (!this.card || !(this.feed.pending_flips || []).length) return;
    const ends = this.feed.game.phase_ends_at;
    if (ends && new Date(ends.replace(' ', 'T') + 'Z') > Date.now()) return;

    for (const pending of this.feed.pending_flips) {
      const subject = Number(pending.user_id);
      if (subject === this.me) continue;
      if ((this.feed.flip_reveals || []).some((r) => Number(r.subject_user_id) === subject && Number(r.holder_slot) === this.card.slot)) continue;
      const mine = (this.feed.flip_shares || []).find(
        (x) => Number(x.subject_user_id) === subject && Number(x.holder_slot) === this.card.slot
      );
      if (!mine) continue;
      try {
        const opened = await this.SH.eciesOpen(mine.ciphertext, this.state.identity.idkPriv, FLIP_INFO);
        const sigPayload = JSON.stringify({ game: this.gameId, slot: this.card.slot, subject, share: opened.share });
        await this.anon('reveal-share.php', {
          game: this.gameId, slot: this.card.slot, subject, share: opened.share,
          sig: await this.SH.signAnon(this.state.identity.sigkPriv, sigPayload),
        });
        this.log('opened a share of ' + this.nameOf(subject) + "'s card");
      } catch (e) { /* not ours, or already open */ }
    }
  }
}
