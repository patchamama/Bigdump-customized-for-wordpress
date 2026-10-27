<?php
// Trusted offline initialization. No backup PHP is included or evaluated here.
require __DIR__.'/import.php';
try {
    $admin=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) { exit(1); }
try {
    if (file_exists('/run/upgrade-setup-started')) throw new RuntimeException('Initialization is not retryable.');
    touch('/run/upgrade-setup-started');
    $prefix=getenv('CMS_TABLE_PREFIX'); $origin=getenv('CMS_PRODUCTION_ORIGIN');
    if (!preg_match('/^[A-Za-z0-9_]{1,40}$/D',$prefix) || !preg_match('#^https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?$#D',$origin)) throw new RuntimeException('Invalid staging identity.');
    $sql=file_get_contents('/data/seed.sql',false,null,0,33554433);
    $statements=upgrade_import_statements($sql,$prefix);
    $secrets=['seed'=>bin2hex(random_bytes(24)),'source'=>bin2hex(random_bytes(24)),'target'=>bin2hex(random_bytes(24)),'login'=>bin2hex(random_bytes(24))];
    foreach (['cmsseed','cmstarget'] as $schema) $admin->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4');
    foreach (['seed'=>'cmsimport','source'=>'cmssource','target'=>'cmstarget'] as $key=>$user) $admin->exec("CREATE USER '$user'@'localhost' IDENTIFIED BY ".$admin->quote($secrets[$key]));
    $admin->exec("GRANT SELECT, INSERT, UPDATE, CREATE ON `cmsseed`.* TO 'cmsimport'@'localhost'");
    $import=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=cmsseed;charset=utf8mb4','cmsimport',$secrets['seed'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]);
    $import->exec('SET SESSION max_statement_time=5');
    foreach ($statements as $statement) $import->exec($statement);
    $query=$import->prepare('UPDATE `'.$prefix.'options` SET option_value=? WHERE option_name IN (\'siteurl\',\'home\')');
    $query->execute(['https://staging.invalid:8443']);
    $admin->exec("DROP USER 'cmsimport'@'localhost'");
    $admin->exec("GRANT SELECT ON `cmsseed`.* TO 'cmssource'@'localhost'");
    $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX ON `cmstarget`.* TO 'cmstarget'@'localhost'");
    $config="<?php\n";
    $values=['WP_ENVIRONMENT_TYPE'=>'staging','CMS_TOOLS_PRODUCTION_URL'=>$origin,'CMS_TOOLS_STAGING_URL'=>'https://staging.invalid:8443','CMS_TOOLS_ISOLATION_VERIFIED'=>'docker-network-none',
        'DB_HOST'=>'localhost','DB_NAME'=>'cmsseed','DB_USER'=>'cmssource','DB_PASSWORD'=>$secrets['source'],'DB_CHARSET'=>'utf8mb4','DB_COLLATE'=>''];
    foreach ($values as $key=>$value) $config.='define('.var_export($key,true).', '.var_export($value,true).");\n";
    $config.='$table_prefix = '.var_export($prefix,true).";\nif (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\nrequire_once ABSPATH . 'wp-settings.php';\n";
    if (file_put_contents('/data/site/wp-config.php',$config)!==strlen($config)) throw new RuntimeException('Configuration write failed.');
    if (!copy('/opt/cms_tools.php','/data/site/cms_tools.php') || !copy('/opt/upgrade/runtime-settings.php','/data/site/cms-runtime-settings.php')) throw new RuntimeException('Trusted tool copy failed.');
    // Authentication uses a fresh local password, never the original migration config.
    file_put_contents('/data/site/.migrate_config',json_encode(['password_hash'=>hash('sha256','odc_migrate_2026_x9k'.$secrets['login'])],JSON_THROW_ON_ERROR));
    file_put_contents('/run/upgrade-secrets.json',json_encode(['login'=>$secrets['login'],'target'=>$secrets['target']],JSON_THROW_ON_ERROR));
    chmod('/run/upgrade-secrets.json',0600);
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/data/site',FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $file) {
        if ($file->isLink()) throw new RuntimeException('Unsafe copied resource.');
        // Set permissions while root still owns the file; CAP_FOWNER stays absent.
        if (!chmod($file->getPathname(),$file->isDir()?0700:0600)
            || !chgrp($file->getPathname(),'www-data') || !chown($file->getPathname(),'www-data')) throw new RuntimeException('Copied resource ownership failed.');
    }
    if (!chmod('/data/site',0700) || !chgrp('/data/site','www-data') || !chown('/data/site','www-data')) throw new RuntimeException('Site ownership failed.');
    // Root log directory is not web-readable; PHP errors go to its own non-public file.
    if (!touch('/data/logs/php-error.log') || !chmod('/data/logs/php-error.log',0600)
        || !chown('/data/logs/php-error.log','www-data')) throw new RuntimeException('Private PHP log ownership failed.');
    exit(0);
} catch (Throwable $e) { fwrite(STDERR,"Offline initialization failed closed.\n"); exit(1); }
