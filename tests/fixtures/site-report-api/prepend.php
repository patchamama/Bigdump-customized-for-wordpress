<?php
namespace SiteReportFixture;
// No real database or TLS connection is ever made by these fixtures.
$_SERVER['HTTPS'] = isset($_GET['http']) ? '' : 'on';
// Fail only synthetic configuration writes, including atomic temporary files.
function config_path($path) { return str_starts_with(basename($path), '.migrate_config'); }
function file_put_contents($path, $data, $flags = 0, $context = null) {
    if (config_path($path)) {
        if (($_GET['fail_config'] ?? '') === 'write') return false;
        if (($_GET['fail_config'] ?? '') === 'short') return \file_put_contents($path, substr($data, 0, -1), $flags);
    }
    return \file_put_contents($path, $data, $flags, $context);
}
function chmod($path, $mode) {
    if (config_path($path) && ($_GET['fail_config'] ?? '') === 'permissions') return false;
    return \chmod($path, $mode);
}
function rename($from, $to) {
    if (config_path($to) && ($_GET['fail_config'] ?? '') === 'rename') return false;
    return \rename($from, $to);
}
class PDO {
    const ATTR_ERRMODE = 3, ERRMODE_EXCEPTION = 2, ATTR_TIMEOUT = 2, FETCH_ASSOC = 2;
    public function __construct(...$args) { throw new \Exception('SYNTHETIC_DB_SECRET'); }
}
