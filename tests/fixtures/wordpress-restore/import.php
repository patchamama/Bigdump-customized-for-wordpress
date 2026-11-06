<?php
// Included only after genuine public preparation, independent recovery and confirmation.
[$manifestStatus, $manifest] = nativeJson('wp_restore_state&manifest=values');
nativeCheck($manifestStatus === 200 && isset($manifest['run']['values_manifest']['plan_sha256']), 'public final prepared plan digest available');
$stagingProof = ['artifact_sha256' => $run['preflight']['backup']['sha256'],
    'plan_sha256' => $manifest['run']['values_manifest']['plan_sha256'], 'staging_confirmed' => '1'];
function stagingRequest(array $fields, ?array $owner = null): array {
    global $csrf, $run;
    $owner ??= $run;
    return nativeJson('wp_restore_step', $fields + ['csrf' => $csrf, 'run_id' => $owner['run_id'],
        'stage' => 'preflight', 'operation' => 'staging', 'revision' => $owner['revision']]);
}
function stagingUnchanged(array $expected, string $databaseHash): void {
    global $pdo;
    [$status, $state] = nativeJson('wp_restore_state');
    nativeCheck($status === 200 && $state['run'] === $expected && nativeOracle($pdo) === $databaseHash,
        'rejection preserves exact stored checkpoint counters and all database objects');
}
function stagingPrivate(string $id): string {
    global $private;
    $found = [];
    foreach (glob($private . '/cms-wp-restore-*', GLOB_ONLYDIR) as $dir) {
        $owner = json_decode(file_get_contents($dir . '/owner.json'), true, 16, JSON_THROW_ON_ERROR);
        if (($owner['run_id'] ?? null) === $id) $found[] = $dir;
    }
    nativeCheck(count($found) === 1, 'tamper target has exact owner.run_id in exclusive fixture TMPDIR');
    return $found[0];
}
if (in_array($scenario, ['staging-lock','staging-collation'], true)) {
    $lockName = 'wpr:' . substr(hash('sha256', 'restore_target'), 0, 48);
    if ($scenario === 'staging-lock') {
        $lockQuery = $pdo->prepare('SELECT GET_LOCK(?,0)'); $lockQuery->execute([$lockName]);
        nativeCheck((int)$lockQuery->fetchColumn() === 1, 'fixture exclusively owns active request lock'); $lockQuery->closeCursor();
    }
    [$deniedStatus, $denied] = stagingRequest($stagingProof);
    nativeCheck($deniedStatus === 400 && $denied['code'] === 'staging_failed', 'busy active request or unsupported target collation rejected before mutation');
    stagingUnchanged($run, $before);
    if ($scenario === 'staging-lock') {
        $lockQuery = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $lockQuery->execute([$lockName]); $lockQuery->closeCursor();
    }
    return;
}
if (in_array($scenario, ['staging-plan-tamper', 'staging-artifact-tamper'], true)) {
    $path = stagingPrivate($run['run_id']) . ($scenario === 'staging-plan-tamper' ? '/values-plan.ndjson' : '/prior-database.sql.gz');
    $bytes = file_get_contents($path); $bytes[32] = chr(ord($bytes[32]) ^ 1); file_put_contents($path, $bytes);
    [$deniedStatus, $denied] = stagingRequest($stagingProof);
    nativeCheck($deniedStatus === 400 && $denied['code'] === 'staging_failed', 'tampered original generated plan or verified artifact refused before SQL');
    stagingUnchanged($run, $before);
    return;
}
if (in_array($scenario, ['staging-control-collision', 'staging-table-collision'], true)) {
    $collision = $scenario === 'staging-control-collision' ? '_wpr_staging_owner' : '_wpr_' . substr($run['run_id'], 0, 32) . '_1';
    $pdo->exec('CREATE TABLE ' . nativeQuote($collision) . ' (business_data INT) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO ' . nativeQuote($collision) . ' VALUES (1927)');
    $collisionHash = nativeOracle($pdo);
    [$deniedStatus, $denied] = stagingRequest($stagingProof);
    nativeCheck($deniedStatus === 400 && $denied['code'] === 'staging_failed', 'unknown control or staging collision is never adopted or modified');
    stagingUnchanged($run, $collisionHash);
    return;
}
if ($scenario === 'staging-owner') {
    $firstCookie = $cookie; $firstCsrf = $csrf; $firstRun = $run;
    $cookie = '';
    nativeRequest('', ['password' => 'FixturePassword']);
    [$secondStatus, $secondDiscovery] = nativeJson('wp_restore_discover');
    $csrf = $secondDiscovery['csrf'];
    [$secondStatus, $secondPrepared] = nativeJson('wp_restore_prepare', ['csrf'=>$csrf, 'archive'=>'hosting_fixture.zip', 'database'=>'db_fixture.sql.gz', 'target_url'=>$target]);
    nativeCheck($secondStatus === 201, 'second independent authenticated session prepared before ownership');
    $run = $secondPrepared['run'];
    foreach (['files','sql','values','backup'] as $op) {
        for ($attempt = 0; $attempt < 32 && !($run['preflight'][$op]['ready'] ?? false); $attempt++) {
            [$s, $j] = nativeJson('wp_restore_step', ['csrf'=>$csrf, 'run_id'=>$run['run_id'], 'stage'=>'preflight', 'operation'=>$op, 'revision'=>$run['revision']]);
            nativeCheck($s === 200, 'second session prerequisite ' . $op); $run = $j['run'];
        }
    }
    [$s, $secondArtifact] = nativeRequest('wp_restore_backup_download', ['csrf'=>$csrf, 'run_id'=>$run['run_id']]);
    nativeCheck($s === 200 && hash('sha256', $secondArtifact) === $run['preflight']['backup']['sha256'], 'second session generated backup downloaded authentically');
    // Same exact OLD snapshot was already independently recovered above.
    nativeCheck(gzdecode($secondArtifact) === $recoverySql, 'second generated artifact has identical independently recovered SQL bytes');
    [$s, $j] = nativeJson('wp_restore_step', ['csrf'=>$csrf, 'run_id'=>$run['run_id'], 'stage'=>'preflight', 'operation'=>'backup_confirm', 'revision'=>$run['revision'],
        'artifact_sha256'=>$run['preflight']['backup']['sha256'], 'recovery_method'=>'separate_database_schema_rows_counters', 'recovery_confirmed'=>'1', 'replace_all_tables_confirmed'=>'1']);
    nativeCheck($s === 200 && $j['run']['backup_verified'], 'second session independently authenticated confirmation');
    $secondRun = $j['run']; $secondCookie = $cookie; $secondCsrf = $csrf;
    $run = $firstRun; $cookie = $firstCookie; $csrf = $firstCsrf;
}
foreach ([['csrf'=>'wrong'], ['run_id'=>str_repeat('0', 64)], ['revision'=>'0']] as $denial) {
    [$deniedStatus] = stagingRequest($denial + $stagingProof);
    nativeCheck(in_array($deniedStatus, [403,404,409], true), 'independent CSRF run and revision staging denial');
    stagingUnchanged($run, $before);
}
$savedCookie = $cookie; $cookie = '';
[$anonymousStatus] = stagingRequest($stagingProof);
nativeCheck($anonymousStatus === 401, 'staging requires authenticated session independently of valid proof');
$cookie = $savedCookie;
stagingUnchanged($run, $before);
$rejects = [array_diff_key($stagingProof, ['staging_confirmed' => true]),
    array_replace($stagingProof, ['artifact_sha256' => str_repeat('0', 64)]),
    array_replace($stagingProof, ['plan_sha256' => str_repeat('0', 64)]),
    $stagingProof + ['sql' => 'not executable'], $stagingProof + ['table' => 'legacy_native'],
    $stagingProof + ['target_path' => '/not-used'], $stagingProof + ['executor' => 'not-used']];
