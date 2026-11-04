<?php
// Public preflight contracts. Backup PHP is never executed and PDO rejects all connections.
$backend=$argv[1]??'native';$case=$argv[2]??'valid';
if(!in_array($backend,['native','portable'],true))exit(2);
if($backend==='native'&&!class_exists('ZipArchive')){fwrite(STDERR,"Native ZipArchive unavailable\n");exit(2);}
putenv('WORDPRESS_RESTORE_ZIP_BACKEND='.$backend);
require __DIR__.'/fixtures/wordpress-restore/bootstrap.php';
function fixtureZip(array $entries):string {
    $data='';$central='';
    foreach($entries as $entry){
        [$name,$bytes]=$entry;$method=$entry[2]??8;$flags=$GLOBALS['case']==='encrypted'?2049:($GLOBALS['case']==='unicode_extra'?0:2048);$packed=$method===0?$bytes:gzdeflate($bytes);
        $crc=crc32($bytes);if($GLOBALS['case']==='crc'&&$name==='index.php')$crc^=1;
        $offset=strlen($data);$mode=$entry[3]??0100644;
        $extra=$GLOBALS['case']==='unicode_extra'&&preg_match('/[^\x00-\x7f]/',$name)?pack('vvCV',0x7075,5+strlen($name),1,crc32($name)).$name:'';
        $declared=$GLOBALS['case']==='oversize'&&$name==='index.php'?1073741825:strlen($bytes);
        $data.=pack('VvvvvvVVVvv',0x04034b50,20,$flags,$method,0,0,$crc,strlen($packed),$declared,strlen($name),strlen($extra)).$name.$extra.$packed;
        $central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,3*256+20,20,$flags,$method,0,0,$crc,strlen($packed),$declared,strlen($name),strlen($extra),0,0,0,$mode<<16,$offset).$name.$extra;
    }
    return $data.$central.pack('VvvvvVVv',0x06054b50,0,0,count($entries),count($entries),strlen($central),strlen($data),0);
}
$codePayload="<?php file_put_contents(".var_export($root.'/EXECUTED',true).",'not allowed');";
$entries=[['index.php',$codePayload],['wp-settings.php','core'],['wp-load.php','core'],['wp-includes/version.php',"<?php \$wp_version='6.8.3';"],
 ['wp-content/uploads/photo[1]á.txt',"\0binary\xff".str_repeat('payload',10000),0],
 ['.htaccess','old rules'],['wp-content/uploads/.htaccess','nested rules'],['wp-config.php','SOURCE_CONFIG_SECRET'],
 ['cms_tools.php','old tool'],['.migrate_config','old tool password'],['db_original.sql.gz','old database archive'],
 ['wp-content/cache/stale.php',$codePayload],['wp-content/mu-plugins/unsafe.php',$codePayload],['wp-content/object-cache.php',$codePayload],
 ['wp-content/plugins/example/example.php',$codePayload],['.env.production','PRIVATE_ENV_SECRET'],['.ssh/id_rsa','PRIVATE_KEY_SECRET']];
