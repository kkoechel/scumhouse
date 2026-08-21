#!/usr/bin/env node
/*
 * The town has to agree, and agreeing is not the same as being right.
 *
 * sh_tally_votes (inc/engine.php) lynches on a plurality and treats a tie as a
 * no-lynch, so a town that scatters its votes does not get a coin-flip -- it
 * gets nothing, and a night passes for free. That makes "do five seats, each
 * reasoning alone and unable to compare notes, land on the same name?" a
 * property worth testing directly rather than inferring from a win rate.
 *
 * Day one is the worst case and the one tested here: no flips, no reads, no
 * votes yet, every score identically zero. Anything that breaks the tie by
 * chance splits the table; anything that breaks it from shared data does not.
 *
 * Runs on node alone -- no PHP, no database, no server.
 */
import assert from 'assert';
import { STRATEGIES } from '../bot/strategy.mjs';

function blankDay(me, n, phaseNo) {
  const players = Array.from({ length: n }, (_, i) => ({
    user_id: i + 1, name: 'p' + (i + 1), seat: i + 1,
    alive: true, died_phase_no: null, died_cause: null,
  }));
  return {
    me, role: 'TOWN', slot: me, phase: 'day', phase_no: phaseNo, status: 'active',
    players, thread: [], votes: [], flips: [], teamMessages: [], known: [], reads: {},
    lastProtected: null, saidThisPhase: true, saidToTeamThisPhase: true,
    votedThisPhase: false, actedThisNight: false, investigatedThisNight: false,
    memory: {}, nameOf: (id) => 'p' + id,
  };
}

async function blocFor(strategy, seats, phaseNo) {
  const picks = [];
  for (let me = 1; me <= seats; me++) picks.push(await strategy.dayVote(blankDay(me, seats, phaseNo)));
  const tally = {};
  for (const p of picks) tally[p] = (tally[p] || 0) + 1;
  return { picks, largest: Math.max(...Object.values(tally)) };
}

let failures = 0;
for (const seats of [5, 7, 9]) {
  const majority = Math.floor(seats / 2) + 1;
  for (const day of [1, 2, 3]) {
    const { picks, largest } = await blocFor(STRATEGIES.deducing, seats, day);
    // One seat can never vote for itself, so the ceiling is seats-1.
    const ok = largest >= majority;
    if (!ok) failures++;
    console.log(`  ${seats} seats, day ${day}: largest bloc ${largest}/${seats} ` +
                `(majority ${majority}) ${ok ? 'ok' : 'FAILED'}  picks=[${picks}]`);
  }
}

/* Seats do not share a memory, and that is the trap this pins.
 *
 * Each seat records the day's votes during its OWN pass, so one that acted
 * early holds half a tally and one that acted late holds all of it. An
 * ordering computed from that looks shared and is not -- measured, it dropped
 * the bloc from 5 of 6 to 3 of 6, which is a tie, which is a no-lynch. The
 * shared ordering must therefore come only from what the feed gives everyone
 * identically. */
{
  const N = 7, DEAD = 4;
  const mk = (me, voteLog) => ({
    me, role: 'TOWN', slot: me, phase: 'day', phase_no: 3, status: 'active',
    players: Array.from({ length: N }, (_, i) => ({
      user_id: i + 1, name: 'p' + (i + 1), seat: i + 1, alive: i + 1 !== DEAD,
      died_phase_no: i + 1 === DEAD ? 2 : null, died_cause: i + 1 === DEAD ? 'lynch' : null,
    })),
    thread: [], votes: [], flips: [{ slot_index: DEAD, user_id: DEAD, role: 'TOWN' }],
    teamMessages: [], known: [], reads: {}, trackResults: {}, watchResults: {},
    lastProtected: null, saidThisPhase: true, saidToTeamThisPhase: true,
    votedThisPhase: false, actedThisNight: false, investigatedThisNight: false,
    memory: { voteLog }, nameOf: (id) => 'p' + id,
  });
  const FULL    = { 2: { 1: 4, 2: 4, 3: 4, 5: 1, 6: 1, 7: 4 } };
  const PARTIAL = { 2: { 1: 4, 2: 4, 3: 4, 5: 1 } };
  const EARLY   = { 2: { 1: 4, 2: 4 } };

  const picks = [];
  for (let me = 1; me <= N; me++) {
    if (me === DEAD) continue;
    const log = me % 3 === 0 ? EARLY : me % 3 === 1 ? PARTIAL : FULL;
    picks.push(await STRATEGIES.deducing.dayVote(mk(me, log)));
  }
  const tally = {};
  for (const p of picks) tally[p] = (tally[p] || 0) + 1;
  const largest = Math.max(...Object.values(tally));
  const majority = Math.floor((N - 1) / 2) + 1;
  console.log(`  staggered vote memories: largest bloc ${largest}/${N - 1} ` +
              `(majority ${majority}) ${largest >= majority ? 'ok' : 'FAILED'}  picks=[${picks}]`);
  assert.ok(largest >= majority,
    'seats with differently-staggered vote memories no longer agree: the shared ' +
    'ordering has picked up a seat-private input again');
}

// The target must not be the same seat every day, or the bots are a fixed
// firing squad and the first player in the list is unplayable.
const targets = new Set();
for (const day of [1, 2, 3, 4, 5]) targets.add((await blocFor(STRATEGIES.deducing, 7, day)).picks[0]);
console.log(`  target rotates across days: ${targets.size} distinct over 5 days`);
assert.ok(targets.size > 1, 'the day-one target never changes: every game lynches the same seat');

assert.strictEqual(failures, 0, `${failures} configuration(s) failed to reach a lynching majority`);
console.log('strategy_convergence: ok');
