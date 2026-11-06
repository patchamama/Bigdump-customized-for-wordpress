<?php
// Synthetic SQL premise only. Never load WordPress, app code or arbitrary SQL.
$report = ['phase' => 'bootstrap', 'checks' => [], 'outcomes' => array_fill_keys(
    ['privacy', 'comparison', 'competing_mutations', 'invalid_rename', 'valid_rename', 'journal', 'transferred_exclusion', 'external_ack'], 'NOTRUN')];
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5,
    PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => false, PDO::MYSQL_ATTR_LOCAL_INFILE => false,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false];
$dsn = 'mysql:unix_socket=/var/run/mysqld/fixture.sock;charset=utf8mb4';
$primary = null;
$locked = false;
$advisory = false;
$exit = 1;
function check(bool $ok, string $label): void {
    global $report;
    $report['checks'][] = ['label' => $label, 'pass' => $ok];
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}
function attempt(PDO $pdo, string $label, string $sql): ?int {
    global $report;
    try {
        $pdo->exec($sql);
        $report['attempts'][$label] = ['success' => true];
        return null;
    } catch (PDOException $error) {
        $code = (int)($error->errorInfo[1] ?? 0);
        $report['attempts'][$label] = ['success' => false, 'sqlstate' => $error->getCode(), 'driver_code' => $code];
        return $code;
    }
}
function fingerprint(PDO $pdo, string $name): array {
    check(in_array($name, ['m84_expected','m84_new','m84_old','m84_control','m84_taken','m84_rollback'], true), 'fingerprint target is owned synthetic name');
    $q = '`' . $name . '`';
    $ddl = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM)[1];
    $prefix = 'CREATE ' . ($name === 'm84_expected' ? 'TEMPORARY ' : '') . 'TABLE ' . $q;
    check(str_starts_with($ddl, $prefix . ' ('), 'DDL begins with exact known own identifier and temporary marker');
    $ddl = 'CREATE TABLE `m84_shape`' . substr($ddl, strlen($prefix));
    $columns = $pdo->query('SHOW FULL COLUMNS FROM ' . $q)->fetchAll(PDO::FETCH_ASSOC);
    $select = [];
    foreach ($columns as $column) {
        $field = '`' . str_replace('`', '``', $column['Field']) . '`';
        $select[] = 'CAST(' . $field . (str_starts_with($column['Type'], 'bit(') ? ' AS UNSIGNED)' : ' AS BINARY)');
    }
    $rows = $pdo->query('SELECT ' . implode(',', $select) . ' FROM ' . $q)->fetchAll(PDO::FETCH_NUM);
    $hashes = [];
    foreach ($rows as $row) {
        $cells = array_map(static fn($v) => $v === null ? null : base64_encode((string)$v), $row);
        $hashes[] = hash('sha256', json_encode($cells, JSON_THROW_ON_ERROR));
    }
    sort($hashes, SORT_STRING);
    return ['schema_sha256' => hash('sha256', $ddl), 'rows' => count($hashes),
        'data_sha256' => hash('sha256', implode("\n", $hashes))];
}
function inventory(PDO $pdo): array {
    $state = [];
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$name, $kind]) {
        check($kind === 'BASE TABLE', 'inventory contains only ordinary synthetic tables');
        $state[$name] = fingerprint($pdo, $name);
    }
    ksort($state);
    return $state;
}
try {
    $bootstrap = new PDO($dsn, 'root', '', $options);
    $password = bin2hex(random_bytes(24));
    $bootstrap->exec('ALTER USER \'root\'@\'localhost\' IDENTIFIED BY ' . $bootstrap->quote(bin2hex(random_bytes(24))));
    $bootstrap->exec('CREATE DATABASE fixture84 CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    $bootstrap->exec("CREATE USER 'restore_fixture'@'localhost' IDENTIFIED BY " . $bootstrap->quote($password));
    $bootstrap->exec("GRANT ALL PRIVILEGES ON fixture84.* TO 'restore_fixture'@'localhost'");
    $bootstrap = null;
    $primary = new PDO($dsn . ';dbname=fixture84', 'restore_fixture', $password, $options);
    $peer = new PDO($dsn . ';dbname=fixture84', 'restore_fixture', $password, $options);
    unset($password);
    foreach ([$primary, $peer] as $pdo) {
        $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION,NO_BACKSLASH_ESCAPES'");
        $pdo->exec("SET SESSION time_zone='+00:00'");
        $pdo->exec('SET SESSION lock_wait_timeout=1');
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
    }
    $report['profile'] = $primary->query('SELECT VERSION() AS version,CURRENT_USER() AS account,@@autocommit AS autocommit,@@innodb_table_locks AS innodb_table_locks,@@sql_mode AS sql_mode,@@time_zone AS time_zone,@@lock_wait_timeout AS lock_wait_timeout,@@innodb_lock_wait_timeout AS innodb_lock_wait_timeout')->fetch(PDO::FETCH_ASSOC);
    $report['peer_account'] = $peer->query('SELECT CURRENT_USER()')->fetchColumn();
    check(str_starts_with($report['profile']['version'], '8.4.') && $report['profile']['account'] === 'restore_fixture@localhost'
        && $report['peer_account'] === 'restore_fixture@localhost', 'actual MySQL84 and both admitted normal accounts verified');
    $report['phase'] = 'privacy';
    check(inventory($primary) === [], 'private reference starts without real objects to shadow');
    $shape = " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,payload LONGBLOB NULL,amount DECIMAL(20,6) NOT NULL,flag BIT(9) NOT NULL,stamp TIMESTAMP(6) NULL DEFAULT NULL,label VARCHAR(40) NOT NULL DEFAULT 'literal-default',KEY label_idx (label(8))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=19";
    $rows = " VALUES (0,UNHEX('4100FF27'),12.340000,b'111111111','2024-02-03 04:05:06.123456','explicit'),(7,NULL,-9.000001,b'1',NULL,'other')";
    $primary->exec('CREATE TEMPORARY TABLE m84_expected' . $shape);
    $primary->exec('INSERT INTO m84_expected' . $rows);
    $typed = $primary->query('SELECT id,HEX(payload),amount,CAST(flag AS UNSIGNED),stamp,label FROM m84_expected ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    check($typed === [['0','4100FF27','12.340000','511','2024-02-03 04:05:06.123456','explicit'],
        ['7',null,'-9.000001','1',null,'other']], 'independent reference preserves known zero ID binary NULL decimal BIT UTC values');
    check(inventory($primary) === [], 'temporary expected reference has no real-name shadow');
    check(attempt($peer, 'private_read', 'SELECT * FROM m84_expected') === 1146, 'peer cannot read private reference');
    check(attempt($peer, 'private_write', 'INSERT INTO m84_expected (id) VALUES (99)') === 1146, 'peer cannot write private reference');
    $report['outcomes']['privacy'] = 'PASS';
    $primary->exec('CREATE TABLE m84_new' . $shape);
    $primary->exec('INSERT INTO m84_new' . $rows);
    $primary->exec('CREATE TABLE m84_old' . $shape);
    $primary->exec("INSERT INTO m84_old VALUES (5,UNHEX('0102'),1.000000,b'1',NULL,'retained-old')");
    $primary->exec('CREATE TABLE m84_control (id INT PRIMARY KEY,phase VARCHAR(8) NOT NULL) ENGINE=InnoDB');
    $primary->exec("INSERT INTO m84_control VALUES (1,'pending')");
    $primary->exec('CREATE TABLE m84_taken (id INT PRIMARY KEY) ENGINE=InnoDB');
    $report['engines'] = $primary->query('SELECT DISTINCT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    check($report['engines'] === ['InnoDB'], 'all real participants explicitly use InnoDB');
    $old = fingerprint($primary, 'm84_old');
    $stmt = $primary->prepare('SELECT GET_LOCK(?,0)'); $stmt->execute(['mysql84-owned-premise']);
    check((int)$stmt->fetchColumn() === 1, 'cooperative advisory lock acquired'); $stmt->closeCursor();
    $advisory = true;
    $primary->exec('SET autocommit=0');
    $report['locked_autocommit'] = $primary->query('SELECT @@autocommit')->fetchColumn();
    check($report['locked_autocommit'] === '0', 'autocommit disabled before explicit locks without START TRANSACTION');
    $report['phase'] = 'comparison';
    $primary->exec('LOCK TABLES m84_old WRITE,m84_new WRITE,m84_control WRITE,m84_taken WRITE');
    $locked = true;
    $expected = fingerprint($primary, 'm84_expected');
    check(fingerprint($primary, 'm84_new') === $expected && fingerprint($primary, 'm84_old') === $old, 'locked NEW equals independent reference and OLD remains exact');
    $report['expected'] = $expected;
    $report['outcomes']['comparison'] = 'PASS';
    $report['phase'] = 'competing_mutations';
    foreach ([['new_write', "UPDATE m84_new SET label='changed' WHERE id=7"], ['old_alter', 'ALTER TABLE m84_old ADD changed INT'],
        ['control_write', "UPDATE m84_control SET phase='changed' WHERE id=1"]] as [$label, $sql]) {
        check(attempt($peer, $label, $sql) === 1205, 'bounded competitor ' . $label . ' blocked');
    }
    $report['outcomes']['competing_mutations'] = 'PASS';
    $report['phase'] = 'invalid_rename';
    $before = inventory($primary);
    $invalid = attempt($primary, 'invalid_rename', 'RENAME TABLE m84_old TO m84_rollback,m84_new TO m84_taken');
    check(inventory($primary) === $before, 'failed rename preserves every name schema row and AUTO_INCREMENT');
    check($invalid === 1050, 'invalid destination yields collision 1050 not unrelated locked-operation refusal');
    $report['outcomes']['invalid_rename'] = 'PASS';
    $report['phase'] = 'valid_rename';
    check(attempt($primary, 'valid_rename', 'RENAME TABLE m84_old TO m84_rollback,m84_new TO m84_old') === null, 'one valid multi-rename succeeds without unlock');
    check(fingerprint($primary, 'm84_old') === $expected && fingerprint($primary, 'm84_rollback') === $old, 'final NEW and retained rollback OLD fingerprints remain exact');
    $report['outcomes']['valid_rename'] = 'PASS';
    $report['phase'] = 'journal';
    check($primary->exec("UPDATE m84_control SET phase='done' WHERE id=1 AND phase='pending'") === 1, 'known journal acknowledgement affected exactly one row');
    $primary->exec('COMMIT');
    $report['outcomes']['journal'] = 'PASS';
    $report['phase'] = 'transferred_exclusion';
    check(attempt($peer, 'renamed_final_write', "UPDATE m84_old SET label='changed' WHERE id=7") === 1205, 'renamed final WRITE exclusion survives COMMIT');
    check(attempt($peer, 'rollback_alter', 'ALTER TABLE m84_rollback ADD changed INT') === 1205, 'retained rollback WRITE exclusion survives COMMIT');
    check(fingerprint($primary, 'm84_old') === $expected && fingerprint($primary, 'm84_rollback') === $old, 'protected final and retained OLD remain exact after competing attempts');
    $report['outcomes']['transferred_exclusion'] = 'PASS';
    $primary->exec('UNLOCK TABLES'); $locked = false;
    $report['phase'] = 'external_ack';
    check($peer->query('SELECT phase FROM m84_control WHERE id=1')->fetchColumn() === 'done', 'separate normal connection observes committed journal after release');
    $report['outcomes']['external_ack'] = 'PASS';
    $report['phase'] = 'complete';
    $exit = 0;
} catch (Throwable $error) {
    $report['failure'] = ['class' => get_class($error), 'sqlstate' => $error instanceof PDOException ? $error->getCode() : null,
        'driver_code' => $error instanceof PDOException ? ($error->errorInfo[1] ?? null) : null];
    if (isset($report['outcomes'][$report['phase']])) $report['outcomes'][$report['phase']] = 'FAIL';
    fwrite(STDERR, 'FAIL premise phase=' . $report['phase'] . "\n");
} finally {
    try {
        if ($locked) $primary->exec('UNLOCK TABLES');
        if ($advisory) {
            $stmt = $primary->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute(['mysql84-owned-premise']); $stmt->closeCursor();
        }
    } catch (Throwable $error) { $report['release_failed'] = true; $exit = 1; }
    echo json_encode($report, JSON_THROW_ON_ERROR) . "\n";
}
exit($exit);
