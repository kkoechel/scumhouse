<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'scumhouse',
        'user' => 'scumhouse',
        'pass' => 'CHANGE_ME',
    ],

    // Addresses that may reach public/admin/*. Everything else is an ordinary
    // player. At least one is required or nobody can manage the invite list.
    'admin_emails' => [
        'admin@example.com',
    ],

    'session_name' => 'scumhouse_sess',
    'base_url' => 'https://example.com/scumhouse',

    // Optional link shown in the page footer, back to whatever site hosts this
    // install. Leave null and no link is rendered.
    'portal_url' => null,

    // Magic-link delivery, via Resend (https://resend.com). The FROM address must
    // belong to a domain you have verified with them or the send silently fails.
    'resend_api_key' => 'CHANGE_ME',
    'resend_from' => 'Scumhouse <scumhouse@example.com>',

    // WHO IS ALLOWED IN.
    //
    //   'local'  -- this app owns its own allowed_emails table, managed from
    //              public/admin/access.php. Use this unless you know otherwise.
    //   'portal' -- defer to a shared allowlist table in ANOTHER database on the
    //              same MySQL server, for running several games behind one sign-in.
    //              Requires that database's name below, and a read grant on it.
    'allowlist' => [
        'mode' => 'local',
        'portal_db' => null,
    ],

    // OPTIONAL single sign-on. Leave null unless you run a portal that issues the
    // shared cookie described in inc/sso.php. When null, sign-in is magic-link only
    // and inc/sso.php is never consulted.
    'sso_secret' => null,
    'sso_cookie' => 'portal_sso',
];
