<?php
/**
 * Lets a player check that the code their browser received is the code that is
 * published, without taking the server's word for it.
 *
 * Be clear about what this page can and cannot do. It compares the files on disk
 * against the committed manifest, which catches a partial deploy, a stray edit, or
 * anyone who tampers with the JavaScript but not the manifest. It cannot catch an
 * operator who changes both -- for that, compare the hashes below against the
 * repository, or against the output of tools/monitor-integrity.sh run from a
 * machine this operator does not control.
 */
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/render.php';

session_init();
$user = current_user();

$rows = [];
$allMatch = true;
foreach (sh_integrity() as $file => $expected) {
    $path = __DIR__ . '/' . $file;
    $actual = is_readable($path) ? 'sha384-' . base64_encode(hash_file('sha384', $path, true)) : null;
    $match = $actual !== null && hash_equals($expected, $actual);
    $allMatch = $allMatch && $match;
    $rows[] = ['file' => $file, 'expected' => $expected, 'actual' => $actual, 'match' => $match];
}

sh_head('Verify the code', $user);
?>
<h1>Verify the code</h1>
<p class="sh-lede">Scumhouse&rsquo;s privacy claims rest on JavaScript that runs in your
browser and holds keys the server never sees. That is only worth anything if you can tell
which JavaScript you got.</p>

<?php if ($allMatch): ?>
  <div class="sh-status ok">Every pinned file matches the committed manifest.</div>
<?php else: ?>
  <div class="sh-status error">A pinned file does not match the manifest. Do not play this
  game until that is explained.</div>
<?php endif; ?>

<div class="scroll">
<table class="sh-setups sh-verify">
  <tr><th>File</th><th>Published hash</th><th>Served</th></tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><code><?= sh_e($r['file']) ?></code></td>
      <td class="sh-hashcell"><code><?= sh_e($r['expected']) ?></code></td>
      <td style="color: <?= $r['match'] ? 'var(--sealed, #7fa88a)' : 'var(--blood)' ?>">
        <?= $r['match'] ? 'matches' : 'DOES NOT MATCH' ?>
      </td>
    </tr>
  <?php endforeach; ?>
</table>
</div>

<h2>What this proves, and what it doesn't</h2>
<p>These hashes are also written into every game page as
<code>integrity=&quot;sha384-&hellip;&quot;</code> attributes, so your browser refuses to run
a script whose bytes do not match. That stops tampering with the deployed file on its own.</p>
<p>It does not stop someone who edits the manifest too. For that, the check has to happen
somewhere the operator does not control:</p>
<ol class="sh-steps">
  <li>Compare the hashes above against <code>public/integrity.json</code> in the
  <a href="https://github.com/kkoechel/scumhouse/blob/main/public/integrity.json">published repository</a>,
  whose history shows every time they changed.</li>
  <li>Or check it yourself, from your own machine:
  <pre><code>git clone https://github.com/kkoechel/scumhouse.git
cd scumhouse
tools/monitor-integrity.sh <?= sh_e(rtrim(config()['base_url'], '/')) ?></code></pre>
  A silent swap then has to survive somebody else&rsquo;s computer.</li>
</ol>
<p>An operator can always ship bad code. The point of all this is that they cannot ship it
<em>quietly</em>.</p>
<?php sh_foot();
