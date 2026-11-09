<?php
// Real public API contract. Root is used only for isolated schema/account bootstrap.
require __DIR__ . '/values.php';
$base = '/tmp/contract'; $site = $base . '/site'; $private = $base . '/private';
$phase = $argv[1]; $case = $argv[2]; $cookie = ''; $run = null; $csrf = '';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false,
    PDO::ATTR_STRINGIFY_FETCHES=>true, PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false, PDO::MYSQL_ATTR_LOCAL_INFILE=>false];
$dsn = 'mysql:unix_socket=/var/run/mysqld/fixture.sock;charset=utf8mb4';
function check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}
function request(string $action, ?array $post = null, bool $json = true): array {
    global $cookie, $lastHttp;
    $context = stream_context_create(['http'=>['method'=>$post === null ? 'GET' : 'POST', 'ignore_errors'=>true, 'timeout'=>8,
        'header'=>"Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content'=>$post === null ? '' : http_build_query($post)]]);
    $stream = fopen('http://127.0.0.1:18084/cms_tools.php' . ($action === '' ? '' : '?action=' . $action), 'rb', false, $context);
    if (!$stream) throw new RuntimeException('HTTP transport');
    $body = stream_get_contents($stream); $headers = stream_get_meta_data($stream)['wrapper_data']; fclose($stream);
    foreach ($headers as $header) if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) $cookie = $match[1];
    preg_match('#HTTP/\S+ (\d+)#', $headers[0], $match);
    $lastHttp = ['status'=>(int)$match[1], 'body_sha256'=>hash('sha256',$body)];
    $decoded = $json ? json_decode($body, true, 64) : $body;
    // Only fixed public error DTOs may be printed; never dump HTML, SQL or run data.
    $safe = ['A named preflight operation and revision are required.', 'Invalid restore session.',
        'Backup preflight failed. No database operation was performed.',
        'Prior database backup failed. The tool did not modify existing tables.',
        'Prior recovery confirmation failed. No table replacement was performed.',
        'Staging refused or interrupted. Existing tables were not replaced. Inspect state; pending SQL requires recovery.',
        'Staged content assurance failed. No permanent table was changed.'];
    if (is_array($decoded) && !array_diff(array_keys($decoded), ['error','code'])
        && in_array($decoded['error']??null,$safe,true)
        && in_array($decoded['code']??null,[null,'backup_preflight_failed','prior_backup_failed','prior_confirmation_failed','staging_failed','assurance_failed'],true)) $lastHttp['body']=$decoded;
    if ($json && !is_array($decoded)) throw new RuntimeException('Non-JSON public response');
    return [(int)$match[1], $decoded];
}
function step(string $operation, array $extra = []): array {
    global $csrf, $run;
    return request('wp_restore_step', $extra + ['csrf'=>$csrf, 'run_id'=>$run['run_id'], 'stage'=>'preflight', 'operation'=>$operation, 'revision'=>$run['revision']]);
}
function oracle(PDO $pdo, ?array $only = null): string {
    $state = [];
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$name, $kind]) {
        if ($only !== null && !in_array($name,$only,true)) continue;
        $q = '`' . str_replace('`','``',$name) . '`';
        $ddl = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM)[1];
        $projection = [];
        foreach ($pdo->query('SHOW COLUMNS FROM ' . $q)->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $projection[] = 'CAST(`' . $c['Field'] . '` AS ' . (str_starts_with($c['Type'],'bit(') ? 'UNSIGNED)' : 'BINARY)');
        }
        $hashes = [];
        foreach ($pdo->query('SELECT ' . implode(',', $projection) . ' FROM ' . $q)->fetchAll(PDO::FETCH_NUM) as $row) {
            $hashes[] = hash('sha256', json_encode(array_map(static fn($v)=>$v === null ? null : base64_encode((string)$v), $row), JSON_THROW_ON_ERROR));
        }
        sort($hashes); $state[$name] = [$kind, hash('sha256',$ddl), $hashes];
    }
    ksort($state); return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));
}
function readerAttribute(PDO $reader, string $phase): mixed {
    $raw=$reader->getAttribute(PDO::ATTR_EMULATE_PREPARES);
    echo 'READER_ATTRIBUTE '.json_encode(['phase'=>$phase,'type'=>get_debug_type($raw),
        'value'=>in_array($raw,[false,true,0,1],true)?$raw:null],JSON_THROW_ON_ERROR)."\n";
    return $raw;
}
function nativeReaderControls(string $dsn, string $password, array $options): void {
    global $site;
    require dirname(__DIR__,2).'/wordpress-restore-warning-reader.php';
    loadWarningReader($site.'/cms_tools.php');
    $reader=new PDO($dsn.';dbname=restore_target','restore_fixture',$password,$options+[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>false,PDO::ATTR_TIMEOUT=>5]);
    $original=readerAttribute($reader,'native_initial');
    check(in_array($original,[false,0],true),'actual helper starts with native preparation');
    $reader->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION,NO_BACKSLASH_ESCAPES'");
    $reader->exec('SET NAMES utf8mb4');
    $clean=wp_restore_staging_warning_count($reader);
    check($clean===0 && readerAttribute($reader,'clean_after')===$original,'actual runtime reader returns clean zero and restores exact native flag');
    $cursor=$reader->query("SELECT CAST('fixture-not-a-number' AS UNSIGNED)"); $cursor->fetchColumn(); $cursor->closeCursor();
    $warning=wp_restore_staging_warning_count($reader);
    check($warning===1 && readerAttribute($reader,'warning_after')===$original,'actual runtime reader preserves nonzero and restores exact native flag');
    $reader->setAttribute(PDO::ATTR_EMULATE_PREPARES,true);
    $text=readerAttribute($reader,'text_initial');
    check(in_array($text,[true,1],true),'diagnostic snapshot uses explicit text flag');
    try {
        $cursor=$reader->query('SHOW WARNINGS'); $rows=$cursor->fetchAll(PDO::FETCH_ASSOC); $cursor->closeCursor();
        $conditions=array_map(static fn($row)=>['level'=>$row['Level'],'code'=>(int)$row['Code'],'message_sha256'=>hash('sha256',$row['Message'])],$rows);
        check(count($conditions)===1 && $conditions[0]['level']==='Warning' && $conditions[0]['code']===1292,'direct snapshot confirms genuine Warning1292 without manufactured Error1295');
        $preserved=wp_restore_staging_warning_count($reader);
        check($preserved===1 && readerAttribute($reader,'text_after')===$text,'actual helper also restores exact original text attribute');
        echo 'READER '.json_encode(['clean'=>0,'warning_count'=>1,'conditions'=>$conditions,'source_sha256'=>hash_file('sha256',$site.'/cms_tools.php')],JSON_THROW_ON_ERROR)."\n";
    } finally {
        $reader->setAttribute(PDO::ATTR_EMULATE_PREPARES,$original);
        check(readerAttribute($reader,'finally')===$original,'native fixture restores exact original attribute');
    }
}
function stagingFailure(PDO $pdo, array $response): void {
    global $run;
    [$status,$state]=request('wp_restore_state');
    $names=$pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM); $names=array_column($names,0);
    $prefix='_wpr_'.substr($run['run_id'],0,32).'_';
    $summary=['response'=>$response,'public_status'=>$status,
        'public_run'=>array_intersect_key($state['run']??[],array_flip(['revision','preflight','backup_verified','runtime_verified','replacement_enabled'])),
        'old_sha256'=>oracle($pdo,['legacy_native','_wpr_business']),'database_sha256'=>oracle($pdo),
        'control_present'=>in_array('_wpr_staging_owner',$names,true),
        'derived_new_count'=>count(array_filter($names,static fn($n)=>str_starts_with($n,$prefix)&&ctype_digit(substr($n,strlen($prefix)))))];
    if ($summary['control_present']) $summary['journal']=$pdo->query('SELECT phase,position FROM _wpr_staging_owner')->fetchAll(PDO::FETCH_ASSOC);
    echo 'STAGING_FAILURE '.json_encode($summary,JSON_THROW_ON_ERROR)."\n";
}
function ownedWork(): string {
    global $private, $run;
    $found = [];
    foreach (glob($private . '/cms-wp-restore-*', GLOB_ONLYDIR) as $path) {
        $owner = json_decode(file_get_contents($path . '/owner.json'), true, 16, JSON_THROW_ON_ERROR);
        if (($owner['run_id'] ?? null) === $run['run_id']) $found[] = $path;
    }
    check(count($found) === 1, 'private tamper target located by exact owner.run_id');
    return $found[0];
}
try {
    if ($phase === 'prepare') {
        mkdir($site,0700); mkdir($private,0700);
        copy(dirname(__DIR__,3) . '/cms_tools.php', $site . '/cms_tools.php');
        $bootstrap = new PDO($dsn, 'root', '', $options);
        $password = bin2hex(random_bytes(20));
        $bootstrap->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY " . $bootstrap->quote(bin2hex(random_bytes(24))));
        $bootstrap->exec('CREATE DATABASE restore_target CHARACTER SET utf8mb4');
        $bootstrap->exec('CREATE DATABASE recovery84 CHARACTER SET utf8mb4');
        $bootstrap->exec("CREATE USER 'restore_fixture'@'localhost' IDENTIFIED BY " . $bootstrap->quote($password));
        $bootstrap->exec("GRANT ALL PRIVILEGES ON restore_target.* TO 'restore_fixture'@'localhost'");
        $bootstrap->exec("GRANT SELECT ON recovery84.* TO 'restore_fixture'@'localhost'");
        $bootstrap->exec("CREATE USER 'fixture_recovery'@'localhost'");
        $bootstrap->exec("GRANT ALL PRIVILEGES ON recovery84.* TO 'fixture_recovery'@'localhost'");
        $bootstrap = null;
        $pdo = new PDO($dsn . ';dbname=restore_target', 'restore_fixture', $password, $options);
        $pdo->exec("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'"); $pdo->exec("SET time_zone='+00:00'");
        check($pdo->query('SELECT CURRENT_USER()')->fetchColumn() === 'restore_fixture@localhost', 'fixture mutations use normal account');
        $pdo->exec('CREATE TABLE legacy_native (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,payload LONGBLOB,flag BIT(9)) ENGINE=InnoDB AUTO_INCREMENT=19');
        $pdo->exec("INSERT INTO legacy_native VALUES (0,UNHEX('4100FF27'),b'111111111'),(7,NULL,b'1')");
        $pdo->exec('CREATE TABLE _wpr_business (id INT PRIMARY KEY,payload VARCHAR(30)) ENGINE=InnoDB');
        if ($case==='replacement-partition') $pdo->exec('ALTER TABLE _wpr_business PARTITION BY HASH(id) PARTITIONS 2');
        $pdo->exec("INSERT INTO _wpr_business VALUES (5,'legitimate original table')");
        $before = oracle($pdo);
        file_put_contents($site . '/.migrate_config', json_encode(['password_hash'=>hash('sha256','odc_migrate_2026_x9kFixturePassword')]));
        file_put_contents($site . '/wp-config.php', "<?php\ndefine('DB_HOST','localhost');\ndefine('DB_NAME','restore_target');\ndefine('DB_USER','restore_fixture');\ndefine('DB_PASSWORD'," . var_export($password,true) . ");\n\$table_prefix='wp_';\n");
        [$sql] = valuesFixture('valid','http://127.0.0.1:18084/destination',$base . '/BACKUP_EXECUTED');
        // Adapt trusted synthetic input data, never product PHP source.
        $sql = str_replace('CREATE TABLE `old_postmeta` (`id` BIGINT,`payload` LONGTEXT)', 'CREATE TABLE `old_postmeta` (`id` BIGINT,`payload` LONGBLOB)', $sql);
        $sql .= "SET TIME_ZONE='+00:00';\nCREATE TABLE old_types (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,payload LONGBLOB,amount DECIMAL(20,6) NOT NULL DEFAULT -1.250000,flag BIT(9) NOT NULL DEFAULT 0x0001,stamp TIMESTAMP(6) NULL DEFAULT NULL,label VARCHAR(32) NOT NULL DEFAULT 'literal-default',PRIMARY KEY (id),KEY label_idx (label(8) DESC)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=19;\nINSERT INTO old_types VALUES (0,0x4100FF27,12.340000,0x01FF,'2024-02-03 04:05:06.123456','explicit'),(7,NULL,-9.000001,0x0001,NULL,'other');\n";
        $sql .= "INSERT INTO old_postmeta VALUES (NULL,0x00FF),(NULL,0x00FF),(NULL,NULL),(NULL,NULL);\n";
        file_put_contents($site . '/db_fixture.sql.gz',gzencode($sql));
        $zip = new ZipArchive(); $zip->open($site . '/hosting_fixture.zip',ZipArchive::CREATE|ZipArchive::EXCL);
        foreach (['index.php'=>'<?php die("must not execute");','wp-settings.php'=>'Inert core','wp-load.php'=>'Inert core','wp-includes/version.php'=>"<?php \$wp_version='6.8.3';"] as $path=>$bytes) $zip->addFromString($path,$bytes);
        $zip->close();
        $originals = [];
        foreach (['cms_tools.php','wp-config.php','hosting_fixture.zip','db_fixture.sql.gz'] as $name) $originals[$name] = hash_file('sha256',$site . '/' . $name);
        touch($base . '/ready');
        $ready = false;
        for ($i=0;$i<100;$i++) { if ($s=@fsockopen('127.0.0.1',18084)) { fclose($s); $ready=true; break; } usleep(20000); }
        check($ready,'owned PHP HTTP server ready with legitimate default_socket ini');
        request('', ['password'=>'FixturePassword'], false);
        [$status,$discovery] = request('wp_restore_discover');
        check($status===200 && $discovery['eligible'],'public authenticated discovery'); $csrf=$discovery['csrf'];
        [$status,$j] = request('wp_restore_prepare',['csrf'=>$csrf,'archive'=>'hosting_fixture.zip','database'=>'db_fixture.sql.gz','target_url'=>'http://127.0.0.1:18084/destination']);
        check($status===201,'public prepare'); $run=$j['run'];
        foreach (['files','sql','values'] as $operation) {
            for ($i=0;$i<64 && !($run['preflight'][$operation]['ready']??false);$i++) {
                [$status,$j] = step($operation); check($status===200,'public prerequisite ' . $operation); $run=$j['run'];
            }
            check($run['preflight'][$operation]['ready'],'bounded prerequisite ready ' . $operation);
        }
        [$status,$j] = step('backup'); check($status===200,'whole OLD backup captured through API'); $run=$j['run'];
        [$status,$gzip] = request('wp_restore_backup_download',['csrf'=>$csrf,'run_id'=>$run['run_id']],false);
        check($status===200 && hash('sha256',$gzip)===$run['preflight']['backup']['sha256'],'authenticated whole OLD backup download');
        file_put_contents($base . '/recovery.sql',gzdecode($gzip));
        check(oracle($pdo)===$before,'all original OLD including legitimate _wpr_ name remain exact');
        file_put_contents($base . '/state.json',json_encode(compact('password','cookie','csrf','run','before','originals'),JSON_THROW_ON_ERROR));
        chmod($base . '/state.json',0600);
    } else {
        extract(json_decode(file_get_contents($base . '/state.json'),true,32,JSON_THROW_ON_ERROR));
        $pdo = new PDO($dsn . ';dbname=restore_target','restore_fixture',$password,$options);
        $pdo->exec("SET time_zone='+00:00'");
        $recovery = new PDO($dsn . ';dbname=recovery84','restore_fixture',$password,$options); $recovery->exec("SET time_zone='+00:00'");
        check(oracle($recovery)===$before && oracle($pdo)===$before,'actual separate standard MySQL client recovery reproduces exact whole OLD state');
        [$status,$j] = step('backup_confirm',['artifact_sha256'=>$run['preflight']['backup']['sha256'],'recovery_method'=>'separate_database_schema_rows_counters','recovery_confirmed'=>'1','replace_all_tables_confirmed'=>'1']);
        check($status===200,'explicit operator artifact-bound recovery confirmation'); $run=$j['run'];
        check($run['backup_verified'] && $run['preflight']['backup']['provenance']==='operator_verified_external_backup' && !$run['preflight']['backup']['app_recovery_verified'],'external recovery remains operator provenance not app certification');
        if (($argv[3]??'')==='reader-controls') nativeReaderControls($dsn,$password,$options);
        [$status,$manifest] = request('wp_restore_state&manifest=values');
        $proof = ['artifact_sha256'=>$run['preflight']['backup']['sha256'],'plan_sha256'=>$manifest['run']['values_manifest']['plan_sha256']];
        for ($i=0;$i<64 && !($run['preflight']['staging']['ready']??false);$i++) {
            [$status,$j] = step('staging',$proof+['staging_confirmed'=>'1']);
            if ($status!==200) {
                $failed=$lastHttp; stagingFailure($pdo,$failed); $lastHttp=$failed;
                check(false,'existing public staging behavior'); // Terminal: no second staging request.
            }
            $run=$j['run'];
            check(oracle($pdo,['legacy_native','_wpr_business'])===$before,'successful stage preserves exact whole OLD state');
        }
        check($run['preflight']['staging']['ready'],'bounded staged readiness');
        $types = null;
        foreach ($manifest['run']['values_manifest']['tables'] as $index=>$t) if ($t['name']==='wp_types') $types='_wpr_' . substr($run['run_id'],0,32) . '_' . ($index+1);
        check(is_string($types),'manifest-derived exact staged typed table'); $q='`'.$types.'`';
        if ($case==='staging-only') {
            foreach ($manifest['run']['values_manifest']['tables'] as $index=>$table) {
                $name='_wpr_'.substr($run['run_id'],0,32).'_'.($index+1);
                check((int)$pdo->query('SELECT COUNT(*) FROM `'.$name.'`')->fetchColumn()===$table['rows'],'actual staged table count matches prepared manifest');
            }
            $actual=$pdo->query('SELECT id,HEX(payload),amount,CAST(flag AS UNSIGNED),stamp,label FROM '.$q.' ORDER BY id')->fetchAll(PDO::FETCH_NUM);
            check($actual===[['0','4100FF27','12.340000','511','2024-02-03 04:05:06.123456','explicit'],['7',null,'-9.000001','1',null,'other']],'actual staged typed zero ID binary NULL DECIMAL BIT UTC values');
            $ddl=$pdo->query('SHOW CREATE TABLE '.$q)->fetch(PDO::FETCH_NUM)[1];
            check(str_contains($ddl,'AUTO_INCREMENT=19') && str_contains($ddl,'label_idx') && str_contains($ddl,'literal-default'),'staged counter index and default retained');
            $users=null;
            foreach ($manifest['run']['values_manifest']['tables'] as $index=>$table) if ($table['name']==='wp_users') $users='`_wpr_'.substr($run['run_id'],0,32).'_'.($index+1).'`';
            check(is_string($users),'manifest-derived exact default target');
            $pdo->exec('CREATE TEMPORARY TABLE `_restore_default_check` LIKE '.$users);
            try {
                $pdo->exec('INSERT INTO `_restore_default_check` (id) VALUES (1)');
                check($pdo->query('SELECT payload FROM `_restore_default_check`')->fetchColumn()==='DEFAULT_VALUE_SECRET','omitted LONGTEXT default retains exact admitted bytes in a private temporary clone');
            } finally { $pdo->exec('DROP TEMPORARY TABLE `_restore_default_check`'); }
            check(!$run['replacement_enabled'] && !$run['runtime_verified'] && $run['backup_verified'],'staging readiness never authorizes replacement or runtime');
            foreach ($originals as $name=>$hash) check(hash_file('sha256',$site.'/'.$name)===$hash,'staging preserves original '.$name);
            check(!file_exists($site.'/index.php') && !file_exists($base.'/BACKUP_EXECUTED'),'no placement or backup PHP execution');
            return; // Compatibility regression only, no assurance request.
        }
        $bag = null;
        foreach ($manifest['run']['values_manifest']['tables'] as $index=>$table)
            if ($table['name']==='wp_postmeta') $bag='`_wpr_'.substr($run['run_id'],0,32).'_'.($index+1).'`';
        check(is_string($bag) && $pdo->query('SELECT COUNT(*) FROM '.$bag.' WHERE id IS NULL AND payload IS NULL')->fetchColumn()==='2'
            && $pdo->query("SELECT COUNT(*) FROM ".$bag." WHERE id IS NULL AND payload=UNHEX('00FF')")->fetchColumn()==='2','staged duplicate NULL and binary multiplicities are exact');
        if ($case==='row') check($pdo->exec('UPDATE '.$bag." SET payload=NULL WHERE id IS NULL AND payload=UNHEX('00FF') LIMIT 1")===1,
            'row negative changes multiplicities but preserves row count and distinct value set');
        if ($case==='schema') $pdo->exec('ALTER TABLE '.$q.' ADD unexpected INT');
        if ($case==='default') $pdo->exec('ALTER TABLE '.$q." ALTER label SET DEFAULT 'changed'");
        if ($case==='index') $pdo->exec('ALTER TABLE '.$q.' DROP INDEX label_idx');
        if ($case==='ai') $pdo->exec('ALTER TABLE '.$q.' AUTO_INCREMENT=999');
        if ($case==='inventory') $pdo->exec('CREATE TABLE unexpected_business (id INT) ENGINE=InnoDB');
        if ($case==='unsupported') {
            $pdo->exec('CREATE TABLE unsupported_engine (id INT) ENGINE=MyISAM');
            check($pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='unsupported_engine'")->fetchColumn()==='MyISAM','real unsupported storage profile, not a forged response');
        }
        if (in_array($case,['plan','backup'],true)) {
            $path=ownedWork() . ($case==='plan'?'/values-plan.ndjson':'/prior-database.sql.gz');
            $bytes=file_get_contents($path); $bytes[32]=chr(ord($bytes[32])^1); file_put_contents($path,$bytes);
        }
        $permanent = oracle($pdo); // AFTER intentional trusted mutation.
        $checkpoint=hash_file('sha256',ownedWork().'/state.json');
        if ($case==='authority') {
            $refused=['error'=>'Staged content assurance failed. No permanent table was changed.','code'=>'assurance_failed'];
            $inputs=[$proof,array_replace($proof,['artifact_sha256'=>str_repeat('0',64),'assurance_confirmed'=>'1']),
                array_replace($proof,['plan_sha256'=>str_repeat('0',64),'assurance_confirmed'=>'1'])];
            foreach (['sql','table','target_path','executor'] as $field) $inputs[]=$proof+['assurance_confirmed'=>'1',$field=>'forbidden'];
            foreach ($inputs as $input) {
                [$s,$bad]=step('assurance',$input);
                check($s===400 && $bad===$refused,'artifact plan explicit confirmation and executor authority refused');
                [$s,$state]=request('wp_restore_state');
                check($s===200 && $state['run']===$run && oracle($pdo)===$permanent
                    && hash_file('sha256',ownedWork().'/state.json')===$checkpoint,'authority refusal preserves durable checkpoint and permanent state');
            }
            $pdo->exec("UPDATE _wpr_staging_owner SET token='".str_repeat('0',64)."' WHERE id=1");
            $permanent=oracle($pdo);
            [$s,$bad]=step('assurance',$proof+['assurance_confirmed'=>'1']);
            [$readStatus,$state]=request('wp_restore_state');
            check($s===400 && $bad===$refused && $readStatus===200 && $state['run']===$run && oracle($pdo)===$permanent
                && hash_file('sha256',ownedWork().'/state.json')===$checkpoint,'journal authority tamper refused without checkpoint change or permanent repair');
        }
        if ($case==='concurrent') {
            $mutex="CONCAT('wpr:',LEFT(SHA2(DATABASE(),256),48))";
            check($pdo->query('SELECT IS_FREE_LOCK('.$mutex.')')->fetchColumn()==='1','cooperative mutex free; exclusion case holds a real object instead');
            $pdo->exec('SET autocommit=0'); $pdo->exec('LOCK TABLES '.$q.' WRITE');
        }
        $started=microtime(true); $previous = $run;
        [$status,$j] = step('assurance',$proof+['assurance_confirmed'=>'1']+($case==='authority'?['csrf'=>'wrong']:[]));
        if ($case==='concurrent') {
            $pdo->exec('UNLOCK TABLES');
            check(microtime(true)-$started>=1 && $pdo->query('SELECT IS_FREE_LOCK('.$mutex.')')->fetchColumn()==='1','bounded external-object exclusion refusal, not cooperative mutex possession');
        }
        if ($case==='red' && $status!==200) {
            $failed=$lastHttp; [$readStatus,$state]=request('wp_restore_state');
            $files=[];
            foreach ($originals as $name=>$hash) $files[$name]=hash_file('sha256',$site.'/'.$name)===$hash;
            echo 'ASSURANCE_RED '.json_encode(['response'=>$failed,'state_status'=>$readStatus,
                'checkpoint_unchanged'=>$state['run']===$previous,'permanent_before_sha256'=>$permanent,
                'permanent_after_sha256'=>oracle($pdo),'old_sha256'=>oracle($pdo,['legacy_native','_wpr_business']),
                'original_files_unchanged'=>$files],JSON_THROW_ON_ERROR)."\n";
            $lastHttp=$failed;
        }
        if (in_array($case,['red','valid','replacement-authority','replacement-partition','replacement-terminal'],true)) {
            check($status===200,'public assurance operation accepted'); $run=$j['run'];
            $a=$run['preflight']['assurance'];
            check($a['status']==='compared_as_of' && $a['requires_locked_recheck']===true && $a['position']===$run['preflight']['staging']['processed'],'truthful plan-position-bound historical assurance');
            check(!$run['replacement_enabled'] && !$run['runtime_verified'],'as-of comparison never authorizes replacement or runtime certification');
        } else {
            $expected = $case==='authority' ? ['error'=>'Invalid restore session.'] : ['error'=>'Staged content assurance failed. No permanent table was changed.','code'=>'assurance_failed'];
            check($status===($case==='authority'?403:400) && $j===$expected,'exact safe public rejection for '.$case);
            [$s,$state]=request('wp_restore_state'); check($s===200 && $state['run']===$previous && hash_file('sha256',ownedWork().'/state.json')===$checkpoint,'rejection leaves exact durable checkpoint and counters unchanged');
        }
        check(oracle($pdo)===$permanent,'assurance never mutates permanent OLD NEW control schemas rows counters');
        if (str_starts_with($case,'replacement-')) {
            require __DIR__.'/replacement-authority.php';
            replacementAuthorityFixture($case,$pdo,$proof,$manifest);
        }
        if ($case==='valid') {
            $pdo->exec('UPDATE '.$q." SET label='changed-after-release' WHERE id=7"); $permanent=oracle($pdo); $previous=$run;
            $checkpoint=hash_file('sha256',ownedWork().'/state.json');
            [$s,$state]=request('wp_restore_state'); check($s===200 && $state['run']['preflight']['assurance']===$run['preflight']['assurance'] && !$state['run']['replacement_enabled'],'historical summary survives later tamper without granting future authority');
            [$s,$bad]=step('assurance',$proof+['assurance_confirmed'=>'1']);
            check($s===400 && $bad===['error'=>'Staged content assurance failed. No permanent table was changed.','code'=>'assurance_failed'],'fresh comparison refuses post-as-of tamper');
            [$s,$state]=request('wp_restore_state'); check($state['run']===$previous && oracle($pdo)===$permanent && hash_file('sha256',ownedWork().'/state.json')===$checkpoint,'later mismatch preserves exact historical checkpoint and tampered data without repair');
        }
        foreach ($originals as $name=>$hash) check(hash_file('sha256',$site.'/'.$name)===$hash,'original bytes preserved '.$name);
        check(!file_exists($site.'/index.php') && !file_exists($base.'/BACKUP_EXECUTED'),'no public placement or backup PHP execution');
    }
} catch (Throwable $error) {
    if (isset($lastHttp)) fwrite(STDERR,'FAILED_API '.json_encode($lastHttp,JSON_THROW_ON_ERROR)."\n");
    fwrite(STDERR,'FAIL '.($error instanceof PDOException?'native fixture SQL failure':$error->getMessage())."\n"); exit(1);
}
