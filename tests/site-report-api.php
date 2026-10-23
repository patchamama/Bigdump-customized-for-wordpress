<?php
// Public HTTP contracts against an owned synthetic installation, never a real site.
$root = sys_get_temp_dir() . '/report-api-' . bin2hex(random_bytes(6));
mkdir($root, 0700); mkdir($root.'/nested', 0700); $site = $root.'/nested';
$source = file_get_contents(__DIR__.'/../cms_tools.php');
$source = preg_replace('/^<\?php/', '<?php namespace SiteReportFixture; use \\DirectoryIterator; use \\RecursiveIteratorIterator; use \\RecursiveDirectoryIterator; use \\FilesystemIterator; use \\Throwable; use \\Exception;', $source, 1);
file_put_contents($site.'/cms_tools.php', $source);
if (is_file(__DIR__.'/../cms_tool.php')) copy(__DIR__.'/../cms_tool.php', $site.'/cms_tool.php');
copy(__DIR__.'/fixtures/site-report-api/prepend.php', $site.'/prepend.php');
$original = ['password_hash'=>hash('sha256', 'odc_migrate_2026_x9kSyntheticLogin123'), 'unrelated'=>'KEEP'];
file_put_contents($site.'/.migrate_config', json_encode($original));
file_put_contents($site.'/wp-config.php', "<?php define('DB_PASSWORD', 'CONFIG_SECRET');");
mkdir($site.'/wp-includes'); file_put_contents($site.'/wp-includes/version.php', "<?php \$wp_version = '6.8.1';");
mkdir($site.'/.migrate_exports', 0700);
file_put_contents($site.'/.migrate_exports/legacy_0123456789ab.sql', "SELECT 1;\n");
$socket = stream_socket_server('tcp://127.0.0.1:0');
$port = substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
$process = proc_open([PHP_BINARY, '-n', '-d', 'auto_prepend_file='.$site.'/prepend.php', '-d', 'session.save_path='.$root, '-S', '127.0.0.1:'.$port, '-t', $root], [0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']], $pipes);
$cookie = '';
function req($path, $data = null, $headers = [], $method = null) {
    global $port;
    $ctx = stream_context_create(['http'=>['method'=>$method ?? ($data === null ? 'GET' : 'POST'), 'header'=>implode("\r\n", array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers)), 'content'=>$data === null ? '' : http_build_query($data), 'ignore_errors'=>true]]);
    $stream = fopen('http://127.0.0.1:'.$port.'/nested/'.$path, 'rb', false, $ctx);
    $body = stream_get_contents($stream); $headers = stream_get_meta_data($stream)['wrapper_data']; fclose($stream);
    return [(int)explode(' ', $headers[0])[1], $body, $headers];
}
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function denied($path, $data = null, $headers = []) {
    global $site;
    $before = file_get_contents($site.'/.migrate_config');
    [$code,$body,$response] = req($path,$data,$headers);
    check($code === 401 && $body === '{"error":"Unauthorized."}', 'stable JSON401 for rejected machine request');
    check($before === file_get_contents($site.'/.migrate_config'), 'rejection preserves config');
    check(!preg_grep('/^Set-Cookie:/i', $response), 'machine rejection creates no session');
}
$exit = 0;
try {
    for ($i=0;$i<100;$i++) { $s=@fsockopen('127.0.0.1',$port); if ($s) { fclose($s); break; } usleep(50000); }
    [$code,$body,$legacyHeaders] = req('cms_tools.php?action=download&token=0123456789ab&file=legacy_0123456789ab.sql&lang=es');
    check($code === 200 && $body === "SELECT 1;\n" && in_array('Content-Disposition: attachment; filename="legacy_0123456789ab.sql"', $legacyHeaders, true), 'legacy download token still serves exact SQL attachment');
    check(count(preg_grep('/^Set-Cookie: migrate_lang=es;/i', $legacyHeaders)) === 1, 'legacy download language cookie behavior preserved');
    [$code,$body] = req('cms_tools.php?action=download&token=abcdef&file=legacy_0123456789ab.sql');
    check($code === 403 && $body === 'Invalid token', 'legacy rejected download keeps original status and text');
    [$code,$body] = req('cms_tools.php?action=site_report_token', ['operation'=>'create']);
    check($code === 401 && $body === '{"error":"Unauthorized."}', 'unauthenticated token creation denied');
    check(json_decode(file_get_contents($site.'/.migrate_config'),true) === $original, 'unauthenticated creation has no writes');
    [$code,$body,$headers] = req('cms_tools.php', ['password'=>'SyntheticLogin123']);
    foreach ($headers as $h) if (preg_match('/^Set-Cookie: ([^;]+)/i',$h,$m)) $cookie=$m[1];
    $auth = ['Cookie: '.$cookie];
    [$code,$html] = req('cms_tools.php', null, $auth);
    preg_match('/id="report_api_csrf" value="([a-f0-9]+)"/', $html, $m); $csrf=$m[1] ?? '';
    check(strlen($csrf) === 64, 'authenticated CSRF binding');
    [$code,$body] = req('cms_tools.php?action=site_report_token', ['operation'=>'create','csrf'=>'wrong'], $auth);
    check($code===403 && $body==='{"error":"Invalid API session."}', 'CSRF rejection');
    check(json_decode(file_get_contents($site.'/.migrate_config'),true)===$original, 'CSRF preserves config');
    foreach ([['operation'=>'create','csrf'=>['bad']], ['operation'=>['bad'],'csrf'=>$csrf]] as $bad) {
        [$code,$body]=req('cms_tools.php?action=site_report_token',$bad,$auth);
        check(($code===403 && $body==='{"error":"Invalid API session."}') || ($code===400 && $body==='{"error":"Invalid API operation."}'),'array management fields rejected safely');
        check(json_decode(file_get_contents($site.'/.migrate_config'),true)===$original,'invalid management preserves config');
    }
    [$code,$body]=req('cms_tools.php?action=site_report_token',null,$auth);
    check($code===405 && $body==='{"error":"Explicit POST required."}','management GET rejected');
    $manage = function($op) use ($csrf,$auth) { return req('cms_tools.php?action=site_report_token',['operation'=>$op,'csrf'=>$csrf],$auth); };
    [$code,$body] = $manage('create'); $url=json_decode($body,true)['url'] ?? '';
    check($code===200 && str_starts_with($url,'https://127.0.0.1:'.$GLOBALS['port'].'/nested/cms_tool.php?action=site_report_api&token='), 'one-time current-directory HTTPS URL');
    parse_str(parse_url($url,PHP_URL_QUERY),$q); $token=$q['token'] ?? '';
    check(strlen($token)===64 && ctype_xdigit($token), '256-bit token');
    $config=json_decode(file_get_contents($site.'/.migrate_config'),true);
    check($config['password_hash']===$original['password_hash'] && $config['unrelated']==='KEEP' && $config['site_report_api_hash']===hash('sha256',$token) && !str_contains(json_encode($config),$token), 'hash-only merge persistence');
    [$code,$body] = req('cms_tools.php',null,$auth); check(!str_contains($body,$token), 'URL never restored on reload');
    foreach (['cms_tool.php?action=site_report_api&token='.$token,'cms_tools.php?action=site_report_api'] as $path) {
        [$code,$body,$headers]=req($path,null,['Authorization: Bearer '.$token]);
        check($code===200 && (json_decode($body,true)['schema']??'')==='cms-tools-site-report','fresh public JSON without cookies');
        check(!preg_grep('/^Set-Cookie:/i',$headers) && in_array('Cache-Control: no-store',$headers) && in_array('X-Content-Type-Options: nosniff',$headers),'machine security headers');
        foreach ([$token,$config['site_report_api_hash'],$original['password_hash'],'CONFIG_SECRET','SYNTHETIC_DB_SECRET'] as $secret) check(!str_contains($body,$secret),'report excludes secrets');
    }
    file_put_contents($site.'/wp-includes/version.php', "<?php \$wp_version = '6.9.9';");
    [$code,$body]=req('cms_tool.php?action=site_report_api&token='.$token);
    check((json_decode($body,true)['wordpress']['version']??'')==='6.9.9','report collected anew');
    foreach (['','&token=bad','&token[]=bad','&token=bad&lang=es'] as $suffix) denied('cms_tool.php?action=site_report_api'.$suffix);
    denied('cms_tools.php?action=site_report_api&token[]=bad');
    denied('cms_tool.php?action[]=site_report_api&token='.$token);
    denied('cms_tool.php?action=site_report_api', null, ['Authorization: Bearer bad']);
    denied('cms_tools.php?action=get_wpconfig&token='.$token, ['set_password'=>1], $auth);
    denied('cms_tools.php?action=site_report_github', [], ['Authorization: Bearer '.$token]);
    [$code,$body,$headers]=req('cms_tool.php?action=site_report_api&token='.$token.'&lang=es');
    check($code===200 && !preg_grep('/^Set-Cookie:/i',$headers),'machine language parameter cannot write cookies');
    foreach (['get_wpconfig','site_report_github','diag_maintenance','bigdump','site_report','test_db'] as $action) denied('cms_tool.php?action='.$action.'&token='.$token,['set_password'=>1,'new_password'=>'Attacker123','new_password2'=>'Attacker123'],$auth);
    denied('cms_tools.php?action=site_report_api&token='.$token,['set_password'=>1,'new_password'=>'Attacker123','new_password2'=>'Attacker123'],$auth);
    $beforeOptions=file_get_contents($site.'/.migrate_config');
    [$code,$body,$headers]=req('cms_tool.php?action=site_report_api',null,[],'OPTIONS');
    check($code===204 && $body==='' && !preg_grep('/^Set-Cookie:/i',$headers) && $beforeOptions===file_get_contents($site.'/.migrate_config'),'OPTIONS has no report or auth writes');
    [$code,$body]=req('cms_tool.php?action=site_report_api&token='.$token.'&http=1');
    check($code===400 && $body==='{"error":"HTTPS required."}','HTTP refuses token use');
    foreach (['write','short','permissions','rename'] as $failure) {
        foreach (['create','rotate','revoke'] as $operation) {
            $beforeFailure = file_get_contents($site.'/.migrate_config');
            [$code,$body] = req('cms_tools.php?action=site_report_token&fail_config='.$failure, ['operation'=>$operation,'csrf'=>$csrf], $auth);
            check($code === 503 && $body === '{"error":"API token could not be saved. Check configuration permissions and try again."}',
                'persistence '.$failure.' never claims '.$operation.' success');
            check(file_get_contents($site.'/.migrate_config') === $beforeFailure, 'failed '.$operation.' preserves exact existing credentials and settings');
            [$activeCode,$activeBody] = req('cms_tool.php?action=site_report_api&token='.$token);
            check($activeCode === 200 && (json_decode($activeBody,true)['schema'] ?? '') === 'cms-tools-site-report', 'failed '.$operation.' honestly leaves old token active');
            check(count(glob($site.'/.migrate_config-*')) === 0, 'failed persistence cleans private temporary files');
        }
    }
    [$code,$body]=$manage('rotate'); parse_str(parse_url(json_decode($body,true)['url'],PHP_URL_QUERY),$new);
    check($new['token']!==$token,'independent rotation'); denied('cms_tool.php?action=site_report_api&token='.$token);
    [$code,$body]=$manage('revoke'); check($code===200 && $body==='{"ok":true}','explicit revoke');
    denied('cms_tool.php?action=site_report_api&token='.$new['token']);
    $finalConfig=json_decode(file_get_contents($site.'/.migrate_config'),true);
    check($finalConfig['password_hash']===$original['password_hash'] && $finalConfig['unrelated']==='KEEP' && $finalConfig['site_report_api_hash']===null,'rotation and revocation preserve every unrelated config key');
    // A missing config must not reopen password bootstrap through the alias.
    file_put_contents($site.'/.migrate_config', '{}');
    denied('cms_tool.php?action=site_report_api', ['set_password'=>1,'new_password'=>'Attacker123','new_password2'=>'Attacker123']);
    foreach (glob($root.'/sess_*') as $session) check(!str_contains(file_get_contents($session),$token) && !str_contains(file_get_contents($session),$new['token']),'no token session persistence');
} catch (Throwable $e) { fwrite(STDERR,'FAIL '.$e->getMessage()."\nArtifacts: $root\n"); $exit=1; }
finally { proc_terminate($process); proc_close($process); }
exit($exit);
