<?php
// Drive the existing authenticated staged HTTP contract over container loopback TLS.
// Do not print transport bodies, SQL, logs, credentials, plugin names or exception text.
$cookie='';
function request($action='', $fields=null, $script='cms_tools.php') {
    global $cookie;
    $context=stream_context_create(['http'=>['method'=>$fields===null?'GET':'POST','timeout'=>30,'follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>true,
        'header'=>"Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",'content'=>$fields===null?'':http_build_query($fields)],
        'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'cafile'=>'/run/tls/ca.crt']]);
    $stream=@fopen('https://staging.invalid:8443/'.$script.($action?'?action='.$action:''),'rb',false,$context);
    if (!$stream) throw new RuntimeException('Staging HTTP unavailable.');
    try {
        $status=null;
        foreach (stream_get_meta_data($stream)['wrapper_data'] ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ ([0-9]{3})#',$h,$m)) $status=(int)$m[1];
            if (preg_match('/^Set-Cookie: (PHPSESSID=[A-Za-z0-9,-]+);/i',$h,$m)) $cookie=$m[1];
        }
        $body=stream_get_contents($stream,1048577);
        if ($status!==200 || !is_string($body) || strlen($body)>1048576 || !feof($stream)) throw new RuntimeException('Staging request failed.');
        return $body;
    } finally { fclose($stream); }
}
try {
    for ($i=0;$i<200 && !file_exists('/run/upgrade-ready');$i++) usleep(100000);
    if (!file_exists('/run/upgrade-ready')) throw new RuntimeException('Initialization unavailable.');
    // Verify actual mod_php effective identity/capabilities/settings, not CLI PHP defaults.
    $settings=null;
    // Only service startup is polled; never retry a mutation request.
    for ($i=0;$i<100;$i++) { try { $settings=json_decode(request('',null,'cms-runtime-settings.php'),true,16,JSON_THROW_ON_ERROR); break; } catch (Throwable $e) { usleep(100000); } }
    if (!$settings || $settings['uid']===0 || trim($settings['caps'],'0')!=='' || !str_starts_with($settings['server'],'Apache/')) throw new RuntimeException('Non-root Apache PHP could not be proven.');
    $secrets=json_decode(file_get_contents('/run/upgrade-secrets.json'),true,16,JSON_THROW_ON_ERROR);
    $html=request('',['password'=>$secrets['login']]);
    // Login can render a redirect; the authenticated GET retrieves the staged form.
    $html=request();
    if (!preg_match('/name="upgrade_csrf" value="([a-f0-9]{64})"/',$html,$m)) throw new RuntimeException('No authenticated upgrade form.');
    $csrf=$m[1];
    $run=json_decode(request('upgrade_prepare',['csrf'=>$csrf,'backup'=>'yes','separate_host'=>'yes','mode'=>'new','target_host'=>'localhost',
        'target_name'=>'cmstarget','target_user'=>'cmstarget','target_password'=>$secrets['target']]),true,32,JSON_THROW_ON_ERROR);
    unset($secrets);
    for ($i=0;$i<2000;$i++) {
        if (($run['ok'] ?? false)!==true || !preg_match('/^[a-f0-9]{24}$/D',$run['run_id'] ?? '')) throw new RuntimeException('Staged execution failed.');
        if (($run['stage'] ?? '')==='done') break;
        $run=json_decode(request('upgrade_step',['csrf'=>$csrf,'run_id'=>$run['run_id']]),true,32,JSON_THROW_ON_ERROR);
    }
    if (($run['stage'] ?? '')!=='done' || !is_array($run['findings'] ?? null)) throw new RuntimeException('Stage limit exceeded.');
    $counts=['ok'=>0,'warning'=>0,'failed'=>0,'unavailable'=>0];
    foreach ($run['findings'] as $f) { if (!isset($counts[$f['severity'] ?? ''])) throw new RuntimeException('Invalid finding.'); $counts[$f['severity']]++; }
    echo json_encode(['ok'=>true,'ready'=>($run['ready'] ?? false)===true && !$counts['failed'] && !$counts['unavailable'],
        'runtime_verified'=>true,'findings'=>$counts,'settings'=>$settings],JSON_THROW_ON_ERROR);
} catch (Throwable $e) { echo '{"ok":false,"ready":false,"runtime_verified":false,"findings":{"failed":1}}'; exit(1); }
