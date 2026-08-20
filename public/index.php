<?php
require_once __DIR__ . '/../inc/auth.php';

// require_login() (not a bare current_user() check) so a visitor who's only
// logged into a portal, not this game yet, gets the optional SSO auto-login
// attempt too -- this is the actual entry point every portal tile links to,
// so skipping require_login() here silently defeats global login for every
// first-time visit to a game. Copied from grovewarden/public/index.php's
// current (fixed) version, not reconstructed -- this exact bug was found
// and fixed repo-wide earlier this session.
require_login();
header('Location: ' . APP_PATH . '/lobby.php');
exit;
