<?php
/**
 * BigDump WordPress Edition v0.39-wp
 * Staggered MySQL importer with wp-config.php auto-load, URL replacement,
 * backup, FTP/SFTP deploy, and persistent configuration.
 *
 * Original: Alexey Ozerov — GPL (C) 2003-2015
 * WP Edition enhancements: GPL
 */

error_reporting(E_ALL);
@set_time_limit(0);
@ini_set('auto_detect_line_endings', true);

define('VERSION',           '0.40-wp');
define('DATA_CHUNK_LENGTH',  1048576); // 1MB per fgets() read — handles long extended-insert lines
define('TESTMODE',           false);
define('BIGDUMP_DIR',        dirname(__FILE__));
define('CONFIG_FILE',        BIGDUMP_DIR . '/bigdump.config.json');
define('BACKUP_DIR',         BIGDUMP_DIR . '/backups');

// Create backup directory if it doesn't exist
if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0775);

// ============================================================
// CONFIGURATION — load / save
// ============================================================

function cfg_load(): array {
    if (!file_exists(CONFIG_FILE)) return [];
    $raw = file_get_contents(CONFIG_FILE);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function cfg_save(array $data): void {
    file_put_contents(CONFIG_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function cfg_get(string $key, $default = '') {
    static $cfg = null;
    if ($cfg === null) $cfg = cfg_load();
    return $cfg[$key] ?? $default;
}

// ============================================================
// HELPERS
// ============================================================

function ini_bytes(string $key): int {
    $val = ini_get($key);
    if ($val === false || $val === '') return 0;
    $num  = (int) $val;
    $unit = strtolower(substr(trim($val), -1));
    if ($unit === 'g') $num *= 1073741824;
    elseif ($unit === 'm') $num *= 1048576;
    elseif ($unit === 'k') $num *= 1024;
    return $num;
}

function fmt_bytes(int $bytes): string {
    if ($bytes <= 0) return '∞';
    if ($bytes >= 1073741824) return round($bytes/1073741824,1).' GB';
    if ($bytes >= 1048576)    return round($bytes/1048576,1).' MB';
    if ($bytes >= 1024)       return round($bytes/1024,1).' KB';
    return $bytes.' B';
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ============================================================
// WORDPRESS DB HELPERS
// ============================================================

function wp_db(string $server, string $user, string $pass, string $db, string $charset = 'utf8mb4'): ?mysqli {
    $m = @new mysqli($server, $user, $pass, $db);
    if ($m->connect_error) return null;
    $m->query("SET NAMES $charset");
    return $m;
}

function wp_tables(string $prefix): array {
    return [
        'users'    => $prefix . 'users',
        'usermeta' => $prefix . 'usermeta',
        'options'  => $prefix . 'options',
    ];
}

function wp_portable_hash(string $password): string {
    $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $count_log2 = 8;
    $count = 1 << $count_log2;
    $salt  = substr(str_shuffle($itoa64 . $itoa64), 0, 8);
    $hash  = md5($salt . $password, true);
    do { $hash = md5($hash . $password, true); } while (--$count);
    $output = '$P$';
    $output .= $itoa64[min($count_log2 + 5, 30)];
    $output .= $salt;
    $len = strlen($hash); $i = 0; $encoded = '';
    do {
        $value = ord($hash[$i++]);
        $encoded .= $itoa64[$value & 0x3f];
        if ($i < $len) $value |= ord($hash[$i]) << 8;
        $encoded .= $itoa64[($value >> 6) & 0x3f];
        if ($i++ >= $len) break;
        if ($i < $len) $value |= ord($hash[$i]) << 16;
        $encoded .= $itoa64[($value >> 12) & 0x3f];
        if ($i++ >= $len) break;
        $encoded .= $itoa64[($value >> 18) & 0x3f];
    } while ($i < $len);
    return $output . $encoded;
}

// ============================================================
// URL VALIDATOR
// ============================================================

/**
 * Validate a URL AND verify it points to a live WordPress installation
 * by checking a file that is always present in WP (/wp-login.php).
 * Returns ['ok'=>bool, 'msg'=>string, 'url_checked'=>string]
 */
function validate_wp_url(string $url): array {
    $url = rtrim($url, '/');

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'msg' => 'The URL format is not valid.', 'url_checked' => ''];
    }

    // WordPress always ships these — check the most reliable one
    $probes = [
        $url . '/wp-login.php',
        $url . '/wp-includes/version.php',
        $url . '/readme.html',
    ];

    if (!function_exists('curl_init')) {
        // Fallback: try get_headers with a short timeout
        foreach ($probes as $probe) {
            $ctx = stream_context_create(['http' => [
                'method'          => 'HEAD',
                'timeout'         => 8,
                'follow_location' => true,
                'ignore_errors'   => true,
            ]]);
            $headers = @get_headers($probe, 1, $ctx);
            if ($headers && isset($headers[0]) && preg_match('/^HTTP\/\S+\s+2\d\d/', $headers[0])) {
                return ['ok' => true, 'msg' => 'Valid URL — WordPress detected.', 'url_checked' => $probe];
            }
        }
        return ['ok' => false, 'msg' => 'Could not confirm a WordPress installation at that URL. Make sure the site is online.', 'url_checked' => $probes[0]];
    }

    foreach ($probes as $probe) {
        $ch = curl_init($probe);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'BigDump-WP-Validator/1.0',
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // 200 or 302 (login redirect) are both valid WP responses
        if ($code >= 200 && $code < 400) {
            return ['ok' => true, 'msg' => "WordPress confirmed (HTTP $code).", 'url_checked' => $probe];
        }
    }
    return ['ok' => false, 'msg' => 'WordPress was not found at that URL (no core resource responded with 2xx/3xx).', 'url_checked' => $probes[0]];
}

// ============================================================
// BACKUP — mysqldump first, PHP fallback
// ============================================================

function run_backup(string $db_server, string $db_username, string $db_password, string $db_name, string $upload_dir): array {
    $ts       = date('Ymd_His');
    $filename = "backup_{$db_name}_{$ts}.sql";
    $filepath = BACKUP_DIR . '/' . $filename;

    // Try mysqldump — check absolute paths first (Apache may not have the user PATH)
    $mysqldump = null;
    $mysqldump_candidates = [
        '/Applications/XAMPP/xamppfiles/bin/mysqldump',  // XAMPP macOS
        '/opt/lampp/bin/mysqldump',                       // XAMPP Linux
        '/usr/local/bin/mysqldump',                       // Homebrew / custom
        '/usr/bin/mysqldump',                             // system package
        '/opt/homebrew/bin/mysqldump',                    // Homebrew Apple Silicon
        'mysqldump',                                      // in PATH (works if Apache inherits it)
    ];
    foreach ($mysqldump_candidates as $bin) {
        // For absolute paths: check existence + executable bit before shelling out
        if (str_starts_with($bin, '/') && (!file_exists($bin) || !is_executable($bin))) continue;
        $test = @shell_exec(escapeshellarg($bin) . ' --version 2>&1');
        if ($test && stripos($test, 'mysqldump') !== false) {
            $mysqldump = $bin;
            break;
        }
    }

    if ($mysqldump) {
        // Build args array to avoid shell quoting issues with passwords containing special chars
        $args = [
            $mysqldump,
            '-h', $db_server,
            '-u', $db_username,
            '--single-transaction',
            '--routines',
            '--triggers',
            '--result-file=' . $filepath,
        ];
        if ($db_password !== '') $args[] = '-p' . $db_password; // no space — mysqldump requirement
        $args[] = $db_name;
        $cmd = implode(' ', array_map('escapeshellarg', array_slice($args, 1)));
        $cmd = escapeshellcmd($mysqldump) . ' ' . $cmd . ' 2>&1';
        exec($cmd, $output, $return_code);
        if ($return_code === 0 && file_exists($filepath) && filesize($filepath) > 100) {
            return ['ok' => true, 'file' => $filename, 'method' => 'mysqldump', 'size' => filesize($filepath)];
        }
        // Log mysqldump error for debug
        $mysqldump_err = implode("\n", $output);
    }

    // PHP fallback
    $mysqli = @new mysqli($db_server, $db_username, $db_password, $db_name);
    if ($mysqli->connect_error) {
        return ['ok' => false, 'msg' => 'Connection failed: ' . $mysqli->connect_error];
    }
    $mysqli->query("SET NAMES utf8mb4");

    $fh = @fopen($filepath, 'w');
    if (!$fh) {
        $err = error_get_last();
        $detail = $err['message'] ?? 'unknown error';
        $mysqldump_info = isset($mysqldump_err) && $mysqldump_err !== '' ? ' mysqldump output: ' . $mysqldump_err : '';
        return ['ok' => false, 'msg' => "Could not create backup file at: $filepath — $detail.$mysqldump_info"];
    }

    fwrite($fh, "-- BigDump WordPress Backup\n");
    fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fh, "-- Database: $db_name\n\n");
    fwrite($fh, "SET NAMES utf8mb4;\n");
    fwrite($fh, "SET foreign_key_checks = 0;\n\n");

    $tables_res = $mysqli->query("SHOW TABLES");
    while ($row = $tables_res->fetch_array()) {
        $table = $row[0];

        // CREATE TABLE
        $create_res = $mysqli->query("SHOW CREATE TABLE `$table`");
        $create_row = $create_res->fetch_assoc();
        fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($fh, $create_row['Create Table'] . ";\n\n");
        $create_res->free();

        // DATA
        $data_res = $mysqli->query("SELECT * FROM `$table`");
        if (!$data_res) continue;
        $num_fields = $data_res->field_count;
        while ($data_row = $data_res->fetch_row()) {
            $vals = [];
            for ($i = 0; $i < $num_fields; $i++) {
                if ($data_row[$i] === null) {
                    $vals[] = 'NULL';
                } else {
                    $vals[] = "'" . $mysqli->real_escape_string($data_row[$i]) . "'";
                }
            }
            fwrite($fh, "INSERT INTO `$table` VALUES (" . implode(',', $vals) . ");\n");
        }
        $data_res->free();
        fwrite($fh, "\n");
    }

    fwrite($fh, "SET foreign_key_checks = 1;\n");
    fclose($fh);
    $mysqli->close();

    return ['ok' => true, 'file' => $filename, 'method' => 'php-fallback', 'size' => filesize($filepath)];
}

// ============================================================
// FTP / SFTP upload
// ============================================================

// $rename_map: ['local_basename' => 'remote_name'] — used to rename bigdump.php on upload
function ftp_upload_files(array $ftp_cfg, array $local_files, array $rename_map = []): array {
    $host     = $ftp_cfg['host']     ?? '';
    $user     = $ftp_cfg['user']     ?? '';
    $pass     = $ftp_cfg['pass']     ?? '';
    $port     = (int)($ftp_cfg['port'] ?? 21);
    $path     = rtrim($ftp_cfg['path'] ?? '/', '/') . '/';
    $protocol = $ftp_cfg['protocol'] ?? 'ftp';

    $results = [];

    if ($protocol === 'sftp') {
        if (!function_exists('ssh2_connect')) {
            return [['ok' => false, 'msg' => 'PHP extension ssh2 is not installed. Install php-ssh2 or use FTP.']];
        }
        $conn = @ssh2_connect($host, $port ?: 22);
        if (!$conn) return [['ok' => false, 'msg' => "Could not connect to $host:$port via SFTP."]];
        if (!@ssh2_auth_password($conn, $user, $pass)) {
            return [['ok' => false, 'msg' => 'SFTP authentication failed. Check username and password.']];
        }
        $sftp = ssh2_sftp($conn);
        foreach ($local_files as $lf) {
            if (!file_exists($lf)) { $results[] = ['ok' => false, 'file' => basename($lf), 'msg' => 'Local file not found.']; continue; }
            $rname  = $rename_map[basename($lf)] ?? basename($lf);
            $remote = "ssh2.sftp://" . intval($sftp) . $path . $rname;
            $ok = copy($lf, $remote);
            $results[] = ['ok' => $ok, 'file' => $rname, 'msg' => $ok ? 'Uploaded successfully.' : 'Error copying via SFTP.'];
        }
        return $results;
    }

    // FTP / FTPS
    if (!function_exists('ftp_connect')) {
        return [['ok' => false, 'msg' => 'PHP FTP extension is not available.']];
    }

    $conn = $protocol === 'ftps' ? @ftp_ssl_connect($host, $port ?: 21, 15) : @ftp_connect($host, $port ?: 21, 15);
    if (!$conn) return [['ok' => false, 'msg' => "Could not connect to $host:$port via " . strtoupper($protocol) . "."]];
    if (!@ftp_login($conn, $user, $pass)) {
        ftp_close($conn);
        return [['ok' => false, 'msg' => 'FTP login failed. Check username and password.']];
    }
    ftp_pasv($conn, true);

    foreach ($local_files as $lf) {
        if (!file_exists($lf)) { $results[] = ['ok' => false, 'file' => basename($lf), 'msg' => 'Local file not found.']; continue; }
        $rname  = $rename_map[basename($lf)] ?? basename($lf);
        $remote = $path . $rname;
        $ok = @ftp_put($conn, $remote, $lf, FTP_BINARY);
        $results[] = ['ok' => $ok, 'file' => $rname, 'msg' => $ok ? 'Uploaded successfully.' : 'FTP error uploading: ' . $rname];
    }
    ftp_close($conn);
    return $results;
}

// ============================================================
// FIND SQL FILES
// ============================================================

function find_dump_files(string $dir): array {
    $sql = []; $gz = []; $zip = []; $backup = [];
    // Scan main dir for importable dumps
    if ($dh = opendir($dir)) {
        while (($f = readdir($dh)) !== false) {
            if (!is_file($dir . '/' . $f)) continue;
            if      (preg_match('/\.sql$/i', $f))            $sql[] = $f;
            elseif  (preg_match('/\.(sql\.gz|gz)$/i', $f))  $gz[]  = $f;
            elseif  (preg_match('/\.zip$/i', $f))            $zip[] = $f;
        }
        closedir($dh);
    }
    // Scan BACKUP_DIR for all SQL/GZ files (any name)
    $backup_dir = BACKUP_DIR;
    if (is_dir($backup_dir) && ($dh = opendir($backup_dir))) {
        while (($f = readdir($dh)) !== false) {
            if (is_file($backup_dir . '/' . $f) && preg_match('/\.(sql|gz)$/i', $f))
                $backup[] = $f;
        }
        closedir($dh);
    }
    rsort($backup); // most recent first
    return ['sql' => $sql, 'gz' => $gz, 'zip' => $zip, 'backup' => $backup];
}

// ============================================================
// wp-config.php auto-load
// ============================================================

function find_wp_config(string $start_dir): ?string {
    $dir = $start_dir;
    for ($i = 0; $i < 5; $i++) {
        $f = rtrim($dir, '/') . '/wp-config.php';
        if (file_exists($f)) return $f;
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return null;
}

function parse_wp_config(string $path): array {
    $c = file_get_contents($path);
    $v = [];
    foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET'] as $k) {
        $pattern = '/define\s*\(\s*[\'"]' . $k . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'\"]/';
        $v[$k] = preg_match($pattern, $c, $m) ? $m[1] : '';
    }
    $v['table_prefix'] = preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $c, $m) ? $m[1] : 'wp_';
    return $v;
}

