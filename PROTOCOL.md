# Scumhouse cryptographic protocol (v1)

Scumhouse is play-by-post Mafia. Its one unusual property:

> **The server operator cannot read mafia chat, and cannot tell which player accounts
> hold which roles.**

This document is the normative spec. `public/js/crypto.js` implements the client half;
`inc/anon.php` implements the server half. If the two ever disagree with this file,
this file is wrong and should be fixed -- but say so loudly, because a silent drift here
is a privacy bug, not a rendering bug.

---

## 1. Threat model

**Defended against:** an *honest-but-curious* operator. Someone with full `SELECT` on the
game database, full read of `state_json`, and the ability to read every stored row and
every message blob. That is the realistic threat: whoever runs the server idly looking
at tables, or a database backup leaking.

**NOT defended against:**

1. **A malicious operator who serves backdoored JavaScript.** We ship the client. Anyone
   who can change `public/js/crypto.js` can ship a version that POSTs private keys home.
   This is the hard ceiling on browser-delivered E2E and no amount of protocol design
   removes it. Partial mitigation in §8.
2. **IP-address correlation.** Anonymous submissions arrive over HTTP from a real IP. If
   that IP also carries an authenticated, cookie-bearing request from account `alice`,
   the two are trivially linked. Mitigation in §7 -- but this is the floor, and it is the
   single most important caveat in this document.
3. **Collusion.** N-1 players working together can always deduce the last player's role.
   Inherent to the game, not to the crypto.
4. **Traffic timing at the network layer.** We batch and pad (§6), but a passive observer
   with packet-level capture on the host will do better than the database can.

---

## 2. Primitives

All WebCrypto, no third-party crypto libraries.

| Purpose | Primitive |
| --- | --- |
| Anonymous identity / key agreement | ECDH P-256 |
| Anonymous authorship proof | ECDSA P-256 (SHA-256) |
| Message confidentiality | AES-256-GCM |
| Key derivation | HKDF-SHA256 |
| One-identity-per-player | RSA-2048 blind signatures (FDH, MGF1-SHA256) |

RSA blinding is the only piece needing bignum arithmetic; it uses native `BigInt`, not a
library. Everything else is `crypto.subtle`.

---

## 3. Phase 0 -- Setup

The lobby fills with N players (5-9). The **role composition is public** and printed in
the lobby before anyone commits:

| N | Mafia | Cop | Doctor | Vigilante | Roleblocker | Tracker | Watcher | Town |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 5 | 1 | 1 | 0 | 0 | 0 | 0 | 0 | 3 |
| 6 | 1 | 1 | 1 | 0 | 0 | 0 | 0 | 3 |
| 7 | 2 | 1 | 1 | 0 | 0 | 0 | 0 | 3 |
| 8 | 2 | 1 | 1 | 1 | 0 | 0 | 0 | 3 |
| 9 | 3 | 1 | 1 | 0 | 1 | 0 | 0 | 3 |
| 10 | 3 | 1 | 1 | 0 | 0 | 1 | 1 | 3 |

Publishing the composition costs nothing -- everyone knows it in a normal game too --
and it is what makes several later integrity checks possible (§5, §9).

The server generates a per-game RSA-2048 keypair (the **credential key**) and publishes
`(n, e)`. The private exponent never leaves the server; it is used only to blind-sign.

---

## 4. Phase 1 -- Anonymous identity registration

This is the step that severs account from role. Nothing later can repair it if this step
leaks, and nothing later can break it if this step holds.

Each player's browser:

1. Generates two keypairs locally and stores the private halves in `localStorage`:
   - `idk` -- ECDH P-256, used to receive the sealed card and to agree the team key
   - `sigk` -- ECDSA P-256, used to sign anonymous posts and night actions
2. Forms `anon_pub = {idk_pub, sigk_pub}` (canonical JSON, raw-uncompressed points) and
   `h = FDH(anon_pub, n)`.
3. Picks a random blinding factor `r`, coprime to `n`, and sends
   `h' = h · r^e mod n` to `POST /api/blind-sign.php` **with the session cookie**.
   The server signs `s' = (h')^d mod n` and marks this account's credential as spent --
   one per player per game. `h'` is uniformly random to the server; it learns nothing
   about `h`.
