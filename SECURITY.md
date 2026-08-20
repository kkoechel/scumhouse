# Security

Scumhouse makes unusually specific claims: that whoever operates a server cannot
read the mafia's private channel, and cannot tell which accounts hold which roles.
[PROTOCOL.md](PROTOCOL.md) is the normative description of how, and section 1 is an
explicit list of what it does *not* defend against.

## This has not been independently audited

The protocol was designed, documented and tested, and the tests drive the real
client against the real server through every cryptographic step. That is not the
same as review by someone who did not write it. **Treat the guarantees as
plausible and unverified rather than established**, and please do not deploy this
somewhere the stakes are higher than a game with friends.

If you have the background to review it, section 5 of PROTOCOL.md is the part
worth attacking, and section 5.5 is the general rule the whole role list rests on.

## Reporting a vulnerability

Use GitHub's **private vulnerability reporting** on this repository
(Security → Report a vulnerability). That keeps the report private until there is
a fix, which matters here because there is a live instance.

Please do not open a public issue for anything that would let someone deanonymise
a player or read a channel they are not in.

## What counts as a vulnerability

In scope, and interesting:

- Any way for a server operator to learn `account -> role` for a living player.
- Any way to read the mafia channel, or a role envelope, without holding the key
  the design says you need.
- Any way for a player to obtain more investigation results than their budget
  allows, or to forge a claim about which slot they hold.
- Any way to bypass the invite list, or to act as a slot you do not hold.

Known and already documented, so not a finding on its own:

- **Network-level correlation.** Anonymous requests still carry an IP. Section 7
  says so, and says what is and is not done about it.
- **A malicious operator serving backdoored JavaScript.** Section 8 is entirely
  about why this cannot be prevented, only made loud.
- **Two colluding players forging a reverse envelope.** Section 5.4 explains the
  attack, why it needs the victim's cooperation, and why it is detectable.
- **A dead player refusing to reveal their card**, stalling the game. Section 9.

If you think one of those is worse than the document claims, that *is* worth
reporting -- the concern is that the write-ups understate them, not that they exist.
