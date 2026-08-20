# Running Scumhouse from your own machine

Everything else in this project protects you from an operator who reads the database.
It cannot protect you from one who *rewrites the code*, because the page holding your
keys came from them.

The committed hash manifest catches a change served to **everyone**. It does not catch a
change served to **you** — the operator generates the `integrity` attribute as well as
the file, so they could hand good code to anyone checking and backdoored code to one
player, and no monitor would notice. Loading the client from your own clone closes
exactly that hole.

## Use it

```sh
git clone https://github.com/kkoechel/scumhouse.git
cd scumhouse
python3 -m http.server 8787
```

Open <http://localhost:8787/client/>, then enter:

Serve the **repository root**, not `client/`. The client loads the shared crypto from
`../public/js/`, and a static server will not serve above the directory you point it at.

- **Server** — the Scumhouse install you play on, e.g. `https://example.com/scumhouse`
- **API token** — created on that server's **Account** page, revocable there at any time

Any static server works; the client is plain HTML and needs no build step. `file://`
will not work, because browsers treat it as an opaque origin and block the API calls.

## What this does and does not change

**Closes:** the operator cannot serve you a different implementation from everyone
else's. The scripts come from your checkout, which you can hash against the repository.

**Unchanged:** the server still sees your IP, still knows which account you are, and
still learns everything the protocol says it learns. This is not anonymity — it is
assurance about *which code* is holding your keys. See PROTOCOL.md §7 and §8.

**Worth knowing:** your card lives in browser storage, which is per-origin. A local
client starts with no identity even if you have one on the hosted site. Move it across
with the recovery code shown on the game screen, or use the local client from the start
of a game.

Nothing on the page is fetched from the game server except the API calls the client
makes with your token — no fonts, no scripts, no analytics.