foreach ($rejects as $index => $fields) {
    [$badStatus, $bad] = stagingRequest($fields);
    nativeCheck($badStatus === 400 && $bad === ['error' => 'Staging refused or interrupted. Existing tables were not replaced. Inspect state; pending SQL requires recovery.', 'code' => 'staging_failed'], 'invalid staging proof or execution target refused exactly ' . $index);
    [$stateStatus, $state] = nativeJson('wp_restore_state');
    nativeCheck($stateStatus === 200 && $state['run'] === $run && nativeOracle($pdo) === $before, 'rejected staging leaves exact checkpoint and OLD state ' . $index);
}
for ($step = 0; $step < 32; $step++) {
    $previous = $run;
    if ($scenario === 'staging-interrupt' && $step === 1) {
        // Kill an actual INSERT blocked AFTER this batch's DDL implicit commits.
        // No runtime fault-injection option exists in the product.
        $optionIndex = array_search('wp_options', array_column($manifest['run']['values_manifest']['tables'], 'name'), true);
        nativeCheck($optionIndex !== false, 'fixture identifies staged INSERT target through public manifest');
        $blockTable = '_wpr_' . substr($run['run_id'], 0, 32) . '_' . ($optionIndex + 1);
        $pdo->exec('LOCK TABLES ' . nativeQuote($blockTable) . ' WRITE');
        $body = http_build_query($stagingProof + ['csrf'=>$csrf, 'run_id'=>$run['run_id'], 'stage'=>'preflight', 'operation'=>'staging', 'revision'=>$run['revision']]);
        $request = fsockopen('127.0.0.1', $port);
        fwrite($request, "POST /cms_tools.php?action=wp_restore_step HTTP/1.1\r\nHost: 127.0.0.1\r\nCookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        $blocked = false;
        for ($poll = 0; $poll < 50; $poll++) {
            foreach ($pdo->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $process) {
                if (str_starts_with($process['Info'] ?? '', 'INSERT INTO ' . nativeQuote($blockTable))) $blocked = true;
            }
            if ($blocked) break;
            usleep(10000);
        }
        nativeCheck($blocked, 'actual server reached in-flight staging INSERT after preceding batch DDL');
        nativeCheck(proc_terminate($server, 9), 'SIGKILL sent to exact owned in-flight PHP server');
        for ($poll = 0; $poll < 50; $poll++) {
            $killed = proc_get_status($server);
            if (!$killed['running']) break;
            usleep(10000);
        }
        nativeCheck(!$killed['running'] && $killed['signaled'] && $killed['termsig'] === 9, 'actual abrupt server death observed with termination signal 9');
        $report['termination_signal'] = $killed['termsig'];
        proc_close($server); $server = null; fclose($request);
        $pdo->exec('UNLOCK TABLES');
        $server = proc_open($serverCommand, [0=>['pipe','r'],1=>['file',$base.'/server.log','a'],2=>['file',$base.'/server.log','a']], $restartPipes);
        fclose($restartPipes[0]);
        for ($poll=0; $poll<50; $poll++) {
            if ($socket = @fsockopen('127.0.0.1', $port)) { fclose($socket); break; }
            usleep(10000);
        }
        [$s, $state] = nativeJson('wp_restore_state');
        nativeCheck($s === 200 && $state['run']['revision'] === $run['revision'] + 1
            && $state['run']['preflight']['staging']['status'] === 'pending'
            && $state['run']['preflight']['staging']['recovery_needed']
            && $state['run']['preflight']['staging']['rows'] === 0, 'crash retains valid session/hash authority and durable unacknowledged intent');
        $actualTables = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '_wpr_%'")->fetchColumn();
        nativeCheck((int)$actualTables > $state['run']['preflight']['staging']['tables'] + 1,
            'actual implicit-commit DDL survives kill without falsely acknowledging batch counters');
        $run = $state['run']; $afterKill = nativeOracle($pdo);
        [$retryStatus, $retry] = stagingRequest($stagingProof);
        nativeCheck($retryStatus === 400 && $retry['code'] === 'staging_failed', 'interrupted SQL ambiguity is terminal without replay');
        stagingUnchanged($run, $afterKill);
        nativeCheck(nativeOracle($pdo, ['legacy_native','unrelated_prefix']) === $before, 'abrupt SQL interruption preserves OLD data schema and counters');
        $report['abrupt_termination_observed'] = true;
        return;
    }
    [$status, $response] = stagingRequest($stagingProof);
    $report['staging_status'] = $status;
    nativeCheck($status === 200, 'authenticated confirmed staging accepted; observed HTTP ' . $status);
    $run = $response['run'];
    $progress = $run['preflight']['staging'];
    nativeCheck($progress['processed'] > ($previous['preflight']['staging']['processed'] ?? 0)
        && $progress['processed'] <= $progress['total'] && $progress['frames'] - ($previous['preflight']['staging']['frames'] ?? 0) <= 8,
        'bounded progressive captured frames advance only after observed outcome');
    nativeCheck($run['revision'] === $previous['revision'] + 2 && !$progress['recovery_needed'], 'intent and observed outcome revisions acknowledged');
    nativeCheck(nativeOracle($pdo, ['legacy_native', 'unrelated_prefix']) === $before, 'every staging step preserves exact OLD schemas bytes zero IDs and AUTO_INCREMENT');
    nativeCheck(!$run['runtime_verified'] && !$run['replacement_enabled'] && $run['backup_verified'], 'staging never claims runtime or replacement');
    [$staleStatus] = stagingRequest($stagingProof, $previous);
    [$stateStatus, $state] = nativeJson('wp_restore_state');
    nativeCheck($staleStatus === 409 && $stateStatus === 200 && $state['run'] === $run, 'stale revision cannot replay successful SQL');
    if ($step === 0 && $scenario === 'staging-owner') {
        $ownerRun = $run; $ownerCookie = $cookie; $ownerCsrf = $csrf;
        $run = $secondRun; $cookie = $secondCookie; $csrf = $secondCsrf;
        $otherProof = $stagingProof; $otherProof['artifact_sha256'] = $run['preflight']['backup']['sha256'];
        $ownedHash = nativeOracle($pdo);
        [$otherStatus, $other] = stagingRequest($otherProof);
        nativeCheck($otherStatus === 400 && $other['code'] === 'staging_failed', 'persistent database ownership refuses independently verified second session between HTTP steps');
        stagingUnchanged($run, $ownedHash);
        $run = $ownerRun; $cookie = $ownerCookie; $csrf = $ownerCsrf;
    }
    if ($step === 0 && $scenario === 'staging-trigger') {
        $pdo->exec('CREATE TRIGGER fixture_redirect BEFORE UPDATE ON _wpr_staging_owner FOR EACH ROW UPDATE unrelated_prefix SET value=\'must never execute\' WHERE id=99');
        $guardedHash = nativeOracle($pdo);
        [$triggerStatus, $triggerResponse] = stagingRequest($stagingProof);
        nativeCheck($triggerStatus === 400 && $triggerResponse['code'] === 'staging_failed', 'new trigger cannot redirect journal writes into OLD data');
        stagingUnchanged($run, $guardedHash);
        nativeCheck(nativeOracle($pdo, ['legacy_native','unrelated_prefix']) === $before, 'post-backup trigger remains unexecuted and OLD data exact');
        return;
    }
    if ($step === 0 && $scenario === 'staging-journal') {
        $pdo->exec("UPDATE _wpr_staging_owner SET phase='pending'");
        $uncertainHash = nativeOracle($pdo);
        [$retryStatus, $retry] = stagingRequest($stagingProof);
        nativeCheck($retryStatus === 400 && $retry['code'] === 'staging_failed', 'unacknowledged DATABASE journal forbids replay even with valid filesystem authority');
        stagingUnchanged($run, $uncertainHash);
        return;
    }
    if ($progress['ready']) break;
}
nativeCheck($step > 0 && $step < 32 && $progress['ready'] && $progress['processed'] === $progress['total'], 'staging completes across multiple bounded requests');
nativeCheck($progress['tables'] === count($manifest['run']['values_manifest']['tables'])
    && $progress['rows'] === $manifest['run']['values_manifest']['row_count'], 'observed staging totals match authenticated prepared manifest');
$names = [];
foreach ($manifest['run']['values_manifest']['tables'] as $index => $table) {
    $physical = '_wpr_' . substr($run['run_id'], 0, 32) . '_' . ($index + 1);
    $names[$table['name']] = $physical;
    nativeCheck((int)$pdo->query('SELECT COUNT(*) FROM ' . nativeQuote($physical))->fetchColumn() === $table['rows'], 'actual staged row count matches ' . $table['name']);
}
$optionsTable = nativeQuote($names['wp_options']);
$actualOptions = $pdo->query('SELECT option_name,option_value FROM ' . $optionsTable)->fetchAll(PDO::FETCH_KEY_PAIR);
nativeCheck($actualOptions['siteurl'] === $target && $actualOptions['home'] === $target && $actualOptions['active_plugins'] === 'a:0:{}'
    && isset($actualOptions['wp_user_roles']) && !isset($actualOptions['_transient_widget']), 'typed staging preserves URL permission plugin and transient preparation');
$posts = $pdo->query('SELECT guid,payload FROM ' . nativeQuote($names['wp_posts']) . ' ORDER BY id')->fetchAll(PDO::FETCH_NUM);
nativeCheck($posts[0][0] === 'https://old.example/root/?p=1' && str_contains($posts[0][1], "UTF8 á雪\0 quo'te\"")
    && str_contains($posts[0][1], $target . '/image.jpg'), 'GUID preserved and text NUL multibyte quote bytes migrate without execution');
$metaRows = $pdo->query('SELECT id,HEX(payload) FROM ' . nativeQuote($names['wp_postmeta']) . ' ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
nativeCheck($metaRows[1] === null && $metaRows[2] === '00FF27' && $metaRows[3] === '6200FF'
    && $metaRows[4] === strtoupper(bin2hex('-1234')), 'NULL hex binary and numeric literal semantics preserved');
$types = nativeQuote($names['wp_types']);
$typeRows = $pdo->query('SELECT id,HEX(payload),amount,CAST(flag AS UNSIGNED),stamp,label FROM ' . $types . ' ORDER BY id')->fetchAll(PDO::FETCH_NUM);
$typeRows = array_map(static fn($row) => array_map(static fn($cell) => $cell === null ? null : (string)$cell, $row), $typeRows);
nativeCheck($typeRows === [['0', '41001A5C27FF', '12.340000', '511', '2024-02-03 04:05:06.123456', 'explicit'],
    ['7', null, '-9.000001', '1', null, 'other']], 'native typed zero ID binary DECIMAL BIT UTC timestamp and NULL values are exact');
$columns = $pdo->query('SHOW FULL COLUMNS FROM ' . $types)->fetchAll(PDO::FETCH_ASSOC);
nativeCheck($columns[2]['Type'] === 'decimal(20,6)' && $columns[3]['Type'] === 'bit(9)'
    && $columns[5]['Default'] === "quote'slash\\end", 'native type precision and quote/backslash default semantics retained');
$ai = $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $pdo->quote($names['wp_types']))->fetchColumn();
nativeCheck((int)$ai === 19, 'staging AUTO_INCREMENT preserves explicit zero ID and declared next counter');
$indexes = $pdo->query('SHOW INDEX FROM ' . $types)->fetchAll(PDO::FETCH_ASSOC);
nativeCheck(count($indexes) === 2 && $indexes[1]['Key_name'] === 'label_idx' && (int)$indexes[1]['Sub_part'] === 8
    && $indexes[1]['Collation'] === 'D', 'typed primary key and descending prefix index preserved');
[$s, $stable] = stagingRequest($stagingProof);
nativeCheck($s === 200 && $stable['run']['preflight']['staging'] === $progress, 'ready staging is idempotent without SQL replay');
foreach ($originals as $name => $sha) nativeCheck(hash_file('sha256', $site . '/' . $name) === $sha, 'staging preserves original bytes ' . $name);
nativeCheck(!file_exists($base . '/BACKUP_EXECUTED') && !file_exists($site . '/index.php'), 'staging executes neither stored PHP nor public placement');
$report['old_state_unchanged'] = nativeOracle($pdo, ['legacy_native', 'unrelated_prefix']) === $before;
