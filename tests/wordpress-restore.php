<?php
// HTTP admission contracts against a four-file synthetic hosting, never a real DB.
$root=sys_get_temp_dir().'/wordpress-restore-'.bin2hex(random_bytes(6));mkdir($root,0700);$site=$root.'/site';mkdir($site,0700);
$source=file_get_contents(getenv('WORDPRESS_RESTORE_SOURCE') ?: __DIR__.'/../cms_tools.php');
$source=preg_replace('/^<\?php/','<?php namespace WordPressRestoreFixture; use \\Exception; use \\Throwable; use \\DirectoryIterator; use \\RecursiveIteratorIterator; use \\RecursiveDirectoryIterator; use \\FilesystemIterator; use \\ZipArchive;', $source,1);
file_put_contents($site.'/cms_tools.php',$source);
file_put_contents($root.'/prepend.php',"<?php namespace WordPressRestoreFixture; class PDO extends \\PDO { public function __construct(...\$args){ file_put_contents(__DIR__.'/connections','attempt\\n',FILE_APPEND); throw new \\RuntimeException('Synthetic DB disabled'); } }");
file_put_contents($site.'/.migrate_config',json_encode(['password_hash'=>hash('sha256','odc_migrate_2026_x9k'.'FixturePassword')]));
$config="<?php\ndefine('DB_HOST','localhost');\ndefine('DB_NAME','fixture');\ndefine('DB_USER','fixture');\ndefine('DB_PASSWORD','DESTINATION_SECRET');\n\$table_prefix='wp_';\n";
file_put_contents($site.'/wp-config.php',$config);
file_put_contents($site.'/hosting_fixture.zip','Owned archive placeholder');file_put_contents($site.'/db_fixture.sql.gz',gzencode('-- Owned dump placeholder'));
$socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
$process=proc_open([PHP_BINARY,'-d','auto_prepend_file='.$root.'/prepend.php','-d','session.save_path='.$root,'-S','127.0.0.1:'.$port,'-t',$site],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);$cookie='';
function request($action='',$post=null){global$port,$cookie;$ctx=stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>"Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'timeout'=>10]]);$f=fopen('http://127.0.0.1:'.$port.'/cms_tools.php'.($action===''?'':'?action='.$action), 'rb',false,$ctx);$body=stream_get_contents($f);$headers=stream_get_meta_data($f)['wrapper_data'];fclose($f);foreach($headers as$h)if(preg_match('/^Set-Cookie: ([^;]+)/i',$h,$m))$cookie=$m[1];preg_match('#HTTP/\S+ (\d+)#',$headers[0],$m);return[(int)$m[1],$body];}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo"PASS $message\n";}
function jsonRequest($action,$post=null){[$status,$body]=request($action,$post);$json=json_decode($body,true);check(is_array($json),'JSON response: '.$action);check(!str_contains($body,'DESTINATION_SECRET') && !str_contains($body,'Synthetic DB disabled'),'no credentials/driver errors in response');return[$status,$json];}
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
