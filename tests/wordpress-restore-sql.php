<?php
// Public SQL admission only: no SQL connection or execution is permitted here.
$case=$argv[1]??'valid';
// PHP's cached parent tempdir is not the child's private tempdir. Give this server
// a new inherited TMPDIR, never search/tamper with any other fixture's runs.
$sqlTmp=sys_get_temp_dir().'/sql-admission-'.bin2hex(random_bytes(6));mkdir($sqlTmp,0700);putenv('TMPDIR='.$sqlTmp);
require __DIR__.'/fixtures/wordpress-restore/bootstrap.php';
function sqlQuote(string $value):string {
    return "'".strtr($value,["\\"=>"\\\\","'"=>"\\'","\0"=>"\\0","\n"=>"\\n","\r"=>"\\r","\t"=>"\\t","\x1a"=>"\\Z"])."'";
}
function rowValue($value):array {return $value===null?['type'=>'null']:(is_int($value)?['type'=>'number','value'=>(string)$value]:['type'=>'bytes','base64'=>base64_encode($value)]);}
$names=['commentmeta','comments','links','options','postmeta','posts','termmeta','terms','term_relationships','term_taxonomy','usermeta','users'];
$sql="-- Owned WordPress-shaped fixture, not an arbitrary SQL interpreter\n/*!40101 SET NAMES utf8mb4 */;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";$rows=[];
foreach($names as$name){
    $table='old_'.$name;
    $sql.="DROP TABLE IF EXISTS `$table`;\n";
    if($name==='options')$sql.="CREATE TABLE `$table` (`option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `option_name` varchar(191) NOT NULL DEFAULT '', `option_value` longtext NOT NULL, PRIMARY KEY (`option_id`), UNIQUE KEY `option_name` (`option_name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;\n";
    else $sql.="CREATE TABLE `$table` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `payload` longtext DEFAULT NULL, PRIMARY KEY (`id`), KEY `payload` (`payload`(191))) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ENGINE=InnoDB;\n";
}
$options=[[1,'siteurl','https://old.example/root'],[2,'home','https://old.example/root'],[3,'active_plugins',serialize(['example/plugin.php'])]];
foreach($options as$values){$sql.="INSERT INTO `old_options` VALUES (".implode(',',array_map(fn($v)=>is_int($v)?(string)$v:sqlQuote($v),$values)).");\n";$rows[]=['kind'=>'insert','table'=>'old_options','columns'=>['option_id','option_name','option_value'],'values'=>array_map('rowValue',$values)];}
$payload="SQL_ROW_SECRET; quo'te\\slash\n\0\xff a:1:{s:3:\"url\";s:24:\"https://old.example/root\";}";
$payload="<?php file_put_contents(".var_export($root.'/EXECUTED',true).",'not allowed'); ?> ".$payload;
foreach([[1,$payload],[2,null],[3,'double "quote" and -- # /* text */']]as$values){$sql.="INSERT INTO `old_posts` (`id`,`payload`) VALUES (".implode(',',array_map(fn($v)=>$v===null?'NULL':(is_int($v)?(string)$v:sqlQuote($v)),$values)).");\n";$rows[]=['kind'=>'insert','table'=>'old_posts','columns'=>['id','payload'],'values'=>array_map('rowValue',$values)];}
if($case==='extended'){
    $sql.="INSERT INTO `old_postmeta` VALUES (4,'first; item'),(5,'second \\' quote');\n";
    foreach([[4,'first; item'],[5,"second ' quote"]]as$v)$rows[]=['kind'=>'insert','table'=>'old_postmeta','columns'=>['id','payload'],'values'=>array_map('rowValue',$v)];
}
if($case==='large')for($i=4;$i<10004;$i++){
    $v=[$i,str_repeat('x',1000)];$sql.="INSERT INTO `old_posts` VALUES ($i,".sqlQuote($v[1]).");\n";
    $rows[]=['kind'=>'insert','table'=>'old_posts','columns'=>['id','payload'],'values'=>array_map('rowValue',$v)];
}
$bad=[
 'database'=>"DROP DATABASE `fixture`;",'qualified'=>"INSERT INTO `other`.`old_posts` VALUES (1,'bad');",
 'expression'=>"INSERT INTO `old_posts` VALUES (9,SLEEP(1));",'global'=>"SET GLOBAL local_infile=1;",
 'out-file'=>"SELECT 'SQL_ROW_SECRET' INTO OUTFILE '/tmp/not-owned';",'trigger'=>"CREATE TRIGGER t BEFORE INSERT ON old_posts FOR EACH ROW SET @x=1;",
 'ddl-expression'=>"CREATE TABLE `old_unsafe` (`id` int DEFAULT (SLEEP(1))) ENGINE=InnoDB;",
 'foreign'=>"CREATE TABLE `foreign_table` (`id` int) ENGINE=InnoDB;",'reserved'=>"CREATE TABLE `__cms_restore_journal` (`id` int) ENGINE=InnoDB;",
 'truncated'=>"INSERT INTO `old_posts` VALUES (99,'unterminated",'conditional'=>"/*!50000 DROP DATABASE fixture */;",'future-conditional'=>"/*!999999 SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;"
];
if(isset($bad[$case]))$sql.=$bad[$case]."\n";
if(in_array($case,['ddl-metadata','timestamp-ambiguous'],true)){
    if($case==='ddl-metadata')$sql.="SET TIME_ZONE='+00:00';\n";
    $sql.="CREATE TABLE `old_clock` (`label` varchar(64) DEFAULT 'CURRENT_TIMESTAMP', `stamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;\n";
}
if($case==='mode-zero')$sql.="SET SQL_MODE=''; INSERT INTO `old_posts` VALUES (0,'ambiguous generated id');\n";
if($case==='literals'){
    $sql.="CREATE TABLE `old_bytes` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `payload` longblob DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;\n";
    $sql.="INSERT INTO `old_bytes` VALUES (1,0x00ff27),(2,_binary".sqlQuote("b\0\xff")."),(3,-12.340e+2),(4,'don''t'),(5,\"double \"\"quote\"\"\");\n";
    $cells=[['type'=>'hex','value'=>'00FF27'],['type'=>'binary','base64'=>base64_encode("b\0\xff")],['type'=>'number','value'=>'-12.340e+2'],rowValue("don't"),rowValue('double "quote"')];
    foreach($cells as$i=>$cell)$rows[]=['kind'=>'insert','table'=>'old_bytes','columns'=>['id','payload'],'values'=>[rowValue($i+1),$cell]];
}
if($case==='plan-tamper')$sql.=str_repeat("SET SQL_NOTES=0;\n",128);
if($case==='zero'){$sql.="INSERT INTO `old_posts` VALUES (0,'captured zero id');\n";$rows[]=['kind'=>'insert','table'=>'old_posts','columns'=>['id','payload'],'values'=>[rowValue(0),rowValue('captured zero id')]];}
$gz=gzencode($sql);if($case==='gzip-crc')$gz[strlen($gz)-8]=chr(ord($gz[strlen($gz)-8])^1);
if($case==='gzip-trailing')$gz.=substr($gz,-8);
file_put_contents($site.'/db_fixture.sql.gz',$gz);$original=hash_file('sha256',$site.'/db_fixture.sql.gz');$originalFiles=[];foreach(['wp-config.php','cms_tools.php','hosting_fixture.zip','db_fixture.sql.gz']as$name)$originalFiles[$name]=hash_file('sha256',$site.'/'.$name);$exit=0;
try{
    for($i=0;$i<100;$i++){if($s=@fsockopen('127.0.0.1',$port)){fclose($s);break;}usleep(20000);}
    request('',['password'=>'FixturePassword']);$connections=@file_get_contents($root.'/connections')?:'';
    [$code,$j]=jsonRequest('wp_restore_discover');check($code===200&&$j['eligible'],'SQL-only private preflight fixture eligible');$csrf=$j['csrf'];
    [$code,$j]=jsonRequest('wp_restore_prepare',['csrf'=>$csrf,'archive'=>'hosting_fixture.zip','database'=>'db_fixture.sql.gz','target_url'=>'http://127.0.0.1:'.$port]);check($code===201,'unverified private run prepared');$run=$j['run'];$failed=false;$steps=0;$tampered=false;
    while($steps++<512){
        $before=$run;[$code,$j]=jsonRequest('wp_restore_step',['csrf'=>$csrf,'run_id'=>$run['run_id'],'stage'=>'preflight','operation'=>'sql','revision'=>$run['revision']]);
        check(!str_contains(json_encode($j),'SQL_ROW_SECRET')&&!str_contains(json_encode($j),base64_encode('SQL_ROW_SECRET')),'preflight response excludes row payloads');
        if($code===400){check($j===['error'=>'Backup preflight failed. No database operation was performed.','code'=>'backup_preflight_failed'],'exact bounded preflight error, without SQL contents or driver details');$failed=true;[$code,$state]=jsonRequest('wp_restore_state');check($code===200&&$state['run']===$before,'rejected SQL preserves exact checkpoint/counters');break;}
        check($code===200,'SQL preflight step accepted');$run=$j['run'];
        check(!$run['backup_verified']&&!$run['runtime_verified']&&!$run['replacement_enabled'],'SQL admission does not permit replacement or certify recovery');
        if($case==='plan-tamper'&&!$tampered&&$run['preflight']['sql']['processed']>0&&!$run['preflight']['sql']['ready']){
            $owned=glob($sqlTmp.'/cms-wp-restore-*',GLOB_ONLYDIR);check(count($owned)===1&&json_decode(file_get_contents($owned[0].'/owner.json'),true)['run_id']===$run['run_id'],'locate exactly this run in its exclusive inherited TMPDIR');$path=$owned[0].'/sql-plan.ndjson';
            $bytes=file_get_contents($path);$changed=str_replace(base64_encode($payload),base64_encode(str_replace('SQL_ROW_SECRET','SQL_ROW_ATTACK',$payload)),$bytes,$replacements);
            check($replacements===1&&strlen($changed)===strlen($bytes),'tamper exactly one already-staged row with equal-length valid typed JSON');file_put_contents($path,$changed);$tampered=true;
        }
        if($run['preflight']['sql']['ready'])break;
    }
    if(in_array($case,['valid','extended','large','ddl-metadata','zero','literals'],true)){
        check(!$failed&&$run['preflight']['sql']['ready'],'fully admitted dump becomes ready privately');
        [$code,$j]=jsonRequest('wp_restore_state&manifest=sql');$m=$j['run']['sql_manifest'];
        check($code===200&&count($m['tables'])===(in_array($case,['ddl-metadata','literals'],true)?13:12)&&$m['source_prefix']==='old_'&&$m['target_prefix']==='wp_','whole source table inventory and structural target-prefix mapping');
        check($m['row_count']===count($rows),'all complete and extended-insert rows counted');
        check($run['preflight']['sql']['processed']===$run['preflight']['sql']['total']&&$run['preflight']['sql']['total']===strlen($sql),'ready byte counters cover the exact complete decompressed source');
        $ctx=hash_init('sha256');foreach($rows as$r)hash_update($ctx,json_encode($r,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
        check($m['data_sha256']===hash_final($ctx),'all decoded typed values preserve exact bytes/NULL/numeric identity and statement boundaries');
        check($m['source_url']==='https://old.example/root'&&strlen($m['plan_sha256'])===64,'source identity and private plan fingerprint exposed without data');
        check(!str_contains(json_encode($j),'SQL_ROW_SECRET')&&!str_contains(json_encode($j),base64_encode($payload)),'manifest exposes metadata, never decoded/base64 row contents');
        if($case==='ddl-metadata'){
            $schema=['kind'=>'create','table'=>'old_clock','columns'=>[
                ['name'=>'label','type'=>'VARCHAR','args'=>[64],'attributes'=>['default'=>rowValue('CURRENT_TIMESTAMP')]],
                ['name'=>'stamp','type'=>'TIMESTAMP','args'=>[],'attributes'=>['not_null'=>true,'default'=>['type'=>'current_timestamp'],'on_update'=>['type'=>'current_timestamp']]]
            ],'keys'=>[],'options'=>['ENGINE'=>'INNODB','CHARSET'=>'utf8mb4','COLLATE'=>'utf8mb4_unicode_520_ci']];
            $tables=array_column($m['tables'],null,'name');check($tables['old_clock']['schema_sha256']===hash('sha256',json_encode($schema,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"),'quoted keyword defaults remain strings; empty current_timestamp() functions remain explicit metadata, never executed');
        }
        if($case==='large')check($steps>10,'large compressed dump advances through bounded successful checkpoints');
    }else check($failed,'unsupported/dangerous/corrupt SQL rejected before database access: '.$case);
    check(hash_file('sha256',$site.'/db_fixture.sql.gz')===$original&&file_get_contents($site.'/wp-config.php')===$config,'original dump and destination credentials remain unchanged');
    foreach($originalFiles as$name=>$sha)check(hash_file('sha256',$site.'/'.$name)===$sha,'original selected input remains byte-identical: '.$name);
    check((@file_get_contents($root.'/connections')?:'')===$connections&&!file_exists($site.'/index.php')&&!file_exists($root.'/EXECUTED'),'no database connection, SQL/PHP execution or public placement');
    echo "All $case private SQL admission checks passed.\n";
}catch(Throwable$e){fwrite(STDERR,'FAIL '.$e->getMessage()."\nArtifacts: $root\n");$exit=1;}
finally{proc_terminate($process);proc_close($process);}exit($exit);
