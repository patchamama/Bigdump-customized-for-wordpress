<?php
namespace UpgradeFixture;
// Every database and HTTPS operation in the copied application is synthetic.
function scenario() { return trim(\file_get_contents(__DIR__.'/scenario')); }
// Inventory-time mutation double: inspection must match the hash already captured.
function hash_file($algorithm,$path) {
    $hash=\hash_file($algorithm,$path);
    if ($path===__DIR__.'/wp-content/index.php' && scenario()==='index_hash_race')
        \file_put_contents($path,"<?php\n// Silence is golden.\n");
    return $hash;
}
// Simulate the trusted TLS virtual host; actual fixture transport stays loopback HTTP.
$_SERVER['HTTPS']=scenario()==='stage_plain_http'?'off':'on';
$_SERVER['HTTP_HOST']=scenario()==='stage_wrong_host'?'unexpected.fixture.test':'wp.fixture.test';
function state($target = true) { return json_decode(\file_get_contents(__DIR__.($target?'/target.json':'/source.json')),true); }
function save_target($state) { \file_put_contents(__DIR__.'/target.json',json_encode($state)); }
class PDO {
    const ATTR_ERRMODE=3, ERRMODE_EXCEPTION=2, ATTR_TIMEOUT=2, FETCH_COLUMN=7, FETCH_NUM=3, FETCH_ASSOC=2;
    public $target;
    public function __construct($dsn,$user,$password,$options=[]) {
        $this->target=$user==='sandbox_user' && $password==='TARGET_SECRET';
        if (!$this->target && !($user==='live_user' && $password==='SOURCE_SECRET')) throw new \RuntimeException('Synthetic DB unavailable');
    }
    public function query($sql) { return new Statement($this,$sql); }
    public function prepare($sql) { return new Statement($this,$sql); }
    public function exec($sql) {
        if (!$this->target) {
            if (!in_array($sql,['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ','START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY','ROLLBACK'],true)) throw new \RuntimeException('Source mutation refused: '.$sql);
            \file_put_contents(__DIR__.'/source-transactions.log',$sql."\n",FILE_APPEND);
            return 0;
        }
        if (!preg_match('/^CREATE TABLE `([A-Za-z0-9_]+)` \(/',$sql,$m)) throw new \RuntimeException('Unexpected target SQL: '.$sql);
        $state=state();
        if (isset($state['tables'][$m[1]])) throw new \RuntimeException('Existing target table');
        $state['tables'][$m[1]]=[]; $state['writes']++; save_target($state); return 0;
    }
}
class Statement {
    public function __construct(private $pdo,private $sql) {}
    public function fetchColumn() {
        if ($this->sql==='SELECT DATABASE()') return $this->pdo->target?'sandbox_db':(scenario()==='source_identity'?'wrong_schema':'live');
        if (str_starts_with($this->sql,'SELECT owner FROM')) return state()['tables']['wp_cms_upgrade_owner'][0]['owner'] ?? false;
        if (str_contains($this->sql,"option_name = 'active_plugins'")) {
            foreach (state()['tables']['wp_options'] ?? [] as $row) if ($row['option_name']==='active_plugins') return $row['option_value'];
            return false;
        }
        throw new \RuntimeException('Unexpected scalar SQL: '.$this->sql);
    }
    public function fetch($mode=null) {
        if (str_starts_with($this->sql,'SHOW CREATE TABLE')) { preg_match('/`([^`]+)`/',$this->sql,$m); return [$m[1],'CREATE TABLE `'.$m[1].'` (`option_name` varchar(255), `option_value` longtext) ENGINE=InnoDB']; }
        if (str_contains($this->sql,"option_name='siteurl'")) return ['https://wp.fixture.test'];
        throw new \RuntimeException('Unexpected fetch SQL');
    }
    public function fetchAll($mode=null) {
        $case=scenario();
        if ($this->sql==='SHOW GRANTS FOR CURRENT_USER') {
            if (!$this->pdo->target) {
                if ($case==='source_unavailable') throw new \RuntimeException('Synthetic grants unavailable');
                $grants=['GRANT USAGE ON *.* TO `live_user`@`localhost`','GRANT SELECT ON `live`.* TO `live_user`@`localhost`'];
                $unsafe=[
                    'source_global'=>'GRANT SELECT ON *.* TO `live_user`@`localhost`',
                    'source_write'=>'GRANT SELECT, UPDATE ON `live`.* TO `live_user`@`localhost`',
                    'source_all'=>'GRANT ALL PRIVILEGES ON `live`.* TO `live_user`@`localhost`',
                    'source_role'=>'GRANT `reader`@`%` TO `live_user`@`localhost`',
                    'source_delegation'=>'GRANT SELECT ON `live`.* TO `live_user`@`localhost` WITH GRANT OPTION',
                    'source_other'=>'GRANT SELECT ON `other`.* TO `live_user`@`localhost`',
                    'source_table'=>'GRANT SELECT ON `live`.`wp_options` TO `live_user`@`localhost`',
                    'source_missing'=>'GRANT USAGE ON *.* TO `live_user`@`localhost`'];
                if (isset($unsafe[$case])) $grants[]=$unsafe[$case];
                if ($case==='source_missing') return [$unsafe[$case]];
                if ($case==='source_empty') return [];
                return $grants;
            }
            $grants=['GRANT USAGE ON *.* TO `sandbox_user`@`localhost`','GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER ON `sandbox\\_db`.* TO `sandbox_user`@`localhost`'];
            if ($case==='global') $grants[]='GRANT SELECT ON *.* TO `sandbox_user`@`localhost`';
            if ($case==='role') $grants[]='GRANT `role`@`%` TO `sandbox_user`@`localhost`';
            if ($case==='delegation') $grants[1].=' WITH GRANT OPTION';
            if ($case==='wildcard') $grants[1]=str_replace('sandbox\\_db','sandbox_db',$grants[1]);
            if ($case==='other_schema') $grants[]='GRANT UPDATE ON `live`.* TO `sandbox_user`@`localhost`';
            return $grants;
        }
        if ($this->sql==='SHOW FULL TABLES') {
            if ($this->pdo->target && $case==='nonempty') return [['existing','BASE TABLE']];
            return array_map(fn($key)=>[$key,'BASE TABLE'],array_keys(state($this->pdo->target)['tables']));
        }
        if ($this->sql==='SHOW TRIGGERS') return $case==='triggers' ? [['Table'=>'wp_options']] : [];
        if (preg_match('/^SELECT \* FROM `([^`]+)` LIMIT 20001$/',$this->sql,$m)) return state(false)['tables'][$m[1]];
        if (str_starts_with($this->sql,'SELECT option_name, option_value')) return state(false)['tables']['wp_options'];
        throw new \RuntimeException('Unexpected list SQL: '.$this->sql);
    }
    public function execute($values=[]) {
        if (!$this->pdo->target) throw new \RuntimeException('Source prepared mutation refused');
        $state=state();
        if (preg_match('/^INSERT INTO `([^`]+)` \((.+)\) VALUES \(/',$this->sql,$m)) {
            $columns=array_map(fn($key)=>trim($key,'` '),explode(',',$m[2]));
            $state['tables'][$m[1]][]=array_combine($columns,$values);
        } elseif (str_starts_with($this->sql,'UPDATE `wp_options` SET option_value')) {
            foreach ($state['tables']['wp_options'] as &$row) if ($row['option_name']===$values[1]) $row['option_value']=$values[0];
            unset($row);
        } else throw new \RuntimeException('Unexpected prepared SQL: '.$this->sql);
        $state['writes']++; save_target($state); return true;
    }
}
function package_files() {
    $files=['wp-includes/version.php'=>"<?php \$wp_version = '99.2.1';",'wp-settings.php'=>'<?php // fixture','wp-load.php'=>'<?php // fixture','index.php'=>'<?php // fixture','wp-admin/includes/upgrade.php'=>'<?php // fixture'];
    if (in_array(scenario(),['native_safe','native_akismet_checksum'],true)) $files += [
        'wp-content/plugins/akismet/.htaccess'=>'Require all granted\n',
        'wp-content/plugins/akismet/_inc/img/akismet-refresh-logo@2x.png'=>'SYNTHETIC OFFICIAL IMAGE',
        'wp-content/themes/fixture/assets/Inter-VariableFont_slnt,wght.woff2'=>'SYNTHETIC OFFICIAL FONT',
    ];
    $controls=['native_control_root'=>'.htaccess','native_control_nested'=>'wp-content/plugins/other/.htaccess','native_control_php'=>'wp-content/plugins/akismet/php.ini','native_control_user'=>'wp-content/plugins/akismet/.user.ini','native_control_config'=>'wp-config.php'];
    if (isset($controls[scenario()])) $files[$controls[scenario()]]='UNTRUSTED CONTROL';
    return $files;
}
class ZipArchive {
    public $numFiles=5;
    private $entries=[];
    private $native=null;
    public function open($file) {
        if (str_starts_with(scenario(),'native_')) {
            $this->native=new \ZipArchive();
            $result=$this->native->open($file);
            if ($result===true) $this->numFiles=$this->native->numFiles;
            \file_put_contents(__DIR__.'/native-reader.log',scenario()."\n",FILE_APPEND);
            return $result;
        }
        foreach (package_files() as $name=>$body) $this->entries[]=['name'=>'wordpress/'.$name,'body'=>$body,'size'=>strlen($body)];
        $case=scenario();
        if ($case==='archive_path') $this->entries[]=['name'=>'wordpress/../wp-config.php','body'=>'bad','size'=>3];
        if ($case==='archive_duplicate') $this->entries[]=$this->entries[0];
        if ($case==='archive_large') $this->entries[0]['size']=16777217;
        if ($case==='archive_missing') array_pop($this->entries);
        $this->numFiles=count($this->entries); return $case==='invalid_archive'?false:true;
    }
    public function statIndex($i) { return $this->native ? $this->native->statIndex($i) : $this->entries[$i]; }
    public function getExternalAttributesIndex($i,&$opsys,&$attributes) {
        if ($this->native) return $this->native->getExternalAttributesIndex($i,$opsys,$attributes);
        $opsys=3; $attributes=((scenario()==='archive_symlink' && $i===0)?0120000:0100000)<<16; return true;
    }
    public function getFromIndex($i) { return $this->native ? $this->native->getFromIndex($i) : (scenario()==='checksum_mismatch' && $i===0 ? 'corrupt' : $this->entries[$i]['body']); }
    public function close() { if ($this->native) $this->native->close(); }
}
function fopen($url,$mode,$include=false,$context=null) {
    if (!str_starts_with($url,'https://')) return \fopen($url,$mode,$include,$context);
    $options=stream_context_get_options($context);
    if (($options['ssl']['verify_peer'] ?? false)!==true || ($options['ssl']['verify_peer_name'] ?? false)!==true || ($options['ssl']['allow_self_signed'] ?? true)!==false || ($options['http']['follow_location'] ?? 1)!==0) throw new \RuntimeException('Unsafe HTTPS options');
    $u=parse_url($url); $case=scenario(); $status=200; $headers=[]; $body='';
    \file_put_contents(__DIR__.'/http.log',$url."\n",FILE_APPEND);
    if ($u['host']==='api.wordpress.org') {
        $offer=['response'=>'upgrade','version'=>'99.2.1','locale'=>'en_US','php_version'=>'8.0','mysql_version'=>'5.7','packages'=>['full'=>'https://downloads.wordpress.org/release/wordpress-99.2.1.zip']];
        if ($case==='beta') $offer['channel']='beta';
        if ($case==='bad_origin') $offer['packages']['full']='https://evil.invalid/core.zip';
        $sums=array_map('md5',package_files());
        if ($case==='bad_checksum') $sums['../wp-config.php']=str_repeat('a',32);
        if ($case==='missing_checksum') unset($sums['wp-settings.php']);
        $body=str_contains($url,'version-check')?json_encode(['offers'=>$case==='malformed_offers'?'bad':[$offer]]):json_encode(['checksums'=>$sums]);
        if ($case==='invalid_json') $body='invalid';
        if ($case==='oversized') $body=str_repeat('x',2097153);
        $status=['redirect'=>302,'status0'=>0,'not_found'=>404,'server_error'=>500][$case] ?? 200;
        $headers[]='Location: https://evil.invalid/';
    } elseif ($u['host']==='downloads.wordpress.org') {
        $body='SYNTHETIC ZIP';
        if (str_starts_with($case,'native_')) {
            $archive=__DIR__.'/native-package.zip'; $zip=new \ZipArchive();
            if ($zip->open($archive,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true) throw new \RuntimeException('Native fixture ZIP creation failed');
            $files=package_files(); if ($case==='native_path') $files['../escape.php']='bad';
            if ($case==='native_checksum') $files['wp-settings.php']='CORRUPTED SYNTHETIC CORE';
            if ($case==='native_akismet_checksum') $files['wp-content/plugins/akismet/.htaccess']='CORRUPTED OMITTED RULES';
            foreach ($files as $name=>$contents) {
                $entry='wordpress/'.$name; $zip->addFromString($entry,$contents);
                $type=$case==='native_symlink' && $name==='wp-settings.php'?0120777:0100600;
                $zip->setExternalAttributesName($entry,\ZipArchive::OPSYS_UNIX,$type<<16);
            }
            $zip->close(); $body=\file_get_contents($archive);
        }
    }
    elseif ($u['host']==='wp.fixture.test') {
        if (!preg_match('#^/(cms-upgrade-([a-f0-9]{24}))/(.*)$#',$u['path'],$m)) throw new \RuntimeException('Escaped sandbox request');
        $dir=__DIR__.'/'.$m[1]; $id=$m[2]; $path=$m[3];
        $rules=\file_get_contents($dir.'/.htaccess'); preg_match('/!\^([a-f0-9]{64})\$/',$rules,$token);
        $authenticated=str_contains($options['http']['header'] ?? '', 'X-CMS-Upgrade: '.($token[1] ?? 'NO_TOKEN'));
        $headers[]='X-CMS-Upgrade-Identity: '.($case==='wrong_identity' && $path===''?'wrong':$id);
        if ($case==='htaccess') $status=500;
        elseif (!$authenticated) $status=$case==='unprotected'?200:403;
        elseif ($path==='wp-config.php') $status=$case==='unprotected'?200:403;
        elseif ($path==='cms-upgrade-probe.php') {
            $state=state(); $active=[];
            foreach ($state['tables']['wp_options'] as $row) if ($row['option_name']==='active_plugins') $active=unserialize($row['option_value'],['allowed_classes'=>false]);
            if (($u['query'] ?? '')==='mode=upgrade') {
                $state['core_upgrades']=($state['core_upgrades'] ?? 0)+1; save_target($state);
                if ($case==='core_file_mutation') \file_put_contents($dir.'/wp-settings.php','UNEXPECTED CORE MUTATION');
                $body=json_encode(['probe_ok'=>true,'version'=>'99.2.1','db_version'=>999,'expected_db_version'=>999,'php'=>'8.4.0','mysql'=>'8.0.40','rest_url'=>match($case) {'plain_rest'=>'https://wp.fixture.test/'.$m[1].'/?rest_route=/','encoded_rest'=>'https://wp.fixture.test/'.$m[1].'/?rest_route=%2F','index_rest'=>'https://wp.fixture.test/'.$m[1].'/index.php/wp-json/','front_rest'=>'https://wp.fixture.test/'.$m[1].'/index.php?rest_route=/','front_encoded_rest'=>'https://wp.fixture.test/'.$m[1].'/index.php?rest_route=%2F','foreign_rest'=>'https://evil.invalid/','traversal_rest'=>'https://wp.fixture.test/'.$m[1].'/../wp-json/','malformed_rest'=>['unexpected'],default=>'https://wp.fixture.test/'.$m[1].'/wp-json/'},'permalink'=>'https://wp.fixture.test/'.$m[1].'/cms-upgrade-smoke/','ini'=>['memory_limit'=>$case==='php_failure'?'8M':'256M','max_execution_time'=>'30','upload_max_filesize'=>'8M','post_max_size'=>'16M','auto_prepend_file'=>'','auto_append_file'=>''],'mysqli'=>true,'mail_blocked'=>true,'cron_disabled'=>true,'indexing'=>0]);
                if ($case==='legacy_rest') { $legacy=json_decode($body,true); unset($legacy['rest_url']); $body=json_encode($legacy); }
                if ($case==='core_failure') $status=500;
            } elseif ($case==='plugin_failure' && $active===['broken/main.php']) { $status=500; $body='Plugin fatal'; }
            elseif ($case==='plugin_unavailable' && $active===['broken/main.php']) throw new \RuntimeException('Synthetic request unavailable');
            else $body=json_encode(['probe_ok'=>true,'active'=>$active]);
        } elseif (in_array($path,['','index.php'],true) && in_array($u['query'] ?? '',['rest_route=/','rest_route=%2F'],true)) {
            $body='{"name":"Synthetic plain-permalink REST index"}';
        } elseif ($path==='') {
            $body='<html><img src="wp-content/uploads/test.png"></html>';
            if ($case==='asset_limit') { $body=''; for ($i=0;$i<101;$i++) $body.='<img src="wp-content/uploads/test.png?i='.$i.'">'; }
            $status=['http500'=>500,'http0'=>0,'unsafe_redirect'=>302][$case] ?? 200;
            if ($case==='unsafe_redirect') $headers[]='Location: https://wp.fixture.test/';
        } elseif ($path==='wp-admin/') { $status=302; $headers[]='Location: /'.$m[1].'/wp-login.php'; }
        elseif ($path==='wp-json/' || $path==='index.php/wp-json/') $body=in_array($case,['plain_rest','front_rest','front_encoded_rest'],true)?'<html>Not a REST index</html>':'{"name":"Synthetic sandbox"}';
        elseif ($path==='wp-login.php' || $path==='cms-upgrade-smoke/') $body='Sandbox page';
        elseif (is_file($dir.'/'.$path)) { $body=\file_get_contents($dir.'/'.$path); if ($case==='missing_asset' && str_ends_with($path,'test.png')) $status=404; }
        else $status=404;
    } else throw new \RuntimeException('Forbidden external request: '.$url);
    $stream=\fopen('php://temp','w+'); fwrite($stream,$body); rewind($stream);
    $GLOBALS['fixture_http'][(int)$stream]=array_merge(['HTTP/1.1 '.$status.' Synthetic'],$headers);
    return $stream;
}
function stream_get_meta_data($stream) { return isset($GLOBALS['fixture_http'][(int)$stream]) ? ['wrapper_data'=>$GLOBALS['fixture_http'][(int)$stream]] : \stream_get_meta_data($stream); }