4. Unblinds: `s = s' · r^-1 mod n`. Now `s` is a valid RSA-FDH signature over `anon_pub`
   that the server has never seen and cannot link to step 3.
5. Sends `{anon_pub, s}` to `POST /anon/register.php` **with no session cookie, no
   Referer, and no CSRF token** -- see §7 for how that request is kept clean.

The server verifies `s`, rejects duplicates, and stores the identity. When N distinct
identities are registered, registration closes and they are published in canonical order
(sorted by `SHA-256(anon_pub)`) as **anon slots** `#0 … #N-1`.

**What the server now knows:** N anonymous public keys, and that each of the N accounts
obtained exactly one credential. **What it does not know:** which account owns which slot.

---

## 5. Phase 2 -- Dealing

1. The server shuffles the role list for the setup with a CSPRNG.
2. For each slot `i` it builds a card:
   - Mafia: `{role: "MAFIA", slot: i, team: [j, k, …]}` -- the anon *slot indices* of the
     other mafia, not accounts, because the server does not know the accounts.
   - Others: `{role: "COP" | "DOCTOR" | "TOWN", slot: i}`
3. It seals card `i` to slot `i`'s `idk_pub` by ECIES (ephemeral ECDH → HKDF → AES-GCM)
   and publishes all N sealed cards. Each client decrypts exactly the one addressed to
   its own key.

**The server knows the full slot→role map.** That is deliberate and necessary: it is what
lets the server validate night actions in §6 without a human moderator. It is harmless,
because slot→account is exactly what it does not have.

### 5.1 The team key

The mafia's shared key is **never generated by, transmitted to, or derivable by the
server**. For mafia slots `j` and `k`:

    K_jk = HKDF(ECDH(idk_j, idk_k), "scumhouse/team/v1")

Each side computes it from its own private key and the other's published public key. The
server holds neither private key and cannot compute it.

With three mafia there is no group-key negotiation: a sender simply posts one copy of the
message per teammate, each under the relevant pairwise key. At these table sizes that is
two extra blobs, which is cheaper in both code and failure modes than agreeing a shared
key over an asynchronous channel where a teammate may not appear for a day.

This is the crux of the whole design. The server composed the deck, so it knows *that*
slot 3 is mafia -- but the key protecting their conversation is a Diffie-Hellman secret
between two private keys that were generated in browsers and never left them.

---

### 5.2 Investigative roles: the two-lock envelope

An earlier draft of this document claimed investigative roles were impossible here. That was
wrong, and the way it was wrong is worth recording, because it points straight at the fix.

The obstacle was never *answering* the cop. It is **rate-limiting** the cop. Getting one
player's alignment to the investigator is easy; stopping the investigator from getting
*everyone's* requires somebody to withhold something, and knowing what to withhold is what
re-linked account to slot. So the design splits that into two locks held by two parties who
never combine.

#### The envelope

After the deal, the server publishes which **slot indices** hold investigative roles. That
discloses nothing about people -- it says "slot 4 investigates", not who slot 4 is -- and it
is what stops every slot from being able to open envelopes.

Each player `X` then publishes, for each investigator slot `v`:

    envelope[X][v] = ECIES(idk_v,  AES-GCM(K_X,  {account: X, slot: s, sig_s}))

- The **outer lock** is the investigator's public key. Only slot `v` can strip it. The
  server cannot, and never will -- it has no slot's private key.
- The **inner lock** is `K_X`, a random key `X` generates and hands to the server. That is
  safe precisely because the server can never get past the outer lock to use it.
- `sig_s` is a signature by slot `s`'s key over the payload. `X` cannot claim a slot whose
  private key they do not hold, so **`X` cannot lie**.

The envelope is posted from an ordinary logged-in request and labelled with `X`'s account.
None of that matters: it is opaque to everyone except a role nobody can identify.

#### Retrieval without telling the server who was asked about

Each night every slot draws **one blind-signed retrieval token** -- the same RSA-FDH
construction as section 4, and the reason that machinery is worth having twice. The token is
redeemed unauthenticated:

    POST /anon/redeem.php  {token, target_account: X, ephemeral_pub: Q}
    -> ECIES(Q, K_X)

