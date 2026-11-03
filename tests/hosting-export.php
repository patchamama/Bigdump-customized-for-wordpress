<?php
// Public export requests against owned synthetic sites; no real WordPress or DB runs.
$method = $argv[1] ?? 'cli';
if (!in_array($method, ['cli','php','tar'], true)) exit(2);
$root = sys_get_temp_dir().'/hosting-export-'.bin2hex(random_bytes(6));
mkdir($root,0700); $site=$root.'/site'; mkdir($site,0700);
$source=file_get_contents(getenv('HOSTING_EXPORT_SOURCE') ?: __DIR__.'/../cms_tools.php');
$source=preg_replace('/^<\?php/', '<?php namespace HostingExportFixture; use \\Exception; use \\Throwable; use \\RuntimeException; use \\DirectoryIterator; use \\RecursiveIteratorIterator; use \\RecursiveDirectoryIterator; use \\FilesystemIterator; use \\ZipArchive;', $source,1);
file_put_contents($site.'/cms_tools.php',$source);
file_put_contents($site.'/.migrate_config',json_encode(['password_hash'=>hash('sha256','odc_migrate_2026_x9k'.'FixturePassword')]));
file_put_contents($site.'/wp-config.php',"<?php define('DB_PASSWORD','FixturePassword'); define('DB_NAME','fixture'); define('DB_USER','fixture'); \$table_prefix='wp_';");
$outside=['backup.zip','outside/deep/copy.ZIP','outside/a[1].zip','outside/a?.zip','outside/a*.zip','wp-content-other/backup.zip','other/wp-content/backup.zip'];
$outside=array_merge($outside,['backup.tar','outside/copy.TAR.GZ','outside/deep/copy.Gz']);
$inside=['wp-content/keep.zip','wp-content/uploads/deep/keep.ZiP','wp-content/keep.tar','wp-content/uploads/deep/keep.tar.gz','wp-content/uploads/keep.GZ'];
$ordinary=['index.php','outside/read me.txt','outside/a1.txt','archive.zip/notes.txt','outside/folder.zip/notes.txt','wp-content/folder.zip/notes.txt','wp-content/uploads/image.jpg'];
$original=[];
if(getenv('HOSTING_EXPORT_CONTROL')==='1')$outside=[];
foreach(array_merge($outside,$inside,$ordinary) as $name){@mkdir(dirname($site.'/'.$name),0700,true);$data=str_repeat(hash('sha256',$name,true),64);file_put_contents($site.'/'.$name,$data);$original[$name]=$data;}
if(getenv('HOSTING_EXPORT_CONTROL')!=='1')symlink($site.'/wp-content',$site.'/linked-content');
$outsideNames=getenv('HOSTING_EXPORT_CONTROL')==='1'?[]:array_merge($outside,['linked-content/keep.zip','linked-content/uploads/deep/keep.ZiP','linked-content/keep.tar','linked-content/uploads/deep/keep.tar.gz','linked-content/uploads/keep.GZ']);
$prepend= <<<'PHP'
<?php
namespace HostingExportFixture;
class PDO extends \PDO { public function __construct(...$args){throw new \RuntimeException('No database required');} }
function exec($command,&$output=null,&$code=null){
    $output=[];
    if(preg_match('/^(which|where) /',$command)){
        $method=__METHOD_CHOICE__;
        if(($method==='cli' && str_contains($command,"'zip'")) || ($method==='tar' && str_contains($command,"'tar'")))return \exec($command,$output,$code);
        $code=1;return '';
    }
    $result=\exec($command,$output,$code);
    file_put_contents(dirname(__DIR__).'/commands.jsonl',json_encode(['command'=>$command,'stdout'=>$output,'exit'=>$code])."\n",FILE_APPEND);
    return $result;
}
function class_exists($name,$autoload=true){if($name==='ZipArchive' && __METHOD_CHOICE__==='tar')return false;return \class_exists($name,$autoload);}
PHP;
file_put_contents($site.'/prepend.php',str_replace('__METHOD_CHOICE__',var_export($method,true),$prepend));
$socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
$process=proc_open([PHP_BINARY,'-d','auto_prepend_file='.$site.'/prepend.php','-d','session.save_path='.$root,'-S','127.0.0.1:'.$port,'-t',$site],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);
$cookie='';
function request($query,$post){global $port,$cookie;$ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",'content'=>http_build_query($post),'ignore_errors'=>true,'timeout'=>15]]);$f=fopen('http://127.0.0.1:'.$port.'/cms_tools.php'.$query,'rb',false,$ctx);$body=stream_get_contents($f);$headers=stream_get_meta_data($f)['wrapper_data'];fclose($f);foreach($headers as $h)if(preg_match('/^Set-Cookie: ([^;]+)/i',$h,$m))$cookie=$m[1];return [$body,$headers];}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$exit=0;
try{
 for($i=0;$i<100;$i++){if($s=@fsockopen('127.0.0.1',$port)){fclose($s);break;}usleep(20000);}
 request('',['password'=>'FixturePassword']);
 [$body,$headers]=request('?action=export_zip',['source_url'=>'https://source.invalid','target_url'=>'https://target.invalid']);
 file_put_contents($root.'/response.json',json_encode(['body'=>$body,'headers'=>$headers]));
 $result=json_decode($body,true);
 check(str_contains($headers[0],'200') && ($result['ok']??false)===true,'successful public export: '.$method);
 check(array_keys($result)===['ok','method','filename','size','url'],'existing export response fields retained');
 check(($result['method']??'')===['cli'=>'zip CLI','php'=>'ZipArchive PHP','tar'=>'tar.gz CLI'][$method],'requested native backend used');
 [$download,$downloadHeaders]=request(substr($result['url'],strpos($result['url'],'?')),[]);
 file_put_contents($root.'/download.json',json_encode(['headers'=>$downloadHeaders,'sha256'=>hash('sha256',$download)]));
 check(str_contains($downloadHeaders[0],'200'),'returned public download URL succeeds');
 $archives=glob($site.'/.migrate_exports/*.'.($method==='tar'?'tar.gz':'zip'));
 check(count($archives)===1,'one owned downloadable archive generated');
 check(basename($archives[0])===$result['filename'] && $download===file_get_contents($archives[0]),'returned filename and downloaded archive bytes match');
 if($method==='tar'){$lines=[];exec('tar -tzf '.escapeshellarg($archives[0]),$lines,$ret);check($ret===0,'tar archive readable');$names=array_map(fn($n)=>preg_replace('#^\./#','',$n),$lines);}
 else{$zip=new ZipArchive();check($zip->open($archives[0])===true,'ZIP archive readable');$names=[];for($i=0;$i<$zip->numFiles;$i++)$names[]=preg_replace('#^\./#','',$zip->getNameIndex($i));}
 sort($names);file_put_contents($root.'/manifest.json',json_encode($names));
 $unexpected=[];
 foreach($outsideNames as $name){if(in_array($name,$names,true))$unexpected[]=$name;else echo 'PASS outside wp-content archive omitted: '.$name."\n";}
 check($unexpected===[],'outside archive exclusions: '.implode(', ',$unexpected));
 foreach(array_merge($inside,$ordinary) as $name){check(in_array($name,$names,true),'existing included file retained: '.$name);if($method==='tar'){$reader=proc_open(['tar','-xOf',$archives[0],'./'.$name],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$streams);fclose($streams[0]);$archived=stream_get_contents($streams[1]);$errors=stream_get_contents($streams[2]);fclose($streams[1]);fclose($streams[2]);check(proc_close($reader)===0 && $errors==='','tar member read succeeds: '.$name);}else{$archived=$zip->getFromName($name);}check($archived===$original[$name],'archived bytes unchanged: '.$name);}
 if($method==='cli' && getenv('HOSTING_EXPORT_CONTROL')!=='1')check(in_array('linked-content/folder.zip/notes.txt',$names,true),'non-ZIP file in linked directory ending .zip retained');
 if($method!=='tar')$zip->close();
 foreach($original as $name=>$data)check(file_get_contents($site.'/'.$name)===$data,'source untouched: '.$name);
 echo "All hosting export checks passed: $method\n";
}catch(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage()."\nArtifacts: $root\n");$exit=1;}
finally{proc_terminate($process);proc_close($process);}
exit($exit);