if(in_array($case,['large','large_deflate'],true)){$entries[4][1]=str_repeat('x',8388608)."tail\0\xff";if($case==='large_deflate')$entries[4][2]=8;}
if($case==='traversal')$entries[]=['../ESCAPED','bad'];
if($case==='backslash')$entries[]=['..\\ESCAPED','bad'];
if($case==='absolute')$entries[]=['/ESCAPED','bad'];
if($case==='unsupported')$entries[]=['new-compression','payload',93];
if($case==='symlink')$entries[]=['wp-content/link','/etc/passwd',0,0120777];
if($case==='duplicate')$entries[]=['INDEX.php','case collision'];
if($case==='parent')$entries[]=['wp-content','file conflicts with a directory'];
$zipBytes=fixtureZip($entries);if($case==='truncated')$zipBytes=substr($zipBytes,0,-7);
file_put_contents($site.'/hosting_fixture.zip',$zipBytes);
$originalZip=hash_file('sha256',$site.'/hosting_fixture.zip');$originalTool=hash_file('sha256',$site.'/cms_tools.php');$exit=0;
try {
    for($i=0;$i<100;$i++){if($s=@fsockopen('127.0.0.1',$port)){fclose($s);break;}usleep(20000);}
    request('',['password'=>'FixturePassword']);$connections=@file_get_contents($root.'/connections')?:'';
    [$code,$j]=jsonRequest('wp_restore_discover');check($code===200&&$j['eligible'],'four-file fixture eligible');$csrf=$j['csrf'];
    [$code,$j]=jsonRequest('wp_restore_prepare',['csrf'=>$csrf,'archive'=>'hosting_fixture.zip','database'=>'db_fixture.sql.gz','target_url'=>'http://127.0.0.1:'.$port]);check($code===201,'private run prepared');$run=$j['run'];
    $failed=false;$partialSteps=0;
    for($n=0;$n<100;$n++){
        $before=$run;
        [$code,$j]=jsonRequest('wp_restore_step',['csrf'=>$csrf,'run_id'=>$run['run_id'],'stage'=>'preflight','operation'=>'files','revision'=>$run['revision']]);
        if($code===400){$failed=true;[$s,$state]=jsonRequest('wp_restore_state');check($s===200&&$state['run']===$before,'rejected archive leaves exact checkpoint and counters unchanged');break;}
        check($code===200,'staging step accepted by '.$backend.' backend');$run=$j['run'];
        if($before['preflight']['files']['total']>0 && $run['preflight']['files']['processed']===$before['preflight']['files']['processed'])$partialSteps++;
        check(!$run['backup_verified']&&!$run['runtime_verified']&&!$run['replacement_enabled'],'staging never enables replacement/certification');
        if($run['preflight']['files']['ready'])break;
    }
    if(in_array($case,['valid','large','large_deflate','unicode_extra'],true)){
        check(!$failed&&$run['preflight']['files']['ready'],'private archive staging completes');
        if(in_array($case,['large','large_deflate'],true))check($partialSteps>0,'large member resumes over multiple bounded requests');
        [$code,$j]=jsonRequest('wp_restore_state&manifest=1');$manifest=$j['run']['manifest'];check(!str_contains(json_encode($j),'PRIVATE_ENV_SECRET')&&!str_contains(json_encode($j),'PRIVATE_KEY_SECRET')&&!str_contains(json_encode($j),'SOURCE_CONFIG_SECRET'),'inspection never returns private file contents');
        $byPath=array_column($manifest,null,'path');
        foreach($entries as[$name,$bytes])check(($byPath[$name]['sha256']??null)===hash('sha256',$bytes)&&$byPath[$name]['size']===strlen($bytes),'exact staged/source member bytes: '.$name);
        foreach(['.htaccess','wp-content/uploads/.htaccess','wp-content/cache/stale.php','wp-content/mu-plugins/unsafe.php','wp-content/object-cache.php','.env.production','.ssh/id_rsa']as$name)check($byPath[$name]['disposition']==='hold','unsafe hosting/cache/autoload control withheld: '.$name);
        foreach(['wp-config.php','cms_tools.php','.migrate_config','db_original.sql.gz']as$name)check($byPath[$name]['disposition']==='skip','destination tool/config/backups protected: '.$name);
        check($byPath['wp-content/uploads/photo[1]á.txt']['disposition']==='deploy','binary UTF-8/glob-literal member remains eligible');
        check($run['preflight']['files']['processed']===$run['preflight']['files']['total'],'file counters cover all validated members');
    }else check($failed,'invalid archive rejected before any deployment: '.$case);
    check(hash_file('sha256',$site.'/hosting_fixture.zip')===$originalZip&&hash_file('sha256',$site.'/cms_tools.php')===$originalTool&&file_get_contents($site.'/wp-config.php')===$config,'original config/tool/archive bytes unchanged');
    check(!file_exists($root.'/ESCAPED')&&!file_exists($root.'/EXECUTED')&&!file_exists($site.'/index.php'),'no path escape, backup PHP execution or public-root placement');
    check((@file_get_contents($root.'/connections')?:'')===$connections,'no restoration database connection');
    echo "All $backend/$case private ZIP staging checks passed.\n";
}catch(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage()."\nArtifacts: $root\n");$exit=1;}
finally{proc_terminate($process);proc_close($process);}exit($exit);
