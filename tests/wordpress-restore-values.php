<?php
// Default command owns its complete matrix; all disposable state stays excluded.
$proof=dirname(__DIR__).'/.atl/wordpress-restore-values-proof';
if(!is_dir($proof.'/tmp'))mkdir($proof.'/tmp',0700,true);
if(($argv[1]??'')!=='--case'){
    $batch='values-'.bin2hex(random_bytes(6));$receipt=['owner'=>$batch,'baseline'=>'5bfc6aee40c19e05c395ff9cf2739c93180d84ed','cases'=>[]];
    $output='';
    $finish=static function(int $exit,string $stderr='')use($proof,$batch,&$receipt,&$output){
        file_put_contents($proof.'/'.$batch.'.json',json_encode($receipt,JSON_PRETTY_PRINT));
        file_put_contents($proof.'/'.$batch.'.stdout',$output);
        file_put_contents($proof.'/'.$batch.'.stderr',$stderr);
        file_put_contents($proof.'/'.$batch.'.exit',(string)$exit."\n");
        echo $output;if($stderr!=='')fwrite(STDERR,$stderr);exit($exit);
    };
    $cases=['valid','opaque','depth-ok','nodes-ok','missing-column','core-type','missing-plugins','duplicate-plugins','key-collision','casefold-collision','nonascii-key','meta-collision','array-collision','malformed','unknown-token','dangling-ref','wrong-object-ref','custom','depth','nodes','growth','aggregate-growth','column-size','enum-collision','source-tamper','output-tamper'];
    foreach($cases as$case){
        $scratch=$proof.'/tmp/'.$batch.'-'.$case;mkdir($scratch,0700);file_put_contents($scratch.'/test-owner.json',json_encode(['owner'=>$batch,'case'=>$case]));
        $prefix=$proof.'/'.$batch.'-'.$case;$process=proc_open([PHP_BINARY,__FILE__,'--case',$case,$scratch],[0=>['pipe','r'],1=>['file',$prefix.'.stdout','w'],2=>['file',$prefix.'.stderr','w']],$pipes);
        fclose($pipes[0]);$exit=proc_close($process);file_put_contents($prefix.'.exit',(string)$exit."\n");$stderr=file_get_contents($prefix.'.stderr');
        $publicRun=is_file($scratch.'/public-run.json')?json_decode(file_get_contents($scratch.'/public-run.json'),true):[];
        $receipt['cases'][$case]=['exit'=>$exit,'stderr_bytes'=>strlen($stderr),'scratch'=>$scratch,'receipts'=>$prefix,'run_id'=>$publicRun['run_id']??null];
        $output.="$case: exit=$exit stderr=".strlen($stderr)." receipts=$prefix\n";
        if($exit!==0||$stderr!=='')$finish(1,file_get_contents($prefix.'.stdout').$stderr);
    }
    $output.="All private values cases passed; owner=$batch. Parent owns cleanup.\n";$finish(0);
}
$case=$argv[2];$scratch=$argv[3];
if(!str_starts_with($scratch,$proof.'/tmp/values-')||!is_file($scratch.'/test-owner.json'))throw new RuntimeException('Unowned scratch');
putenv('TMPDIR='.$scratch);
require __DIR__.'/fixtures/wordpress-restore/bootstrap.php';
require __DIR__.'/fixtures/wordpress-restore/values.php';
// The exact FQN matches the source O token. Any unsafe unserialize would fire it.
file_put_contents($root.'/prepend.php',"\nclass WakeTrap { public function __wakeup(){file_put_contents(__DIR__.'/WAKEUP','bad');} }\n",FILE_APPEND);
$target='http://127.0.0.1:'.$port.'/confirmed-destination';
if(in_array($case,['growth','aggregate-growth'],true))$target.='/'.str_repeat('long',400);
[$sql,$expected,$schemas]=valuesFixture($case,$target,$root.'/EXECUTED');
file_put_contents($site.'/db_fixture.sql.gz',gzencode($sql));$originals=[];foreach(['wp-config.php','cms_tools.php','hosting_fixture.zip','db_fixture.sql.gz']as$n)$originals[$n]=hash_file('sha256',$site.'/'.$n);
$exit=0;
function valueStep($run,$csrf,$operation='values') {return jsonRequest('wp_restore_step',['csrf'=>$csrf,'run_id'=>$run['run_id'],'stage'=>'preflight','operation'=>$operation,'revision'=>$run['revision']]);}
function ownValueRun($scratch,$run):string {
    $dirs=glob($scratch.'/cms-wp-restore-*',GLOB_ONLYDIR);check(count($dirs)===1,'one private run in exclusive test TMPDIR');$owner=json_decode(file_get_contents($dirs[0].'/owner.json'),true);check($owner['run_id']===$run['run_id'],'tamper locator bound to exact owner.run_id');return $dirs[0];
}
try{
    for($i=0;$i<100;$i++){if($s=@fsockopen('127.0.0.1',$port)){fclose($s);break;}usleep(20000);}
    request('',['password'=>'FixturePassword']);$connections=@file_get_contents($root.'/connections')?:'';
    [$code,$j]=jsonRequest('wp_restore_discover');check($code===200&&$j['eligible'],'eligible isolated fixture');$csrf=$j['csrf'];
    [$code,$j]=jsonRequest('wp_restore_prepare',['csrf'=>$csrf,'archive'=>'hosting_fixture.zip','database'=>'db_fixture.sql.gz','target_url'=>$target]);check($code===201,'prepare accepted');$run=$j['run'];
    file_put_contents($scratch.'/public-run.json',json_encode(['run_id'=>$run['run_id'],'site'=>$site,'fixture_root'=>$root]));
    [$code,$j]=valueStep($run,$csrf);check($code===400&&$j===['error'=>'Backup preflight failed. No database operation was performed.','code'=>'backup_preflight_failed'],'values requires ready accepted SQL plan');
    [$code,$j]=jsonRequest('wp_restore_state');check($j['run']===$run,'premature values preserves exact public state');
    for($i=0;$i<128&&!$run['preflight']['sql']['ready'];$i++){[$code,$j]=valueStep($run,$csrf,'sql');check($code===200,'source SQL ready progression');$run=$j['run'];}
    check($run['preflight']['sql']['ready'],'accepted SQL source ready');[$code,$j]=jsonRequest('wp_restore_state&manifest=sql');$sourceManifest=$j;
    // Authority and revision failures must not add values fields or advance counters.
    [$code,$j]=jsonRequest('wp_restore_step',['csrf'=>'wrong','run_id'=>$run['run_id'],'stage'=>'preflight','operation'=>'values','revision'=>$run['revision']]);check($code===403&&$j===['error'=>'Invalid restore session.'],'independent CSRF guard reused');
    $stale=$run;$stale['revision']--;[$code,$j]=valueStep($stale,$csrf);check($code===409&&$j===['error'=>'Stale restore revision.'],'revision guard reused');
    [$code,$j]=jsonRequest('wp_restore_state');check($j['run']===$run,'authority rejects leave public state identical');
    if($case==='source-tamper'){$dir=ownValueRun($scratch,$run);$path=$dir.'/sql-plan.ndjson';$bytes=file_get_contents($path);$bytes=str_replace(base64_encode('password-secret'),base64_encode('password-attack'),$bytes,$n);check($n===1,'one source frame tampered');file_put_contents($path,$bytes);}
    $failed=false;$tampered=false;$steps=0;$advanced=false;
    while($steps++<128){
        $before=$run;[$code,$j]=valueStep($run,$csrf);
        if($code===400){check($j===['error'=>'Backup preflight failed. No database operation was performed.','code'=>'backup_preflight_failed'],'exact fail-closed error JSON');[$code,$j]=jsonRequest('wp_restore_state');check($code===200&&$j['run']===$before,'rejected values preserves exact checkpoint/counters');$failed=true;break;}
        check($code===200,'values preflight accepted');$run=$j['run'];
        foreach(['password-secret',base64_encode('password-secret'),'DEFAULT_VALUE_SECRET',base64_encode('DEFAULT_VALUE_SECRET'),$root]as$secret)check(!str_contains(json_encode($j),$secret),'step response contains no cells/defaults/private paths');
        if(!$run['preflight']['values']['ready']&&$run['preflight']['values']['processed']>0)$advanced=true;
        check(!$run['backup_verified']&&!$run['runtime_verified']&&!$run['replacement_enabled'],'all runtime/replacement/backup flags remain false');
        if($case==='output-tamper'&&!$tampered&&!$run['preflight']['values']['ready']){$dir=ownValueRun($scratch,$run);$path=$dir.'/values-plan.ndjson';if(is_file($path)&&filesize($path)>0){$bytes=file_get_contents($path);$bytes=str_replace('wp_comments','wp_commentx',$bytes,$n);if($n){file_put_contents($path,$bytes);$tampered=true;}}}
        if($run['preflight']['values']['ready'])break;
    }
    if(in_array($case,['valid','opaque','depth-ok','nodes-ok'],true)){
        check(!$failed&&$run['preflight']['values']['ready'],'new private prepared values plan ready');
        check($advanced&&$steps>1,'private values output resumes over authenticated revision-bound steps');
        check($run['preflight']['values']['processed']===$run['preflight']['values']['total'],'complete source byte counters');
        [$code,$j]=jsonRequest('wp_restore_state&manifest=values');check($code===200,'metadata-only values manifest');$m=$j['run']['values_manifest'];
        $hash=hash_init('sha256');foreach($expected as$r)hash_update($hash,json_encode($r,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
        check($m['data_sha256']===hash_final($hash),'exact prepared rows: URLs/nested serialization/objects/UTF8/NUL/quotes/reference/number/NULL/binary/hex/GUID identity, roles, plugins and cache omission');
        check($m['row_count']===count($expected)&&$m['omitted_rows']===2&&$m['plugins_disabled']===true,'truthful prepared row/plugin/cache counts');
        foreach($m['tables']as$t){check(isset($schemas[$t['name']]),'destination prefix mapped structurally');check($t['schema_sha256']===hash('sha256',json_encode($schemas[$t['name']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"),'exact migrated schema/default/ENUM fingerprint '.$t['name']);}
        foreach(['password-secret',base64_encode('password-secret'),'DESTINATION_SECRET','DEFAULT_VALUE_SECRET',base64_encode('DEFAULT_VALUE_SECRET'),$root,'opaque payload',base64_encode('opaque payload')]as$secret)check(!str_contains(json_encode($j),$secret),'metadata excludes cells/credentials/private paths');
        check(strlen($m['plan_sha256'])===64&&$m['source_plan_sha256']===$sourceManifest['run']['sql_manifest']['plan_sha256'],'distinct authenticated output/source fingerprints');
    }else check($failed&&($case!=='output-tamper'||$tampered),'unsupported, oversized or tampered structure rejected: '.$case);
    [$code,$j]=jsonRequest('wp_restore_state&manifest=sql');unset($j['run']['preflight']['values']);$old=$sourceManifest;$old['run']['revision']=$j['run']['revision'];check($j===$old,'original public SQL catalog/plan fingerprints unchanged');
    foreach($originals as$n=>$sha)check(hash_file('sha256',$site.'/'.$n)===$sha,'original input SHA unchanged '.$n);
    check((@file_get_contents($root.'/connections')?:'')===$connections&&!file_exists($site.'/index.php')&&!file_exists($root.'/EXECUTED')&&!file_exists($root.'/WAKEUP'),'no DB access, public placement, PHP execution or namespaced object wakeup');
    echo "PASS $case values contracts; run_id=".$run['run_id']." scratch=$scratch\n";
}catch(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage()."\nArtifacts: $scratch\n");$exit=1;}
finally{proc_terminate($process);proc_close($process);}exit($exit);
