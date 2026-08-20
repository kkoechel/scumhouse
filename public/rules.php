<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/engine.php';
require_once __DIR__ . '/../inc/render.php';

session_init();
$user = current_user();
// From the committed manifest, deliberately NOT recomputed from the served file --
// see inc/render.php's sh_integrity() for why that distinction is the whole point.
$cryptoHash = sh_integrity()['js/crypto.js'] ?? 'unknown';

sh_head('Rules', $user);
?>
<h1>Scumhouse</h1>
<p class="sh-lede">Mafia, played out over days by post. A village of townsfolk, a couple of
killers among them, and no moderator.</p>

<h2>The loop</h2>
<p><strong>Day.</strong> Everyone talks in the town square and votes to lynch. A strict
majority ends the day immediately. If the deadline arrives first, whoever has the most
votes is lynched -- and a tie means nobody is.</p>
<p><strong>Night.</strong> The mafia agree a target in their private channel. The doctor
protects someone. The vigilante, if the table is big enough, may take one of their two
shots. At dawn the results are announced.</p>
<p>The town wins when every mafia member is dead. The mafia win when they equal the
number of remaining townsfolk -- at that point they cannot be out-voted, so the game is
over rather than merely decided.</p>

<h2>Tables</h2>
<div class="scroll">
<table class="sh-setups">
  <tr><th>Players</th><?php foreach (SH_ROLES as $r): ?><th><?= sh_e(ucfirst(strtolower($r))) ?></th><?php endforeach; ?></tr>
  <?php foreach ([5, 6, 7, 8, 9, 10] as $n): $s = sh_setup($n); ?>
    <tr><td><?= $n ?></td><?php foreach (SH_ROLES as $r): ?><td><?= (int) $s[$r] ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
</table>
</div>

<h2>Roles</h2>
<dl>
  <dt>Mafia</dt><dd>Kill one player per night. You know your partners and can talk to them
  privately at any hour.</dd>
  <dt>Cop</dt><dd>Look into one player per night and learn whether they are mafia. They
  cannot lie to you, and you submit nothing to the server &mdash; your whole night happens
  in your own browser.</dd>
  <dt>Doctor</dt><dd>Protect one player per night from a kill. Not the same player two
  nights running.</dd>
  <dt>Vigilante</dt><dd>Two shots for the entire game, none on night 1. A vigilante who
  shoots the doctor has done the mafia's work for them.</dd>
  <dt>Watcher</dt><dd>Watch one player each night and find out who visited them. Your
  report arrives at dawn.</dd>
  <dt>Tracker</dt><dd>Follow one player each night and find out who they visited. Your
  report arrives at dawn, once the night is final.</dd>
  <dt>Roleblocker</dt><dd>Stop one player&rsquo;s night action. You find out which anonymous
  slot they hold &mdash; never what they were about to do, and never their role.</dd>
  <dt>Town</dt><dd>No night action. Talk, read, and vote.</dd>
</dl>

<h2 id="privacy">What the server can and cannot see</h2>
<p>This is the unusual part, so here it is without spin.</p>

<h3>What it cannot see</h3>
<ul>
  <li><strong>Which player holds which role.</strong> Your role is dealt to an anonymous
  identity your own browser generated. The server hands out one blind-signed credential per
  player -- it signs a value it cannot read -- and you redeem it with no session attached.
  The server knows a mafia card went to "slot 3". It has nothing that says slot 3 is you.</li>
  <li><strong>The mafia's conversation.</strong> Its key is a Diffie-Hellman secret between
  two private keys that were made in two browsers and never left them. The server stores
  fixed-size ciphertext it has no key for, sitting alongside the cover traffic every other
  player's browser posts.</li>
  <li><strong>Who submitted a night action.</strong> Actions are signed by a slot, not an
  account, and are sent without a cookie.</li>
</ul>

<h3>What it can see</h3>
<ul>
  <li><strong>Everything public:</strong> the day thread, every vote, who died and how.
  That is the game, and hiding it would be pointless.</li>
  <li><strong>Which slot numbers hold which roles.</strong> The server needs this to
  adjudicate the night without a human moderator. It is inert without the account link.</li>
  <li><strong>Your IP address, if it chooses to log it.</strong> This is the real limit.
  Anonymous requests still travel over the network. This game's anonymous endpoints run with
  access logging off and never read the client address, but that is a promise about a
  configuration, made to you by the person who controls that configuration. If that is not
  good enough for you, play over a VPN or from mobile data.</li>
</ul>

<h3>The honest ceiling</h3>
<p>All of the above defends against an operator who reads the database, not against one who
rewrites the code. The page you are reading served you the JavaScript that holds your keys.
A determined operator could ship a version that quietly mails them home, and no protocol
design fixes that.</p>
<p>What it costs them is that the backdoor has to be <em>published</em>. The crypto is one
static file; its hash is committed to a
<a href="https://github.com/kkoechel/scumhouse">public repository</a>, written into every game page as an
<code>integrity</code> attribute your browser enforces, and checkable from a machine this
operator does not control &mdash; including yours.</p>
<p class="sh-hash"><code><?= sh_e($cryptoHash) ?></code></p>
<p><a href="<?= APP_PATH ?>/verify.php">Check the code you were served &rarr;</a></p>

<h3>What can and cannot exist here</h3>
<p>The privacy design does constrain the role list, but not where you would guess. Anything
that is <em>one player asking about one named player, on a budget</em> works &mdash; the cop,
the tracker, the watcher, the roleblocker all decompose into two halves the game already has.</p>
<p>What cannot exist is a rule where <em>the game engine itself</em> needs to know a living
player&rsquo;s role: something like &ldquo;the mafia kill fails if it hits the vigilante&rdquo;.
Nobody is asking anything there, so nothing pays for the lookup, and the only way to settle it
would be to keep the very map this game is built to destroy.</p>

<h3>How the cop works without anyone holding the answer</h3>
<p>Every player publishes a sealed envelope naming which anonymous slot they hold, signed by
that slot&rsquo;s key so they cannot lie. It has two locks. The outer one is the
investigator&rsquo;s public key, which the server does not have. The inner one is a key the
server does have &mdash; safe, because it can never get past the outer lock to use it.</p>
<p>Each night every living player&rsquo;s browser draws one blind-signed token and spends it
on somebody: on a real target if they have a question, on a random player if they do not. The
server hands back one inner key per token and sees a pile of requests naming a pile of
accounts, with no way to tell which was the real question or who asked it. Holding the rate
limit and holding the ability to read are split between two parties who never combine.</p>
<p>Questions are only accepted during the <strong>first half of the night</strong>, and every
answer opens at the halfway mark, together. That matters more than it sounds: if answers came
back the moment you asked, the person who sat and thought about it would stand out from the
people whose browsers asked on autopilot. Everyone waits for the same clock instead. The
second half of the night is for acting on what you learned.</p>
<p>What it still costs: the server sees the <em>set</em> of accounts asked about each night
and knows one of them mattered, without being able to tell which. And you have to open the
game in the first half of the night to investigate at all &mdash; that one is a real
inconvenience, not a cryptographic subtlety.</p>

<h3>If you lose your keys</h3>
<p>Your card lives in your browser's local storage. Clear it and your card is gone -- there
is no copy anywhere else, by design. The game screen shows a recovery code the moment your
card opens; keep it. There is also an optional server-side backup, wrapped with a passphrase
that never reaches us. It is optional because it is the one record that ties your account to
your (encrypted) identity, and a weak passphrase would undo that.</p>

<?php sh_foot();
