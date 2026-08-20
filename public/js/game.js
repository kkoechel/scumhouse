/*
 * Scumhouse client. Drives PROTOCOL.md from the browser: registration, opening
 * the sealed card, the mafia channel, night actions, and the death flip.
 *
 * Rendering note, learned the hard way elsewhere in this repo: the 5s poll must
 * never rebuild a node the player might be typing into or selecting from. Every
 * render function below either skips live inputs or restores their state, and
 * the composer is built once and left alone.
 */
(function () {
  'use strict';

  const APP = window.SH_APP_PATH || '';
  const GAME_ID = window.SH_GAME_ID;
  const COVER_BLOBS = 4;
  const CARD_INFO = 'scumhouse/card/v1';
  const ENVELOPE_INFO = 'scumhouse/envelope/v1';
  const TABLE_INFO = 'scumhouse/roletable/v1';
  const KEY_INFO = 'scumhouse/innerkey/v1';
  const TRACK_INFO = 'scumhouse/trackreport/v1';
  const WATCH_INFO = 'scumhouse/watchreport/v1';
  const REVERSE_INFO = 'scumhouse/reverse/v1';
  const POLL_MS = 5000;

  const $ = (id) => document.getElementById(id);
  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  };

  let state = null;       // latest feed
  let identity = null;    // {idkPriv, idkPub, sigkPriv, sigkPub}
  let card = null;        // {role, slot, team?}
  let teamKeys = [];      // one AES key per mafia teammate
  let busy = false;
  let coveredPhase = null;
  let roleTable = null;    // slot -> role, cop only
  let spentNight = null;   // night whose retrieval token this client has spent

  /* ---------- plumbing ---------- */

  async function api(path, body) {
    const res = await fetch(APP + '/api/' + path, {
      method: body ? 'POST' : 'GET',
      credentials: 'include',
      headers: body ? { 'Content-Type': 'application/json' } : {},
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'bad response' }));
    if (!data.ok) throw new Error(data.error || 'request failed');
    return data;
  }

  function cardStoreKey() { return 'scumhouse/card/' + GAME_ID; }

  function note(msg, kind) {
    const box = $('sh-status');
    box.className = 'sh-status ' + (kind || '');
    box.textContent = msg;
  }

  /* ---------- registration (PROTOCOL.md sec 4) ---------- */

  async function ensureIdentity(game) {
    if (identity) return;
    identity = SH.loadIdentity(GAME_ID);
    if (identity) return;

    note('Generating your anonymous identity in this browser...');
    const fresh = await SH.generateIdentity();
    const anonPub = SH.anonPubJson(fresh);

    // The one authenticated call. The server signs a value it cannot read, so it
    // learns that we drew a credential and nothing about which one.
    const { blinded, r } = await SH.blindCredential(anonPub, game.cred_n, game.cred_e);
    const signed = await api('blind-sign.php', { game: GAME_ID, blinded: blinded });
    const credential = SH.unblindCredential(signed.blind_sig, r, game.cred_n);

    // Saved BEFORE the anonymous POST: if registration succeeds and we crash
    // before writing, the credential is spent and the slot is unreachable forever.
    SH.saveIdentity(GAME_ID, fresh);
    identity = fresh;

    await SH.anonPost(APP + '/anon/register.php', {
      game: GAME_ID,
      idk: fresh.idkPub,
      sigk: fresh.sigkPub,
      credential: credential,
    });
    note('Anonymous identity registered. Waiting for the rest of the table.', 'ok');
    showRecovery();
  }

  /* ---------- opening the card (PROTOCOL.md sec 5) ---------- */

  async function ensureCard(feed) {
    if (card) return;
    const cached = localStorage.getItem(cardStoreKey());
    if (cached) { card = JSON.parse(cached); await deriveTeam(feed); return; }
    if (!identity || !feed.cards.length) return;

    // We do not know our own slot yet, so try every sealed card. Exactly one
    // opens -- that is what tells us which slot we are.
    for (const c of feed.cards) {
      try {
        const opened = await SH.eciesOpen(c.ciphertext, identity.idkPriv, CARD_INFO);
        card = opened;
        localStorage.setItem(cardStoreKey(), JSON.stringify(opened));
        await deriveTeam(feed);
        showRecovery();
        return;
      } catch (e) { /* not ours; the GCM tag failing IS the negative answer */ }
    }
  }

  async function deriveTeam(feed) {
    teamKeys = [];
    if (!card || card.role !== 'MAFIA' || !card.team) return;
    for (const mate of card.team) {
      const slot = feed.slots.find((s) => Number(s.slot_index) === Number(mate));
      if (slot) teamKeys.push({ slot: Number(mate), key: await SH.pairKey(identity.idkPriv, slot.idk_pub) });
    }
  }

  /* ---------- the anonymous channel (PROTOCOL.md sec 6) ---------- */

  async function signAndPost(ct) {
    const payload = JSON.stringify({
      game: GAME_ID, slot: card.slot, phase_no: state.game.phase_no, phase: state.game.phase, ct: ct,
    });
    const sig = await SH.signAnon(identity.sigkPriv, payload);
    await SH.anonPost(APP + '/anon/post.php', { game: GAME_ID, slot: card.slot, ct: ct, sig: sig });
  }

  // Posted once per phase by EVERY living client, so no slot is ever
  // conspicuously silent. Town clients post only this.
  async function postCover() {
    const tag = state.game.phase + state.game.phase_no;
    if (coveredPhase === tag || !card || !isAlive(state.me)) return;
    coveredPhase = tag;
    for (let i = 0; i < COVER_BLOBS; i++) {
      await signAndPost(SH.coverBlob());
    }
  }

  async function sendTeamMessage(text) {
    // One copy per teammate under the pairwise key. With 2-3 mafia this costs a
    // couple of extra blobs and saves a whole group-key negotiation.
    for (const mate of teamKeys) {
      const blobs = await SH.sealBlobs(mate.key, text);
      for (const b of blobs) await signAndPost(b);
    }
  }

  async function readTeamMessages(feed) {
    if (!teamKeys.length) return [];
    const chunks = new Map();
    for (const entry of feed.channel) {
      for (const mate of teamKeys) {
        const c = await SH.openBlob(mate.key, entry.ciphertext);
        if (!c) continue;
        if (!chunks.has(c.msgId)) chunks.set(c.msgId, { slot: Number(entry.slot_index), total: c.total, parts: new Map() });
        chunks.get(c.msgId).parts.set(c.index, c.body);
        break;
      }
    }
    const out = [];
    for (const [msgId, m] of chunks) {
      if (m.parts.size !== m.total) continue; // a partially-delivered message just waits
      let body = '';
      for (let i = 0; i < m.total; i++) body += m.parts.get(i);
      out.push({ msgId: msgId, slot: m.slot, body: body });
    }
    return out;
  }


  /* ---------- the two-lock envelope (PROTOCOL.md sec 5.2) ---------- */

  // Published once, by every player, as soon as the investigator slots are known.
  // The outer lock is an investigator's public key; the inner lock is a key we
  // hand to the server, which is safe only because it can never strip the outer
  // one. Publishing is unconditional -- a player who skipped it would be
  // advertising something.
  async function ensureEnvelopes(feed) {
    if (!card || feed.my_envelope_published) return;
    const investigators = feed.investigator_slots || [];
    if (!investigators.length) return;

    const innerKey = SH.randomAesKey();
    const claim = JSON.stringify({ game: GAME_ID, account: feed.me, slot: card.slot });
    const payload = {
      game: GAME_ID, account: feed.me, slot: card.slot,
      sig: await SH.signAnon(identity.sigkPriv, claim),
    };
    const inner = await SH.innerSeal(innerKey, payload);

    const envelopes = {};
    for (const v of investigators) {
      const slot = feed.slots.find((x) => Number(x.slot_index) === Number(v));
      if (!slot) return; // slots not published yet; try again next poll
      envelopes[v] = await SH.eciesSeal(slot.idk_pub, { inner: inner }, ENVELOPE_INFO);
    }
    await api('envelope.php', { game: GAME_ID, inner_key: innerKey, envelopes: envelopes });
  }

  /* ---------- the reverse direction, for the watcher (PROTOCOL.md sec 5.4) ---------- */

  // An account-bound signing key, published while logged in so the SERVER attests
  // the binding. It names no slot, so publishing it authenticated is safe -- and
  // it is the only thing that lets a watcher trust a slot-indexed claim later.
  async function ensureAccountKey(feed) {
    if (feed.my_account_key_published) return;
    if (!identity.acct) {
      identity.acct = await SH.generateSigningKey();
      SH.saveIdentity(GAME_ID, identity);
    }
    await api('account-key.php', { game: GAME_ID, sigk_pub: identity.acct.pub });
  }

  // slot -> account, sealed to the watcher. Posted ANONYMOUSLY and signed by the
  // slot: a logged-in POST of a slot-labelled row would be the leak itself. The
  // payload carries two signatures -- one from this slot's key, one from this
  // account's key -- so the watcher can check that one person holds both without
  // the server ever seeing either.
  async function ensureReverseEnvelope(feed) {
    if (!card || !identity.acct) return;
    const watchers = feed.reverse_slots || [];
    if (!watchers.length) return;
    if ((feed.reverse_envelopes || []).some((r) => Number(r.slot_index) === card.slot)) return;

    const claim = JSON.stringify({ game: GAME_ID, slot: card.slot, account: feed.me });
    const payload = {
      game: GAME_ID, slot: card.slot, account: feed.me,
      slot_sig: await SH.signAnon(identity.sigkPriv, claim),
      acct_sig: await SH.signAnon(identity.acct.priv, claim),
    };
    const innerKey = SH.randomAesKey();
    const inner = await SH.innerSeal(innerKey, payload);

    // Setups carry at most one watcher, which is what lets a single ciphertext per
    // slot suffice. A second watcher would need a row per (slot, watcher).
    const slot = feed.slots.find((x) => Number(x.slot_index) === Number(watchers[0]));
    if (!slot) return;
    const ct = await SH.eciesSeal(slot.idk_pub, { inner: inner }, REVERSE_INFO);

    const sigPayload = JSON.stringify({ game: GAME_ID, slot: card.slot, ct: ct, inner_key: innerKey });
    const sig = await SH.signAnon(identity.sigkPriv, sigPayload);
    await SH.anonPost(APP + '/anon/reverse.php', {
      game: GAME_ID, slot: card.slot, ct: ct, inner_key: innerKey, sig: sig,
    });
  }

  function amWatcher(feed) {
    return card && (feed.reverse_slots || []).includes(card.slot);
  }

  function watchKey(night) { return 'scumhouse/watcheph/' + GAME_ID + '/' + night; }

  async function submitWatch(feed, targetUserId) {
    const night = feed.game.phase_no;
    const eph = await SH.ephemeralKeyPair();
    localStorage.setItem(watchKey(night), JSON.stringify({ priv: eph.priv, name: nameOf(targetUserId) }));
    const payload = JSON.stringify({
      game: GAME_ID, slot: card.slot, night: night, target: targetUserId, ephemeral_pub: eph.pub,
    });
    const sig = await SH.signAnon(identity.sigkPriv, payload);
    await SH.anonPost(APP + '/anon/watch.php', {
      game: GAME_ID, slot: card.slot, night: night, target: targetUserId,
      ephemeral_pub: eph.pub, sig: sig,
    });
    localStorage.setItem(resultKey(night), JSON.stringify({
      night: night, text: 'Watching ' + nameOf(targetUserId) + '. Your report arrives at dawn.',
    }));
  }

  async function readWatcherReports(feed) {
    if (!card || !amWatcher(feed)) return;
    for (const r of feed.watcher_reports || []) {
      if (Number(r.slot_index) !== card.slot) continue;
      const night = Number(r.night_no);
      const key = 'scumhouse/watch-result/' + GAME_ID + '/' + night;
      if (localStorage.getItem(key)) continue;
      const eph = loadLocal(watchKey(night));
      if (!eph) continue;
      let report;
      try {
        report = await SH.eciesOpen(r.ciphertext, eph.priv, WATCH_INFO);
      } catch (e) { continue; }

      const names = [];
      for (const v of report.visitors) {
        const name = await nameReverseSlot(feed, v.slot, v.inner_key);
        names.push(name);
      }
      const text = names.length
        ? eph.name + ' was visited on night ' + night + ' by ' + names.join(', ') + '.'
        : eph.name + ' was visited by nobody on night ' + night + '.';
      localStorage.setItem(key, JSON.stringify({ night: night, text: text }));
      note(text, 'ok');
    }
  }

  // Opens one reverse envelope and checks BOTH signatures over the same claim: the
  // slot's, proving whoever wrote it holds that slot, and the account's, proving
  // they hold that account. Either alone would be forgeable.
  async function nameReverseSlot(feed, slotIndex, innerKey) {
    if (!innerKey) return 'someone who published no envelope (slot #' + slotIndex + ')';
    const row = (feed.reverse_envelopes || []).find((x) => Number(x.slot_index) === Number(slotIndex));
    if (!row) return 'an unidentifiable visitor (slot #' + slotIndex + ')';
    try {
      const outer = await SH.eciesOpen(row.ciphertext, identity.idkPriv, REVERSE_INFO);
      const payload = await SH.innerOpen(innerKey, outer.inner);
      const claim = JSON.stringify({ game: payload.game, slot: payload.slot, account: payload.account });

      const slot = feed.slots.find((x) => Number(x.slot_index) === Number(payload.slot));
      const acct = (feed.account_keys || []).find((k) => Number(k.user_id) === Number(payload.account));
      if (!slot || !acct) return 'an unverifiable visitor (slot #' + slotIndex + ')';
      const slotOk = await SH.verifyAnon(slot.sigk_pub, claim, payload.slot_sig);
      const acctOk = await SH.verifyAnon(acct.sigk_pub, claim, payload.acct_sig);
      if (!slotOk || !acctOk || Number(payload.slot) !== Number(slotIndex)) {
        return 'a visitor whose claim failed verification (slot #' + slotIndex + ')';
      }
      return nameOf(Number(payload.account));
    } catch (e) {
      return 'an unreadable visitor (slot #' + slotIndex + ')';
    }
  }

  async function ensureRoleTable(feed) {
    if (roleTable || !card || card.role !== 'COP') return;
    const row = (feed.role_table || []).find((r) => Number(r.slot_index) === card.slot);
    if (!row) return;
    const opened = await SH.eciesOpen(row.ciphertext, identity.idkPriv, TABLE_INFO);
    roleTable = opened.roles;
  }

  function amInvestigator(feed) {
    return card && (feed.investigator_slots || []).includes(card.slot);
  }

  function resultKey(night) { return 'scumhouse/result/' + GAME_ID + '/' + night; }
  function ephKey(night) { return 'scumhouse/eph/' + GAME_ID + '/' + night; }
  function trackKey(night) { return 'scumhouse/trackeph/' + GAME_ID + '/' + night; }

  function loadLocal(key) {
    const raw = localStorage.getItem(key);
    return raw ? JSON.parse(raw) : null;
  }

  // Stage 1. Draws a blind-signed token and QUEUES a question. Nothing comes back
  // now: every answer for the night is released together at a fixed point, so that
  // the order in which questions were asked -- the investigator deliberating while
  // everyone else spends on autopilot -- carries no information.
  async function queueQuestion(feed, targetUserId) {
    const night = feed.game.phase_no;
    const nonce = SH.b64u(SH.randomBytes(16));
    const msg = SH.tokenMessage(GAME_ID, night, nonce);

    const { blinded, r } = await SH.blindCredential(msg, feed.game.cred_n, feed.game.cred_e);
    const tokenPayload = JSON.stringify({ game: GAME_ID, slot: card.slot, night: night, blinded: blinded });
    const sig = await SH.signAnon(identity.sigkPriv, tokenPayload);
    const issued = await SH.anonPost(APP + '/anon/token.php', {
      game: GAME_ID, slot: card.slot, night: night, blinded: blinded, sig: sig,
    });
    const token = SH.unblindCredential(issued.blind_sig, r, feed.game.cred_n);

    const eph = await SH.ephemeralKeyPair();
    // Stored BEFORE the request: losing this key after the token is spent would
    // make the answer permanently unreadable, and the token is not reissued.
    localStorage.setItem(ephKey(night), JSON.stringify({ priv: eph.priv, target: targetUserId }));

    await SH.anonPost(APP + '/anon/redeem.php', {
      game: GAME_ID, night: night, nonce: nonce, token: token,
      target: targetUserId, ephemeral_pub: eph.pub,
    });
    spentNight = night;
  }

  // Town clients ask about somebody at random. This is the cover the real question
  // hides in, and it is why the server sees a pile of names rather than one.
  async function queueCoverQuestion(feed) {
    if (!card || spentNight === feed.game.phase_no) return;
    if (amInvestigator(feed)) return;              // theirs is queued by choosing
    if (!isAlive(feed.me) || !feed.my_envelope_published) return;
    if (feed.game.keys_released) return;           // window closed
    if (loadLocal(ephKey(feed.game.phase_no))) { spentNight = feed.game.phase_no; return; }
    const living = feed.players.filter((p) => p.alive);
    if (!living.length) return;
    const pick = living[Math.floor(Math.random() * living.length)];
    try {
      await queueQuestion(feed, pick.user_id);
    } catch (e) {
      if (!/already|closed/.test(e.message)) throw e;
      spentNight = feed.game.phase_no;
    }
  }

  async function chooseTarget(feed, targetUserId) {
    try {
      note('Question queued. Every answer for tonight opens together at the release point.');
      await queueQuestion(feed, targetUserId);
      await poll();
    } catch (e) {
      note(e.message, 'error');
    }
  }

  // Stage 2. After the release point the whole night's batch is public; we find
  // ours by trial-decrypting with the one-use key we kept. Nobody watching can
  // tell which of them is ours, including the server that sealed them.
  async function collectAnswer(feed) {
    const night = feed.game.phase_no;
    if (!amInvestigator(feed) || !feed.game.keys_released) return;
    if (localStorage.getItem(resultKey(night))) return;
    const mine = loadLocal(ephKey(night));
    if (!mine) return;

    const batch = await SH.anonPost(APP + '/anon/answers.php', { game: GAME_ID, night: night });
    if (!batch.released) return;
    for (const sealed of batch.answers) {
      let answer;
      try {
        answer = await SH.eciesOpen(sealed, mine.priv, KEY_INFO);
      } catch (e) { continue; }
      await applyAnswer(feed, answer.inner_key, answer.target, night);
      return;
    }
  }

  async function applyAnswer(feed, innerKey, targetUserId, night) {
    const env = (feed.envelopes || []).find(
      (e) => Number(e.user_id) === targetUserId && Number(e.investigator_slot) === card.slot
    );
    if (!env) throw new Error('that player published no envelope');

    const outer = await SH.eciesOpen(env.ciphertext, identity.idkPriv, ENVELOPE_INFO);
    const payload = await SH.innerOpen(innerKey, outer.inner);

    // The target cannot lie: the claim is signed by the slot it names.
    const claim = JSON.stringify({ game: payload.game, account: payload.account, slot: payload.slot });
    const slot = feed.slots.find((x) => Number(x.slot_index) === Number(payload.slot));
    if (!slot || !await SH.verifyAnon(slot.sigk_pub, claim, payload.sig)) {
      throw new Error('that envelope failed its signature check -- report this');
    }
    if (Number(payload.account) !== targetUserId) {
      throw new Error('the server answered about the wrong player -- report this');
    }

    let text;
    if (card.role === 'COP') {
      const role = roleTable ? roleTable[payload.slot] : null;
      if (!role) return; // table not open yet; retry next poll
      text = nameOf(targetUserId) + ' is ' + (role === 'MAFIA' ? 'MAFIA' : 'not mafia (' + role + ')');
    } else if (card.role === 'ROLEBLOCKER') {
      await submitBlock(payload.slot);
      text = 'Blocked ' + nameOf(targetUserId) + ' for the rest of tonight.';
    } else {
      await submitTrack(feed, payload.slot, targetUserId);
      text = 'Following ' + nameOf(targetUserId) + '. Your report arrives at dawn.';
    }
    localStorage.setItem(resultKey(night), JSON.stringify({ night: night, text: text }));
    note(text, 'ok');
  }

  async function submitBlock(targetSlot) {
    const payload = JSON.stringify({
      game: GAME_ID, slot: card.slot, night: state.game.phase_no,
      action: 'block', target: null, target_slot: targetSlot,
    });
    const sig = await SH.signAnon(identity.sigkPriv, payload);
    await SH.anonPost(APP + '/anon/action.php', {
      game: GAME_ID, slot: card.slot, night: state.game.phase_no,
      action: 'block', target_slot: targetSlot, sig: sig,
    });
  }

  async function submitTrack(feed, targetSlot, targetUserId) {
    const night = feed.game.phase_no;
    const eph = await SH.ephemeralKeyPair();
    localStorage.setItem(trackKey(night), JSON.stringify({ priv: eph.priv, name: nameOf(targetUserId) }));
    const payload = JSON.stringify({
      game: GAME_ID, slot: card.slot, night: night, target_slot: targetSlot, ephemeral_pub: eph.pub,
    });
    const sig = await SH.signAnon(identity.sigkPriv, payload);
    await SH.anonPost(APP + '/anon/track.php', {
      game: GAME_ID, slot: card.slot, night: night, target_slot: targetSlot,
      ephemeral_pub: eph.pub, sig: sig,
    });
  }

  // The tracker's report is sealed at dawn, once the night's actions are final --
  // there is nothing to report before then.
  async function readTrackerReports(feed) {
    if (!card || card.role !== 'TRACKER') return;
    for (const r of feed.tracker_reports || []) {
      if (Number(r.slot_index) !== card.slot) continue;
      const night = Number(r.night_no);
      const key = 'scumhouse/track-result/' + GAME_ID + '/' + night;
      if (localStorage.getItem(key)) continue;
      const eph = loadLocal(trackKey(night));
      if (!eph) continue;
      try {
        const report = await SH.eciesOpen(r.ciphertext, eph.priv, TRACK_INFO);
        const text = report.visited === null
          ? eph.name + ' visited nobody on night ' + night + '.'
          : eph.name + ' visited ' + nameOf(report.visited) + ' on night ' + night + '.';
        localStorage.setItem(key, JSON.stringify({ night: night, text: text }));
        note(text, 'ok');
      } catch (e) { /* not ours, or key lost */ }
    }
  }

  /* ---------- death flip (PROTOCOL.md sec 9) ---------- */

  async function autoFlip(feed) {
    if (!card) return;
    const me = feed.players.find((p) => p.user_id === feed.me);
    if (!me || me.alive) return;
    if (feed.flips.some((f) => Number(f.user_id) === feed.me)) return;

    const payload = JSON.stringify({ game: GAME_ID, slot: card.slot, user: feed.me, role: card.role });
    const sig = await SH.signAnon(identity.sigkPriv, payload);
    await SH.anonPost(APP + '/anon/flip.php', {
      game: GAME_ID, slot: card.slot, user: feed.me, role: card.role, sig: sig,
    });
  }

  /* ---------- rendering ---------- */

  function isAlive(userId) {
    const p = state.players.find((x) => x.user_id === userId);
    return p ? p.alive : false;
  }

  function nameOf(userId) {
    const p = state.players.find((x) => x.user_id === userId);
    return p ? p.name : 'someone';
  }

  function renderHeader(feed) {
    const g = feed.game;
    const h = $('sh-phase');
    let label;
    if (g.status === 'registration') label = 'Setting up -- ' + feed.registered + ' of ' + g.num_seats + ' identities registered';
    else if (g.status === 'finished') label = (g.winner === 'MAFIA' ? 'The mafia win' : 'The town wins');
    else if (g.status === 'active') label = (g.phase === 'day' ? 'Day ' : 'Night ') + g.phase_no;
    else label = 'Waiting for players';
    h.textContent = label;

    const clock = $('sh-clock');
    if (g.phase_ends_at && g.status === 'active') {
      const left = Math.max(0, new Date(g.phase_ends_at.replace(' ', 'T') + 'Z').getTime() - Date.now());
      const hrs = Math.floor(left / 3600000);
      const mins = Math.floor((left % 3600000) / 60000);
      clock.textContent = left > 0 ? (hrs + 'h ' + mins + 'm left') : 'deadline passed';
    } else {
      clock.textContent = '';
    }

    if (feed.pending_flips.length) {
      note('Waiting on ' + feed.pending_flips.map((f) => f.display_name).join(', ') +
           ' to open their card. The clock is paused until they do.', 'warn');
    }
  }

  function renderCard() {
    const box = $('sh-card');
    if (!card) { box.textContent = ''; return; }
    if (box.dataset.role === card.role) return; // never rebuild; nothing here changes
    box.dataset.role = card.role;
    box.innerHTML = '';
    box.appendChild(el('h3', null, 'You are ' + (card.role === 'MAFIA' ? 'MAFIA' : card.role)));
    const blurb = {
      MAFIA: 'Kill one player each night. Talk to your partner in the private channel below -- the server cannot read it.',
      COP: 'Look into one player each night and learn whether they are mafia. You submit nothing to the server; your whole night happens in this browser.',
      ROLEBLOCKER: 'Stop one player\u2019s night action. You learn which slot they hold, never what they were going to do.',
      WATCHER: 'Watch one player each night and learn who visited them. Your report arrives at dawn.',
      TRACKER: 'Follow one player each night and learn who they visited. Your report arrives at dawn, once the night is final.',
      DOCTOR: 'Protect one player each night. You may not protect the same player twice in a row.',
      VIGILANTE: 'You have two shots for the whole game, and none on night 1. Use them badly and you help the mafia.',
      TOWN: 'You have no night action. Your vote is your weapon.',
    }[card.role];
    box.appendChild(el('p', null, blurb));
    box.className = 'sh-card sh-card-' + card.role.toLowerCase();
  }

  function renderPlayers(feed) {
    const list = $('sh-players');
    list.innerHTML = '';
    const tally = {};
    for (const v of feed.votes) {
      if (v.target_user_id !== null) tally[v.target_user_id] = (tally[v.target_user_id] || 0) + 1;
    }
    for (const p of feed.players) {
      const row = el('li', p.alive ? 'alive' : 'dead');
      row.appendChild(el('span', 'sh-name', p.name));
      const flip = feed.flips.find((f) => Number(f.user_id) === p.user_id);
      if (!p.alive) {
        row.appendChild(el('span', 'sh-fate',
          (p.died_cause === 'lynch' ? 'lynched' : 'killed') + ' ' +
          (p.died_cause === 'lynch' ? 'day ' : 'night ') + p.died_phase_no +
          (flip ? ' -- ' + flip.role : ' -- card not yet opened')));
      } else if (tally[p.user_id]) {
        row.appendChild(el('span', 'sh-votes', tally[p.user_id] + ' vote' + (tally[p.user_id] > 1 ? 's' : '')));
      }
      list.appendChild(row);
    }
  }

  function renderThread(feed) {
    const box = $('sh-thread');
    // Append-only: rebuilding would fight the scroll position on every poll.
    const have = box.childElementCount;
    for (let i = have; i < feed.thread.length; i++) {
      const p = feed.thread[i];
      const post = el('div', 'sh-post');
      post.appendChild(el('span', 'sh-post-who', p.display_name));
      post.appendChild(el('span', 'sh-post-when', 'day ' + p.phase_no));
      post.appendChild(el('p', 'sh-post-body', p.body));
      box.appendChild(post);
    }
    if (feed.thread.length > have) box.scrollTop = box.scrollHeight;
  }

  function renderTeam(messages) {
    const panel = $('sh-team');
    if (!teamKeys.length) { panel.hidden = true; return; }
    panel.hidden = false;
    const log = $('sh-team-log');
    const have = log.childElementCount;
    for (let i = have; i < messages.length; i++) {
      const m = messages[i];
      const line = el('div', 'sh-team-msg');
      line.appendChild(el('span', 'sh-post-who', m.slot === card.slot ? 'you' : 'partner #' + m.slot));
      line.appendChild(el('p', 'sh-post-body', m.body));
      log.appendChild(line);
    }
    if (messages.length > have) log.scrollTop = log.scrollHeight;
  }

  function renderVoteAndNight(feed) {
    const g = feed.game;
    const votePanel = $('sh-vote');
    const nightPanel = $('sh-night');
    const alive = feed.players.filter((p) => p.alive);
    const iAmAlive = isAlive(feed.me);

    votePanel.hidden = !(g.status === 'active' && g.phase === 'day' && iAmAlive);
    const action = card ? nightAction() : null;
    nightPanel.hidden = !(g.status === 'active' && g.phase === 'night' && iAmAlive && action);

    // Rebuild target buttons only when the living set actually changed, so a
    // half-made choice is not yanked out from under the player mid-poll.
    for (const [panel, handler] of [[votePanel, castVote], [nightPanel, submitNight]]) {
      if (panel.hidden) continue;
      const targets = panel.querySelector('.sh-targets');
      const key = alive.map((p) => p.user_id).join(',') + '|' + g.phase_no;
      if (targets.dataset.key === key) continue;
      targets.dataset.key = key;
      targets.innerHTML = '';
      for (const p of alive) {
        const b = el('button', 'sh-target', p.name);
        b.onclick = () => handler(p.user_id);
        targets.appendChild(b);
      }
      if (panel === votePanel) {
        const b = el('button', 'sh-target sh-nolynch', 'No lynch');
        b.onclick = () => castVote(null);
        targets.appendChild(b);
      }
    }
    if (!nightPanel.hidden) {
      $('sh-night-label').textContent = {
        kill: 'Choose tonight’s target. Agree it with your partner first.',
        protect: 'Choose who to protect tonight.',
        vigkill: 'Choose who to shoot. You do not get this back.',
      }[action];
    }
  }

  function renderInvestigate(feed) {
    const panel = $('sh-investigate');
    const g = feed.game;
    const watcher = amWatcher(feed);
    const active = g.status === 'active' && g.phase === 'night' && isAlive(feed.me)
      && (amInvestigator(feed) || watcher);
    panel.hidden = !active;
    if (!active) return;

    if (watcher) { renderWatch(feed); return; }
    const asked = !!loadLocal(ephKey(g.phase_no));
    const answer = loadLocal(resultKey(g.phase_no));
    const verb = card.role === 'COP' ? 'Look into' : (card.role === 'ROLEBLOCKER' ? 'Block' : 'Follow');

    let label;
    if (answer) {
      label = answer.text;
    } else if (asked && !g.keys_released) {
      // Naming the release time is the point: it is the same for everybody, so
      // waiting for it tells nobody anything.
      label = 'Question queued. Every answer tonight opens at ' + shortTime(g.key_release_at) + '.';
    } else if (asked) {
      label = 'Opening your answer...';
    } else if (g.keys_released) {
      label = 'The retrieval window closed at ' + shortTime(g.key_release_at) + '. Nothing tonight.';
    } else {
      label = verb + ' one player. Choose before ' + shortTime(g.key_release_at) + '.';
    }
    $('sh-investigate-label').textContent = label;

    const past = loadLocal('scumhouse/track-result/' + GAME_ID + '/' + (g.phase_no - 1));
    $('sh-investigate-result').textContent = past ? past.text : '';

    const targets = panel.querySelector('.sh-targets');
    const living = feed.players.filter((p) => p.alive && p.user_id !== feed.me);
    const done = asked || g.keys_released;
    const key = living.map((p) => p.user_id).join(',') + '|' + g.phase_no + '|' + (done ? 'done' : 'open');
    if (targets.dataset.key === key) return;
    targets.dataset.key = key;
    targets.innerHTML = '';
    if (done) return;
    for (const p of living) {
      const b = el('button', 'sh-target', p.name);
      b.onclick = () => chooseTarget(feed, p.user_id);
      targets.appendChild(b);
    }
  }

  // The watcher needs no retrieval token: its answer arrives with the keys it
  // needs, because only the slots that actually visited the target get unlocked.
  function renderWatch(feed) {
    const g = feed.game;
    const panel = $('sh-investigate');
    const answer = loadLocal(resultKey(g.phase_no));
    $('sh-investigate-label').textContent = answer
      ? answer.text
      : 'Watch one player tonight. At dawn you learn who visited them.';

    const past = loadLocal('scumhouse/watch-result/' + GAME_ID + '/' + (g.phase_no - 1));
    $('sh-investigate-result').textContent = past ? past.text : '';

    const targets = panel.querySelector('.sh-targets');
    const living = feed.players.filter((p) => p.alive);
    const key = living.map((p) => p.user_id).join(',') + '|' + g.phase_no + '|' + (answer ? 'done' : 'open');
    if (targets.dataset.key === key) return;
    targets.dataset.key = key;
    targets.innerHTML = '';
    if (answer) return;
    for (const p of living) {
      const b = el('button', 'sh-target', p.name);
      b.onclick = async () => {
        try { await submitWatch(feed, p.user_id); await poll(); }
        catch (e) { note(e.message, 'error'); }
      };
      targets.appendChild(b);
    }
  }

  function shortTime(sqlTime) {
    if (!sqlTime) return 'the release point';
    const d = new Date(sqlTime.replace(' ', 'T') + 'Z');
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  function nightAction() {
    if (!card) return null;
    if (card.role === 'MAFIA') return 'kill';
    if (card.role === 'DOCTOR') return 'protect';
    if (card.role === 'VIGILANTE') return state.game.phase_no >= 2 ? 'vigkill' : null;
    // COP and ROLEBLOCKER act through the investigation panel, not this one.
    return null;
  }

  /* ---------- actions ---------- */

  async function castVote(target) {
    try {
      await api('vote.php', { game: GAME_ID, target: target });
      note(target === null ? 'Voted for no lynch.' : 'Voted for ' + nameOf(target) + '.', 'ok');
      await poll();
    } catch (e) { note(e.message, 'error'); }
  }

  async function submitNight(target) {
    const action = nightAction();
    if (!action) return;
    try {
      const payload = JSON.stringify({
        game: GAME_ID, slot: card.slot, night: state.game.phase_no, action: action, target: target,
      });
      const sig = await SH.signAnon(identity.sigkPriv, payload);
      await SH.anonPost(APP + '/anon/action.php', {
        game: GAME_ID, slot: card.slot, night: state.game.phase_no, action: action, target: target, sig: sig,
      });
      note('Night action submitted against ' + nameOf(target) + '. You can change it until dawn.', 'ok');
    } catch (e) { note(e.message, 'error'); }
  }

  /* ---------- recovery UI (PROTOCOL.md sec 10) ---------- */

  function showRecovery() {
    if (!identity) return;
    $('sh-recovery-code').value = SH.recoveryCode(identity);
    $('sh-recovery').hidden = false;
  }

  async function saveBackup() {
    const pass = $('sh-backup-pass').value;
    if (pass.length < 12) { note('Use a passphrase of at least 12 characters.', 'error'); return; }
    try {
      const wrapped = await SH.wrapIdentity(identity, pass);
      await api('key-backup.php', { game: GAME_ID, wrapped: wrapped });
      $('sh-backup-pass').value = '';
      note('Encrypted backup stored. The passphrase never left this browser.', 'ok');
    } catch (e) { note(e.message, 'error'); }
  }

  async function restoreBackup() {
    const pass = prompt('Passphrase for your stored backup:');
    if (!pass) return;
    try {
      identity = await SH.unwrapIdentity(state.key_backup, pass);
      SH.saveIdentity(GAME_ID, identity);
      card = null;
      localStorage.removeItem(cardStoreKey());
      note('Identity restored.', 'ok');
      await poll();
    } catch (e) { note('That passphrase did not open the backup.', 'error'); }
  }

  function restoreFromCode() {
    const code = prompt('Paste your recovery code:');
    if (!code) return;
    try {
      identity = SH.fromRecoveryCode(code.trim());
      SH.saveIdentity(GAME_ID, identity);
      card = null;
      localStorage.removeItem(cardStoreKey());
      note('Identity restored from code.', 'ok');
      poll();
    } catch (e) { note('That does not look like a recovery code.', 'error'); }
  }

  /* ---------- main loop ---------- */

  async function poll() {
    if (busy) return;
    busy = true;
    try {
      const feed = await api('feed.php?game=' + GAME_ID);
      state = feed;

      if (feed.game.status === 'registration') await ensureIdentity(feed.game);
      if (!identity) identity = SH.loadIdentity(GAME_ID);
      if (feed.game.status === 'active' || feed.game.status === 'finished') {
        await ensureCard(feed);
        if (card) {
          await autoFlip(feed);
          if (feed.game.status === 'active') {
            await ensureEnvelopes(feed);
            await ensureAccountKey(feed);
            await ensureReverseEnvelope(feed);
            await ensureRoleTable(feed);
            await postCover();
            if (feed.game.phase === 'night') {
              await queueCoverQuestion(feed);
              await collectAnswer(feed);
            }
            await readTrackerReports(feed);
            await readWatcherReports(feed);
          }
        }
      }

      renderHeader(feed);
      renderCard();
      renderPlayers(feed);
      renderThread(feed);
      renderVoteAndNight(feed);
      renderInvestigate(feed);
      renderTeam(await readTeamMessages(feed));

      $('sh-restore-backup').hidden = !(feed.key_backup && !card);
      if (identity && $('sh-recovery').hidden) showRecovery();
    } catch (e) {
      note(e.message, 'error');
    } finally {
      busy = false;
    }
  }

  function wire() {
    $('sh-say').onclick = async () => {
      const box = $('sh-say-body');
      const body = box.value.trim();
      if (!body) return;
      try {
        await api('say.php', { game: GAME_ID, body: body });
        box.value = '';
        await poll();
      } catch (e) { note(e.message, 'error'); }
    };
    $('sh-team-send').onclick = async () => {
      const box = $('sh-team-body');
      const body = box.value.trim();
      if (!body) return;
      $('sh-team-send').disabled = true;
      try {
        await sendTeamMessage(body);
        box.value = '';
        await poll();
      } catch (e) { note(e.message, 'error'); } finally { $('sh-team-send').disabled = false; }
    };
    $('sh-backup-save').onclick = saveBackup;
    $('sh-restore-backup').onclick = restoreBackup;
    $('sh-restore-code').onclick = restoreFromCode;
  }

  wire();
  poll();
  setInterval(poll, POLL_MS);
})();
