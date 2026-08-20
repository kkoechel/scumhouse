#!/usr/bin/env bash
# Drives the JS<->PHP crypto interop test in PROTOCOL.md order.
#
# Every value in this protocol crosses a language boundary -- blinded in the
# browser and signed in PHP, sealed in PHP and opened in the browser -- so
# testing either half alone proves nothing. This runs the real client against
# the real server code, step by step, through a shared state file.
#
# Runs entirely locally when PHP is on PATH. If it is not (some dev machines
# have node but no PHP), set SH_TEST_HOST to an ssh host that does have it and
# the PHP half is shuttled there instead.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE="${SH_STATE_DIR:-${TMPDIR:-/tmp}}/scumhouse-interop/state.json"
mkdir -p "$(dirname "$STATE")"
rm -f "$STATE"

if command -v php >/dev/null 2>&1; then
  MODE=local
  php_step() { php "$ROOT/tests/interop_php.php" "$1"; }
else
  MODE="remote (${SH_TEST_HOST:-unset})"
  if [[ -z "${SH_TEST_HOST:-}" ]]; then
    echo "no local php, and SH_TEST_HOST is not set -- cannot run the PHP half" >&2
    exit 2
  fi
  REMOTE_DIR=/tmp/scumhouse-interop
  ssh "$SH_TEST_HOST" "mkdir -p $REMOTE_DIR"
  scp -q "$ROOT/inc/anon.php" "$ROOT/tests/interop_php.php" "$SH_TEST_HOST:$REMOTE_DIR/"
  php_step() {
    scp -q "$STATE" "$SH_TEST_HOST:$REMOTE_DIR/state.json"
    ssh "$SH_TEST_HOST" "SH_STATE=$REMOTE_DIR/state.json php $REMOTE_DIR/interop_php.php $1"
    scp -q "$SH_TEST_HOST:$REMOTE_DIR/state.json" "$STATE"
  }
fi

js_step() { SH_STATE="$STATE" node "$ROOT/tests/interop_node.mjs" "$1"; }
export SH_STATE="$STATE"

echo "interop mode: $MODE"
echo

js_step  identities   # browser makes both keypairs, nothing leaves it
php_step credkey      # server mints the per-game RSA credential key
js_step  blind        # client blinds its anon_pub
php_step blindsign    # server signs a value it cannot read
js_step  unblind      # client unblinds into a usable credential
php_step verifycred   # server accepts it without ever having seen it
php_step seal         # server seals a MAFIA card to the anon key
js_step  opencard     # only that client can open it
js_step  sign         # client signs a night action from its slot
php_step verifysig    # server authorises it with no session involved
js_step  team         # mafia derive a key the server cannot compute
js_step  recovery     # key backup round-trips, wrong passphrase does not

# The two-lock envelope: an investigative role the server can neither read nor
# rate-limit its way into (PROTOCOL.md sec 5.2).
js_step  envelope      # target seals its slot claim under both locks
js_step  tokenblind    # investigator blinds a retrieval token
php_step tokensign     # server signs it without learning which slot asked
js_step  tokenunblind  # unblind into a spendable token
php_step tokenredeem   # server checks it, seals the inner key to a one-use key
js_step  investigate   # both locks together yield exactly one answer

# The fixed release point: one batch, trial-decrypted, order shuffled.
js_step  batchprep     # three redemptions, one of them ours
php_step batchseal     # server seals every answer to its own one-use key
js_step  batchcollect  # we open exactly ours and learn nothing about the rest

# The reverse envelope: slot -> account, for the watcher.
js_step  reverse       # two signatures replace the attested account label

# Forced flips: the threshold has to be exactly what it claims, or a coalition
# smaller than the whole table could crack a living player's card.
js_step  shamir        # every T-subset reconstructs, every T-1 subset fails

echo
echo "interop: ALL STEPS PASSED"
