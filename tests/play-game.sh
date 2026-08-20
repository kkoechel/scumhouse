#!/usr/bin/env bash
# Plays a whole game with bots, start to finish, against a throwaway instance.
#
# This is the test nothing else covers. Every other suite checks a piece: the
# crypto agrees across languages, the endpoints refuse what they should, the
# engine resolves a night correctly. None of them answer the only question that
# matters -- can a table of players actually complete a game? -- because until
# there was a headless client, nothing could play one.
#
# It exercises the parts that only appear in a real game: the blind-credential
# dance for every seat, the deal, envelopes and escrow, cover traffic, the
# two-stage night retrieval across the release point, night resolution, deaths,
# flips, and the win condition.
#
#   tests/play-game.sh [seats]        default 5
#
# SH_STRATEGY picks the decision layer every seat runs (default: deducing). It is
# passed explicitly rather than left to run.mjs's default so that editing that
# default mid-batch cannot silently change what a run was measuring.
#
# Needs php, node, mysql, and an account that can create its own test database.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SEATS="${1:-5}"
PORT="${SH_PLAY_PORT:-8299}"
DB="${SH_TEST_DB:-scumhouse_play_test}"
DBH="${SH_TEST_DB_HOST:-127.0.0.1}"
DBU="${SH_TEST_DB_USER:-root}"
DBP="${SH_TEST_DB_PASS:-}"
WORK="${TMPDIR:-/tmp}/scumhouse-play"

case "$DB" in *test*) ;; *) echo "refusing: SH_TEST_DB ('$DB') must contain 'test' -- this drops it" >&2; exit 2;; esac

MYSQL=(mysql "-h$DBH" "-u$DBU")
[ -n "$DBP" ] && MYSQL+=("-p$DBP")

rm -rf "$WORK"; mkdir -p "$WORK"
TREE="$WORK/tree"
cp -r "$ROOT" "$TREE"
rm -rf "$TREE/.git"

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"${MYSQL[@]}" "$DB" < "$ROOT/schema.sql"

cat > "$TREE/inc/config.php" <<EOF
<?php
return [
  'db' => ['host' => '$DBH', 'name' => '$DB', 'user' => '$DBU', 'pass' => '$DBP'],
  'admin_emails' => ['admin@example.com'],
  'session_name' => 'sh_play', 'base_url' => 'http://127.0.0.1:$PORT',
  'resend_api_key' => 'unused', 'resend_from' => 'x <x@example.com>',
  'allowlist' => ['mode' => 'local', 'portal_db' => null],
  'sso_secret' => null, 'sso_cookie' => 'portal_sso',
];
EOF

php -S "127.0.0.1:$PORT" -t "$TREE/public" >"$WORK/server.log" 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null || true' EXIT
for _ in $(seq 1 40); do sleep 0.2; curl -s -o /dev/null "http://127.0.0.1:$PORT/rules.php" && break; done

php "$TREE/tests/seed-game.php" "$SEATS" "$WORK/seats.json"
GAME=$(python3 -c "import json;print(json.load(open('$WORK/seats.json'))['game'])")

run_all() {
  python3 -c "
import json
d=json.load(open('$WORK/seats.json'))
for s in d['seats']: print(s['name'], s['token'])
" | while read -r name tok; do
    node "$ROOT/bot/run.mjs" --base "http://127.0.0.1:$PORT" --token "$tok" \
      --game "$GAME" --state "$WORK/$name.json" --strategy "${SH_STRATEGY:-deducing}" \
      --once --quiet 2>&1 \
      | sed "s/^/    $name: /" || echo "    $name: PASS FAILED"
  done
}

echo
echo "=== playing ==="
for round in $(seq 1 60); do
  run_all                                        # act on the current phase
  php "$TREE/tests/tick-game.php" "$GAME" release >/dev/null   # open the night's answers
  run_all                                        # collect answers, act on them
  state=$(php "$TREE/tests/tick-game.php" "$GAME" phase)       # end the phase
  printf "  round %-2s %s\n" "$round" "$state"
  case "$state" in
    finished*) echo; echo "play-game: completed with $(echo "$state" | grep -o 'winner=[A-Z]*')"; exit 0;;
    *PENDING_FLIP*) run_all;;                    # let escrow shares open it
  esac
done

echo "play-game: NO WINNER after 60 rounds" >&2
exit 1
