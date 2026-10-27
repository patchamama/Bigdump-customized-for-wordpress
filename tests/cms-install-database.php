<?php
// Synthetic public HTTP only. Both database adapters are isolated namespace doubles.
$root = sys_get_temp_dir().'/database-'.bin2hex(random_bytes(6));
mkdir($root, 0700);
$input = file_get_contents(getenv('CMS_DB_SOURCE') ?: __DIR__.'/../cms_tools.php');
$source = preg_replace('/^<\?php/', '<?php namespace InstallDatabaseFixture; use \\DirectoryIterator; use \\RecursiveIteratorIterator; use \\RecursiveDirectoryIterator; use \\FilesystemIterator; use \\Throwable; use \\Exception;', $input, 1);
foreach (['app','other','unsafe'] as $app) {
    mkdir($root.'/'.$app);
    file_put_contents($root.'/'.$app.'/cms_tools.php', $source);
    file_put_contents($root.'/'.$app.'/.migrate_config', '{"keep":"unchanged"}');
}
mkdir($root.'/unsafe/wp-config.php');
copy(__DIR__.'/fixtures/cms-install-database/prepend.php', $root.'/prepend.php');
$socket = stream_socket_server('tcp://127.0.0.1:0');
$port = substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
$process = proc_open([PHP_BINARY,'-n','-d','display_errors=1','-d','disable_functions=exec','-d','auto_prepend_file='.$root.'/prepend.php','-d','session.save_path='.$root,'-S','127.0.0.1:'.$port,'-t',$root], [0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['pipe','w']], $pipes);
function req($action, $data = null, $owner = 'owner', $app = 'app', $headers = []) {
    global $port;
    $ctx = stream_context_create(['http'=>['method'=>$data===null?'GET':'POST','ignore_errors'=>true,'follow_location'=>0,'header'=>implode("\r\n", array_merge(['Cookie: PHPSESSID='.$owner,'Content-Type: application/x-www-form-urlencoded'], $headers)),'content'=>$data===null?'':http_build_query($data)]]);
    $s = fopen('http://127.0.0.1:'.$port.'/'.$app.'/cms_tools.php?action='.$action, 'rb', false, $ctx);
    $body = stream_get_contents($s); $h = stream_get_meta_data($s)['wrapper_data']; fclose($s);
    return [(int)explode(' ', $h[0])[1],$body,json_decode($body,true)];
}
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function probes() { global $root; return is_file($root.'/probes') ? file_get_contents($root.'/probes') : ''; }
function snapshot() {
    global $root;
    $out = [];
    foreach (['app','other','unsafe'] as $app) foreach (['cms_tools.php','.migrate_config'] as $file) $out[$app.'/'.$file] = hash_file('sha256',$root.'/'.$app.'/'.$file);
    return $out;
}
$exit = 0;
try {
    if (!str_contains(fgets($pipes[2]), 'Development Server')) throw new RuntimeException('server startup');
    $before = snapshot();
    $prepare = ['csrf'=>'installer-test-csrf','cms'=>'wordpress','destination'=>'sandbox'];
    $extra = ['host'=>'127.0.0.1','database'=>'fixturedb','user'=>'dbuser','password'=>'DB_SECRET','engine'=>'mysql','namespace'=>'fresh_'];
    if (in_array('--legacy', $argv, true)) {
        req('fixture');
        foreach ([['cms_install_prepare',$prepare+$extra],['cms_install_state',null],['cms_install_step',['csrf'=>$prepare['csrf'],'run_id'=>str_repeat('78',32),'stage'=>'awaiting_database_validation']+$extra],['cms_install_prepare',['csrf'=>'wrong']+$extra],['cms_install_prepare&token=bad',$extra],['cms_install_prepare',$prepare+$extra],['diag_maintenance',$extra+['path'=>['new-field'],'directory'=>'new-field']],['get_wpconfig',$extra],['download&token=bad',$extra],['site_report_api&token=bad',$extra]] as [$action,$data]) {
            [$status,$body] = req($action,$data,'owner','app');
            echo json_encode([$status,$body])."\n";
        }
        check(probes()==='' && snapshot()===$before, 'legacy effects invariant');
    } else {
        [$status,$body] = req('cms_install_database', $extra, 'unauth', 'unsafe');
        check($status===401 && $body==='{"error":"Unauthorized."}', 'new route rejects before unsafe config bootstrap: '.json_encode([$status,$body]));
        req('fixture');
        $draft = req('cms_install_prepare',$prepare)[2]['draft'];
        $data = $extra+['csrf'=>$prepare['csrf'],'run_id'=>$draft['run_id']];
        $digest = req('fixture')[1];
        $cases = [
            ['',null,405,'POST required.'],
            ['',array_replace($data,['csrf'=>'wrong']),403,'Invalid installer session.'],
            ['',array_replace($data,['run_id'=>['bad']]),400,'Invalid draft input.'],
            ['',array_replace($data,['run_id'=>'foreign']),404,'Draft not found.'],
            ['',array_replace($data,['host'=>'remote.example']),400,'Invalid local database input.'],
            ['',array_replace($data,['database'=>"db;options=bad"]),400,'Invalid local database input.'],
            ['',array_replace($data,['password'=>['bad']]),400,'Invalid local database input.'],
            ['',array_replace($data,['namespace'=>'x;DROP']),400,'Invalid local database input.'],
            ['',array_replace($data,['engine'=>'postgresql']),400,'Unsupported database engine.'],
            ['&nodriver=1',$data,503,'Database driver unavailable.'],
            ['&missingconst=1',$data,503,'Database driver unavailable.'],
            ['',array_replace($data,['port'=>['3306']]),400,'Invalid local database input.'],
            ['&token=bad',$data,401,'Unauthorized.'],
        ];
        foreach ($cases as [$query,$payload,$code,$error]) {
            [$status,$body] = req('cms_install_database'.$query,$payload);
            check($status===$code && $body===json_encode(['error'=>$error]), 'exact rejection '.$error);
            check(probes()==='' && req('fixture')[1]===$digest && snapshot()===$before, 'rejection leaves session/source/driver counters unchanged');
        }
        req('fixture',null,'second');
        check(req('cms_install_database',$data,'second')[0]===404 && req('cms_install_database',$data,'owner','other')[0]===404, 'session/app ownership');
        foreach (['&expire=1'=>410,'&foreign=1'=>404] as $flag=>$code) {
            $owner = 'authority'.$code; req('fixture',null,$owner); req('cms_install_prepare',$prepare,$owner); req('fixture'.$flag,null,$owner);
            check(req('cms_install_database',$data,$owner)[0]===$code && probes()==='', 'expired/foreign authority before connection');
        }
        foreach (['&optionfail=1','&authfail=1','&queryfail=1','&truncate=1','&collision=1','&version=not-a-version','&version=5.5.5-10.11.8-MariaDB',''] as $query) {
            $prior = strlen(probes());
            [$status,$body,$json] = req('cms_install_database'.$query,$data);
            $effects = substr(probes(), $prior);
            if (str_contains($query,'optionfail')) check(!str_contains($effects,'"connect"') && !str_contains($effects,'"query"') && str_contains($effects,'"close"'), 'option rejection closes handle before connect/query');
            if (str_contains($query,'authfail')) check(!str_contains($effects,'"query"') && str_contains($effects,'"close"'), 'failed authentication never queries');
            check(substr_count($effects,'["query"') <= 2, 'bounded query count');
            check($status===200 && isset($json['evidence']), 'transient evidence response '.$query);
            $e = $json['evidence'];
            $expected = str_contains($query,'collision')?'collision':($query==='' || str_contains($query,'MariaDB')?'not-observed':'unknown');
            check($e['namespace_status']===$expected && $e['installation_status']==='unverified', 'honest bounded namespace status '.$expected);
            if (str_contains($query,'MariaDB')) check($e['observed_engine']==='mariadb' && $e['observed_version']==='10.11.8', 'MariaDB compatibility prefix normalized');
            check(!str_contains($body,'DB_SECRET') && !str_contains($body,'fixturedb') && !str_contains($body,'dbuser') && !str_contains($body,'raw'), 'no connection input/exception disclosure');
            check(req('fixture')[1]===$digest, 'no evidence or credentials persisted; stage unchanged');
        }
        [$status,,$json] = req('cms_install_database',array_replace($data,['namespace'=>'']));
        check($status===200 && preg_match('/^cms_[a-f0-9]{10}_$/',$json['evidence']['namespace']) && $json['evidence']['namespace_status']==='not-observed', 'generated proposal checked against actual synthetic metadata');
        foreach (['drupal','mediawiki','joomla'] as $cms) {
            $owner = $cms; req('fixture',null,$owner); $d = req('cms_install_prepare',array_replace($prepare,['cms'=>$cms]),$owner)[2]['draft'];
            [$status,,$json] = req('cms_install_database&version=PostgreSQL%2016.3&collision=1',array_replace($data,['run_id'=>$d['run_id'],'engine'=>'postgresql','host'=>'::1']),$owner);
            check($status===200 && $json['evidence']['observed_engine']==='postgresql' && $json['evidence']['namespace_kind']===($cms==='mediawiki'?'schema':'table-prefix'), 'PG namespace semantics '.$cms);
        }
        [$status,$html] = req('&lang=es');
        check($status===200 && str_contains($html,'id="db_password"') && str_contains($html,'Comprobar BD local') && str_contains($html,'</html>') && !preg_match('/(?:^|\n)(?:<br\s*\/?>\s*)?(?:<b>)?(?:Warning|Fatal error|Notice)/i',$html), 'actual bilingual UI renders with isolated driver doubles');
        $events = array_map(fn($line)=>json_decode($line,true), array_filter(explode("\n",probes())));
        $queries = array_filter($events,fn($e)=>$e[0]==='query');
        foreach ($queries as $event) check(str_starts_with($event[1],'SELECT ') && !str_contains($event[1],'fresh_'), 'fixed SELECT without input interpolation');
        check(str_contains(probes(),'["connect",["127.0.0.1",3306,true]]') && str_contains(probes(),'statement_timeout=1000') && str_contains(probes(),'default_transaction_read_only=on'), 'credentials used and client/startup limits requested');
        check(snapshot()===$before && !str_contains(file_get_contents($root.'/sess_owner'),'DB_SECRET'), 'source/config/session secrecy');
    }
} catch (Throwable $e) { fwrite(STDERR,'FAIL '.$e->getMessage()."\nArtifacts: $root\n"); $exit=1; }
finally { proc_terminate($process); file_put_contents($root.'/server.log',stream_get_contents($pipes[2]),FILE_APPEND); fclose($pipes[2]); proc_close($process); }
$log = file_get_contents($root.'/server.log');
if (preg_match('/(?:Warning|Fatal error|Notice|Deprecated|Parse error):/i',$log) || str_contains($log,'DB_SECRET')) { fwrite(STDERR,"FAIL server diagnostic/secret\nArtifacts: $root\n"); $exit=1; }
exit($exit);
