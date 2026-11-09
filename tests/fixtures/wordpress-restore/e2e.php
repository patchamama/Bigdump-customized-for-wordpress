<?php
// End-to-end driver: a REAL WordPress core is installed as the backup source, then restored through the
// public API and exercised over HTTP. Runs only inside the owned, network-less disposable containers.
$phase = $argv[1];
$base = '/tmp/contract'; $site = $base . '/site'; $private = $base . '/private'; $wpdir = $base . '/wordpress';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_STRINGIFY_FETCHES=>true,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false, PDO::MYSQL_ATTR_LOCAL_INFILE=>false];
$dsn = 'mysql:unix_socket=/var/run/mysqld/fixture.sock;charset=utf8mb4';
$cookie = ''; $csrf = ''; $run = null; $root = 'http://127.0.0.1:18084';
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
function http(string $method, string $path, ?array $post = null, string $jar = '', bool $follow = false): array {
    global $root;
    $context = stream_context_create(['http'=>['method'=>$method, 'ignore_errors'=>true, 'timeout'=>20, 'follow_location'=>$follow ? 1 : 0,
        'header'=>"Content-Type: application/x-www-form-urlencoded\r\n" . ($jar !== '' ? "Cookie: $jar\r\n" : ''),
        'content'=>$post === null ? '' : http_build_query($post)]]);
    $stream = fopen($root . $path, 'rb', false, $context);
    if (!$stream) throw new RuntimeException('HTTP transport');
    $body = stream_get_contents($stream); $headers = stream_get_meta_data($stream)['wrapper_data']; fclose($stream);
    preg_match('#HTTP/\S+ (\d+)#', $headers[0], $m);
    $cookies = [];
    foreach ($headers as $h) if (preg_match('/^Set-Cookie: ([^;]+)/i', $h, $c)) $cookies[] = $c[1];
    return [(int)$m[1], $body, $cookies, $headers];
}
function api(string $action, ?array $post = null): array {
    global $cookie;
    [$status, $body, $cookies] = http($post === null ? 'GET' : 'POST', '/cms_tools.php?action=' . $action, $post, $cookie);
    if ($cookies) $cookie = implode('; ', $cookies);
    $decoded = json_decode($body, true);
    return [$status, $decoded ?? $body];
}
function step(string $operation, array $extra = []): array {
    global $csrf, $run;
    return api('wp_restore_step', $extra + ['csrf'=>$csrf, 'run_id'=>$run['run_id'], 'stage'=>'preflight', 'operation'=>$operation, 'revision'=>$run['revision']]);
}
function bootstrapPdo(string $password = ''): PDO { global $dsn, $options; return new PDO($dsn, 'root', $password, $options); }
try {
    if ($phase === 'source') {
        $password = bin2hex(random_bytes(16));
        $root_pdo = bootstrapPdo();
        foreach (['src_wp', 'restore_target', 'recovery84'] as $db) $root_pdo->exec("CREATE DATABASE $db CHARACTER SET utf8mb4");
        $root_pdo->exec("CREATE USER 'src_user'@'localhost' IDENTIFIED BY " . $root_pdo->quote($password));
        $root_pdo->exec("GRANT ALL PRIVILEGES ON src_wp.* TO 'src_user'@'localhost'");
        file_put_contents($base . '/source.json', json_encode(compact('password')));
        file_put_contents($wpdir . '/wp-config.php', "<?php\ndefine('DB_NAME','src_wp');\ndefine('DB_USER','src_user');\ndefine('DB_PASSWORD'," . var_export($password, true)
            . ");\ndefine('DB_HOST','localhost');\ndefine('DB_CHARSET','utf8mb4');\ndefine('DB_COLLATE','');\ndefine('DISABLE_WP_CRON',true);\ndefine('WP_DEBUG',false);\n"
            . "\$table_prefix='srcwp_';\nif(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');\nrequire_once ABSPATH.'wp-settings.php';\n");
        define('WP_INSTALLING', true);
        $_SERVER['HTTP_HOST'] = 'source.example'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = 'source.example';
        require $wpdir . '/wp-load.php';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $result = wp_install('E2E Restored Site', 'admin', 'admin@source.example', true, '', 'E2E-admin-pass-1');
        check(!empty($result['user_id']), 'real WordPress core installed on the source database');
        update_option('siteurl', 'http://source.example'); update_option('home', 'http://source.example');
        update_option('permalink_structure', '');
        wp_insert_post(['post_title'=>'E2E Restored Post', 'post_content'=>'Body from the backup source.', 'post_status'=>'publish']);
        mkdir($wpdir . '/wp-content/plugins/e2e-good'); mkdir($wpdir . '/wp-content/plugins/e2e-bad');
        file_put_contents($wpdir . '/wp-content/plugins/e2e-good/e2e-good.php', "<?php\n/* Plugin Name: E2E Good */\nadd_action('wp_footer', function () { echo '<!--e2e-good-->'; });\n");
        file_put_contents($wpdir . '/wp-content/plugins/e2e-bad/e2e-bad.php', "<?php\n/* Plugin Name: E2E Bad */\nadd_action('init', function () { throw new Error('e2e plugin breaks the site'); });\n");
        update_option('active_plugins', ['e2e-good/e2e-good.php', 'e2e-bad/e2e-bad.php']);
        $zip = new ZipArchive(); $zip->open($base . '/hosting_e2e.zip', ZipArchive::CREATE | ZipArchive::EXCL);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wpdir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) if ($file->isFile()) $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($wpdir) + 1));
        $zip->close();
        check(is_file($base . '/hosting_e2e.zip'), 'source site archived like a hosting backup');
        exit(0);
    }
    if ($phase === 'prepare') {
        mkdir($site, 0700); mkdir($private, 0700);
        copy(dirname(__DIR__, 3) . '/cms_tools.php', $site . '/cms_tools.php');
        rename($base . '/hosting_e2e.zip', $site . '/hosting_e2e.zip'); rename($base . '/incoming/db_e2e.sql.gz', $site . '/db_e2e.sql.gz');
        $password = bin2hex(random_bytes(20));
        $root_pdo = bootstrapPdo();
        $root_pdo->exec("CREATE USER 'restore_fixture'@'localhost' IDENTIFIED BY " . $root_pdo->quote($password));
        $root_pdo->exec("GRANT ALL PRIVILEGES ON restore_target.* TO 'restore_fixture'@'localhost'");
        $root_pdo->exec("GRANT SELECT ON recovery84.* TO 'restore_fixture'@'localhost'");
        $root_pdo->exec("CREATE USER 'fixture_recovery'@'localhost'");
        $root_pdo->exec("GRANT ALL PRIVILEGES ON recovery84.* TO 'fixture_recovery'@'localhost'");
        $root_pdo->exec('ALTER USER \'root\'@\'localhost\' IDENTIFIED BY ' . $root_pdo->quote(bin2hex(random_bytes(24))));
        $pdo = new PDO($dsn . ';dbname=restore_target', 'restore_fixture', $password, $options);
        $pdo->exec('CREATE TABLE wp_options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,option_name VARCHAR(191) NOT NULL,option_value LONGTEXT NOT NULL) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO wp_options (option_name,option_value) VALUES ('siteurl','http://previous.example')");
        $pdo->exec('CREATE TABLE unrelated_old (id INT PRIMARY KEY,note VARCHAR(30)) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO unrelated_old VALUES (1,'previous site table')");
        file_put_contents($site . '/.migrate_config', json_encode(['password_hash'=>hash('sha256', 'odc_migrate_2026_x9kFixturePassword')]));
        file_put_contents($site . '/wp-config.php', "<?php\ndefine('DB_HOST','localhost');\ndefine('DB_NAME','restore_target');\ndefine('DB_USER','restore_fixture');\ndefine('DB_PASSWORD',"
            . var_export($password, true) . ");\n\$table_prefix='wp_';\nif (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\nrequire_once ABSPATH . 'wp-settings.php';\n");
        touch($base . '/ready');
        for ($i = 0, $up = false; $i < 150 && !$up; $i++) { if ($s = @fsockopen('127.0.0.1', 18084)) { fclose($s); $up = true; } else usleep(20000); }
        check($up, 'owned PHP HTTP server ready');
        [, , $login] = http('POST', '/cms_tools.php', ['password'=>'FixturePassword']); $cookie = implode('; ', $login);
        [$status, $d] = api('wp_restore_discover'); check($status === 200 && $d['eligible'], 'real backup-only hosting discovered ' . json_encode($d['reason'] ?? $d)); $csrf = $d['csrf'];
        [$status, $j] = api('wp_restore_prepare', ['csrf'=>$csrf, 'archive'=>'hosting_e2e.zip', 'database'=>'db_e2e.sql.gz', 'target_url'=>$root]);
        check($status === 201, 'public prepare'); $run = $j['run'];
        foreach (['files', 'sql', 'values'] as $op) {
            for ($i = 0; $i < 4000 && !($run['preflight'][$op]['ready'] ?? false); $i++) {
                [$status, $j] = step($op);
                if ($status !== 200) throw new RuntimeException("$op refused: " . json_encode($j));
                $run = $j['run'];
            }
            check($run['preflight'][$op]['ready'], "real WordPress $op preparation ready ({$run['preflight'][$op]['total']} units)");
        }
        [$status, $j] = step('backup'); check($status === 200, 'whole previous database captured'); $run = $j['run'];
        [$status, $manifest] = api('wp_restore_state&manifest=sql');
        check(($manifest['run']['sql_manifest']['source_prefix'] ?? null) === 'srcwp_' && ($run['preflight']['prefix']['status'] ?? null) === 'remap'
            && $run['preflight']['prefix']['config'] === 'wp_', 'real backup prefix srcwp_ is reported against the wp-config prefix wp_');
        [$status, $gzip] = http('POST', '/cms_tools.php?action=wp_restore_backup_download', ['csrf'=>$csrf, 'run_id'=>$run['run_id']], $cookie);
        check(hash('sha256', $gzip) === $run['preflight']['backup']['sha256'], 'authenticated previous-database download');
        file_put_contents($base . '/recovery.sql', gzdecode($gzip));
        file_put_contents($base . '/state.json', json_encode(compact('password', 'cookie', 'csrf', 'run')));
        exit(0);
    }
    // complete
    extract(json_decode(file_get_contents($base . '/state.json'), true));
    [$status, $j] = step('backup_confirm', ['artifact_sha256'=>$run['preflight']['backup']['sha256'], 'recovery_method'=>'separate_database_schema_rows_counters',
        'recovery_confirmed'=>'1', 'replace_all_tables_confirmed'=>'1']);
    check($status === 200, 'operator-confirmed external recovery'); $run = $j['run'];
    [$status, $manifest] = api('wp_restore_state&manifest=values');
    $proof = ['artifact_sha256'=>$run['preflight']['backup']['sha256'], 'plan_sha256'=>$manifest['run']['values_manifest']['plan_sha256']];
    for ($i = 0; $i < 400 && !($run['preflight']['staging']['ready'] ?? false); $i++) {
        [$status, $j] = step('staging', $proof + ['staging_confirmed'=>'1']);
        if ($status !== 200) throw new RuntimeException('staging refused: ' . json_encode($j));
        $run = $j['run'];
    }
    check($run['preflight']['staging']['ready'], 'real WordPress schema and rows staged');
    [$status, $j] = step('assurance', $proof + ['assurance_confirmed'=>'1']); check($status === 200, 'locked comparison of staged WordPress tables'); $run = $j['run'];
    $replace = $proof + ['replacement_confirmed'=>'1', 'scope_confirmed'=>'exact_original_old_set'];
    [$status, $j] = step('replacement_prepare', $replace); check($status === 200, 'exact-set replacement prepared'); $run = $j['run'];
    [$status, $j] = step('replacement', $replace); check($status === 200, 'previous tables replaced by the restored WordPress tables'); $run = $j['run'];
    $place = ['artifact_sha256'=>$proof['artifact_sha256']];
    for ($i = 0; $i < 400 && ($run['preflight']['placement']['status'] ?? 'none') !== 'trial_ready'; $i++) {
        [$status, $j] = step('placement_trial', $place); if ($status !== 200) throw new RuntimeException('trial refused'); $run = $j['run'];
    }
    check(is_file($site . '/wpr-trial-' . substr($run['run_id'], 0, 16) . '/wp-load.php') && !is_file($site . '/wp-load.php'), 'WordPress core waits in the trial folder');
    $promote = $place + ['promote_confirmed'=>'1', 'scope_confirmed'=>'trial_to_root', 'trial_sha256'=>$run['preflight']['placement']['trial_sha256']];
    for ($i = 0; $i < 400 && ($run['preflight']['placement']['status'] ?? '') !== 'promoted'; $i++) {
        [$status, $j] = step('placement_promote', $promote); if ($status !== 200) throw new RuntimeException('promotion refused'); $run = $j['run'];
    }
    check($run['preflight']['placement']['status'] === 'promoted' && is_file($site . '/wp-load.php'), 'confirmed promotion placed WordPress at the site root');
    check(!$run['runtime_verified'], 'placement alone does not claim a working site');
    for ($i = 0; $i < 20; $i++) {
        [$status, $j] = step('verify_runtime', ['verify_confirmed'=>'1']); if ($status !== 200) throw new RuntimeException('verification refused: ' . json_encode($j));
        $run = $j['run']; if ($run['preflight']['runtime']['status'] !== 'running') break;
    }
    $rt = $run['preflight']['runtime'];
    check($rt['status'] === 'partial' && $rt['good'] === ['e2e-good/e2e-good.php'] && $rt['failed'] === ['e2e-bad/e2e-bad.php'],
        'tool re-enabled the healthy plugin and rolled back the plugin that crashes WordPress ' . json_encode($rt));
    // The restored site, exercised from outside the tool.
    [$status, $front] = http('GET', '/', null, '', true);
    check($status === 200 && str_contains($front, 'E2E Restored Site') && str_contains($front, 'E2E Restored Post') && str_contains($front, '<!--e2e-good-->'),
        'real WordPress front page serves the restored site title, post and the active plugin output');
    [$status, $login] = http('GET', '/wp-login.php');
    check($status === 200 && str_contains($login, 'user_login'), 'wp-login.php responds');
    [$status, $rest] = http('GET', '/?rest_route=/wp/v2/posts');
    $posts = json_decode($rest, true);
    check($status === 200 && is_array($posts) && ($posts[0]['title']['rendered'] ?? '') === 'E2E Restored Post', 'REST API returns the restored post');
    [$status, , $cookies] = http('POST', '/wp-login.php', ['log'=>'admin', 'pwd'=>'E2E-admin-pass-1', 'wp-submit'=>'Log In', 'testcookie'=>'1'], 'wordpress_test_cookie=WP%20Cookie%20check');
    check($status === 302 && count($cookies) >= 2, 'restored administrator logs in with the original password');
    [$status, $admin] = http('GET', '/wp-admin/', null, implode('; ', $cookies));
    check($status === 200 && str_contains($admin, 'Dashboard'), 'wp-admin dashboard opens: roles, capabilities and prefix mapping are intact');
    $pdo = new PDO($dsn . ';dbname=restore_target', 'restore_fixture', $password, $options);
    check($pdo->query("SELECT option_value FROM wp_options WHERE option_name='siteurl'")->fetchColumn() === $root, 'site URL rewritten to the destination');
    check($pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='restore_target' AND TABLE_NAME LIKE 'srcwp\\_%'")->fetchColumn() === '0'
        && $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='restore_target' AND TABLE_NAME='unrelated_old'")->fetchColumn() === '0', 'previous tables are gone and no source-prefix table remains');
    check(str_contains((string)file_get_contents($site . '/wp-config.php'), "\$table_prefix='wp_'"), 'wp-config.php keeps the destination prefix and credentials');
    // Several installations in one database: list, snapshot, previews.
    [$status, $list] = api('installs_list', []);
    check($status === 200 && $list['ok'] && $list['live'] === 'wp_' && count($list['installs']) === 1 && $list['installs'][0]['title'] === 'E2E Restored Site'
        && $list['installs'][0]['current'] === true && $list['installs'][0]['posts'] >= 1, 'installation list shows the single restored site with its blog title');
    [$status, $start] = api('installs_snapshot_start', []);
    check($status === 200 && $start['ok'] && preg_match('/^wp_[0-9]{8}_$/', $start['new_prefix']) && count($start['tables']) >= 12, 'snapshot prefix is the current prefix plus today\'s date');
    $snap = $start['new_prefix'];
    foreach ($start['tables'] as $t) { [$status, $copy] = api('installs_snapshot_table', ['new_prefix'=>$snap, 'table'=>$t['name']]); if (!($copy['ok'] ?? false)) throw new RuntimeException('snapshot table failed: ' . json_encode($copy)); }
    [$status, $fin] = api('installs_snapshot_finish', ['new_prefix'=>$snap]);
    check($fin['ok'] === true && $fin['usermeta_rewritten'] >= 2, 'snapshot completed and prefix-bound keys were rewritten');
    [$status, $again] = api('installs_snapshot_finish', ['new_prefix'=>$snap]);
    check(($again['ok'] ?? true) === false && $pdo->query("SELECT COUNT(*) FROM {$snap}usermeta WHERE meta_key='{$snap}capabilities'")->fetchColumn() === '1',
        'replaying the snapshot finalization is refused and cannot corrupt the copy');
    check($pdo->query("SELECT COUNT(*) FROM {$snap}usermeta WHERE meta_key='{$snap}capabilities'")->fetchColumn() === '1'
        && $pdo->query("SELECT COUNT(*) FROM {$snap}options WHERE option_name='{$snap}user_roles'")->fetchColumn() === '1'
        && $pdo->query("SELECT COUNT(*) FROM wp_options WHERE option_name='wp_user_roles'")->fetchColumn() === '1', 'snapshot owns its roles and capabilities; the live installation is untouched');
    [$status, $list] = api('installs_list', []);
    check(count($list['installs']) === 2 && $list['installs'][1]['prefix'] === $snap && $list['installs'][1]['snapshot'] === true
        && $list['installs'][1]['title'] === 'E2E Restored Site', 'the snapshot is listed as a second installation');
    [$status, $dup] = api('installs_snapshot_table', ['new_prefix'=>'evil_', 'table'=>'wp_options']);
    check(($dup['ok'] ?? true) === false && $pdo->query("SHOW TABLES LIKE 'evil%'")->fetchColumn() === false, 'a snapshot prefix outside the current-prefix-plus-date pattern is refused');
    [$status, $open] = api('installs_preview_open', ['prefix'=>$snap]);
    check($status === 200 && $open['ok'] && str_contains($open['url'], 'cmspv='), 'preview of the snapshot opened');
    [$status, $page] = http('GET', parse_url($open['url'], PHP_URL_PATH) . '?' . parse_url($open['url'], PHP_URL_QUERY), null, '', true);
    check($status === 200 && str_contains($page, 'E2E Restored Post') && str_contains($page, 'E2E Restored Site'), 'the real blog renders from the snapshot tables');
    [$status] = http('GET', '/' . $open['folder'] . '/');
    check($status === 403, 'the preview refuses requests without its token');
    [$status, $switch] = api('installs_preview_switch', ['folder'=>$open['folder'], 'token'=>$open['token'], 'prefix'=>'wp_']);
    check($status === 200 && $switch['ok'] && str_contains(file_get_contents($site . '/' . $open['folder'] . '/wp-config.php'), "\$table_prefix = 'wp_'"), 'live switch repoints the same preview to another prefix');
    [$status, $page] = http('GET', parse_url($switch['url'], PHP_URL_PATH) . '?' . parse_url($switch['url'], PHP_URL_QUERY), null, '', true);
    check($status === 200 && str_contains($page, 'E2E Restored Post'), 'the preview renders the other installation after switching');
    [$status, $bad] = api('installs_preview_switch', ['folder'=>$open['folder'], 'token'=>str_repeat('0', 32), 'prefix'=>$snap]);
    check(($bad['ok'] ?? true) === false, 'switching with a wrong token is refused');
    [$status, $closed] = api('installs_preview_close', ['folder'=>$open['folder'], 'token'=>$open['token']]);
    check($closed['ok'] === true && !file_exists($site . '/' . $open['folder']) && is_file($site . '/wp-load.php'), 'closing removes the preview folder and leaves the live site intact');
    [$status, $unknown] = api('installs_preview_open', ['prefix'=>'nope_']);
    check(($unknown['ok'] ?? true) === false, 'an unknown prefix cannot be previewed');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'E2E stopped: ' . get_class($e) . ' ' . $e->getMessage() . "\n");
    exit(1);
}
