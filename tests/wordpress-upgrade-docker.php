<?php
// Synthetic provider tests: no application bootstrap, site, database or network.
$source=file_get_contents(__DIR__.'/../cms_tools.php');
$start=strpos($source,'function upgrade_offline_cache(');
$end=strpos($source,'// Only literal, unconditional source identities', $start);
$functions=substr($source,$start,$end-$start);
$root=realpath(sys_get_temp_dir()).'/cms-docker-provider-'.bin2hex(random_bytes(6)); mkdir($root,0700);
// Model root-owned immutable cache under the unprivileged test account.
eval('namespace DockerFixture; function fopen($p, ...$args) { throw new \\RuntimeException("Network forbidden by synthetic fixture"); } function is_writable($p) { return $GLOBALS["cache_writable"] ?? false; } function lstat($p) { $s=\\lstat($p); if ($s) { $s["uid"]=($GLOBALS["cache_untrusted"] ?? false) ? 999 : 0; $s["mode"] &= ~0022; } return $s; } '.$functions);
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function refuses($fn,$message) { try { $fn(); } catch (Throwable $e) { check(true,$message); return; } throw new RuntimeException($message); }
putenv('CMS_UPGRADE_OFFLINE_DIR='.$root);
try {
    refuses(fn()=>DockerFixture\upgrade_stable_release(),'missing cache fails closed');
    $version='6.8.1'; $url='https://downloads.wordpress.org/release/wordpress-'.$version.'.zip';
    $release=['offers'=>[['response'=>'upgrade','locale'=>'en_US','version'=>$version,'packages'=>['full'=>$url],'php_version'=>'7.4','mysql_version'=>'5.7']]];
    $checks=['checksums'=>array_fill_keys(['wp-includes/version.php','wp-settings.php','wp-admin/includes/upgrade.php'],str_repeat('a',32))];
    file_put_contents($root.'/release.json',json_encode($release)); file_put_contents($root.'/checksums.json',json_encode($checks));
    file_put_contents($root.'/package.zip','synthetic archive; extraction covered by existing ZIP contract');
    $provenance=['schema'=>1,'version'=>$version,'captured_at'=>time(),'package_url'=>$url,'sha256'=>hash_file('sha256',$root.'/package.zip')];
    file_put_contents($root.'/provenance.json',json_encode($provenance));
    $good=DockerFixture\upgrade_stable_release(); check($good['version']===$version,'official metadata parsed from immutable offline cache');
    foreach (['stale','future','bad-hash','wrong-url','wrong-version','malformed','symlink','hardlink','oversize'] as $case) {
        $p=$provenance;
        if ($case==='stale') $p['captured_at']=time()-3601;
        if ($case==='future') $p['captured_at']=time()+100;
        if ($case==='bad-hash') $p['sha256']=str_repeat('0',64);
        if ($case==='wrong-url') $p['package_url']='https://evil.invalid/core.zip';
        if ($case==='wrong-version') $p['version']='1.0';
        file_put_contents($root.'/provenance.json',$case==='malformed'?'not json':json_encode($p));
        if ($case==='symlink') { rename($root.'/checksums.json',$root.'/checksums.saved'); symlink($root.'/checksums.saved',$root.'/checksums.json'); }
        if ($case==='hardlink') link($root.'/checksums.json',$root.'/second-link');
        if ($case==='oversize') file_put_contents($root.'/release.json',str_repeat(' ',2097153));
        refuses(fn()=>DockerFixture\upgrade_stable_release(),'offline rejection: '.$case);
        if ($case==='symlink') { unlink($root.'/checksums.json'); rename($root.'/checksums.saved',$root.'/checksums.json'); }
        if ($case==='hardlink') unlink($root.'/second-link');
        if ($case==='oversize') file_put_contents($root.'/release.json',json_encode($release));
    }
    file_put_contents($root.'/provenance.json',json_encode($provenance));
    check(DockerFixture\upgrade_stable_release()===$good,'rejections do not mutate cache');
    // Transport must remain the original verified HTTPS implementation outside Docker.
    check(str_contains($functions,"'verify_peer'=>true") && str_contains($functions,"'follow_location'=>0"),'separate-host transport retains verified TLS and no redirects');
    $GLOBALS['cache_writable']=true;
    refuses(fn()=>DockerFixture\upgrade_stable_release(),'web-writable cache is refused');
    $GLOBALS['cache_writable']=false; $GLOBALS['cache_untrusted']=true;
    refuses(fn()=>DockerFixture\upgrade_stable_release(),'non-root-owned cache is refused');
    $GLOBALS['cache_untrusted']=false;
    $contextStart=strpos($source,'function upgrade_staging_context(');
    $contextEnd=strpos($source,'function upgrade_begin(', $contextStart);
    eval('namespace DockerFixture; '.substr($source,$contextStart,$contextEnd-$contextStart));
    $contextConfig=$root.'/context.php';
    $declarations="<?php\ndefine('WP_ENVIRONMENT_TYPE', 'staging');\ndefine('CMS_TOOLS_PRODUCTION_URL', 'https://production.invalid');\ndefine('CMS_TOOLS_STAGING_URL', 'https://staging.invalid:8443');\ndefine('CMS_TOOLS_ISOLATION_VERIFIED', 'docker-network-none');\n";
    file_put_contents($contextConfig,$declarations);
    $_SERVER=['SCRIPT_NAME'=>'/cms_tools.php','HTTP_HOST'=>'staging.invalid:8443','HTTPS'=>'on'];
    check(DockerFixture\upgrade_staging_context($contextConfig)['proof']==='runner-attested-docker-network-none','Docker marker admitted only with immutable cache and fixed loopback HTTPS origin');
    putenv('CMS_UPGRADE_OFFLINE_DIR');
    refuses(fn()=>DockerFixture\upgrade_staging_context($contextConfig),'Docker marker never permits online fallback');
    file_put_contents($contextConfig,str_replace(['docker-network-none','staging.invalid:8443'],['separate-host','staging.example'],$declarations));
    $_SERVER['HTTP_HOST']='staging.example';
    check(DockerFixture\upgrade_staging_context($contextConfig)['proof']==='operator-verified-separate-host','separate-host mode still works without Docker/cache');
    $_SERVER['HTTP_HOST']='production.invalid';
    refuses(fn()=>DockerFixture\upgrade_staging_context($contextConfig),'separate-host request origin still enforced');
    require __DIR__.'/../docker/wordpress-upgrade/import.php';
    $plain="CREATE TABLE `wp_options` (`option_name` varchar(191), `option_value` text) ENGINE=InnoDB; INSERT INTO `wp_options` VALUES ('siteurl','https://production.invalid; safe quoted \\\\ path');";
    check(count(upgrade_import_statements($plain,'wp_'))===2,'restricted importer parses plain statements without interpreting quoted data');
    foreach (["\\\\! system id;", "SOURCE /secret;", "DELIMITER $$", "\\\\C utf8;", "CREATE DATABASE live;", "USE live;", "INSERT INTO `live`.`wp_options` VALUES ('x');", "INSERT INTO `wp_options` SELECT * FROM mysql.user;", "/*!40101 SET @x=1 */;", "CREATE TABLE `wp_bad` (id int) ENGINE=FEDERATED;", "CREATE TABLE `wp_bad` (id int REFERENCES live.x) ENGINE=InnoDB;", "INSERT INTO `wp_options` VALUES ('x'); GRANT ALL ON *.* TO 'bad';"] as $sql) {
        refuses(fn()=>upgrade_import_statements($sql,'wp_'),'SQL/client escape refused without execution');
    }
    // Exercise the real staged download entry point; transport and archive consumer are doubles.
    putenv('CMS_UPGRADE_OFFLINE_DIR='.$root);
    $stepStart=strpos($source,'function upgrade_step('); $stepEnd=strpos($source,'function upgrade_ini_bytes(', $stepStart);
    eval('namespace DockerStepFixture; class ZipArchive {} function upgrade_files($p) { return []; } function upgrade_owned($r) {} function upgrade_offline_cache() { return \\DockerFixture\\upgrade_offline_cache(); } function upgrade_archive(&$r) { $GLOBALS["archive_called"]=true; } function upgrade_http(...$args) { throw new \\RuntimeException("Offline download attempted network"); } '.substr($source,$stepStart,$stepEnd-$stepStart));
    mkdir($root.'/work',0700);
    $staged=['release'=>['offline_fingerprint'=>'changed-cache'],'work'=>$root.'/work','dir'=>$root.'/work','stage'=>'download'];
    refuses(function () use (&$staged) { DockerStepFixture\upgrade_step($staged); },'real step rejects changed cache before any download/copy');
    check(!file_exists($root.'/work/package.zip') && !isset($GLOBALS['archive_called']),'changed-cache step has no archive/resource side effects');
    $staged['release']['offline_fingerprint']=DockerFixture\upgrade_offline_cache()['fingerprint'];
    DockerStepFixture\upgrade_step($staged);
    check($staged['stage']==='extract' && $GLOBALS['archive_called']===true && hash_file('sha256',$root.'/work/package.zip')===$provenance['sha256'],'real download stage copies verified cache then invokes existing archive validation without HTTPS');
    unlink($root.'/work/package.zip'); rmdir($root.'/work'); putenv('CMS_UPGRADE_OFFLINE_DIR');
    // Public HTTP dispatch rejects Docker before source admission or execution.
    file_put_contents($root.'/cms_tools.php',$source);
    file_put_contents($root.'/.migrate_config',json_encode(['password_hash'=>hash('sha256','odc_migrate_2026_x9kSyntheticDockerPassword')]));
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket);
    $server=proc_open([PHP_BINARY,'-n','-d','session.save_path='.$root,'-S','127.0.0.1:'.$port,'-t',$root],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);
    for ($i=0;$i<100;$i++) { $ready=@fsockopen('127.0.0.1',$port); if ($ready) { fclose($ready); break; } usleep(10000); }
    $cookie='';
    $http=function ($fields, $action='') use ($port,&$cookie) {
        $ctx=stream_context_create(['http'=>['method'=>'POST','timeout'=>5,'ignore_errors'=>true,'follow_location'=>0,'header'=>"Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",'content'=>http_build_query($fields)]]);
        $stream=fopen('http://127.0.0.1:'.$port.'/cms_tools.php'.($action?'?action='.$action:''),'rb',false,$ctx);
        $body=stream_get_contents($stream); $headers=stream_get_meta_data($stream)['wrapper_data']; fclose($stream);
        foreach ($headers as $header) if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i',$header,$m)) $cookie=$m[1];
        preg_match('/HTTP\/\S+ ([0-9]{3})/',$headers[0],$m);
        return [(int)$m[1],$body];
    };
    [, $page]=$http(['password'=>'SyntheticDockerPassword']);
    check(str_contains($page,'name="platform"') && str_contains($page,'Docker: show local CLI instructions only'),'authenticated page exposes optional Docker without requiring a site');
    preg_match('/name="upgrade_csrf" value="([a-f0-9]{64})"/',$page,$m);
    check(isset($m[1]),'authenticated page supplies CSRF');
    $before=scandir($root);
    [$status,$body]=$http(['csrf'=>$m[1],'platform'=>'docker'],'upgrade_prepare');
    check($status===400 && str_contains($body,'Docker runs only from the local CLI') && !str_contains($body,'SyntheticDockerPassword'),'public Docker POST cannot launch Docker or execute a site');
    check(scandir($root)===$before && !glob($root.'/cms-upgrade-*'),'Docker rejection creates no run or settings resources');
    proc_terminate($server); proc_close($server); $server=null;
    echo "Docker provider/importer/public HTTP synthetic tests passed; no real runtime proof.\n";
} finally {
    if (isset($server) && is_resource($server)) { proc_terminate($server); proc_close($server); }
    if (is_dir($root.'/work')) { foreach (glob($root.'/work/*') as $file) unlink($file); rmdir($root.'/work'); }
    foreach (array_merge(glob($root.'/*'),glob($root.'/.*')) as $file) if (is_file($file) || is_link($file)) unlink($file);
    rmdir($root);
}
