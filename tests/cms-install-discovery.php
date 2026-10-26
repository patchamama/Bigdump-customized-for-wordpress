<?php
// Synthetic public HTTP tests. The runner chooses a safe TMPDIR; no real site is used.
$root = sys_get_temp_dir().'/discovery-'.bin2hex(random_bytes(6));
mkdir($root, 0700);
$source = file_get_contents(__DIR__.'/../cms_tools.php');
$source = preg_replace('/^<\?php/', '<?php namespace InstallDiscoveryFixture; use \\DirectoryIterator; use \\RecursiveIteratorIterator; use \\RecursiveDirectoryIterator; use \\FilesystemIterator; use \\Throwable; use \\Exception;', $source, 1);
$configs = ['wordpress'=>'wp-config.php', 'joomla'=>'configuration.php', 'drupal'=>'sites/default/settings.php', 'mediawiki'=>'LocalSettings.php'];
$markers = ['wordpress'=>'wp-includes/version.php', 'joomla'=>'includes/version.php', 'drupal'=>'core/lib/Drupal.php', 'mediawiki'=>'includes/DefaultSettings.php'];
function putFixture($path, $data) {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, $data);
}
$payload = '<?php file_put_contents(__DIR__."/EXECUTED", "bad"); /* RAW_CONFIG_SECRET */ define("DB_PASSWORD", "CONFIG_PASSWORD_SECRET");';
// The parent config deliberately changes legacy $abspath, not discovery's destination.
putFixture($root.'/wp-config.php', $payload);
$cases = ['empty', 'config', 'core', 'mixed', 'link', 'nonregular', 'ancestor-link'];
foreach ($cases as $case) {
    mkdir($root.'/'.$case, 0700);
    putFixture($root.'/'.$case.'/cms_tools.php', $source);
    putFixture($root.'/'.$case.'/.migrate_config', json_encode(['password_hash'=>hash('sha256', 'odc_migrate_2026_x9kSyntheticLogin123'), 'keep'=>'unchanged']));
}
foreach ($configs as $cms=>$path) {
    putFixture($root.'/config/'.$path, $payload);
    putFixture($root.'/mixed/'.$path, $payload);
    putFixture($root.'/core/'.$markers[$cms], $payload);
    putFixture($root.'/mixed/'.$markers[$cms], $payload);
}
symlink($root.'/wp-config.php', $root.'/link/wp-config.php');
mkdir($root.'/nonregular/wp-config.php');
mkdir($root.'/ancestor-link/sites');
symlink($root.'/config/sites/default', $root.'/ancestor-link/sites/default');
copy(__DIR__.'/fixtures/cms-install-discovery/prepend.php', $root.'/prepend.php');
$socket = stream_socket_server('tcp://127.0.0.1:0');
$port = substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
$process = proc_open([PHP_BINARY, '-n', '-d', 'display_errors=1', '-d', 'auto_prepend_file='.$root.'/prepend.php', '-d', 'session.save_path='.$root, '-S', '127.0.0.1:'.$port, '-t', $root], [0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['pipe','w']], $pipes);
function request($case, $query, $cookie = '', $data = null, $extra = []) {
    global $port;
    $context = stream_context_create(['http'=>[
        'method'=>$data === null ? 'GET' : 'POST', 'follow_location'=>0,
        'header'=>implode("\r\n", array_merge(['Cookie: '.$cookie, 'Content-Type: application/x-www-form-urlencoded'], $extra)),
        'content'=>$data === null ? '' : http_build_query($data), 'ignore_errors'=>true,
    ]]);
    $stream = fopen('http://127.0.0.1:'.$port.'/'.$case.'/cms_tools.php'.$query, 'rb', false, $context);
    $body = stream_get_contents($stream);
    $headers = stream_get_meta_data($stream)['wrapper_data'];
    fclose($stream);
    return [(int)explode(' ', $headers[0])[1], $body, $headers];
}
function check($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function snapshot($root) {
    $result = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = $file->getPathname();
        if (str_starts_with($file->getFilename(), 'sess_') || $file->getFilename() === 'server.log') continue;
        $result[$path] = is_link($path) ? readlink($path) : hash_file('sha256', $path);
    }
    return $result;
}
$exit = 0;
try {
    // Block on the server's startup announcement, not sleep/retry polling.
    $ready = fgets($pipes[2]);
    check(is_string($ready) && str_contains($ready, 'Development Server'), 'owned HTTP server started');
    $before = snapshot($root);
    [$code,$body,$headers] = request('empty', '?action=cms_install_discover');
    check($code === 401 && $body === '{"error":"Unauthorized."}', 'unauthenticated discovery is JSON401');
    check(in_array('Cache-Control: no-store', $headers, true), 'unauthenticated response is no-store');
    [$unsafeCode,$unsafeBody]=request('nonregular','?action=cms_install_discover');
    check($unsafeCode===401 && $unsafeBody==='{"error":"Unauthorized."}', 'unsafe config unauthenticated discovery remains clean JSON with display_errors enabled');
    foreach ($cases as $case) {
        // Unsafe-config discovery must work with established authority; legacy login is unchanged.
        $loginCase=$case==='nonregular'?'empty':$case;
        [$code,$body,$headers] = request($loginCase, '', '', ['password'=>'SyntheticLogin123']);
        $cookie = '';
        foreach ($headers as $header) if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/', $header, $match)) $cookie = $match[1];
        check($cookie !== '', $case.' uses existing password/session login');
        [$code,$body,$headers] = request($case, '?action=cms_install_discover', $cookie);
        $report = json_decode($body, true);
        check($code === 200 && ($report['schema'] ?? '') === 'cms-tools-install-discovery', $case.' authenticated JSON profile');
        check(in_array('Cache-Control: no-store', $headers, true) && in_array('X-Content-Type-Options: nosniff', $headers, true), $case.' security headers');
        check($report['scope'] === 'app-directory' && $report['database_status'] === 'unverified' && $report['installation_status'] === 'unverified', $case.' no DB or installation inference');
        foreach ($configs as $cms=>$path) {
            $profile = $report['cms'][$cms];
            $expected = in_array($case, ['config','mixed'], true) ? 'regular-file' : 'absent';
            if (($case === 'link' && $cms === 'wordpress') || ($case === 'ancestor-link' && $cms === 'drupal')) $expected = 'unsafe-symlink';
            if ($case === 'nonregular' && $cms === 'wordpress') $expected = 'unsafe-nonregular';
            check($profile['config_status'] === $expected, $case.' '.$cms.' config evidence');
            check($profile['core_files_present'] === in_array($case, ['core','mixed'], true), $case.' '.$cms.' core evidence only');
            check($profile['engine_validation'] === 'version-validation-pending', $cms.' requires release-specific engine validation');
        }
        check($report['cms']['wordpress']['engine_candidates'] === ['mysql','mariadb'], 'WordPress never offers PostgreSQL');
        foreach ($report['php_drivers'] as $available) check(is_bool($available), 'PHP drivers are availability booleans only');
        foreach ([$root, 'RAW_CONFIG_SECRET', 'CONFIG_PASSWORD_SECRET', 'SYNTHETIC_DB_SECRET', 'install_ready'] as $secret) check(!str_contains($body, $secret), 'no secret/path/readiness leak');
        foreach (['&path='.urlencode($root.'/config'), '&path[]=bad&directory=../config'] as $query) {
            [$pathCode,$pathBody] = request($case, '?action=cms_install_discover'.$query, $cookie);
            check($pathCode === 200 && $pathBody === $body, 'arbitrary destination input ignored');
        }
        [$postCode,$postBody] = request($case, '?action=cms_install_discover', $cookie, ['set_password'=>1,'new_password'=>'Attacker123','new_password2'=>'Attacker123']);
        check($postCode === 405 && json_decode($postBody,true)['error'] === 'GET required.', 'discovery POST cannot reach password writes');
        [$machineCode,$machineBody,$machineHeaders] = request($case, '?action=cms_install_discover&token=bad', $cookie);
        check($machineCode === 401 && $machineBody === '{"error":"Unauthorized."}' && !preg_grep('/^Set-Cookie:/i', $machineHeaders), 'machine token precedence preserved');
        [$bearerCode,$bearerBody] = request($case, '?action=cms_install_discover', $cookie, null, ['Authorization: Bearer bad']);
        check($bearerCode === 401 && $bearerBody === '{"error":"Unauthorized."}', 'Bearer cannot grant installer access');
    }
    check(snapshot($root) === $before, 'no source/settings/config mutation, config execution or DB probes');
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL '.$e->getMessage()."\nArtifacts: $root\n");
    $exit = 1;
} finally {
    proc_terminate($process);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($process);
    file_put_contents($root.'/server.log', $stderr, FILE_APPEND);
}
exit($exit);