Every living client spends its token every night: on a real target if it has one, on a random
living player if it does not. So the server sees N redemptions naming N accounts, each
answering to a fresh ephemeral key, and **cannot tell which slot asked which question** --
nor even which of the N was the investigator's. The answer is sealed to `Q` rather than
published, so a dead investigator who later hands out their private key exposes only the
envelopes they actually opened, not the whole table.

#### What each role gets

- **Cop.** At deal time the server seals the full `slot -> role` table to the cop's `idk_pub`.
  That table is inert on its own -- it names slots, not people. Combined with one envelope per
  night it yields exactly one player's alignment per night. The cop submits no night action at
  all, so the cop leaks *nothing* to the server.
- **Roleblocker.** Gets envelopes but **not** the sealed role table, so an opened envelope
  tells them `X` holds slot `s` and nothing more. They then submit `{block_slot: s}`, and the
  server cancels that slot's action. The server learns which slot was blocked and has no idea
  which account that was, because the lookup was anonymous.
- **Tracker.** Also no role table. An opened envelope gives them slot `s`; they then ask
  `{target_slot: s}` and at dawn learn who that slot targeted.

  The tracker is worth dwelling on, because it looks impossible and is not. "Who did `X`
  visit?" is two questions -- *which slot is `X`*, and *what did slot `s` target* -- and the
  server has always known the second one. It holds every `(slot, action, target)` triple; it
  has to, or it could not resolve a night at all. A query naming a slot index therefore
  reveals nothing it did not already have, and the account-to-slot half never reaches it.

  (An earlier draft said a watcher was the thing that could not exist. Section 5.4 is that
  claim being wrong too, and section 5.5 is the general rule I should have found first.)

If the roleblocker had the role table they could simply block a known mafia slot every night
without ever identifying anyone, which is why they do not get it. The asymmetry is load-bearing,
not an oversight.

#### What this still costs

- The server sees the *set* of accounts asked about each night, N of them, one of which was a
  real question. It cannot tell which. Over a long game a blocked slot is known to correspond
  to *some* account from the union of those sets, which narrows slowly and never resolves.
- A target who hands the server a bogus `K_X` breaks their own investigation. Nothing detects
  it server-side, because the server cannot open the envelope to check. It is also the most
  suspicious thing a player can do.
- See section 5.5 for what is actually out, stated as a rule rather than a list.

### 5.3 The fixed release point

An earlier version of this document admitted a timing signal and left it there: every client
spends a retrieval token each night, but an investigator *deliberates* while everybody else
spends on autopilot, so the order in which questions arrived was itself the tell. Cover
traffic that all arrives at once is cover; cover traffic with a conspicuous straggler is not.

So retrieval is no longer request/response:

1. Redemptions are accepted from the start of the night until **the halfway mark**
   (`games.key_release_at`, set when the night begins). The endpoint queues the question and
   returns nothing.
2. At that moment every answer for the night becomes readable, **together**, from
   `answers.php` -- which hands back the entire batch to anyone who asks. Each answer is
   sealed to the one-use key its asker supplied, so a client finds its own by trial
   decryption and fetching the batch says nothing about which one that is.
3. The second half of the night is left for anyone who has to *act* on what they learned --
   the roleblocker submitting a block, the tracker submitting a follow.

Two supporting details that are easy to get wrong:

- **`spent_tokens` has no timestamp column.** Batching the answers is pointless if the
  redemptions themselves are stored in a totally ordered table; the ordering *is* the signal.
  What the table holds is an unordered set per night, which is all resolution needs.
- **`answers.php` shuffles.** MySQL will happily return rows in insertion order, which would
  reconstruct exactly what the missing timestamp was meant to destroy.

One thing the application *can* do about the network layer, and now does: every automatic
submission is delayed by a random interval of up to four minutes rather than fired the
instant a page loads. Cover traffic that all arrives together is not cover -- the player
who stops to think stands out by arriving late. Spreading the automatic ones out means
arrival order no longer tracks decision order.

What remains after that: a passive observer at the network layer still sees requests arrive
individually, and correlating them with a login session is not something the application can
prevent. That is the same floor as the IP address (section 7). The database, the backups and
the logs no longer carry it.

