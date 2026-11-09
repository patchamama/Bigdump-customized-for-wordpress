<?php
// Trusted native fixture driver. No backup PHP or WordPress runtime is loaded.
require __DIR__ . '/values.php';
$report = ['checks' => [], 'old_state_unchanged' => false];
$server = null;
$exit = 1;
function nativeCheck(bool $condition, string $label): void {
    global $report;
    $report['checks'][] = ['label' => $label, 'passed' => $condition];
    if (!$condition) throw new RuntimeException($label);
    echo "PASS $label\n";
}
function nativeQuote(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }
function nativeOracle(PDO $pdo, ?array $only = null): string {
    $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    usort($tables, static fn($a, $b) => strcmp($a[0], $b[0]));
    $state = [];
    foreach ($tables as [$name, $kind]) {
        if ($only !== null && !in_array($name, $only, true)) continue;
        if ($kind !== 'BASE TABLE') throw new RuntimeException('Unexpected fixture object');
        $q = nativeQuote($name);
        $ddl = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM)[1];
        $columns = $pdo->query('SHOW COLUMNS FROM ' . $q)->fetchAll(PDO::FETCH_ASSOC);
        $select = implode(',', array_map(static fn($c) => 'CAST(' . nativeQuote($c['Field']) . ' AS BINARY)', $columns));
        $rows = $pdo->query('SELECT ' . $select . ' FROM ' . $q)->fetchAll(PDO::FETCH_NUM);
        $hashes = [];
        foreach ($rows as $row) {
            $encoded = array_map(static fn($v) => $v === null ? null : base64_encode((string)$v), $row);
            $hashes[] = hash('sha256', json_encode($encoded, JSON_THROW_ON_ERROR));
        }
        sort($hashes, SORT_STRING);
        $state[] = [$name, hash('sha256', $ddl), count($rows), $hashes];
    }
    return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
}
function nativeRequest(string $action = '', ?array $post = null, string $extraHeaders = ''): array {
    global $port, $cookie;
    $options = ['method' => $post === null ? 'GET' : 'POST', 'ignore_errors' => true, 'timeout' => 60,
        'header' => $extraHeaders . "Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => $post === null ? '' : http_build_query($post)];
    $url = 'http://127.0.0.1:' . $port . '/cms_tools.php' . ($action === '' ? '' : '?action=' . $action);
    $stream = fopen($url, 'rb', false, stream_context_create(['http' => $options]));
    if (!$stream) throw new RuntimeException('Native HTTP transport unavailable');
    $body = stream_get_contents($stream);
    $headers = stream_get_meta_data($stream)['wrapper_data'];
    fclose($stream);
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) $cookie = $match[1];
    }
    preg_match('#HTTP/\S+ (\d+)#', $headers[0], $match);
    return [(int)$match[1], $body, $headers];
}
function nativeJson(string $action, ?array $post = null): array {
    global $password;
    [$status, $body] = nativeRequest($action, $post);
    nativeCheck(!str_contains($body, $password), 'response excludes destination credentials');
    $decoded = json_decode($body, true);
    nativeCheck(is_array($decoded), 'public JSON: ' . $action);
    return [$status, $decoded];
}
try {
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false, PDO::MYSQL_ATTR_LOCAL_INFILE => false];
    $pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', $options);
    $pdo->exec("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
    $pdo->exec("SET SESSION time_zone='+00:00'");
    $pdo->exec('CREATE DATABASE restore_target CHARACTER SET utf8mb4');
    $password = bin2hex(random_bytes(20));
    $pdo->exec("CREATE USER 'restore_fixture'@'localhost' IDENTIFIED BY " . $pdo->quote($password));
    $pdo->exec("GRANT ALL PRIVILEGES ON restore_target.* TO 'restore_fixture'@'localhost'");
    $pdo->exec('USE restore_target');
    if (in_array($argv[1] ?? '', ['assurance-premise', 'assurance-reference-premise'], true)) {
        require __DIR__ . '/assurance.php';
        $exit = 0;
        return;
    }
    $pdo->exec('CREATE TABLE legacy_native (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, payload LONGBLOB NOT NULL, amount DECIMAL(20,6) NOT NULL, flag BIT(9) NOT NULL, stamp TIMESTAMP(6) NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=19');
    $pdo->exec("INSERT INTO legacy_native VALUES (0,UNHEX('4100FF27'),12.340000,b'111111111','2024-02-03 04:05:06.123456'),(7,UNHEX('E99BAA'),-9.000001,b'1',NULL)");
    $pdo->exec('CREATE TABLE unrelated_prefix (id INT NOT NULL PRIMARY KEY, value VARCHAR(64) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec("INSERT INTO unrelated_prefix VALUES (99,'persistent business data')");
    $scenario = $argv[1] ?? 'admission';
    if ($scenario === 'unsupported') $pdo->exec('ALTER TABLE legacy_native ADD unsupported FLOAT NOT NULL DEFAULT 0.125');
    if ($scenario === 'cell-quota') $pdo->exec("UPDATE legacy_native SET payload=REPEAT('x',8388609) WHERE id=7");
    $before = nativeOracle($pdo);
    $report['native_version'] = $pdo->query('SELECT VERSION()')->fetchColumn();
    $report['old_fingerprint'] = $before;
    $base = '/tmp/native-backup-' . bin2hex(random_bytes(8));
    mkdir($base, 0700);
    $site = $base . '/site';
    $private = $base . '/private';
    mkdir($site, 0700);
    mkdir($private, 0700);
    copy(dirname(__DIR__, 3) . '/cms_tools.php', $site . '/cms_tools.php');
    $report['app_sha256'] = hash_file('sha256', $site . '/cms_tools.php');
    $authority = ['password_hash' => hash('sha256', 'odc_migrate_2026_x9k' . 'FixturePassword')];
    if ($scenario === 'verify') {
        $reportToken = bin2hex(random_bytes(32));
        $authority['site_report_api_hash'] = hash('sha256', $reportToken);
    }
    file_put_contents($site . '/.migrate_config', json_encode($authority));
    $config = "<?php\ndefine('DB_HOST','localhost');\ndefine('DB_NAME','restore_target');\ndefine('DB_USER','restore_fixture');\ndefine('DB_PASSWORD'," . var_export($password, true) . ");\n\$table_prefix='wp_';\n";
    file_put_contents($site . '/wp-config.php', $config);
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $target = 'http://127.0.0.1:' . $port . '/confirmed-destination';
    [$sql] = valuesFixture('valid', $target, $base . '/BACKUP_EXECUTED');
    if (str_starts_with($scenario, 'staging')) {
        // The passive values fixture includes non-UTF8 binary cells. Give these
        // an actual binary target rather than demanding lossy UTF8 conversion.
        $sql = str_replace('CREATE TABLE `old_postmeta` (`id` BIGINT,`payload` LONGTEXT)',
            'CREATE TABLE `old_postmeta` (`id` BIGINT,`payload` LONGBLOB)', $sql);
        $sql .= "SET TIME_ZONE='+00:00';\nCREATE TABLE old_types (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, payload LONGBLOB, amount DECIMAL(20,6) NOT NULL DEFAULT -1.250000, flag BIT(9) NOT NULL DEFAULT 0x0001, stamp TIMESTAMP(6) NULL DEFAULT NULL, label VARCHAR(32) NOT NULL DEFAULT 'quote\\'slash\\\\end', PRIMARY KEY (id), KEY label_idx (label(8) DESC)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=19;\n";
        $sql .= 'INSERT INTO old_types VALUES (0,_binary' . valueQuote("A\0\x1a\\'\xff") . ",12.340000,0x01FF,'2024-02-03 04:05:06.123456','explicit'),(7,NULL,-9.000001,0x0001,NULL,'other');\n";
    }
    if ($scenario === 'staging-utf8') $sql = str_replace('CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'CHARSET=utf8 COLLATE=utf8_unicode_ci', $sql);
    if ($scenario === 'staging-collation') $sql = str_replace('utf8mb4_unicode_ci', 'utf8mb4_future_fixture_ci', $sql);
    file_put_contents($site . '/db_fixture.sql.gz', gzencode($sql));
    $zip = new ZipArchive();
    $zip->open($site . '/hosting_fixture.zip', ZipArchive::CREATE | ZipArchive::EXCL);
    $zip->addFromString('index.php', '<?php file_put_contents(' . var_export($base . '/BACKUP_EXECUTED', true) . ',"bad");');
    $zip->addFromString('wp-settings.php', 'Inert synthetic core fixture');
    $zip->addFromString('wp-load.php', 'Inert synthetic core fixture');
    $zip->addFromString('wp-includes/version.php', "<?php \$wp_version='6.8.3';");
    $zip->close();
    $originals = [];
    foreach (['cms_tools.php', 'wp-config.php', 'hosting_fixture.zip', 'db_fixture.sql.gz'] as $name) $originals[$name] = hash_file('sha256', $site . '/' . $name);
    putenv('TMPDIR=' . $private);
    $serverCommand = [PHP_BINARY, '-d', 'pdo_mysql.default_socket=/run/mysqld/mysqld.sock',
        '-d', 'mysqli.default_socket=/run/mysqld/mysqld.sock', '-d', 'session.save_path=' . $private,
        '-S', '127.0.0.1:' . $port, '-t', $site];
    if ($scenario === 'verify') {
        // Trusted fixture server context only, NOT a TLS transport certificate.
        $router = $base . '/trusted-router.php';
        file_put_contents($router, "<?php \$_SERVER['HTTPS']='on'; require __DIR__.'/site/cms_tools.php';\n");
        $serverCommand[] = $router;
        $report['trusted_https_context'] = true;
        $report['tls_transport_verified'] = false;
    }
    $server = proc_open($serverCommand,
        [0 => ['pipe', 'r'], 1 => ['file', $base . '/server.log', 'a'], 2 => ['file', $base . '/server.log', 'a']], $pipes);
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        if ($probe = @fsockopen('127.0.0.1', $port)) { fclose($probe); $ready = true; break; }
        usleep(20000);
    }
    nativeCheck($ready, 'owned loopback PHP server ready');
    $cookie = '';
    nativeRequest('', ['password' => 'FixturePassword']);
    [$status, $j] = nativeJson('wp_restore_discover');
    $report['discovery'] = ['status' => $status, 'eligible' => $j['eligible'] ?? null,
        'reason' => $j['reason'] ?? ($j['error'] ?? null)];
    $report['hosting_inventory'] = array_values(array_diff(scandir($site), ['.', '..']));
    nativeCheck($status === 200 && ($j['eligible'] ?? false), 'native config-only candidate admitted');
    $csrf = $j['csrf'];
    [$status, $j] = nativeJson('wp_restore_prepare', ['csrf' => $csrf, 'archive' => 'hosting_fixture.zip', 'database' => 'db_fixture.sql.gz', 'target_url' => $target]);
    nativeCheck($status === 201, 'native prepared run admitted');
    $run = $j['run'];
    foreach (['files', 'sql', 'values'] as $operation) {
        for ($i = 0; $i < 128 && !($run['preflight'][$operation]['ready'] ?? false); $i++) {
            [$status, $j] = nativeJson('wp_restore_step', ['csrf' => $csrf, 'run_id' => $run['run_id'],
                'stage' => 'preflight', 'operation' => $operation, 'revision' => $run['revision']]);
            nativeCheck($status === 200, 'accepted prerequisite step ' . $operation);
            $run = $j['run'];
        }
        nativeCheck($run['preflight'][$operation]['ready'], 'native prerequisite ready ' . $operation);
    }
    $report['old_state_unchanged'] = nativeOracle($pdo) === $before;
    nativeCheck($report['old_state_unchanged'], 'all old tables, bytes, schemas and AUTO_INCREMENT unchanged by private preparation');
    nativeCheck(!file_exists($site . '/NUL'), 'native POSIX lookup leaves no null-device artifact');
    foreach ($originals as $name => $sha) nativeCheck(hash_file('sha256', $site . '/' . $name) === $sha, 'original bytes preserved ' . $name);
    nativeCheck(!file_exists($base . '/BACKUP_EXECUTED') && !file_exists($site . '/index.php'), 'no backup PHP execution or public placement');
    nativeCheck(!$run['backup_verified'] && !$run['runtime_verified'] && !$run['replacement_enabled'],
        'private preparation does not claim backup, runtime or replacement verification');
    if ($scenario === 'verify') {
        $savedCookie = $cookie; $cookie = '';
        [$referenceStatus, $reference] = nativeJson('wp_restore_step', []);
        [$deniedStatus, $denied] = nativeJson('wp_restore_backup_download', ['csrf' => $csrf, 'run_id' => $run['run_id']]);
        nativeCheck($referenceStatus === 401 && $deniedStatus === 401 && $denied === $reference, 'backup download preserves exact anonymous-session denial');
        $cookie = '';
        [$tokenStatus, $tokenBody] = nativeRequest('site_report_api', null, "Authorization: Bearer $reportToken\r\n");
        nativeCheck($tokenStatus === 200 && (json_decode($tokenBody, true)['schema'] ?? '') === 'cms-tools-site-report', 'report-only token is genuinely valid without restore session');
        [$deniedStatus, $deniedBody] = nativeRequest('wp_restore_backup_download', ['csrf' => $csrf, 'run_id' => $run['run_id']], "Authorization: Bearer $reportToken\r\n");
        nativeCheck($deniedStatus === 401 && json_decode($deniedBody, true) === $reference, 'valid report-only token cannot authorize private backup download');
        $cookie = $savedCookie;
        [$referenceStatus, $reference] = nativeJson('wp_restore_step');
        [$deniedStatus, $deniedBody, $deniedHeaders] = nativeRequest('wp_restore_backup_download');
        nativeCheck($referenceStatus === 405 && $deniedStatus === 405 && json_decode($deniedBody, true) === $reference
            && in_array('Allow: POST', $deniedHeaders, true), 'backup download preserves exact POST-only contract and Allow header');
        [$referenceStatus, $reference] = nativeJson('wp_restore_step', ['csrf' => 'wrong']);
        [$deniedStatus, $denied] = nativeJson('wp_restore_backup_download', ['csrf' => 'wrong', 'run_id' => $run['run_id']]);
        nativeCheck($referenceStatus === 403 && $deniedStatus === 403 && $denied === $reference, 'backup download preserves independent CSRF denial');
        [$deniedStatus, $denied] = nativeJson('wp_restore_backup_download', ['csrf' => $csrf, 'run_id' => str_repeat('0', 64)]);
        nativeCheck($deniedStatus === 404 && $denied === ['error' => 'Restore run not found.'], 'backup download rejects foreign run without exposing artifact');
        [$stateStatus, $state] = nativeJson('wp_restore_state');
        nativeCheck($stateStatus === 200 && $state['run'] === $run && nativeOracle($pdo) === $before,
            'all access denials preserve exact state, counters and old data');
    }
    if (in_array($scenario, ['lock-contention', 'unsupported', 'grant-refusal', 'cell-quota'], true)) {
        if ($scenario === 'lock-contention') $pdo->exec('LOCK TABLES legacy_native WRITE, unrelated_prefix WRITE');
        if ($scenario === 'grant-refusal') {
            $pdo->exec("REVOKE ALL PRIVILEGES ON restore_target.* FROM 'restore_fixture'@'localhost'");
            $pdo->exec("GRANT SELECT, LOCK TABLES ON restore_target.* TO 'restore_fixture'@'localhost'");
        }
        try {
            [$failedStatus, $failed] = nativeJson('wp_restore_step', ['csrf' => $csrf, 'run_id' => $run['run_id'],
                'stage' => 'preflight', 'operation' => 'backup', 'revision' => $run['revision']]);
        } finally { if ($scenario === 'lock-contention') $pdo->exec('UNLOCK TABLES'); }
        nativeCheck($failedStatus === 400 && $failed === ['error' => 'Prior database backup failed. The tool did not modify existing tables.', 'code' => 'prior_backup_failed'], 'unsupported, unobservable, over-quota or locked capture fails closed exactly');
        [$stateStatus, $state] = nativeJson('wp_restore_state');
        $terminal = $state['run'];
        nativeCheck($stateStatus === 200 && $terminal['revision'] === $run['revision'] + 1
            && $terminal['preflight']['backup']['status'] === 'capturing' && !$terminal['preflight']['backup']['ready']
            && !$terminal['backup_verified'], 'failed capture retains intent checkpoint without readiness or verification');
        [$retryStatus, $retry] = nativeJson('wp_restore_step', ['csrf' => $csrf, 'run_id' => $run['run_id'],
            'stage' => 'preflight', 'operation' => 'backup', 'revision' => $terminal['revision']]);
        [$stateStatus, $state] = nativeJson('wp_restore_state');
        nativeCheck($retryStatus === 400 && $retry === $failed && $stateStatus === 200 && $state['run'] === $terminal,
            'terminal capture never blindly replays or advances checkpoint and counters');
        [$deniedDownload] = nativeJson('wp_restore_backup_download', ['csrf' => $csrf, 'run_id' => $run['run_id']]);
        nativeCheck($deniedDownload === 409 && nativeOracle($pdo) === $before, 'incomplete capture is not downloadable and OLD data, schema and counters remain exact');
        foreach ($originals as $name => $sha) nativeCheck(hash_file('sha256', $site . '/' . $name) === $sha, 'failed capture preserves original ' . $name);
    }
    if (in_array($scenario, ['capture', 'verify', 'artifact-tamper', 'catalog-tamper'], true) || str_starts_with($scenario, 'staging')) {
        $prior = $run;
        [$status, $j] = nativeJson('wp_restore_step', ['csrf' => $csrf, 'run_id' => $run['run_id'],
            'stage' => 'preflight', 'operation' => 'backup', 'revision' => $run['revision']]);
        $report['capture_status'] = $status;
        nativeCheck(nativeOracle($pdo) === $before, 'prior capture leaves all OLD rows, schemas and AUTO_INCREMENT unchanged');
        foreach ($originals as $name => $sha) nativeCheck(hash_file('sha256', $site . '/' . $name) === $sha, 'capture preserves original ' . $name);
        if ($status !== 200) {
            [$stateStatus, $state] = nativeJson('wp_restore_state');
            nativeCheck($stateStatus === 200 && $state['run'] === $prior, 'rejected operation leaves exact checkpoint and counters unchanged');
        }
        nativeCheck($status === 200, 'whole-old-database capture accepted; observed HTTP ' . $status);
        $run = $j['run'];
        nativeCheck(($run['preflight']['backup']['ready'] ?? false) === true && !$run['backup_verified'],
            'private capture readiness is not recovery verification');
        nativeCheck($run['preflight']['backup']['tables'] === 2 && $run['preflight']['backup']['rows'] === 3,
            'prior snapshot covers whole OLD schema, including unrelated prefixes');
        [$downloadStatus, $backupBytes] = nativeRequest('wp_restore_backup_download', ['csrf' => $csrf, 'run_id' => $run['run_id']]);
        nativeCheck($downloadStatus === 200, 'authenticated prior-backup download accepted; observed HTTP ' . $downloadStatus);
        nativeCheck(hash('sha256', $backupBytes) === $run['preflight']['backup']['sha256'], 'download matches generated compressed-byte SHA');
        $recoverySql = gzdecode($backupBytes);
        nativeCheck(is_string($recoverySql), 'standard gzip recovery artifact decodes');
        $pdo->exec('CREATE DATABASE prior_recovery CHARACTER SET utf8mb4');
        $recover = proc_open(['mariadb', '--no-defaults', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'prior_recovery'],
            [0 => ['pipe', 'r'], 1 => ['file', $base . '/recovery.stdout', 'w'], 2 => ['file', $base . '/recovery.stderr', 'w']], $recoveryPipes);
        nativeCheck(is_resource($recover), 'independent standard SQL recovery client started');
        $offset = 0;
        while ($offset < strlen($recoverySql)) {
            $written = fwrite($recoveryPipes[0], substr($recoverySql, $offset));
            if (!$written) throw new RuntimeException('Recovery input failed');
            $offset += $written;
        }
        fclose($recoveryPipes[0]);
        nativeCheck(proc_close($recover) === 0 && filesize($base . '/recovery.stderr') === 0, 'standard client restores artifact into separate database without errors');
        $recovered = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=prior_recovery;charset=utf8mb4', 'root', '', $options);
        $recovered->exec("SET SESSION time_zone='+00:00'");
        nativeCheck(nativeOracle($recovered) === $before, 'external recovery reproduces exact whole-old-schema, bytes, zero IDs and AUTO_INCREMENT');
        nativeCheck(nativeOracle($pdo) === $before, 'external recovery never modifies original destination');
        nativeCheck(!$run['backup_verified'], 'external fixture recovery is not falsely recorded as app verification');
        if (in_array($scenario, ['artifact-tamper', 'catalog-tamper'], true)) {
            $dirs = glob($private . '/cms-wp-restore-*', GLOB_ONLYDIR);
            nativeCheck(count($dirs) === 1, 'one private run in exact owned native TMPDIR');
            $owner = json_decode(file_get_contents($dirs[0] . '/owner.json'), true, 64, JSON_THROW_ON_ERROR);
            nativeCheck($owner['run_id'] === $run['run_id'], 'tamper target is bound to exact owner.run_id');
            $path = $dirs[0] . ($scenario === 'artifact-tamper' ? '/prior-database.sql.gz' : '/prior-catalog.json');
            $bytes = file_get_contents($path); $bytes[0] = chr(ord($bytes[0]) ^ 1);
            file_put_contents($path, $bytes);
            [$tamperStatus, $tamper] = nativeJson('wp_restore_backup_download', ['csrf' => $csrf, 'run_id' => $run['run_id']]);
            nativeCheck($tamperStatus === 409 && $tamper === ['error' => 'Prior backup artifact changed or is unavailable.'], 'changed generated artifact or catalog is rejected before bytes are emitted');
            [$claimStatus, $claim] = nativeJson('wp_restore_step', ['csrf' => $csrf, 'run_id' => $run['run_id'],
                'stage' => 'preflight', 'operation' => 'backup_confirm', 'revision' => $run['revision'],
                'artifact_sha256' => $run['preflight']['backup']['sha256'], 'recovery_method' => 'separate_database_schema_rows_counters',
                'recovery_confirmed' => '1', 'replace_all_tables_confirmed' => '1']);
            nativeCheck($claimStatus === 400 && $claim === ['error' => 'Prior recovery confirmation failed. No table replacement was performed.', 'code' => 'prior_confirmation_failed'], 'operator declaration cannot bless changed private output');
            [$stateStatus, $state] = nativeJson('wp_restore_state');
            nativeCheck($stateStatus === 200 && $state['run'] === $run && nativeOracle($pdo) === $before, 'tamper rejection preserves exact checkpoint, flags, counters and OLD data');
        }
        if ($scenario === 'verify' || str_starts_with($scenario, 'staging')) {
            $proof = ['artifact_sha256' => $run['preflight']['backup']['sha256'],
                'recovery_method' => 'separate_database_schema_rows_counters',
                'recovery_confirmed' => '1', 'replace_all_tables_confirmed' => '1'];
            $invalid = [array_diff_key($proof, ['artifact_sha256' => true]),
                array_replace($proof, ['artifact_sha256' => str_repeat('0', 64)]),
                array_replace($proof, ['recovery_method' => 'checksum_only']),
                array_replace($proof, ['recovery_confirmed' => '0']),
                array_replace($proof, ['replace_all_tables_confirmed' => '0'])];
            foreach ($invalid as $index => $fields) {
                [$badStatus, $bad] = nativeJson('wp_restore_step', $fields + ['csrf' => $csrf, 'run_id' => $run['run_id'],
                    'stage' => 'preflight', 'operation' => 'backup_confirm', 'revision' => $run['revision']]);
                nativeCheck($badStatus === 400 && $bad === ['error' => 'Prior recovery confirmation failed. No table replacement was performed.', 'code' => 'prior_confirmation_failed'], 'unverified, wrong-artifact or incomplete recovery claim rejected exactly ' . $index);
                [$stateStatus, $state] = nativeJson('wp_restore_state');
                nativeCheck($stateStatus === 200 && $state['run'] === $run && nativeOracle($pdo) === $before,
                    'rejected confirmation leaves exact checkpoint, counters and old data unchanged ' . $index);
            }
            [$verifiedStatus, $verified] = nativeJson('wp_restore_step', $proof + ['csrf' => $csrf, 'run_id' => $run['run_id'],
                'stage' => 'preflight', 'operation' => 'backup_confirm', 'revision' => $run['revision']]);
            nativeCheck($verifiedStatus === 200 && $verified['run']['backup_verified'] === true,
                'explicit artifact-bound externally tested recovery declaration accepted');
            nativeCheck($verified['run']['preflight']['backup']['provenance'] === 'operator_verified_external_backup'
                && $verified['run']['preflight']['backup']['app_recovery_verified'] === false,
                'operator declaration is truthfully distinguished from app recovery certification');
            nativeCheck(!$verified['run']['runtime_verified'] && !$verified['run']['replacement_enabled'] && nativeOracle($pdo) === $before,
                'confirmation alone does not import, replace tables or certify runtime');
            if (str_starts_with($scenario, 'staging')) {
                $run = $verified['run'];
                require __DIR__ . '/import.php';
            }
        }
    }
    $exit = 0;
} catch (Throwable $error) {
    // Only our fixed contract labels are rendered; no PDO exception/SQL/credential chain.
    $message = $error instanceof PDOException ? 'native fixture SQL setup/oracle failed' : $error->getMessage();
    $report['failure'] = $message;
    fwrite(STDERR, 'FAIL ' . $message . "\n");
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    file_put_contents('/tmp/admission-contract.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
exit($exit);
