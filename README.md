# Scumhouse

Play-by-post Mafia for 5-10 players, with one unusual property:

> **Whoever runs the server cannot read the mafia's private channel, and cannot tell
> which accounts hold which roles.**

Not "does not" — *cannot*. No row anywhere joins a player to a role until that player
dies and their own browser publishes the link.

[**PROTOCOL.md**](PROTOCOL.md) is the normative spec for how, and — just as importantly —
what it does not protect against. If you read one file here, read that one.

## Is that actually true?

Partly, and the document is specific about which parts.

**It holds against an operator who reads the database.** Roles are dealt to anonymous
identities each browser generates for itself. The server issues one blind-signed
credential per player — it signs a value it cannot read — which is redeemed over a
request carrying no session. The server knows a mafia card went to "slot 3"; it has
nothing that says slot 3 is you. The mafia's channel key is a Diffie-Hellman secret
between two private keys that were generated in two browsers and never left them.

**It does not hold against an operator who rewrites the code** — unless you stop them
delivering it. The hash manifest catches a build swapped for *everyone*, but not one
swapped for *you*: the operator generates the `integrity` attribute too. So there is a
[local client](client/) — the same game, loaded from your own clone, authenticating with
a revocable API token. The server then never delivers the code that holds your keys.
Section 8 covers both halves honestly.

**Your IP is still your IP.** Anonymous requests travel over a network. Section 7 says
exactly what is and is not done about that.

It has **not been independently audited**. See [SECURITY.md](SECURITY.md).

## How it plays

Ordinary forum mafia. Days run 24-72 hours — everyone argues in the town square and votes
to lynch, a strict majority ends the day early, a tie at the deadline lynches nobody.
Nights run 12-48 hours.

Roles are **Mafia / Cop / Doctor / Vigilante / Roleblocker / Tracker / Watcher / Town**.

The investigative roles are the interesting part, and PROTOCOL.md §5.2-5.5 is where to
look. Three times during design a role was declared impossible here and three times that
was wrong. The rule that finally held:

> Anything phrased as *one player asking about one named player, on a budget* can be
> built. Anything phrased as *the rules quietly checking what someone is* cannot.

## Requirements

- PHP 8.3 with `ext-openssl`
- MySQL 8 or MariaDB
- nginx (or any web server that can run PHP)

**No Composer, no npm, no dependencies at all.** The client crypto is WebCrypto plus one
`BigInt` routine for RSA blinding; the server crypto is `ext-openssl` and nothing else.
For a project asking you to trust its cryptography, a dependency tree you would also have
to trust seemed like the wrong trade.

## Install

```sh
git clone https://github.com/kkoechel/scumhouse.git
cd scumhouse

mysql -e "CREATE DATABASE scumhouse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER 'scumhouse'@'localhost' IDENTIFIED BY 'a-real-password';"
mysql -e "GRANT ALL PRIVILEGES ON scumhouse.* TO 'scumhouse'@'localhost';"
mysql scumhouse < schema.sql

cp inc/config.example.php inc/config.php
$EDITOR inc/config.php          # db credentials, admin_emails, resend key
```

Then serve `public/` — see [`deploy/nginx.conf.example`](deploy/nginx.conf.example), and
**read the comment at the top of it**. The `/anon/` location block is load-bearing: an
install that omits it works perfectly, passes every test, and quietly stops keeping the
promise the whole game is built on.

Sign-in is invite-only and magic-link based (delivered via [Resend](https://resend.com)).
Add the first address to `admin_emails` in the config, then manage everyone else from
`/admin/access.php`.

## Tests

```sh
php tests/simulate.php        # pure rules engine
php tests/lint.php            # undefined functions, stale prefixes
php tests/privacy_check.php   # the privacy invariants, enforced mechanically
tools/integrity.sh --check    # the committed script hashes are current
tests/run_interop.sh          # the real client crypto against the real server crypto
```

The last one is the one that matters. Every value in this protocol crosses a language
boundary — blinded in the browser and signed in PHP, sealed in PHP and opened in the
browser — so testing either half alone proves nothing. It drives both implementations
against each other through every step: blind-signature issue and verify, dealing a card,
authorising a night action with no session, deriving the team key from both sides, the
two-lock envelope, the batch release, and the reverse envelope.

`tests/privacy_check.php` deserves a mention too. It guards the mistakes that would
un-anonymise the game *without breaking a single feature* — a session started in an
anonymous endpoint, a client IP read, a second network call added to the file that holds
the keys. Those checks were themselves verified by injecting each violation and
confirming they fail.

## Layout

    inc/anon.php        server crypto -- blind signatures, ECIES, P-256 verification
    inc/engine.php      pure rules: setups, night resolution, tallies, win conditions
    inc/game_state.php  database + the lazy phase clock (no cron needed)
    public/anon/        the unauthenticated endpoints -- read _boot.php first
    public/api/         the ordinary logged-in endpoints
    public/js/crypto.js client crypto -- the only file that ever sees a private key
    tools/integrity.sh  regenerates the committed script-hash manifest
    tools/prune.php     retention for finished games (dry run by default)
    client/             the locally-run client -- read client/README.md first
    extension/          browser-extension packaging of that same client

The game screen renders **client-side**, unlike the rest of the app. It has to: the server
cannot render a card it is not allowed to know.

## Storage

About **1.8 MB per finished 10-player game**, three quarters of it the mandatory cover
traffic that gives real mafia chat somewhere to hide. `tools/prune.php` drops the
ephemeral tables for games finished more than 30 days ago; the day thread, votes, deaths
and flips are the permanent record and are never touched.

## Two ways to run it without trusting the server's code

The hash manifest catches a build swapped for *everyone*, but not one swapped for *you* —
the operator generates the `integrity` attribute too. Both of these close that by not
letting the server deliver the code at all:

- **[Local client](client/)** — run it from your own clone. Nothing to trust but the
  repository you can hash yourself.
- **[Browser extension](extension/)** — the same client, store-delivered and
  self-updating, for people who will not clone a repository. Built and testable now;
  **not yet submitted to either store.**

Both authenticate with a revocable API token instead of a session cookie, so the API
never accepts ambient credentials cross-origin. See §8.1.

## Roadmap

- **Publish the extension** to the Chrome and Firefox stores — needs a developer account,
  review, and a commitment to an update channel.
- **Play a game.** Everything here is verified at unit, crypto-interop, endpoint and
  browser level. Five people arguing for forty-eight hours is a different test.

## Licence

[AGPL-3.0](LICENSE). Chosen deliberately rather than by habit: the AGPL requires anyone
running a *modified* copy as a network service to publish their changes, which is the same
argument §8 makes about backdoors having to be published. A permissive licence would let
someone fork this, quietly backdoor the crypto, and run it as a service — undercutting the
one claim the design leans on hardest.

Mafia is a public-domain party game (Dimitry Davidoff, 1986). This is an original
implementation with an original name and is not affiliated with any commercial edition.
