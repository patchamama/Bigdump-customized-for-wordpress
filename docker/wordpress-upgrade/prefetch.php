<?php
// Trusted prefetch only: no site code, backup or database is mounted in this phase.
function official($url, $limit) {
    if (!preg_match('#^https://(?:api\.wordpress\.org/core/(?:version-check/1\.7/|checksums/1\.0/\?version=[0-9.]+&locale=en_US)|downloads\.wordpress\.org/release/wordpress-[0-9.]+\.zip)$#D',$url)) throw new RuntimeException('Invalid official URL.');
    $context=stream_context_create(['http'=>['timeout'=>30,'follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>true],
        'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
    $stream=@fopen($url,'rb',false,$context);
    if (!$stream) throw new RuntimeException('Verified HTTPS unavailable.');
    try {
        $status=null;
        foreach (stream_get_meta_data($stream)['wrapper_data'] ?? [] as $h) if (preg_match('#^HTTP/\S+ ([0-9]{3})#',$h,$m)) $status=(int)$m[1];
        $body=stream_get_contents($stream,$limit+1);
        if ($status!==200 || !is_string($body) || strlen($body)>$limit || !feof($stream)) throw new RuntimeException('Official download failed or exceeded bound.');
        return $body;
    } finally { fclose($stream); }
}
try {
    if (count(glob('/cache/*'))!==0) throw new RuntimeException('Fresh owned cache required.');
    $release=official('https://api.wordpress.org/core/version-check/1.7/',2097152);
    $data=json_decode($release,true,32,JSON_THROW_ON_ERROR); $stable=[];
    if (!is_array($data['offers'] ?? null) || count($data['offers'])>100) throw new RuntimeException('Invalid release offers.');
    foreach ($data['offers'] as $o) {
        $v=$o['version'] ?? '';
        if (($o['response'] ?? '')==='upgrade' && ($o['locale'] ?? '')==='en_US' && ($o['channel'] ?? 'stable')==='stable'
            && preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D',$v)
            && ($o['packages']['full'] ?? '')==='https://downloads.wordpress.org/release/wordpress-'.$v.'.zip'
            && preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D',$o['php_version'] ?? '')
            && preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D',$o['mysql_version'] ?? '')) $stable[$v]=$o['packages']['full'];
    }
    if (!$stable) throw new RuntimeException('No stable offer.');
    uksort($stable,'version_compare'); $version=array_key_last($stable); $url=$stable[$version];
    $checksums=official('https://api.wordpress.org/core/checksums/1.0/?version='.$version.'&locale=en_US',2097152);
    $package=official($url,67108864);
    $files=['release.json'=>$release,'checksums.json'=>$checksums,'package.zip'=>$package,
        'provenance.json'=>json_encode(['schema'=>1,'version'=>$version,'captured_at'=>time(),'package_url'=>$url,'sha256'=>hash('sha256',$package)],JSON_THROW_ON_ERROR)];
    foreach ($files as $name=>$body) if (file_put_contents('/cache/'.$name,$body,LOCK_EX)!==strlen($body) || !chmod('/cache/'.$name,0444)) throw new RuntimeException('Cache write failed.');
    chmod('/cache',0555);
} catch (Throwable $e) { fwrite(STDERR,"Official prefetch failed closed.\n"); exit(1); }
