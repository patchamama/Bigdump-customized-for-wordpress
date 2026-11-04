<?php
// Public login/discovery regression, with deterministic CLI lookup outcomes.
if (PHP_OS_FAMILY === 'Windows') { fwrite(STDERR, "POSIX probe fixture requires POSIX.\n"); exit(77); }
$proof = dirname(__DIR__) . '/.atl/wordpress-restore-probes-proof';
$owned = $proof . '/tmp/probes-' . bin2hex(random_bytes(8));
mkdir($owned, 0700, true);
file_put_contents($owned . '/owner.json', json_encode(['scope' => 'posix-probe-contract']));
putenv('TMPDIR=' . $owned);
require __DIR__ . '/fixtures/wordpress-restore/bootstrap.php';
$stub = <<<'PHP'

function exec($command, &$output = null, &$result = null) {
    // Model the actual POSIX shell redirection that created NUL in the native fixture.
    if (str_contains($command, '2>NUL')) file_put_contents(FIXTURE_SITE . '/NUL', '');
    $output = []; $result = 1;
    if (str_starts_with($command, "which 'zip' ")) { $output = ['/fixture/zip']; $result = 0; }
    if (str_starts_with($command, "where 'mysql' ")) { $output = ['/fixture/mysql']; $result = 0; }
    file_put_contents(FIXTURE_SITE . '/../probe-count', "probe\n", FILE_APPEND);
    return $output[0] ?? '';
}
PHP;
file_put_contents($root . '/prepend.php', str_replace('FIXTURE_SITE', var_export($site, true), $stub), FILE_APPEND);
$exit = 0;
try {
    for ($i = 0; $i < 100; $i++) {
        if ($s = @fsockopen('127.0.0.1', $port)) { fclose($s); break; }
        usleep(20000);
    }
    $originals = [];
    foreach (['cms_tools.php', 'wp-config.php', 'hosting_fixture.zip', 'db_fixture.sql.gz', '.migrate_config'] as $name) $originals[$name] = hash_file('sha256', $site . '/' . $name);
    [$status, $html] = request('', ['password' => 'FixturePassword']);
    check($status === 200 && is_file($root . '/probe-count'), 'authenticated public GUI really exercised CLI probes');
    foreach (['zip' => 'ok', 'mysql' => 'ok', 'mysqldump' => 'err', 'unzip' => 'err', 'tar' => 'err', 'wp' => 'err', 'curl' => 'err'] as $name => $badge) {
        check((bool)preg_match('/class="badge badge-' . $badge . '"[^>]*>\s*' . $name . '<\/span>/', $html), 'unchanged public CLI lookup badge ' . $name . '=' . $badge);
    }
    [$status, $json] = jsonRequest('wp_restore_discover');
    check(!file_exists($site . '/NUL'), 'POSIX lookup does not create a Windows null-device filename');
    $names = array_values(array_diff(scandir($site), ['.', '..'])); sort($names);
    $expected = array_keys($originals); sort($expected);
    check($names === $expected, 'login leaves exact backup-only hosting inventory unchanged');
    foreach ($originals as $name => $hash) check(hash_file('sha256', $site . '/' . $name) === $hash, 'original bytes unchanged ' . $name);
    check($status === 200 && $json['eligible'] === true && $json['reason'] === 'Backups detected. Preparation does not replace files or tables.', 'automatic restoration discovery remains eligible after login');
    echo "PASS POSIX command-probe admission contracts.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . "\n"); $exit = 1;
} finally {
    proc_terminate($process); proc_close($process);
}
exit($exit);
