<?php
// Owned synthetic HTTP trees only; select a project-local TMPDIR before running.
$root = sys_get_temp_dir().'/workflow-'.bin2hex(random_bytes(6));
mkdir($root, 0700);
$source = preg_replace('/^<\?php/', '<?php namespace InstallWorkflowFixture; use \\DirectoryIterator; use \\RecursiveIteratorIterator; use \\RecursiveDirectoryIterator; use \\FilesystemIterator; use \\Throwable; use \\Exception;', file_get_contents(__DIR__.'/../cms_tools.php'), 1);
foreach (['app','other','unsafe'] as $app) {
    mkdir($root.'/'.$app);
    file_put_contents($root.'/'.$app.'/cms_tools.php', $source);
    file_put_contents($root.'/'.$app.'/.migrate_config', '{"keep":"unchanged"}');
}
file_put_contents($root.'/wp-config.php', '<?php /* PARENT_SECRET */');
file_put_contents($root.'/app/wp-config.php', '<?php /* CONFIG_SECRET */');
mkdir($root.'/unsafe/wp-config.php');
copy(__DIR__.'/fixtures/cms-install-workflow/prepend.php', $root.'/prepend.php');
$socket = stream_socket_server('tcp://127.0.0.1:0');
$port = substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
// Legacy HTML command availability checks must not invoke host shells or write NUL.
$process = proc_open([PHP_BINARY,'-n','-d','display_errors=1','-d','disable_functions=exec','-d','auto_prepend_file='.$root.'/prepend.php','-d','session.save_path='.$root,'-S','127.0.0.1:'.$port,'-t',$root], [0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['pipe','w']], $pipes);
function req($action, $data = null, $cookie = 'PHPSESSID=workflowowner', $app = 'app', $headers = []) {
    global $port;
    $ctx = stream_context_create(['http'=>['method'=>$data === null?'GET':'POST','ignore_errors'=>true,'follow_location'=>0,'header'=>implode("\r\n", array_merge(['Cookie: '.$cookie,'Content-Type: application/x-www-form-urlencoded'], $headers)),'content'=>$data === null?'':http_build_query($data)]]);
    $s = fopen('http://127.0.0.1:'.$port.'/'.$app.'/cms_tools.php?action='.$action, 'rb', false, $ctx);
    $body = stream_get_contents($s);
    $h = stream_get_meta_data($s)['wrapper_data']; fclose($s);
    return [(int)explode(' ', $h[0])[1], $body, json_decode($body, true), $h];
}
function check($ok, $msg) { if (!$ok) throw new RuntimeException($msg); echo "PASS $msg\n"; }
function htmlPhpDiagnostic($body) {
    // PHP's plain/HTML diagnostics start a line; CSS selectors are not diagnostics.
    return preg_match('/(?:^|[\r\n])(?:<br\s*\/?>\s*)?(?:<b>)?(?:PHP )?(?:Warning|Fatal error|Notice|Deprecated|Parse error)(?:<\/b>)?:/i', $body) === 1;
}
function filesSnapshot($root) {
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getFilename() === 'server.log' || str_starts_with($f->getFilename(), 'sess_')) continue;
        $out[$f->getPathname()] = hash_file('sha256', $f->getPathname());
    }
    return $out;
}
$exit = 0;
try {
    check(str_contains(fgets($pipes[2]), 'Development Server'), 'HTTP server ready');
    check(!htmlPhpDiagnostic('.btn-warning:hover { color: red; }'), 'CSS warning selector is not a PHP diagnostic');
    check(htmlPhpDiagnostic("<br />\n<b>Warning</b>: synthetic diagnostic") && htmlPhpDiagnostic("PHP Fatal error: synthetic diagnostic"), 'plain and HTML PHP diagnostics are detected');
    $before = filesSnapshot($root);
    foreach (['cms_install_prepare','cms_install_state','cms_install_step'] as $action) {
        [$code,$body] = req($action, ['set_password'=>1,'new_password'=>'NEVER_SAVE','new_password2'=>'NEVER_SAVE'], '', 'unsafe');
        check($code === 401 && $body === '{"error":"Unauthorized."}', $action.' public unauthenticated clean JSON before password writes');
    }
    req('fixture&weak=1');
    check(req('cms_install_state')[0] === 401, 'truthy session is not strict authority');
    req('fixture');
    $prepare = ['csrf'=>'installer-test-csrf','cms'=>'wordpress','destination'=>'app-directory','set_password'=>1,'new_password'=>'NEVER_SAVE','new_password2'=>'NEVER_SAVE'];
    [$code,$body,$state,$headers] = req('cms_install_prepare', $prepare);
    check($code === 201 && ($state['draft']['stage'] ?? '') === 'awaiting_database_validation', 'prepare creates owned draft');
    check(in_array('Cache-Control: no-store', $headers, true), 'state is no-store');
    $draft = $state['draft']; $id = $draft['run_id'];
    check(preg_match('/^[a-f0-9]{64}$/', $id) && $draft['expires_at'] > $draft['created_at'], 'random id and bounded lifetime metadata');
    $step = ['csrf'=>'installer-test-csrf','run_id'=>$id,'stage'=>$draft['stage']];
    $digest = req('fixture')[1];
    $cases = [
        ['cms_install_prepare',$prepare,409],
        ['cms_install_prepare',array_replace($prepare,['cms'=>['wordpress']]),400],
        ['cms_install_prepare',array_replace($prepare,['destination'=>'../escape']),400],
        ['cms_install_prepare',array_replace($prepare,['csrf'=>['bad']]),403],
        ['cms_install_step',$step,503],
        ['cms_install_step',array_replace($step,['run_id'=>['bad']]),400],
        ['cms_install_step',array_replace($step,['run_id'=>str_repeat('0',64)]),404],
        ['cms_install_step',array_replace($step,['stage'=>'install']),409],
        ['cms_install_step',array_replace($step,['csrf'=>'wrong']),403],
        ['cms_install_state',[],405],
        ['cms_install_prepare',null,405],
    ];
    foreach ($cases as [$action,$data,$expected]) {
        [$code,$body,$json] = req($action, $data);
        check($code === $expected && is_array($json), $action.' rejects without advancing: '.$expected);
        check(req('fixture')[1] === $digest, 'session invariant');
    }
    [$code,,$json] = req('cms_install_state');
    check($code === 200 && $json['draft'] === $draft, 'GET resumes exact draft');
    foreach (['cms_install_prepare','cms_install_state','cms_install_step'] as $action) {
        foreach (['token=bad','bearer'] as $mode) {
            [$code,$body] = req($action.($mode==='bearer'?'':'&'.$mode), $action==='cms_install_state'?null:$prepare, 'PHPSESSID=workflowowner', 'app', $mode==='bearer'?['Authorization: Bearer bad']:[]);
            check($code===401 && $body==='{"error":"Unauthorized."}', 'machine authority cannot authorize '.$action);
        }
    }
    req('fixture', null, 'PHPSESSID=secondowner');
    check(req('cms_install_state',null,'PHPSESSID=secondowner')[2]['draft'] === null, 'other session cannot adopt draft');
    check(req('cms_install_step',$step,'PHPSESSID=secondowner')[0] === 404, 'other session rejects id');
    check(req('cms_install_state',null,'PHPSESSID=workflowowner','other')[2]['draft'] === null, 'other app cannot expose draft');
    check(req('cms_install_prepare',$prepare,'PHPSESSID=workflowowner','other')[0] === 409, 'other app cannot overwrite draft');
    req('fixture&expire=1'); $digest = req('fixture')[1];
    check(req('cms_install_state')[0] === 410 && req('cms_install_step',$step)[0] === 410, 'expired state rejected');
    check(req('fixture')[1] === $digest && req('cms_install_prepare',$prepare)[0] === 409, 'expiry never repairs or overwrites state');
    foreach (['wordpress','joomla','drupal','mediawiki'] as $cms) {
        $cookie = 'PHPSESSID=fresh'.$cms; req('fixture',null,$cookie);
        [$code,$body,$json] = req('cms_install_prepare',array_replace($prepare,['cms'=>$cms,'destination'=>'sandbox','path'=>'/SECRET_PATH','password'=>'SECRET_PASSWORD']),$cookie,'unsafe');
        check($code===201 && $json['draft']['cms']===$cms && $json['draft']['destination']==='sandbox', 'explicit sandbox choice '.$cms);
        foreach ([$root,'SECRET_PATH','SECRET_PASSWORD','CONFIG_SECRET','PARENT_SECRET'] as $secret) check(!str_contains($body,$secret), 'no path/secret reflected');
    }
    // One bounded actual-render probe: two fresh sessions, EN then ES in each.
    $tokens = [];
    foreach (['htmlownerone', 'htmlownertwo'] as $owner) {
        $cookie = 'PHPSESSID='.$owner;
        req('fixture&html=1', null, $cookie);
        foreach (['en'=>['Install CMS','Choose CMS','Choose destination','Prepare draft','Resume draft'],
                  'es'=>['Instalar CMS','Elegir CMS','Elegir destino','Preparar borrador','Retomar borrador']] as $lang=>$labels) {
            [$code,$html] = req('&lang='.$lang, null, $cookie);
            check($code === 200 && str_contains($html, '</html>')
                && !htmlPhpDiagnostic($html), $owner.' '.$lang.' complete HTML without PHP diagnostics');
            check(str_contains($html, '<html lang="'.$lang.'"')
                && str_contains($html, 'onclick="showTab(\'install\')">'.$labels[0].'</button>')
                && preg_match('/<div id="tab-install" class="section">(.*?)<div id="tab-report"/s', $html, $section), $owner.' '.$lang.' translated tab/section wiring');
            foreach (['install_cms'=>$labels[1], 'install_destination'=>$labels[2]] as $id=>$label) {
                check(str_contains($section[1], '<label for="'.$id.'">'.$label.'</label>')
                    && str_contains($section[1], '<select id="'.$id.'">'), $owner.' '.$lang.' label/select '.$id);
            }
            foreach (['prepare'=>$labels[3], 'resume'=>$labels[4]] as $operation=>$label) {
                check(str_contains($section[1], 'id="install_'.$operation.'" onclick="cmsInstallDraft(\''.($operation==='resume'?'state':$operation).'\')">'.$label.'</button>'), $owner.' '.$lang.' translated '.$operation.' button');
            }
            foreach (['wordpress','joomla','drupal','mediawiki','app-directory','sandbox'] as $choice) {
                check(str_contains($section[1], 'value="'.$choice.'"'), $owner.' '.$lang.' explicit choice '.$choice);
            }
            check(str_contains($section[1], 'id="install_status" role="status"')
                && str_contains($section[1], '<button type="button" disabled>'), $owner.' '.$lang.' draft status and unavailable next stage');
            check(preg_match('/id="install_csrf" value="([a-f0-9]{64})"/', $section[1], $match) === 1, $owner.' '.$lang.' actual generated installer token');
            $token = $match[1];
            if (isset($tokens[$owner])) check($tokens[$owner] === $token, $owner.' token stable across EN/ES render');
            $tokens[$owner] = $token;
            if (preg_match('/id="report_api_csrf" value="([a-f0-9]{64})"/', $html, $report)) {
                check($report[1] !== $token, $owner.' '.$lang.' report/installer tokens independent');
            }
        }
        [$code,$body,$json] = req('cms_install_prepare', ['csrf'=>$tokens[$owner],'cms'=>'wordpress','destination'=>'sandbox'], $cookie);
        check($code === 201 && ($json['draft']['stage'] ?? '') === 'awaiting_database_validation', $owner.' generated HTML token prepares owned draft');
        check(req('cms_install_state', null, $cookie)[2]['draft'] === $json['draft'], $owner.' generated-token draft resumes in same session');
        foreach ([$root,'CONFIG_SECRET','PARENT_SECRET','SYNTHETIC_DB_SECRET'] as $secret) check(!str_contains($body,$secret), $owner.' draft JSON has no path/secret leak');
    }
    check(count(array_unique($tokens)) === 2, 'fresh HTML sessions generate independent installer tokens');
    check(filesSnapshot($root) === $before, 'no filesystem/config/source/settings writes or unexpected DB_PROBE');
} catch (Throwable $e) { fwrite(STDERR,'FAIL '.$e->getMessage()."\nArtifacts: $root\n"); $exit=1; }
finally { proc_terminate($process); file_put_contents($root.'/server.log',stream_get_contents($pipes[2]),FILE_APPEND); fclose($pipes[2]); proc_close($process); }
// Read after process shutdown so both stdout/stderr server diagnostics are complete.
$serverLog = file_get_contents($root.'/server.log');
if (preg_match('/(?:Warning|Fatal error|Notice|Deprecated|Parse error):/i', $serverLog)) {
    fwrite(STDERR, "FAIL PHP diagnostics in owned HTTP server log\nArtifacts: $root\n");
    $exit = 1;
} else {
    echo "PASS owned HTTP server log has no PHP warnings/fatals\n";
}
exit($exit);
