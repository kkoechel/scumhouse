#!/usr/bin/env bash
#
# Fetches the live JavaScript and compares it against the committed manifest.
#
# THIS IS THE ONLY INTEGRITY CHECK THAT MEANS ANYTHING AGAINST THE OPERATOR, and
# only because it is meant to run somewhere the operator does not control -- a
# player's laptop, a cron on a different host, a CI job. Every other check in this
# repo is the server agreeing with itself.
#
#   tools/monitor-integrity.sh [base-url]
#
# Exits non-zero on any mismatch, so it can be dropped into cron with an alert.
set -euo pipefail

BASE="${1:-}"
if [[ -z "$BASE" ]]; then
  echo "usage: $0 <base-url>    e.g. $0 https://example.com/scumhouse" >&2
  exit 2
fi
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MANIFEST="$ROOT/public/integrity.json"

if [[ ! -f "$MANIFEST" ]]; then
  echo "no manifest at $MANIFEST -- run tools/integrity.sh first" >&2
  exit 2
fi

status=0
while IFS=$'\t' read -r file expected; do
  actual="sha384-$(curl -fsSL "$BASE/$file" | openssl dgst -sha384 -binary | openssl base64 -A)"
  if [[ "$actual" == "$expected" ]]; then
    printf 'ok        %s\n' "$file"
  else
    printf 'MISMATCH  %s\n  published: %s\n  live:      %s\n' "$file" "$expected" "$actual"
    status=1
  fi
done < <(python3 -c "
import json
m = json.load(open('$MANIFEST'))['files']
for k, v in m.items():
    print(k + '\t' + v)
")

if [[ $status -eq 0 ]]; then
  echo "integrity: live files match the published manifest"
else
  echo "integrity: LIVE CODE DIFFERS FROM THE PUBLISHED MANIFEST" >&2
fi
exit $status
