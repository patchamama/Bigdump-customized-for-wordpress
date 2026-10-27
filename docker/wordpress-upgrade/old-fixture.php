<?php
// Trusted, fixed-version fixture tooling. Never include backup PHP or accept source credentials.
function old_fixture_path(string $path): string {
    if (!preg_match('#^wordpress/(?:[A-Za-z0-9_.,@-]+/)*[A-Za-z0-9_.,@-]+/?$#D',$path)
        || array_intersect(['.','..'],explode('/',$path))) {
        throw new InvalidArgumentException('Unsafe release path.');
    }
    return substr($path,10);
}
function old_fixture_manifest(string $json): array {
    $data=json_decode($json,true);
    $checks=$data['checksums'] ?? null;
    if (!is_array($checks) || !$checks || count($checks)>6000) throw new InvalidArgumentException('Invalid checksums.');
    foreach ($checks as $path=>$hash) {
        old_fixture_path('wordpress/'.$path);
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{32}$/D',$hash)) throw new InvalidArgumentException('Invalid checksum.');
    }
    return $checks;
}
function old_fixture_fetch(string $url, int $limit): string {
    $context=stream_context_create(['http'=>['follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>true,'timeout'=>30],
        'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
    $stream=fopen($url,'rb',false,$context);
    if (!$stream) throw new RuntimeException('Fetch failed.');
    try {
        $headers=stream_get_meta_data($stream)['wrapper_data'] ?? [];
        if (!preg_match('#^HTTP/\S+ 200(?: |$)#',$headers[0] ?? '')) throw new RuntimeException('Unexpected status.');
        $body=''; $deadline=microtime(true)+120;
        while (!feof($stream)) {
            $part=fread($stream,min(65536,$limit+1-strlen($body)));
            if ($part===false || ($part==='' && !feof($stream))) throw new RuntimeException('Incomplete body.');
            $body.=$part;
            if (strlen($body)>$limit || microtime(true)>$deadline) throw new RuntimeException('Fetch limit.');
        }
        return $body;
    } finally { fclose($stream); }
}
function old_fixture_write(string $path, string $body): void {
    $file=fopen($path,'xb');
    if (!$file) throw new RuntimeException('Not fresh.');
    try { if (fwrite($file,$body)!==strlen($body)) throw new RuntimeException('Short write.'); }
    finally { fclose($file); }
}
function old_fixture_archive(?string $destination=null): void {
    $checks=old_fixture_manifest(file_get_contents('/cache/wordpress-6.8.3-checksums.json'));
    $zip=new ZipArchive();
    if ($zip->open('/cache/wordpress-6.8.3.zip',ZipArchive::RDONLY)!==true) throw new RuntimeException('Invalid archive.');
    try {
        if ($zip->numFiles>6000) throw new RuntimeException('Entry limit.');
        $seen=[]; $files=[]; $total=0;
        for ($i=0;$i<$zip->numFiles;$i++) {
            $entry=$zip->statIndex($i); $name=$entry['name'];
            if ($name==='wordpress/') continue;
            $path=old_fixture_path($name);
            if (isset($seen[$path])) throw new RuntimeException('Duplicate path.');
            $seen[$path]=true;
            $zip->getExternalAttributesIndex($i,$os,$attributes);
            $type=($attributes>>16)&0170000;
            $directory=str_ends_with($path,'/');
            if (($type!==0 && $type!==($directory?0040000:0100000)) || ($entry['encryption_method'] ?? 0)!==0) throw new RuntimeException('Nonregular entry.');
            if ($directory) continue;
            $total+=$entry['size'];
            if ($entry['size']>8388608 || $total>167772160 || !isset($checks[$path])) throw new RuntimeException('Archive bounds.');
            $body=$zip->getFromIndex($i,$entry['size']+1);
            if (!is_string($body) || strlen($body)!==$entry['size'] || !hash_equals($checks[$path],md5($body))) throw new RuntimeException('Checksum mismatch.');
            $files[$path]=$i;
        }
        if (count($files)!==count($checks) || !isset($files['wp-includes/version.php'],$files['wp-admin/includes/upgrade.php'])) throw new RuntimeException('Incomplete release.');
        // Complete validation precedes any extraction. Never use extractTo or interpret archive PHP here.
        if ($destination!==null) foreach ($files as $path=>$i) {
            $parent=dirname($destination.'/'.$path);
            if (!is_dir($parent) && !mkdir($parent,0700,true)) throw new RuntimeException('Directory failed.');
            old_fixture_write($destination.'/'.$path,$zip->getFromIndex($i));
        }
    } finally { $zip->close(); }
}
function old_fixture_root(): void {
    if (posix_geteuid()!==0) throw new RuntimeException('Root preparation required.');
}
function old_fixture_prepare(): void {
    old_fixture_root();
    if (file_exists('/data/site') || !is_dir('/data/mysql') || !is_file('/data/fixture-started')) throw new RuntimeException('Invalid fresh state.');
    old_fixture_archive();
    mkdir('/data/site',0700);
    old_fixture_archive('/data/site');
    $admin=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $password=bin2hex(random_bytes(24));
    $admin->exec('CREATE DATABASE fixture CHARACTER SET utf8mb4');
    $admin->exec("CREATE USER 'fixture'@'localhost' IDENTIFIED BY ".$admin->quote($password));
    $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX ON fixture.* TO 'fixture'@'localhost'");
    $config="<?php\n";
    $constants=['DB_NAME'=>'fixture','DB_USER'=>'fixture','DB_PASSWORD'=>$password,'DB_HOST'=>'localhost:/run/mysqld/mysqld.sock',
        'DB_CHARSET'=>'utf8mb4','DB_COLLATE'=>'','WP_ENVIRONMENT_TYPE'=>'local','WP_HOME'=>'https://fixture.invalid',
        'WP_SITEURL'=>'https://fixture.invalid','DISABLE_WP_CRON'=>true,'WP_HTTP_BLOCK_EXTERNAL'=>true,
        'AUTOMATIC_UPDATER_DISABLED'=>true,'WP_AUTO_UPDATE_CORE'=>false,'DISALLOW_FILE_MODS'=>true];
    foreach ($constants as $key=>$value) $config.='define('.var_export($key,true).','.var_export($value,true).");\n";
    foreach (['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $key) $config.="define('$key','".bin2hex(random_bytes(32))."');\n";
    $config.="\$table_prefix='old_';\nif (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');\nrequire_once ABSPATH.'wp-settings.php';\n";
    old_fixture_write('/data/site/wp-config.php',$config);
    // Change modes before transferring ownership (CAP_FOWNER is deliberately absent).
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/data/site',FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $file) {
        $path=$file->getPathname();
        if ($file->isLink() || !chmod($path,$file->isDir()?0700:0600) || !chgrp($path,33) || !chown($path,33)) throw new RuntimeException('Ownership failed.');
    }
    chmod('/data/site',0700); chgrp('/data/site',33); chown('/data/site',33);
}
function old_fixture_create_statement(string $create): string {
    // Reorder strictly known options for the narrow importer; do not discard
    // genuine charset/collation or AUTO_INCREMENT state from SHOW CREATE TABLE.
    if (!preg_match('/^(CREATE TABLE `old_[a-z_]+` \(.*\)) ENGINE=InnoDB((?: AUTO_INCREMENT=[0-9]+)?(?: DEFAULT CHARSET=utf8mb4)?(?: COLLATE=utf8mb4_[a-z0-9_]+)?)$/sD',$create,$m)) throw new RuntimeException('Unsupported table options.');
    return $m[1].$m[2].' ENGINE=InnoDB';
}
function old_fixture_install(): void {
    $status=file_get_contents('/proc/self/status');
    if (posix_geteuid()!==33 || posix_getuid()!==33 || !preg_match('/^CapEff:\s+0+$/m',$status)) throw new RuntimeException('Unsafe PHP identity.');
    if (file_exists('/data/seed.sql') || file_exists('/data/fixture-metadata.json')) throw new RuntimeException('Not retryable.');
    // This process can evaluate only freshly checksum-verified official core and the trusted fixture plugin.
    define('WP_INSTALLING',true);
    // Suppress mail in this fixture process without adding artificial source MU plugins.
    function wp_mail($to,$subject,$message,$headers='',$attachments=[]) { return false; }
    require '/data/site/wp-load.php';
    require_once '/data/site/wp-admin/includes/upgrade.php';
    global $wpdb,$wp_version,$wp_db_version;
    if ($wp_version!=='6.8.3' || $wpdb->get_var('SHOW TABLES')!==null) throw new RuntimeException('Not fresh old core.');
    wp_install('Isolated old fixture','fixture_admin','fixture@example.invalid',false,'',bin2hex(random_bytes(24)),'en_US');
    $dbVersion=(int)get_option('db_version');
    $posts=(int)$wpdb->get_var("SELECT COUNT(*) FROM old_posts WHERE post_type='post' AND post_status='publish'");
    $admins=count(get_users(['role'=>'administrator','fields'=>'ID']));
    if ($dbVersion!==(int)$wp_db_version || $posts<1 || $admins!==1 || !is_blog_installed()) throw new RuntimeException('Installation proof failed.');
    $pdo=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=fixture;charset=utf8mb4',DB_USER,DB_PASSWORD,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (count($tables)!==12) throw new RuntimeException('Unexpected table count.');
    $sql=''; $rows=0;
    foreach ($tables as $table) {
        if (!preg_match('/^old_[a-z_]+$/D',$table)) throw new RuntimeException('Unexpected table.');
        $create=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        $create=old_fixture_create_statement($create);
        $sql.=$create.";\n";
        $query=$pdo->query('SELECT * FROM `'.$table.'` LIMIT 1001');
        $count=0;
        while ($row=$query->fetch(PDO::FETCH_NUM)) {
            if (++$count>1000) throw new RuntimeException('Row bound.');
            $values=array_map(static fn($v)=>$v===null?'NULL':$pdo->quote((string)$v),$row);
            $sql.='INSERT INTO `'.$table.'` VALUES ('.implode(',',$values).");\n"; $rows++;
            if (strlen($sql)>33554432) throw new RuntimeException('SQL bound.');
        }
    }
    require '/opt/upgrade/import.php';
    upgrade_import_statements($sql,'old_');
    old_fixture_write('/data/seed.sql',$sql);
    old_fixture_write('/data/fixture-metadata.json',json_encode(['ok'=>true,'version'=>$wp_version,'db_version'=>$dbVersion,
        'tables'=>count($tables),'rows'=>$rows,'published_posts'=>$posts,'administrators'=>$admins,'prefix'=>'old_',
        'source'=>'official-6.8.3-fresh-install','preserved_default_core'=>true],JSON_THROW_ON_ERROR)."\n");
}
if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {
    umask(0077);
    ini_set('display_errors','0');
    set_error_handler(static function ($severity,$message,$file,$line) {
        if (!(error_reporting() & $severity)) return false;
        throw new ErrorException('Fixture operation failed.',0,$severity,$file,$line);
    });
    try {
        if ($argc!==2) throw new RuntimeException('Invalid arguments.');
        switch ($argv[1]) {
            case '--help': echo "Usage: old-fixture.php prefetch|prepare|install|--help (fixed /cache and /data only)\n"; break;
            case 'prefetch':
                // No DB, site, credentials, environment, or caller-selected URL is read in this branch.
                if (!is_dir('/cache') || is_link('/cache') || scandir('/cache')!==['.','..','tools']
                    || !is_dir('/cache/tools') || is_link('/cache/tools')) throw new RuntimeException('Cache not empty.');
                old_fixture_write('/cache/wordpress-6.8.3-checksums.json',old_fixture_fetch('https://api.wordpress.org/core/checksums/1.0/?version=6.8.3&locale=en_US',1048576));
                old_fixture_write('/cache/wordpress-6.8.3.zip',old_fixture_fetch('https://downloads.wordpress.org/release/wordpress-6.8.3.zip',67108864));
                old_fixture_archive();
                echo "{\"ok\":true,\"phase\":\"prefetch\",\"version\":\"6.8.3\"}\n"; break;
            case 'prepare': old_fixture_prepare(); break;
            case 'install': old_fixture_install(); break;
            default: throw new RuntimeException('Invalid mode.');
        }
    } catch (Throwable $e) {
        // Private bounded location only; never print config, SQL, passwords or raw traces.
        if (in_array($argv[1] ?? '',['prepare','install'],true) && is_dir('/data')) {
            @file_put_contents('/data/fixture-failure.json',json_encode(['phase'=>$argv[1],'class'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine()])."\n");
        }
        fwrite(STDERR,"Old fixture failed closed.\n"); exit(1);
    }
}
