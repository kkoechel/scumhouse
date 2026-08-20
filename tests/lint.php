<?php
/**
 * Static lint for a project with no framework and no autoloader.
 *
 * `php -l` checks syntax only, so a call to a function that does not exist is a
 * clean parse and a fatal error at runtime -- on a page nobody happens to open
 * until a game is halfway through. This walks every call site and every
 * definition and reports the ones that do not line up.
 *
 * Run: php tests/lint.php
 */

$root = dirname(__DIR__);
$files = array_merge(
    glob($root . '/inc/*.php'),
    glob($root . '/public/*.php'),
    glob($root . '/public/*/*.php'),
    glob($root . '/tools/*.php')
);

$defined = [];
$calls = [];

foreach ($files as $file) {
    $tokens = token_get_all(file_get_contents($file));
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        // A definition: `function name(`
        if ($t[0] === T_FUNCTION) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $defined[$tokens[$j][1]] = $file;
                }
                break;
            }
            continue;
        }
        // A call: T_STRING followed by '(' and not preceded by -> :: function new
        if ($t[0] === T_STRING) {
            $prev = $tokens[$i - 1] ?? null;
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true)) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            $k = $i + 1;
            while (is_array($tokens[$k] ?? null) && $tokens[$k][0] === T_WHITESPACE) {
                $k++;
            }
            if (($tokens[$k] ?? null) === '(') {
                $calls[$t[1]][] = $file . ':' . $t[2];
            }
        }
    }
}

$problems = [];
foreach ($calls as $name => $sites) {
    if (isset($defined[$name]) || function_exists($name)) {
        continue;
    }
    // Only flag this project's own naming conventions; anything else is either a
    // PHP builtin this binary lacks (an extension) or a false positive from a
    // language construct, and guessing about those adds noise, not safety.
    if (!preg_match('/^(sh_|nr_)/', $name)) {
        continue;
    }
    foreach ($sites as $site) {
        $problems[] = "undefined function {$name}() called at " . str_replace($root . '/', '', $site);
    }
}

// Stale prefix from the Nightreeve -> Scumhouse rename.
foreach ($files as $file) {
    $src = file_get_contents($file);
    if (preg_match('/\bnr_[a-z]/', $src) || preg_match('/\bNR_[A-Z]/', $src)) {
        $problems[] = 'stale nr_/NR_ prefix in ' . str_replace($root . '/', '', $file);
    }
}

if ($problems) {
    fwrite(STDERR, "LINT FAILED\n\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  - $p\n");
    }
    fwrite(STDERR, "\n" . count($problems) . " problem(s).\n");
    exit(1);
}
printf("lint: %d functions defined, %d call sites, all resolved\n", count($defined), count($calls));