// ============================================================
// wp-config.php variable setup
// ============================================================

$cfg = cfg_load();

$wp_config_path   = find_wp_config(BIGDUMP_DIR);
$wp_config_loaded = $wp_config_path !== null;
$wp_config        = $wp_config_loaded ? parse_wp_config($wp_config_path) : [];

$db_server             = $wp_config['DB_HOST']     ?? 'localhost';
$db_name               = $wp_config['DB_NAME']      ?? '';
$db_username           = $wp_config['DB_USER']      ?? '';
$db_password           = $wp_config['DB_PASSWORD']  ?? '';
$db_connection_charset = $wp_config['DB_CHARSET']   ?? 'utf8mb4';
$wp_table_prefix       = $wp_config['table_prefix'] ?? 'wp_';

// ============================================================
// Import engine settings
// ============================================================

$filename        = '';
$ajax            = true;
// Lines processed per import session. Lower = safer on slow servers.
// Modern WP dumps with extended inserts can have single queries > 1000 lines.
$linespersession = 3000;
$delaypersession = 0;

$csv_insert_table   = '';
$csv_preempty_table = false;
$csv_delimiter      = ',';
$csv_add_quotes     = true;
$csv_add_slashes    = true;

$comment         = ['#', '-- ', 'DELIMITER', '/*!', 'CREATE DATABASE', 'USE `'];
$delimiter       = ';';
$string_quotes   = '\'';
// Max lines allowed in a single query. phpMyAdmin exports with extended inserts
// can produce INSERT blocks with thousands of lines. Setting this too low causes
// "Stopped at line N — query exceeds X lines" errors.
// 50000 covers virtually all real-world dumps including large WP sites.
$max_query_lines = 50000;
$upload_dir      = BIGDUMP_DIR;

// ============================================================
// EARLY AJAX ROUTER — runs before ANY output / ob_start
// ============================================================

$_early_action = $_POST['action'] ?? $_GET['action'] ?? '';

// ---- Backup download handler (GET ?download=filename) ----
if (isset($_GET['download'])) {
    $dl_file = basename($_GET['download']);
    $dl_path = BACKUP_DIR . '/' . $dl_file;
    // Security: only allow backup files from BACKUP_DIR
    if (preg_match('/^backup_.*\.sql$/i', $dl_file) && file_exists($dl_path)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $dl_file . '"');
        header('Content-Length: ' . filesize($dl_path));
        header('Cache-Control: no-cache');
        readfile($dl_path);
    } else {
        http_response_code(404);
        echo 'File not found.';
    }
    exit;
}

$_early_actions = [
    'wp_list_users', 'wp_change_pass', 'wp_change_email', 'wp_delete_user',
    'wp_add_user', 'wp_list_plugins', 'wp_toggle_plugin', 'wp_self_delete',
    'validate_url', 'url_preview_ajax', 'ftp_zip_upload',
];

if (in_array($_early_action, $_early_actions, true)) {

    header('Content-Type: application/json; charset=utf-8');
    header("Cache-Control: no-store, no-cache");

    if ($_early_action === 'validate_url') {
        $url = trim($_POST['url'] ?? '');
        echo json_encode(validate_wp_url($url));
        exit;
    }

    if ($_early_action === 'url_preview_ajax') {
        $url_old  = trim($_POST['url_old']  ?? '');
        $sql_file = basename($_POST['sql_file'] ?? '');
        $sql_path = BIGDUMP_DIR . '/' . $sql_file;
        if (!file_exists($sql_path)) { echo json_encode(['error' => 'File not found.']); exit; }
        $count = 0; $samples = [];
        $fh = fopen($sql_path, 'r');
        if ($fh) {
            while (($line = fgets($fh, DATA_CHUNK_LENGTH)) !== false) {
                if (strpos($line, $url_old) !== false) {
                    $count++;
                    if (count($samples) < 5) $samples[] = substr(trim($line), 0, 200);
                }
            }
            fclose($fh);
        }
        echo json_encode(['count' => $count, 'samples' => $samples]);
        exit;
    }

    if ($_early_action === 'wp_self_delete') {
        $deleted = [];
        $errors  = [];
        if (file_exists(__FILE__)) {
            if (@unlink(__FILE__)) $deleted[] = basename(__FILE__);
            else $errors[] = 'Could not delete ' . basename(__FILE__);
        }
        if (file_exists(CONFIG_FILE)) {
            if (@unlink(CONFIG_FILE)) $deleted[] = basename(CONFIG_FILE);
            else $errors[] = 'Could not delete ' . basename(CONFIG_FILE);
        }
        if (empty($errors)) {
            echo json_encode(['ok' => true, 'msg' => 'Script deleted: ' . implode(', ', $deleted)]);
        } else {
            echo json_encode(['ok' => false, 'msg' => implode('; ', $errors)]);
        }
        exit;
    }

    // FTP zip-and-upload whole working directory
    if ($_early_action === 'ftp_zip_upload') {
        if (!extension_loaded('zip')) {
            echo json_encode(['ok'=>false,'msg'=>'PHP zip extension not available.']); exit;
        }
        $ftp_cfg = [
            'host'     => trim($_POST['ftp_host']     ?? ''),
            'user'     => trim($_POST['ftp_user']     ?? ''),
            'pass'     => trim($_POST['ftp_pass']     ?? ''),
            'port'     => (int)($_POST['ftp_port']    ?? 21),
            'path'     => trim($_POST['ftp_path']     ?? '/'),
            'protocol' => trim($_POST['ftp_protocol'] ?? 'ftp'),
        ];
        if (!$ftp_cfg['host'] || !$ftp_cfg['user']) {
            echo json_encode(['ok'=>false,'msg'=>'FTP host and username are required.']); exit;
        }
        // Build zip
        $ts      = date('Ymd_His');
        $zipname = 'site_backup_' . $ts . '.zip';
        $zippath = sys_get_temp_dir() . '/' . $zipname;
        $zip = new ZipArchive();
        if ($zip->open($zippath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            echo json_encode(['ok'=>false,'msg'=>'Could not create ZIP file at: '.$zippath]); exit;
        }
        $dir = BIGDUMP_DIR;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $count = 0;
        foreach ($iter as $file) {
            if ($file->isFile()) {
                $rel = substr($file->getPathname(), strlen($dir) + 1);
                $zip->addFile($file->getPathname(), $rel);
                $count++;
            }
        }
        $zip->close();
        $size = filesize($zippath);
        // Upload
        $results = ftp_upload_files($ftp_cfg, [$zippath]);
        @unlink($zippath); // clean temp
        $r = $results[0] ?? ['ok'=>false,'msg'=>'No result from FTP upload'];
        echo json_encode([
            'ok'    => $r['ok'],
            'msg'   => $r['ok'] ? "ZIP ($count files, ".fmt_bytes($size).") uploaded as $zipname" : $r['msg'],
            'file'  => $zipname,
            'count' => $count,
            'size'  => $size,
        ]);
        exit;
    }

    // WordPress user/plugin actions — need DB connection
    $wm_early = wp_db($db_server, $db_username, $db_password, $db_name, $db_connection_charset);
    if (!$wm_early) {
        echo json_encode(['ok' => false, 'msg' => 'Could not connect to the database.']);
        exit;
    }

    $tbl_early = wp_tables($wp_table_prefix);

    if ($_early_action === 'wp_list_users') {
        $res  = $wm_early->query("SELECT ID, user_login, user_email, display_name, user_registered FROM `{$tbl_early['users']}` ORDER BY ID ASC");
        $rows = [];
        if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; }
        echo json_encode($rows);
        $wm_early->close();
        exit;
    }

    if ($_early_action === 'wp_change_pass') {
        $uid  = (int)($_POST['uid']      ?? 0);
        $pass =       $_POST['new_pass'] ?? '';
        if ($uid < 1 || strlen($pass) < 6) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid ID or password too short (minimum 6 characters).']);
            exit;
        }
        $hash = wp_portable_hash($pass);
        $stmt = $wm_early->prepare("UPDATE `{$tbl_early['users']}` SET user_pass=? WHERE ID=?");
        $stmt->bind_param('si', $hash, $uid);
        $ok = $stmt->execute();
        $stmt->close();
        $wm_early->query("DELETE FROM `{$tbl_early['usermeta']}` WHERE user_id=$uid AND meta_key='session_tokens'");
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Password updated successfully.' : $wm_early->error]);
        $wm_early->close();
        exit;
    }

    if ($_early_action === 'wp_change_email') {
        $uid   = (int)($_POST['uid']       ?? 0);
        $email = filter_var(trim($_POST['new_email'] ?? ''), FILTER_VALIDATE_EMAIL);
        if ($uid < 1 || !$email) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid ID or email address.']);
            exit;
        }
        $stmt = $wm_early->prepare("UPDATE `{$tbl_early['users']}` SET user_email=? WHERE ID=?");
        $stmt->bind_param('si', $email, $uid);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Email updated successfully.' : $wm_early->error]);
        $wm_early->close();
        exit;
    }

    if ($_early_action === 'wp_delete_user') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid < 1) { echo json_encode(['ok' => false, 'msg' => 'Invalid ID.']); exit; }
        $wm_early->query("DELETE FROM `{$tbl_early['usermeta']}` WHERE user_id=$uid");
        $ok = $wm_early->query("DELETE FROM `{$tbl_early['users']}` WHERE ID=$uid");
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'User deleted.' : $wm_early->error]);
        $wm_early->close();
        exit;
    }

    if ($_early_action === 'wp_add_user') {
        $login = trim($_POST['new_login'] ?? '');
        $email = filter_var(trim($_POST['new_email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $pass  = $_POST['new_pass'] ?? '';
        $role  = in_array($_POST['new_role'] ?? '', ['administrator', 'editor', 'author', 'contributor', 'subscriber'], true)
                 ? $_POST['new_role'] : 'subscriber';
        $name  = trim($_POST['new_name'] ?? '') ?: $login;

        if ($login === '' || !$email || strlen($pass) < 6) {
            echo json_encode(['ok' => false, 'msg' => 'Login, valid email, and password (minimum 6 characters) are required.']);
            exit;
        }
        $chk = $wm_early->prepare("SELECT ID FROM `{$tbl_early['users']}` WHERE user_login=? OR user_email=?");
        $chk->bind_param('ss', $login, $email);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            echo json_encode(['ok' => false, 'msg' => 'A user with that login or email already exists.']);
            $chk->close(); $wm_early->close(); exit;
        }
        $chk->close();

        $hash = wp_portable_hash($pass);
        $now  = date('Y-m-d H:i:s');
        $stmt = $wm_early->prepare(
            "INSERT INTO `{$tbl_early['users']}` (user_login,user_pass,user_email,display_name,user_registered,user_status) VALUES (?,?,?,?,?,0)"
        );
        $stmt->bind_param('sssss', $login, $hash, $email, $name, $now);
        $ok     = $stmt->execute();
        $new_id = (int)$wm_early->insert_id;
        $stmt->close();

        if ($ok && $new_id > 0) {
            $cap_key = $wp_table_prefix . 'capabilities';
            $cap_val = serialize([$role => true]);
            $lvl_key = $wp_table_prefix . 'user_level';
            $lvl_map = ['administrator' => 10, 'editor' => 7, 'author' => 2, 'contributor' => 1, 'subscriber' => 0];
            $lvl_val = (string)($lvl_map[$role] ?? 0);
            $s2 = $wm_early->prepare("INSERT INTO `{$tbl_early['usermeta']}` (user_id,meta_key,meta_value) VALUES (?,?,?),(?,?,?)");
            $s2->bind_param('ississ', $new_id, $cap_key, $cap_val, $new_id, $lvl_key, $lvl_val);
            $s2->execute();
            $s2->close();
            echo json_encode(['ok' => true, 'msg' => "User '$login' created with ID $new_id and role '$role'.", 'id' => $new_id]);
        } else {
            echo json_encode(['ok' => false, 'msg' => $wm_early->error ?: 'Error inserting user.']);
        }
        $wm_early->close();
        exit;
    }

    if ($_early_action === 'wp_list_plugins') {
        $res = $wm_early->query(
            "SELECT option_value FROM `{$tbl_early['options']}` WHERE option_name='active_plugins' LIMIT 1"
        );
        $active = [];
        if ($res) {
            $row = $res->fetch_assoc();
            if ($row) {
                $unserialized = @unserialize($row['option_value']);
                if (is_array($unserialized)) $active = $unserialized;
            }
        }
        echo json_encode(['ok' => true, 'active' => $active]);
        $wm_early->close();
        exit;
    }

    if ($_early_action === 'wp_toggle_plugin') {
        $plugin = trim($_POST['plugin'] ?? '');
        $make_active = (int)($_POST['active'] ?? 0);

        if ($plugin === '') {
            echo json_encode(['ok' => false, 'msg' => 'No plugin slug provided.']);
            $wm_early->close(); exit;
        }

        $res = $wm_early->query(
            "SELECT option_value FROM `{$tbl_early['options']}` WHERE option_name='active_plugins' LIMIT 1"
        );
        $active = [];
        if ($res) {
            $row = $res->fetch_assoc();
            if ($row) {
                $unserialized = @unserialize($row['option_value']);
                if (is_array($unserialized)) $active = $unserialized;
            }
        }

        if ($make_active) {
            if (!in_array($plugin, $active, true)) $active[] = $plugin;
            $msg = "Plugin '$plugin' activated.";
        } else {
            $active = array_values(array_filter($active, fn($p) => $p !== $plugin));
            $msg = "Plugin '$plugin' deactivated.";
        }

        $serialized = serialize(array_values($active));
        $stmt = $wm_early->prepare(
            "UPDATE `{$tbl_early['options']}` SET option_value=? WHERE option_name='active_plugins'"
        );
        $stmt->bind_param('s', $serialized);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => $ok, 'msg' => $ok ? $msg : $wm_early->error]);
        $wm_early->close();
        exit;
    }
}

