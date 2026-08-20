#!/usr/bin/env bash
# Assembles the browser extension into dist/extension/.
#
# The package MIRRORS THE REPOSITORY LAYOUT on purpose: client/ and public/ keep
# their paths, so every shared file is copied byte-identical and nothing is
# rewritten on the way in. That matters beyond tidiness -- anyone can unzip a
# published build and diff it against this repository, which is the same argument
# the hash manifest makes for the hosted client (PROTOCOL.md sec 8).
#
# The build verifies that byte-identity rather than assuming it.
#
#   tools/build-extension.sh          build dist/extension/
#   tools/build-extension.sh --zip    and package it for upload
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/dist/extension"

SHARED=(client/index.html client/local.js public/js/crypto.js public/js/game.js public/css/style.css)

rm -rf "$OUT"
mkdir -p "$OUT"

cp "$ROOT/extension/manifest.json" "$OUT/"
cp "$ROOT/extension/popup.html" "$OUT/"
cp -r "$ROOT/extension/icons" "$OUT/"

for f in "${SHARED[@]}"; do
  mkdir -p "$OUT/$(dirname "$f")"
  cp "$ROOT/$f" "$OUT/$f"
done

# Every shared file must be identical to the one in the repository. If this ever
# fails, the build has started transforming code that players are told they can
# verify against the source.
fail=0
for f in "${SHARED[@]}"; do
  if ! cmp -s "$ROOT/$f" "$OUT/$f"; then
    echo "MISMATCH: $f differs from the repository copy" >&2
    fail=1
  fi
done
[ $fail -eq 0 ] || exit 1

# The extension must be self-contained: a store build that fetches code at runtime
# defeats the entire point, and is also grounds for review rejection.
if grep -rnE 'src="https?://|href="https?://[^"]*\.(js|css)' "$OUT" --include='*.html' >/dev/null 2>&1; then
  echo "ERROR: the package references remote code" >&2
  grep -rnE 'src="https?://|href="https?://[^"]*\.(js|css)' "$OUT" --include='*.html' >&2
  exit 1
fi

echo "built $OUT"
echo "  files:  $(find "$OUT" -type f | wc -l)"
echo "  bytes:  $(du -sb "$OUT" | cut -f1)"
echo "  shared files verified byte-identical to the repository: ${#SHARED[@]}"

if [ "${1:-}" = "--zip" ]; then
  ver=$(python3 -c "import json;print(json.load(open('$ROOT/extension/manifest.json'))['version'])")
  zipf="$ROOT/dist/scumhouse-extension-$ver.zip"
  rm -f "$zipf"
  (cd "$OUT" && zip -qr "$zipf" .)
  echo "  zip:    $zipf ($(du -h "$zipf" | cut -f1))"
fi
