#!/usr/bin/env node
/*
 * Runs one bot seat.
 *
 *   node bot/run.mjs --base https://example.com/scumhouse \
 *                    --token <api token> --game 3 --state ~/.scumhouse/bot3.json
 *
 * Run it wherever YOU are, not on the game server. A bot holds a dealt card, and
 * a card dealt mafia can read that team's channel -- where the humans introduce
 * themselves by name. On the game host that would hand the operator the very
 * thing the protocol exists to withhold. See bot/README.md.
 *
 *   --once     do one pass and exit (for cron, or for testing)
 *   --quiet    no logging
 */
import path from 'path';
import os from 'os';
import { Seat } from './client.mjs';
import { heuristicStrategy } from './strategy.mjs';

function arg(name, fallback) {
  const i = process.argv.indexOf('--' + name);
  return i >= 0 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--')
    ? process.argv[i + 1] : fallback;
}
const flag = (name) => process.argv.includes('--' + name);

const base = arg('base');
const token = arg('token', process.env.SCUMHOUSE_TOKEN);
const gameId = Number(arg('game'));
if (!base || !token || !gameId) {
  console.error('usage: run.mjs --base URL --token TOKEN --game N [--state FILE] [--once] [--quiet]');
  process.exit(2);
}
const statePath = arg('state', path.join(os.homedir(), '.scumhouse', `bot-${gameId}.json`));
const quiet = flag('quiet');

const seat = new Seat({
  base, token, gameId, statePath, strategy: heuristicStrategy,
  log: (m) => quiet || console.log(`[${new Date().toISOString()}] ${m}`),
});

/** Everything this seat is allowed to see, as plain data. Assembled here rather
 * than inside the strategy so that a strategy -- including an LLM one -- can
 * never reach past it into the client and ask the server something extra. */
async function buildView() {
  const f = seat.feed;
  const g = f.game;
  const phaseTag = g.phase + g.phase_no;
  const teamMessages = await seat.readTeam();
  return {
    me: f.me,
    role: seat.card ? seat.card.role : null,
    slot: seat.card ? seat.card.slot : null,
    phase: g.phase,
    phase_no: g.phase_no,
    status: g.status,
    players: f.players,
    thread: f.thread,
    votes: f.votes,
    flips: f.flips,
    teamMessages,
    known: Object.values(seat.state.answered || {}).map((a) => a.account),
    // What investigations established: account id -> role. A cop that does not
    // consult this is just a townie who wasted a night.
    reads: seat.state.reads || {},
    lastProtected: seat.state.lastProtected || null,
    saidThisPhase: !!(seat.state.said || {})[phaseTag],
    saidToTeamThisPhase: !!(seat.state.saidTeam || {})[phaseTag],
    votedThisPhase: f.votes.some((v) => Number(v.voter_user_id) === f.me),
    actedThisNight: !!(seat.state.acted || {})[phaseTag],
    investigatedThisNight: !!(seat.state.eph || {})[g.phase_no],
    nameOf: (id) => seat.nameOf(id),
  };
}

const mark = (bucket, key) => {
  seat.state[bucket] = seat.state[bucket] || {};
  seat.state[bucket][key] = true;
  seat.save();
};

async function pass() {
  await seat.refresh();
  const g = seat.feed.game;

  await seat.ensureIdentity();
  if (g.status !== 'active' && g.status !== 'finished') return;

  await seat.ensureCard();
  if (!seat.card) return;

  await seat.ensureAccountKey();
  await seat.ensureEnvelopes();
  await seat.ensureReverseEnvelope();
  await seat.ensureFlipEscrow();
  await seat.autoFlip();
  await seat.revealShares();
  if (g.status !== 'active' || !seat.isAlive(seat.me)) return;

  await seat.postCover();

  const phaseTag = g.phase + g.phase_no;
  const view = await buildView();

  const teamLine = await heuristicStrategy.mafiaChat(view);
  if (teamLine) { await seat.sayToTeam(teamLine); mark('saidTeam', phaseTag); }

  if (g.phase === 'day') {
    const line = await heuristicStrategy.daySpeak(view);
    if (line) { await seat.say(line); mark('said', phaseTag); seat.log('spoke'); }
    const target = await heuristicStrategy.dayVote(view);
    if (target) { await seat.vote(target); seat.log('voted for ' + seat.nameOf(target)); }
    return;
  }

  // Night.
  const action = await heuristicStrategy.nightAction(view);
  if (action) {
    await seat.nightAction(action.action, action.target);
    mark('acted', phaseTag);
    if (action.action === 'protect') { seat.state.lastProtected = action.target; seat.save(); }
    seat.log(action.action + ' -> ' + seat.nameOf(action.target));
  }

  if (seat.amWatcher()) {
    if (!(seat.state.watchEph || {})[g.phase_no]) {
      const t = await heuristicStrategy.investigate(view);
      if (t) await seat.submitWatch(t);
    }
    return;
  }

  if (seat.amInvestigator()) {
    if (!(seat.state.eph || {})[g.phase_no]) {
      const t = await heuristicStrategy.investigate(view);
      if (t) await seat.queueQuestion(t);
    }
    const answer = await seat.collectAnswer();
    if (answer) {
      if (seat.card.role === 'COP') {
        const table = await seat.roleTable();
        if (table) {
          seat.rememberRead(answer.account, table[answer.slot]);
          seat.log(`${seat.nameOf(answer.account)} is ${table[answer.slot]}`);
        }
      } else if (seat.card.role === 'ROLEBLOCKER') {
        await seat.submitBlock(answer.slot);
        seat.log('blocked ' + seat.nameOf(answer.account));
      } else if (seat.card.role === 'TRACKER') {
        await seat.submitTrack(answer.slot);
        seat.log('following ' + seat.nameOf(answer.account));
      }
    }
  }
}

async function main() {
  if (flag('once')) { await pass(); return; }
  seat.log(`seat running against ${base} game ${gameId}`);
  for (;;) {
    try { await pass(); } catch (e) { seat.log('error: ' + e.message); }
    // Jittered, and slow. A bot that acts the instant a phase opens is
    // identifiable by timing alone, and a forum game does not need fast.
    const wait = 90000 + Math.random() * 210000;
    await new Promise((r) => setTimeout(r, wait));
  }
}

main().catch((e) => { console.error(e); process.exit(1); });
