#!/usr/bin/env bash
# Starts the local model server that bot/strategy-llm.mjs talks to.
#
# Everything here is deliberately offline. The model file sits on this disk, the
# server binds to loopback, and nothing leaves the machine. That is not a
# preference: a bot holds a dealt card, and a card dealt mafia can read that
# team's channel, so sending this seat's view to somebody else's computer would
# hand them the one thing the whole protocol exists to withhold. See
# bot/README.md.
#
#   tools/llm-server.sh              # start it
#   SH_LLM_MODEL=/path/to.gguf tools/llm-server.sh
#
# Then, in another shell:
#   node bot/run.mjs --base ... --token ... --game N --strategy llm
set -euo pipefail

BIN="${SH_LLM_BIN:-$HOME/.local/src/llama.cpp/build/bin/llama-server}"
MODEL="${SH_LLM_MODEL:-$HOME/.local/share/models/qwen2.5-3b-instruct-q4_k_m.gguf}"
PORT="${SH_LLM_PORT:-8080}"

# llama-server defaults to CORS "*" with no auth. Loopback keeps other machines
# out, but it does NOT keep out a web page you happen to be visiting: that page
# can POST to 127.0.0.1 from your own browser, and allow-all CORS lets it read
# the reply. A key it cannot know closes that. Generated once, kept 0600.
KEYFILE="${SH_LLM_KEYFILE:-$HOME/.local/share/models/.scumhouse-llm-key}"
if [ ! -s "$KEYFILE" ]; then
  mkdir -p "$(dirname "$KEYFILE")"
  head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$KEYFILE"
  chmod 600 "$KEYFILE"
  echo "generated a new local API key at $KEYFILE"
fi
API_KEY="$(cat "$KEYFILE")"

# Threads: this box is 2 physical cores / 4 threads, and llama.cpp gains
# nothing from oversubscribing them.
THREADS="${SH_LLM_THREADS:-4}"

# Context: a mafia prompt is the board plus a slice of transcript, comfortably
# under 4k. Asking for more costs RAM that this machine does not have spare.
CTX="${SH_LLM_CTX:-4096}"

[ -x "$BIN" ]   || { echo "no llama-server at $BIN -- build it first" >&2; exit 1; }
[ -f "$MODEL" ] || { echo "no model at $MODEL" >&2; exit 1; }

echo "model   $(basename "$MODEL") ($(du -h "$MODEL" | cut -f1))"
echo "listen  127.0.0.1:$PORT   threads=$THREADS ctx=$CTX"
echo

# --host 127.0.0.1 is the security boundary; do not widen it.
exec "$BIN" \
  --model "$MODEL" \
  --host 127.0.0.1 --port "$PORT" \
  --threads "$THREADS" \
  --ctx-size "$CTX" \
  --gpu-layers 0 \
  --api-key "$API_KEY" \
  --no-webui \
  --no-warmup
