<?php
// Public HTTP contracts. All files, sessions, databases, archives and HTTPS responses are synthetic.
$root=sys_get_temp_dir().'/wordpress-upgrade-'.bin2hex(random_bytes(6)); mkdir($root,0700);
copy(__DIR__.'/fixtures/wordpress-upgrade/prepend.php',$root.'/prepend.php');
$source=file_get_contents(__DIR__.'/../cms_tools.php');
$source=preg_replace('/^<\?php/','<?php namespace UpgradeFixture; use \\DirectoryIterator; use \\RecursiveIteratorIterator; use \\RecursiveDirectoryIterator; use \\FilesystemIterator; use \\Throwable; use \\Exception; require '.var_export($root.'/prepend.php',true).';',$source,1);
file_put_contents($root.'/cms_tools.php',$source);
$config=['password_hash'=>hash('sha256','odc_migrate_2026_x9kSyntheticLogin123'),'unrelated'=>'preserve-me'];
file_put_contents($root.'/.migrate_config',json_encode($config));
file_put_contents($root.'/scenario','safe');
file_put_contents($root.'/target.json',json_encode(['tables'=>[],'writes'=>0]));
$options=['siteurl'=>'https://wp.fixture.test','home'=>'https://wp.fixture.test','active_plugins'=>serialize(['sample/main.php']),'upload_path'=>'','upload_url_path'=>'','blog_public'=>'1'];
$rows=[]; foreach ($options as $key=>$value) $rows[]=['option_name'=>$key,'option_value'=>$value];
$sourceDb=json_encode(['tables'=>['wp_options'=>$rows],'writes'=>0]); file_put_contents($root.'/source.json',$sourceDb);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket);
$process=proc_open([PHP_BINARY,'-n','-d','session.save_path='.$root,'-S','127.0.0.1:'.$port,'-t',$root],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);
$cookie='';
function request($action='', $data=null, $script='/cms_tools.php') {
    global $port,$cookie;
    $context=stream_context_create(['http'=>['method'=>$data===null?'GET':'POST','header'=>"Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",'content'=>$data===null?'':http_build_query($data),'ignore_errors'=>true,'timeout'=>10]]);
    $stream=fopen('http://127.0.0.1:'.$port.$script.($action?'?action='.$action:''),'rb',false,$context);
    $body=stream_get_contents($stream); $headers=stream_get_meta_data($stream)['wrapper_data']; fclose($stream);
    foreach ($headers as $header) if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i',$header,$m)) $cookie=$m[1];
    preg_match('/\s(\d{3})\s/',$headers[0],$m);
    return [(int)$m[1],$body,json_decode($body,true)];
}
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function target() { global $root; return json_decode(file_get_contents($root.'/target.json'),true); }
function active() { foreach (target()['tables']['wp_options'] ?? [] as $row) if ($row['option_name']==='active_plugins') return unserialize($row['option_value'],['allowed_classes'=>false]); return null; }
function reset_target($scenario='safe') { global $root; file_put_contents($root.'/scenario',$scenario); file_put_contents($root.'/target.json',json_encode(['tables'=>[],'writes'=>0])); }
function finish($run, $expectFailure=false) {
    global $csrf;
    // This fixture can require 100 asset steps plus preparation and plugin probes.
    for ($i=0;$i<160;$i++) {
        [$status,$body,$next]=request('upgrade_step',['csrf'=>$csrf,'run_id'=>$run['run_id']]);
        check(!str_contains($body,'TARGET_SECRET') && !str_contains($body,'SOURCE_SECRET'),'step JSON excludes credentials');
        if ($status!==200) { if ($expectFailure) return [$status,$next]; throw new RuntimeException('Unexpected stage failure: '.$body); }
        $run=$next;
        if ($run['stage']==='done') return [$status,$run];
    }
    throw new RuntimeException('Bounded stage count exceeded');
}
$exit=0;
try {
    for ($i=0;$i<100;$i++) { $ready=@fsockopen('127.0.0.1',$port); if ($ready) { fclose($ready); break; } usleep(50000); }
    [, $body]=request('upgrade_prepare',[]); check(str_contains($body,'name="password"'),'session authentication required');
    request('',['password'=>'SyntheticLogin123']);
    [$code,,$data]=request('upgrade_prepare'); check($code===405 && $data['error']==='Explicit POST required.','GET cannot prepare');
    [$code,,$data]=request('upgrade_prepare',[]); check($code===403 && $data['error']==='Invalid upgrade session.','CSRF required');
    [, $body]=request(); preg_match('/name="upgrade_csrf" value="([a-f0-9]{64})"/',$body,$m); $csrf=$m[1] ?? '';
    check(strlen($csrf)===64 && str_contains($body,'full database backup'),'backup guidance before actions');
    check(str_contains($body,'name="separate_host"') && str_contains($body,'CMS_TOOLS_PRODUCTION_URL') && str_contains($body,'retained in session and owned configuration') && !str_contains($body,'admission (preview)'),'markup exposes separate-host setup and truthful credential retention');
    [$code,,$data]=request('upgrade_prepare',['csrf'=>$csrf]); check($code===400 && $data['error']==='Confirm a restorable files and full database backup first.','backup acknowledgement required');
    [$code,,$data]=request('upgrade_prepare',['csrf'=>$csrf,'backup'=>'yes','mode'=>'legacy','path'=>'../wp-config.php']); check($code===400 && $data['error']==='Choose new or verified reuse; arbitrary paths are not accepted.','arbitrary and legacy paths rejected');
    [$code,,$data]=request('upgrade_prepare',['csrf'=>$csrf,'backup'=>'yes','separate_host'=>'yes','mode'=>'reuse']); check($code===409 && $data['error']==='No verified upgrade-owned run in this session.','unowned reuse rejected');
    [$code,,$data]=request('upgrade_prepare',['csrf'=>$csrf,'backup'=>'yes','separate_host'=>'yes','mode'=>'new']); check($code===409 && $data['error']==='A readable WordPress source is required.','non-WordPress rejected');
    $sourceConfig="<?php\ndefine('DB_HOST', 'localhost');\ndefine('DB_NAME', 'live');\ndefine('DB_USER', 'live_user');\ndefine('DB_PASSWORD', 'SOURCE_SECRET');\n\$table_prefix = 'wp_';\nfile_put_contents(__DIR__.'/EXECUTED', 'bad');\n";
    file_put_contents($root.'/wp-config.php',$sourceConfig);
    foreach (['sample','broken'] as $plugin) { mkdir($root.'/wp-content/plugins/'.$plugin,0700,true); file_put_contents($root.'/wp-content/plugins/'.$plugin.'/main.php',"<?php\n/*\nPlugin Name: Synthetic $plugin\nRequires PHP: 8.0\nRequires at least: 6.0\n*/\nfile_put_contents(__DIR__.'/EXECUTED','bad');"); }
    mkdir($root.'/wp-content/uploads',0700); file_put_contents($root.'/wp-content/uploads/test.png','SYNTHETIC IMAGE');
    $fields=['csrf'=>$csrf,'backup'=>'yes','separate_host'=>'yes','mode'=>'new','target_host'=>'localhost','target_name'=>'sandbox_db','target_user'=>'sandbox_user','target_password'=>'TARGET_SECRET'];
    $beforeConfig=file_get_contents($root.'/.migrate_config'); $beforeTarget=target();
    [$code,,$data]=request('upgrade_prepare',$fields);
    check($code===409 && ($data['error'] ?? '')==='Separate staging configuration is required. Follow the staging preparation guide before running upgrade tests.' && target()===$beforeTarget && file_get_contents($root.'/.migrate_config')===$beforeConfig && !file_exists($root.'/http.log') && !glob($root.'/cms-upgrade-*'),'unmarked installation rejected before network, target writes or run creation');
    $declarations="define('WP_ENVIRONMENT_TYPE', 'staging');\ndefine('CMS_TOOLS_PRODUCTION_URL', 'https://production.fixture.test');\ndefine('CMS_TOOLS_STAGING_URL', 'https://wp.fixture.test');\ndefine('CMS_TOOLS_ISOLATION_VERIFIED', 'separate-host');\n";
    $sourceConfig=str_replace("<?php\n","<?php\n".$declarations,$sourceConfig);
    $unsafeConfig=$sourceConfig;
    $sourceConfig=str_replace("file_put_contents(__DIR__.'/EXECUTED', 'bad');\n",'',$sourceConfig);
    $sourceConfig .= "define('AUTH_KEY', 'synthetic\$literal-key');\ndefine('WP_DEBUG', false);\nif ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }\nrequire_once ABSPATH . 'wp-settings.php';\n";
    file_put_contents($root.'/wp-config.php',$sourceConfig);
    $edgeFailures=[];
    $edge=function ($ok,$message) use (&$edgeFailures) { if ($ok) check(true,$message); else { echo "RED $message\n"; $edgeFailures[]=$message; } };
    foreach (['executable-config','wrong-source-origin','source-global-grants'] as $case) {
        reset_target($case==='source-global-grants'?'source_global':'safe');
        if (file_exists($root.'/http.log')) unlink($root.'/http.log');
        file_put_contents($root.'/wp-config.php',$case==='executable-config'?$unsafeConfig:$sourceConfig);
        $database=json_decode($sourceDb,true);
        if ($case==='wrong-source-origin') foreach ($database['tables']['wp_options'] as &$row) { if ($row['option_name']==='siteurl') $row['option_value']='https://production.fixture.test'; } unset($row);
        file_put_contents($root.'/source.json',json_encode($database));
        $beforeTarget=target(); $beforeConfig=file_get_contents($root.'/.migrate_config'); $beforeDirs=glob($root.'/cms-upgrade-*');
        [$code,,$data]=request('upgrade_prepare',$fields);
        $expected=['executable-config'=>'Only literal staging configuration and the standard WordPress bootstrap are supported. Remove executable configuration or prepare a minimal separate staging config.','wrong-source-origin'=>'The baseline siteurl must match the configured staging URL, not production. Correct the restored staging database before testing.','source-global-grants'=>'Cannot verify the baseline database identity and SELECT-only grants. Use a direct read-only account scoped only to the staging baseline schema.'];
        $edge($code===409 && ($data['error'] ?? '')===$expected[$case] && target()===$beforeTarget && file_get_contents($root.'/.migrate_config')===$beforeConfig && glob($root.'/cms-upgrade-*')===$beforeDirs && !file_exists($root.'/http.log') && !file_exists($root.'/EXECUTED') && file_get_contents($root.'/source.json')===json_encode($database),'staging identity rejection has no effects: '.$case);
    }
    file_put_contents($root.'/wp-config.php',$sourceConfig); file_put_contents($root.'/source.json',$sourceDb); reset_target();
    if ($edgeFailures) throw new RuntimeException('Staging identity RED: '.implode(', ',$edgeFailures));
    $bootstrap="if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }\nrequire_once ABSPATH . 'wp-settings.php';\n";
    $literalConfig=str_replace($bootstrap,'',$sourceConfig);
    $unsupported=[
        'function-call'=>$literalConfig."file_put_contents(__DIR__.'/EXECUTED', 'bad');\n",
        'include'=>$literalConfig."include __DIR__.'/effects.php';\n",
        'conditional'=>$literalConfig."if (false) { define('WP_DEBUG_LOG', true); }\n",
        'environment'=>str_replace("'SOURCE_SECRET'","getenv('DB_PASSWORD')",$sourceConfig),
        'concatenation'=>str_replace("'SOURCE_SECRET'","'SOURCE_' . 'SECRET'",$sourceConfig),
        'duplicate'=>$literalConfig."define('WP_DEBUG', true);\n",
        'unsupported-constant'=>$literalConfig."define('WP_SITEURL', 'https://production.fixture.test');\n",
        'bootstrap-effects'=>$sourceConfig."file_put_contents(__DIR__.'/EXECUTED', 'bad');\n",
        'unresolved-abspath'=>$literalConfig."require_once ABSPATH . 'wp-settings.php';\n",
        'wrong-bootstrap'=>str_replace('wp-settings.php','effects.php',$sourceConfig),
        'invalid-integer'=>str_replace("define('WP_DEBUG', false);","define('WP_DEBUG', 08);",$sourceConfig)];
    foreach ($unsupported as $case=>$text) {
        file_put_contents($root.'/wp-config.php',$text);
        [$code,,$data]=request('upgrade_prepare',$fields);
        check($code===409 && ($data['ready'] ?? null)===false && target()===$beforeTarget && file_get_contents($root.'/.migrate_config')===$beforeConfig && !file_exists($root.'/http.log') && !glob($root.'/cms-upgrade-*') && !file_exists($root.'/EXECUTED'),'unsupported PHP fails closed without effects: '.$case);
    }
    foreach (['literal-only'=>$literalConfig,'dirname-bootstrap'=>str_replace("__DIR__ . '/'","dirname(__FILE__) . '/'",$sourceConfig),'unbraced-bootstrap'=>str_replace($bootstrap,"if (!defined(\"ABSPATH\")) define(\"ABSPATH\", __DIR__ . \"/\");\nrequire_once ABSPATH . \"wp-settings.php\";\n",$sourceConfig)] as $case=>$text) {
        file_put_contents($root.'/wp-config.php',$text); reset_target('beta');
        [$code,,$data]=request('upgrade_prepare',$fields);
        check($code===409 && ($data['error'] ?? '')==='No verified stable WordPress release offer is available.' && file_exists($root.'/http.log') && !glob($root.'/cms-upgrade-*') && target()['writes']===0 && !file_exists($root.'/EXECUTED'),'supported literal config reaches metadata without execution: '.$case);
        unlink($root.'/http.log');
    }
    file_put_contents($root.'/wp-config.php',$sourceConfig);
    foreach (['source_write','source_all','source_role','source_delegation','source_other','source_table','source_missing','source_empty','source_unavailable','source_identity'] as $case) {
        reset_target($case); [$code,,$data]=request('upgrade_prepare',$fields);
        check($code===409 && ($data['error'] ?? '')===$expected['source-global-grants'] && target()['writes']===0 && !file_exists($root.'/http.log') && !glob($root.'/cms-upgrade-*') && file_get_contents($root.'/.migrate_config')===$beforeConfig,'baseline direct SELECT-only proof required: '.$case);
    }
    reset_target();
    foreach ([['CMS_TOOLS_PRODUCTION_URL', 'https://wp.fixture.test'],['WP_ENVIRONMENT_TYPE','production'],['CMS_TOOLS_ISOLATION_VERIFIED','pending'],['CMS_TOOLS_STAGING_URL','http://wp.fixture.test']] as [$constant,$value]) {
        $changed=preg_replace("/define\\('".$constant."', '[^']*'\\);/",'define('.var_export($constant,true).', '.var_export($value,true).');',$sourceConfig);
        file_put_contents($root.'/wp-config.php',$changed);
        [$code,,$data]=request('upgrade_prepare',$fields);
        check($code===409 && ($data['ready'] ?? null)===false && target()===$beforeTarget && file_get_contents($root.'/.migrate_config')===$beforeConfig && !file_exists($root.'/http.log') && !glob($root.'/cms-upgrade-*'),'invalid staging declaration has no side effects: '.$constant);
    }
    file_put_contents($root.'/wp-config.php',$sourceConfig);
    foreach ([['target_name'=>'live'],['target_user'=>'live_user'],['target_host'=>'localhost;dbname=live'],['target_name'=>'sandbox_db`'],['target_host'=>['bad']]] as $bad) {
        [$code,,$data]=request('upgrade_prepare',array_replace($fields,$bad));
        check($code===400 && $data['ready']===false && target()['writes']===0 && !file_exists($root.'/http.log'),'unsafe target rejected without effects');
    }
    file_put_contents($root.'/wp-config.php',$sourceConfig."define('DB_NAME', 'other');\n");
    [$code,,$data]=request('upgrade_prepare',$fields); check($code===409 && $data['error']==='Source database identity is ambiguous; use literal unconditional database definitions without earlier includes.' && target()['writes']===0,'ambiguous source identity rejected');
    file_put_contents($root.'/wp-config.php',$sourceConfig);
    foreach (['global','role','delegation','wildcard','other_schema'] as $case) {
        reset_target($case); [$code,,$data]=request('upgrade_prepare',$fields);
        check($code===409 && $data['error']==='Cannot establish database isolation. Ask the host for a direct account restricted only to the empty sandbox schema, without roles or global grants.' && target()['writes']===0 && !file_exists($root.'/http.log'),'unsafe grants rejected: '.$case);
    }
    reset_target('nonempty'); [$code,,$data]=request('upgrade_prepare',$fields); check($code===400 && $data['error']==='The new sandbox schema must be empty. No tables were changed.' && target()['writes']===0,'nonempty destination unchanged');
    foreach (['symlink','hardlink'] as $kind) {
        reset_target(); $beforeTarget=target(); $beforeConfig=file_get_contents($root.'/.migrate_config');
        file_put_contents($root.'/.fixture-htaccess',"# SYNTHETIC HOSTING RULES\n");
        if ($kind==='symlink') symlink($root.'/.fixture-htaccess',$root.'/.htaccess'); else link($root.'/.fixture-htaccess',$root.'/.htaccess');
        [$code,,$data]=request('upgrade_prepare',$fields);
        check($code===409 && ($data['error'] ?? '')==='Shared or unreadable root hosting rules require separate-host remediation before testing.' && target()===$beforeTarget && file_get_contents($root.'/.migrate_config')===$beforeConfig && !file_exists($root.'/http.log'),'shared root hosting rules rejected before network or writes: '.$kind);
        unlink($root.'/.htaccess'); unlink($root.'/.fixture-htaccess');
    }
    // Official WordPress 6.8.3 bundled Akismet bytes, authenticated by the parent
    // against https://wordpress.org/wordpress-6.8.3.zip; SHA256 is pinned in both source guards.
    $defaultRules=file_get_contents(__DIR__.'/fixtures/wordpress-upgrade/akismet-default-6.8.3.htaccess');
    check(hash('sha256',$defaultRules)==='6d8db93cbee3b408b9b8972684d20f33d739efd11d32ba584d6df272a7873011','authenticated old Akismet fixture');
    mkdir($root.'/wp-content/plugins/akismet',0700);
    $sourceDefaults=['wp-content/plugins/akismet/.htaccess'=>$defaultRules,'wp-content/index.php'=>"<?php\n// Silence is golden.\n"];
    $sourceError='MU plugins, drop-ins and content server overrides require an offline isolated upgrade.';
    $refused=[
        ['wp-content/plugins/akismet/.htaccess',$defaultRules.'# modified'],
        ['wp-content/plugins/akismet/.htaccess',str_repeat('x',65537)],
        ['wp-content/plugins/akismet/.HTACCESS',$defaultRules],
        ['wp-content/plugins/sample/.htaccess',$defaultRules],
        ['wp-content/plugins/akismet/.user.ini',$defaultRules],
        ['wp-content/plugins/akismet/php.ini',$defaultRules],
        ['wp-content/index.php',"<?php echo 'executed';"],
        ['wp-content/index.php',"<?php /* benign */ ?>HTML"],
        ['wp-content/index.php',str_repeat(' ',65537)],
        ['wp-content/other.php',"<?php // Silence is golden.\n"],
        ['wp-content/mu-plugins/default.php',"<?php // passive\n"]
    ];
    foreach ($refused as [$path,$text]) {
        if (!is_dir(dirname($root.'/'.$path))) mkdir(dirname($root.'/'.$path),0700,true);
        file_put_contents($root.'/'.$path,$text); reset_target();
        $beforeTarget=target(); $beforeSource=file_get_contents($root.'/source.json');
        [$code,$body,$data]=request('upgrade_prepare',$fields);
        check($code===409 && $body===json_encode(['ok'=>false,'ready'=>false,'error'=>$sourceError]) && target()===$beforeTarget && file_get_contents($root.'/source.json')===$beforeSource && file_get_contents($root.'/'.$path)===$text && !file_exists($root.'/http.log') && !glob($root.'/cms-upgrade-*'),'source refusal exact JSON and no effects: '.$path);
        unlink($root.'/'.$path);
    }
    foreach (['symlink','hardlink'] as $kind) {
        $path=$root.'/wp-content/plugins/akismet/.htaccess';
        file_put_contents($root.'/pinned-rule',$defaultRules);
        if ($kind==='symlink') symlink($root.'/pinned-rule',$path); else link($root.'/pinned-rule',$path);
        reset_target(); $beforeTarget=target();
        [$code,$body]=request('upgrade_prepare',$fields);
        check($code===409 && $body===json_encode(['ok'=>false,'ready'=>false,'error'=>'Shared source files cannot be used for upgrade preparation.']) && target()===$beforeTarget && !file_exists($root.'/http.log') && !glob($root.'/cms-upgrade-*') && file_get_contents($root.'/pinned-rule')===$defaultRules,'known source rule link still refused: '.$kind);
        unlink($path); unlink($root.'/pinned-rule');
    }
    // Inventory captures executable bytes, then the double supplies benign bytes.
    // A parser-only admission without checking the captured hash must not pass.
    file_put_contents($root.'/wp-content/index.php',"<?php echo 'malicious';"); reset_target('index_hash_race');
    $beforeTarget=target(); [$code,$body]=request('upgrade_prepare',$fields);
    check($code===409 && $body===json_encode(['ok'=>false,'ready'=>false,'error'=>$sourceError]) && target()===$beforeTarget && !file_exists($root.'/http.log') && !glob($root.'/cms-upgrade-*'),'index inspection is bound to original inventory hash');
    unlink($root.'/wp-content/index.php');
    $defaultFailures=[];
    foreach ($sourceDefaults as $path=>$text) {
        file_put_contents($root.'/'.$path,$text); reset_target('beta');
        $beforeTarget=target(); $beforeSource=file_get_contents($root.'/source.json');
        [$code,$body,$data]=request('upgrade_prepare',$fields);
        $ok=$code===409 && ($data['error'] ?? '')==='No verified stable WordPress release offer is available.' && file_exists($root.'/http.log') && target()===$beforeTarget && file_get_contents($root.'/source.json')===$beforeSource && file_get_contents($root.'/'.$path)===$text && !glob($root.'/cms-upgrade-*');
        if (!$ok) { echo 'RED standard source admission: '.$path."\n"; $defaultFailures[]=$path; } else check(true,'standard source reaches metadata: '.$path);
        if (file_exists($root.'/http.log')) unlink($root.'/http.log');
        unlink($root.'/'.$path);
    }
    check(!$defaultFailures,'both genuine standard-source rules admitted before metadata');
    foreach ($sourceDefaults as $path=>$text) file_put_contents($root.'/'.$path,$text);
    $metadataErrors=['beta'=>'No verified stable WordPress release offer is available.','bad_origin'=>'No verified stable WordPress release offer is available.','redirect'=>'Official WordPress metadata failed or exceeded its limit. Redirects are not followed.','status0'=>'Official WordPress metadata failed or exceeded its limit. Redirects are not followed.','not_found'=>'Official WordPress metadata failed or exceeded its limit. Redirects are not followed.','server_error'=>'Official WordPress metadata failed or exceeded its limit. Redirects are not followed.','oversized'=>'Official WordPress metadata failed or exceeded its limit. Redirects are not followed.','invalid_json'=>'Invalid official WordPress metadata.','malformed_offers'=>'Invalid official WordPress release offers.','bad_checksum'=>'Invalid official core checksum manifest.','missing_checksum'=>'Official core checksum manifest is incomplete.'];
    foreach ($metadataErrors as $case=>$error) { reset_target($case); [$code,,$data]=request('upgrade_prepare',$fields); check($code===409 && $data['error']===$error && target()['writes']===0,'metadata fails closed: '.$case); }
    foreach (['htaccess','unprotected'] as $case) { reset_target($case); [$code,,$data]=request('upgrade_prepare',$fields); check($code===409 && str_contains($data['error'],'Hosting access rules could not be proven') && target()['writes']===0,'hosting gate before content/credentials: '.$case); }
    $archiveErrors=['invalid_archive'=>'Invalid WordPress ZIP archive.','archive_path'=>'Unsafe archive path.','archive_symlink'=>'Archive links and special files are forbidden.','archive_duplicate'=>'Duplicate archive path.','archive_large'=>'Archive size or checksum coverage is unsafe.','archive_missing'=>'Archive is missing an official checksummed file.','checksum_mismatch'=>'Official package checksum mismatch.'];
    foreach ($archiveErrors as $case=>$error) {
        reset_target($case); [$code,,$run]=request('upgrade_prepare',$fields); check($code===200 && $run['ready']===false && $run['stage']==='download','new owned run admitted: '.$case);
        [$code,$failed]=finish($run,true); check($code===409 && $failed['error']===$error && target()['writes']===0,'unsafe archive has no DB effects: '.$case);
    }
    foreach (['native_path'=>'Unsafe archive path.','native_symlink'=>'Archive links and special files are forbidden.','native_checksum'=>'Official package checksum mismatch.'] as $case=>$error) {
        reset_target($case); [$code,,$run]=request('upgrade_prepare',$fields); check($code===200,'native ZIP run admitted: '.$case);
        [$code,$failed]=finish($run,true); check($code===409 && $failed['error']===$error && target()['writes']===0,'real ZIP failure before DB writes: '.$case);
    }
    foreach (['native_control_root','native_control_nested','native_control_php','native_control_user','native_control_config','native_akismet_checksum'] as $case) {
        reset_target($case); $before=target();
        [$code,,$controlRun]=request('upgrade_prepare',$fields); check($code===200,'protected archive run admitted: '.$case);
        $controlDir=$root.'/cms-upgrade-'.$controlRun['run_id']; $ownedRules=file_get_contents($controlDir.'/.htaccess');
        [$code,$failed]=finish($controlRun,true);
        $error=$case==='native_akismet_checksum'?'Official package checksum mismatch.':'Archive attempted to replace isolation controls.';
        check($code===409 && $failed['error']===$error && target()===$before && file_get_contents($root.'/source.json')===$sourceDb && file_get_contents($controlDir.'/.htaccess')===$ownedRules && !file_exists($controlDir.'/wp-content/plugins/akismet/.htaccess'),'archive rejection preserves source, target counters and owned rules: '.$case);
    }
    reset_target('native_safe'); [$code,,$nativeRun]=request('upgrade_prepare',$fields); check($code===200,'native ZIP happy-path run begins');
    $nativeRules=file_get_contents($root.'/cms-upgrade-'.$nativeRun['run_id'].'/.htaccess');
    [$code,$nativeDone]=finish($nativeRun);
    check($code===200 && $nativeDone['ready']===true && str_starts_with(file_get_contents($root.'/native-package.zip'),'PK') && str_contains(file_get_contents($root.'/native-reader.log'),'native_safe'),'real libzip validates and extracts genuine synthetic ZIP');
    foreach (['wp-content/plugins/akismet/_inc/img/akismet-refresh-logo@2x.png'=>'SYNTHETIC OFFICIAL IMAGE','wp-content/themes/fixture/assets/Inter-VariableFont_slnt,wght.woff2'=>'SYNTHETIC OFFICIAL FONT'] as $path=>$body) {
        check(file_get_contents($root.'/cms-upgrade-'.$nativeDone['run_id'].'/'.$path)===$body && !file_exists($root.'/'.$path),'official @/comma path is verified and independently extracted: '.$path);
    }
    $nativeDir=$root.'/cms-upgrade-'.$nativeDone['run_id'];
    check(!file_exists($nativeDir.'/wp-content/plugins/akismet/.htaccess') && file_get_contents($root.'/wp-content/plugins/akismet/.htaccess')===$defaultRules,'official Akismet rules omitted, genuine source unchanged');
    check(file_get_contents($nativeDir.'/wp-content/index.php')===$sourceDefaults['wp-content/index.php'] && fileinode($nativeDir.'/wp-content/index.php')!==fileinode($root.'/wp-content/index.php'),'passive root content index copied independently');
    check(count(array_filter($nativeDone['findings'],fn($f)=>$f['check']==='source-rules-omitted' && $f['severity']==='warning' && str_contains($f['tip'],'wp-content/plugins/akismet/.htaccess')))===1 && file_get_contents($nativeDir.'/.htaccess')===$nativeRules,'known source omission reported without replacing private rules');
    reset_target(); [$code,,$indexRun]=request('upgrade_prepare',$fields); check($code===200,'passive index admitted for copy mutation test');
    file_put_contents($root.'/wp-content/index.php',"<?php echo 'changed after admission';");
    [$code,$indexFailure]=finish($indexRun,true);
    check($code===409 && $indexFailure['ready']===false && target()['writes']===0 && !file_exists($root.'/cms-upgrade-'.$indexRun['run_id'].'/wp-content/index.php'),'changed index cannot be copied using passive admission');
    file_put_contents($root.'/wp-content/index.php',$sourceDefaults['wp-content/index.php']);
    foreach (['plain_rest','encoded_rest','index_rest','legacy_rest','front_rest','front_encoded_rest'] as $restCase) {
        reset_target($restCase); [$code,,$plainRun]=request('upgrade_prepare',$fields);
        check($code===200,'standard REST source begins: '.$restCase);
        [$code,$plainDone]=finish($plainRun);
        $restSuffix=match($restCase) {'plain_rest'=>'/?rest_route=/','encoded_rest'=>'/?rest_route=%2F','index_rest'=>'/index.php/wp-json/','front_rest'=>'/index.php?rest_route=/','front_encoded_rest'=>'/index.php?rest_route=%2F',default=>'/wp-json/'};
        check(str_contains(file_get_contents($root.'/http.log'),$plainDone['sandbox_url'].$restSuffix),'exact selected REST address requested: '.$restCase);
        check($code===200 && $plainDone['ready']===true && count(array_filter($plainDone['findings'],fn($f)=>$f['check']==='http-rest' && $f['severity']==='ok'))===1 && file_get_contents($root.'/source.json')===$sourceDb && active()===['sample/main.php'],'standard REST passes without source or activation changes: '.$restCase);
    }
    foreach (['foreign_rest','traversal_rest','malformed_rest'] as $restCase) {
        reset_target($restCase); [$code,,$restRun]=request('upgrade_prepare',$fields);
        for ($i=0;$i<100 && $restRun['stage']!=='http';$i++) {
            [$code,,$restRun]=request('upgrade_step',['csrf'=>$csrf,'run_id'=>$restRun['run_id']]);
            check($code===200,'unsafe REST metadata does not bypass prior stages');
        }
        check($restRun['stage']==='http','reached bounded HTTP phase');
        for ($i=0;$i<2;$i++) request('upgrade_step',['csrf'=>$csrf,'run_id'=>$restRun['run_id']]);
        $before=target(); $httpBefore=file_get_contents($root.'/http.log');
        [$code,$restBody,$restRun]=request('upgrade_step',['csrf'=>$csrf,'run_id'=>$restRun['run_id']]);
        check($code===200 && $restRun['ready']===false && count(array_filter($restRun['findings'],fn($f)=>$f['check']==='http-rest' && $f['severity']==='failed'))===1 && target()===$before && file_get_contents($root.'/http.log')===$httpBefore && file_get_contents($root.'/source.json')===$sourceDb && !str_contains($restBody,'TARGET_SECRET'),'unsafe REST address rejected before HTTP/token forwarding or data writes: '.$restCase);
    }
    reset_target(); [$code,,$run]=request('upgrade_prepare',$fields); check($code===200 && $run['stage']==='download' && $run['ready']===false,'actual new workflow begins');
    [$code,$done]=finish($run); check($code===200 && $done['stage']==='done' && $done['ready']===true,'successful synthetic prepare and smoke tests');
    $id=$done['run_id']; $sandbox=$root.'/cms-upgrade-'.$id;
    check(active()===['sample/main.php'] && target()['core_upgrades']===1,'core DB upgrade and original activation restored');
    check(file_get_contents($sandbox.'/wp-content/uploads/test.png')==='SYNTHETIC IMAGE' && fileinode($sandbox.'/wp-content/uploads/test.png')!==fileinode($root.'/wp-content/uploads/test.png'),'independent copied resources');
    $sandboxConfig=file_get_contents($sandbox.'/wp-config.php'); check(str_contains($sandboxConfig,'TARGET_SECRET') && !str_contains($sandboxConfig,'SOURCE_SECRET') && !is_link($sandbox.'/wp-config.php'),'sandbox-owned config never follows live config');
    check(file_get_contents($root.'/.migrate_config')===json_encode($config),'upgrade never persists run IDs or changes authentication and unrelated config');
    preg_match("/hash_equals\\('([a-f0-9]{64})'/",$sandboxConfig,$bootstrapToken);
    $bootstrap=proc_open([PHP_BINARY,'-n','-d','error_reporting=-1','-d','display_errors=stderr','-r','$_SERVER["HTTP_X_CMS_UPGRADE"]=$argv[2]; define("ABSPATH",$argv[1]."/"); require $argv[1]."/wp-config.php"; echo "BOOTSTRAP OK\n";',$sandbox,$bootstrapToken[1] ?? ''],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$bootstrapPipes);
    fclose($bootstrapPipes[0]); $bootstrapOut=stream_get_contents($bootstrapPipes[1]); $bootstrapErr=stream_get_contents($bootstrapPipes[2]); fclose($bootstrapPipes[1]); fclose($bootstrapPipes[2]);
    if ($bootstrapErr!=='') fwrite(STDERR,"Bootstrap diagnostics: ".$bootstrapErr);
    check(proc_close($bootstrap)===0 && $bootstrapOut==="BOOTSTRAP OK\n" && $bootstrapErr==='','generated config supports wp-load pre-defined ABSPATH without warnings');
    $ownerCookie=$cookie; $cookie=''; request('',['password'=>'SyntheticLogin123']);
    [, $otherPage]=request(); preg_match('/name="upgrade_csrf" value="([a-f0-9]{64})"/',$otherPage,$otherCsrf);
    $beforeTarget=target();
    [$code,,$data]=request('upgrade_prepare',['csrf'=>$otherCsrf[1] ?? '', 'backup'=>'yes','separate_host'=>'yes','mode'=>'reuse','run_id'=>$id]);
    check($code===409 && ($data['error'] ?? '')==='No verified upgrade-owned run in this session.' && target()===$beforeTarget && file_get_contents($root.'/.migrate_config')===json_encode($config),'another authenticated session cannot adopt an owned run');
    $cookie=$ownerCookie;
    [$code,,$reuse]=request('upgrade_prepare',['csrf'=>$csrf,'backup'=>'yes','separate_host'=>'yes','mode'=>'reuse','run_id'=>$id]); check($code===200 && $reuse['run_id']===$id && $reuse['stage']==='core-upgrade','verified owned sandbox reused');
    [, $retested]=finish($reuse); check($retested['ready']===true && target()['core_upgrades']===2,'reuse repeats mandatory upgrade and tests');
    file_put_contents($sandbox.'/identity.txt','tampered'); $before=target()['writes'];
    [$code,,$data]=request('upgrade_prepare',['csrf'=>$csrf,'backup'=>'yes','separate_host'=>'yes','mode'=>'reuse','run_id'=>$id]); check($code===409 && str_contains($data['error'],'Upgrade-owned files changed') && target()['writes']===$before,'tampered ownership rejected without DB effects');
    foreach (['core_file_mutation','plugin_failure','plugin_unavailable','missing_asset','http500','http0','unsafe_redirect','wrong_identity','php_failure','core_failure','asset_limit'] as $case) {
        reset_target($case); [$code,,$run]=request('upgrade_prepare',$fields); check($code===200,'failure-case isolated run begins: '.$case);
        [$status,$result]=finish($run,true); $result=$result['run'] ?? $result;
        check($result['ready']===false && ($result['stage']==='done' || $result['stage']==='failed'),'required failure/unavailable blocks readiness: '.$case);
        check(active()===['sample/main.php'],'activation restored after '.$case);
        check(count(array_filter($result['findings'],fn($f)=>in_array($f['severity'],['failed','unavailable'],true) && strlen($f['tip'])>15))>0,'actionable failure tips: '.$case);
    }
    check(file_get_contents($root.'/source.json')===$sourceDb && file_get_contents($root.'/wp-config.php')===$sourceConfig && !file_exists($root.'/EXECUTED'),'source DB/config unchanged and never executed');
    $transactions=file_get_contents($root.'/source-transactions.log');
    $snapshot="SET TRANSACTION ISOLATION LEVEL REPEATABLE READ\nSTART TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY\nROLLBACK\n";
    check($transactions!=='' && str_replace($snapshot,'',$transactions)==='','every source snapshot is a repeatable-read read-only transaction with rollback');
    check(file_get_contents($root.'/.migrate_config')===json_encode($config),'authentication and unrelated settings unchanged after success, reuse and failed stages');
    foreach (['sample','broken'] as $plugin) check(!file_exists($root.'/wp-content/plugins/'.$plugin.'/EXECUTED'),'source plugin never executed: '.$plugin);
    // Separate installations exercise rejections without deleting earlier fixtures.
    foreach (['hardlink','symlink','entry-limit','size-limit','php-override'] as $case) {
        $dir=$root.'/'.$case; mkdir($dir,0700); mkdir($dir.'/wp-content',0700); copy($root.'/cms_tools.php',$dir.'/cms_tools.php'); copy($root.'/.migrate_config',$dir.'/.migrate_config'); file_put_contents($dir.'/wp-config.php',$sourceConfig);
        if ($case==='hardlink') link($root.'/wp-content/uploads/test.png',$dir.'/wp-content/shared.png');
        if ($case==='symlink') symlink($root.'/wp-content/uploads/test.png',$dir.'/wp-content/shared.png');
        if ($case==='entry-limit') for ($i=0;$i<10001;$i++) file_put_contents($dir.'/wp-content/f'.$i,'');
        if ($case==='size-limit') { $f=fopen($dir.'/wp-content/large.bin','w'); ftruncate($f,268435457); fclose($f); }
        if ($case==='php-override') file_put_contents($dir.'/.user.ini','auto_prepend_file=/production/secret.php');
        $before=target()['writes']; [$code,,$data]=request('upgrade_prepare',$fields,'/'.$case.'/cms_tools.php');
        $errors=['hardlink'=>'Shared source files cannot be used for upgrade preparation.','symlink'=>'Shared source files cannot be used for upgrade preparation.','entry-limit'=>'Source inventory limit reached; no complete-copy claim can be made.','size-limit'=>'Source size limit reached; use an offline isolated clone.','php-override'=>'Local PHP override files need host-scoped manual isolation; no configuration was copied.'];
        check($code===409 && $data['error']===$errors[$case] && target()['writes']===$before,'source rejection has no DB effects: '.$case);
    }
    echo "All WordPress upgrade workflow HTTP tests passed.\n";
} catch (Throwable $e) { fwrite(STDERR,"FAIL: {$e->getMessage()}\nFixture: $root\n"); $exit=1;
} finally { proc_terminate($process); proc_close($process); }
exit($exit);
