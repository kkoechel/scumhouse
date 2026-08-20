/*
 * Setup and game-picking for the locally-run client.
 *
 * A separate file rather than an inline <script> because the browser-extension
 * build (see tools/build-extension.sh) is subject to Manifest V3's content
 * security policy, which forbids inline script outright. Keeping it external
 * means the extension ships this file byte-identical to the one here.
 */
(function () {
  'use strict';
  const KEY = 'scumhouse/local-config';
  const $ = (id) => document.getElementById(id);

  const cfg = JSON.parse(localStorage.getItem(KEY) || 'null');
  $('sh-local-origin').textContent = location.origin;

  function note(msg, kind) {
    const box = $('sh-local-status');
    box.className = 'sh-status ' + (kind || '');
    box.textContent = msg || '';
  }

  /**
   * In the browser-extension build the server address is chosen by the player, so
   * the package cannot declare it up front. Asking for <all_urls> at install time
   * would be both a store-review red flag and far more access than this needs, so
   * the origin is requested at the moment it is first used.
   *
   * A no-op when running as an ordinary page (the local client), where there is no
   * permissions API and CORS already governs the request.
   */
  async function ensureHostPermission(base) {
    const ext = (typeof browser !== 'undefined' && browser.permissions) ? browser
              : (typeof chrome !== 'undefined' && chrome.permissions) ? chrome : null;
    if (!ext) return true;
    const origin = new URL(base).origin + '/*';
    if (await ext.permissions.contains({ origins: [origin] })) return true;
    // Must happen inside a user gesture, which the Connect button provides. On a
    // saved config the permission already exists, so this is not reached.
    return ext.permissions.request({ origins: [origin] });
  }

  async function listGames(base, token) {
    const res = await fetch(base.replace(/\/+$/, '') + '/api/games.php', {
      credentials: 'omit',                       // cross-origin: token only, never cookies
      headers: { 'Authorization': 'Bearer ' + token },
    });
    if (res.status === 401) throw new Error('That token was rejected. Create a new one on the server.');
    if (!res.ok) throw new Error('Server returned ' + res.status + '. Check the address.');
    return res.json();
  }

  function renderGames(base, token, data) {
    const box = $('sh-local-games');
    box.innerHTML = '<h2>Your games</h2>';
    if (!data.games.length) {
      box.insertAdjacentHTML('beforeend',
        '<p class="sh-hint">No games yet. Join one from the hosted lobby, then come back.</p>');
      return;
    }
    const ul = document.createElement('ul');
    ul.className = 'sh-games';
    for (const g of data.games) {
      const li = document.createElement('li');
      const label = g.status === 'active'
        ? (g.phase === 'day' ? 'Day ' : 'Night ') + g.phase_no
        : g.status;
      li.innerHTML = '<span class="sh-game-id">#' + g.id + '</span>'
        + '<span class="sh-game-size">' + g.seated + '/' + g.num_seats + '</span>'
        + '<span class="sh-game-state">' + label + (g.alive ? '' : ' · dead') + '</span>';
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = 'Open';
      b.onclick = () => launch(base, token, g.id);
      li.appendChild(b);
      ul.appendChild(li);
    }
    box.appendChild(ul);
  }

  // Globals must be in place BEFORE the shared scripts load: game.js renders and
  // starts polling the moment it is evaluated.
  function launch(base, token, gameId) {
    window.SH_APP_PATH = base.replace(/\/+$/, '');
    window.SH_GAME_ID = gameId;
    window.SH_API_TOKEN = token;

    $('sh-setup').hidden = true;
    $('sh-local-switch').hidden = false;
    $('sh-local-who').textContent = 'table #' + gameId;

    // Same files the hosted page uses, served from your checkout rather than
    // from the game server.
    for (const src of ['../public/js/crypto.js', '../public/js/game.js']) {
      const el = document.createElement('script');
      el.src = src;
      el.async = false;               // crypto.js must finish before game.js runs
      document.body.appendChild(el);
    }
  }

  async function connect(base, token, quiet) {
    try {
      if (!await ensureHostPermission(base)) {
        note('This extension needs your permission to talk to ' + base + '.', 'error');
        return;
      }
      note('Contacting ' + base + '…');
      const data = await listGames(base, token);
      localStorage.setItem(KEY, JSON.stringify({ base: base, token: token }));
      note('Connected.', 'ok');
      renderGames(base, token, data);
    } catch (e) {
      note(e.message, 'error');
      if (quiet) localStorage.removeItem(KEY);
    }
  }

  $('sh-local-form').onsubmit = (ev) => {
    ev.preventDefault();
    connect($('sh-local-base').value.trim(), $('sh-local-token').value.trim(), false);
  };

  $('sh-local-forget').onclick = (ev) => {
    ev.preventDefault();
    localStorage.removeItem(KEY);
    location.reload();
  };
  $('sh-local-switch').onclick = (ev) => { ev.preventDefault(); location.reload(); };

  if (cfg && cfg.base && cfg.token) {
    $('sh-local-base').value = cfg.base;
    $('sh-local-token').value = cfg.token;
    connect(cfg.base, cfg.token, true);
  }
})();
