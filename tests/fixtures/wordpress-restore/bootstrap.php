<?php
// HTTP admission contracts against a four-file synthetic hosting, never a real DB.
$root=sys_get_temp_dir().'/wordpress-restore-'.bin2hex(random_bytes(6));mkdir($root,0700);$site=$root.'/site';mkdir($site,0700);
$source=file_get_contents(getenv('WORDPRESS_RESTORE_SOURCE') ?: __DIR__.'/../../../cms_tools.php');
$source=preg_replace('/^<\?php/','<?php namespace WordPressRestoreFixture; use \\Exception; use \\Throwable; use \\DirectoryIterator; use \\RecursiveIteratorIterator; use \\RecursiveDirectoryIterator; use \\FilesystemIterator; use \\ZipArchive;', $source,1);
file_put_contents($site.'/cms_tools.php',$source);
file_put_contents($root.'/prepend.php', <<<'PHP'
<?php namespace WordPressRestoreFixture;
class PDO extends \PDO {
    public function __construct(...$args) {
        file_put_contents(__DIR__.'/connections', "attempt\n", FILE_APPEND);
        throw new \RuntimeException('Synthetic DB disabled');
    }
}
function class_exists($name, $autoload = true) {
    if (getenv('WORDPRESS_RESTORE_ZIP_BACKEND') === 'portable' && ltrim($name, '\\') === 'ZipArchive') return false;
    return \class_exists($name, $autoload);
}
PHP
);
file_put_contents($site.'/.migrate_config',json_encode(['password_hash'=>hash('sha256','odc_migrate_2026_x9k'.'FixturePassword')]));
$config="<?php\ndefine('DB_HOST','localhost');\ndefine('DB_NAME','fixture');\ndefine('DB_USER','fixture');\ndefine('DB_PASSWORD','DESTINATION_SECRET');\n\$table_prefix='wp_';\n";
file_put_contents($site.'/wp-config.php',$config);
file_put_contents($site.'/hosting_fixture.zip','Owned archive placeholder');file_put_contents($site.'/db_fixture.sql.gz',gzencode('-- Owned dump placeholder'));
$socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
$process=proc_open([PHP_BINARY,'-d','auto_prepend_file='.$root.'/prepend.php','-d','session.save_path='.$root,'-S','127.0.0.1:'.$port,'-t',$site],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);$cookie='';
function request($action='',$post=null){global$port,$cookie;$ctx=stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>"Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'timeout'=>10]]);$f=fopen('http://127.0.0.1:'.$port.'/cms_tools.php'.($action===''?'':'?action='.$action), 'rb',false,$ctx);$body=stream_get_contents($f);$headers=stream_get_meta_data($f)['wrapper_data'];fclose($f);foreach($headers as$h)if(preg_match('/^Set-Cookie: ([^;]+)/i',$h,$m))$cookie=$m[1];preg_match('#HTTP/\S+ (\d+)#',$headers[0],$m);return[(int)$m[1],$body];}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo"PASS $message\n";}
function jsonRequest($action,$post=null){[$status,$body]=request($action,$post);$json=json_decode($body,true);check(is_array($json),'JSON response: '.$action);check(!str_contains($body,'DESTINATION_SECRET') && !str_contains($body,'Synthetic DB disabled'),'no credentials/driver errors in response');return[$status,$json];}
