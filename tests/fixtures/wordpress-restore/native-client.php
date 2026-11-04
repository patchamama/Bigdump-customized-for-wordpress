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
function nativeOracle(PDO $pdo): string {
    $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    usort($tables, static fn($a, $b) => strcmp($a[0], $b[0]));
    $state = [];
    foreach ($tables as [$name, $kind]) {
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
function nativeRequest(string $action = '', ?array $post = null): array {
    global $port, $cookie;
    $options = ['method' => $post === null ? 'GET' : 'POST', 'ignore_errors' => true, 'timeout' => 60,
        'header' => "Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",
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
    return [(int)$match[1], $body];
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
    $pdo->exec('CREATE TABLE legacy_native (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, payload LONGBLOB NOT NULL, amount DECIMAL(20,6) NOT NULL, flag BIT(9) NOT NULL, stamp TIMESTAMP(6) NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=19');
    $pdo->exec("INSERT INTO legacy_native VALUES (0,UNHEX('4100FF27'),12.340000,b'111111111','2024-02-03 04:05:06.123456'),(7,UNHEX('E99BAA'),-9.000001,b'1',NULL)");
    $pdo->exec('CREATE TABLE unrelated_prefix (id INT NOT NULL PRIMARY KEY, value VARCHAR(64) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec("INSERT INTO unrelated_prefix VALUES (99,'persistent business data')");
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
    file_put_contents($site . '/.migrate_config', json_encode(['password_hash' => hash('sha256', 'odc_migrate_2026_x9k' . 'FixturePassword')]));
    $config = "<?php\ndefine('DB_HOST','localhost');\ndefine('DB_NAME','restore_target');\ndefine('DB_USER','restore_fixture');\ndefine('DB_PASSWORD'," . var_export($password, true) . ");\n\$table_prefix='wp_';\n";
    file_put_contents($site . '/wp-config.php', $config);
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $target = 'http://127.0.0.1:' . $port . '/confirmed-destination';
    [$sql] = valuesFixture('valid', $target, $base . '/BACKUP_EXECUTED');
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
    $server = proc_open([PHP_BINARY, '-d', 'pdo_mysql.default_socket=/run/mysqld/mysqld.sock',
        '-d', 'mysqli.default_socket=/run/mysqld/mysqld.sock', '-d', 'session.save_path=' . $private,
        '-S', '127.0.0.1:' . $port, '-t', $site],
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