// ============================================================
// HTTP + buffering setup
// ============================================================

if ($ajax) ob_start();

header("Expires: Mon, 1 Dec 2003 01:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (function_exists('date_default_timezone_set') && function_exists('date_default_timezone_get'))
    @date_default_timezone_set(@date_default_timezone_get());

// Sanitize GET/POST — keep URLs in POST separate (don't strip URL chars from them)
$safe_request = [];
foreach ($_REQUEST as $key => $val) {
    $safe_request[$key] = preg_replace("/[^_A-Za-z0-9\-\.&= ;\$\/\?#@:]/i", '', (string)$val);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<title>BigDump WP v<?php echo VERSION; ?></title>
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="Cache-Control" content="no-cache"/>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#f0f2f5;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222}
#wrap{max-width:900px;margin:20px auto;padding:0 12px}
h2{font-size:15px;margin:14px 0 8px;color:#2d3748}
p{margin:5px 0;line-height:1.55}
code{background:#edf2f7;padding:1px 5px;border-radius:3px;font-size:13px}
a{color:#2b6cb0}

/* Cards */
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px;margin:14px 0;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.card-header{background:#4a5568;color:#fff;border-radius:8px 8px 0 0;padding:11px 18px;margin:-18px -18px 16px;font-weight:bold;font-size:15px;display:flex;align-items:center;gap:8px}
.card-blue   .card-header{background:#2b6cb0}
.card-green  .card-header{background:#276749}
.card-orange .card-header{background:#c05621}
.card-red    .card-header{background:#c53030}
.card-purple .card-header{background:#553c9a}
.card-teal   .card-header{background:#285e61}

/* Security warning card */
.card-security{background:#fff5f5;border:2px solid #fc8181;border-radius:8px;padding:18px;margin:14px 0;box-shadow:0 2px 8px rgba(197,48,48,.15)}
.card-security .card-header{background:#c53030;font-size:16px}
.card-security ul{margin:8px 0 8px 20px;line-height:1.8}
.card-security .delete-btn{margin-top:14px;display:inline-block;padding:12px 28px;background:#c53030;color:#fff;border:none;border-radius:6px;font-size:16px;font-weight:bold;cursor:pointer;transition:background .15s}
.card-security .delete-btn:hover{background:#9b2c2c}

/* Badges */
.badge{display:inline-block;padding:2px 9px;border-radius:12px;font-size:12px;font-weight:bold;line-height:1.6}
.badge-ok    {background:#c6f6d5;color:#276749}
.badge-warn  {background:#fefcbf;color:#744210}
.badge-error {background:#fed7d7;color:#c53030}
.badge-info  {background:#bee3f8;color:#2a69ac}

/* Messages */
.msg-error  {color:#c53030;font-weight:bold}
.msg-success{color:#276749;font-weight:bold}
.msg-warn   {color:#744210;font-weight:bold}
.msg-info   {color:#2b6cb0}
.center     {text-align:center}

/* Tables */
table.data{width:100%;border-collapse:collapse;margin:8px 0}
table.data th{background:#4a5568;color:#fff;padding:7px 10px;text-align:left;font-weight:600}
table.data td{padding:6px 10px;border-bottom:1px solid #edf2f7;vertical-align:top}
table.data tr:hover td{background:#ebf8ff}
table.data tr:nth-child(even) td{background:#f7fafc}
table.data tr:hover td{background:#ebf8ff}

/* Progress */
.pbar-wrap{background:#e2e8f0;border-radius:4px;height:16px;overflow:hidden;margin:4px 0}
.pbar-fill{background:#3182ce;height:16px;transition:width .4s}

/* Forms */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.form-row{margin:10px 0}
label{display:block;font-weight:600;margin-bottom:4px;color:#4a5568;font-size:13px}
input[type=text],input[type=url],input[type=password],input[type=number],select{
  width:100%;padding:7px 9px;border:1px solid #cbd5e0;border-radius:5px;font-size:13px;
  transition:border-color .2s}
input:focus,select:focus{outline:none;border-color:#3182ce;box-shadow:0 0 0 3px rgba(49,130,206,.15)}
.btn{display:inline-block;padding:8px 20px;border-radius:5px;border:none;cursor:pointer;font-size:14px;font-weight:600;transition:background .15s}
.btn-primary{background:#3182ce;color:#fff}.btn-primary:hover{background:#2c5282}
.btn-success{background:#276749;color:#fff}.btn-success:hover{background:#1d4f34}
.btn-danger {background:#c53030;color:#fff}.btn-danger:hover{background:#9b2c2c}
.btn-warn   {background:#c05621;color:#fff}.btn-warn:hover{background:#9c4221}
.btn-purple {background:#553c9a;color:#fff}.btn-purple:hover{background:#44337a}
.btn-sm{padding:4px 12px;font-size:12px}

/* Collapsible sections */
details{border:1px solid #e2e8f0;border-radius:8px;margin:12px 0;background:#fff}
details summary{padding:12px 16px;cursor:pointer;font-weight:bold;font-size:15px;color:#2d3748;
  list-style:none;display:flex;align-items:center;gap:8px;user-select:none}
details summary::-webkit-details-marker{display:none}
details summary::before{content:'▶';font-size:11px;transition:transform .2s;display:inline-block}
details[open] summary::before{transform:rotate(90deg)}
details .details-body{padding:0 16px 16px}

/* Validation result box */
.val-box{padding:10px 14px;border-radius:6px;margin:8px 0;font-size:13px}
.val-ok   {background:#f0fff4;border:1px solid #9ae6b4;color:#276749}
.val-error{background:#fff5f5;border:1px solid #fc8181;color:#c53030}
.val-warn {background:#fffbeb;border:1px solid #fbd38d;color:#744210}
</style>
</head>
<body>
<div id="wrap">

<!-- SECURITY WARNING BANNER -->
<div class="card-security">
  <div class="card-header">⚠ SECURITY WARNING — This script is dangerous</div>
  <p><strong>This tool should NEVER be left on a production server.</strong> It poses serious security risks:</p>
  <ul>
    <li>Exposes your database credentials to anyone who can access this URL</li>
    <li>Allows direct database manipulation with no authentication</li>
    <li>Can be used by attackers to wipe your database entirely</li>
    <li>Allows changing admin passwords and hijacking accounts</li>
    <li>Can be used to exfiltrate all your site data</li>
  </ul>
  <p style="margin-top:10px"><strong>Remove this script from production servers immediately after use.</strong></p>
  <button class="delete-btn" onclick="wpSelfDelete()">🗑 Delete this script now</button>
  <div id="self-delete-result" style="margin-top:10px"></div>
</div>

<div class="card card-blue">
  <div class="card-header">🗄 BigDump WordPress Importer v<?php echo VERSION; ?></div>
  <p>Staggered MySQL dump importer for WordPress &mdash; reads <code>wp-config.php</code> automatically, replaces URLs, creates backups, and deploys via FTP/SFTP.</p>
</div>

<?php

// ============================================================
// ACTION ROUTER
// ============================================================

$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$error   = false;
$file    = false;
$mysqli  = false;
$gzipmode = false;
$curfilename = '';
$filesize    = 0;

// ============================================================
// ACTION: save config
// ============================================================
if ($action === 'save_config') {
    $new_cfg = $cfg;
    // Migration profiles
    $new_cfg['profile_name']   = $_POST['profile_name'] ?? '';
    $new_cfg['url_local']      = $_POST['url_local']    ?? '';
    $new_cfg['url_remote']     = $_POST['url_remote']   ?? '';
    // FTP
    $new_cfg['ftp_host']       = $_POST['ftp_host']     ?? '';
    $new_cfg['ftp_user']       = $_POST['ftp_user']     ?? '';
    $new_cfg['ftp_pass']       = $_POST['ftp_pass']     ?? '';
    $new_cfg['ftp_port']       = $_POST['ftp_port']     ?? '21';
    $new_cfg['ftp_path']       = $_POST['ftp_path']     ?? '/';
    $new_cfg['ftp_protocol']   = $_POST['ftp_protocol'] ?? 'ftp';
    // Import prefs
    $new_cfg['linespersession']  = (int)($_POST['linespersession']  ?? 3000);
    $new_cfg['delaypersession']  = (int)($_POST['delaypersession']  ?? 0);
    $new_cfg['max_query_lines']  = (int)($_POST['max_query_lines']  ?? 50000);
    // Saved timestamp
    $new_cfg['saved_at']       = date('Y-m-d H:i:s');
    cfg_save($new_cfg);
    $cfg = $new_cfg;
    echo '<div class="card card-green"><div class="card-header">✓ Configuration saved</div>';
    echo '<p>Settings saved to <code>' . h(CONFIG_FILE) . '</code>.</p></div>';
}

// Apply config to import settings
$linespersession = (int)($cfg['linespersession'] ?? 3000);
$delaypersession = (int)($cfg['delaypersession'] ?? 0);
// Override $max_query_lines with saved config if present
if (isset($cfg['max_query_lines']) && $cfg['max_query_lines'] > 0)
    $max_query_lines = (int)$cfg['max_query_lines'];

// ============================================================
// ACTION: run backup
// ============================================================
if ($action === 'run_backup') {
    $result = run_backup($db_server, $db_username, $db_password, $db_name, $upload_dir);
    echo '<div class="card ' . ($result['ok'] ? 'card-green' : 'card-red') . '">';
    echo '<div class="card-header">' . ($result['ok'] ? '✓ Backup completed' : '✗ Backup error') . '</div>';
    if ($result['ok']) {
        echo '<p>File: <code>' . h($result['file']) . '</code></p>';
        echo '<p>Method: <strong>' . h($result['method']) . '</strong> &mdash; Size: <strong>' . fmt_bytes($result['size']) . '</strong></p>';
    } else {
        echo '<p class="msg-error">' . h($result['msg'] ?? 'Unknown error') . '</p>';
    }
    echo '</div>';
}

// ============================================================
// ACTION: FTP/SFTP upload
// ============================================================
if ($action === 'ftp_upload') {
    $ftp_cfg = [
        'host'     => trim($_POST['ftp_host']     ?? $cfg['ftp_host']     ?? ''),
        'user'     => trim($_POST['ftp_user']     ?? $cfg['ftp_user']     ?? ''),
        'pass'     => trim($_POST['ftp_pass']     ?? $cfg['ftp_pass']     ?? ''),
        'port'     => (int)($_POST['ftp_port']    ?? $cfg['ftp_port']     ?? 21),
        'path'     => trim($_POST['ftp_path']     ?? $cfg['ftp_path']     ?? '/'),
        'protocol' => trim($_POST['ftp_protocol'] ?? $cfg['ftp_protocol'] ?? 'ftp'),
    ];

    $files_to_send = [];
    // Always include bigdump.php
    $files_to_send[] = __FILE__;

    // Optional: SQL files selected
    if (!empty($_POST['ftp_files'])) {
        foreach ((array)$_POST['ftp_files'] as $fname) {
            $fname = basename((string)$fname); // security: no path traversal
            $fpath = $upload_dir . '/' . $fname;
            if (file_exists($fpath) && preg_match('/\.(sql|gz|zip)$/i', $fname)) {
                $files_to_send[] = $fpath;
            }
        }
    }

    // Rename bigdump.php to bigdump_<random>.php on the remote server for security
    $remote_script_name = 'bigdump_' . rand(100000, 999999) . '.php';
    $rename_map = [basename(__FILE__) => $remote_script_name];

    $results = ftp_upload_files($ftp_cfg, $files_to_send, $rename_map);

    echo '<div class="card"><div class="card-header">FTP/SFTP Deploy Result</div>';
    echo '<table class="data"><tr><th>File</th><th>Status</th><th>Detail</th></tr>';
    foreach ($results as $r) {
        $badge = $r['ok'] ? '<span class="badge badge-ok">OK</span>' : '<span class="badge badge-error">Error</span>';
        echo '<tr><td><code>' . h($r['file'] ?? '—') . '</code></td><td>' . $badge . '</td><td>' . h($r['msg'] ?? '') . '</td></tr>';
    }
    echo '</table>';
    echo '<p class="msg-info" style="margin:10px 0 4px">Script deployed as: <strong>' . h($remote_script_name) . '</strong> — use this name in the URL on the remote server.</p>';
    echo '</div>';
}

// ============================================================
// ACTION: apply URL replacement in SQL file
// ============================================================
if ($action === 'apply_url_replace') {
    $url_old  = trim($_POST['url_old'] ?? '');
    $url_new  = trim($_POST['url_new'] ?? '');
    $sql_file = basename($_POST['sql_file'] ?? '');
    $sql_path = $upload_dir . '/' . $sql_file;

    if ($url_old === '' || $url_new === '' || !file_exists($sql_path)) {
        echo '<p class="msg-error">Missing data for replacement.</p>';
    } else {
        $content  = file_get_contents($sql_path);
        $replaced = str_replace($url_old, $url_new, $content, $count);
        file_put_contents($sql_path, $replaced);
        unset($content, $replaced);
        echo '<div class="card card-green"><div class="card-header">✓ Replacement applied</div>';
        echo '<p><strong>' . number_format($count) . '</strong> replacements of <code>' . h($url_old) . '</code> → <code>' . h($url_new) . '</code> in <code>' . h($sql_file) . '</code>.</p>';
        echo '</div>';
    }
}

// ============================================================
// SECTION 1 — PHP params check
// ============================================================
$upload_max = ini_bytes('upload_max_filesize');
$post_max   = ini_bytes('post_max_size');
$mem_limit  = ini_bytes('memory_limit');
$time_limit = (int) ini_get('max_execution_time');

echo '<details' . ($action === '' ? ' open' : '') . '>';
echo '<summary>⚙ PHP Server Parameters</summary>';
echo '<div class="details-body">';
// Tooltip content for each adjustable ini param
$ini_howto = <<<'TIP'
<div class="ini-tooltip">
  <strong>How to increase these limits:</strong><br><br>
  <b>Option 1 — php.ini</b> (preferred, applies globally):<br>
  <code>upload_max_filesize = 256M<br>post_max_size = 256M<br>memory_limit = 256M<br>max_execution_time = 300</code><br>
  Find your php.ini: run <code>php --ini</code> or check <code>phpinfo()</code>.<br><br>
  <b>Option 2 — .htaccess</b> (Apache only, in your site root):<br>
  <code>php_value upload_max_filesize 256M<br>php_value post_max_size 256M<br>php_value memory_limit 256M<br>php_value max_execution_time 300</code><br><br>
  <b>Option 3 — .user.ini</b> (most shared hosts / Nginx / PHP-FPM):<br>
  Place this file in your web root:<br>
  <code>upload_max_filesize=256M<br>post_max_size=256M<br>memory_limit=256M<br>max_execution_time=300</code><br>
  PHP-FPM reads it every 5 min by default (<code>user_ini.cache_ttl</code>).<br><br>
  <b>Option 4 — ini_set() in script</b> (runtime only, some hosts block it):<br>
  <code>ini_set('memory_limit','256M');</code><br><br>
  <em>After editing, restart Apache/Nginx or PHP-FPM for changes to take effect.</em>
</div>
TIP;

echo '<style>
.ini-help{display:inline-block;margin-left:6px;cursor:help;color:#3182ce;font-weight:bold;font-size:13px;
  position:relative;vertical-align:middle}
.ini-help .ini-tooltip{display:none;position:absolute;left:26px;top:-8px;z-index:999;background:#2d3748;
  color:#e2e8f0;padding:14px 16px;border-radius:8px;width:380px;font-size:12px;line-height:1.6;
  box-shadow:0 4px 20px rgba(0,0,0,.4);white-space:normal}
.ini-help .ini-tooltip code{background:#4a5568;color:#f6e05e;padding:1px 4px;border-radius:3px;
  display:block;margin:2px 0;font-family:monospace}
.ini-help:hover .ini-tooltip{display:block}
</style>';

$help_icon = '<span class="ini-help">ⓘ' . $ini_howto . '</span>';

echo '<table class="data"><tr><th>Parameter</th><th>Value</th><th>Status</th></tr>';
$php_params = [
    ['upload_max_filesize', fmt_bytes($upload_max), $upload_max >= 64*1048576, true],
    ['post_max_size',       fmt_bytes($post_max),   $post_max   >= 64*1048576, true],
    ['memory_limit',        fmt_bytes($mem_limit),  $mem_limit  >= 128*1048576 || $mem_limit <= 0, true],
    ['max_execution_time',  $time_limit === 0 ? '∞' : $time_limit.'s', $time_limit === 0 || $time_limit >= 120, true],
    ['curl',                function_exists('curl_init') ? 'enabled' : 'not available', function_exists('curl_init'), false],
    ['zip',                 extension_loaded('zip')      ? 'enabled' : 'not available', extension_loaded('zip'), false],
    ['ftp',                 function_exists('ftp_connect') ? 'enabled' : 'not available', function_exists('ftp_connect'), false],
    ['ssh2/sftp',           function_exists('ssh2_connect') ? 'enabled' : 'not available', function_exists('ssh2_connect'), false],
];
foreach ($php_params as [$name, $val, $ok, $adjustable]) {
    $badge = $ok ? '<span class="badge badge-ok">OK</span>' : '<span class="badge badge-warn">Review</span>';
    $hint  = (!$ok && $adjustable) ? $help_icon : '';
    echo "<tr><td><code>$name</code>$hint</td><td>$val</td><td>$badge</td></tr>";
}
echo '</table></div></details>';

// ============================================================
// SECTION 2 — DB connection
// ============================================================
echo '<details open>';
echo '<summary>🗄 Database Connection</summary>';
echo '<div class="details-body">';

if ($wp_config_loaded) {
    echo '<p><span class="badge badge-ok">wp-config.php</span> &nbsp;<code>' . h($wp_config_path) . '</code></p>';
} else {
    echo '<p><span class="badge badge-warn">wp-config.php not found</span> — using default values.</p>';
}

echo '<table class="data" style="margin-top:10px">';
echo '<tr><th>Parameter</th><th>Value</th></tr>';
echo '<tr><td>Server</td><td><code>'.h($db_server).'</code></td></tr>';
echo '<tr><td>Database</td><td><code>'.h($db_name).'</code></td></tr>';
echo '<tr><td>User</td><td><code>'.h($db_username).'</code></td></tr>';
echo '<tr><td>Charset</td><td><code>'.h($db_connection_charset).'</code></td></tr>';
echo '<tr><td>Table prefix</td><td><code>'.h($wp_table_prefix).'</code></td></tr>';
echo '</table>';

$test_conn = @new mysqli($db_server, $db_username, $db_password, $db_name);
if ($test_conn->connect_error) {
    echo '<p class="msg-error" style="margin-top:10px">✗ Connection failed: '.h($test_conn->connect_error).'</p>';
    $error = true;
} else {
    echo '<p class="msg-success" style="margin-top:10px">✓ Successfully connected to <strong>'.h($db_name).'</strong> on <strong>'.h($db_server).'</strong></p>';
    $test_conn->close();
}
echo '</div></details>';

// ============================================================
// SECTION 3 — Backup
// ============================================================
echo '<details>';
echo '<summary>💾 Database Backup</summary>';
echo '<div class="details-body">';
echo '<p>Creates a full dump of the current database <strong>before</strong> importing. The file is saved in the same directory.</p>';
echo '<form method="POST">';
echo '<input type="hidden" name="action" value="run_backup">';
echo '<p style="margin-top:10px"><button type="submit" class="btn btn-warn">▶ Generate backup now</button></p>';
echo '</form>';

// List existing backups
$found_all = find_dump_files($upload_dir);
if (!empty($found_all['backup'])) {
    echo '<h2 style="margin-top:14px">Existing Backups &nbsp;<span class="badge badge-info">'.count($found_all['backup']).' file(s)</span></h2>';
    echo '<table class="data"><tr><th>File</th><th>Size</th><th>Date</th><th>Actions</th></tr>';
    foreach ($found_all['backup'] as $bf) {
        $bp = BACKUP_DIR . '/' . $bf;
        $sz = file_exists($bp) ? fmt_bytes(filesize($bp)) : '?';
        $dt = file_exists($bp) ? date('Y-m-d H:i', filemtime($bp)) : '?';
        echo '<tr>';
        echo '<td><code>' . h($bf) . '</code></td>';
        echo '<td>' . $sz . '</td>';
        echo '<td>' . $dt . '</td>';
        echo '<td style="white-space:nowrap">';
        echo '<a href="?download=' . urlencode($bf) . '" class="btn btn-success btn-sm" title="Download backup">⬇ Download</a> ';
        echo '<a href="?delete=' . urlencode($bf) . '" class="btn btn-danger btn-sm" onclick="return confirm(\'Permanently delete ' . h($bf) . '?\')" title="Delete backup">✕ Delete</a>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</table>';
} else {
    echo '<p class="msg-info" style="margin-top:10px">No backup files yet. Click "Generate backup now" to create one.</p>';
}
echo '</div></details>';

// ============================================================
// SECTION 3b — WordPress User Management
// ============================================================
echo '<details>';
echo '<summary>👤 WordPress User Management</summary>';
echo '<div class="details-body">';
echo '<p>Manage WordPress users directly in the database. Password changes use the phpass algorithm (compatible with WP).</p>';

// User table
echo '<div id="wp-users-wrap">';
echo '<p><button type="button" class="btn btn-primary btn-sm" onclick="wpLoadUsers()">↺ Load / Refresh list</button></p>';
echo '<div id="wp-users-table" style="margin-top:10px"></div>';
echo '</div>';

// Add user form
echo '<details style="margin-top:14px;border:1px solid #e2e8f0;border-radius:6px;background:#f7fafc">';
echo '<summary style="padding:10px 14px;cursor:pointer;font-weight:600;color:#2d3748">➕ Add new user</summary>';
echo '<div style="padding:0 14px 14px">';
echo '<form id="addUserForm" style="margin-top:10px">';
echo '<div class="grid-2">';
echo '<div class="form-row"><label>Login (username)</label><input type="text" id="nu_login" placeholder="johnsmith" required></div>';
echo '<div class="form-row"><label>Display name</label><input type="text" id="nu_name" placeholder="John Smith"></div>';
echo '<div class="form-row"><label>Email</label><input type="text" id="nu_email" placeholder="john@example.com" required autocomplete="email"></div>';
echo '<div class="form-row"><label>Password</label><input type="password" id="nu_pass" placeholder="minimum 6 characters" required autocomplete="new-password"></div>';
echo '<div class="form-row"><label>Role</label>';
echo '<select id="nu_role">';
foreach (['administrator' => 'Administrator', 'editor' => 'Editor', 'author' => 'Author', 'contributor' => 'Contributor', 'subscriber' => 'Subscriber'] as $v => $l)
    echo "<option value=\"$v\">$l</option>";
echo '</select></div>';
echo '</div>';
echo '<p><button type="button" class="btn btn-success" onclick="wpAddUser()">➕ Create user</button></p>';
echo '</form>';
echo '<div id="add-user-result" style="margin-top:8px"></div>';
echo '</div></details>';

// Inline modal for edit operations
echo <<<'HTML'
<div id="wp-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:10px;padding:28px;min-width:340px;max-width:460px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.25)">
    <h2 id="wp-modal-title" style="margin-bottom:16px;font-size:16px;color:#2d3748"></h2>
    <div id="wp-modal-body"></div>
    <div style="margin-top:16px;display:flex;gap:10px">
      <button id="wp-modal-confirm" class="btn btn-success" onclick="wpModalConfirm()">Confirm</button>
      <button class="btn" style="background:#e2e8f0;color:#4a5568" onclick="wpModalClose()">Cancel</button>
    </div>
    <div id="wp-modal-result" style="margin-top:10px"></div>
  </div>
</div>
HTML;

echo '</div></details>';

// ============================================================
// SECTION 3c — WordPress Plugins
// ============================================================
echo '<details>';
echo '<summary>🔌 WordPress Plugins</summary>';
echo '<div class="details-body">';
echo '<p>Manage active WordPress plugins directly in the database. This reads and writes the <code>active_plugins</code> option.</p>';

echo '<p style="margin-top:10px">';
echo '<button type="button" class="btn btn-primary btn-sm" onclick="wpLoadPlugins()">↺ Load plugins</button> ';
echo '<button type="button" class="btn btn-danger btn-sm" style="margin-left:6px" onclick="wpDeactivateAll()">✕ Deactivate all</button>';
echo '</p>';
echo '<div id="wp-plugins-table" style="margin-top:12px"></div>';
echo '</div></details>';

// ============================================================
// SECTION 4 — URL replacement
// ============================================================
$cfg_url_local  = $cfg['url_local']  ?? '';
$cfg_url_remote = $cfg['url_remote'] ?? '';

// Auto-read siteurl from wp_options if source URL not yet configured
$wp_siteurl = '';
if ($cfg_url_local === '' && $mysqli && !$mysqli->connect_error) {
    $tbl_opts = $wp_table_prefix . 'options';
    $su_res = $mysqli->query("SELECT option_value FROM `$tbl_opts` WHERE option_name='siteurl' LIMIT 1");
    if ($su_res && $su_row = $su_res->fetch_assoc()) {
        $wp_siteurl = rtrim($su_row['option_value'], '/');
        $su_res->free();
    }
}
// Fallback: try a fresh connection if $mysqli not yet available at this point
if ($wp_siteurl === '') {
    $tmp_conn = @new mysqli($db_server, $db_username, $db_password, $db_name);
    if (!$tmp_conn->connect_error) {
        $tbl_opts = $wp_table_prefix . 'options';
        $su_res = $tmp_conn->query("SELECT option_value FROM `$tbl_opts` WHERE option_name='siteurl' LIMIT 1");
        if ($su_res && $su_row = $su_res->fetch_assoc()) {
            $wp_siteurl = rtrim($su_row['option_value'], '/');
        }
        $tmp_conn->close();
    }
}
// Use siteurl as default for source URL field if no config saved
if ($cfg_url_local === '' && $wp_siteurl !== '') {
    $cfg_url_local = $wp_siteurl;
}

echo '<details>';
echo '<summary>🔗 WordPress URL Replacement</summary>';
echo '<div class="details-body">';
echo '<p>Replace image, upload, and document URLs in the SQL file before importing. URLs are validated against a live WordPress installation.</p>';

// Validation result placeholders
echo '<div id="val-local"></div><div id="val-remote"></div>';

echo '<form method="POST" id="urlReplaceForm">';
echo '<input type="hidden" name="action" value="url_preview">';
echo '<div class="grid-2">';
echo '<div class="form-row"><label>🏠 Source URL (old)</label>';
echo '<input type="url" name="url_old" id="url_old" value="'.h($cfg_url_local).'" placeholder="https://old-site.com" onblur="validateUrl(this.value,\'val-local\',\'local\')"></div>';
echo '<div class="form-row"><label>🌐 Target URL (new)</label>';
echo '<input type="url" name="url_new" id="url_new" value="'.h($cfg_url_remote).'" placeholder="https://new-site.com" onblur="validateUrl(this.value,\'val-remote\',\'remote\')"></div>';
echo '</div>';

// File selection for replacement
$sql_available = array_merge($found_all['sql'], $found_all['gz']);
if (!empty($sql_available)) {
    echo '<div class="form-row"><label>SQL file to process</label>';
    echo '<select name="sql_file" id="sql_file_replace">';
    foreach ($sql_available as $sf) {
        echo '<option value="'.h($sf).'">'.h($sf).'</option>';
    }
    echo '</select></div>';
}

echo '<p><button type="button" class="btn btn-primary" onclick="previewReplace()">🔍 Preview replacement</button></p>';
echo '</form>';

// Preview result
echo '<div id="replace-preview"></div>';

// Apply form (hidden until preview)
echo '<form method="POST" id="applyReplaceForm" style="display:none;margin-top:12px">';
echo '<input type="hidden" name="action" value="apply_url_replace">';
echo '<input type="hidden" name="url_old" id="apply_url_old">';
echo '<input type="hidden" name="url_new" id="apply_url_new">';
echo '<input type="hidden" name="sql_file" id="apply_sql_file">';
echo '<button type="submit" class="btn btn-success">✓ Apply replacement in SQL file</button>';
echo '</form>';

echo '</div></details>';

// ============================================================
// SECTION 5 — FTP/SFTP deploy
// ============================================================
$found_all_deploy = find_dump_files($upload_dir);
$all_sql_files    = array_merge($found_all_deploy['sql'], $found_all_deploy['gz'], $found_all_deploy['zip']);

echo '<details>';
echo '<summary>🚀 FTP / SFTP Deploy</summary>';
echo '<div class="details-body">';
echo '<p>Upload <code>bigdump.php</code> and your SQL files to a remote server. Supports FTP, FTPS, and SFTP. Credentials can be saved in the configuration for reuse.</p>';

echo '<form method="POST">';
echo '<input type="hidden" name="action" value="ftp_upload">';
echo '<div class="grid-2">';
echo '<div class="form-row"><label>Host / IP</label>';
echo '<input type="text" name="ftp_host" value="'.h($cfg['ftp_host']??'').'" placeholder="ftp.mydomain.com"></div>';
echo '<div class="form-row"><label>Protocol</label>';
echo '<select name="ftp_protocol">';
foreach (['ftp' => 'FTP', 'ftps' => 'FTPS (Secure FTP)', 'sftp' => 'SFTP (SSH)'] as $val => $label) {
    $sel = ($cfg['ftp_protocol'] ?? 'ftp') === $val ? ' selected' : '';
    echo "<option value=\"$val\"$sel>$label</option>";
}
echo '</select></div>';
echo '<div class="form-row"><label>Username</label>';
echo '<input type="text" name="ftp_user" value="'.h($cfg['ftp_user']??'').'" placeholder="ftp_user" autocomplete="username"></div>';
echo '<div class="form-row"><label>Password</label>';
echo '<input type="password" name="ftp_pass" value="'.h($cfg['ftp_pass']??'').'" placeholder="••••••••" autocomplete="current-password"></div>';
echo '<div class="form-row"><label>Port</label>';
echo '<input type="number" name="ftp_port" value="'.h($cfg['ftp_port']??'21').'" placeholder="21"></div>';
echo '<div class="form-row"><label>Remote path</label>';
echo '<input type="text" name="ftp_path" value="'.h($cfg['ftp_path']??'/').'" placeholder="/public_html/bigdump/"></div>';
echo '</div>';

if (!empty($all_sql_files)) {
    echo '<div class="form-row"><label>SQL files to upload (optional)</label>';
    echo '<div style="background:#f7fafc;border:1px solid #e2e8f0;border-radius:5px;padding:10px">';
    foreach ($all_sql_files as $sf) {
        $fp = $upload_dir.'/'.$sf;
        $sz = file_exists($fp) ? ' ('.fmt_bytes(filesize($fp)).')' : '';
        echo '<label style="font-weight:normal;display:flex;align-items:center;gap:6px;margin:4px 0">';
        echo '<input type="checkbox" name="ftp_files[]" value="'.h($sf).'"> '.h($sf).$sz.'</label>';
    }
    echo '</div></div>';
}

echo '<p><button type="submit" class="btn btn-purple">📤 Upload selected files</button></p>';
echo '</form>';

// Zip whole directory + upload
echo '<hr style="margin:18px 0;border:none;border-top:1px solid #e2e8f0">';
echo '<h2>📦 Compress &amp; Upload working directory</h2>';
echo '<p>Creates a <code>.zip</code> of the entire directory where this script lives and uploads it via FTP/SFTP. Useful for a full site backup or migration. Uses the same credentials above.</p>';
echo '<div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
echo '<button type="button" class="btn btn-warn" onclick="ftpZipUpload()">🗜 Compress &amp; Upload directory</button>';
echo '<span id="zip-upload-result" style="font-size:13px"></span>';
echo '</div>';

echo '</div></details>';

// ============================================================
// SECTION 6 — Configuration
// ============================================================
echo '<details>';
echo '<summary>⚙ Configuration &amp; Migration Profiles</summary>';
echo '<div class="details-body">';
echo '<p>Save FTP credentials, URLs, and preferences to <code>bigdump.config.json</code> for reuse. Supports bidirectional migration (local → hosting or hosting → local).</p>';

if (file_exists(CONFIG_FILE)) {
    echo '<p><span class="badge badge-ok">Config loaded</span> &nbsp; Last saved: <strong>' . h($cfg['saved_at'] ?? '—') . '</strong></p>';
}

echo '<form method="POST">';
echo '<input type="hidden" name="action" value="save_config">';

echo '<div class="form-row"><label>Profile name (e.g. "My site — local to production")</label>';
echo '<input type="text" name="profile_name" value="'.h($cfg['profile_name']??'').'" placeholder="My WordPress migration"></div>';

echo '<h2>Migration URLs</h2>';
echo '<div class="grid-2">';
echo '<div class="form-row"><label>🏠 Local environment URL</label>';
echo '<input type="url" name="url_local" value="'.h($cfg['url_local']??'').'" placeholder="http://localhost/mysite"></div>';
echo '<div class="form-row"><label>🌐 Hosting / production URL</label>';
echo '<input type="url" name="url_remote" value="'.h($cfg['url_remote']??'').'" placeholder="https://mydomain.com"></div>';
echo '</div>';

echo '<h2>FTP / SFTP Credentials</h2>';
echo '<div class="grid-2">';
echo '<div class="form-row"><label>Host</label><input type="text" name="ftp_host" value="'.h($cfg['ftp_host']??'').'" placeholder="ftp.mydomain.com"></div>';
echo '<div class="form-row"><label>Protocol</label><select name="ftp_protocol">';
foreach (['ftp' => 'FTP', 'ftps' => 'FTPS', 'sftp' => 'SFTP'] as $v => $l) {
    echo '<option value="'.$v.'"'.(($cfg['ftp_protocol']??'ftp')===$v?' selected':'').">$l</option>";
}
echo '</select></div>';
echo '<div class="form-row"><label>Username</label><input type="text" name="ftp_user" value="'.h($cfg['ftp_user']??'').'" placeholder="username" autocomplete="username"></div>';
echo '<div class="form-row"><label>Password</label><input type="password" name="ftp_pass" value="'.h($cfg['ftp_pass']??'').'" placeholder="••••••••" autocomplete="current-password"></div>';
echo '<div class="form-row"><label>Port</label><input type="number" name="ftp_port" value="'.h($cfg['ftp_port']??'21').'"></div>';
echo '<div class="form-row"><label>Remote path</label><input type="text" name="ftp_path" value="'.h($cfg['ftp_path']??'/').'" placeholder="/public_html/bigdump/"></div>';
echo '</div>';

echo '<h2>Import Preferences</h2>';
echo '<div class="grid-2">';
echo '<div class="form-row"><label>Lines per session</label>';
echo '<input type="number" name="linespersession" value="'.h($cfg['linespersession']??'3000').'" min="100" max="100000">';
echo '<small style="color:#718096;font-size:12px">Lower = safer on slow servers. Default: 3000.</small></div>';
echo '<div class="form-row"><label>Delay between sessions (ms)</label>';
echo '<input type="number" name="delaypersession" value="'.h($cfg['delaypersession']??'0').'" min="0" max="5000">';
echo '<small style="color:#718096;font-size:12px">Add delay if MySQL is overloaded. Default: 0.</small></div>';
echo '<div class="form-row"><label>Max lines per query</label>';
echo '<input type="number" name="max_query_lines" value="'.h($cfg['max_query_lines']??'50000').'" min="300" max="500000">';
echo '<small style="color:#718096;font-size:12px">Raise this for dumps with extended inserts (phpMyAdmin default). Default: 50000.</small></div>';
echo '</div>';

echo '<p style="margin-top:12px"><button type="submit" class="btn btn-success">💾 Save configuration</button></p>';
echo '</form>';
echo '</div></details>';

// ============================================================
// SECTION 7 — File list + upload + import
// ============================================================

// Handle file upload
if (!$error && isset($_POST['uploadbutton'])) {
    if (is_uploaded_file($_FILES['dumpfile']['tmp_name'] ?? '') && ($_FILES['dumpfile']['error'] ?? 1) == 0) {
        $uname = str_replace(' ', '_', $_FILES['dumpfile']['name']);
        $uname = preg_replace('/[^_A-Za-z0-9\-\.]/', '', $uname);
        $upath = $upload_dir . '/' . $uname;
        if (file_exists($upath)) {
            echo '<p class="msg-error">File already exists. Delete it first.</p>';
        } elseif (!preg_match('/\.(sql|gz|zip|csv)$/i', $uname)) {
            echo '<p class="msg-error">Only .sql .gz .zip .csv files are allowed.</p>';
        } elseif (!@move_uploaded_file($_FILES['dumpfile']['tmp_name'], $upath)) {
            echo '<p class="msg-error">Error moving file to '.h($upath).'</p>';
        } else {
            echo '<p class="msg-success">✓ File saved: '.h($uname).'</p>';
        }
    } else {
        echo '<p class="msg-error">Error uploading the file.</p>';
    }
}

// Handle backup delete
if (!$error && isset($_GET['delete_backup'])) {
    $del = basename($_GET['delete_backup']);
    $del_path = BACKUP_DIR . '/' . $del;
    if (preg_match('/\.(sql|gz)$/i', $del) && @unlink($del_path))
        echo '<p class="msg-success">✓ Backup ' . h($del) . ' deleted.</p>';
    else
        echo '<p class="msg-error">Could not delete backup ' . h($del) . '</p>';
}

// Handle delete
if (!$error && isset($_GET['delete']) && $_GET['delete'] != basename(__FILE__)) {
    $del = basename($_GET['delete']);
    // Check backup dir first, then upload dir
    $del_path = preg_match('/^backup_/i', $del)
        ? BACKUP_DIR . '/' . $del
        : $upload_dir . '/' . $del;
    if (preg_match('/\.(sql|gz|zip|csv)$/i', $del) && @unlink($del_path))
        echo '<p class="msg-success">✓ ' . h($del) . ' deleted.</p>';
    else
        echo '<p class="msg-error">Could not delete ' . h($del) . '</p>';
}

// Auto-extract ZIP
$found = find_dump_files($upload_dir);
if (empty($found['sql']) && !empty($found['zip']) && extension_loaded('zip')) {
    $zp = new ZipArchive();
    if ($zp->open($upload_dir.'/'.$found['zip'][0]) === true) {
        for ($zi = 0; $zi < $zp->numFiles; $zi++) {
            $entry = $zp->getNameIndex($zi);
            if (preg_match('/\.sql$/i', $entry)) {
                $zp->extractTo($upload_dir, $entry);
                echo '<p class="msg-success">ZIP extracted: <strong>'.h($entry).'</strong></p>';
            }
        }
        $zp->close();
        $found = find_dump_files($upload_dir);
    }
}

// Connect DB for real
if (!$error && !TESTMODE) {
    $mysqli = new mysqli($db_server, $db_username, $db_password, $db_name);
    if ($mysqli->connect_error) {
        echo '<p class="msg-error">Connection failed: '.h($mysqli->connect_error).'</p>';
        $error = true;
    } else {
        $mysqli->query("SET NAMES $db_connection_charset");
        $mysqli->query("SET foreign_key_checks = 0");
    }
}

echo '<details open>';
echo '<summary>📂 Import Files</summary>';
echo '<div class="details-body">';

$all_importable = array_merge($found['sql'], $found['gz'], $found['zip']);
$all_backups    = $found['backup'];
if (!empty($all_importable) || !empty($all_backups)) {
    echo '<table class="data"><tr><th>File</th><th>Size</th><th>Date</th><th>Type</th><th>Actions</th></tr>';
    foreach ($all_importable as $f) {
        $fp   = $upload_dir.'/'.$f;
        $type = preg_match('/\.sql$/i', $f) ? 'SQL' : (preg_match('/\.gz$/i', $f) ? 'GZip' : 'ZIP');
        $can  = preg_match('/\.(sql|gz)$/i', $f);
        $start_url = '?start=1&fn='.urlencode($f).'&foffset=0&totalqueries=0&delimiter='.urlencode($delimiter);
        echo '<tr>';
        echo '<td><code>'.h($f).'</code></td>';
        echo '<td>'.fmt_bytes(filesize($fp)).'</td>';
        echo '<td>'.date('Y-m-d H:i', filemtime($fp)).'</td>';
        echo '<td>'.$type.'</td>';
        echo '<td>';
        if ($can) echo '<a href="'.h($start_url).'" class="btn btn-primary btn-sm">▶ Import</a> &nbsp;';
        echo '<a href="?delete='.urlencode($f).'" class="btn btn-danger btn-sm" onclick="return confirm(\'Delete '.h($f).'?\')">✕</a>';
        echo '</td></tr>';
    }
    foreach ($all_backups as $f) {
        $fp   = BACKUP_DIR.'/'.$f;
        $type = preg_match('/\.gz$/i', $f) ? 'GZip Backup' : 'SQL Backup';
        $start_url = '?start=1&fn='.urlencode($f).'&from_backup=1&foffset=0&totalqueries=0&delimiter='.urlencode($delimiter);
        echo '<tr style="background:#fff9e6">';
        echo '<td><code>'.h($f).'</code> <span class="badge badge-info">backup</span></td>';
        echo '<td>'.fmt_bytes(filesize($fp)).'</td>';
        echo '<td>'.date('Y-m-d H:i', filemtime($fp)).'</td>';
        echo '<td>'.$type.'</td>';
        echo '<td>';
        echo '<a href="'.h($start_url).'" class="btn btn-warning btn-sm" onclick="return confirm(\'Restore '.h($f).'? This will DROP all current tables.\')">↩ Restore</a> &nbsp;';
        echo '<a href="?download='.urlencode($f).'" class="btn btn-secondary btn-sm">⬇</a> &nbsp;';
        echo '<a href="?delete_backup='.urlencode($f).'" class="btn btn-danger btn-sm" onclick="return confirm(\'Delete backup '.h($f).'?\')">✕</a>';
        echo '</td></tr>';
    }
    echo '</table>';
} else {
    echo '<p class="msg-warn">No .sql, .gz or .zip files found. Upload your dump below or via FTP.</p>';
}

// Upload form
echo '<h2 style="margin-top:14px">Upload dump from browser</h2>';
do { $tmpf = $upload_dir.'/'.time().'.tmp'; } while (file_exists($tmpf));
if (!($tf = @fopen($tmpf, 'w'))) {
    echo '<p class="msg-warn">Upload disabled — directory is not writable. Use FTP.</p>';
} else {
    fclose($tf); unlink($tmpf);
    $umax = ini_bytes('upload_max_filesize');
    echo '<p>Upload limit: <strong>'.fmt_bytes($umax).'</strong>. For larger files use FTP/SFTP.</p>';
    echo '<form method="POST" enctype="multipart/form-data" style="margin-top:8px">';
    echo '<input type="hidden" name="MAX_FILE_SIZE" value="'.$umax.'">';
    echo '<div style="display:flex;gap:10px;align-items:flex-end">';
    echo '<div style="flex:1"><label>File (.sql, .gz, .zip)</label>';
    echo '<input type="file" name="dumpfile" accept=".sql,.gz,.zip,.csv"></div>';
    echo '<div><button type="submit" name="uploadbutton" class="btn btn-primary">⬆ Upload</button></div>';
    echo '</div></form>';
}
echo '</div></details>';

// ============================================================
// OPEN FILE + DROP TABLES + IMPORT SESSION
// ============================================================

if (!$error && isset($_GET['start'])) {

    if ($filename != '')        $curfilename = $filename;
    elseif (isset($_GET['fn'])) $curfilename = urldecode($_GET['fn']);

    $gzipmode = (bool) preg_match('/\.gz$/i', $curfilename);

    // Auto-extract ZIP
    if (preg_match('/\.zip$/i', $curfilename) && extension_loaded('zip')) {
        $zp = new ZipArchive();
        if ($zp->open($upload_dir.'/'.$curfilename) === true) {
            for ($zi = 0; $zi < $zp->numFiles; $zi++) {
                $entry = $zp->getNameIndex($zi);
                if (preg_match('/\.sql$/i', $entry)) {
                    $zp->extractTo($upload_dir, $entry);
                    $curfilename = $entry; $gzipmode = false;
                    echo '<p class="msg-success">ZIP extracted: <strong>'.h($entry).'</strong></p>';
                    break;
                }
            }
            $zp->close();
        } else { echo '<p class="msg-error">Could not open the ZIP file.</p>'; $error = true; }
    }

    if (!$error) {
        $from_backup = isset($_GET['from_backup']) && $_GET['from_backup'];
        $fp = ($from_backup ? BACKUP_DIR : $upload_dir).'/'.$curfilename;
        if ((!$gzipmode && !$file = @fopen($fp, 'r')) || ($gzipmode && !$file = @gzopen($fp, 'r'))) {
            echo '<p class="msg-error">Cannot open '.h($curfilename).' for import.</p>';
            $error = true;
        }
    }

    if (!$error) {
        if (!$gzipmode && fseek($file, 0, SEEK_END) === 0) $filesize = ftell($file);
        elseif ($gzipmode) $filesize = 0;
        else { echo '<p class="msg-error">Cannot determine file size.</p>'; $error = true; }
    }

    if (!$error && $csv_insert_table === '' && preg_match('/\.csv$/i', $curfilename)) {
        echo '<p class="msg-error">Set $csv_insert_table to import CSV files.</p>'; $error = true;
    }

    // DROP tables on first session
    if (!$error && $mysqli && (int)$_GET['start'] === 1) {
        $tr = $mysqli->query("SHOW TABLES");
        $dropped = [];
        if ($tr) {
            $mysqli->query("SET foreign_key_checks = 0");
            while ($row = $tr->fetch_array()) {
                $t = $row[0];
                $mysqli->query("DROP TABLE IF EXISTS `".$mysqli->real_escape_string($t)."`");
                $dropped[] = h($t);
            }
            $tr->free();
        }
        if (!empty($dropped)) {
            echo '<div class="card card-red"><div class="card-header">🗑 Tables dropped ('.count($dropped).')</div>';
            echo '<p style="font-size:12px;color:#555">'.implode(', ', $dropped).'</p></div>';
        }
    }
}

// Import session
if (!$error && isset($_GET['start']) && isset($_GET['foffset']) && preg_match('/\.(sql|gz|csv)$/i', $curfilename)) {

    if (isset($_GET['delimiter'])) $delimiter = $_GET['delimiter'];

    if (!is_numeric($_GET['start']) || !is_numeric($_GET['foffset'])) {
        echo '<p class="msg-error">UNEXPECTED: non-numeric values for start/foffset.</p>'; $error = true;
    } else {
        $_GET['start']   = (int)$_GET['start'];
        $_GET['foffset'] = (int)$_GET['foffset'];
    }

    if (!$error && $_GET['start'] === 1 && $csv_insert_table !== '' && $csv_preempty_table) {
        if (!TESTMODE && !$mysqli->query("DELETE FROM `$csv_insert_table`")) {
            echo '<p class="msg-error">Error clearing '.h($csv_insert_table).': '.$mysqli->error.'</p>'; $error = true;
        }
    }

    if (!$error) {
        echo '<div class="card"><div class="card-header">📥 Importing: '.h($curfilename).'</div>';
        if (TESTMODE) echo '<p class="msg-warn">TEST MODE — queries will not be executed</p>';
        echo '<p class="msg-info">Starting line: '.(int)$_GET['start'].'</p></div>';
    }

    if (!$error && !$gzipmode && $_GET['foffset'] > $filesize) {
        echo '<p class="msg-error">UNEXPECTED: offset exceeds file size.</p>'; $error = true;
    }

    if (!$error && ((!$gzipmode && fseek($file, $_GET['foffset']) !== 0) || ($gzipmode && gzseek($file, $_GET['foffset']) !== 0))) {
        echo '<p class="msg-error">UNEXPECTED: cannot position the file pointer.</p>'; $error = true;
    }

    if (!$error) {
        $query = ''; $queries = 0; $totalqueries = (int)$_GET['totalqueries'];
        $linenumber = $_GET['start']; $querylines = 0; $inparents = false;

        while ($linenumber < $_GET['start'] + $linespersession || $query !== '') {
            $dumpline = '';
            while (!feof($file) && substr($dumpline, -1) != "\n" && substr($dumpline, -1) != "\r") {
                $dumpline .= $gzipmode ? gzgets($file, DATA_CHUNK_LENGTH) : fgets($file, DATA_CHUNK_LENGTH);
            }
            if ($dumpline === '') break;

            if ($_GET['foffset'] === 0) $dumpline = preg_replace('|^\xEF\xBB\xBF|', '', $dumpline);

            if ($csv_insert_table !== '' && preg_match('/\.csv$/i', $curfilename)) {
                if ($csv_add_slashes) $dumpline = addslashes($dumpline);
                $dumpline = explode($csv_delimiter, $dumpline);
                $dumpline = $csv_add_quotes ? "'".implode("','", $dumpline)."'" : implode(',', $dumpline);
                $dumpline = "INSERT INTO $csv_insert_table VALUES ($dumpline);";
            }

            $dumpline = str_replace(["\r\n", "\r"], "\n", $dumpline);

            if (!$inparents && strpos($dumpline, 'DELIMITER ') === 0)
                $delimiter = str_replace('DELIMITER ', '', trim($dumpline));

            if (!$inparents) {
                $skip = false;
                foreach ($comment as $cv) {
                    if (trim($dumpline) === '' || strpos(trim($dumpline), $cv) === 0) { $skip = true; break; }
                }
                if ($skip) { $linenumber++; continue; }
            }

            $dd = str_replace('\\\\', '', $dumpline);
            $p  = substr_count($dd, $string_quotes) - substr_count($dd, "\\$string_quotes");
            if ($p % 2 != 0) $inparents = !$inparents;

            $query .= $dumpline;
            if (!$inparents) $querylines++;

            if ($querylines > $max_query_lines) {
                echo '<p class="msg-error">Stopped at line '.$linenumber.' — query exceeds '.$max_query_lines.' lines.</p>';
                $error = true; break;
            }

            if ((preg_match('/'.preg_quote($delimiter, '/').'$/', trim($dumpline)) || $delimiter === '') && !$inparents) {
                $query = substr(trim($query), 0, -strlen($delimiter));
                if (!TESTMODE && !$mysqli->query($query)) {
                    echo '<p class="msg-error">Error on line '.$linenumber.': '.h(trim($dumpline)).'</p>';
                    echo '<p>MySQL: '.h($mysqli->error).'</p>';
                    $error = true; break;
                }
                $totalqueries++; $queries++; $query = ''; $querylines = 0;
            }
            $linenumber++;
        }
    }

    if (!$error) {
        $foffset = $gzipmode ? gztell($file) : ftell($file);
        if (!$foffset) { echo '<p class="msg-error">UNEXPECTED: cannot read the offset.</p>'; $error = true; }
    }

    // Stats
    echo '<div class="card"><div class="card-header">📊 Statistics</div>';
    if (!$error) {
        $lines_this = $linenumber - $_GET['start'];
        $lines_done = $linenumber - 1;
        $bytes_this = $foffset - $_GET['foffset'];
        $bytes_done = $foffset;
        $pct_done   = (!$gzipmode && $filesize > 0) ? ceil($foffset / $filesize * 100) : 0;

        echo '<table class="data"><tr><th></th><th>This session</th><th>Total</th></tr>';
        echo '<tr><td>Lines</td><td>'.number_format($lines_this).'</td><td>'.number_format($lines_done).'</td></tr>';
        echo '<tr><td>Queries</td><td>'.number_format($queries).'</td><td>'.number_format($totalqueries).'</td></tr>';
        echo '<tr><td>MB</td><td>'.round($bytes_this/1048576, 2).'</td><td>'.round($bytes_done/1048576, 2).'</td></tr>';
        if (!$gzipmode) {
            echo '<tr><td>Progress</td><td colspan="2">';
            echo '<div class="pbar-wrap"><div class="pbar-fill" style="width:'.$pct_done.'%"></div></div>';
            echo $pct_done.'%</td></tr>';
        }
        echo '</table>';

        if ($linenumber < $_GET['start'] + $linespersession) {
            echo '<p class="msg-success" style="margin-top:12px">✓ Import completed successfully.</p>';
            echo '<p class="msg-warn"><strong>IMPORTANT:</strong> Delete the dump file and this script from the server when you no longer need them.</p>';
            $error = true;
        } else {
            $next = '?start='.$linenumber.'&fn='.urlencode($curfilename).($from_backup ? '&from_backup=1' : '').'&foffset='.$foffset.'&totalqueries='.$totalqueries.'&delimiter='.urlencode($delimiter);
            if ($delaypersession != 0)
                echo '<p class="msg-info">Waiting '.$delaypersession.' ms...</p>';
            if (!$ajax)
                echo '<script>window.setTimeout(function(){location.href="'.h($next).'";},500+'.$delaypersession.');</script>';
            echo '<noscript><p class="center"><a href="'.h($next).'">Continue from line '.$linenumber.'</a></p></noscript>';
            echo '<p class="center">Press <a href="?"><strong>STOP</strong></a> to abort.</p>';
        }
    } else {
        echo '<p class="msg-error">Stopped due to error.</p>';
    }
    echo '</div>';
}

if ($error && isset($_GET['start']))
    echo '<p class="center" style="margin:16px 0"><a href="?" class="btn btn-primary">⟵ Back to start</a></p>';

if ($mysqli) { $mysqli->query("SET foreign_key_checks = 1"); $mysqli->close(); }
if ($file && !$gzipmode) fclose($file);
elseif ($file && $gzipmode) gzclose($file);

?>

<p class="center" style="color:#999;font-size:12px;margin:20px 0">BigDump WordPress Edition v<?php echo VERSION; ?> &mdash; GPL</p>
</div>

<script>
// ============================================================
// Self-delete
// ============================================================
function ftpZipUpload() {
    var res = document.getElementById('zip-upload-result');
    // Collect FTP fields from the deploy form (first form with ftp_host)
    var host  = document.querySelector('input[name="ftp_host"]');
    var user  = document.querySelector('input[name="ftp_user"]');
    var pass  = document.querySelector('input[name="ftp_pass"]');
    var port  = document.querySelector('input[name="ftp_port"]');
    var path  = document.querySelector('input[name="ftp_path"]');
    var proto = document.querySelector('select[name="ftp_protocol"]');
    if (!host || !host.value.trim()) {
        res.innerHTML = '<span class="msg-error">Fill in FTP Host first.</span>'; return;
    }
    res.innerHTML = '<span class="msg-info">⏳ Compressing and uploading... this may take a while for large directories.</span>';
    var fd = new FormData();
    fd.append('action',       'ftp_zip_upload');
    fd.append('ftp_host',     host  ? host.value  : '');
    fd.append('ftp_user',     user  ? user.value  : '');
    fd.append('ftp_pass',     pass  ? pass.value  : '');
    fd.append('ftp_port',     port  ? port.value  : '21');
    fd.append('ftp_path',     path  ? path.value  : '/');
    fd.append('ftp_protocol', proto ? proto.value : 'ftp');
    fetch(window.location.pathname, {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(d){
        res.innerHTML = d.ok
            ? '<span class="msg-success">✓ '+escHtml(d.msg)+'</span>'
            : '<span class="msg-error">✗ '+escHtml(d.msg)+'</span>';
      })
      .catch(function(e){ res.innerHTML='<span class="msg-error">Error: '+e+'</span>'; });
}

function wpSelfDelete() {
    if (!confirm('Delete this script and config file permanently? This cannot be undone.')) return;
    var result = document.getElementById('self-delete-result');
    result.innerHTML = '<p class="msg-info">Deleting...</p>';
    var fd = new FormData();
    fd.append('action', 'wp_self_delete');
    fetch(window.location.pathname, {method: 'POST', body: fd})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.ok) {
            result.innerHTML = '<p class="msg-success">✓ ' + escHtml(d.msg) + ' — This page will no longer work.</p>';
            // Disable all interactive elements
            document.querySelectorAll('button,input,select,a').forEach(function(el){ el.disabled = true; el.style.pointerEvents = 'none'; });
        } else {
            result.innerHTML = '<p class="msg-error">✗ ' + escHtml(d.msg) + '</p>';
        }
      })
      .catch(function(e){ result.innerHTML = '<p class="msg-error">Error: ' + e + '</p>'; });
}

// ============================================================
// URL validation (against live WP install)
// ============================================================
function validateUrl(url, targetId, which) {
    if (!url) return;
    var el = document.getElementById(targetId);
    if (!el) return;
    el.innerHTML = '<div class="val-box val-warn">Verifying URL...</div>';
    var fd = new FormData();
    fd.append('action', 'validate_url');
    fd.append('url', url);
    fetch(window.location.pathname, {method: 'POST', body: fd})
      .then(function(r){ return r.json(); })
      .then(function(d){
        var cls = d.ok ? 'val-ok' : 'val-error';
        var ico = d.ok ? '✓' : '✗';
        el.innerHTML = '<div class="val-box '+cls+'">'+ico+' '+d.msg+'<br><small>Checked: '+d.url_checked+'</small></div>';
      })
      .catch(function(){ el.innerHTML = '<div class="val-box val-warn">Could not verify (no connection or CORS).</div>'; });
}

// ============================================================
// URL replace preview
// ============================================================
function previewReplace() {
    var url_old  = document.getElementById('url_old').value.trim();
    var url_new  = document.getElementById('url_new').value.trim();
    var sql_file = document.getElementById('sql_file_replace');
    var sf       = sql_file ? sql_file.value : '';
    var preview  = document.getElementById('replace-preview');
    var applyForm = document.getElementById('applyReplaceForm');

    if (!url_old || !url_new || !sf) {
        preview.innerHTML = '<p class="msg-warn">Fill in the source URL, target URL, and select a SQL file.</p>';
        return;
    }
    preview.innerHTML = '<p class="msg-info">Scanning file...</p>';
    applyForm.style.display = 'none';

    var fd = new FormData();
    fd.append('action', 'url_preview_ajax');
    fd.append('url_old', url_old);
    fd.append('url_new', url_new);
    fd.append('sql_file', sf);
    fetch(window.location.pathname, {method: 'POST', body: fd})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.error) { preview.innerHTML = '<p class="msg-error">'+d.error+'</p>'; return; }
        var html = '<div class="val-box '+(d.count > 0 ? 'val-ok' : 'val-warn')+'">'+
                   '<strong>'+d.count+' occurrences</strong> of <code>'+escHtml(url_old)+'</code> in <code>'+escHtml(sf)+'</code>.';
        if (d.samples && d.samples.length > 0) {
            html += '<br><br><strong>Sample affected lines:</strong><ul style="margin:4px 0 0 16px;font-size:12px;font-family:monospace">';
            d.samples.forEach(function(s){ html += '<li>'+escHtml(s)+'</li>'; });
            html += '</ul>';
        }
        html += '</div>';
        preview.innerHTML = html;
        if (d.count > 0) {
            document.getElementById('apply_url_old').value  = url_old;
            document.getElementById('apply_url_new').value  = url_new;
            document.getElementById('apply_sql_file').value = sf;
            applyForm.style.display = 'block';
        }
      })
      .catch(function(e){ preview.innerHTML = '<p class="msg-error">Error: '+e+'</p>'; });
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ============================================================
// WordPress User Management
// ============================================================
var wpModalAction = null;
var wpModalUid    = null;

function wpPost(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    return fetch(window.location.pathname, {method: 'POST', body: fd}).then(function(r){ return r.json(); });
}

function wpLoadUsers() {
    var wrap = document.getElementById('wp-users-table');
    wrap.innerHTML = '<p class="msg-info">Loading...</p>';
    wpPost('wp_list_users', {}).then(function(rows){
        if (!rows || rows.length === 0) {
            wrap.innerHTML = '<p class="msg-warn">No users found in table <?php echo h($wp_table_prefix); ?>users.</p>';
            return;
        }
        var html = '<table class="data">' +
            '<tr><th>ID</th><th>Login</th><th>Email</th><th>Display name</th><th>Registered</th><th>Actions</th></tr>';
        rows.forEach(function(u){
            html += '<tr>' +
                '<td><code>'+escHtml(u.ID)+'</code></td>' +
                '<td><strong>'+escHtml(u.user_login)+'</strong></td>' +
                '<td>'+escHtml(u.user_email)+'</td>' +
                '<td>'+escHtml(u.display_name)+'</td>' +
                '<td style="font-size:12px;color:#718096">'+escHtml(u.user_registered)+'</td>' +
                '<td style="white-space:nowrap">' +
                  '<button class="btn btn-primary btn-sm" onclick="wpOpenChangePass('+u.ID+',\''+escHtml(u.user_login)+'\')">🔑 Password</button> ' +
                  '<button class="btn btn-warn btn-sm" onclick="wpOpenChangeEmail('+u.ID+',\''+escHtml(u.user_login)+'\',\''+escHtml(u.user_email)+'\')">✉ Email</button> ' +
                  '<button class="btn btn-danger btn-sm" onclick="wpConfirmDelete('+u.ID+',\''+escHtml(u.user_login)+'\')">✕ Delete</button>' +
                '</td></tr>';
        });
        html += '</table>';
        wrap.innerHTML = html;
    }).catch(function(e){ wrap.innerHTML = '<p class="msg-error">Error: '+e+'</p>'; });
}

function wpModalOpen(title, bodyHtml) {
    document.getElementById('wp-modal-title').innerHTML  = title;
    document.getElementById('wp-modal-body').innerHTML   = bodyHtml;
    document.getElementById('wp-modal-result').innerHTML = '';
    var ov = document.getElementById('wp-modal-overlay');
    ov.style.display = 'flex';
}
function wpModalClose() {
    document.getElementById('wp-modal-overlay').style.display = 'none';
    wpModalAction = null; wpModalUid = null;
}

function wpOpenChangePass(uid, login) {
    wpModalUid    = uid;
    wpModalAction = 'wp_change_pass';
    wpModalOpen('🔑 Change password — <em>'+escHtml(login)+'</em>',
        '<div class="form-row"><label>New password (minimum 6 characters)</label>' +
        '<input type="password" id="modal_pass" style="width:100%;padding:7px;border:1px solid #cbd5e0;border-radius:4px" placeholder="New password" autofocus autocomplete="new-password"></div>');
}

function wpOpenChangeEmail(uid, login, currentEmail) {
    wpModalUid    = uid;
    wpModalAction = 'wp_change_email';
    wpModalOpen('✉ Change email — <em>'+escHtml(login)+'</em>',
        '<div class="form-row"><label>New email</label>' +
        '<input type="email" id="modal_email" style="width:100%;padding:7px;border:1px solid #cbd5e0;border-radius:4px" value="'+escHtml(currentEmail)+'" autofocus></div>');
}

function wpConfirmDelete(uid, login) {
    wpModalUid    = uid;
    wpModalAction = 'wp_delete_user';
    wpModalOpen('✕ Delete user',
        '<p>Are you sure you want to delete user <strong>'+escHtml(login)+'</strong> (ID '+uid+')?</p>' +
        '<p class="msg-error" style="margin-top:6px">This action cannot be undone.</p>');
}

function wpModalConfirm() {
    var result = document.getElementById('wp-modal-result');
    result.innerHTML = '<p class="msg-info">Processing...</p>';
    var data = {uid: wpModalUid};

    if (wpModalAction === 'wp_change_pass') {
        var p = document.getElementById('modal_pass');
        if (!p || p.value.length < 6) { result.innerHTML = '<p class="msg-error">Password must be at least 6 characters.</p>'; return; }
        data.new_pass = p.value;
    }
    if (wpModalAction === 'wp_change_email') {
        var e = document.getElementById('modal_email');
        if (!e || !e.value.includes('@')) { result.innerHTML = '<p class="msg-error">Invalid email address.</p>'; return; }
        data.new_email = e.value;
    }

    wpPost(wpModalAction, data).then(function(d){
        result.innerHTML = d.ok
            ? '<p class="msg-success">✓ '+escHtml(d.msg)+'</p>'
            : '<p class="msg-error">✗ '+escHtml(d.msg)+'</p>';
        if (d.ok) {
            setTimeout(function(){ wpModalClose(); wpLoadUsers(); }, 1200);
        }
    }).catch(function(e){ result.innerHTML = '<p class="msg-error">Error: '+e+'</p>'; });
}

function wpAddUser() {
    var result = document.getElementById('add-user-result');
    var login = document.getElementById('nu_login').value.trim();
    var email = document.getElementById('nu_email').value.trim();
    var pass  = document.getElementById('nu_pass').value;
    var name  = document.getElementById('nu_name').value.trim();
    var role  = document.getElementById('nu_role').value;

    if (!login || !email || pass.length < 6) {
        result.innerHTML = '<p class="msg-error">Login, email, and password (minimum 6 characters) are required.</p>';
        return;
    }
    result.innerHTML = '<p class="msg-info">Creating user...</p>';
    wpPost('wp_add_user', {new_login: login, new_email: email, new_pass: pass, new_name: name, new_role: role})
      .then(function(d){
        result.innerHTML = d.ok
            ? '<p class="msg-success">✓ '+escHtml(d.msg)+'</p>'
            : '<p class="msg-error">✗ '+escHtml(d.msg)+'</p>';
        if (d.ok) {
            document.getElementById('addUserForm').querySelectorAll('input').forEach(function(i){ i.value = ''; });
            setTimeout(function(){ wpLoadUsers(); }, 800);
        }
      }).catch(function(e){ result.innerHTML = '<p class="msg-error">Error: '+e+'</p>'; });
}

// ============================================================
// WordPress Plugins Management
// ============================================================
var _wpActivePlugins = [];

function wpLoadPlugins() {
    var wrap = document.getElementById('wp-plugins-table');
    wrap.innerHTML = '<p class="msg-info">Loading plugins...</p>';
    wpPost('wp_list_plugins', {}).then(function(d){
        if (!d.ok) { wrap.innerHTML = '<p class="msg-error">✗ ' + escHtml(d.msg || 'Error') + '</p>'; return; }
        _wpActivePlugins = d.active || [];
        wpRenderPlugins(wrap, _wpActivePlugins);
    }).catch(function(e){ wrap.innerHTML = '<p class="msg-error">Error: '+e+'</p>'; });
}

function wpRenderPlugins(wrap, active) {
    if (!active || active.length === 0) {
        wrap.innerHTML = '<p class="msg-warn">No active plugins found in the database.</p>';
        return;
    }
    var html = '<table class="data"><tr><th>Plugin slug</th><th>Status</th><th>Action</th></tr>';
    active.forEach(function(slug){
        html += '<tr>' +
            '<td><code>'+escHtml(slug)+'</code></td>' +
            '<td><span class="badge badge-ok">Active</span></td>' +
            '<td><button class="btn btn-danger btn-sm" onclick="wpTogglePlugin(\''+escHtml(slug)+'\', 0)">Deactivate</button></td>' +
            '</tr>';
    });
    html += '</table>';
    wrap.innerHTML = html;
}

function wpTogglePlugin(slug, makeActive) {
    wpPost('wp_toggle_plugin', {plugin: slug, active: makeActive}).then(function(d){
        if (d.ok) {
            wpLoadPlugins();
        } else {
            alert('Error: ' + (d.msg || 'Unknown error'));
        }
    }).catch(function(e){ alert('Error: '+e); });
}

function wpDeactivateAll() {
    if (_wpActivePlugins.length === 0) { alert('No active plugins to deactivate.'); return; }
    if (!confirm('Deactivate ALL ' + _wpActivePlugins.length + ' active plugins?')) return;
    var wrap = document.getElementById('wp-plugins-table');
    wrap.innerHTML = '<p class="msg-info">Deactivating all plugins...</p>';
    var promises = _wpActivePlugins.map(function(slug){
        return wpPost('wp_toggle_plugin', {plugin: slug, active: 0});
    });
    Promise.all(promises).then(function(){ wpLoadPlugins(); }).catch(function(e){ wrap.innerHTML='<p class="msg-error">Error: '+e+'</p>'; });
}

// ============================================================
// AJAX import (progressive)
// ============================================================
<?php if ($ajax && isset($_GET['start']) && !$error): ?>
(function(){
  var delay = <?php echo (int)$delaypersession; ?>;
  var fromBackup = <?php echo (isset($_GET['from_backup']) && $_GET['from_backup']) ? 'true' : 'false'; ?>;
  function nextUrl(ln,fn,fo,tq,dl){
    return '?start='+ln+'&fn='+fn+(fromBackup?'&from_backup=1':'')+
           '&foffset='+fo+'&totalqueries='+tq+'&delimiter='+dl+'&ajaxrequest=true';
  }
  function doRequest(url){
    var xhr = new XMLHttpRequest();
    xhr.onreadystatechange = function(){
      if(xhr.readyState!==4) return;
      if(xhr.status!==200){ alert('Server error: '+xhr.status); return; }
      var ct = xhr.getResponseHeader('Content-Type')||'';
      if(ct.indexOf('json')>=0){
        var d=JSON.parse(xhr.responseText);
        var bar=document.querySelector('.pbar-fill');
        if(bar && d.pct) bar.style.width=d.pct+'%';
        setTimeout(function(){ doRequest(nextUrl(d.ln,d.fn,d.fo,d.tq,d.dl)); }, 500+delay);
      } else {
        document.open(); document.write(xhr.responseText); document.close();
      }
    };
    xhr.open('GET',url,true); xhr.send(null);
  }
  setTimeout(function(){
    doRequest(nextUrl(
      <?php echo (int)$linenumber; ?>,
      '<?php echo urlencode($curfilename); ?>',
      <?php echo (int)($foffset??0); ?>,
      <?php echo (int)$totalqueries; ?>,
      '<?php echo urlencode($delimiter); ?>'
    ));
  }, 500+delay);
})();
<?php endif; ?>
</script>

<?php
// ============================================================
// AJAX import response
// ============================================================
if ($ajax && isset($_GET['start']) && !$error) {
    if (isset($_GET['ajaxrequest'])) {
        ob_end_clean();
        header('Content-Type: application/json');
        $pct = (!$gzipmode && $filesize > 0) ? ceil(($foffset ?? 0) / $filesize * 100) : 0;
        echo json_encode([
            'ln'  => $linenumber ?? 0,
            'fn'  => urlencode($curfilename),
            'fo'  => $foffset ?? 0,
            'tq'  => $totalqueries ?? 0,
            'dl'  => urlencode($delimiter),
            'pct' => $pct,
            'from_backup' => $from_backup ?? false,
        ]);
        exit;
    }
}

if ($error) {
    $out = ob_get_contents();
    ob_end_clean();
    echo $out;
    exit;
}

ob_flush();
