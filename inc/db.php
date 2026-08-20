<?php
// Baked into every internal link/redirect/fetch/asset path from the start,
// same as every other game in this repo -- nginx does a plain proxy_pass
// with zero body rewriting; the app just always knows its own mount path.
const APP_PATH = '/scumhouse';

function config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $cfg = config()['db'];
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Pin the session to UTC. Every deadline in this game is written by MySQL
        // (DATE_ADD(NOW(), ...)) and read back by PHP as a bare datetime string,
        // then compared with strtotime() against time() -- which is UTC. If the
        // server's MySQL runs on SYSTEM time and the system is not UTC, every one
        // of those comparisons is silently skewed by the offset: phases end early,
        // and any interval shorter than the offset is already in the past the
        // instant it is set. public/js/game.js makes the same assumption when it
        // appends 'Z' to phase_ends_at.
        //
        // Found the hard way -- see tests/endpoints.php, which asserts the two
        // clocks agree so this cannot come back.
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
