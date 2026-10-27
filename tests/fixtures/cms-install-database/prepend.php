<?php
namespace InstallDatabaseFixture;
foreach (['MYSQLI_OPT_CONNECT_TIMEOUT'=>0,'MYSQLI_OPT_READ_TIMEOUT'=>11,'MYSQLI_OPT_LOCAL_INFILE'=>8] as $name=>$value) {
    if (!\defined($name)) define($name, $value);
}
function defined($name) { return !isset($_GET['missingconst']) && \defined($name); }
function extension_loaded($name) { return !isset($_GET['nodriver']) && in_array($name, ['mysqli','pdo_pgsql'], true); }
function random_bytes($length) { return str_repeat('x', $length); }
function time() { return 1800000000; }
function probe($kind, $value = null) { file_put_contents(__DIR__.'/probes', json_encode([$kind,$value])."\n", FILE_APPEND); }
class Rows {
    private $rows;
    function __construct($rows) { $this->rows = $rows; }
    function fetch_row() { return array_shift($this->rows); }
    function fetch($mode = null) { return $this->fetch_row() ?: false; }
    function free() { probe('free'); }
    function closeCursor() { probe('free'); }
}
function rows($sql) {
    probe('query', $sql);
    if (isset($_GET['queryfail'])) throw new \Exception('DB_SECRET raw SQL exception');
    if (stripos($sql, 'version') !== false) {
        $version = $_GET['version'] ?? '8.4.2';
        return new Rows([[$version, $_GET['vendor'] ?? 'MySQL Community Server']]);
    }
    if (isset($_GET['truncate'])) return new Rows(array_fill(0, 257, ['unrelated']));
    return new Rows(isset($_GET['collision']) ? [['fresh_users'],['fresh_view']] : [['other_table']]);
}
class mysqli {
    function __construct() { probe('init'); }
    function options($key, $value) { probe('option', [$key,$value]); return !isset($_GET['optionfail']); }
    function real_connect($host,$user,$password,$database,$port) {
        probe('connect', [$host,$port, $user === 'dbuser' && $password === 'DB_SECRET' && $database === 'fixturedb']);
        if (isset($_GET['authfail'])) throw new \Exception('DB_SECRET raw authentication exception');
        return true;
    }
    function query($sql) { return rows($sql); }
    function close() { probe('close'); }
}
class PDO {
    const ATTR_ERRMODE = 3, ERRMODE_EXCEPTION = 2, ATTR_TIMEOUT = 2, FETCH_ASSOC = 2, FETCH_NUM = 3;
    function __construct($dsn, $user = null, $password = null, $options = []) {
        if (($_GET['action'] ?? '') !== 'cms_install_database') throw new \Exception('DB_SECRET legacy unavailable');
        probe('connect', [$dsn, $user === 'dbuser' && $password === 'DB_SECRET']);
        if (isset($_GET['authfail'])) throw new \Exception('DB_SECRET raw authentication exception');
    }
    function query($sql) { return rows($sql); }
}
if (($_GET['action'] ?? '') === 'fixture') {
    session_start();
    $_SESSION['migrate_auth'] = isset($_GET['weak']) ? 1 : true;
    $_SESSION['cms_install_csrf'] = 'installer-test-csrf';
    if (isset($_SESSION['cms_install_draft'])) {
        if (isset($_GET['expire'])) $_SESSION['cms_install_draft']['expires_at'] = 1;
        if (isset($_GET['foreign'])) $_SESSION['cms_install_draft']['app'] = 'foreign';
    }
    echo hash('sha256', serialize($_SESSION));
    exit;
}
