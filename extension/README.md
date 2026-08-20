# Scumhouse browser extension

The same client as [`../client/`](../client/), delivered by an extension store instead of
by a clone you make yourself.

## Why bother, when the local client already exists

Both close the same hole: the game server cannot hand *you* a different build from
everyone else's, because it does not deliver the code at all.

They differ in who you have to trust and how much work it is:

|  | Local client | Extension |
| --- | --- | --- |
| Code comes from | your own clone | the store, signed |
| Verify it yourself | `git clone`, hash it | diff the unpacked package against this repo |
| Updates | `git pull` | automatic |
| Setup | clone, serve a directory | click install |

The extension is the version for people who will not clone a repository. The local client
is the version for people who do not want to trust a store either. Neither replaces the
other, which is why both exist.

## Build

```sh
tools/build-extension.sh          # assembles dist/extension/
tools/build-extension.sh --zip    # and packages it for upload
```

The package **mirrors this repository's layout** — `client/` and `public/` keep their
paths — so every shared file is copied byte-identical with nothing rewritten on the way
in. The build verifies that rather than assuming it, and fails if the package references
any remote code. Both checks run in CI.

That layout is deliberate: anyone can unzip a published build and diff it against this
repository. An extension you cannot check is just a different party to trust.

## Load it unpacked, to try it

- **Chrome**: `chrome://extensions` → Developer mode → Load unpacked → `dist/extension`
- **Firefox**: `about:debugging` → This Firefox → Load Temporary Add-on → `dist/extension/manifest.json`

Click the toolbar icon, then **Open Scumhouse**. Enter the server address and an API
token from that server's Account page.

## Permissions

The extension asks for **no host access at install time**. The server you play on is your
choice, so it cannot be known in advance, and requesting `<all_urls>` up front would be
both far more access than this needs and a reasonable thing for a reviewer to reject. The
origin you type is requested at the moment it is first used, and you can revoke it in
your browser's extension settings.

There is no background page, no content script, and nothing injected into any website.
The extension is a page that talks to one server you named.

## Publishing

Not yet submitted to either store. Doing so needs a developer account (Chrome charges a
one-off fee; Firefox does not), review, and a commitment to an update channel — which is
exactly the overhead the local client avoids, and the reason that shipped first.

Before submitting, bump `version` in `extension/manifest.json`; both stores reject a
re-upload at an existing version.
