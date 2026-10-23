<?php
// Isolated HTTP contract tests. Only synthetic files are copied into TMPDIR.
$root = sys_get_temp_dir() . '/site-report-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
mkdir($root . '/site', 0700);
$site = $root . '/site';
// Namespace only the synthetic copy to replace PDO, even when PDO is built into PHP.
// Other standard classes and exceptions retain their global production meaning.
$source = file_get_contents(__DIR__ . '/../cms_tools.php');
$source = preg_replace('/^<\?php/', '<?php namespace SiteReportFixture; use \\DirectoryIterator; use \\RecursiveIteratorIterator; use \\RecursiveDirectoryIterator; use \\FilesystemIterator; use \\Throwable; use \\Exception;', $source, 1);
file_put_contents($site . '/cms_tools.php', $source);
copy(__DIR__ . '/fixtures/site-report/prepend.php', $site . '/prepend.php');
copy(__DIR__ . '/fixtures/site-report/github.php', $site . '/github.php');
file_put_contents($site . '/prepend.php', "\nrequire __DIR__ . '/github.php';\n", FILE_APPEND);
file_put_contents($site . '/.migrate_config', json_encode(['password_hash'=>hash('sha256', 'odc_migrate_2026_x9k' . 'SyntheticLogin123')]));
file_put_contents($site . '/wp-config.php', "<?php\ndefine('DB_PASSWORD', 'CONFIG_SECRET');\ndefine('DB_USER', 'PRIVATE_USER');\ndefine('DB_NAME', 'fixture');\n// define('WP_DEBUG', false);\ndefine('WP_DEBUG', true);\n/* define('DISALLOW_FILE_EDIT', true); */\ndefine('DISALLOW_FILE_EDIT', false);\n# define('FORCE_SSL_ADMIN', false);\ndefine('FORCE_SSL_ADMIN', true);\ndefine('WP_CACHE', false);\ndefine('AUTH_KEY', 'SALT_SECRET');\n\$table_prefix = 'wp_';\nfile_put_contents(__DIR__.'/EXECUTED', 'bad');\n");
chmod($site . '/wp-config.php', 0644); // Deterministic world-readable-permissions finding.
mkdir($site . '/wp-includes');
file_put_contents($site . '/wp-includes/version.php', "<?php \$wp_version = '6.8.1'; file_put_contents(__DIR__.'/EXECUTED', 'bad');");
mkdir($site . '/wp-content/plugins/sample', 0700, true);
file_put_contents($site . '/wp-content/plugins/sample/main.php', "<?php\n/*\nPlugin Name: Synthetic Plugin\nVersion: 1.2.3\nAuthor: PRIVATE_EMAIL@example.test\n*/\nfile_put_contents(__DIR__.'/EXECUTED', 'bad');");
mkdir($site . '/wp-content/themes/sample', 0700, true);
file_put_contents($site . '/wp-content/themes/sample/style.css', "/*\nTheme Name: Synthetic Theme\nVersion: 2.3.4\nAuthor: PRIVATE_USER\n*/");
file_put_contents($site . '/debug.log', 'LOG_SECRET');
file_put_contents($site . '/robots.txt', "User-agent: *\nDisallow: /\n# PERSONAL_CONTENT");
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
$process = proc_open([PHP_BINARY, '-n', '-d', 'disable_functions=' . (getenv('SITE_REPORT_TEST_CURL') === '1' ? '' : 'curl_init'), '-d', 'auto_prepend_file=' . $site . '/prepend.php', '-d', 'session.save_path=' . $root, '-S', '127.0.0.1:' . $port, '-t', $site], [0=>['pipe','r'], 1=>['file',$root.'/server.log','a'], 2=>['file',$root.'/server.log','a']], $pipes);
if (!is_resource($process)) throw new RuntimeException('Cannot start test server');
$cookie = '';
function request($query, $data = null) {
    global $port, $cookie;
    $context = stream_context_create(['http'=>['method'=>$data === null ? 'GET' : 'POST', 'header'=>"Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n", 'content'=>$data === null ? '' : http_build_query($data), 'ignore_errors'=>true, 'timeout'=>10]]);
    $stream = fopen('http://127.0.0.1:' . $port . '/cms_tools.php' . $query, 'rb', false, $context);
    $body = stream_get_contents($stream);
    $headers = stream_get_meta_data($stream)['wrapper_data'];
    fclose($stream);
    $cookies = [];
    foreach (explode('; ', $cookie) as $pair) if (str_contains($pair, '=')) { [$key, $value] = explode('=', $pair, 2); $cookies[$key] = $value; }
    foreach ($headers as $header) if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $header, $m)) $cookies[$m[1]] = $m[2];
    $cookie = implode('; ', array_map(fn($key, $value) => "$key=$value", array_keys($cookies), $cookies));
    return [$body, $headers];
}
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function report($query = '') {
    [$body] = request('?action=site_report' . $query, []);
    $data = json_decode($body, true);
    check(is_array($data) && ($data['schema'] ?? '') === 'cms-tools-site-report', 'report JSON schema' . $query);
    foreach (['CONFIG_SECRET','SALT_SECRET','PRIVATE_USER','PRIVATE_EMAIL','LOG_SECRET','URL_SECRET','PERSONAL_CONTENT','SyntheticLogin123'] as $secret) check(!str_contains($body, $secret), 'excludes ' . $secret);
    $counts = ['critical'=>0,'warning'=>0,'ok'=>0,'unavailable'=>0];
    foreach ($data['findings'] as $finding) {
        $counts[$finding['severity']]++;
        if ($finding['availability'] === 'unavailable') check($finding['severity'] === 'unavailable', 'unknown is not success');
    }
    check($counts === $data['summary'], 'severity counts match findings');
    return $data;
}
$exitCode = 0;
try {
    for ($i=0; $i<100; $i++) { $ready = @fsockopen('127.0.0.1', $port); if ($ready) { fclose($ready); break; } usleep(50000); }
    [$body] = request('?action=site_report', []);
    check(str_contains($body, 'name="password"') && !str_contains($body, 'cms-tools-site-report'), 'unauthenticated report denied');
    request('', ['password'=>'SyntheticLogin123']);
    [$body, $headers] = request('?action=site_report');
    check(str_contains($headers[0], '405') && !isset(json_decode($body, true)['schema']), 'GET cannot generate report');
    [$body] = request('?action=site_report', ['db_host'=>'CALLER_SECRET', 'db_name'=>'CALLER_SECRET', 'siteurl'=>'https://attacker.test']);
    check((json_decode($body, true)['filename'] ?? '') === 'wp.example.test.json' && !str_contains($body, 'CALLER_SECRET'), 'caller credentials and domain ignored');
    $data = report();
    $configFindings = array_column($data['findings'], null, 'id');
    check($configFindings['wordpress.wp_debug']['severity'] === 'warning'
        && $configFindings['wordpress.disallow_file_edit']['severity'] === 'warning'
        && $configFindings['wordpress.force_ssl_admin']['severity'] === 'ok'
        && $configFindings['wordpress.wp_cache']['severity'] === 'warning',
        'active literal booleans determine severity; line/block/hash comments never produce false OK');
    check($data['filename'] === 'wp.example.test.json' && $data['site']['domain'] === 'wp.example.test', 'filename from authoritative siteurl, not HTTP host');
    check($data['wordpress']['version'] === '6.8.1', 'core version parsed without execution');
    check($data['wordpress']['plugins'][0]['active'] === true, 'plugin activity from DB');
    check($data['wordpress']['themes'][0]['active'] === true, 'theme activity from DB');
    check($data['wordpress']['plugins'][0]['version'] === '1.2.3' && $data['wordpress']['themes'][0]['version'] === '2.3.4', 'component version headers');
    check($data['mysql']['version'] === '8.0.40' && $data['mysql']['tables'][0]['estimated_rows'] === 12, 'DB version and estimated table counts');
    check(isset($data['generated_at'], $data['scope']['read_only'], $data['schema_version']) && $data['scope']['read_only'], 'time, version and read-only scope');
    check($data['summary']['critical'] > 0 && $data['summary']['warning'] > 0 && $data['summary']['ok'] > 0, 'all risk levels represented');
    check(!file_exists($site.'/EXECUTED') && !file_exists($site.'/wp-includes/EXECUTED') && !file_exists($site.'/wp-content/plugins/sample/EXECUTED'), 'WordPress and plugin PHP never executed');
    [$body, $headers] = request('?action=site_report&download=1', []);
    check(in_array('Content-Disposition: attachment; filename="wp.example.test.json"', $headers, true), 'JSON attachment filename');
    require __DIR__ . '/site-report-github.php';
    $offline = report('&offline=1');
    check($offline['site']['domain'] === null && $offline['filename'] === 'site-report.json', 'no request-host domain fallback');
    check($offline['wordpress']['plugins'][0]['active'] === null && $offline['wordpress']['themes'][0]['active'] === null && $offline['summary']['unavailable'] > 0, 'offline activity unknown and partial report retained');
    $incomplete = report('&missing_options=1');
    check($incomplete['site']['domain'] === null && $incomplete['wordpress']['plugins'][0]['active'] === null, 'missing options are unavailable');
    $badQuery = report('&query_failure=1');
    check($badQuery['mysql']['version'] === '8.0.40' && $badQuery['mysql']['tables'] === null && $badQuery['site']['domain'] === null, 'query failure retains partial report without raw errors');
    $registration = report('&unknown_role=1');
    $finding = array_values(array_filter($registration['findings'], fn($f) => $f['id'] === 'site.registration'))[0];
    check($finding['severity'] === 'unavailable', 'unknown registration role is not success');
    // Force the directory bound using only tiny synthetic non-plugin entries.
    for ($i=0; $i<201; $i++) file_put_contents($site . '/wp-content/plugins/filler-' . $i . '.txt', '');
    $bounded = report();
    check(count(array_filter($bounded['findings'], fn($f) => $f['id'] === 'inventory.limit')) > 0, 'directory truncation explicitly unavailable');
    for ($i=0; $i<201; $i++) symlink($site . '/wp-content/themes/sample/style.css', $site . '/wp-content/themes/linked-' . $i);
    $links = report();
    check(count(array_filter($links['findings'], fn($f) => $f['id'] === 'inventory.limit')) === 2, 'symlink entries also consume the directory scan budget');
    check(file_get_contents($site . '/debug.log') === 'LOG_SECRET', 'existing site data unchanged');
    [$body] = request('?lang=es');
    check(str_contains($body, 'Reporte') && str_contains($body, 'Generar reporte'), 'Spanish Report tab and explicit generation');
    [$body] = request('?action=diag_maintenance', []);
    check((json_decode($body, true)['ok'] ?? false) === true, 'existing read-only action smoke');
    // A second installation has no WordPress; the report still includes PHP.
    mkdir($site . '/empty', 0700);
    // Two nested levels keep the app's parent-config discovery away from synthetic WP.
    mkdir($site . '/empty/no-wp', 0700);
    copy($site . '/cms_tools.php', $site . '/empty/no-wp/cms_tools.php');
    copy($site . '/.migrate_config', $site . '/empty/no-wp/.migrate_config');
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",'content'=>'','ignore_errors'=>true]]);
    $missing = json_decode(file_get_contents('http://127.0.0.1:'.$port.'/empty/no-wp/cms_tools.php?action=site_report', false, $ctx), true);
    check(isset($missing['php']['version']) && $missing['wordpress']['version'] === null, 'report without WordPress');
    echo "All site report HTTP tests passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\nFixture artifacts: $root\n");
    $exitCode = 1;
} finally {
    proc_terminate($process);
    proc_close($process);
}
exit($exitCode);
