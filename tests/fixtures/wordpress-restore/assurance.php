<?php
if (($argv[1] ?? '') === 'assurance-reference-premise') {
    // A distinct owner-authorized hypothesis; never dispatch to the old CREATE probe.
    $report['reference_observations'] = array_fill_keys(
        ['privacy', 'locked_comparison', 'competing_mutations', 'invalid_rename', 'valid_rename', 'journal_ack'], 'NOTRUN');
    $report['reference_phase'] = 'connect';
    $dsn = 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=restore_target;charset=utf8mb4';
    $probeOptions = $options + [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_STRINGIFY_FETCHES => true,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false];
    $primary = new PDO($dsn, 'restore_fixture', $password, $probeOptions);
    $secondary = new PDO($dsn, 'restore_fixture', $password, $probeOptions);
    foreach ([$primary, $secondary] as $connection) {
        $connection->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION,NO_BACKSLASH_ESCAPES'");
        $connection->exec("SET SESSION time_zone='+00:00'");
        $connection->exec('SET SESSION lock_wait_timeout=1');
        $connection->exec('SET SESSION innodb_lock_wait_timeout=1');
    }
    $report['reference_profile'] = $primary->query('SELECT VERSION() AS version, CURRENT_USER() AS account, @@autocommit AS autocommit, @@innodb_table_locks AS innodb_table_locks, @@sql_mode AS sql_mode, @@time_zone AS time_zone, @@lock_wait_timeout AS lock_wait_timeout, @@innodb_lock_wait_timeout AS innodb_lock_wait_timeout')->fetch(PDO::FETCH_ASSOC);
    $report['reference_secondary_account'] = $secondary->query('SELECT CURRENT_USER()')->fetchColumn();
    nativeCheck($report['reference_profile']['account'] === 'restore_fixture@localhost'
        && $report['reference_secondary_account'] === 'restore_fixture@localhost', 'both probe connections use admitted normal account');
    $attempt = static function (PDO $connection, string $label, string $sql): ?int {
        global $report;
        try {
            $connection->exec($sql);
            $report['reference_attempts'][$label] = ['success' => true];
            return null;
        } catch (PDOException $error) {
            $code = (int)($error->errorInfo[1] ?? 0);
            $report['reference_attempts'][$label] = ['success' => false,
                'sqlstate' => $error->getCode(), 'driver_code' => $code];
            return $code;
        }
    };
    $inventory = static function () use ($primary): array {
        $names = $primary->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        sort($names);
        return $names;
    };
    $fingerprint = static function (string $name) use ($primary): array {
        $ddl = $primary->query('SHOW CREATE TABLE ' . nativeQuote($name))->fetch(PDO::FETCH_NUM)[1];
        // Only the known leading own identifier and TEMPORARY marker are normalized.
        $prefix = 'CREATE ' . ($name === 'reference_probe_expected' ? 'TEMPORARY ' : '') . 'TABLE ' . nativeQuote($name);
        nativeCheck(str_starts_with($ddl, $prefix . ' ('), 'SHOW CREATE has exact known own identifier prefix');
        $ddl = 'CREATE TABLE `reference_probe_shape`' . substr($ddl, strlen($prefix));
        $rows = $primary->query('SELECT CAST(id AS BINARY),CAST(payload AS BINARY),CAST(amount AS BINARY),CAST(flag AS UNSIGNED),CAST(stamp AS BINARY),CAST(label AS BINARY) FROM ' . nativeQuote($name))->fetchAll(PDO::FETCH_NUM);
        $hashes = [];
        foreach ($rows as $row) {
            $cells = array_map(static fn($value) => $value === null ? null : base64_encode((string)$value), $row);
            $hashes[] = hash('sha256', json_encode($cells, JSON_THROW_ON_ERROR));
        }
        sort($hashes, SORT_STRING);
        return ['schema_sha256' => hash('sha256', $ddl), 'rows' => count($hashes),
            'data_sha256' => hash('sha256', implode("\n", $hashes))];
    };
    $report['reference_phase'] = 'private_reference';
    nativeCheck($inventory() === [], 'synthetic probe begins with no real tables to shadow');
    $shape = " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, payload LONGBLOB NULL, amount DECIMAL(20,6) NOT NULL, flag BIT(9) NOT NULL, stamp TIMESTAMP(6) NULL DEFAULT NULL, label VARCHAR(40) NOT NULL DEFAULT 'literal-default', KEY label_idx (label(8))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=19";
    $values = " VALUES (0,UNHEX('4100FF27'),12.340000,b'111111111','2024-02-03 04:05:06.123456','explicit'),(7,NULL,-9.000001,b'1',NULL,'other')";
    $primary->exec('CREATE TEMPORARY TABLE reference_probe_expected' . $shape);
    $primary->exec('INSERT INTO reference_probe_expected' . $values);
    nativeCheck($inventory() === [], 'private reference does not create or shadow a real object');
    nativeCheck($attempt($secondary, 'reference_read', 'SELECT * FROM reference_probe_expected') === 1146,
        'second connection cannot read session-private expected reference');
    nativeCheck($attempt($secondary, 'reference_write', 'INSERT INTO reference_probe_expected (id) VALUES (99)') === 1146,
        'second connection cannot mutate session-private expected reference');
    $report['reference_observations']['privacy'] = 'PASS';
    // Independently populate actual NEW before locking; do not derive expected from readback.
    $primary->exec('CREATE TABLE reference_probe_new' . $shape);
    $primary->exec('INSERT INTO reference_probe_new' . $values);
    $primary->exec('CREATE TABLE reference_probe_old (id INT PRIMARY KEY) ENGINE=InnoDB');
    $primary->exec('INSERT INTO reference_probe_old VALUES (3)');
    $primary->exec('CREATE TABLE reference_probe_control (id INT PRIMARY KEY, phase VARCHAR(8) NOT NULL) ENGINE=InnoDB');
    $primary->exec("INSERT INTO reference_probe_control VALUES (1,'pending')");
    $primary->exec('CREATE TABLE reference_probe_taken (id INT PRIMARY KEY) ENGINE=InnoDB');
    $report['reference_engines'] = $primary->query('SELECT DISTINCT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    nativeCheck($report['reference_engines'] === ['InnoDB'], 'all real synthetic objects use InnoDB');
    $stmt = $primary->prepare('SELECT GET_LOCK(?,0)');
    $stmt->execute(['reference-probe-only']);
    nativeCheck((int)$stmt->fetchColumn() === 1, 'cooperative synthetic advisory lock acquired');
    $stmt->closeCursor();
    $locked = false;
    try {
        $report['reference_phase'] = 'locked_comparison';
        // MariaDB temporary tables are WRITE-locked implicitly; only existing real objects listed.
        nativeCheck($attempt($primary, 'lock_existing', 'LOCK TABLES reference_probe_old WRITE, reference_probe_new WRITE, reference_probe_control WRITE, reference_probe_taken WRITE') === null,
            'explicit lock set acquired for already existing real objects');
        $locked = true;
        $expected = $fingerprint('reference_probe_expected');
        $actual = $fingerprint('reference_probe_new');
        $report['reference_fingerprints'] = ['expected' => $expected, 'actual' => $actual];
        nativeCheck($actual === $expected, 'locked schema defaults indexes rows and AUTO_INCREMENT match private expected reference');
        $report['reference_observations']['locked_comparison'] = 'PASS';
        $report['reference_phase'] = 'competing_mutations';
        foreach ([['new_write', 'UPDATE reference_probe_new SET label=\'changed\' WHERE id=7'],
            ['old_ddl', 'ALTER TABLE reference_probe_old ADD changed INT'],
            ['control_write', "UPDATE reference_probe_control SET phase='changed' WHERE id=1"]] as [$label, $sql]) {
            nativeCheck($attempt($secondary, $label, $sql) === 1205, 'bounded competing ' . $label . ' blocked while same locks held');
        }
        nativeCheck($fingerprint('reference_probe_new') === $expected, 'blocked attempts leave locked NEW comparison exact');
        $report['reference_observations']['competing_mutations'] = 'PASS';
        $report['reference_phase'] = 'invalid_rename';
        $beforeNames = $inventory();
        $beforeState = nativeOracle($primary);
        $invalid = $attempt($primary, 'invalid_rename', 'RENAME TABLE reference_probe_old TO reference_probe_rollback, reference_probe_new TO reference_probe_taken');
        nativeCheck($inventory() === $beforeNames && nativeOracle($primary) === $beforeState,
            'invalid multi-rename leaves all synthetic names schemas and rows unchanged');
        nativeCheck($invalid === 1050, 'invalid destination is the observed multi-rename refusal under same locks');
        $report['reference_observations']['invalid_rename'] = 'PASS';
        $report['reference_phase'] = 'valid_rename';
        nativeCheck($attempt($primary, 'valid_rename', 'RENAME TABLE reference_probe_old TO reference_probe_rollback, reference_probe_new TO reference_probe_old') === null,
            'one valid synthetic multi-rename succeeds without unlocking');
        $report['reference_observations']['valid_rename'] = 'PASS';
        nativeCheck($fingerprint('reference_probe_old') === $expected, 'renamed final content still matches private expected reference');
        $report['reference_phase'] = 'journal_ack';
        nativeCheck($primary->exec("UPDATE reference_probe_control SET phase='done' WHERE id=1 AND phase='pending'") === 1
            && $primary->query('SELECT phase FROM reference_probe_control WHERE id=1')->fetchColumn() === 'done',
            'known synthetic journal acknowledgement observed inside same lock window');
        $report['reference_observations']['journal_ack'] = 'PASS';
        $report['reference_phase'] = 'complete';
    } finally {
        if ($report['reference_phase'] !== 'complete') $report['reference_observations'][$report['reference_phase']] = 'FAIL';
        // No mutation after releasing locks, and no individual object cleanup.
        if ($locked) $primary->exec('UNLOCK TABLES');
        $stmt = $primary->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute(['reference-probe-only']);
        $stmt->closeCursor();
    }
    return;
}

// Fixture-only premise probe; no product OLD, staging or control objects exist.
$report['native_version'] = $pdo->query('SELECT VERSION()')->fetchColumn();
$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION,NO_BACKSLASH_ESCAPES'");
$pdo->exec('SET SESSION lock_wait_timeout=1');
$pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
$other = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=restore_target;charset=utf8mb4', 'restore_fixture', $password, $options);
$other->exec("SET SESSION time_zone='+00:00'");
$other->exec('SET SESSION lock_wait_timeout=1');
$other->exec('SET SESSION innodb_lock_wait_timeout=1');
$pdo->exec('CREATE TABLE assurance_probe_control (id INT PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('INSERT INTO assurance_probe_control VALUES (1)');
$lock = $pdo->prepare('SELECT GET_LOCK(?,0)');
$lock->execute(['assurance-synthetic-premise']);
nativeCheck((int)$lock->fetchColumn() === 1, 'synthetic advisory lock acquired');
$lock->closeCursor();
$observations = [];
function assuranceAttempt(PDO $connection, string $label, string $sql): bool {
    global $observations;
    try {
        $connection->exec($sql);
        $observations[$label] = ['success' => true];
        return true;
    } catch (PDOException $error) {
        // No SQL, server messages, private paths, credentials or row bytes logged.
        $observations[$label] = ['success' => false, 'sqlstate' => $error->getCode(),
            'driver_code' => $error->errorInfo[1] ?? null];
        return false;
    }
}
try {
    $nonexistent = assuranceAttempt($pdo, 'lock_nonexistent_new',
        'LOCK TABLES assurance_probe_control WRITE, assurance_probe_new WRITE');
    nativeCheck(!$nonexistent, 'explicit lock set cannot include a nonexistent NEW table');
    $pdo->exec('LOCK TABLES assurance_probe_control WRITE');
    nativeCheck(!assuranceAttempt($other, 'control_write_before_create',
        'UPDATE assurance_probe_control SET id=2 WHERE id=1'), 'control write blocked before CREATE');
    nativeCheck(assuranceAttempt($pdo, 'create_under_lock',
        'CREATE TABLE assurance_probe_new (id INT PRIMARY KEY) ENGINE=InnoDB'), 'synthetic CREATE succeeds under existing control lock');
    assuranceAttempt($pdo, 'creator_insert_new', 'INSERT INTO assurance_probe_new VALUES (1)');
    assuranceAttempt($other, 'other_insert_new', 'INSERT INTO assurance_probe_new VALUES (2)');
    assuranceAttempt($other, 'control_write_after_create', 'UPDATE assurance_probe_control SET id=3 WHERE id=1');
    $report['premise_observations'] = $observations;
    nativeCheck(($observations['creator_insert_new']['success'] ?? false)
        && !($observations['other_insert_new']['success'] ?? true)
        && !($observations['control_write_after_create']['success'] ?? true),
        'CREATE keeps NEW execution through seal protected against another owned writer');
} finally {
    $report['premise_observations'] = $observations;
    // Release only; synthetic objects remain until the owned container lifecycle ends.
    $pdo->exec('UNLOCK TABLES');
    $lock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $lock->execute(['assurance-synthetic-premise']);
    $lock->closeCursor();
}
