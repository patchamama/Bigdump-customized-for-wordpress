<?php
// Root-installed probe of actual mod_php identity and effective container settings.
$s=file_get_contents('/proc/self/status');
preg_match('/^Uid:\s+([0-9]+)/m',$s,$u); preg_match('/^CapEff:\s+([0-9a-f]+)/mi',$s,$c);
header('Content-Type: application/json');
echo json_encode(['uid'=>(int)($u[1] ?? 0),'caps'=>$c[1] ?? 'unsafe','php'=>PHP_VERSION,'server'=>$_SERVER['SERVER_SOFTWARE'] ?? '',
    'memory'=>ini_get('memory_limit'),'execution'=>(int)ini_get('max_execution_time'),'upload'=>ini_get('upload_max_filesize'),'post'=>ini_get('post_max_size')]);
