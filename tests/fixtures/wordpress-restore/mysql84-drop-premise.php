<?php
// Synthetic DROP/exclusion premise only; no application cutover or crash certification.
$report=['phase'=>'bootstrap','checks'=>[],'outcomes'=>array_fill_keys(['failed_drop','locked_rename','irreversible_drop','preserved_exclusion','journal','external_ack'],'NOTRUN')];
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
    check(in_array($name, ['drop_old_a','drop_old_b','drop_new_a','drop_new_b','drop_control','drop_retired_a','drop_retired_b'], true), 'fingerprint target is owned synthetic name');
    $q = '`' . $name . '`';
    $ddl = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM)[1];
    $prefix = 'CREATE ' . '' . 'TABLE ' . $q;
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
    $report['scope'] = 'synthetic-known-participants-only';
    $report['namespace_exclusion_demonstrated'] = false;
    $report['application_cutover_demonstrated'] = false;
    $report['negative_drop_lock_context'] = 'unlocked';
    $report['profile'] = $primary->query('SELECT VERSION() AS version,@@version_comment AS flavor,CURRENT_USER() AS account,@@autocommit AS autocommit,@@innodb_table_locks AS innodb_table_locks')->fetch(PDO::FETCH_ASSOC);
    check($report['profile'] === ['version'=>'8.4.13','flavor'=>'MySQL Community Server - GPL','account'=>'restore_fixture@localhost','autocommit'=>'1','innodb_table_locks'=>'1']
        && $peer->query('SELECT CURRENT_USER()')->fetchColumn()==='restore_fixture@localhost', 'exact demonstrated server profile and two normal accounts');
    check(inventory($primary)===[], 'owned synthetic schema starts empty');
    $shape = " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,payload LONGBLOB NULL,label VARCHAR(40) NOT NULL DEFAULT 'literal-default') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=19";
    foreach (['drop_old_a','drop_old_b','drop_new_a','drop_new_b'] as $name) $primary->exec('CREATE TABLE `'.$name.'`'.$shape);
    $primary->exec("INSERT INTO drop_old_a VALUES (0,UNHEX('4100FF27'),'old-a')");
    $primary->exec("INSERT INTO drop_old_b VALUES (7,NULL,'old-b')");
    $primary->exec("INSERT INTO drop_new_a VALUES (0,UNHEX('0102'),'new-a')");
    $primary->exec("INSERT INTO drop_new_b VALUES (7,NULL,'new-b')");
    $primary->exec('CREATE TABLE drop_control (id INT PRIMARY KEY,phase VARCHAR(8) NOT NULL) ENGINE=InnoDB');
    $primary->exec("INSERT INTO drop_control VALUES (1,'pending')");
    check($primary->query('SELECT DISTINCT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN)===['InnoDB'], 'every participant uses the supported atomic-DDL engine');
    $before=inventory($primary);
    $report['phase']='failed_drop';
    check(attempt($primary,'missing_drop','DROP TABLE drop_old_a,drop_absent')===1051, 'missing-table DROP without IF EXISTS yields exact error1051');
    check(inventory($primary)===$before, 'failed unlocked multi-DROP preserves every name schema row and AUTO_INCREMENT');
    $report['outcomes']['failed_drop']='PASS';
    $oldA=$before['drop_old_a']; $oldB=$before['drop_old_b'];
    $newA=$before['drop_new_a']; $newB=$before['drop_new_b']; $control=$before['drop_control'];
    $stmt=$primary->prepare('SELECT GET_LOCK(?,0)');
    $stmt->execute(['mysql84-drop-premise']); check($stmt->fetchColumn()==='1','owned cooperative mutex acquired'); $stmt->closeCursor(); $advisory=true;
    $primary->exec('SET autocommit=0');
    check($primary->query('SELECT @@autocommit')->fetchColumn()==='0','autocommit disabled without START TRANSACTION');
    $primary->exec('LOCK TABLES drop_old_a WRITE,drop_old_b WRITE,drop_new_a WRITE,drop_new_b WRITE,drop_control WRITE'); $locked=true;
    $report['phase']='locked_rename';
    check(inventory($primary)===$before,'all original synthetic participants remain exact under locks');
    check($primary->exec('RENAME TABLE drop_old_a TO drop_retired_a,drop_old_b TO drop_retired_b,drop_new_a TO drop_old_a,drop_new_b TO drop_old_b')===0,'single locked multi-RENAME acknowledged without unlock');
    check(fingerprint($primary,'drop_old_a')===$newA && fingerprint($primary,'drop_old_b')===$newB
        && fingerprint($primary,'drop_retired_a')===$oldA && fingerprint($primary,'drop_retired_b')===$oldB,'renamed final and retired objects retain exact schemas data multiplicities and counters');
    $report['outcomes']['locked_rename']='PASS';
    $report['phase']='irreversible_drop';
    check($primary->exec('DROP TABLE drop_retired_a,drop_retired_b')===0,'single permanent multi-DROP of exact retired synthetic OLD acknowledged');
    $after=inventory($primary);
    check(array_keys($after)===['drop_control','drop_old_a','drop_old_b'] && $after['drop_old_a']===$newA
        && $after['drop_old_b']===$newB && $after['drop_control']===$control,'every retired OLD is absent and final schemas rows counters and control remain exact');
    $report['outcomes']['irreversible_drop']='PASS';
    $report['phase']='preserved_exclusion';
    foreach ([['final_write',"UPDATE drop_old_a SET label='changed' WHERE id=0"],['final_alter','ALTER TABLE drop_old_b ADD changed INT'],
        ['control_write',"UPDATE drop_control SET phase='changed' WHERE id=1"]] as [$label,$sql])
        check(attempt($peer,$label,$sql)===1205,'real peer '.$label.' remains blocked after retired-table DROP');
    check(inventory($primary)===$after,'blocked peer attempts leave all surviving objects unchanged');
    $report['outcomes']['preserved_exclusion']='PASS';
    $report['phase']='journal';
    check($primary->exec("UPDATE drop_control SET phase='dropdone' WHERE id=1 AND phase='pending'")===1,'post-DROP journal acknowledgement affects exactly one row');
    $primary->exec('COMMIT');
    check(attempt($peer,'after_commit_write',"UPDATE drop_old_a SET label='changed' WHERE id=0")===1205,'final WRITE exclusion survives post-DROP journal COMMIT');
    check(attempt($peer,'after_commit_control',"UPDATE drop_control SET phase='changed' WHERE id=1")===1205,'control WRITE exclusion survives post-DROP journal COMMIT');
    check(fingerprint($primary,'drop_old_a')===$newA && fingerprint($primary,'drop_old_b')===$newB,'final schemas rows and AUTO_INCREMENT remain exact after COMMIT and peer attempts');
    $report['outcomes']['journal']='PASS';
    $primary->exec('UNLOCK TABLES'); $locked=false;
    $report['phase']='external_ack';
    check($peer->query('SELECT id,phase FROM drop_control')->fetchAll(PDO::FETCH_NUM)===[['1','dropdone']],'separate ordinary connection observes committed journal after release');
    $final=inventory($peer);
    check(array_keys($final)===['drop_control','drop_old_a','drop_old_b'] && $final['drop_old_a']===$newA && $final['drop_old_b']===$newB,'separate connection confirms only exact surviving synthetic participants');
    $report['outcomes']['external_ack']='PASS';
    $report['phase']='complete'; $exit=0;
} catch (Throwable $error) {
    $report['failure']=['class'=>get_class($error),'sqlstate'=>$error instanceof PDOException?$error->getCode():null,
        'driver_code'=>$error instanceof PDOException?($error->errorInfo[1]??null):null];
    if(isset($report['outcomes'][$report['phase']])) $report['outcomes'][$report['phase']]='FAIL';
    fwrite(STDERR,'FAIL premise phase='.$report['phase']."\n");
} finally {
    try {
        if($locked) $primary->exec('UNLOCK TABLES');
        if($advisory) { $stmt=$primary->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute(['mysql84-drop-premise']);
            if($stmt->fetchColumn()!=='1') throw new RuntimeException('Release failed'); $stmt->closeCursor(); }
    } catch(Throwable $error) { $report['release_failed']=true; $exit=1; }
    echo json_encode($report,JSON_THROW_ON_ERROR)."\n";
}
exit($exit);