The cost is a real usability constraint, stated plainly rather than hidden: **to investigate,
you must open the game during the first half of the night.** A player who arrives later
contributes no cover traffic either, which slightly thins the crowd their neighbours hide in.

### 5.4 The reverse envelope, for the watcher

A watcher asks "who visited `X` last night?" The server can answer *half* of that
immediately -- actions name their target account in the clear, so it knows slots 3 and 7
targeted `X`. What it cannot do is put names to those slots.

The forward envelope is no help, because it is indexed the wrong way. It is stored per
account, and picking out "the envelope whose slot is 3" means already knowing the mapping.
So there is a second envelope, indexed by **slot**:

    reverse[s] = ECIES(idk_watcher,  AES-GCM(K'_s,  {slot: s, account: X, slot_sig, acct_sig}))

Same two locks as before. What changes is publication. This row is labelled by slot, so it
**must** be posted anonymously and signed by that slot -- a logged-in POST of a slot-labelled
row would be precisely the leak everything else avoids. And that costs something: the forward
envelope gets a server-attested account label for free, because the account posted it while
logged in. The reverse one cannot.

That gap is closed by `account_keys`: a signing key each player publishes **while logged in**,
so the server attests the account-to-key binding publicly. It names no slot, so publishing it
authenticated is safe. The reverse payload is then signed twice over the same claim -- once by
the slot key, once by that account's key -- and a watcher who verifies both knows one person
holds both.

Retrieval needs no token. At dawn the server releases `K'_s` for exactly the slots that
visited the watched target, and nothing else. The rate limit is the answer itself.

#### The one attack this admits

Signing is delegable, so two **colluding** players can split the pair: one signs with their
slot key, the other with their account key, producing a claim that "slot 3 is bob" when slot 3
is really alice. The forward direction is immune to this, because there the account label is
server-attested rather than signed.

It matters much less than it looks:

- A liar needs the private key of the account they claim, so they can only borrow the identity
  of someone who *helps them*. Townies do not. In practice this lets mafia attribute a visit to
  a fellow mafia -- shuffling blame within a set that is uniformly guilty anyway.
- It is detectable. Every reverse envelope should map a distinct account, so a watcher who
  ever attributes one account to two different slots has caught the lie.
- Refusing to publish a reverse envelope at all is worse: the watcher's report then names an
  unidentifiable visitor at slot `s`, which is its own accusation.

Stated rather than hidden, because it is a real asymmetry between the two directions and
someone reading this later should not have to rediscover it.

### 5.5 What actually cannot be built

Three times in this document I have declared a role impossible and been wrong -- the cop, the
tracker, and the watcher. The pattern in the mistakes is more useful than the corrections, so
here is the rule instead of a fourth guess.

Every one of those roles turned out to be a **question one player asks about one named player,
answered on a budget**. That shape always works, because it decomposes into two halves the
protocol already has: bridging account-to-slot (an envelope, either direction) and answering
about a slot (which the server can always do, since it resolves the night in slot terms).

What does *not* work is the other shape: **the game engine itself needing to know a living
player's role.** A rule like "the mafia kill fails if it targets the vigilante" has nobody
asking anything. The server would have to evaluate account-to-role, for everyone, at resolution
time, every night, with no investigation budget to pay for it -- and the only way to do that is
to hold the map.

So: if a mechanic can be phrased as *somebody asks about somebody*, it can be built here. If it
has to be phrased as *the rules quietly check what someone is*, it cannot.

## 6. Phase 3 -- The day/night loop

### Day thread (public, attributed)
Ordinary forum posts and ordinary votes, tied to real accounts. No crypto. This is the
game; hiding it would defeat the point.

### Anonymous channel (public, unattributed)
An append-only log. Each entry is `{slot, ciphertext, sig}`, signed by that slot's `sigk`
and posted unauthenticated. Every entry is exactly 508 bytes before base64 --
`iv(12)` + `AES-GCM(480-byte plaintext)` + `tag(16)` -- regardless of message length.
Longer messages are split across several entries sharing a random 8-byte message id in
the chunk header; readers reassemble by id. Padding is random bytes, not zeroes, so the
true length does not leak even to someone who later recovers the key.

Every client downloads the whole log and trial-decrypts each entry with every key it
holds. GCM's auth tag is the "is this for me" test.

**What cover traffic is and is not for.** It is tempting to demand that every player emit
an identical *volume* of traffic each phase. That buys less than it looks like, and the
reason is worth writing down: a slot is already unlinkable to an account (section 4), so
"slot #3 sent forty entries and slot #6 sent none" tells an observer only that *some
anonymous slot* is chatty. It never names a person. Volume equality is also unenforceable
in an asynchronous forum game, where a player may simply not open the site during a
48-hour phase -- and an unenforceable rule that looks like a guarantee is worse than an
honest weaker one.

So the rule is the achievable one: **on first load of a phase, every living client posts
a small batch of cover entries** (`COVER_BLOBS = 4`), so no slot is ever conspicuously
silent, and mafia clients then post freely on top of that. What protects the player is
that the request carries no session -- not that it is one of a fixed number.

### Night actions
`POST /anon/action.php` with `{slot, night, action, target_account_id, sig}`,
unauthenticated. The server verifies the signature against slot `i`'s `sigk_pub`, checks
that slot `i`'s role permits `action`, and enforces one action per slot per night.

Every night result in v1 is public -- who died, and who conspicuously did not -- and is
announced against real account names. There are no private night results, because there
are no roles that produce them (section 5.2).

The mafia coordinate their kill in their own channel and any one of them submits it;
last valid submission before the deadline wins.

---

## 7. Keeping the anonymous endpoints clean

Everything under `public/anon/` must be, and is asserted by `tests/privacy_check.php` to
be:

- served by an nginx location with `access_log off;`
- never reading `$_SERVER['REMOTE_ADDR']`, `HTTP_X_FORWARDED_FOR`, `HTTP_USER_AGENT`, or
  `HTTP_REFERER`
- never calling `session_init()` or touching `$_COOKIE`
- fetched by the client with `credentials: 'omit'` and `referrerPolicy: 'no-referrer'`

Submissions are additionally **held until the phase deadline** and only then made visible,
so posting order carries no information.

None of this defeats an operator who decides to turn packet capture on. It defeats the
database, the backups, and the access logs -- which is the stated threat model. A player
who wants more should use a VPN or mobile data, and the rules page says so plainly.

---

## 8. Client integrity

Everything in this document defends against an operator who *reads* the database. None of it
defends against one who *rewrites the code*, because the page that holds your keys was served
by that operator. This section is about making that attack loud rather than impossible,
because impossible is not on the menu for browser-delivered cryptography.

### The manifest is committed, not computed

`public/integrity.json` maps each key-handling script to its SHA-384, and
`inc/render.php` writes those hashes into every page as `integrity="sha384-..."`.

The important word is **committed**. A server that hashes the file it is about to serve
always agrees with itself; such a check proves nothing and is worse than nothing, because it
looks like a guarantee. Reading a manifest that lives in git means:

- tampering with the deployed JavaScript alone makes the browser refuse to run it;
- tampering with the manifest as well means editing a tracked file whose history is public.

`tools/integrity.sh` generates and checks it. `tests/privacy_check.php` fails the build if it
goes stale, so nobody is ever tempted to "fix" a mismatch by dropping the attribute.

### The check that actually counts

`tools/monitor-integrity.sh` fetches the live files, hashes them, and compares against the
repository copy. It is the only integrity check here that means anything against the
operator, **and only because it is meant to run somewhere the operator does not control** --
a player's laptop, a cron on another host, a CI job. Run from the game server it would be
just another instance of the server agreeing with itself.

Since the source is public, that check is something a *player* can run, not just an
operator. Against the reference instance:

    git clone https://github.com/kkoechel/scumhouse.git
    cd scumhouse
    tools/monitor-integrity.sh https://the-instance-you-play-on/scumhouse

A mismatch means the JavaScript being served is not the JavaScript published here. This is
the practical difference the repository being public makes: without it, "the hash is
committed" is a claim only the operator can evaluate.

`public/verify.php` shows players the comparison and says plainly which of the two things it
is doing.

### What is still true

An operator can serve different bytes to different visitors. The manifest does not stop
this, and it is worth being precise about why: **the operator generates the `integrity`
attribute as well as the file.** They can serve the published crypto to everyone who
checks and a modified build to one player, and no monitor anywhere would see a
discrepancy. A global swap is loud; a targeted one is silent.

That is not fixable by hashing code the operator delivers. It is only fixable by not
letting them deliver it.

## 8.1 The locally-run client

`client/index.html` is the same game, loaded from a clone the player made themselves.
It authenticates with a revocable API token instead of a session cookie, so it can run
from `http://localhost` without the API ever accepting ambient credentials.

What that closes: the server cannot hand *this* player a different implementation,
because it does not deliver the implementation at all. It sees only API calls carrying
ciphertext and signed blobs. A player who wants certainty can hash their checkout against
the repository and know exactly what is holding their keys.

Two consequences worth stating rather than discovering:

- **CORS is `Access-Control-Allow-Origin: *` with no `Allow-Credentials`, deliberately.**
  The wildcard is safe *only* because of that omission: without it a browser will not
  attach cookies to a cross-origin request, so there are no ambient credentials for a
  hostile page to ride on. A local client must present a bearer token, which it can only
  have because its owner pasted it in. Adding `Allow-Credentials` alongside a reflected
  origin would hand every site on the internet the ability to act as a logged-in player.
- **Browser storage is per-origin, so a local client starts with no identity.** A player
  who registered on the hosted site must move theirs across with the recovery code, or
  use the local client from the start of a game. This is a property of the browser, not
  a bug, but it surprises people.

What it does *not* change: the server still sees the player's IP and still knows which
account they are. This is assurance about **which code holds the keys**, not anonymity.
Sections 5.3 and 7 are unchanged.

A store-delivered browser extension would go further -- it removes the clone step and
updates itself -- and remains on the roadmap. The client here needs no store, no signing,
no review and no update channel, which is why it came first.

## 9. Death, flips, and win conditions

When account `X` dies, `X`'s client automatically publishes `{slot, card, sig}`, which
links `X ↔ slot i ↔ role` publicly and permanently. The server needs this: it knows slot
roles but not accounts, so without flips it cannot evaluate "all mafia are dead".

Integrity checks available because the composition is public: the flipped roles must never
exceed the setup's counts, and every claimed slot must be one of the published slots with a
valid signature. A player cannot flip a role they do not hold.

### 9.1 Forcing a flip

A player who tampers with their client can die and simply refuse to open their card,
stalling everyone. The clock stops, because the win check cannot count the mafia until
every death is accounted for.

So each player escrows their own flip at deal time:

1. They seal their flip -- `{game, slot, user, role, sig}`, byte-identical to what they
   would post voluntarily -- under a fresh key `K_flip`.
2. `K_flip` is **Shamir-split across every slot** at threshold `N-1`, each share sealed
   to that slot's public key.
3. If they die and do not flip, and the phase deadline actually passes, every other
   client opens its share in the clear. At `N-1` shares anyone can rebuild `K_flip`,
   open the blob, and relay the flip -- the signature inside was made by the dead
   player's own slot key, so it verifies exactly as a voluntary flip would.

The server gates reveals rather than trusting clients to be polite: a share is refused
while the subject is alive, refused once they have flipped, and refused before the
deadline has passed. A slow player is not a refusing one.

#### Why the threshold is N-1, and what that does not buy

`N-1` means every *other* player must take part. A lower threshold would let a small
coalition crack a **living** player's card, which is far worse than the problem being
solved. At `N-1` the only coalition that can open a living card is everyone-but-them,
who by section 1 already know that player's role by elimination.

The honest limit: this does **not** guarantee the card opens. A dead mafia's partners can
withhold their shares and stall the game anyway.

What it does is make withholding **visible**. Reveals are public, so the players who did
not help are named by their absence. A dead mafia's partners must choose between letting
the flip through and identifying themselves. The mechanism does not remove the ability to
stall; it prices it. That is a game-design judgement sitting inside a cryptographic
feature, and it is deliberate rather than an oversight.

## 10. Key loss

The private keys live in `localStorage`, so clearing site data mid-game loses the card.
On first decrypt the client shows a **recovery code** (both private keys, base64) and
offers a password-wrapped backup (PBKDF2-SHA256, 600k iterations) stored server-side.
The server cannot open the backup without the password, which is never sent.
