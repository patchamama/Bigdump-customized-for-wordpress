<?php
require __DIR__.'/fixtures/wordpress-restore/bootstrap.php';
$exit=0;
try{
 for($i=0;$i<100;$i++){if($s=@fsockopen('127.0.0.1',$port)){fclose($s);break;}usleep(20000);}
 [$code,$j]=jsonRequest('wp_restore_discover');check($code===401,'discovery requires authentication');
 request('',['password'=>'FixturePassword']);
 [$pageCode,$page]=request();check($pageCode===200 && str_contains($page,'id="wp-restore-offer"'),'authenticated page renders automatic-offer controller');
 if(getenv('WORDPRESS_RESTORE_HTML_OUT'))file_put_contents(getenv('WORDPRESS_RESTORE_HTML_OUT'),$page);
 $connections=@file_get_contents($root.'/connections')?:'';
 [$code,$j]=jsonRequest('wp_restore_discover');check($code===200 && $j['eligible']===true,'config-only hosting offers restoration without installed core');$csrf=$j['csrf'];$adapters=$j['adapters'];
 check($j['archives']===['hosting_fixture.zip'] && $j['databases']===['db_fixture.sql.gz'],'only local named backup candidates');
 [$code]=jsonRequest('wp_restore_discover',[]);check($code===405,'discovery is GET only');
 [$code]=jsonRequest('wp_restore_prepare',[]);check($code===403,'prepare requires independent CSRF');
 $input=['csrf'=>$csrf,'archive'=>'hosting_fixture.zip','database'=>'db_fixture.sql.gz','target_url'=>'http://127.0.0.1:'.$port];
 [$code]=jsonRequest('wp_restore_prepare',array_merge($input,['archive'=>'../hosting_fixture.zip']));check($code===400,'reject foreign/path candidate before run creation');
 [$code]=jsonRequest('wp_restore_prepare',array_merge($input,['target_url'=>'https://name:secret@example.test/']));check($code===400,'target URL rejects embedded credentials');
 [$code,$j]=jsonRequest('wp_restore_state');check($code===200 && $j['run']===null,'rejected input leaves no run');
 [$code,$j]=jsonRequest('wp_restore_prepare',$input);check($code===201 && $j['run']['stage']==='preflight' && !$j['run']['backup_verified'] && !$j['run']['runtime_verified'],'prepare creates only an unverified preflight run');$run=$j['run'];
 [$code,$j]=jsonRequest('wp_restore_prepare',$input);check($code===409,'duplicate preparation cannot overwrite run');
 [$code,$j]=jsonRequest('wp_restore_state');check($code===200 && $j['run']===$run,'duplicate leaves exact public state unchanged');
 [$code]=jsonRequest('wp_restore_step',['csrf'=>$csrf,'run_id'=>str_repeat('0',64),'stage'=>'preflight']);check($code===404,'foreign run rejected');
 [$code,$j]=jsonRequest('wp_restore_step',['csrf'=>$csrf,'run_id'=>$run['run_id'],'stage'=>'preflight']);check($code===400,'step requires a named operation and server revision');
 if(!$adapters['files']){[$code,$j]=jsonRequest('wp_restore_step',['csrf'=>$csrf,'run_id'=>$run['run_id'],'stage'=>'preflight','operation'=>'files','revision'=>$run['revision']]);check($code===503 && $j['code']==='restore_adapter_unavailable','unfinished adapters cannot advance or mutate DB');}
 [$code,$j]=jsonRequest('wp_restore_step',['csrf'=>$csrf,'run_id'=>$run['run_id'],'stage'=>'preflight','operation'=>'unknown','revision'=>$run['revision']]);check($code===400,'unknown operation cannot select an arbitrary handler');
 [$code,$j]=jsonRequest('wp_restore_state');check($j['run']===$run,'rejected/unavailable step leaves exact state unchanged');
 check((@file_get_contents($root.'/connections')?:'')===$connections,'restore routes do not connect to a database');
 check(file_get_contents($site.'/wp-config.php')===$config && file_get_contents($site.'/hosting_fixture.zip')==='Owned archive placeholder','destination config and backup originals unchanged');
 $gate=file_get_contents($site.'/.migrate_config');file_put_contents($site.'/.migrate_config','{}');
 [$code]=jsonRequest('wp_restore_state');check($code===409,'removed custom authority cannot reuse an existing session run');
 file_put_contents($site.'/.migrate_config',$gate);
 [$code,$j]=jsonRequest('wp_restore_state');check($code===200 && $j['run']===$run,'authority rejection preserves exact checkpoint');
 file_put_contents($site.'/wp-config.php',$config."file_put_contents(__DIR__.'/EXECUTED','bad');\n");
 [$code]=jsonRequest('wp_restore_state');check($code===409 && !file_exists($site.'/EXECUTED'),'changed executable configuration is never executed or accepted');
 echo"All WordPress restoration admission checks passed.\n";
}catch(Throwable$e){fwrite(STDERR,'FAIL '.$e->getMessage()."\nArtifacts: $root\n");$exit=1;}finally{proc_terminate($process);proc_close($process);}exit($exit);
