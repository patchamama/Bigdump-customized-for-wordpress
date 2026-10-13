<?php
/**
 * WP Migration Tool
 * Protegido por contraseña + nombre de archivo aleatorio
 * Uso: renombrar este archivo a migrate_RANDOMCHARS.php
 */

// ============================================================
// CONFIGURACIÓN DE SEGURIDAD
// ============================================================
// Contraseña almacenada como hash (SHA-256 + salt)
// Para cambiar la contraseña, ejecutar:
//   php -r "echo hash('sha256', 'TU_SALT_AQUI' . 'TU_PASSWORD');"
// y reemplazar PASSWORD_HASH abajo.
define('TOOL_VERSION',  '2.2.0');
define('AUTH_SALT',     'odc_migrate_2026_x9k');
define('PASSWORD_HASH', ''); // vacío = primera ejecución, se pedirá crear contraseña

// ============================================================
// DETECCIÓN DE WP CONFIG
// ============================================================
$wp_config_path = null;
$search_paths = [__DIR__ . '/wp-config.php'];
// Only search parent dir if current dir is a CONFIGURED WP root (has core dirs)
// and NOT a fresh WP download (wp-config-sample.php present + wp-config.php absent
// means the installer hasn't run yet — don't bleed into a parent installation).
$_is_wp_root = is_dir(__DIR__ . '/wp-includes') || is_dir(__DIR__ . '/wp-admin');
$_is_fresh   = file_exists(__DIR__ . '/wp-config-sample.php') && !file_exists(__DIR__ . '/wp-config.php');
if ($_is_wp_root && !$_is_fresh) {
    $search_paths[] = dirname(__DIR__) . '/wp-config.php';
}
unset($_is_wp_root, $_is_fresh);
foreach ($search_paths as $p) {
    if (file_exists($p)) { $wp_config_path = $p; break; }
}

$db = ['host' => 'localhost', 'name' => '', 'user' => '', 'pass' => '', 'prefix' => 'wp_', 'charset' => 'utf8mb4'];
$site_url = '';
$abspath   = __DIR__ . '/';

if ($wp_config_path) {
    $cfg = file_get_contents($wp_config_path);
    foreach ([
        'DB_HOST'    => 'host',
        'DB_NAME'    => 'name',
        'DB_USER'    => 'user',
        'DB_PASSWORD'=> 'pass',
        'DB_CHARSET' => 'charset',
    ] as $const => $key) {
        if (preg_match("/define\s*\(\s*['\"]" . $const . "['\"]\s*,\s*['\"]([^'\"]*)['\"\s]/", $cfg, $m)) {
            $db[$key] = $m[1];
        }
    }
    if (preg_match("/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]/", $cfg, $m)) {
        $db['prefix'] = $m[1];
    }
    if (preg_match("/define\s*\(\s*['\"]ABSPATH['\"]\s*,\s*(.+?)\s*\)\s*;/", $cfg, $m)) {
        // ABSPATH viene del config — usamos __DIR__ mejor
    }
    $abspath = dirname($wp_config_path) . '/';
}

// ============================================================
// I18N — bilingual UI (English / Spanish)
// ============================================================
// Language resolution order: ?lang= query → cookie → Accept-Language → default 'en'.
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'es'], true)) {
    $LANG = $_GET['lang'];
    setcookie('migrate_lang', $LANG, time() + 31536000, '/');
} elseif (isset($_COOKIE['migrate_lang']) && in_array($_COOKIE['migrate_lang'], ['en', 'es'], true)) {
    $LANG = $_COOKIE['migrate_lang'];
} else {
    $al = strtolower(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 0, 2));
    $LANG = $al === 'es' ? 'es' : 'en';
}

// Translation dictionary. Keys are English phrases; 'es' holds the Spanish text.
// Sections are filled progressively; t() falls back to the key when missing.
$GLOBALS['__LANG'] = $LANG;
$GLOBALS['__I18N'] = [
    'es' => [
        // Access / auth
        'Set a password to protect this tool' => 'Definí una contraseña para proteger esta herramienta',
        'Enter password to continue'          => 'Ingresá la contraseña para continuar',
        'Enter the database password from wp-config.php' => 'Ingresá la contraseña de la base de datos definida en wp-config.php',
        'Access Password'                     => 'Contraseña de acceso',
        'Password'                            => 'Contraseña',
        'New password'                        => 'Nueva contraseña',
        'Custom password'                     => 'Contraseña propia',
        'Confirm'                             => 'Confirmar',
        'Login'                               => 'Entrar',
        'Logout'                              => 'Salir',
        'Create password'                     => 'Crear contraseña',
        'Set password'                        => 'Establecer',
        'Rotate'                              => 'Rotar',
        'The database password in wp-config.php is empty, so the tool would be unprotected. Create a password now — it is stored encrypted and required on every access.'
            => 'La contraseña de la base de datos en wp-config.php está vacía, por lo que la herramienta quedaría sin protección. Creá una contraseña ahora; se guarda cifrada y será obligatoria en cada acceso.',
        'Use the database password defined in wp-config.php (DB_PASSWORD) to log in. Once inside you can set your own encrypted password.'
            => 'Usá la contraseña de la base de datos definida en wp-config.php (DB_PASSWORD) para entrar. Una vez dentro podés establecer tu propia contraseña cifrada.',
        'Access is protected by a custom encrypted password. Set a new one below to rotate it.'
            => 'El acceso está protegido por una contraseña cifrada propia. Definí una nueva abajo para rotarla.',
        'Access currently uses the wp-config.php database password. Set a custom encrypted password below to use that instead.'
            => 'El acceso usa la contraseña de la base de datos de wp-config.php. Definí una contraseña cifrada propia abajo para usar esa en su lugar.',
        // Header
        'Download tool'                       => 'Descargar herramienta',
        'WordPress Migration · Export & Import' => 'Migración de WordPress · Exportar e importar',
        'Running on live server.'             => 'Ejecutándose en un servidor en producción.',
        'Delete this file after use!'         => '¡Eliminá este archivo después de usarlo!',
        // Tabs + main card titles
        'Export (Origin)'                     => 'Exportar (Origen)',
        'Import (Destination)'                => 'Importar (Destino)',
        'How to use'                          => 'Cómo usar',
        'DB Tools'                            => 'Herramientas DB',
        'Environment Status'                  => 'Estado del entorno',
        'Clear Cache'                         => 'Limpiar caché',
        'Database Export'                     => 'Exportar base de datos',
        'Import SQL'                          => 'Importar SQL',
        // WordPress Users
        'WordPress Users'                     => 'Usuarios de WordPress',
        'List WordPress users, change passwords, edit details or create new users directly in the database. Useful when WP admin is inaccessible after a migration.'
            => 'Listá usuarios de WordPress, cambiá contraseñas, editá datos o creá usuarios nuevos directamente en la base de datos. Útil cuando el panel de WP no es accesible tras una migración.',
        'Load users'                          => 'Cargar usuarios',
        'New user'                            => 'Usuario nuevo',
        'Email'                               => 'Correo',
        'Display name'                        => 'Nombre visible',
        'Role'                                => 'Rol',
        'Create user'                         => 'Crear usuario',
        'Change password'                     => 'Cambiar contraseña',
        'Edit'                                => 'Editar',
        'Save'                                => 'Guardar',
        'Registered'                          => 'Registrado',
        'Username'                            => 'Usuario',
        // Domain Link Manager
        'Verify'                              => 'Verificar',
        'existing'                            => 'existente',
        'final'                               => 'final',
        'checking…'                           => 'verificando…',
        // PHP Configuration
        'Method'                              => 'Método',
        'Auto-detect (test both)'             => 'Autodetectar (probar ambos)',
        'Test runs the proposed config in an isolated temporary subfolder and loads it over HTTP. If it would break the site (HTTP 500) you are warned and Apply stays disabled; the test folder only affects itself, never the live site.'
            => 'La prueba aplica la configuración propuesta en una subcarpeta temporal aislada y la carga por HTTP. Si rompería el sitio (HTTP 500) se te avisa y Aplicar queda deshabilitado; la carpeta de prueba solo se afecta a sí misma, nunca al sitio en producción.',
        'Test safely'                         => 'Probar sin riesgo',
        'Apply'                               => 'Aplicar',
        // Security Audit
        'Security Audit'                      => 'Auditoría de seguridad',
        'Run Security Audit'                  => 'Ejecutar auditoría',
        'Hardening checks across users, WordPress core, filesystem and attack surface: weak/common passwords, default admin username, outdated WP version, default security keys, file permissions, exposed dumps, XML-RPC and REST exposure. Uses the DB connection configured above.'
            => 'Chequeos de endurecimiento sobre usuarios, núcleo de WordPress, sistema de archivos y superficie de ataque: contraseñas débiles/comunes, usuario admin por defecto, versión de WP desactualizada, claves de seguridad por defecto, permisos de archivos, dumps expuestos, exposición de XML-RPC y REST. Usa la conexión a la base de datos configurada arriba.',
        // WordPress Extras + phpMyAdmin
        'WordPress Extras'                    => 'Extras de WordPress',
        'Common post-migration fixes. Each action uses the DB connection configured above or writes to the site root.'
            => 'Arreglos comunes posmigración. Cada acción usa la conexión a la base de datos configurada arriba o escribe en la raíz del sitio.',
        'Regenerate .htaccess + permalinks'   => 'Regenerar .htaccess + enlaces permanentes',
        'Fixes 404s on all pages after a migration.' => 'Arregla los 404 en todas las páginas tras una migración.',
        'Repair & optimize tables'            => 'Reparar y optimizar tablas',
        'Runs REPAIR and OPTIMIZE on every table.' => 'Ejecuta REPAIR y OPTIMIZE en cada tabla.',
        'Flush rewrite rules + transients'    => 'Vaciar reglas de reescritura + transients',
        'Clears cached rewrite rules and transients.' => 'Limpia reglas de reescritura y transients en caché.',
        'WP_DEBUG'                            => 'WP_DEBUG',
        'On'                                  => 'Activar',
        'Off'                                 => 'Desactivar',
        'Maintenance mode'                    => 'Modo mantenimiento',
        'Download the latest phpMyAdmin and extract it into a /phpmyadmin folder. No config is written — set the connection on first access. Remove the folder when you are done.'
            => 'Descarga la última versión de phpMyAdmin y la extrae en una carpeta /phpmyadmin. No se escribe configuración — definí la conexión en el primer acceso. Eliminá la carpeta al terminar.',
        'Download & install phpMyAdmin'       => 'Descargar e instalar phpMyAdmin',
        // SEO
        'SEO'                                 => 'SEO',
        'Audit SEO basics (indexability, title/tagline, friendly permalinks, sitemap, robots.txt, HTTPS), edit the title and tagline, toggle indexing, generate a sitemap, request indexing and check if the site is indexed. Uses the DB connection configured above.'
            => 'Audita aspectos básicos de SEO (indexabilidad, título/descripción, enlaces permanentes amigables, sitemap, robots.txt, HTTPS), edita el título y la descripción, activa/desactiva la indexación, genera un sitemap, solicita indexación y verifica si el sitio está indexado. Usa la conexión a la base de datos configurada arriba.',
        'Run SEO Audit'                       => 'Ejecutar auditoría SEO',
        'Basic meta'                          => 'Metadatos básicos',
        'Site title'                          => 'Título del sitio',
        'Tagline'                             => 'Descripción corta',
        'Save meta'                           => 'Guardar metadatos',
        'SEO actions'                         => 'Acciones SEO',
        'Set friendly permalinks'             => 'Poner enlaces permanentes amigables',
        'Generate sitemap.xml'                => 'Generar sitemap.xml',
        'Indexing'                            => 'Indexación',
        'Allow'                               => 'Permitir',
        'Block'                               => 'Bloquear',
        'Request indexing'                    => 'Solicitar indexación',
        'Check if indexed'                    => 'Verificar si está indexado',
    ],
];

// Translate: returns the localized string for the current language, or the key.
function t($key) {
    $lang = $GLOBALS['__LANG'] ?? 'en';
    if ($lang === 'en') return $key;
    return $GLOBALS['__I18N'][$lang][$key] ?? $key;
}

// ============================================================
// WORDPRESS PASSWORD HASHING (phpass $P$ — WP-compatible)
// ============================================================
// Portable Phpass implementation matching WordPress' wp-includes/class-phpass.php.
// WordPress upgrades the stored hash to bcrypt on the user's next login, so writing
// a valid $P$ hash is enough for the credential to work immediately.
class WpPasswordHash {
    private $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private $iteration_count_log2;
    public function __construct($iteration_count_log2 = 8) {
        if ($iteration_count_log2 < 4 || $iteration_count_log2 > 31) $iteration_count_log2 = 8;
        $this->iteration_count_log2 = $iteration_count_log2;
    }
    private function encode64($input, $count) {
        $output = ''; $i = 0;
        do {
            $value = ord($input[$i++]);
            $output .= $this->itoa64[$value & 0x3f];
            if ($i < $count) $value |= ord($input[$i]) << 8;
            $output .= $this->itoa64[($value >> 6) & 0x3f];
            if ($i++ >= $count) break;
            if ($i < $count) $value |= ord($input[$i]) << 16;
            $output .= $this->itoa64[($value >> 12) & 0x3f];
            if ($i++ >= $count) break;
            $output .= $this->itoa64[($value >> 18) & 0x3f];
        } while ($i < $count);
        return $output;
    }
    private function gensalt($input) {
        $output  = '$P$';
        $output .= $this->itoa64[min($this->iteration_count_log2 + 5, 30)];
        $output .= $this->encode64($input, 6);
        return $output;
    }
    public function crypt($password, $setting) {
        $output = '*0';
        if (substr($setting, 0, 2) === $output) $output = '*1';
        $id = substr($setting, 0, 3);
        if ($id !== '$P$' && $id !== '$H$') return $output;
        $count_log2 = strpos($this->itoa64, $setting[3]);
        if ($count_log2 < 7 || $count_log2 > 30) return $output;
        $count = 1 << $count_log2;
        $salt  = substr($setting, 4, 8);
        if (strlen($salt) !== 8) return $output;
        $hash = md5($salt . $password, true);
        do { $hash = md5($hash . $password, true); } while (--$count);
        return substr($setting, 0, 12) . $this->encode64($hash, 16);
    }
    public function hash($password) {
        $hash = $this->crypt($password, $this->gensalt(random_bytes(6)));
        return strlen($hash) === 34 ? $hash : '*';
    }
    public function check($password, $stored_hash) {
        if ($stored_hash === '' || $stored_hash[0] !== '$') return false;
        return hash_equals($stored_hash, $this->crypt($password, $stored_hash));
    }
}

// WordPress role → user_level map (for the deprecated-but-still-present user_level meta).
function wp_role_level($role) {
    return [
        'administrator' => 10, 'editor' => 7, 'author' => 2,
        'contributor'   => 1,  'subscriber' => 0,
    ][$role] ?? 0;
}

// ============================================================
// HELPERS
// ============================================================
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_localhost() {
    $ip = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '';
    return in_array($ip, ['127.0.0.1', '::1', 'localhost']) ||
           strpos(($_SERVER['HTTP_HOST'] ?? ''), 'localhost') !== false ||
           strpos(($_SERVER['HTTP_HOST'] ?? ''), '127.0.0.1') !== false;
}
function detect_site_url($db) {
    try {
        $pdo = new PDO(
            "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}",
            $db['user'], $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        $row = $pdo->query("SELECT option_value FROM {$db['prefix']}options WHERE option_name='siteurl' LIMIT 1")->fetch();
        return $row ? $row[0] : '';
    } catch (Exception $e) { return ''; }
}
function try_db_connect($db) {
    try {
        $pdo = new PDO(
            "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}",
            $db['user'], $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        return ['ok' => true, 'pdo' => $pdo];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
function current_url() {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri   = $_SERVER['REQUEST_URI'] ?? '/';
    $base  = dirname($uri);
    $base  = rtrim($base, '/');
    return rtrim($proto . '://' . $host . $base, '/');
}
function check_cmd($cmd) {
    if (function_exists('exec')) {
        $out = []; $ret = 0;
        exec('which ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $ret);
        if ($ret === 0 && !empty($out[0])) return $out[0];
        exec('where ' . escapeshellarg($cmd) . ' 2>NUL', $out, $ret); // Windows
        if ($ret === 0 && !empty($out[0])) return $out[0];
    }
    return false;
}
function human_size($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes/1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes/1048576, 2) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes/1024, 2) . ' KB';
    return $bytes . ' B';
}
function dir_size($dir) {
    $size = 0;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) { try { $size += $f->getSize(); } catch(Exception $e) {} }
    } catch(Exception $e) {}
    return $size;
}

// ------------------------------------------------------------
// SQL SPLITTER — size-limited .zip parts
// ------------------------------------------------------------
// Reads a SQL file one logical statement at a time (respecting string literals
// and escapes so ';' inside data never splits a statement) and groups full
// statements into parts. Each part is written as a .zip whose COMPRESSED size
// stays under $max_bytes. gzencode (same DEFLATE as zip) is used to measure the
// compressed size cheaply before committing a part to a real .zip on disk.
// Every part repeats a header (FK checks off, charset) so it imports standalone
// and in order; the final part restores FK checks.
function split_sql_to_zip_parts($sql_path, $out_dir, $base_name, $max_bytes) {
    if (!is_file($sql_path)) return ['ok' => false, 'error' => 'SQL file not found for splitting'];
    if (!class_exists('ZipArchive')) return ['ok' => false, 'error' => 'ZipArchive not available on this server'];

    $header = "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET NAMES utf8mb4;\n\n";
    $footer = "\nSET FOREIGN_KEY_CHECKS=1;\n";

    $fh = fopen($sql_path, 'rb');
    if (!$fh) return ['ok' => false, 'error' => 'Could not open SQL file'];

    $parts      = [];       // committed part file paths
    $part_index = 0;
    $buffer     = $header;   // statements already committed to the current part
    $in_part    = 0;         // statements in current part (beyond header)
    $stmt       = '';        // statement being read
    $in_string  = false;
    $quote      = '';
    $escaped    = false;

    // Write $content as a .zip and return its real on-disk size (-1 on failure).
    $write_zip = function ($content, $idx) use ($out_dir, $base_name) {
        $part_path = sprintf('%s/%s_part_%03d.zip', $out_dir, $base_name, $idx);
        $sql_entry = sprintf('%s_part_%03d.sql', $base_name, $idx);
        $zip = new ZipArchive();
        if ($zip->open($part_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return [-1, $part_path];
        $zip->addFromString($sql_entry, $content);
        $zip->close();
        clearstatcache(true, $part_path);
        return [filesize($part_path), $part_path];
    };

    while (true) {
        $line = fgets($fh, 1048576);
        $eof  = ($line === false);
        if (!$eof) {
            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $ch = $line[$i];
                if ($in_string) {
                    if ($escaped)           { $escaped = false; }
                    elseif ($ch === '\\')   { $escaped = true; }
                    elseif ($ch === $quote) { $in_string = false; }
                } else {
                    if ($ch === '\'' || $ch === '"') { $in_string = true; $quote = $ch; }
                }
            }
            $stmt .= $line;
            $trimmed = rtrim($line, "\r\n");
            $stmt_complete = (!$in_string && $trimmed !== '' && substr($trimmed, -1) === ';');
            if (!$stmt_complete) continue; // keep reading until the statement ends
        }

        // At EOF, flush any trailing non-terminated statement with the buffer.
        if ($eof) { if (trim($stmt) !== '') { $buffer .= $stmt; $in_part++; $stmt = ''; } break; }

        // We have one complete statement in $stmt. Measure the real .zip of
        // buffer + this statement. If it exceeds the limit AND the part already
        // holds at least one statement, commit the part WITHOUT this statement
        // and start a fresh part. A single statement bigger than the limit forms
        // its own (unavoidably oversized) part.
        $candidate_content = $buffer . $stmt . $footer;
        [$csize, ] = $write_zip($candidate_content, $part_index + 1);
        if ($csize === -1) { fclose($fh); return ['ok' => false, 'error' => 'Failed writing a zip part']; }

        if ($csize > $max_bytes && $in_part > 0) {
            // Commit current buffer (without the new statement) as the part.
            $part_index++;
            [$sz, $path] = $write_zip($buffer . $footer, $part_index);
            if ($sz === -1) { fclose($fh); return ['ok' => false, 'error' => 'Failed writing a zip part']; }
            $parts[] = $path;
            // Start new part with the pending statement.
            $buffer  = $header . $stmt;
            $in_part = 1;
        } else {
            $buffer .= $stmt;
            $in_part++;
        }
        $stmt = '';
    }

    fclose($fh);

    // Commit the final part (buffer always holds the header + remaining stmts).
    if ($in_part > 0 || $buffer !== $header) {
        $part_index++;
        [$sz, $path] = $write_zip($buffer . $footer, $part_index);
        if ($sz === -1) return ['ok' => false, 'error' => 'Failed writing the final zip part'];
        $parts[] = $path;
    }

    // Clean up the probe file left at index+1 if it was never committed.
    $probe = sprintf('%s/%s_part_%03d.zip', $out_dir, $base_name, $part_index + 1);
    if (is_file($probe)) @unlink($probe);

    return ['ok' => true, 'parts' => $parts, 'count' => count($parts)];
}

// ============================================================
// AUTENTICACIÓN
// ============================================================
session_start();

$config_file = __DIR__ . '/.migrate_config';
$stored_hash = '';
if (file_exists($config_file)) {
    $cfg_data = json_decode(file_get_contents($config_file), true);
    $stored_hash = $cfg_data['password_hash'] ?? '';
}

$auth_error = '';
$authenticated = isset($_SESSION['migrate_auth']) && $_SESSION['migrate_auth'] === true;

// Access model:
//  - If a custom password hash exists (.migrate_config), it is the only gate.
//  - Otherwise, the gate is the wp-config DB_PASSWORD, so the tool is never open:
//      * DB_PASSWORD set   -> enter by typing it.
//      * DB_PASSWORD empty -> no password can be typed; the user MUST create a
//        custom password first. This covers "root with no password" setups.
//  - After entering, the user can set a custom encrypted password that then
//    replaces the DB password as the gate.
$db_pass          = (string)($db['pass'] ?? '');
$db_pass_is_empty = ($db_pass === '');
// Must bootstrap a custom password when there is no stored hash and the DB has none.
$must_set_password = (!$stored_hash && $db_pass_is_empty);

// Save a custom encrypted password. Required during bootstrap; also available
// afterwards (from the authenticated UI) to set or rotate the gate password.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_password'])) {
    $allowed = $must_set_password || $authenticated;
    $pw  = $_POST['new_password'] ?? '';
    $pw2 = $_POST['new_password2'] ?? '';
    if (!$allowed) {
        $auth_error = 'Not allowed / Operación no permitida.';
    } elseif (strlen($pw) < 8) {
        $auth_error = 'Password must be at least 8 characters / La contraseña debe tener al menos 8 caracteres.';
    } elseif ($pw !== $pw2) {
        $auth_error = 'Passwords do not match / Las contraseñas no coinciden.';
    } else {
        $hash = hash('sha256', AUTH_SALT . $pw);
        file_put_contents($config_file, json_encode(['password_hash' => $hash]));
        @chmod($config_file, 0600);
        $stored_hash       = $hash;
        $must_set_password = false;
        $_SESSION['migrate_auth'] = true;
        $authenticated     = true;
    }
}

// Login: accept the custom hash when present, else the wp-config DB password.
if (!$authenticated && !$must_set_password
    && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $pw = (string)($_POST['password'] ?? '');
    $ok = $stored_hash
        ? hash_equals($stored_hash, hash('sha256', AUTH_SALT . $pw))
        : (!$db_pass_is_empty && hash_equals($db_pass, $pw));
    if ($ok) {
        $_SESSION['migrate_auth'] = true;
        $authenticated = true;
    } else {
        $auth_error = 'Wrong password / Contraseña incorrecta.';
        sleep(2);
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . basename(__FILE__));
    exit;
}

// ============================================================
// BIGDUMP EMBEDDED (requiere auth)
// ============================================================
if ($authenticated && isset($_GET['action']) && $_GET['action'] === 'bigdump') {
    // Inject DB credentials from wp-config into BigDump variables
    $db_server            = $db['host'];
    $db_name              = $db['name'];
    $db_username          = $db['user'];
    $db_password          = $db['pass'];
    $db_connection_charset = 'utf8';
    $filename             = '';
    $ajax                 = true;
    $linespersession      = 3000;
    $delaypersession      = 0;
    $max_query_lines      = 50000;
    $csv_insert_table     = '';
    $csv_preempty_table   = false;
    $csv_delimiter        = ',';
    $csv_add_quotes       = true;
    $csv_add_slashes      = true;
    $comment              = ['#', '-- ', 'DELIMITER', '/*!'];
    $delimiter            = ';';
    $string_quotes        = '\'';
    $upload_dir           = $abspath;  // upload SQL files to WP root

    // Override PHP_SELF so BigDump links point back to this script with ?action=bigdump
    $_SERVER['PHP_SELF']  = basename(__FILE__) . '?action=bigdump';

    if ($ajax) ob_start();

    define('VERSION', '0.36b');
    define('DATA_CHUNK_LENGTH', 16384);
    define('TESTMODE', false);

    header("Expires: Mon, 1 Dec 2003 01:00:00 GMT");
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");

    @ini_set('auto_detect_line_endings', true);
    @set_time_limit(0);

    if (function_exists("date_default_timezone_set") && function_exists("date_default_timezone_get"))
        @date_default_timezone_set(@date_default_timezone_get());

    foreach ($_REQUEST as $key => $val) {
        $val = preg_replace("/[^_A-Za-z0-9-\.&= ;\$]/i", '', $val);
        $_REQUEST[$key] = $val;
    }

    function bd_do_action($tag) {
        global $bd_plugin_actions;
        if (isset($bd_plugin_actions[$tag])) {
            foreach ($bd_plugin_actions[$tag] as $action) call_user_func($action);
        }
    }
    function bd_add_action($tag, $fn) {
        global $bd_plugin_actions;
        $bd_plugin_actions[$tag][] = $fn;
    }

    ?><!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html><head>
<title>BigDump ver. <?php echo VERSION; ?> — WP Migration Tool</title>
<meta http-equiv="CONTENT-TYPE" content="text/html; charset=utf-8"/>
<meta name="robots" content="noindex, nofollow">
<style type="text/css">
body{background:#0f1117;color:#e2e8f0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;padding:24px}
h1{font-size:20px;color:#fff;margin:0 0 8px}
p,td,th{font-size:14px;line-height:18px;font-family:inherit;margin:5px 0;vertical-align:top}
p.centr{text-align:center}p.smlcentr{font-size:10px;text-align:center}
p.error{color:#f87171;font-weight:bold}p.success{color:#4ade80;font-weight:bold}
p.successcentr{color:#4ade80;background:#064e3b;font-weight:bold;text-align:center;padding:8px;border-radius:6px}
p.wpconfig{color:#86efac;font-style:italic;font-size:12px;text-align:center}
table{border-collapse:collapse;width:100%}
td{background:#1a1d27;border:1px solid #2d3148;padding:6px 10px;text-align:left}
th{background:#2d3148;border:1px solid #3a3f5c;padding:6px 10px;color:#a0aec0;font-weight:600;text-align:left}
td.right{text-align:right}td.bg3{background:#1e2235;width:20%}th.bg4{background:#2d3148;width:20%}
td.bgpctbar{background:#1a1d27;width:80%}
div.skin1{background:#1a1d27;border:2px solid #4f6ef7;border-radius:8px;text-align:center;padding:12px;margin:0 0 16px}
.btn{display:inline-block;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;border:none}
.btn-back{background:#374151;color:#e2e8f0}.btn-back:hover{background:#4b5563}
a{color:#7dd3fc}a:hover{color:#38bdf8}
form{margin:8px 0}input[type=file]{color:#e2e8f0;background:#0f1117;border:1px solid #2d3148;padding:4px 8px;border-radius:4px}
input[type=submit]{background:#4f6ef7;color:#fff;border:none;padding:7px 16px;border-radius:6px;cursor:pointer;font-size:13px}
div.danger-banner{background:#7f1d1d;border:2px solid #ef4444;border-radius:8px;padding:16px 20px;margin:12px 0}
div.danger-banner h2{font-size:18px;color:#fca5a5;margin:0 0 8px}
div.danger-banner p,div.danger-banner li{color:#fecaca;font-size:13px}
a.delete-btn{background:#fff;color:#dc2626;font-weight:bold;padding:8px 20px;border-radius:4px;text-decoration:none;border:2px solid #dc2626;display:inline-block;margin-top:8px}
</style></head><body>
<p><a href="<?php echo basename(__FILE__); ?>" class="btn btn-back">← Back to Migration Tool</a></p>
<?php
    function skin_open()  { echo '<div class="skin1">'; }
    function skin_close() { echo '</div>'; }

    skin_open();
    echo '<h1>BigDump: Staggered MySQL Dump Importer v' . VERSION . '</h1>';
    echo '<p style="color:#86efac;font-size:12px;">DB credentials loaded from wp-config.php — <b>' . htmlspecialchars($db_name) . '</b> @ <b>' . htmlspecialchars($db_server) . '</b></p>';
    skin_close();

    $error = false;
    $file  = false;

    if (!function_exists('version_compare')) {
        echo '<p class="error">PHP version 4.1.0 is required.</p>';
        $error = true;
    }
    if (!$error && !function_exists('mysqli_connect')) {
        echo '<p class="error">No MySQLi extension found.</p>';
        $error = true;
    }
    if (!$error) {
        $upload_max_filesize = ini_get("upload_max_filesize");
        if (preg_match("/([0-9]+)K/i", $upload_max_filesize, $tempregs)) $upload_max_filesize = $tempregs[1] * 1024;
        if (preg_match("/([0-9]+)M/i", $upload_max_filesize, $tempregs)) $upload_max_filesize = $tempregs[1] * 1024 * 1024;
        if (preg_match("/([0-9]+)G/i", $upload_max_filesize, $tempregs)) $upload_max_filesize = $tempregs[1] * 1024 * 1024 * 1024;
    }

    // Handle file upload
    if (!$error && isset($_REQUEST["uploadbutton"])) {
        if (is_uploaded_file($_FILES["dumpfile"]["tmp_name"]) && $_FILES["dumpfile"]["error"] == 0) {
            $uploaded_filename = str_replace(" ", "_", $_FILES["dumpfile"]["name"]);
            $uploaded_filename = preg_replace("/[^_A-Za-z0-9-\.]/i", '', $uploaded_filename);
            $uploaded_filepath = str_replace("\\", "/", $upload_dir . "/" . $uploaded_filename);
            if (file_exists($uploaded_filepath)) {
                echo "<p class='error'>File $uploaded_filename already exists!</p>";
            } elseif (!preg_match("/(\.(sql|gz|zip|csv))$/i", $uploaded_filename)) {
                echo "<p class='error'>Only .sql, .gz, .zip or .csv files allowed.</p>";
            } elseif (!@move_uploaded_file($_FILES["dumpfile"]["tmp_name"], $uploaded_filepath)) {
                echo "<p class='error'>Error moving file to $uploaded_filepath. Check directory permissions.</p>";
            } else {
                echo "<p class='success'>File uploaded as $uploaded_filename</p>";
            }
        } else {
            echo "<p class='error'>Error uploading file.</p>";
        }
    }

    // Handle file deletion
    if (!$error && isset($_REQUEST["delete"]) && $_REQUEST["delete"] != basename(__FILE__)) {
        if (preg_match("/(\.(sql|gz|zip|csv))$/i", $_REQUEST["delete"]) && @unlink($upload_dir . '/' . $_REQUEST["delete"]))
            echo "<p class='success'>" . htmlspecialchars($_REQUEST["delete"]) . " removed.</p>";
        else
            echo "<p class='error'>Can't remove " . htmlspecialchars($_REQUEST["delete"]) . "</p>";
    }

    // Connect to DB
    if (!$error && !TESTMODE) {
        try {
            mysqli_report(MYSQLI_REPORT_STRICT);
            $mysqli = new mysqli($db_server, $db_username, $db_password, $db_name);
            mysqli_report(MYSQLI_REPORT_OFF);
            if ($db_connection_charset !== '') $mysqli->set_charset($db_connection_charset);
        } catch (mysqli_sql_exception $e) {
            $conn_error_code = $e->getCode();
            echo "<div class='danger-banner'><h2>&#9888; DB Connection Failed</h2><p>" . htmlspecialchars($e->getMessage()) . "</p></div>";
            $error = true;
        }
    }

    // Drop & import
    if (!$error && !TESTMODE && isset($_REQUEST["dropandimport"])) {
        $fn_to_import = $_REQUEST["fn_to_import"] ?? '';
        if ($fn_to_import !== '' && preg_match("/(\.(sql|gz|zip))$/i", $fn_to_import)) {
            $mysqli->query("SET foreign_key_checks = 0");
            $tables_result = $mysqli->query("SHOW TABLES");
            $dropped = 0;
            if ($tables_result) { while ($trow = $tables_result->fetch_array()) { $mysqli->query("DROP TABLE IF EXISTS `{$trow[0]}`"); $dropped++; } }
            $mysqli->query("SET foreign_key_checks = 1");
            echo "<p class='success'>Dropped $dropped table(s). Starting import...</p>";
            $redirect_url = $_SERVER["PHP_SELF"] . "&start=1&fn=" . urlencode($fn_to_import) . "&foffset=0&totalqueries=0&delimiter=" . urlencode($delimiter);
            echo "<script>window.setTimeout('location.href=\"" . $redirect_url . "\"',1500);</script>";
            echo "<p class='centr'><a href='" . htmlspecialchars($redirect_url) . "'>Click here if not redirected</a></p>";
        } else {
            echo "<p class='error'>Invalid file.</p>";
        }
    }

    // List files
    if (!$error && !isset($_REQUEST["fn"]) && !isset($_REQUEST["dropandimport"]) && $filename == "") {
        if ($dirhandle = opendir($upload_dir)) {
            $files = [];
            while (false !== ($f = readdir($dirhandle))) $files[] = $f;
            closedir($dirhandle);
            $dirhead = false;
            sort($files);
            foreach ($files as $dirfile) {
                if ($dirfile != "." && $dirfile != ".." && $dirfile != basename(__FILE__) && preg_match("/\.(sql|gz|zip)$/i", $dirfile)) {
                    if (!$dirhead) {
                        echo "<table><tr><th>Filename</th><th>Size</th><th>Date</th><th>Type</th><th>Action</th><th>&nbsp;</th></tr>";
                        $dirhead = true;
                    }
                    $fsize = number_format(filesize($upload_dir . '/' . $dirfile));
                    $fdate = date("Y-m-d H:i:s", filemtime($upload_dir . '/' . $dirfile));
                    $ftype = preg_match("/\.gz$/i",  $dirfile) ? 'GZip' : (preg_match("/\.zip$/i", $dirfile) ? 'ZIP' : 'SQL');
                    $can_import = (preg_match("/\.gz$/i", $dirfile) && function_exists("gzopen")) || preg_match("/\.(sql|zip)$/i", $dirfile);
                    $self = $_SERVER["PHP_SELF"];
                    echo "<tr><td>" . htmlspecialchars($dirfile) . "</td><td class='right'>$fsize</td><td>$fdate</td><td>$ftype</td>";
                    if ($can_import) {
                        $direct_url = "$self&start=1&fn=" . urlencode($dirfile) . "&foffset=0&totalqueries=0&delimiter=" . urlencode($delimiter);
                        $drop_url   = "$self&dropandimport=1&fn_to_import=" . urlencode($dirfile);
                        echo "<td><a href='" . htmlspecialchars($direct_url) . "'>Import</a> into <b>$db_name</b> &nbsp;|&nbsp; <a href='" . htmlspecialchars($drop_url) . "' onclick=\"return confirm('DROP ALL TABLES in $db_name then import?');\">Drop &amp; Import</a></td>";
                    } else {
                        echo "<td>&nbsp;</td>";
                    }
                    echo "<td><a href='" . $self . "&delete=" . urlencode($dirfile) . "' onclick=\"return confirm('Delete " . htmlspecialchars($dirfile) . "?');\">Delete</a></td></tr>";
                }
            }
            if ($dirhead) echo "</table>";
            else echo "<p>No SQL/GZ/ZIP files found in: <i>" . htmlspecialchars($upload_dir) . "</i></p>";
        }
    }

    // Upload form
    if (!$error && !isset($_REQUEST["fn"]) && !isset($_REQUEST["dropandimport"]) && $filename == "") {
        $tmptest = $upload_dir . '/' . time() . ".tmp";
        if ($tf = @fopen($tmptest, "w")) { fclose($tf); unlink($tmptest);
            echo "<p>Upload a dump file (max " . round($upload_max_filesize/1024/1024) . " MB). Formats: .sql, .gz, .zip</p>";
            ?>
<form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" enctype="multipart/form-data">
<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo $upload_max_filesize; ?>">
<p>Dump file: <input type="file" name="dumpfile" accept=".sql,.gz,.zip" size="50"></p>
<p><input type="submit" name="uploadbutton" value="Upload"></p>
</form>
<?php
        } else {
            echo "<p>Upload disabled: <i>$upload_dir</i> is not writable. Upload via FTP instead.</p>";
        }
    }

    // Charset info
    if (!$error && !TESTMODE && !isset($_REQUEST["fn"]) && !isset($_REQUEST["dropandimport"])) {
        $res = $mysqli->query("SHOW VARIABLES LIKE 'character_set_connection'");
        if ($res && $row = $res->fetch_assoc()) echo "<p>MySQL charset: <i>{$row['Value']}</i></p>";
    }

    // Open file for import
    if (!$error && isset($_REQUEST["start"])) {
        $curfilename = $filename ?: (isset($_REQUEST["fn"]) ? urldecode($_REQUEST["fn"]) : "");
        $gzipmode = (bool)preg_match("/\.gz$/i", $curfilename);
        $zipmode  = (bool)preg_match("/\.zip$/i", $curfilename);
        $curfilename_real = null;
        if ($zipmode) {
            if (!class_exists('ZipArchive')) { echo "<p class='error'>ZipArchive not available.</p>"; $error = true; }
            else {
                $zip = new ZipArchive();
                if ($zip->open($upload_dir . '/' . $curfilename) === true) {
                    $sql_entry = '';
                    for ($zi = 0; $zi < $zip->numFiles; $zi++) { $zn = $zip->getNameIndex($zi); if (preg_match("/\.sql$/i",$zn)){$sql_entry=$zn;break;} }
                    if (!$sql_entry) { echo "<p class='error'>No .sql in ZIP.</p>"; $error = true; }
                    else {
                        $tmp_sql = $upload_dir . '/' . time() . '_bd_extracted.sql';
                        file_put_contents($tmp_sql, $zip->getFromName($sql_entry));
                        $zip->close();
                        $curfilename_real = $tmp_sql;
                        register_shutdown_function(function() use ($tmp_sql) { @unlink($tmp_sql); });
                    }
                } else { echo "<p class='error'>Can't open ZIP: $curfilename</p>"; $error = true; }
            }
        }
        if (!$error) {
            $open_path = $curfilename_real ?: $upload_dir . '/' . $curfilename;
            if ((!$gzipmode && !$file = @fopen($open_path, "r")) || ($gzipmode && !$file = @gzopen($open_path, "r"))) {
                echo "<p class='error'>Can't open " . htmlspecialchars($curfilename) . "</p>"; $error = true;
            } elseif ((!$gzipmode && fseek($file, 0, SEEK_END) == 0) || ($gzipmode && gzseek($file, 0) == 0)) {
                $filesize = $gzipmode ? gztell($file) : ftell($file);
            } else { echo "<p class='error'>Can't seek into file.</p>"; $error = true; }
        }
    }

    // Import session
    if (!$error && isset($_REQUEST["start"]) && isset($_REQUEST["foffset"]) && preg_match("/(\.(sql|gz|zip))$/i", $curfilename)) {
        if (!is_numeric($_REQUEST["start"]) || !is_numeric($_REQUEST["foffset"])) { echo "<p class='error'>Non-numeric start/foffset</p>"; $error = true; }
        else { $_REQUEST["start"] = floor($_REQUEST["start"]); $_REQUEST["foffset"] = floor($_REQUEST["foffset"]); }
        if (isset($_REQUEST["delimiter"])) $delimiter = $_REQUEST["delimiter"];

        if (!$error && !$gzipmode && $_REQUEST["foffset"] > $filesize) { echo "<p class='error'>foffset beyond EOF</p>"; $error = true; }
        if (!$error && ((!$gzipmode && fseek($file, $_REQUEST["foffset"]) != 0) || ($gzipmode && gzseek($file, $_REQUEST["foffset"]) != 0))) { echo "<p class='error'>Can't seek to offset.</p>"; $error = true; }

        if (!$error) {
            skin_open();
            echo "<p class='centr'>Processing: <b>" . htmlspecialchars($curfilename) . "</b></p><p class='smlcentr'>Starting from line: " . $_REQUEST["start"] . "</p>";
            skin_close();
            $query = ""; $queries = 0; $totalqueries = $_REQUEST["totalqueries"]; $linenumber = $_REQUEST["start"]; $querylines = 0; $inparents = false;
            while ($linenumber < $_REQUEST["start"] + $linespersession || $query != "") {
                $dumpline = "";
                while (!feof($file) && substr($dumpline,-1) != "\n" && substr($dumpline,-1) != "\r") {
                    $dumpline .= $gzipmode ? gzgets($file, DATA_CHUNK_LENGTH) : fgets($file, DATA_CHUNK_LENGTH);
                }
                if ($dumpline === "") break;
                if ($_REQUEST["foffset"] == 0) $dumpline = preg_replace('|^\xEF\xBB\xBF|','', $dumpline);
                $dumpline = str_replace(["\r\n","\r"], "\n", $dumpline);
                if (!$inparents && strpos($dumpline, "DELIMITER ") === 0) $delimiter = str_replace("DELIMITER ", "", trim($dumpline));
                if (!$inparents) {
                    $skipline = false;
                    foreach ($comment as $cv) { if (trim($dumpline) == "" || strpos(trim($dumpline), $cv) === 0) { $skipline = true; break; } }
                    if ($skipline) { $linenumber++; continue; }
                }
                $deslashed = str_replace("\\\\", "", $dumpline);
                $parents = substr_count($deslashed, $string_quotes) - substr_count($deslashed, "\\$string_quotes");
                if ($parents % 2 != 0) $inparents = !$inparents;
                $query .= $dumpline;
                if (!$inparents) $querylines++;
                if ($querylines > $max_query_lines) { echo "<p class='error'>Query exceeds $max_query_lines lines at line $linenumber.</p>"; $error = true; break; }
                if ((preg_match('/' . preg_quote($delimiter, '/') . '$/', trim($dumpline)) || $delimiter == '') && !$inparents) {
                    $query = substr(trim($query), 0, -strlen($delimiter));
                    if (!TESTMODE && !$mysqli->query($query)) {
                        echo "<p class='error'>Error at line $linenumber: " . trim(htmlspecialchars($dumpline)) . "</p><p>MySQL: " . $mysqli->error . "</p>"; $error = true; break;
                    }
                    $totalqueries++; $queries++; $query = ""; $querylines = 0;
                }
                $linenumber++;
            }
        }

        if (!$error) {
            $foffset = $gzipmode ? gztell($file) : ftell($file);
            if (!$foffset) { echo "<p class='error'>Can't read file pointer.</p>"; $error = true; }
        }

        skin_open();
        if (!$error) {
            $bytes_this = $foffset - $_REQUEST["foffset"]; $bytes_done = $foffset;
            if (!$gzipmode) {
                $bytes_togo = $filesize - $foffset; $pct_done = ceil($foffset/$filesize*100);
                $pct_bar = "<div style='height:12px;width:{$pct_done}%;background:#4f6ef7;border-radius:3px'></div>";
            } else { $bytes_togo = '?'; $pct_done = '?'; $pct_bar = '<i>N/A for gzip</i>'; }
            echo "
<center><table style='max-width:520px'>
<tr><th class='bg4'>&nbsp;</th><th class='bg4'>Session</th><th class='bg4'>Done</th><th class='bg4'>To go</th></tr>
<tr><th class='bg4'>Lines</th><td class='bg3'>" . ($linenumber - $_REQUEST['start']) . "</td><td class='bg3'>" . ($linenumber-1) . "</td><td class='bg3'>?</td></tr>
<tr><th class='bg4'>Queries</th><td class='bg3'>$queries</td><td class='bg3'>$totalqueries</td><td class='bg3'>?</td></tr>
<tr><th class='bg4'>Bytes</th><td class='bg3'>" . number_format($bytes_this) . "</td><td class='bg3'>" . number_format($bytes_done) . "</td><td class='bg3'>" . (is_numeric($bytes_togo) ? number_format($bytes_togo) : $bytes_togo) . "</td></tr>
<tr><th class='bg4'>% bar</th><td class='bgpctbar' colspan='3'>$pct_bar</td></tr>
</table></center>\n";
            if ($linenumber < $_REQUEST["start"] + $linespersession) {
                echo "<p class='successcentr'>Import completed successfully! You can now <a href='" . basename(__FILE__) . "'>return to the Migration Tool</a>.</p>";
                $error = true;
            } else {
                $next_url = $_SERVER["PHP_SELF"] . "&start=$linenumber&fn=" . urlencode($curfilename) . "&foffset=$foffset&totalqueries=$totalqueries&delimiter=" . urlencode($delimiter);
                if (!$ajax) echo "<script>window.setTimeout('location.href=\"$next_url\"',500+$delaypersession);</script>";
                echo "<noscript><p class='centr'><a href='" . htmlspecialchars($next_url) . "'>Continue from line $linenumber</a></p></noscript>";
                echo "<p class='centr'>Press <b><a href='" . $_SERVER['PHP_SELF'] . "'>STOP</a></b> to abort.</p>";
            }
        } else {
            echo "<p class='error'>Stopped on error.</p>";
        }
        skin_close();
    }

    if ($error) echo "<p class='centr'><a href='" . $_SERVER["PHP_SELF"] . "'>Start over</a></p>";
    if (isset($mysqli) && $mysqli) $mysqli->close();
    if (isset($file) && $file && !$gzipmode) fclose($file);
    elseif (isset($file) && $file && $gzipmode) gzclose($file);
    ?>
<p class="centr" style="color:#718096;font-size:11px">&copy; 2003-2015 Alexey Ozerov — BigDump v<?php echo VERSION; ?> embedded in WP Migration Tool</p>
</body></html>
<?php
    if ($ajax && isset($_REQUEST['start']) && isset($_REQUEST['ajaxrequest'])) {
        ob_end_clean();
        // AJAX XML response for BigDump
        header('Content-Type: application/xml');
        header('Cache-Control: no-cache');
        echo '<?xml version="1.0" encoding="UTF-8"?><root>';
        foreach (['linenumber'=>$linenumber,'foffset'=>$foffset,'fn'=>$curfilename,'totalqueries'=>$totalqueries,'delimiter'=>$delimiter] as $k=>$v) echo "<$k>$v</$k>";
        $fields = ['lines_this'=>0,'lines_done'=>0,'lines_togo'=>'?','lines_tota'=>'?','queries_this'=>0,'queries_done'=>0,'queries_togo'=>'?','queries_tota'=>'?','bytes_this'=>0,'bytes_done'=>0,'bytes_togo'=>'?','bytes_tota'=>'?','kbytes_this'=>0,'kbytes_done'=>0,'kbytes_togo'=>'?','kbytes_tota'=>'?','mbytes_this'=>0,'mbytes_done'=>0,'mbytes_togo'=>'?','mbytes_tota'=>'?','pct_this'=>0,'pct_done'=>0,'pct_togo'=>'?','pct_tota'=>100];
        $i=1; foreach ($fields as $v) echo "<elem$i>$v</elem$i>" . (++$i ? '' : '');
        echo "<elem_bar>" . htmlentities($pct_bar??'') . "</elem_bar></root>";
    } elseif ($ajax && isset($_REQUEST['start'])) {
        ob_flush();
        $next = $_SERVER["PHP_SELF"] . "&start=$linenumber&fn=" . urlencode($curfilename) . "&foffset=$foffset&totalqueries=$totalqueries&delimiter=" . urlencode($delimiter) . "&ajaxrequest=true";
        ?>
<script type="text/javascript">
var http_request=false;
function makeRequest(url){
  http_request=false;
  if(window.XMLHttpRequest){http_request=new XMLHttpRequest();if(http_request.overrideMimeType)http_request.overrideMimeType("text/xml");}
  else if(window.ActiveXObject){try{http_request=new ActiveXObject("Msxml2.XMLHTTP");}catch(e){try{http_request=new ActiveXObject("Microsoft.XMLHTTP");}catch(e){}}}
  if(!http_request){alert("Cannot create XMLHTTP");return;}
  http_request.onreadystatechange=function(){
    if(http_request.readyState!=4)return;
    if(http_request.status!=200){alert("Error: page unavailable");return;}
    var r=http_request.responseXML;
    if(!r||r.getElementsByTagName('root').length==0){document.open();document.write(http_request.responseText);document.close();return;}
    var ps=document.getElementsByTagName('p');if(ps[1])ps[1].innerHTML="Starting from line: "+r.getElementsByTagName('linenumber').item(0).firstChild.nodeValue;
    var tds=document.getElementsByTagName('td');for(var i=1;i<=24;i++){if(tds[i]&&tds[i].firstChild)tds[i].firstChild.data=r.getElementsByTagName('elem'+i).item(0).firstChild.nodeValue;}
    window.setTimeout(function(){makeRequest("<?php echo addslashes($next); ?>");},500+<?php echo $delaypersession; ?>);
  };
  http_request.open("GET",url,true);http_request.send(null);
}
window.setTimeout(function(){makeRequest("<?php echo addslashes($next); ?>");},500+<?php echo $delaypersession; ?>);
</script>
<?php
    } else { if ($ajax) ob_flush(); }
    exit;
}

// ============================================================
// ACCIONES AJAX (requieren auth)
// ============================================================
// Download no requiere sesión activa — token + nombre de archivo como auth
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $token    = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
    $filename = basename($_GET['file'] ?? '');
    $dl_dir   = __DIR__ . '/.migrate_exports';
    // Validar: el token debe estar en el nombre del archivo
    if (!$token || !$filename || strpos($filename, $token) === false) {
        http_response_code(403); echo 'Invalid token'; exit;
    }
    $found = $dl_dir . '/' . $filename;
    if (!file_exists($found) || !preg_match('/\.(zip|tar\.gz|sql\.gz|sql)$/i', $filename)) {
        http_response_code(404); echo 'File not found'; exit;
    }
    $ext  = strtolower(pathinfo($found, PATHINFO_EXTENSION));
    $mime = match($ext) {
        'gz'  => 'application/gzip',
        'zip' => 'application/zip',
        'sql' => 'text/plain',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($found));
    readfile($found);
    exit;
}

if ($authenticated && isset($_GET['action'])) {
    $action = $_GET['action'];

    // --- RELOAD WP-CONFIG ---
    if ($action === 'get_wpconfig') {
        echo json_encode([
            'ok'     => true,
            'host'   => $db['host'],
            'name'   => $db['name'],
            'user'   => $db['user'],
            'pass'   => $db['pass'],
            'prefix' => $db['prefix'],
        ]);
        exit;
    }

    // --- TEST DB CONNECTION ---
    if ($action === 'test_db') {
        $test_db = [
            'host'    => $_POST['db_host']    ?? $db['host'],
            'name'    => $_POST['db_name']    ?? $db['name'],
            'user'    => $_POST['db_user']    ?? $db['user'],
            'pass'    => $_POST['db_pass']    ?? $db['pass'],
            'prefix'  => $_POST['db_prefix']  ?? $db['prefix'],
            'charset' => 'utf8mb4',
        ];
        $r = try_db_connect($test_db);
        if ($r['ok']) {
            $url = detect_site_url($test_db);
            echo json_encode(['ok' => true, 'siteurl' => $url]);
        } else {
            echo json_encode(['ok' => false, 'error' => $r['error']]);
        }
        exit;
    }

    // --- EXPORT DB ---
    if ($action === 'export_db') {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        $source_url = trim($_POST['source_url'] ?? '');
        $target_url = trim($_POST['target_url'] ?? '');
        $export_db  = [
            'host'    => $_POST['db_host']    ?? $db['host'],
            'name'    => $_POST['db_name']    ?? $db['name'],
            'user'    => $_POST['db_user']    ?? $db['user'],
            'pass'    => $_POST['db_pass']    ?? $db['pass'],
            'prefix'  => $_POST['db_prefix']  ?? $db['prefix'],
            'charset' => 'utf8mb4',
        ];
        $dl_dir   = __DIR__ . '/.migrate_exports';
        if (!is_dir($dl_dir)) mkdir($dl_dir, 0750, true);
        $filename = 'db_export_' . date('Ymd_His') . '.sql';
        $filepath = $dl_dir . '/' . $filename;
        $zipname  = $filename . '.gz';
        $zippath  = $dl_dir . '/' . $zipname;

        $exported = false;
        $method   = '';
        $error    = '';

        // Método 1: mysqldump CLI
        $mysqldump = check_cmd('mysqldump');
        if (!$exported && $mysqldump) {
            $pass_flag = $export_db['pass'] !== '' ? '--password=' . escapeshellarg($export_db['pass']) : '--password=""';
            $cmd = sprintf(
                '%s --host=%s --user=%s %s --default-character-set=utf8mb4 --single-transaction --routines --triggers %s > %s 2>&1',
                escapeshellarg($mysqldump),
                escapeshellarg($export_db['host']),
                escapeshellarg($export_db['user']),
                $pass_flag,
                escapeshellarg($export_db['name']),
                escapeshellarg($filepath)
            );
            exec($cmd, $out_exec, $ret);
            if ($ret === 0 && file_exists($filepath) && filesize($filepath) > 100) {
                $exported = true;
                $method   = 'mysqldump CLI';
            } else {
                $error = implode("\n", $out_exec);
            }
        }

        // Método 2: PDO puro — escribe directo al archivo, sin acumular en memoria
        if (!$exported) {
            $r = try_db_connect($export_db);
            if ($r['ok']) {
                $pdo = $r['pdo'];
                $pdo->exec("SET NAMES utf8mb4");
                $fh = fopen($filepath, 'w');
                fwrite($fh, "-- WordPress DB Export\n-- Date: " . date('Y-m-d H:i:s') . "\n-- Method: PDO PHP\n\n");
                fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET NAMES utf8mb4;\n\n");

                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                    fwrite($fh, "\nDROP TABLE IF EXISTS `$table`;\n");
                    fwrite($fh, $create['Create Table'] . ";\n\n");

                    $rows  = $pdo->query("SELECT * FROM `$table`");
                    $chunk = [];
                    $cols  = null;
                    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                        if ($cols === null) $cols = '`' . implode('`,`', array_keys($row)) . '`';
                        $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row));
                        $chunk[] = '(' . implode(',', $vals) . ')';
                        if (count($chunk) >= 100) {
                            fwrite($fh, "INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $chunk) . ";\n");
                            $chunk = [];
                        }
                    }
                    if (!empty($chunk) && $cols) {
                        fwrite($fh, "INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $chunk) . ";\n");
                    }
                    fwrite($fh, "\n");
                }
                fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
                fclose($fh);
                $exported = true;
                $method   = 'PDO PHP';
            } else {
                $error .= ' | PDO: ' . $r['error'];
            }
        }

        if (!$exported) {
            echo json_encode(['ok' => false, 'error' => 'Could not export DB: ' . $error]);
            exit;
        }

        // Reemplazar URLs en el SQL (serialized-safe)
        if ($source_url && $target_url && $source_url !== $target_url) {
            $content = file_get_contents($filepath);

            // 1. Reemplazar en strings serializados primero (actualiza s:LENGTH correctamente)
            $content = preg_replace_callback(
                '/s:(\d+):"((?:[^"\\\\]|\\\\.)*)"/s',
                function($m) use ($source_url, $target_url) {
                    if (strpos($m[2], $source_url) === false) return $m[0];
                    $new_str = str_replace($source_url, $target_url, $m[2]);
                    return 's:' . strlen($new_str) . ':"' . $new_str . '"';
                },
                $content
            );

            // 2. Reemplazar el resto (valores planos: siteurl, home, guid, etc.)
            $content = str_replace($source_url, $target_url, $content);

            file_put_contents($filepath, $content);
        }

        // Split into size-limited .zip parts when requested. Each part is cut at a
        // full-statement boundary and compressed to stay under the chosen limit.
        $split      = !empty($_POST['split']) && $_POST['split'] !== '0';
        $max_part_mb = (int)($_POST['max_part_size'] ?? 0);
        if ($split && $max_part_mb >= 1 && $max_part_mb <= 10) {
            $dl_token = bin2hex(random_bytes(6));
            $base     = 'db_' . date('Ymd_His') . '_' . $dl_token;
            $res = split_sql_to_zip_parts($filepath, $dl_dir, $base, $max_part_mb * 1024 * 1024);
            @unlink($filepath); // raw .sql no longer needed
            if (!$res['ok']) { echo json_encode(['ok' => false, 'error' => 'Split failed: ' . $res['error']]); exit; }
            $parts = [];
            foreach ($res['parts'] as $i => $p) {
                $name = basename($p);
                $parts[] = [
                    'n'    => $i + 1,
                    'name' => $name,
                    'size' => human_size(filesize($p)),
                    'url'  => basename(__FILE__) . '?action=download&token=' . $dl_token . '&file=' . urlencode($name),
                ];
            }
            echo json_encode([
                'ok'       => true,
                'method'   => $method,
                'split'    => true,
                'count'    => $res['count'],
                'max_mb'   => $max_part_mb,
                'parts'    => $parts,
            ]);
            exit;
        }

        // Comprimir a .gz
        $gz_ok = false;
        if (function_exists('gzopen')) {
            $gz = gzopen($zippath, 'wb9');
            $fh = fopen($filepath, 'rb');
            while (!feof($fh)) gzwrite($gz, fread($fh, 65536));
            fclose($fh);
            gzclose($gz);
            if (file_exists($zippath) && filesize($zippath) > 0) $gz_ok = true;
        }
        if (!$gz_ok) $zippath = $filepath; // servir .sql si no hay gzip

        // Renombrar con token aleatorio dentro de la misma carpeta
        $dl_token   = bin2hex(random_bytes(6));
        $final_name = 'db_' . date('Ymd_His') . '_' . $dl_token . ($gz_ok ? '.sql.gz' : '.sql');
        $final_path = $dl_dir . '/' . $final_name;
        rename($gz_ok ? $zippath : $filepath, $final_path);
        if ($gz_ok && file_exists($filepath)) unlink($filepath);

        echo json_encode([
            'ok'       => true,
            'method'   => $method,
            'filename' => $final_name,
            'size'     => human_size(filesize($final_path)),
            'url'      => basename(__FILE__) . '?action=download&token=' . $dl_token . '&file=' . urlencode($final_name),
        ]);
        exit;
    }

    // --- EXPORT ZIP HOSTING ---
    if ($action === 'export_zip') {
        $source_url = trim($_POST['source_url'] ?? '');
        $target_url = trim($_POST['target_url'] ?? '');
        $root       = rtrim($abspath, '/');
        $dl_token   = bin2hex(random_bytes(6));
        $dl_dir     = __DIR__ . '/.migrate_exports';
        if (!is_dir($dl_dir)) mkdir($dl_dir, 0750);
        $zip_name   = 'hosting_' . date('Ymd_His') . '_' . $dl_token . '.zip';
        $zip_path   = $dl_dir . '/' . $zip_name;

        // Excluir paths que no deben ir en el zip
        $excludes = [
            realpath($dl_dir),
            realpath(__DIR__ . '/.migrate_config'),
            realpath(__DIR__ . '/.backups'),
            realpath($root . '/wp-content/cache'),
            realpath($root . '/wp-content/et-cache'),
            realpath($root . '/wp-content/ai1wm-backups'),
        ];

        $zipped   = false;
        $method   = '';

        // Método 1: zip CLI
        $zip_cmd = check_cmd('zip');
        if (!$zipped && $zip_cmd) {
            $excludes_str = '';
            foreach (array_filter($excludes) as $ex) {
                $excludes_str .= ' --exclude=' . escapeshellarg($ex . '/*');
            }
            $cmd = sprintf(
                'cd %s && %s -r %s . %s 2>&1',
                escapeshellarg($root),
                escapeshellarg($zip_cmd),
                escapeshellarg($zip_path),
                $excludes_str
            );
            exec($cmd, $out_exec, $ret);
            if ($ret === 0 && file_exists($zip_path) && filesize($zip_path) > 1000) {
                $zipped = true;
                $method = 'zip CLI';
            }
        }

        // Método 2: ZipArchive PHP
        if (!$zipped && class_exists('ZipArchive')) {
            set_time_limit(600);
            $zip = new ZipArchive();
            if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($files as $file) {
                    if (!$file->isFile()) continue;
                    $real = $file->getRealPath();
                    // Excluir paths
                    $skip = false;
                    foreach (array_filter($excludes) as $ex) {
                        if (strpos($real, $ex) === 0) { $skip = true; break; }
                    }
                    if ($skip) continue;
                    $relative = ltrim(str_replace($root, '', $real), '/\\');
                    $zip->addFile($real, $relative);
                }
                $zip->close();
                if (file_exists($zip_path) && filesize($zip_path) > 1000) {
                    $zipped = true;
                    $method = 'ZipArchive PHP';
                }
            }
        }

        // Método 3: tar.gz CLI (fallback en Linux)
        if (!$zipped && check_cmd('tar')) {
            $tar_path = $dl_dir . '/hosting_' . date('Ymd_His') . '_' . $dl_token . '.tar.gz';
            $exc_str  = '';
            foreach (array_filter($excludes) as $ex) {
                $exc_str .= ' --exclude=' . escapeshellarg(str_replace($root . '/', '', $ex));
            }
            $cmd = sprintf('cd %s && tar -czf %s . %s 2>&1', escapeshellarg($root), escapeshellarg($tar_path), $exc_str);
            exec($cmd, $out_exec, $ret);
            if ($ret === 0 && file_exists($tar_path) && filesize($tar_path) > 1000) {
                $zip_path = $tar_path;
                $zip_name = 'hosting_' . $dl_token . '.tar.gz';
                $zipped   = true;
                $method   = 'tar.gz CLI';
            }
        }

        if (!$zipped) {
            echo json_encode(['ok' => false, 'error' => 'No method available to create zip. Try downloading files manually via FTP/cPanel.']);
            exit;
        }

        echo json_encode([
            'ok'       => true,
            'method'   => $method,
            'filename' => $zip_name,
            'size'     => human_size(filesize($zip_path)),
            'url'      => basename(__FILE__) . '?action=download&token=' . $dl_token . '&file=' . urlencode($zip_name),
        ]);
        exit;
    }

    // --- DOWNLOAD ---
    if ($action === 'download') {
        $token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
        $type  = $_GET['type'] ?? 'db';
        $dl_dir = __DIR__ . '/.migrate_exports';
        $prefix = $type === 'hosting' ? 'hosting_' : 'db_';
        $found  = null;
        foreach (glob($dl_dir . '/' . $prefix . $token . '.*') as $f) {
            $found = $f; break;
        }
        if (!$found || !file_exists($found)) {
            http_response_code(404); echo 'File not found'; exit;
        }
        $ext = pathinfo($found, PATHINFO_EXTENSION);
        $mime = match($ext) {
            'gz'  => 'application/gzip',
            'zip' => 'application/zip',
            'sql' => 'text/plain',
            default => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . basename($found) . '"');
        header('Content-Length: ' . filesize($found));
        readfile($found);
        // Limpiar después de 1h (lazy cleanup)
        if (filemtime($found) < time() - 3600) unlink($found);
        exit;
    }

    // --- IMPORT DB (destino) ---
    if ($action === 'import_db') {
        // Verificar URL del destino
        $dest_url = trim($_POST['dest_url'] ?? '');
        $cur_url  = current_url();
        if ($dest_url && rtrim($dest_url, '/') !== rtrim($cur_url, '/')) {
            echo json_encode(['ok' => false, 'error' => "URL mismatch: expected '$dest_url', current '$cur_url'. Check your target URL."]);
            exit;
        }

        $import_db = [
            'host'    => trim($_POST['db_host']   ?? $db['host']),
            'name'    => trim($_POST['db_name']   ?? $db['name']),
            'user'    => trim($_POST['db_user']   ?? $db['user']),
            'pass'    => trim($_POST['db_pass']   ?? $db['pass']),
            'prefix'  => trim($_POST['db_prefix'] ?? $db['prefix']),
            'charset' => 'utf8mb4',
        ];

        // Test conexión
        $r = try_db_connect($import_db);
        if (!$r['ok']) {
            echo json_encode(['ok' => false, 'error' => 'DB connection failed: ' . $r['error']]);
            exit;
        }

        // Recibir archivo SQL: upload o archivo en servidor
        $server_sql = isset($_POST['server_file']) ? basename($_POST['server_file']) : '';
        if ($server_sql) {
            $server_sql_path = rtrim($abspath, '/') . '/' . $server_sql;
            if (!file_exists($server_sql_path) || !preg_match('/\.(sql|sql\.gz|gz|zip)$/i', $server_sql)) {
                echo json_encode(['ok' => false, 'error' => 'Server file not found or invalid.']);
                exit;
            }
            $tmp  = $server_sql_path;
            $name = $server_sql;
        } elseif (!empty($_FILES['sql_file']['tmp_name'])) {
            $tmp  = $_FILES['sql_file']['tmp_name'];
            $name = $_FILES['sql_file']['name'];
        } else {
            echo json_encode(['ok' => false, 'error' => 'No SQL file provided.']);
            exit;
        }

        // Decompress / extract to a plain .sql path.
        $sql_path = $tmp;
        $tmp_sql  = null; // extracted temp file to clean up afterwards
        if (preg_match('/\.zip$/i', $name)) {
            if (!class_exists('ZipArchive')) { echo json_encode(['ok' => false, 'error' => 'ZipArchive not available to read .zip part.']); exit; }
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) { echo json_encode(['ok' => false, 'error' => 'Could not open .zip file.']); exit; }
            $entry = '';
            for ($zi = 0; $zi < $zip->numFiles; $zi++) {
                $zn = $zip->getNameIndex($zi);
                if (preg_match('/\.sql$/i', $zn)) { $entry = $zn; break; }
            }
            if ($entry === '') { $zip->close(); echo json_encode(['ok' => false, 'error' => 'No .sql entry inside the .zip.']); exit; }
            $tmp_sql = sys_get_temp_dir() . '/import_' . bin2hex(random_bytes(4)) . '.sql';
            file_put_contents($tmp_sql, $zip->getFromName($entry));
            $zip->close();
            $sql_path = $tmp_sql;
        } elseif (preg_match('/\.gz$/', $name) && function_exists('gzopen')) {
            $sql_path = $tmp . '.sql';
            $tmp_sql  = $sql_path;
            $gz = gzopen($tmp, 'rb');
            $fh = fopen($sql_path, 'wb');
            while (!gzeof($gz)) fwrite($fh, gzread($gz, 65536));
            gzclose($gz); fclose($fh);
        }

        $imported = false;
        $method   = '';

        // Método 1: mysql CLI
        $mysql_cmd = check_cmd('mysql');
        if (!$imported && $mysql_cmd) {
            $cmd = sprintf(
                '%s --host=%s --user=%s --password=%s %s < %s 2>&1',
                escapeshellarg($mysql_cmd),
                escapeshellarg($import_db['host']),
                escapeshellarg($import_db['user']),
                escapeshellarg($import_db['pass']),
                escapeshellarg($import_db['name']),
                escapeshellarg($sql_path)
            );
            exec($cmd, $out_exec, $ret);
            if ($ret === 0) { $imported = true; $method = 'mysql CLI'; }
        }

        // Método 2: PDO chunk-by-chunk
        if (!$imported) {
            $r = try_db_connect($import_db);
            if ($r['ok']) {
                $pdo = $r['pdo'];
                $pdo->exec("SET FOREIGN_KEY_CHECKS=0; SET NAMES utf8mb4;");
                $content = file_get_contents($sql_path);
                // Dividir por ; respetando strings
                $queries = preg_split('/;\s*\n/', $content);
                $errors  = [];
                foreach ($queries as $q) {
                    $q = trim($q);
                    if (!$q || strpos($q, '--') === 0) continue;
                    try { $pdo->exec($q); }
                    catch (PDOException $e) { $errors[] = $e->getMessage(); }
                }
                $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");
                $imported = true;
                $method   = 'PDO PHP' . (count($errors) ? ' (with ' . count($errors) . ' warnings)' : '');
            }
        }

        if (!$imported) {
            echo json_encode(['ok' => false, 'error' => 'Could not import DB. Try BigDump for large files.']);
            exit;
        }

        // Verificar URL en DB tras importar
        $db_url = detect_site_url($import_db);
        $url_ok = rtrim($db_url, '/') === rtrim($cur_url, '/');

        echo json_encode([
            'ok'       => $imported,
            'method'   => $method,
            'db_url'   => $db_url,
            'cur_url'  => $cur_url,
            'url_match'=> $url_ok,
        ]);
        exit;
    }

    // --- LIST EXPORTS ---
    if ($action === 'list_exports') {
        $dl_dir = __DIR__ . '/.migrate_exports';
        $files  = [];
        foreach (glob($dl_dir . '/*.{zip,gz,sql}', GLOB_BRACE) ?: [] as $f) {
            $name   = basename($f);
            $token  = '';
            if (preg_match('/_([a-f0-9]{12})\./', $name, $m)) $token = $m[1];
            $files[] = [
                'name'    => $name,
                'size'    => human_size(filesize($f)),
                'bytes'   => filesize($f),
                'date'    => date('Y-m-d H:i:s', filemtime($f)),
                'token'   => $token,
                'dl_url'  => basename(__FILE__) . '?action=download&token=' . $token . '&file=' . urlencode($name),
            ];
        }
        usort($files, fn($a,$b) => $b['bytes'] <=> $a['bytes']);
        echo json_encode(['ok' => true, 'files' => $files]);
        exit;
    }

    // --- DELETE EXPORT ---
    if ($action === 'delete_export') {
        $name   = basename($_POST['file'] ?? '');
        $dl_dir = __DIR__ . '/.migrate_exports';
        $path   = $dl_dir . '/' . $name;
        if (!$name || !preg_match('/\.(zip|tar\.gz|sql\.gz|sql)$/i', $name) || !file_exists($path)) {
            echo json_encode(['ok' => false, 'error' => 'File not found.']); exit;
        }
        echo json_encode(['ok' => @unlink($path), 'error' => 'Could not delete.']);
        exit;
    }

    // --- SCAN FILES ---
    if ($action === 'scan_files') {
        $patterns = ['*.zip', '*.tar.gz', '*.sql.gz', '*.sql'];
        $found = [];
        foreach ($patterns as $pat) {
            foreach (glob(rtrim($abspath, '/') . '/' . $pat) ?: [] as $f) {
                $name = basename($f);
                // skip our own exports and tool file
                if (strpos($name, 'migrate_') === 0) continue;
                $ext = '';
                if (preg_match('/\.(zip|tar\.gz|sql\.gz|sql)$/i', $name, $m)) $ext = strtolower($m[1]);
                $found[] = [
                    'name' => $name,
                    'size' => human_size(filesize($f)),
                    'ext'  => $ext,
                    'path' => $f,
                ];
            }
        }
        echo json_encode(['ok' => true, 'files' => $found]);
        exit;
    }

    // --- EXTRACT ZIP ---
    if ($action === 'extract_zip') {
        @set_time_limit(0);
        // Puede venir como archivo subido o como nombre de archivo ya en el servidor
        $server_file = isset($_POST['server_file']) ? basename($_POST['server_file']) : '';
        if ($server_file) {
            $server_path = rtrim($abspath, '/') . '/' . $server_file;
            if (!file_exists($server_path) || !preg_match('/\.(zip|tar\.gz|sql\.gz|sql)$/i', $server_file)) {
                echo json_encode(['ok' => false, 'error' => 'File not found or invalid type.']);
                exit;
            }
            $tmp  = $server_path;
            $name = $server_file;
        } elseif (!empty($_FILES['zip_file']['tmp_name'])) {
            $tmp  = $_FILES['zip_file']['tmp_name'];
            $name = $_FILES['zip_file']['name'];
        } else {
            echo json_encode(['ok' => false, 'error' => 'No file specified.']);
            exit;
        }
        $dest = rtrim($abspath, '/');

        $extracted = false;
        $method    = '';

        // Método 1: unzip CLI
        if (!$extracted && check_cmd('unzip')) {
            $cmd = sprintf('unzip -o %s -d %s 2>&1', escapeshellarg($tmp), escapeshellarg($dest));
            exec($cmd, $out_exec, $ret);
            if ($ret === 0) { $extracted = true; $method = 'unzip CLI'; }
        }

        // Método 2: tar CLI (para .tar.gz)
        if (!$extracted && preg_match('/\.tar\.gz$/', $name) && check_cmd('tar')) {
            $cmd = sprintf('tar -xzf %s -C %s 2>&1', escapeshellarg($tmp), escapeshellarg($dest));
            exec($cmd, $out_exec, $ret);
            if ($ret === 0) { $extracted = true; $method = 'tar CLI'; }
        }

        // Método 3: ZipArchive PHP
        if (!$extracted && class_exists('ZipArchive')) {
            set_time_limit(600);
            $zip = new ZipArchive();
            if ($zip->open($tmp) === true) {
                $zip->extractTo($dest);
                $zip->close();
                $extracted = true;
                $method    = 'ZipArchive PHP';
            }
        }

        echo json_encode(['ok' => $extracted, 'method' => $method]);
        exit;
    }

    // --- CLEAR CACHE ---
    if ($action === 'clear_cache') {
        $wp_root    = rtrim($abspath, '/');
        $cache_dirs = [
            $wp_root . '/wp-content/et-cache',
            $wp_root . '/wp-content/cache',
        ];
        $deleted = 0;
        $errors  = 0;
        foreach ($cache_dirs as $dir) {
            if (!is_dir($dir)) continue;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                if ($f->isFile() && $f->getFilename() !== '.cache-cleared-at') {
                    if (@unlink($f->getRealPath())) $deleted++;
                    else $errors++;
                } elseif ($f->isDir()) {
                    @rmdir($f->getRealPath());
                }
            }
        }
        @file_put_contents($wp_root . '/wp-content/et-cache/.cache-cleared-at', time());
        echo json_encode(['ok' => true, 'deleted' => $deleted, 'errors' => $errors]);
        exit;
    }

    // --- SCAN MEDIA URLS ---
    if ($action === 'scan_media_urls') {
        $scan_db = [
            'host'    => $_POST['db_host']   ?? $db['host'],
            'name'    => $_POST['db_name']   ?? $db['name'],
            'user'    => $_POST['db_user']   ?? $db['user'],
            'pass'    => $_POST['db_pass']   ?? $db['pass'],
            'prefix'  => $_POST['db_prefix'] ?? $db['prefix'],
            'charset' => 'utf8mb4',
        ];
        $r = try_db_connect($scan_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo    = $r['pdo'];
        $prefix = $scan_db['prefix'];
        $like   = '%http%://%.%/wp-content%';

        $tables = [
            ['table' => $prefix . 'posts',    'col' => 'post_content', 'id' => 'ID'],
            ['table' => $prefix . 'postmeta', 'col' => 'meta_value',   'id' => 'meta_id'],
            ['table' => $prefix . 'options',  'col' => 'option_value', 'id' => 'option_id'],
        ];

        $total   = 0;
        $results = [];
        $samples = [];

        foreach ($tables as $t) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$t['table']}` WHERE `{$t['col']}` LIKE ?");
                $stmt->execute([$like]);
                $count = (int)$stmt->fetchColumn();
                if ($count > 0) {
                    $results[] = ['table' => $t['table'], 'col' => $t['col'], 'count' => $count];
                    $total    += $count;
                    $ss = $pdo->prepare("SELECT `{$t['col']}` FROM `{$t['table']}` WHERE `{$t['col']}` LIKE ? LIMIT 3");
                    $ss->execute([$like]);
                    while ($row = $ss->fetch(PDO::FETCH_NUM)) {
                        preg_match_all('/https?:\/\/[^\/\s\'"<>]+\/wp-content\/[^\s\'"<>]{1,80}/', $row[0], $m);
                        foreach (array_slice($m[0], 0, 2) as $url) $samples[] = $url;
                    }
                }
            } catch (Exception $e) {}
        }

        echo json_encode([
            'ok'      => true,
            'total'   => $total,
            'tables'  => $results,
            'samples' => array_values(array_unique(array_slice($samples, 0, 10))),
        ]);
        exit;
    }

    // --- FIX MEDIA URLS ---
    if ($action === 'fix_media_urls') {
        $fix_db = [
            'host'    => $_POST['db_host']   ?? $db['host'],
            'name'    => $_POST['db_name']   ?? $db['name'],
            'user'    => $_POST['db_user']   ?? $db['user'],
            'pass'    => $_POST['db_pass']   ?? $db['pass'],
            'prefix'  => $_POST['db_prefix'] ?? $db['prefix'],
            'charset' => 'utf8mb4',
        ];
        $r = try_db_connect($fix_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo     = $r['pdo'];
        $prefix  = $fix_db['prefix'];
        $like    = '%http%://%.%/wp-content%';
        // Capture the full absolute asset URL so we can verify the target file
        // exists on disk before rewriting it to a relative /wp-content/ path.
        // Group 1 = everything from /wp-content/ onwards (the kept relative path).
        $pattern = '#https?://[^/\s\'"<>]+(/wp-content/[^\s\'"<>()\[\]\\\\]*)#i';
        $root    = rtrim($abspath, '/');

        // Rewrite only when the resulting relative path points to a real file.
        // When it does not, leave the URL untouched and count it as unverified so
        // we never create a relative link to a missing asset.
        $unverified = 0;
        $rewrite = function ($str) use ($pattern, $root, &$unverified) {
            return preg_replace_callback($pattern, function ($m) use ($root, &$unverified) {
                $rel = preg_replace('/[?#].*$/', '', $m[1]); // strip query/hash for the disk check
                if (is_file($root . $rel)) {
                    return $m[1]; // keep original query/hash by returning full captured path
                }
                $unverified++;
                return $m[0]; // leave the absolute URL as-is
            }, $str);
        };

        $tables = [
            ['table' => $prefix . 'posts',    'col' => 'post_content', 'id' => 'ID'],
            ['table' => $prefix . 'postmeta', 'col' => 'meta_value',   'id' => 'meta_id'],
            ['table' => $prefix . 'options',  'col' => 'option_value', 'id' => 'option_id'],
        ];

        $updated = 0;
        $errors  = 0;

        foreach ($tables as $t) {
            try {
                $stmt = $pdo->prepare("SELECT `{$t['id']}`, `{$t['col']}` FROM `{$t['table']}` WHERE `{$t['col']}` LIKE ?");
                $stmt->execute([$like]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $old = $row[$t['col']];
                    // Fix serialized strings first (recalculate s:LENGTH)
                    $new = preg_replace_callback(
                        '/s:(\d+):"((?:[^"\\\\]|\\\\.)*)"/s',
                        function($m) use ($rewrite) {
                            $str = $rewrite($m[2]);
                            if ($str === $m[2]) return $m[0];
                            return 's:' . strlen($str) . ':"' . $str . '"';
                        },
                        $old
                    );
                    // Fix remaining plain occurrences
                    $new = $rewrite($new);
                    if ($new !== $old) {
                        $upd = $pdo->prepare("UPDATE `{$t['table']}` SET `{$t['col']}` = ? WHERE `{$t['id']}` = ?");
                        if ($upd->execute([$new, $row[$t['id']]])) $updated++;
                        else $errors++;
                    }
                }
            } catch (Exception $e) { $errors++; }
        }

        echo json_encode(['ok' => true, 'updated' => $updated, 'errors' => $errors, 'unverified' => $unverified]);
        exit;
    }

    // --- SCAN LINKED DOMAINS ---
    // Aggregates every absolute http(s) domain linked in content, excluding the
    // current host, so the user can decide which legacy/external domains to rewrite.
    if ($action === 'scan_domains') {
        $scan_db = [
            'host'    => $_POST['db_host']   ?? $db['host'],
            'name'    => $_POST['db_name']   ?? $db['name'],
            'user'    => $_POST['db_user']   ?? $db['user'],
            'pass'    => $_POST['db_pass']   ?? $db['pass'],
            'prefix'  => $_POST['db_prefix'] ?? $db['prefix'],
            'charset' => 'utf8mb4',
        ];
        $r = try_db_connect($scan_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo    = $r['pdo'];
        $prefix = $scan_db['prefix'];

        // Current host (scheme-less, www-less) is excluded from the results.
        $cur_host = preg_replace('#^www\.#', '', strtolower(parse_url(current_url(), PHP_URL_HOST) ?? ''));

        $tables = [
            ['table' => $prefix . 'posts',    'col' => 'post_content'],
            ['table' => $prefix . 'postmeta', 'col' => 'meta_value'],
            ['table' => $prefix . 'options',  'col' => 'option_value'],
        ];
        $url_rx = '#https?://([a-z0-9.-]+)(/[^\s"\'<>()\[\]\\\\]*)?#i';

        $domains = []; // host => ['count'=>n, 'samples'=>[], 'asset_paths'=>[path=>true]]
        foreach ($tables as $t) {
            try {
                $stmt = $pdo->query("SELECT `{$t['col']}` FROM `{$t['table']}` WHERE `{$t['col']}` LIKE 'http%' OR `{$t['col']}` LIKE '%http%'");
                while (($val = $stmt->fetchColumn()) !== false) {
                    if (strpos($val, 'http') === false) continue;
                    if (!preg_match_all($url_rx, $val, $m, PREG_SET_ORDER)) continue;
                    foreach ($m as $hit) {
                        $host = preg_replace('#^www\.#', '', strtolower($hit[1]));
                        if ($host === '' || $host === $cur_host) continue;
                        if (!isset($domains[$host])) $domains[$host] = ['count' => 0, 'samples' => [], 'asset_paths' => []];
                        $domains[$host]['count']++;
                        $path = $hit[2] ?? '';
                        if (count($domains[$host]['samples']) < 3) {
                            $full = rtrim($hit[0], '.,;)"\'');
                            if (strlen($full) > 90) $full = substr($full, 0, 90) . '…';
                            $domains[$host]['samples'][$full] = true;
                        }
                        // Keep a bounded set of paths that point to real files (have an
                        // extension) so we can verify strip candidates on disk later.
                        if ($path !== '' && count($domains[$host]['asset_paths']) < 300
                            && preg_match('#\.[a-z0-9]{2,5}($|\?)#i', $path)) {
                            $clean = preg_replace('/[?#].*$/', '', $path);
                            $domains[$host]['asset_paths'][$clean] = true;
                        }
                    }
                }
            } catch (Exception $e) {}
        }

        // For each domain, probe strip candidates and verify them on disk.
        // A candidate is a leading path segment to drop (e.g. "/wordpress", "/test").
        // We recommend the candidate that resolves the most existing files.
        $root = rtrim($abspath, '/');
        $recommend_strip = function (array $asset_paths) use ($root) {
            if (!$asset_paths) return ['strip' => '', 'verified' => 0, 'checked' => 0];
            $paths = array_keys($asset_paths);
            // Build candidate strips: "" plus each distinct first segment, and
            // first-two segments (covers nested like /old/site).
            $cands = ['' => true];
            foreach ($paths as $p) {
                $seg = explode('/', ltrim($p, '/'));
                if (isset($seg[0]) && $seg[0] !== '') $cands['/' . $seg[0]] = true;
                if (isset($seg[1]) && $seg[1] !== '') $cands['/' . $seg[0] . '/' . $seg[1]] = true;
            }
            $sample = array_slice($paths, 0, 60); // bound disk hits
            $best = ['strip' => '', 'verified' => 0, 'checked' => count($sample)];
            foreach (array_keys($cands) as $strip) {
                $hits = 0;
                foreach ($sample as $p) {
                    if ($strip !== '' && strpos($p, $strip . '/') !== 0 && $p !== $strip) continue;
                    $rel = $strip !== '' ? substr($p, strlen($strip)) : $p;
                    if ($rel === '' || $rel[0] !== '/') $rel = '/' . ltrim($rel, '/');
                    if (is_file($root . $rel)) $hits++;
                }
                // Prefer more verified hits; tie-break favors a non-empty strip
                // (removing a stale subfolder) only when it matches at least as well.
                if ($hits > $best['verified'] || ($hits === $best['verified'] && $strip !== '' && $best['strip'] === '' && $hits > 0)) {
                    $best = ['strip' => $strip, 'verified' => $hits, 'checked' => count($sample)];
                }
            }
            return $best;
        };

        $out = [];
        foreach ($domains as $host => $d) {
            $rec = $recommend_strip($d['asset_paths']);
            $out[] = [
                'domain'        => $host,
                'count'         => $d['count'],
                'samples'       => array_keys($d['samples']),
                'strip'         => $rec['strip'],        // recommended base-path to drop
                'verified'      => $rec['verified'],     // files that exist on disk with this strip
                'checked'       => $rec['checked'],      // asset paths probed
            ];
        }
        usort($out, fn($a, $b) => $b['count'] <=> $a['count']);

        echo json_encode(['ok' => true, 'current_host' => $cur_host, 'domains' => $out, 'total' => count($out)]);
        exit;
    }

    // --- VERIFY URLS (fast HEAD) ---
    // Checks whether a small batch of URLs responds (2xx/3xx) within a short
    // timeout, so the Domain Link Manager can show whether the rewritten target
    // URL and the existing links actually resolve. Capped to avoid long scans.
    if ($action === 'verify_url') {
        $raw  = json_decode($_POST['urls'] ?? '', true);
        $urls = is_array($raw) ? array_slice(array_values($raw), 0, 25) : [];
        if (!$urls) { echo json_encode(['ok' => false, 'error' => 'No URLs provided']); exit; }

        $check = function ($url) {
            if (!preg_match('#^https?://#i', $url)) return ['status' => 0, 'ok' => false];
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_NOBODY         => true,   // HEAD
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 5,
                    CURLOPT_CONNECTTIMEOUT => 4,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_USERAGENT      => 'WP-Migration-Tool/verify',
                    CURLOPT_RETURNTRANSFER => true,
                ]);
                curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5; the
                // handle is freed when it goes out of scope.
                unset($ch);
                return ['status' => $code, 'ok' => ($code >= 200 && $code < 400)];
            }
            // Fallback: get_headers (needs allow_url_fopen).
            $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 5, 'ignore_errors' => true]]);
            $hdr = @get_headers($url, true, $ctx);
            if (!$hdr || !isset($hdr[0]) || !preg_match('#\s(\d{3})\s#', $hdr[0], $m)) return ['status' => 0, 'ok' => false];
            $code = (int)$m[1];
            return ['status' => $code, 'ok' => ($code >= 200 && $code < 400)];
        };

        $results = [];
        foreach ($urls as $u) { $results[$u] = $check((string)$u); }
        echo json_encode(['ok' => true, 'results' => $results]);
        exit;
    }

    // --- FIX LINKED DOMAINS ---
    // Rewrites every absolute URL of the selected domains to a root-relative path,
    // stripping scheme + host + an optional known subfolder. Serialized-safe.
    if ($action === 'fix_domains') {
        $fix_db = [
            'host'    => $_POST['db_host']   ?? $db['host'],
            'name'    => $_POST['db_name']   ?? $db['name'],
            'user'    => $_POST['db_user']   ?? $db['user'],
            'pass'    => $_POST['db_pass']   ?? $db['pass'],
            'prefix'  => $_POST['db_prefix'] ?? $db['prefix'],
            'charset' => 'utf8mb4',
        ];
        // Per-domain strip map: { "igs-bonn.de": "/wordpress", ... }.
        // Legacy fallback: a plain "domains" CSV (no strip).
        $map = json_decode($_POST['map'] ?? '', true);
        if (!is_array($map) || !$map) {
            $raw = trim($_POST['domains'] ?? '');
            $map = [];
            foreach (array_filter(array_map('trim', explode(',', $raw))) as $h) $map[$h] = '';
        }
        // Normalize keys (www-less, lowercase) and strips (leading slash, no trailing).
        $strips   = [];
        $selected = [];
        foreach ($map as $host => $strip) {
            $host = preg_replace('#^www\.#', '', strtolower(trim((string)$host)));
            if ($host === '') continue;
            $strip = trim((string)$strip);
            if ($strip !== '') $strip = '/' . trim($strip, '/');
            $strips[$host] = $strip;
            $selected[]    = $host;
        }
        if (!$selected) { echo json_encode(['ok' => false, 'error' => 'No domains selected']); exit; }

        $r = try_db_connect($fix_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo    = $r['pdo'];
        $prefix = $fix_db['prefix'];

        // Named capture per host so the rewrite can drop that host's own strip.
        $alts = [];
        $i    = 0;
        $host_to_group = [];
        foreach ($selected as $host) {
            $g = 'h' . $i++;
            $host_to_group[$g] = $host;
            $alts[] = '(?<' . $g . '>' . preg_quote($host, '#') . ')';
        }
        // http(s)://[www.](one selected host)(/path)? → strip host + its base-path.
        $pattern = '#https?://(?:www\.)?(?:' . implode('|', $alts) . ')(?<path>/[^\s"\'<>()\[\]\\\\]*)?#i';

        $rewrite = function ($str) use ($pattern, $host_to_group, $strips) {
            return preg_replace_callback($pattern, function ($m) use ($host_to_group, $strips) {
                // Which host matched? Find the non-empty named group.
                $strip = '';
                foreach ($host_to_group as $g => $host) {
                    if (isset($m[$g]) && $m[$g] !== '') { $strip = $strips[$host] ?? ''; break; }
                }
                $path = $m['path'] ?? '';
                if ($strip !== '' && ($path === $strip || strpos($path, $strip . '/') === 0)) {
                    $path = substr($path, strlen($strip));
                }
                return ($path !== '') ? $path : '/';
            }, $str);
        };

        $tables = [
            ['table' => $prefix . 'posts',    'col' => 'post_content', 'id' => 'ID'],
            ['table' => $prefix . 'postmeta', 'col' => 'meta_value',   'id' => 'meta_id'],
            ['table' => $prefix . 'options',  'col' => 'option_value', 'id' => 'option_id'],
        ];
        $like = '%http%';

        $updated = 0;
        $errors  = 0;
        foreach ($tables as $t) {
            try {
                $stmt = $pdo->prepare("SELECT `{$t['id']}`, `{$t['col']}` FROM `{$t['table']}` WHERE `{$t['col']}` LIKE ?");
                $stmt->execute([$like]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $old = $row[$t['col']];
                    if (!preg_match($pattern, $old)) continue;
                    // Serialized strings first: rewrite inside, recalculate s:LENGTH.
                    $new = preg_replace_callback(
                        '/s:(\d+):"((?:[^"\\\\]|\\\\.)*)"/s',
                        function ($m) use ($rewrite) {
                            $str = $rewrite($m[2]);
                            if ($str === $m[2]) return $m[0];
                            return 's:' . strlen($str) . ':"' . $str . '"';
                        },
                        $old
                    );
                    // Remaining plain occurrences.
                    $new = $rewrite($new);
                    if ($new !== $old) {
                        $upd = $pdo->prepare("UPDATE `{$t['table']}` SET `{$t['col']}` = ? WHERE `{$t['id']}` = ?");
                        if ($upd->execute([$new, $row[$t['id']]])) $updated++;
                        else $errors++;
                    }
                }
            } catch (Exception $e) { $errors++; }
        }

        echo json_encode(['ok' => true, 'updated' => $updated, 'errors' => $errors, 'domains' => $selected]);
        exit;
    }

    // --- UPDATE SITE URL PROTOCOL ---
    if ($action === 'update_site_url_protocol') {
        $upd_db = [
            'host'    => $_POST['db_host']   ?? $db['host'],
            'name'    => $_POST['db_name']   ?? $db['name'],
            'user'    => $_POST['db_user']   ?? $db['user'],
            'pass'    => $_POST['db_pass']   ?? $db['pass'],
            'prefix'  => $_POST['db_prefix'] ?? $db['prefix'],
            'charset' => 'utf8mb4',
        ];
        $protocol = in_array($_POST['protocol'] ?? '', ['http', 'https']) ? $_POST['protocol'] : null;
        if (!$protocol) { echo json_encode(['ok' => false, 'error' => 'Invalid protocol']); exit; }
        $r = try_db_connect($upd_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo    = $r['pdo'];
        $prefix = $upd_db['prefix'];

        $row = $pdo->query("SELECT option_value FROM `{$prefix}options` WHERE option_name='siteurl' LIMIT 1")->fetch();
        if (!$row) { echo json_encode(['ok' => false, 'error' => 'siteurl not found in DB']); exit; }
        $new_url = preg_replace('/^https?:\/\//', $protocol . '://', rtrim($row[0], '/'));

        $stmt = $pdo->prepare("UPDATE `{$prefix}options` SET option_value = ? WHERE option_name IN ('siteurl','home')");
        $stmt->execute([$new_url]);
        echo json_encode(['ok' => true, 'new_url' => $new_url, 'affected' => $stmt->rowCount()]);
        exit;
    }

    // --- LIST PLUGINS ---
    if ($action === 'list_plugins') {
        $list_db = [
            'host'    => $_POST['db_host']   ?? $db['host'],
            'name'    => $_POST['db_name']   ?? $db['name'],
            'user'    => $_POST['db_user']   ?? $db['user'],
            'pass'    => $_POST['db_pass']   ?? $db['pass'],
            'prefix'  => $_POST['db_prefix'] ?? $db['prefix'],
            'charset' => 'utf8mb4',
        ];
        $r = try_db_connect($list_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo    = $r['pdo'];
        $prefix = $list_db['prefix'];

        $row = $pdo->query("SELECT option_value FROM `{$prefix}options` WHERE option_name='active_plugins' LIMIT 1")->fetch();
        $active_plugins = $row ? @unserialize($row[0]) : [];
        if (!is_array($active_plugins)) $active_plugins = [];

        $plugins_dir = rtrim($abspath, '/') . '/wp-content/plugins';
        $installed   = [];

        if (is_dir($plugins_dir)) {
            // Single-file plugins directly in plugins/
            foreach (glob($plugins_dir . '/*.php') ?: [] as $php_file) {
                $head = file_get_contents($php_file, false, null, 0, 4096);
                if (strpos($head, 'Plugin Name:') === false) continue;
                preg_match('/Plugin Name:\s*(.+)/i', $head, $m);
                $file = basename($php_file);
                $installed[] = ['slug' => basename($php_file, '.php'), 'file' => $file, 'name' => $m ? trim($m[1]) : $file, 'active' => in_array($file, $active_plugins)];
            }
            // Directory plugins
            foreach (scandir($plugins_dir) ?: [] as $item) {
                if ($item[0] === '.') continue;
                $dir = $plugins_dir . '/' . $item;
                if (!is_dir($dir)) continue;
                foreach (glob($dir . '/*.php') ?: [] as $php_file) {
                    $head = file_get_contents($php_file, false, null, 0, 4096);
                    if (strpos($head, 'Plugin Name:') === false) continue;
                    preg_match('/Plugin Name:\s*(.+)/i', $head, $m);
                    $file = $item . '/' . basename($php_file);
                    $installed[] = ['slug' => $item, 'file' => $file, 'name' => $m ? trim($m[1]) : $item, 'active' => in_array($file, $active_plugins)];
                    break;
                }
            }
        }

        usort($installed, fn($a, $b) => strcmp($a['name'], $b['name']));
        echo json_encode([
            'ok'              => true,
            'active'          => $active_plugins,
            'installed'       => $installed,
            'count_active'    => count($active_plugins),
            'count_installed' => count($installed),
        ]);
        exit;
    }

    // --- TOGGLE PLUGINS ---
    if ($action === 'toggle_plugins') {
        $tog_db = [
            'host'    => $_POST['db_host']   ?? $db['host'],
            'name'    => $_POST['db_name']   ?? $db['name'],
            'user'    => $_POST['db_user']   ?? $db['user'],
            'pass'    => $_POST['db_pass']   ?? $db['pass'],
            'prefix'  => $_POST['db_prefix'] ?? $db['prefix'],
            'charset' => 'utf8mb4',
        ];
        $r = try_db_connect($tog_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo    = $r['pdo'];
        $prefix = $tog_db['prefix'];

        if (($_POST['mode'] ?? '') === 'deactivate_all') {
            $new_active = [];
        } else {
            $raw        = trim($_POST['plugins'] ?? '');
            $new_active = $raw ? array_values(array_filter(explode(',', $raw))) : [];
        }

        $stmt = $pdo->prepare("UPDATE `{$prefix}options` SET option_value = ? WHERE option_name = 'active_plugins'");
        $stmt->execute([serialize($new_active)]);
        echo json_encode(['ok' => true, 'active_count' => count($new_active)]);
        exit;
    }

    // --- WORDPRESS USERS: shared connection helper ---
    $wp_users_db = function () use ($db) {
        return [
            'host'    => $_POST['db_host']   ?? $db['host'],
            'name'    => $_POST['db_name']   ?? $db['name'],
            'user'    => $_POST['db_user']   ?? $db['user'],
            'pass'    => $_POST['db_pass']   ?? $db['pass'],
            'prefix'  => $_POST['db_prefix'] ?? $db['prefix'],
            'charset' => 'utf8mb4',
        ];
    };

    // --- LIST WP USERS ---
    if ($action === 'wp_users_list') {
        $u_db = $wp_users_db();
        $r = try_db_connect($u_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $u_db['prefix'];
        $rows = $pdo->query("SELECT ID, user_login, user_email, display_name, user_registered FROM `{$prefix}users` ORDER BY ID")->fetchAll(PDO::FETCH_ASSOC);
        // Roles come from usermeta `{prefix}capabilities` (serialized array of role=>bool).
        $users = [];
        foreach ($rows as $row) {
            $cap = $pdo->prepare("SELECT meta_value FROM `{$prefix}usermeta` WHERE user_id = ? AND meta_key = ? LIMIT 1");
            $cap->execute([$row['ID'], $prefix . 'capabilities']);
            $caps = @unserialize((string)$cap->fetchColumn());
            $roles = is_array($caps) ? array_keys(array_filter($caps)) : [];
            $users[] = [
                'id'         => (int)$row['ID'],
                'login'      => $row['user_login'],
                'email'      => $row['user_email'],
                'display'    => $row['display_name'],
                'registered' => $row['user_registered'],
                'roles'      => $roles,
            ];
        }
        echo json_encode(['ok' => true, 'users' => $users, 'count' => count($users)]);
        exit;
    }

    // --- CHANGE WP USER PASSWORD (phpass $P$) ---
    if ($action === 'wp_user_password') {
        $u_db = $wp_users_db();
        $id = (int)($_POST['user_id'] ?? 0);
        $pw = (string)($_POST['new_password'] ?? '');
        if ($id <= 0)            { echo json_encode(['ok' => false, 'error' => 'Invalid user id']); exit; }
        if (strlen($pw) < 6)     { echo json_encode(['ok' => false, 'error' => 'Password too short (min 6)']); exit; }
        $r = try_db_connect($u_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $u_db['prefix'];
        $hash = (new WpPasswordHash(8))->hash($pw);
        if (strlen($hash) !== 34) { echo json_encode(['ok' => false, 'error' => 'Hashing failed']); exit; }
        $upd = $pdo->prepare("UPDATE `{$prefix}users` SET user_pass = ? WHERE ID = ?");
        $upd->execute([$hash, $id]);
        // Invalidate any stored session tokens so the new password takes effect.
        $pdo->prepare("DELETE FROM `{$prefix}usermeta` WHERE user_id = ? AND meta_key = 'session_tokens'")->execute([$id]);
        echo json_encode(['ok' => true, 'affected' => $upd->rowCount()]);
        exit;
    }

    // --- UPDATE WP USER (email / display_name / role) ---
    if ($action === 'wp_user_update') {
        $u_db = $wp_users_db();
        $id      = (int)($_POST['user_id'] ?? 0);
        $email   = trim($_POST['email'] ?? '');
        $display = trim($_POST['display_name'] ?? '');
        $role    = $_POST['role'] ?? '';
        $valid_roles = ['administrator','editor','author','contributor','subscriber'];
        if ($id <= 0)                                   { echo json_encode(['ok' => false, 'error' => 'Invalid user id']); exit; }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok' => false, 'error' => 'Invalid email']); exit; }
        if ($role !== '' && !in_array($role, $valid_roles, true)) { echo json_encode(['ok' => false, 'error' => 'Invalid role']); exit; }
        $r = try_db_connect($u_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $u_db['prefix'];
        $sets = []; $vals = [];
        if ($email   !== '') { $sets[] = 'user_email = ?';  $vals[] = $email; }
        if ($display !== '') { $sets[] = 'display_name = ?'; $vals[] = $display; }
        if ($sets) {
            $vals[] = $id;
            $pdo->prepare("UPDATE `{$prefix}users` SET " . implode(', ', $sets) . " WHERE ID = ?")->execute($vals);
        }
        if ($role !== '') {
            $caps = serialize([$role => true]);
            foreach ([['capabilities', $caps], ['user_level', (string)wp_role_level($role)]] as $meta) {
                [$key, $value] = $meta;
                $mk  = $prefix . $key;
                $sel = $pdo->prepare("SELECT umeta_id FROM `{$prefix}usermeta` WHERE user_id = ? AND meta_key = ? LIMIT 1");
                $sel->execute([$id, $mk]);
                if ($sel->fetchColumn()) {
                    $pdo->prepare("UPDATE `{$prefix}usermeta` SET meta_value = ? WHERE user_id = ? AND meta_key = ?")->execute([$value, $id, $mk]);
                } else {
                    $pdo->prepare("INSERT INTO `{$prefix}usermeta` (user_id, meta_key, meta_value) VALUES (?, ?, ?)")->execute([$id, $mk, $value]);
                }
            }
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- CREATE WP USER ---
    if ($action === 'wp_user_create') {
        $u_db = $wp_users_db();
        $login   = trim($_POST['login'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $display = trim($_POST['display_name'] ?? '') ?: $login;
        $pw      = (string)($_POST['new_password'] ?? '');
        $role    = $_POST['role'] ?? 'subscriber';
        $valid_roles = ['administrator','editor','author','contributor','subscriber'];
        if ($login === '')                              { echo json_encode(['ok' => false, 'error' => 'Login required']); exit; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok' => false, 'error' => 'Invalid email']); exit; }
        if (strlen($pw) < 6)                            { echo json_encode(['ok' => false, 'error' => 'Password too short (min 6)']); exit; }
        if (!in_array($role, $valid_roles, true))       { echo json_encode(['ok' => false, 'error' => 'Invalid role']); exit; }
        $r = try_db_connect($u_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $u_db['prefix'];
        // Reject duplicate login/email.
        $dup = $pdo->prepare("SELECT ID FROM `{$prefix}users` WHERE user_login = ? OR user_email = ? LIMIT 1");
        $dup->execute([$login, $email]);
        if ($dup->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'Login or email already exists']); exit; }
        $hash     = (new WpPasswordHash(8))->hash($pw);
        $nicename = preg_replace('/[^a-z0-9-]+/', '-', strtolower($login));
        $ins = $pdo->prepare("INSERT INTO `{$prefix}users` (user_login, user_pass, user_nicename, user_email, user_registered, display_name) VALUES (?, ?, ?, ?, NOW(), ?)");
        $ins->execute([$login, $hash, $nicename, $email, $display]);
        $new_id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO `{$prefix}usermeta` (user_id, meta_key, meta_value) VALUES (?, ?, ?)")
            ->execute([$new_id, $prefix . 'capabilities', serialize([$role => true])]);
        $pdo->prepare("INSERT INTO `{$prefix}usermeta` (user_id, meta_key, meta_value) VALUES (?, ?, ?)")
            ->execute([$new_id, $prefix . 'user_level', (string)wp_role_level($role)]);
        echo json_encode(['ok' => true, 'id' => $new_id]);
        exit;
    }

    // --- UPDATE PHP CONFIG ---
    // --- TEST PHP CONFIG IN AN ISOLATED SUBFOLDER ---
    // Writes the proposed .htaccess / .user.ini into a throwaway subfolder with a
    // probe script, requests the probe over HTTP, and reports whether the config
    // loads (HTTP 200) or would break the site (HTTP 500), plus the effective
    // values. The subfolder only affects itself, so the live site is never at risk.
    if ($action === 'test_php_config') {
        $root    = rtrim($abspath, '/');
        $allowed = ['memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'max_input_vars'];
        $method  = in_array($_POST['method'] ?? '', ['htaccess', 'user_ini', 'auto'], true) ? $_POST['method'] : 'auto';

        $settings = [];
        foreach ($allowed as $k) {
            $v = trim($_POST[$k] ?? '');
            if ($v === '') continue;
            if (!preg_match('/^\d+[KMGkmg]?$/', $v)) { echo json_encode(['ok' => false, 'error' => "Invalid value for $k: $v"]); exit; }
            $settings[$k] = $v;
        }
        if (empty($settings)) { echo json_encode(['ok' => false, 'error' => 'No values provided']); exit; }

        if (!is_writable($root)) { echo json_encode(['ok' => false, 'error' => 'Web root is not writable; cannot create a test folder.']); exit; }

        // Probe script: reports the effective ini values as JSON.
        $probe = "<?php header('Content-Type: application/json'); \$k=" . var_export($allowed, true) . "; \$o=[]; foreach(\$k as \$x)\$o[\$x]=ini_get(\$x); echo json_encode(['probe_ok'=>true,'values'=>\$o]);";

        // Base URL of the current script's directory, to reach the test folder over HTTP.
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base  = rtrim(dirname($proto . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/')), '/');

        $build_htaccess = function () use ($settings) {
            $b = "# WP-Migrate PHP Config test\n";
            foreach ($settings as $k => $v) { if ($k === 'max_input_vars') continue; $b .= "php_value $k $v\n"; }
            return $b;
        };
        $build_userini = function () use ($settings) {
            $b = "; WP-Migrate PHP Config test\n";
            foreach ($settings as $k => $v) $b .= "$k = $v\n";
            return $b;
        };

        // Run one isolated test for a given method; returns status + effective values.
        $run_test = function ($use_htaccess, $use_userini) use ($root, $base, $probe, $build_htaccess, $build_userini, $settings) {
            $dir = $root . '/_phpcfg_test_' . bin2hex(random_bytes(4));
            if (!@mkdir($dir, 0755)) return ['ok' => false, 'error' => 'Could not create test folder'];
            $cleanup = function () use ($dir) {
                foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
                foreach (glob($dir . '/.*') ?: [] as $f) { if (basename($f) !== '.' && basename($f) !== '..') @unlink($f); }
                @rmdir($dir);
            };
            file_put_contents($dir . '/probe.php', $probe);
            if ($use_htaccess) file_put_contents($dir . '/.htaccess', $build_htaccess());
            if ($use_userini)  file_put_contents($dir . '/.user.ini', $build_userini());

            $url = $base . '/' . basename($dir) . '/probe.php';
            $status = 0; $body = '';
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false, CURLOPT_FOLLOWLOCATION => true]);
                $body = (string)curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                unset($ch);
            } else {
                $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
                $body = (string)@file_get_contents($url, false, $ctx);
                // PHP 8.5 deprecates the $http_response_header global; prefer the function.
                $hdrs = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
                if (isset($hdrs[0]) && preg_match('#\s(\d{3})\s#', $hdrs[0], $m)) $status = (int)$m[1];
            }
            $cleanup();

            $breaks = ($status >= 500);
            $values = null; $applied = null;
            $decoded = json_decode($body, true);
            if (is_array($decoded) && !empty($decoded['probe_ok'])) {
                $values = $decoded['values'];
                // Did the proposed values actually take effect in that folder?
                $applied = [];
                foreach ($settings as $k => $want) {
                    $got = $values[$k] ?? '';
                    $applied[$k] = ['want' => $want, 'got' => $got,
                        'ok' => (strcasecmp(rtrim((string)$got, 'Bb'), rtrim((string)$want, 'Bb')) === 0)
                             || ((int)$got === (int)$want && (int)$want > 0)];
                }
            }
            return ['ok' => true, 'status' => $status, 'breaks' => $breaks,
                    'loaded' => ($status >= 200 && $status < 400), 'values' => $values, 'applied' => $applied];
        };

        $report = [];
        if ($method === 'htaccess' || $method === 'auto') $report['htaccess'] = $run_test(true, false);
        if ($method === 'user_ini' || $method === 'auto') $report['user_ini'] = $run_test(false, true);

        // Recommend a method: a loaded, non-breaking one (prefer the explicit choice).
        $recommended = null;
        foreach (['htaccess', 'user_ini'] as $mth) {
            if (isset($report[$mth]) && $report[$mth]['loaded'] && !$report[$mth]['breaks']) { $recommended = $mth; break; }
        }
        echo json_encode(['ok' => true, 'method' => $method, 'report' => $report, 'recommended' => $recommended]);
        exit;
    }

    if ($action === 'update_php_config') {
        $root    = rtrim($abspath, '/');
        $allowed = ['memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'max_input_vars'];
        $settings = [];
        foreach ($allowed as $k) {
            $v = trim($_POST[$k] ?? '');
            if ($v === '') continue;
            if (!preg_match('/^\d+[KMGkmg]?$/', $v)) {
                echo json_encode(['ok' => false, 'error' => "Invalid value for $k: $v"]); exit;
            }
            $settings[$k] = $v;
        }
        if (empty($settings)) { echo json_encode(['ok' => false, 'error' => 'No values provided']); exit; }

        // Which methods to write. Defaults to both for backward compatibility; the
        // UI passes the method the isolated test validated so we don't write a
        // config that would 500 the site.
        $method = in_array($_POST['method'] ?? '', ['htaccess', 'user_ini', 'both'], true) ? $_POST['method'] : 'both';

        $written = []; $skipped = [];
        $ms = '# BEGIN WP-Migrate PHP Config'; $me = '# END WP-Migrate PHP Config';

        // .htaccess — all settings except max_input_vars
        if ($method === 'htaccess' || $method === 'both') {
            $htaccess = $root . '/.htaccess';
            $ht_block = "$ms\n";
            foreach ($settings as $k => $v) {
                if ($k === 'max_input_vars') continue;
                $ht_block .= "php_value $k $v\n";
            }
            $ht_block .= "$me";
            $ht_writable = (file_exists($htaccess) && is_writable($htaccess)) || (!file_exists($htaccess) && is_writable($root));
            if ($ht_writable) {
                $content = file_exists($htaccess) ? file_get_contents($htaccess) : '';
                $content = trim(preg_replace('/' . preg_quote($ms, '/') . '.*?' . preg_quote($me, '/') . '/s', '', $content));
                $content = $ht_block . ($content ? "\n\n" . $content : '');
                file_put_contents($htaccess, $content);
                $written[] = '.htaccess';
            } else { $skipped[] = '.htaccess (not writable)'; }
        }

        // .user.ini — all settings (PHP-FPM/CGI). Correct filename is ".user.ini".
        if ($method === 'user_ini' || $method === 'both') {
            $user_ini = $root . '/.user.ini';
            $ini_block = "; WP-Migrate PHP Config\n";
            foreach ($settings as $k => $v) $ini_block .= "$k = $v\n";
            $ini_block .= "; END WP-Migrate PHP Config";
            $ini_writable = (file_exists($user_ini) && is_writable($user_ini)) || (!file_exists($user_ini) && is_writable($root));
            if ($ini_writable) {
                $content = file_exists($user_ini) ? file_get_contents($user_ini) : '';
                $content = trim(preg_replace('/; WP-Migrate PHP Config.*?; END WP-Migrate PHP Config/s', '', $content));
                $content = $ini_block . ($content ? "\n\n" . $content : '');
                file_put_contents($user_ini, $content);
                $written[] = '.user.ini';
            } else { $skipped[] = '.user.ini (not writable)'; }
        }

        echo json_encode(['ok' => !empty($written), 'written' => $written, 'skipped' => $skipped,
            'note' => 'Changes take effect on next request. Some hosts require server restart.']);
        exit;
    }

    // --- SITE HEALTH CHECK ---
    if ($action === 'health_check') {
        @set_time_limit(180);
        $hc_db = [
            'host'    => $_POST['db_host']   ?? $db['host'],
            'name'    => $_POST['db_name']   ?? $db['name'],
            'user'    => $_POST['db_user']   ?? $db['user'],
            'pass'    => $_POST['db_pass']   ?? $db['pass'],
            'prefix'  => $_POST['db_prefix'] ?? $db['prefix'],
            'charset' => 'utf8mb4',
        ];
        $root   = rtrim($abspath, '/');
        $prefix = $hc_db['prefix'];
        $cats   = [];

        // Helper: status from counts
        $cat = function($label, $icon, $items) {
            $errors = 0; $warns = 0;
            foreach ($items as $i) {
                if ($i['s'] === 'error') $errors++;
                elseif ($i['s'] === 'warn') $warns++;
            }
            $status = $errors ? 'error' : ($warns ? 'warn' : 'ok');
            return compact('label', 'icon', 'status', 'items', 'errors', 'warns');
        };

        // Convert PHP ini shorthand (128M, 2G) → MB; plain numbers stay as-is
        $ini_to_mb = function($v) {
            $n = (int)$v; $u = strtolower(substr(trim($v), -1));
            if ($u === 'g') return $n * 1024;
            if ($u === 'm') return $n;
            if ($u === 'k') return (int)($n / 1024);
            return (int)($n / 1048576); // bytes
        };

        // ── 1. PHP Environment (no DB needed) ──────────────────────────────
        $php_items = [];
        $php_ver = phpversion();
        $php_items[] = ['s' => version_compare($php_ver, '7.4', '>=') ? 'ok' : 'warn',
                        'text' => "PHP $php_ver" . (version_compare($php_ver, '7.4', '<') ? ' — WP requires 7.4+' : '')];

        // memory_limit: ok ≥ 256M, warn 128-255M, error < 128M
        $ml = ini_get('memory_limit'); $ml_mb = $ini_to_mb($ml);
        $php_items[] = ['s' => $ml_mb < 0 ? 'ok' : ($ml_mb >= 256 ? 'ok' : ($ml_mb >= 128 ? 'warn' : 'error')),
                        'text' => "memory_limit: $ml (rec: 256M)"];

        // upload_max_filesize: ok ≥ 32M, warn 8-31M, error < 8M
        $uf = ini_get('upload_max_filesize'); $uf_mb = $ini_to_mb($uf);
        $php_items[] = ['s' => $uf_mb >= 32 ? 'ok' : ($uf_mb >= 8 ? 'warn' : 'error'),
                        'text' => "upload_max_filesize: $uf (rec: 64M)"];

        // post_max_size: must be ≥ upload_max_filesize
        $pm = ini_get('post_max_size'); $pm_mb = $ini_to_mb($pm);
        $php_items[] = ['s' => $pm_mb >= $uf_mb ? 'ok' : 'error',
                        'text' => "post_max_size: $pm (must be ≥ upload_max_filesize)"];

        // max_execution_time: ok ≥ 120, warn 60-119, error < 60 (0 = unlimited)
        $me = (int)ini_get('max_execution_time');
        $php_items[] = ['s' => $me === 0 || $me >= 120 ? 'ok' : ($me >= 60 ? 'warn' : 'error'),
                        'text' => "max_execution_time: " . ($me === 0 ? '0 (unlimited)' : "{$me}s") . " (rec: 300)"];

        // max_input_vars
        $miv = (int)ini_get('max_input_vars');
        $php_items[] = ['s' => $miv >= 3000 ? 'ok' : ($miv >= 1000 ? 'warn' : 'error'),
                        'text' => "max_input_vars: $miv (rec: 3000)"];

        foreach (['mysqli', 'pdo_mysql', 'gd', 'zip', 'curl'] as $ext) {
            $ok = extension_loaded($ext);
            $php_items[] = ['s' => $ok ? 'ok' : 'warn', 'text' => "ext/$ext " . ($ok ? 'loaded' : 'missing')];
        }
        $imagick = extension_loaded('imagick');
        $gd      = extension_loaded('gd');
        $php_items[] = ['s' => ($imagick || $gd) ? 'ok' : 'warn',
                        'text' => 'Image library: ' . ($imagick ? 'Imagick' : ($gd ? 'GD' : 'NONE — image processing unavailable'))];
        $cats[] = $cat('PHP Environment', '⚙️', $php_items);

        // ── 2. Filesystem ──────────────────────────────────────────────────
        $fs_items = [];
        $core_files = ['index.php', 'wp-login.php', 'wp-config.php', 'wp-admin/index.php', 'wp-includes/version.php'];
        $wp_version = '?';
        foreach ($core_files as $f) {
            $exists = file_exists($root . '/' . $f);
            if ($f === 'wp-includes/version.php' && $exists) {
                $vc = file_get_contents($root . '/wp-includes/version.php');
                if (preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $vc, $vm)) $wp_version = $vm[1];
            }
            $fs_items[] = ['s' => $exists ? 'ok' : 'error', 'text' => $f . ($exists ? '' : ' — NOT FOUND')];
        }
        $fs_items[] = ['s' => 'ok', 'text' => "WordPress version: $wp_version"];
        $uploads = $root . '/wp-content/uploads';
        $fs_items[] = ['s' => is_dir($uploads) ? 'ok' : 'error',     'text' => 'wp-content/uploads/ ' . (is_dir($uploads) ? 'exists' : 'MISSING')];
        $fs_items[] = ['s' => is_writable($uploads) ? 'ok' : 'warn', 'text' => 'wp-content/uploads/ ' . (is_writable($uploads) ? 'writable' : 'NOT writable')];
        $htaccess = $root . '/.htaccess';
        $fs_items[] = ['s' => file_exists($htaccess) ? 'ok' : 'warn', 'text' => '.htaccess ' . (file_exists($htaccess) ? 'present' : 'missing')];
        $cats[] = $cat('Filesystem & WP Core', '📁', $fs_items);

        // ── 3. Database (needs connection) ─────────────────────────────────
        $r = try_db_connect($hc_db);
        if (!$r['ok']) {
            $cats[] = ['label' => 'Database', 'icon' => '🗄️', 'status' => 'error', 'items' => [['s' => 'error', 'text' => 'Connection failed: ' . $r['error']]], 'errors' => 1, 'warns' => 0];
            echo json_encode(['ok' => true, 'categories' => $cats]);
            exit;
        }
        $pdo = $r['pdo'];

        // DB Tables
        $db_items = [];
        $existing_tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $std_tables = ['posts','postmeta','users','usermeta','options','terms','term_relationships','term_taxonomy','comments','commentmeta','links','options'];
        $std_tables = array_unique(array_map(fn($t) => $prefix . $t, $std_tables));
        foreach ($std_tables as $t) {
            $exists = in_array($t, $existing_tables);
            if ($exists) {
                $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
                $db_items[] = ['s' => 'ok', 'text' => "$t — $count rows"];
            } else {
                $db_items[] = ['s' => 'error', 'text' => "$t — MISSING"];
            }
        }
        $cats[] = $cat('Database Tables', '🗄️', $db_items);

        // ── 4. Themes ──────────────────────────────────────────────────────
        $th_items = [];
        $th_row = $pdo->query("SELECT option_name, option_value FROM `{$prefix}options` WHERE option_name IN ('stylesheet','template')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $stylesheet = $th_row['stylesheet'] ?? '';
        $template   = $th_row['template']   ?? '';
        foreach (array_unique(array_filter([$stylesheet, $template])) as $theme) {
            $theme_path = $root . '/wp-content/themes/' . $theme;
            $has_dir    = is_dir($theme_path);
            $has_css    = file_exists($theme_path . '/style.css');
            $label      = $theme === $stylesheet ? "(active)" : "(parent)";
            $th_items[] = ['s' => ($has_dir && $has_css) ? 'ok' : 'error', 'text' => "$theme $label — " . (!$has_dir ? 'folder MISSING' : (!$has_css ? 'style.css MISSING' : 'OK'))];
        }
        if (empty($th_items)) $th_items[] = ['s' => 'warn', 'text' => 'No active theme found in wp_options'];
        $cats[] = $cat('Themes', '🎨', $th_items);

        // ── 5. Plugins ─────────────────────────────────────────────────────
        $pl_items = [];
        $pl_row = $pdo->query("SELECT option_value FROM `{$prefix}options` WHERE option_name='active_plugins' LIMIT 1")->fetch(PDO::FETCH_COLUMN);
        $active_plugins = $pl_row ? (@unserialize($pl_row) ?: []) : [];
        foreach ($active_plugins as $plugin_file) {
            $exists = file_exists($root . '/wp-content/plugins/' . $plugin_file);
            $name   = dirname($plugin_file) === '.' ? $plugin_file : dirname($plugin_file);
            $pl_items[] = ['s' => $exists ? 'ok' : 'error', 'text' => $name . ($exists ? '' : ' — FILE MISSING (' . $plugin_file . ')')];
        }
        if (empty($pl_items)) $pl_items[] = ['s' => 'warn', 'text' => 'No active plugins'];
        $cats[] = $cat('Active Plugins', '🔌', $pl_items);

        // ── 6. Media / Attachments ─────────────────────────────────────────
        $med_items = [];
        $total_att = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}posts` WHERE post_type='attachment'")->fetchColumn();
        $med_items[] = ['s' => 'ok', 'text' => "Total attachments in DB: $total_att"];
        $att_limit  = 2000;
        $stmt = $pdo->query("SELECT pm.meta_value FROM `{$prefix}posts` p JOIN `{$prefix}postmeta` pm ON p.ID=pm.post_id WHERE p.post_type='attachment' AND pm.meta_key='_wp_attached_file' LIMIT $att_limit");
        $missing_att = 0; $checked_att = 0; $missing_att_list = [];
        while ($file_path = $stmt->fetchColumn()) {
            $checked_att++;
            if (!file_exists($root . '/wp-content/uploads/' . $file_path)) {
                $missing_att++;
                if (count($missing_att_list) < 10) $missing_att_list[] = $file_path;
            }
        }
        $sampled = $checked_att < $total_att ? " (checked first $checked_att)" : '';
        $med_items[] = ['s' => $missing_att === 0 ? 'ok' : 'error', 'text' => "Missing attachment files: $missing_att / $checked_att$sampled"];
        foreach ($missing_att_list as $mf) $med_items[] = ['s' => 'error', 'text' => "  ✗ wp-content/uploads/$mf"];
        if ($missing_att > 10) $med_items[] = ['s' => 'error', 'text' => "  … and " . ($missing_att - 10) . " more"];
        $cats[] = $cat('Media & Attachments', '🖼️', $med_items);

        // ── 7. Asset references in content (images/video/CSS/JS/fonts) ─────
        $ext_pat = 'jpe?g|png|gif|webp|svg|avif|mp4|webm|ogg|mp3|wav|pdf|css|js|woff2?|ttf|eot|otf|ico|zip|gz|docx?|xlsx?|pptx?';
        $url_rx  = '/(?:https?:\/\/[^\/\s"\'<>]+)?\/?(wp-content\/[^\s"\'<>()\[\]\\\\]+\.(?:' . $ext_pat . '))/i';

        // Scan post_content of published posts
        $all_paths = [];
        $scan_stmt = $pdo->query(
            "SELECT post_content FROM `{$prefix}posts` " .
            "WHERE post_status='publish' AND post_type NOT IN ('revision','nav_menu_item','attachment') " .
            "AND post_content LIKE '%wp-content%' LIMIT 500"
        );
        while ($row = $scan_stmt->fetch(PDO::FETCH_NUM)) {
            preg_match_all($url_rx, $row[0], $m);
            foreach ($m[1] as $p) { $all_paths[rtrim(preg_replace('/[?#].*$/', '', $p), '.,;)')] = true; }
        }
        // Scan postmeta (Elementor, ACF, Divi, etc.)
        $meta_stmt = $pdo->query(
            "SELECT meta_value FROM `{$prefix}postmeta` " .
            "WHERE meta_value LIKE '%wp-content%' AND meta_value LIKE '%.%" .
            "' AND LENGTH(meta_value) < 1048576 LIMIT 300"
        );
        while ($row = $meta_stmt->fetch(PDO::FETCH_NUM)) {
            preg_match_all($url_rx, $row[0], $m);
            foreach ($m[1] as $p) { $all_paths[rtrim(preg_replace('/[?#].*$/', '', $p), '.,;)')] = true; }
        }

        $total_refs = count($all_paths); $missing_refs = 0; $missing_ref_list = [];
        foreach (array_keys($all_paths) as $p) {
            if (!file_exists($root . '/' . $p)) {
                $missing_refs++;
                if (count($missing_ref_list) < 15) $missing_ref_list[] = $p;
            }
        }
        $asset_items = [];
        $asset_items[] = ['s' => 'ok', 'text' => "Unique asset refs found: $total_refs"];
        $asset_items[] = ['s' => $missing_refs === 0 ? 'ok' : 'error', 'text' => "Missing on disk: $missing_refs / $total_refs"];
        foreach ($missing_ref_list as $a) $asset_items[] = ['s' => 'error', 'text' => "  ✗ $a"];
        if ($missing_refs > 15) $asset_items[] = ['s' => 'error', 'text' => "  … and " . ($missing_refs - 15) . " more"];
        $cats[] = $cat('Asset References (img/video/CSS/JS/fonts)', '🔗', $asset_items);

        echo json_encode(['ok' => true, 'categories' => $cats]);
        exit;
    }

    // --- SECURITY AUDIT ---
    // Hardening checks across users, WP core, files and attack surface. Same
    // category/severity shape as health_check so the UI can reuse the renderer.
    if ($action === 'security_audit') {
        @set_time_limit(180);
        $sa_db = [
            'host'    => $_POST['db_host']   ?? $db['host'],
            'name'    => $_POST['db_name']   ?? $db['name'],
            'user'    => $_POST['db_user']   ?? $db['user'],
            'pass'    => $_POST['db_pass']   ?? $db['pass'],
            'prefix'  => $_POST['db_prefix'] ?? $db['prefix'],
            'charset' => 'utf8mb4',
        ];
        $root   = rtrim($abspath, '/');
        $prefix = $sa_db['prefix'];
        $cats   = [];
        $cat = function ($label, $icon, $items) {
            $errors = 0; $warns = 0;
            foreach ($items as $i) { if ($i['s'] === 'error') $errors++; elseif ($i['s'] === 'warn') $warns++; }
            $status = $errors ? 'error' : ($warns ? 'warn' : 'ok');
            return compact('label', 'icon', 'status', 'items', 'errors', 'warns');
        };

        // ── 1. Users & passwords ───────────────────────────────────────────
        $u_items = [];
        $r = try_db_connect($sa_db);
        if (!$r['ok']) {
            $cats[] = ['label' => 'Database', 'icon' => '🗄️', 'status' => 'error',
                       'items' => [['s' => 'error', 'text' => 'Connection failed: ' . $r['error']]], 'errors' => 1, 'warns' => 0];
            echo json_encode(['ok' => true, 'categories' => $cats]); exit;
        }
        $pdo = $r['pdo'];
        // Common passwords to test against each user_pass hash (phpass $P$).
        $common = ['123456','password','12345678','qwerty','123456789','12345','1234','111111',
                   'admin','letmein','welcome','monkey','abc123','password1','admin123','root',
                   'test','pass','1234567890','changeme','wordpress','secret','p@ssw0rd','Password1'];
        $hasher = new WpPasswordHash(8);
        $admin_logins = [];
        try {
            $urows = $pdo->query("SELECT ID, user_login, user_pass, display_name FROM `{$prefix}users`")->fetchAll(PDO::FETCH_ASSOC);
            $weak = 0;
            foreach ($urows as $u) {
                // Role lookup to flag administrators.
                $cap = $pdo->prepare("SELECT meta_value FROM `{$prefix}usermeta` WHERE user_id = ? AND meta_key = ? LIMIT 1");
                $cap->execute([$u['ID'], $prefix . 'capabilities']);
                $caps = @unserialize((string)$cap->fetchColumn());
                $is_admin = is_array($caps) && !empty($caps['administrator']);
                if ($is_admin) $admin_logins[] = $u['user_login'];
                if (strtolower($u['user_login']) === 'admin')
                    $u_items[] = ['s' => 'warn', 'text' => "Default username 'admin' in use (ID {$u['ID']}) — easy to brute-force"];
                if (strcasecmp($u['user_login'], $u['display_name']) === 0)
                    $u_items[] = ['s' => 'warn', 'text' => "User '{$u['user_login']}' exposes its login as display name"];
                // Weak-password probe against the stored hash.
                foreach ($common as $cpw) {
                    if ($hasher->check($cpw, $u['user_pass'])) {
                        $u_items[] = ['s' => 'error', 'text' => "Weak password for '{$u['user_login']}' — matches common password"];
                        $weak++;
                        break;
                    }
                }
            }
            $u_items[] = ['s' => $weak ? 'error' : 'ok', 'text' => "Users checked: " . count($urows) . ", weak passwords: $weak"];
            $admin_count = count($admin_logins);
            $u_items[] = ['s' => $admin_count > 2 ? 'warn' : 'ok', 'text' => "Administrator accounts: $admin_count" . ($admin_count > 2 ? ' (consider reducing)' : '')];
        } catch (Exception $e) {
            $u_items[] = ['s' => 'warn', 'text' => 'User check failed: ' . $e->getMessage()];
        }
        $cats[] = $cat('Users & Passwords', '👤', $u_items);

        // ── 2. WordPress core / config ─────────────────────────────────────
        $c_items = [];
        // Table prefix.
        $c_items[] = ['s' => $prefix === 'wp_' ? 'warn' : 'ok', 'text' => "Table prefix: '$prefix'" . ($prefix === 'wp_' ? ' (default — predictable)' : '')];
        // WP version vs a known-recent baseline.
        $wp_version = '?';
        if (is_file($root . '/wp-includes/version.php')) {
            $vc = file_get_contents($root . '/wp-includes/version.php');
            if (preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $vc, $vm)) $wp_version = $vm[1];
        }
        $recent = '6.4';
        $c_items[] = ['s' => ($wp_version !== '?' && version_compare($wp_version, $recent, '<')) ? 'warn' : 'ok',
                      'text' => "WordPress version: $wp_version" . (($wp_version !== '?' && version_compare($wp_version, $recent, '<')) ? " (older than $recent — update recommended)" : '')];
        // wp-config.php: default SALTs, debug, file edit.
        $wp_config = null;
        foreach ([$root . '/wp-config.php', dirname($root) . '/wp-config.php'] as $p) { if (is_file($p)) { $wp_config = $p; break; } }
        if ($wp_config) {
            $cfg = file_get_contents($wp_config);
            $default_salt = strpos($cfg, 'put your unique phrase here') !== false;
            $c_items[] = ['s' => $default_salt ? 'error' : 'ok', 'text' => 'Security keys/SALTs: ' . ($default_salt ? 'DEFAULT placeholder — must be randomized' : 'customized')];
            $debug_on = preg_match("/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*true\s*\)/i", $cfg);
            $c_items[] = ['s' => $debug_on ? 'warn' : 'ok', 'text' => 'WP_DEBUG: ' . ($debug_on ? 'ENABLED (may leak paths/errors in production)' : 'off')];
            $file_edit = preg_match("/define\s*\(\s*['\"]DISALLOW_FILE_EDIT['\"]\s*,\s*true\s*\)/i", $cfg);
            $c_items[] = ['s' => $file_edit ? 'ok' : 'warn', 'text' => 'Admin file editor: ' . ($file_edit ? 'disabled (DISALLOW_FILE_EDIT)' : 'enabled — a compromised admin can edit PHP')];
        } else {
            $c_items[] = ['s' => 'warn', 'text' => 'wp-config.php not found for inspection'];
        }
        $cats[] = $cat('WordPress Core & Config', '⚙️', $c_items);

        // ── 3. Filesystem exposure ─────────────────────────────────────────
        $f_items = [];
        if ($wp_config) {
            $perms = @fileperms($wp_config) & 0777;
            $world = ($perms & 0004) !== 0;
            $f_items[] = ['s' => $world ? 'error' : 'ok', 'text' => 'wp-config.php permissions: ' . sprintf('%o', $perms) . ($world ? ' (WORLD-READABLE — restrict to 600/640)' : '')];
        }
        // Fingerprinting files present in web root.
        foreach (['readme.html', 'license.txt', 'wp-config-sample.php'] as $fp) {
            if (is_file($root . '/' . $fp)) $f_items[] = ['s' => 'warn', 'text' => "$fp present — reveals WordPress / version, delete it"];
        }
        // Exposed dumps / archives in web root.
        $exposed = [];
        foreach (glob($root . '/*.{sql,sql.gz,zip,tar.gz,bak}', GLOB_BRACE) ?: [] as $f) $exposed[] = basename($f);
        $f_items[] = ['s' => $exposed ? 'error' : 'ok', 'text' => $exposed ? 'Exposed dumps/archives in web root: ' . implode(', ', array_slice($exposed, 0, 5)) : 'No exposed dumps/archives in web root'];
        // .htaccess presence (directory listing / rules).
        $f_items[] = ['s' => is_file($root . '/.htaccess') ? 'ok' : 'warn', 'text' => '.htaccess: ' . (is_file($root . '/.htaccess') ? 'present' : 'missing (no URL rules / listing protection)')];
        $cats[] = $cat('Filesystem Exposure', '📁', $f_items);

        // ── 4. Attack surface (local checks) ───────────────────────────────
        $s_items = [];
        $s_items[] = ['s' => is_file($root . '/xmlrpc.php') ? 'warn' : 'ok', 'text' => 'XML-RPC: ' . (is_file($root . '/xmlrpc.php') ? 'xmlrpc.php present — common brute-force/DDoS vector, block if unused' : 'not present')];
        // REST API user enumeration is reachable by default; flag as info/warn.
        $s_items[] = ['s' => 'warn', 'text' => 'REST API user enumeration (/wp-json/wp/v2/users) is enabled by default — restrict if not needed'];
        // Login throttling can't be detected from here — advisory.
        $s_items[] = ['s' => 'warn', 'text' => 'Brute-force protection on wp-login.php cannot be detected here — ensure a limit-login plugin or WAF is active'];
        $cats[] = $cat('Attack Surface', '🛡️', $s_items);

        echo json_encode(['ok' => true, 'categories' => $cats]);
        exit;
    }

    // --- SEO: shared DB connection ---
    $seo_db = function () use ($db) {
        return [
            'host' => $_POST['db_host'] ?? $db['host'], 'name' => $_POST['db_name'] ?? $db['name'],
            'user' => $_POST['db_user'] ?? $db['user'], 'pass' => $_POST['db_pass'] ?? $db['pass'],
            'prefix' => $_POST['db_prefix'] ?? $db['prefix'], 'charset' => 'utf8mb4',
        ];
    };

    // --- SEO AUDIT ---
    // Checks indexability, title/description, friendly permalinks, sitemap and
    // robots.txt presence, HTTPS, and untitled published posts. Same category
    // shape as the other audits so the UI reuses renderCategories().
    if ($action === 'seo_audit') {
        $s_db = $seo_db();
        $root = rtrim($abspath, '/');
        $r = try_db_connect($s_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $s_db['prefix'];
        $cat = function ($label, $icon, $items) {
            $e = 0; $w = 0; foreach ($items as $i) { if ($i['s'] === 'error') $e++; elseif ($i['s'] === 'warn') $w++; }
            return ['label' => $label, 'icon' => $icon, 'status' => $e ? 'error' : ($w ? 'warn' : 'ok'), 'items' => $items, 'errors' => $e, 'warns' => $w];
        };
        $opt = function ($name) use ($pdo, $prefix) {
            $st = $pdo->prepare("SELECT option_value FROM `{$prefix}options` WHERE option_name = ? LIMIT 1");
            $st->execute([$name]); return (string)$st->fetchColumn();
        };
        $cats = [];

        // Indexability
        $idx_items = [];
        $blog_public = $opt('blog_public');
        $idx_items[] = ['s' => $blog_public === '1' ? 'ok' : 'warn',
            'text' => 'blog_public: ' . $blog_public . ($blog_public === '1' ? ' (search engines allowed)' : ' (site is discouraging indexing)')];
        $robots = $root . '/robots.txt';
        if (is_file($robots)) {
            $rc = file_get_contents($robots);
            $blocks_all = (bool)preg_match('/Disallow:\s*\/\s*$/mi', $rc) && !preg_match('/Allow:/i', $rc);
            $idx_items[] = ['s' => $blocks_all ? 'warn' : 'ok', 'text' => 'robots.txt present' . ($blocks_all ? ' and blocks the whole site (Disallow: /)' : '')];
        } else {
            $idx_items[] = ['s' => 'warn', 'text' => 'robots.txt not found (WordPress serves a virtual one)'];
        }
        $cats[] = $cat('Indexability', '🔎', $idx_items);

        // Title / description / URLs
        $meta_items = [];
        $name = $opt('blogname'); $desc = $opt('blogdescription');
        $meta_items[] = ['s' => $name !== '' ? 'ok' : 'error', 'text' => 'Site title (blogname): ' . ($name !== '' ? $name : 'EMPTY')];
        $meta_items[] = ['s' => $desc !== '' ? 'ok' : 'warn', 'text' => 'Tagline (blogdescription): ' . ($desc !== '' ? $desc : 'empty')];
        $siteurl = $opt('siteurl');
        $meta_items[] = ['s' => str_starts_with($siteurl, 'https://') ? 'ok' : 'warn', 'text' => 'siteurl: ' . $siteurl . (str_starts_with($siteurl, 'https://') ? '' : ' (not HTTPS)')];
        $perma = $opt('permalink_structure');
        $friendly = $perma !== '' && strpos($perma, 'index.php') === false;
        $meta_items[] = ['s' => $friendly ? 'ok' : 'warn', 'text' => 'Permalinks: ' . ($perma ?: 'plain (?p=123)') . ($friendly ? ' (friendly)' : ' (not SEO-friendly)')];
        $cats[] = $cat('Title, URLs & Permalinks', '🏷️', $meta_items);

        // Sitemap + content
        $content_items = [];
        $has_sitemap_file = is_file($root . '/sitemap.xml');
        $content_items[] = ['s' => $has_sitemap_file ? 'ok' : 'warn', 'text' => 'sitemap.xml file: ' . ($has_sitemap_file ? 'present' : 'not found (WordPress 5.5+ serves /wp-sitemap.xml)')];
        try {
            $untitled = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}posts` WHERE post_status='publish' AND post_type IN ('post','page') AND (post_title='' OR post_title IS NULL)")->fetchColumn();
            $content_items[] = ['s' => $untitled === 0 ? 'ok' : 'warn', 'text' => "Published posts/pages without a title: $untitled"];
            $pub = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}posts` WHERE post_status='publish' AND post_type IN ('post','page')")->fetchColumn();
            $content_items[] = ['s' => 'ok', 'text' => "Published posts/pages: $pub"];
        } catch (Exception $e) { $content_items[] = ['s' => 'warn', 'text' => 'Content check failed: ' . $e->getMessage()]; }
        $cats[] = $cat('Sitemap & Content', '🗺️', $content_items);

        echo json_encode(['ok' => true, 'categories' => $cats]);
        exit;
    }

    // --- SEO: UPDATE BASIC META (title / tagline) ---
    if ($action === 'seo_update_meta') {
        $s_db = $seo_db();
        $r = try_db_connect($s_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $s_db['prefix'];
        $name = trim($_POST['blogname'] ?? '');
        $desc = trim($_POST['blogdescription'] ?? '');
        $done = [];
        if ($name !== '') { $pdo->prepare("UPDATE `{$prefix}options` SET option_value = ? WHERE option_name='blogname'")->execute([$name]); $done[] = 'title'; }
        // Tagline may legitimately be blank; only update when the field was submitted.
        if (isset($_POST['blogdescription'])) { $pdo->prepare("UPDATE `{$prefix}options` SET option_value = ? WHERE option_name='blogdescription'")->execute([$desc]); $done[] = 'tagline'; }
        if (!$done) { echo json_encode(['ok' => false, 'error' => 'Nothing to update']); exit; }
        echo json_encode(['ok' => true, 'updated' => $done]);
        exit;
    }

    // --- SEO: SET FRIENDLY PERMALINKS (/%postname%/) ---
    // Friendly permalinks need WordPress rewrite rules in .htaccess. We set the
    // option and (re)generate the standard rules, having tested them anti-500
    // in an isolated subfolder first when possible.
    if ($action === 'seo_set_permalinks') {
        $s_db = $seo_db();
        $root = rtrim($abspath, '/');
        $r = try_db_connect($s_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $s_db['prefix'];
        $structure = '/%postname%/';

        // Set the option; WordPress reads this to build links and rewrite rules.
        $st = $pdo->prepare("UPDATE `{$prefix}options` SET option_value = ? WHERE option_name = 'permalink_structure'");
        $st->execute([$structure]);
        if ($st->rowCount() === 0) {
            $pdo->prepare("INSERT INTO `{$prefix}options` (option_name, option_value, autoload) VALUES ('permalink_structure', ?, 'yes')")->execute([$structure]);
        }
        // Clear cached rewrite rules so WordPress rebuilds them.
        $pdo->prepare("DELETE FROM `{$prefix}options` WHERE option_name = 'rewrite_rules'")->execute();

        // Ensure the standard WordPress rewrite block exists in .htaccess.
        $htaccess = $root . '/.htaccess';
        $wrote_htaccess = false;
        if (is_writable($root) || (is_file($htaccess) && is_writable($htaccess))) {
            if (is_file($htaccess)) @copy($htaccess, $htaccess . '.bak-' . date('YmdHis'));
            $rules = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n";
            $existing = is_file($htaccess) ? file_get_contents($htaccess) : '';
            $existing = trim(preg_replace('/# BEGIN WordPress.*?# END WordPress/s', '', $existing));
            @file_put_contents($htaccess, $rules . ($existing ? "\n" . $existing . "\n" : ''));
            $wrote_htaccess = true;
        }
        echo json_encode(['ok' => true, 'structure' => $structure, 'htaccess' => $wrote_htaccess,
            'note' => $wrote_htaccess ? 'Permalinks set and .htaccess updated.' : 'Permalinks set, but .htaccess is not writable — add the WordPress rewrite rules manually.']);
        exit;
    }

    // --- SEO: GENERATE sitemap.xml ---
    if ($action === 'seo_generate_sitemap') {
        $s_db = $seo_db();
        $root = rtrim($abspath, '/');
        $r = try_db_connect($s_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $s_db['prefix'];
        $home = rtrim((string)$pdo->query("SELECT option_value FROM `{$prefix}options` WHERE option_name='home' LIMIT 1")->fetchColumn(), '/');
        if ($home === '') { echo json_encode(['ok' => false, 'error' => 'home URL not found']); exit; }
        // Pull published posts/pages with their permalink guid/name.
        $rows = $pdo->query("SELECT post_name, post_type, post_modified_gmt, guid FROM `{$prefix}posts` WHERE post_status='publish' AND post_type IN ('post','page') ORDER BY post_modified_gmt DESC LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $xml .= "  <url><loc>" . htmlspecialchars($home . '/') . "</loc></url>\n";
        foreach ($rows as $p) {
            // Prefer a clean /post_name/ URL; fall back to the stored guid.
            $loc = $p['post_name'] !== '' ? $home . '/' . rawurlencode($p['post_name']) . '/' : $p['guid'];
            $lastmod = $p['post_modified_gmt'] && $p['post_modified_gmt'] !== '0000-00-00 00:00:00'
                ? '<lastmod>' . date('Y-m-d', strtotime($p['post_modified_gmt'])) . '</lastmod>' : '';
            $xml .= "  <url><loc>" . htmlspecialchars($loc) . "</loc>$lastmod</url>\n";
        }
        $xml .= '</urlset>' . "\n";
        $path = $root . '/sitemap.xml';
        if (@file_put_contents($path, $xml) === false) { echo json_encode(['ok' => false, 'error' => 'Could not write sitemap.xml (web root not writable)']); exit; }

        // Reference the sitemap in robots.txt (append if missing).
        $robots = $root . '/robots.txt';
        $line = 'Sitemap: ' . $home . '/sitemap.xml';
        $rc = is_file($robots) ? file_get_contents($robots) : "User-agent: *\nAllow: /\n";
        if (strpos($rc, $line) === false) { $rc = rtrim($rc) . "\n" . $line . "\n"; @file_put_contents($robots, $rc); }
        echo json_encode(['ok' => true, 'urls' => count($rows) + 1, 'sitemap_url' => $home . '/sitemap.xml']);
        exit;
    }

    // --- SEO: TOGGLE INDEXING (robots.txt + blog_public) ---
    if ($action === 'seo_toggle_index') {
        $s_db = $seo_db();
        $root = rtrim($abspath, '/');
        $allow = ($_POST['allow'] ?? '') === '1'; // allow indexing?
        $r = try_db_connect($s_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $s_db['prefix'];
        // blog_public drives WordPress' own noindex meta tag.
        $pdo->prepare("UPDATE `{$prefix}options` SET option_value = ? WHERE option_name='blog_public'")->execute([$allow ? '1' : '0']);
        // Physical robots.txt.
        $robots = $root . '/robots.txt';
        $body = $allow ? "User-agent: *\nAllow: /\n" : "User-agent: *\nDisallow: /\n";
        $wrote = @file_put_contents($robots, $body) !== false;
        echo json_encode(['ok' => true, 'allow' => $allow, 'robots_written' => $wrote,
            'note' => $allow ? 'Indexing allowed.' : 'Indexing blocked (robots.txt Disallow + blog_public=0).']);
        exit;
    }

    // --- INSTALL PHPMYADMIN ---
    // Downloads the latest phpMyAdmin and extracts it into ./phpmyadmin. No config
    // is written; the user configures the connection on first access.
    if ($action === 'pma_install') {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        $dest    = rtrim($abspath, '/') . '/phpmyadmin';
        $tmp_zip = sys_get_temp_dir() . '/pma_' . time() . '.zip';
        // Latest stable English build.
        $url     = 'https://www.phpmyadmin.net/downloads/phpMyAdmin-latest-english.zip';
        if (is_dir($dest)) { echo json_encode(['ok' => false, 'error' => 'A /phpmyadmin folder already exists. Remove it first.']); exit; }

        $downloaded = false; $dl_method = '';
        if (check_cmd('curl')) {
            exec(sprintf('curl -L --silent -o %s %s 2>&1', escapeshellarg($tmp_zip), escapeshellarg($url)), $o, $ret);
            if ($ret === 0 && is_file($tmp_zip) && filesize($tmp_zip) > 1000000) { $downloaded = true; $dl_method = 'curl CLI'; }
        }
        if (!$downloaded && check_cmd('wget')) {
            exec(sprintf('wget -q -O %s %s 2>&1', escapeshellarg($tmp_zip), escapeshellarg($url)), $o, $ret);
            if ($ret === 0 && is_file($tmp_zip) && filesize($tmp_zip) > 1000000) { $downloaded = true; $dl_method = 'wget CLI'; }
        }
        if (!$downloaded && function_exists('curl_init')) {
            $ch = curl_init($url); $fh = fopen($tmp_zip, 'wb');
            curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300]);
            curl_exec($ch); $cerr = curl_error($ch); unset($ch); fclose($fh);
            if (!$cerr && is_file($tmp_zip) && filesize($tmp_zip) > 1000000) { $downloaded = true; $dl_method = 'curl PHP'; }
        }
        if (!$downloaded && ini_get('allow_url_fopen')) {
            $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 300, 'follow_location' => true]]));
            if ($data && strlen($data) > 1000000) { file_put_contents($tmp_zip, $data); $downloaded = true; $dl_method = 'file_get_contents'; }
        }
        if (!$downloaded) { @unlink($tmp_zip); echo json_encode(['ok' => false, 'error' => 'Could not download phpMyAdmin. Check server connectivity.']); exit; }

        // Extract, then move the single top-level phpMyAdmin-* folder to ./phpmyadmin.
        $tmp_dir = rtrim($abspath, '/') . '/_pma_tmp_' . time();
        @mkdir($tmp_dir, 0755, true);
        $extracted = false; $ex_method = '';
        if (check_cmd('unzip')) {
            exec(sprintf('unzip -o -q %s -d %s 2>&1', escapeshellarg($tmp_zip), escapeshellarg($tmp_dir)), $o, $ret);
            if ($ret === 0) { $extracted = true; $ex_method = 'unzip CLI'; }
        }
        if (!$extracted && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tmp_zip) === true) { $zip->extractTo($tmp_dir); $zip->close(); $extracted = true; $ex_method = 'ZipArchive PHP'; }
        }
        @unlink($tmp_zip);
        if (!$extracted) { echo json_encode(['ok' => false, 'error' => 'Could not extract phpMyAdmin zip.']); exit; }

        $inner = glob($tmp_dir . '/phpMyAdmin-*');
        $src = ($inner && is_dir($inner[0])) ? $inner[0] : $tmp_dir;
        @rename($src, $dest);
        // Clean leftover tmp dir.
        if (is_dir($tmp_dir)) {
            foreach (glob($tmp_dir . '/*') ?: [] as $f) { if (is_dir($f)) @rmdir($f); else @unlink($f); }
            @rmdir($tmp_dir);
        }
        if (!is_dir($dest)) { echo json_encode(['ok' => false, 'error' => 'Extraction succeeded but moving to /phpmyadmin failed.']); exit; }

        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base  = rtrim(dirname($proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/')), '/');
        echo json_encode(['ok' => true, 'dl_method' => $dl_method, 'ex_method' => $ex_method, 'url' => $base . '/phpmyadmin/']);
        exit;
    }

    // --- WP EXTRAS: shared DB connection ---
    $wp_extras_db = function () use ($db) {
        return [
            'host' => $_POST['db_host'] ?? $db['host'], 'name' => $_POST['db_name'] ?? $db['name'],
            'user' => $_POST['db_user'] ?? $db['user'], 'pass' => $_POST['db_pass'] ?? $db['pass'],
            'prefix' => $_POST['db_prefix'] ?? $db['prefix'], 'charset' => 'utf8mb4',
        ];
    };

    // --- REGENERATE .htaccess (standard WordPress rules) ---
    if ($action === 'wp_fix_htaccess') {
        $root     = rtrim($abspath, '/');
        $htaccess = $root . '/.htaccess';
        if (!is_writable($root) && !(is_file($htaccess) && is_writable($htaccess))) {
            echo json_encode(['ok' => false, 'error' => 'Web root / .htaccess not writable']); exit;
        }
        // Back up any existing file first.
        if (is_file($htaccess)) @copy($htaccess, $htaccess . '.bak-' . date('YmdHis'));
        $rules = "# BEGIN WordPress\n"
            . "<IfModule mod_rewrite.c>\n"
            . "RewriteEngine On\n"
            . "RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n"
            . "RewriteBase /\n"
            . "RewriteRule ^index\\.php$ - [L]\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "RewriteCond %{REQUEST_FILENAME} !-d\n"
            . "RewriteRule . /index.php [L]\n"
            . "</IfModule>\n"
            . "# END WordPress\n";
        // Preserve any non-WordPress content outside the markers.
        $existing = is_file($htaccess) ? file_get_contents($htaccess) : '';
        $existing = trim(preg_replace('/# BEGIN WordPress.*?# END WordPress/s', '', $existing));
        $content  = $rules . ($existing ? "\n" . $existing . "\n" : '');
        $ok = @file_put_contents($htaccess, $content) !== false;
        echo json_encode(['ok' => $ok, 'error' => $ok ? '' : 'Write failed', 'note' => 'Standard WordPress rewrite rules written (previous file backed up).']);
        exit;
    }

    // --- REPAIR & OPTIMIZE TABLES ---
    if ($action === 'wp_repair_tables') {
        $x_db = $wp_extras_db();
        $r = try_db_connect($x_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo'];
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $repaired = 0; $optimized = 0; $errors = 0;
        foreach ($tables as $tbl) {
            try { $pdo->query("REPAIR TABLE `$tbl`");   $repaired++; }  catch (Exception $e) { $errors++; }
            try { $pdo->query("OPTIMIZE TABLE `$tbl`"); $optimized++; } catch (Exception $e) { $errors++; }
        }
        echo json_encode(['ok' => true, 'tables' => count($tables), 'repaired' => $repaired, 'optimized' => $optimized, 'errors' => $errors]);
        exit;
    }

    // --- FLUSH REWRITE RULES / TRANSIENTS ---
    if ($action === 'wp_flush_rewrite') {
        $x_db = $wp_extras_db();
        $r = try_db_connect($x_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $x_db['prefix'];
        // Clearing rewrite_rules forces WordPress to rebuild them on the next request.
        $d1 = $pdo->prepare("DELETE FROM `{$prefix}options` WHERE option_name = 'rewrite_rules'");  $d1->execute();
        $d2 = $pdo->exec("DELETE FROM `{$prefix}options` WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'");
        echo json_encode(['ok' => true, 'rewrite_cleared' => $d1->rowCount(), 'transients_cleared' => (int)$d2]);
        exit;
    }

    // --- TOGGLE WP_DEBUG (wp-config.php) ---
    if ($action === 'wp_toggle_debug') {
        $enable = ($_POST['enable'] ?? '') === '1';
        $target = $wp_config_path;
        if (!$target || !is_writable($target)) { echo json_encode(['ok' => false, 'error' => 'wp-config.php not found or not writable']); exit; }
        @copy($target, $target . '.bak-' . date('YmdHis'));
        $cfg = file_get_contents($target);
        $val = $enable ? 'true' : 'false';
        if (preg_match("/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*(?:true|false)\s*\)\s*;/i", $cfg)) {
            $cfg = preg_replace("/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*(?:true|false)\s*\)\s*;/i", "define('WP_DEBUG', $val);", $cfg);
        } else {
            // Insert before the "stop editing" marker, or append.
            $line = "define('WP_DEBUG', $val);\n";
            if (strpos($cfg, "/* That's all, stop editing") !== false) {
                $cfg = preg_replace("/(\/\* That's all, stop editing)/", $line . '$1', $cfg, 1);
            } else { $cfg .= "\n" . $line; }
        }
        $ok = @file_put_contents($target, $cfg) !== false;
        echo json_encode(['ok' => $ok, 'enabled' => $enable, 'error' => $ok ? '' : 'Write failed']);
        exit;
    }

    // --- TOGGLE MAINTENANCE MODE (.maintenance) ---
    if ($action === 'wp_toggle_maintenance') {
        $enable = ($_POST['enable'] ?? '') === '1';
        $root   = rtrim($abspath, '/');
        $file   = $root . '/.maintenance';
        if ($enable) {
            if (!is_writable($root)) { echo json_encode(['ok' => false, 'error' => 'Web root not writable']); exit; }
            // WordPress treats the site as under maintenance while $upgrading is recent.
            $ok = @file_put_contents($file, "<?php \$upgrading = " . time() . "; ?>") !== false;
            echo json_encode(['ok' => $ok, 'enabled' => true, 'error' => $ok ? '' : 'Write failed']);
        } else {
            $ok = !is_file($file) || @unlink($file);
            echo json_encode(['ok' => $ok, 'enabled' => false, 'error' => $ok ? '' : 'Could not remove .maintenance']);
        }
        exit;
    }

    // --- DOWNLOAD THIS TOOL ---
    if ($action === 'download_self') {
        $path = __FILE__;
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    // --- INSTALL WORDPRESS ---
    if ($action === 'wp_install') {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $dest    = rtrim($abspath, '/');
        $tmp_zip = sys_get_temp_dir() . '/wp_latest_' . time() . '.zip';
        $wp_url  = 'https://wordpress.org/latest.zip';
        $dl_method = '';
        $ex_method = '';

        // Download
        $downloaded = false;

        if (!$downloaded && check_cmd('curl')) {
            $cmd = sprintf('curl -L --silent -o %s %s 2>&1', escapeshellarg($tmp_zip), escapeshellarg($wp_url));
            exec($cmd, $o, $ret);
            if ($ret === 0 && file_exists($tmp_zip) && filesize($tmp_zip) > 1000000) {
                $downloaded = true; $dl_method = 'curl CLI';
            }
        }
        if (!$downloaded && check_cmd('wget')) {
            $cmd = sprintf('wget -q -O %s %s 2>&1', escapeshellarg($tmp_zip), escapeshellarg($wp_url));
            exec($cmd, $o, $ret);
            if ($ret === 0 && file_exists($tmp_zip) && filesize($tmp_zip) > 1000000) {
                $downloaded = true; $dl_method = 'wget CLI';
            }
        }
        if (!$downloaded && function_exists('curl_init')) {
            $ch = curl_init($wp_url);
            $fh = fopen($tmp_zip, 'wb');
            curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 180]);
            curl_exec($ch); $cerr = curl_error($ch);
            unset($ch); fclose($fh); // curl_close() deprecated in PHP 8.5 (no-op since 8.0)
            if (!$cerr && file_exists($tmp_zip) && filesize($tmp_zip) > 1000000) {
                $downloaded = true; $dl_method = 'curl PHP';
            }
        }
        if (!$downloaded && ini_get('allow_url_fopen')) {
            $ctx  = stream_context_create(['http' => ['timeout' => 180, 'follow_location' => true]]);
            $data = @file_get_contents($wp_url, false, $ctx);
            if ($data && strlen($data) > 1000000) {
                file_put_contents($tmp_zip, $data);
                $downloaded = true; $dl_method = 'file_get_contents';
            }
        }

        if (!$downloaded) {
            @unlink($tmp_zip);
            echo json_encode(['ok' => false, 'error' => 'Could not download WordPress. Check server connectivity or download manually from wordpress.org']);
            exit;
        }

        // Extract
        $tmp_dir   = $dest . '/_wp_tmp_' . time();
        @mkdir($tmp_dir, 0755, true);
        $extracted = false;

        if (!$extracted && check_cmd('unzip')) {
            $cmd = sprintf('unzip -o %s -d %s 2>&1', escapeshellarg($tmp_zip), escapeshellarg($tmp_dir));
            exec($cmd, $o, $ret);
            if ($ret === 0) { $extracted = true; $ex_method = 'unzip CLI'; }
        }
        if (!$extracted && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tmp_zip) === true) { $zip->extractTo($tmp_dir); $zip->close(); $extracted = true; $ex_method = 'ZipArchive PHP'; }
        }

        @unlink($tmp_zip);

        if (!$extracted) {
            @rmdir($tmp_dir);
            echo json_encode(['ok' => false, 'error' => 'Could not extract WordPress zip.']);
            exit;
        }

        // Move files from wordpress/ to dest
        $wp_src = is_dir($tmp_dir . '/wordpress') ? $tmp_dir . '/wordpress' : $tmp_dir;
        $moved  = 0;
        $errors = 0;

        foreach (scandir($wp_src) ?: [] as $item) {
            if ($item[0] === '.') continue;
            $src = $wp_src . '/' . $item;
            $dst = $dest . '/' . $item;
            // Preserve existing wp-content
            if ($item === 'wp-content' && is_dir($dst)) continue;
            if (is_dir($src)) {
                if (!is_dir($dst)) {
                    rename($src, $dst) ? $moved++ : $errors++;
                }
            } else {
                rename($src, $dst) ? $moved++ : $errors++;
            }
        }

        // Cleanup tmp
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isFile() ? @unlink($f->getRealPath()) : @rmdir($f->getRealPath()); }
        @rmdir($tmp_dir);

        $proto       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $install_url = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['REQUEST_URI'] ?? '/'), '/') . '/wp-admin/install.php';

        echo json_encode(['ok' => true, 'dl_method' => $dl_method, 'ex_method' => $ex_method, 'moved' => $moved, 'errors' => $errors, 'install_url' => $install_url]);
        exit;
    }
}

// ============================================================
// DETECCIÓN DE ENTORNO
// ============================================================
$site_url    = detect_site_url($db);
$current_url = current_url();
$db_test     = try_db_connect($db);
$abspath_size = is_dir($abspath) ? dir_size($abspath) : 0;

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WP Migration Tool</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f1117;color:#e2e8f0;min-height:100vh}
.wrap{max-width:900px;margin:0 auto;padding:32px 16px}
h1{font-size:22px;font-weight:700;color:#fff;margin-bottom:4px}
.subtitle{color:#718096;font-size:13px;margin-bottom:32px}
.card{background:#1a1d27;border:1px solid #2d3148;border-radius:10px;padding:24px;margin-bottom:20px}
.card h2{font-size:14px;font-weight:600;color:#a0aec0;text-transform:uppercase;letter-spacing:.08em;margin-bottom:16px}
.field{margin-bottom:12px}
.field label{display:block;font-size:12px;color:#718096;margin-bottom:4px}
.field input{width:100%;padding:8px 12px;background:#0f1117;border:1px solid #2d3148;border-radius:6px;color:#e2e8f0;font-size:14px;outline:none}
.field input:focus{border-color:#4f6ef7}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:600px){.row{grid-template-columns:1fr}}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:.15s}
.btn-primary{background:#4f6ef7;color:#fff}.btn-primary:hover{background:#3d5ce6}
.btn-success{background:#22c55e;color:#fff}.btn-success:hover{background:#16a34a}
.btn-warning{background:#f59e0b;color:#fff}.btn-warning:hover{background:#d97706}
.btn-danger{background:#ef4444;color:#fff}.btn-danger:hover{background:#dc2626}
.btn-sm{padding:6px 12px;font-size:12px}
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600}
.badge-ok{background:#064e3b;color:#6ee7b7}
.badge-err{background:#7f1d1d;color:#fca5a5}
.badge-warn{background:#78350f;color:#fcd34d}
.info-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #2d3148;font-size:13px}
.info-row:last-child{border-bottom:none}
.info-label{color:#718096}
.info-val{color:#e2e8f0;font-family:monospace;font-size:12px}
.log{background:#0f1117;border:1px solid #2d3148;border-radius:6px;padding:12px;font-family:monospace;font-size:12px;color:#6ee7b7;min-height:60px;max-height:200px;overflow-y:auto;margin-top:12px;white-space:pre-wrap}
.tabs{display:flex;gap:2px;margin-bottom:20px;background:#0f1117;border-radius:8px;padding:4px}
.tab{flex:1;padding:8px;text-align:center;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500;color:#718096;border:none;background:none}
.tab.active{background:#1a1d27;color:#fff}
.section{display:none}.section.active{display:block}
.alert{padding:12px 16px;border-radius:6px;font-size:13px;margin-bottom:16px}
.alert-warn{background:#78350f22;border:1px solid #d97706;color:#fcd34d}
.alert-ok{background:#064e3b22;border:1px solid #22c55e;color:#6ee7b7}
.alert-err{background:#7f1d1d22;border:1px solid #ef4444;color:#fca5a5}
.instructions{background:#0f1117;border:1px solid #2d3148;border-radius:6px;padding:16px;font-size:12px;color:#a0aec0;line-height:1.7}
.instructions h4{color:#e2e8f0;margin-bottom:8px;font-size:13px}
.instructions ol{padding-left:18px}
.instructions code{background:#1a1d27;padding:1px 5px;border-radius:3px;color:#7dd3fc}
.progress{height:6px;background:#2d3148;border-radius:3px;overflow:hidden;margin-top:8px}
.progress-bar{height:100%;background:#4f6ef7;transition:width .3s;border-radius:3px}
.auth-wrap{max-width:380px;margin:80px auto;padding:0 16px}
.auth-wrap .card{text-align:center}
.auth-wrap h1{font-size:20px;margin-bottom:4px}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
</style>
</head>
<body>
<div class="wrap">

<?php if (!$authenticated): ?>
<!-- ====================================================== AUTH ====================================================== -->
<div class="auth-wrap">
<div class="card">
<h1>🔐 WP Migration Tool</h1>
<p class="subtitle" style="margin-bottom:20px">
    <?php if ($must_set_password): ?>
        <?= h(t('Set a password to protect this tool')) ?>
    <?php elseif ($stored_hash): ?>
        <?= h(t('Enter password to continue')) ?>
    <?php else: ?>
        <?= h(t('Enter the database password from wp-config.php')) ?>
    <?php endif; ?>
</p>
<?php if ($auth_error): ?>
<div class="alert alert-err"><?= h($auth_error) ?></div>
<?php endif; ?>

<?php if ($must_set_password): ?>
<div class="alert alert-warn" style="text-align:left">
    <?= h(t('The database password in wp-config.php is empty, so the tool would be unprotected. Create a password now — it is stored encrypted and required on every access.')) ?>
</div>
<form method="POST">
    <div class="field"><label><?= h(t('New password')) ?></label><input type="password" name="new_password" minlength="8" required autofocus></div>
    <div class="field"><label><?= h(t('Confirm')) ?></label><input type="password" name="new_password2" minlength="8" required></div>
    <button type="submit" name="set_password" class="btn btn-primary" style="width:100%;justify-content:center"><?= h(t('Create password')) ?></button>
</form>
<?php else: ?>
<?php if (!$stored_hash): ?>
<div class="alert" style="text-align:left;background:#1e2235;color:#a0aec0;border:1px solid #2d3148">
    <?= h(t('Use the database password defined in wp-config.php (DB_PASSWORD) to log in. Once inside you can set your own encrypted password.')) ?>
</div>
<?php endif; ?>
<form method="POST">
    <div class="field"><label><?= h(t('Password')) ?></label><input type="password" name="password" required autofocus></div>
    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center"><?= h(t('Login')) ?></button>
</form>
<?php endif; ?>
</div>
</div>

<?php else: ?>
<!-- ====================================================== MAIN APP ====================================================== -->

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
<div>
    <h1>🚀 WP Migration Tool <span style="font-size:13px;font-weight:400;color:#4f6ef7;margin-left:6px">v<?= TOOL_VERSION ?></span></h1>
    <div class="subtitle"><?= h(t('WordPress Migration · Export & Import')) ?></div>
</div>
<div style="display:flex;gap:8px;align-items:center">
<span style="display:inline-flex;border:1px solid #2d3148;border-radius:6px;overflow:hidden;font-size:12px">
    <a href="?lang=en" style="padding:5px 10px;text-decoration:none;<?= $LANG==='en'?'background:#4f6ef7;color:#fff':'background:#2d3148;color:#a0aec0' ?>">EN</a>
    <a href="?lang=es" style="padding:5px 10px;text-decoration:none;<?= $LANG==='es'?'background:#4f6ef7;color:#fff':'background:#2d3148;color:#a0aec0' ?>">ES</a>
</span>
<a href="?action=download_self" class="btn btn-sm" style="background:#2d3148;color:#a0aec0;text-decoration:none" download>⬇️ <?= h(t('Download tool')) ?></a>
<a href="?logout" class="btn btn-sm" style="background:#2d3148;color:#a0aec0"><?= h(t('Logout')) ?></a>
</div>
</div>

<?php if (!is_localhost()): ?>
<div class="alert alert-warn">⚠️ <strong><?= h(t('Running on live server.')) ?></strong> <?= h(t('Delete this file after use!')) ?></div>
<?php endif; ?>

<!-- ACCESS PASSWORD -->
<div class="card" style="border-color:#2d3148">
<h2>🔑 <?= h(t('Access Password')) ?></h2>
<p style="font-size:12px;color:#718096;margin-bottom:12px">
    <?php if ($stored_hash): ?>
        <?= h(t('Access is protected by a custom encrypted password. Set a new one below to rotate it.')) ?>
    <?php else: ?>
        <?= h(t('Access currently uses the wp-config.php database password. Set a custom encrypted password below to use that instead.')) ?>
    <?php endif; ?>
</p>
<form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <div class="field" style="margin:0"><label><?= h($stored_hash ? t('New password') : t('Custom password')) ?></label>
        <input type="password" name="new_password" minlength="8" required style="width:200px"></div>
    <div class="field" style="margin:0"><label><?= h(t('Confirm')) ?></label>
        <input type="password" name="new_password2" minlength="8" required style="width:200px"></div>
    <button type="submit" name="set_password" class="btn btn-primary"><?= h($stored_hash ? t('Rotate') : t('Set password')) ?></button>
</form>
</div>

<?php if (!$wp_config_path): ?>
<div class="card" style="border-color:#4f6ef7;background:#1a1d27">
<h2 style="color:#7dd3fc">📦 WordPress Not Found</h2>
<p style="font-size:13px;color:#a0aec0;margin-bottom:6px">
    No <code>wp-config.php</code> found in <code><?= h(rtrim($abspath, '/')) ?></code> or parent directories.
</p>
<p style="font-size:13px;color:#a0aec0;margin-bottom:18px">
    Do you want to download and install the latest version of WordPress here?<br>
    <span style="color:#718096;font-size:12px">
        Downloads from wordpress.org/latest.zip · Extracts to current directory · Redirects to WP installer
    </span>
</p>
<div class="actions">
    <button id="wp_install_btn" class="btn btn-primary" onclick="installWordPress()">⬇️ Download &amp; Install WordPress</button>
</div>
<div id="wp_install_log" class="log" style="display:none;margin-top:12px"></div>
</div>
<?php endif; ?>

<!-- STATUS -->
<div class="card">
<h2>📊 <?= h(t('Environment Status')) ?></h2>
<div class="info-row">
    <span class="info-label">Current URL</span>
    <span class="info-val"><?= h($current_url) ?></span>
</div>
<div class="info-row">
    <span class="info-label">DB site_url</span>
    <span class="info-val">
        <?= h($site_url ?: '—') ?>
        <?php if ($site_url && rtrim($site_url,'/') === rtrim($current_url,'/')): ?>
            <span class="badge badge-ok">✓ match</span>
        <?php elseif ($site_url): ?>
            <span class="badge badge-warn">⚠ mismatch</span>
        <?php endif; ?>
    </span>
</div>
<div class="info-row">
    <span class="info-label">DB Connection</span>
    <span class="badge <?= $db_test['ok'] ? 'badge-ok' : 'badge-err' ?>">
        <?= $db_test['ok'] ? '✓ OK — ' . h($db['name']) . '@' . h($db['host']) : '✗ ' . h($db_test['error'] ?? '') ?>
    </span>
</div>
<div class="info-row">
    <span class="info-label">Hosting size</span>
    <span class="info-val"><?= human_size($abspath_size) ?> <span style="color:#4a5568">(<?= h($abspath) ?>)</span></span>
</div>
<div class="info-row">
    <span class="info-label">wp-config.php</span>
    <span class="badge <?= $wp_config_path ? 'badge-ok' : 'badge-err' ?>"><?= $wp_config_path ? '✓ found' : '✗ not found' ?></span>
</div>
<div class="info-row">
    <span class="info-label">CLI available / CLI disponible</span>
    <span class="info-val">
        <?php foreach (['mysqldump','mysql','zip','unzip','tar'] as $cmd): ?>
        <span class="badge <?= check_cmd($cmd) ? 'badge-ok' : 'badge-err' ?>"><?= $cmd ?></span>
        <?php endforeach; ?>
    </span>
</div>
</div>

<!-- CACHE -->
<div class="card" style="border-color:#78350f44">
<h2 style="display:flex;justify-content:space-between;align-items:center">
    <span>🧹 <?= h(t('Clear Cache')) ?></span>
</h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    Clears <code>wp-content/et-cache/</code> (Divi) and <code>wp-content/cache/</code> (WP). Divi regenerates on next page visit.
</p>
<div class="actions">
    <button id="clear-cache-btn" class="btn btn-warning" onclick="clearCache()">🗑️ Clear WP + Divi Cache</button>
</div>
<div id="cache_log" class="log" style="display:none"></div>
</div>

<!-- TABS -->
<div class="tabs">
    <button class="tab active" onclick="showTab('export')">📤 <?= h(t('Export (Origin)')) ?></button>
    <button class="tab" onclick="showTab('import')">📥 <?= h(t('Import (Destination)')) ?></button>
    <button class="tab" onclick="showTab('info')">ℹ️ <?= h(t('How to use')) ?></button>
    <button class="tab" onclick="showTab('tools')">🛠️ <?= h(t('DB Tools')) ?></button>
</div>

<!-- ===== EXPORT TAB ===== -->
<div id="tab-export" class="section active">

<div class="card">
<h2>🗄️ <?= h(t('Database Export')) ?></h2>
<div class="row">
    <div class="field"><label>DB Host</label><input type="text" id="exp_db_host" value="<?= h($db['host']) ?>"></div>
    <div class="field"><label>DB Name</label><input type="text" id="exp_db_name" value="<?= h($db['name']) ?>"></div>
</div>
<div class="row">
    <div class="field"><label>DB User</label><input type="text" id="exp_db_user" value="<?= h($db['user']) ?>"></div>
    <div class="field"><label>DB Password</label><input type="password" id="exp_db_pass" value="<?= h($db['pass']) ?>"></div>
</div>
<div class="row">
    <div class="field"><label>Table prefix</label><input type="text" id="exp_db_prefix" value="<?= h($db['prefix']) ?>"></div>
    <div class="field"><label></label>
        <div style="display:flex;gap:8px;margin-top:22px">
            <button class="btn btn-sm" style="background:#2d3148;color:#a0aec0" onclick="testDB('exp')">🔌 Test connection</button>
            <button class="btn btn-sm" style="background:#1e3a2f;color:#6ee7b7" onclick="reloadWpConfig()">↺ wp-config</button>
        </div>
    </div>
</div>
<div id="exp_db_status"></div>
</div>

<div class="card">
<h2>🔗 URL Replacement</h2>
<div class="row">
    <div class="field">
        <label>Source URL (origin)</label>
        <input type="text" id="exp_source_url" value="<?= h($site_url ?: $current_url) ?>" placeholder="https://origin.com">
    </div>
    <div class="field">
        <label>Target URL (destination)</label>
        <input type="text" id="exp_target_url" placeholder="https://destination.com" oninput="localStorage.setItem('migrate_target_url',this.value)">
    </div>
</div>
<p style="font-size:11px;color:#718096;margin-top:4px">
    URL will be replaced everywhere in the SQL including serialized data.
</p>
</div>

<div class="card">
<h2>📦 Export Actions</h2>
<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;font-size:13px;color:#a0aec0">
    <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
        <input type="checkbox" id="exp_split" onchange="document.getElementById('exp_split_size').disabled=!this.checked">
        Split into size-limited <code>.zip</code> parts
    </label>
    <label style="display:flex;align-items:center;gap:6px">
        max part size:
        <select id="exp_split_size" disabled style="padding:5px 8px;background:#0f1117;border:1px solid #2d3148;border-radius:4px;color:#e2e8f0;font-size:13px">
            <?php foreach ([1,2,3,4,5,6,7,8,9,10] as $mb): ?>
            <option value="<?= $mb ?>"<?= $mb === 2 ? ' selected' : '' ?>><?= $mb ?> MB</option>
            <?php endforeach; ?>
        </select>
    </label>
</div>
<p style="font-size:11px;color:#718096;margin-bottom:12px">
    Splitting cuts the dump at full SQL statement boundaries and compresses each part so every
    <code>.zip</code> stays under the chosen size — import them in order (part 001, 002, …) on
    hosts that reject large uploads. Each part imports standalone.
</p>
<div class="actions">
    <button class="btn btn-primary" onclick="exportDB()">🗄️ Export DB (.sql.gz)</button>
    <button class="btn btn-warning" onclick="exportZip()">📁 Export Hosting (.zip)</button>
</div>
<div id="exp_progress" style="display:none" class="progress"><div class="progress-bar" id="exp_progress_bar" style="width:0%"></div></div>
<div id="exp_log" class="log" style="display:none"></div>
<div id="exp_parts" style="display:none;margin-top:12px"></div>
</div>

</div><!-- /export -->

<!-- ===== IMPORT TAB ===== -->
<div id="tab-import" class="section">

<div class="alert alert-warn">
    ⚠️ <strong>Run this on the DESTINATION server.</strong>
    Make sure the URL in the DB matches this server's URL before importing.
</div>

<div class="card">
<h2>🗄️ Destination DB</h2>
<div class="row">
    <div class="field"><label>DB Host</label><input type="text" id="imp_db_host" value="<?= h($db['host']) ?>"></div>
    <div class="field"><label>DB Name</label><input type="text" id="imp_db_name" value="<?= h($db['name']) ?>"></div>
</div>
<div class="row">
    <div class="field"><label>DB User</label><input type="text" id="imp_db_user" value="<?= h($db['user']) ?>"></div>
    <div class="field"><label>DB Password</label><input type="password" id="imp_db_pass" value="<?= h($db['pass']) ?>"></div>
</div>
<div class="row">
    <div class="field"><label>Table prefix</label><input type="text" id="imp_db_prefix" value="<?= h($db['prefix']) ?>"></div>
    <div class="field"><label>Expected destination URL</label>
        <input type="text" id="imp_dest_url" value="<?= h($current_url) ?>" placeholder="https://destination.com">
    </div>
</div>
<button class="btn btn-sm" style="background:#2d3148;color:#a0aec0;margin-top:8px" onclick="testDB('imp')">🔌 Test connection</button>
<div id="imp_db_status"></div>
</div>

<div class="card">
<h2>📥 <?= h(t('Import SQL')) ?></h2>
<div class="field">
    <label>SQL file (.sql or .sql.gz) — max <?= ini_get('upload_max_filesize') ?></label>
    <input type="file" id="imp_sql_file" accept=".sql,.gz" style="color:#a0aec0">
</div>
<div class="actions">
    <button class="btn btn-success" onclick="importDB()">🗄️ Import DB</button>
</div>
<div class="alert alert-warn" style="margin-top:12px;font-size:12px">
    <strong>Large files?</strong> Upload the .sql.gz to the server and use
    <a href="?action=bigdump" style="color:#fcd34d">BigDump</a> for chunk-based import.
</div>
<div id="imp_log" class="log" style="display:none"></div>
</div>

<div class="card">
<h2>📁 Extract Hosting</h2>

<div style="margin-bottom:12px">
    <button class="btn btn-sm" style="background:#2d3148;color:#a0aec0" onclick="scanFiles()">🔍 Scan files on server</button>
    <span style="font-size:12px;color:#718096;margin-left:8px"><?= h(rtrim($abspath, '/')) ?>/</span>
</div>
<div id="imp_scan_result" style="display:none;margin-bottom:12px"></div>

<div class="field" style="margin-top:8px">
    <label>Or upload a file (.zip, .tar.gz) — max <?= ini_get('upload_max_filesize') ?></label>
    <input type="file" id="imp_zip_file" accept=".zip,.gz" style="color:#a0aec0">
</div>
<div class="actions">
    <button class="btn btn-warning" onclick="extractZip()">📁 Extract uploaded file</button>
</div>
<div id="imp_zip_log" class="log" style="display:none"></div>
</div>

</div><!-- /import -->

<!-- ===== INFO TAB ===== -->
<div id="tab-info" class="section">
<div class="card">
<h2>📖 How to migrate</h2>

<div class="instructions">
<h4>Step by step</h4>
<ol>
<li>On the <strong>origin server</strong>: open this tool, go to <strong>Export</strong> tab.</li>
<li>Set the source URL (origin) and target URL (destination). Click <strong>Export DB</strong>.</li>
<li>Download the <code>.sql.gz</code> file.</li>
<li>Click <strong>Export Hosting</strong> to download the full site zip.</li>
<li>On the <strong>destination server</strong>:
    <ol>
        <li>Create a database in phpMyAdmin:<br>
            <code>Databases → Create database → name it → Collation: utf8mb4_unicode_ci</code></li>
        <li>Create a DB user and assign full privileges.</li>
        <li>Upload and extract the hosting zip to the destination folder.</li>
        <li>Update <code>wp-config.php</code> with the new DB credentials.</li>
        <li>Open this tool on the destination, go to <strong>Import</strong> tab.</li>
        <li>Upload the <code>.sql.gz</code> and click <strong>Import DB</strong>.</li>
        <li>The tool will verify the URL matches before importing.</li>
    </ol>
</li>
<li>If the SQL is very large (&gt;50MB), use <a href="?action=bigdump" style="color:#7dd3fc">BigDump</a> instead.</li>
</ol>
</div>

<div class="instructions" style="margin-top:16px">
<h4>⚠️ Divi / WordPress notes</h4>
<ul style="padding-left:18px">
<li>The URL replacement handles <strong>serialized PHP strings</strong> (Divi stores layout data serialized).</li>
<li>After import, if Divi looks broken: Dashboard → Divi → <strong>Clear All Caches</strong>.</li>
<li>Also delete: <code>wp-content/cache/</code> and <code>wp-content/et-cache/</code>.</li>
<li>If you see the old domain in the DB, run: <code>UPDATE wp_options SET option_value = REPLACE(option_value, 'old.com', 'new.com');</code></li>
</ul>
</div>
</div>
</div><!-- /info -->

<!-- ===== TOOLS TAB ===== -->
<div id="tab-tools" class="section">

<div class="card">
<h2>🔌 DB Connection for Tools</h2>
<div class="row">
    <div class="field"><label>DB Host</label><input type="text" id="tls_db_host" value="<?= h($db['host']) ?>"></div>
    <div class="field"><label>DB Name</label><input type="text" id="tls_db_name" value="<?= h($db['name']) ?>"></div>
</div>
<div class="row">
    <div class="field"><label>DB User</label><input type="text" id="tls_db_user" value="<?= h($db['user']) ?>"></div>
    <div class="field"><label>DB Password</label><input type="password" id="tls_db_pass" value="<?= h($db['pass']) ?>"></div>
</div>
<div class="row">
    <div class="field"><label>Table prefix</label><input type="text" id="tls_db_prefix" value="<?= h($db['prefix']) ?>"></div>
    <div class="field"><label>&nbsp;</label>
        <button class="btn btn-sm" style="background:#2d3148;color:#a0aec0;margin-top:22px" onclick="testDB('tls')">🔌 Test connection</button>
    </div>
</div>
<div id="tls_db_status"></div>
</div>

<div class="card">
<h2>🌐 Site URL Manager</h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    Updates <code>siteurl</code> and <code>home</code> in wp_options. Switch between http and https without touching wp-config.php.
</p>
<div id="tls_siteurl_current" style="margin-bottom:16px;font-family:monospace;font-size:13px;color:#a0aec0">
    <?php if ($site_url): ?>
    Current DB site_url: <strong style="color:#e2e8f0"><?= h($site_url) ?></strong>
    <?php else: ?>
    <em>Connect to DB first, then click Refresh</em>
    <?php endif; ?>
</div>
<div class="actions">
    <button class="btn btn-success" onclick="updateSiteUrlProtocol('https')">🔒 Switch to HTTPS</button>
    <button class="btn btn-warning" onclick="updateSiteUrlProtocol('http')">🔓 Switch to HTTP</button>
    <button class="btn btn-sm" style="background:#2d3148;color:#a0aec0" onclick="refreshSiteUrl()">↺ Refresh current URL</button>
</div>
<div id="tls_siteurl_log" class="log" style="display:none"></div>
</div>

<div class="card">
<h2>🖼️ Media URL Scanner</h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    Scans posts, postmeta and options for absolute <code>http(s)://domain/wp-content/</code> URLs in images, icons and videos,
    then converts them to root-relative <code>/wp-content/</code> paths. Handles serialized data correctly.
</p>
<div class="actions">
    <button class="btn btn-primary" onclick="scanMediaUrls()">🔍 Scan DB for absolute URLs</button>
    <button id="tls_fix_btn" class="btn btn-danger" onclick="fixMediaUrls()" style="display:none">⚡ Fix — Convert to /wp-content/</button>
</div>
<div id="tls_media_log" class="log" style="display:none"></div>
</div>

<div class="card">
<h2>🌍 Domain Link Manager</h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    Scans posts, postmeta and options for every absolute <code>http(s)://domain/</code> link,
    excluding the current site. Review the list of linked domains with their counts and select the
    ones to convert to root-relative <code>/path</code> links — useful to remove references to old
    hostings (e.g. <code>igs-bonn.de/wordpress</code>) while keeping legitimate external links.
    The base path to drop (e.g. the old <code>/wordpress</code> subfolder) is recommended by
    verifying referenced files exist on disk, so you don't create links to missing files.
    Serialized data is handled correctly.
</p>
<div class="actions" style="margin-bottom:12px">
    <button class="btn btn-primary" onclick="scanDomains()">🔍 Scan DB for linked domains</button>
    <button id="tls_dom_fix_btn" class="btn btn-danger" onclick="fixDomains()" style="display:none">⚡ Convert selected to relative</button>
</div>
<div id="tls_domains_list"></div>
<div id="tls_domains_log" class="log" style="display:none"></div>
</div>

<div class="card">
<h2>👤 <?= h(t('WordPress Users')) ?></h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    <?= h(t('List WordPress users, change passwords, edit details or create new users directly in the database. Useful when WP admin is inaccessible after a migration.')) ?>
</p>
<div class="actions" style="margin-bottom:16px">
    <button class="btn btn-primary" onclick="loadWpUsers()">🔍 <?= h(t('Load users')) ?></button>
    <button id="tls_user_new_btn" class="btn btn-success" style="display:none" onclick="toggleNewUserForm()">➕ <?= h(t('New user')) ?></button>
</div>
<div id="tls_newuser_form" style="display:none;background:#0f1117;border:1px solid #2d3148;border-radius:6px;padding:14px;margin-bottom:14px">
    <div class="row">
        <div class="field"><label><?= h(t('Username')) ?></label><input type="text" id="nu_login"></div>
        <div class="field"><label><?= h(t('Email')) ?></label><input type="email" id="nu_email"></div>
    </div>
    <div class="row">
        <div class="field"><label><?= h(t('Display name')) ?></label><input type="text" id="nu_display"></div>
        <div class="field"><label><?= h(t('Password')) ?></label><input type="text" id="nu_pass"></div>
    </div>
    <div class="row">
        <div class="field"><label><?= h(t('Role')) ?></label>
            <select id="nu_role" style="width:100%;padding:8px 12px;background:#0f1117;border:1px solid #2d3148;border-radius:6px;color:#e2e8f0">
                <option value="administrator">Administrator</option>
                <option value="editor">Editor</option>
                <option value="author">Author</option>
                <option value="contributor">Contributor</option>
                <option value="subscriber" selected>Subscriber</option>
            </select></div>
        <div class="field"><label>&nbsp;</label><button class="btn btn-success" style="margin-top:0" onclick="createWpUser()"><?= h(t('Create user')) ?></button></div>
    </div>
</div>
<div id="tls_users_list"></div>
<div id="tls_users_log" class="log" style="display:none"></div>
</div>

<div class="card">
<h2>🔌 Plugin Manager</h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    Lists all installed plugins and lets you activate or deactivate them directly in the database.
    Useful when WP admin is inaccessible after a migration.
</p>
<div class="actions" style="margin-bottom:16px">
    <button class="btn btn-primary" onclick="loadPlugins()">🔍 Load plugins</button>
    <button id="tls_deactivate_all_btn" class="btn btn-danger" style="display:none" onclick="deactivateAllPlugins()">⛔ Deactivate ALL</button>
    <button id="tls_save_plugins_btn" class="btn btn-success" style="display:none" onclick="savePluginSelection()">💾 Save selection</button>
</div>
<div id="tls_plugins_list"></div>
<div id="tls_plugins_log" class="log" style="display:none"></div>
</div>

<div class="card">
<h2>⚙️ PHP Configuration</h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    Current PHP settings vs. WordPress recommendations. Saves to <code>.htaccess</code> and/or <code>user.ini</code> in the site root.
    Changes apply on the next request (some hosts need a server restart).
</p>
<table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:14px">
<thead>
<tr style="color:#718096;text-align:left">
    <th style="padding:4px 8px;border-bottom:1px solid #2d3148">Setting</th>
    <th style="padding:4px 8px;border-bottom:1px solid #2d3148">Current</th>
    <th style="padding:4px 8px;border-bottom:1px solid #2d3148">Recommended</th>
    <th style="padding:4px 8px;border-bottom:1px solid #2d3148">New value</th>
</tr>
</thead>
<tbody>
<?php
$php_cfg_rows = [
    ['key' => 'memory_limit',        'rec' => '256M',  'note' => 'min 128M'],
    ['key' => 'upload_max_filesize', 'rec' => '64M',   'note' => ''],
    ['key' => 'post_max_size',       'rec' => '64M',   'note' => '≥ upload_max_filesize'],
    ['key' => 'max_execution_time',  'rec' => '300',   'note' => 'seconds; 0 = unlimited'],
    ['key' => 'max_input_vars',      'rec' => '3000',  'note' => 'user.ini only'],
];
foreach ($php_cfg_rows as $r):
    $cur = ini_get($r['key']);
?>
<tr style="border-bottom:1px solid #1a1d27">
    <td style="padding:5px 8px;font-family:monospace;color:#a0aec0"><?= $r['key'] ?></td>
    <td style="padding:5px 8px;font-family:monospace"><?= h($cur) ?></td>
    <td style="padding:5px 8px;font-family:monospace;color:#6ee7b7"><?= $r['rec'] ?><?= $r['note'] ? ' <span style="color:#718096;font-size:11px">(' . $r['note'] . ')</span>' : '' ?></td>
    <td style="padding:5px 8px"><input type="text" id="phpcfg_<?= $r['key'] ?>" placeholder="e.g. <?= $r['rec'] ?>" style="width:90px;padding:3px 6px;background:#1a1d27;border:1px solid #2d3148;border-radius:4px;color:#e2e8f0;font-size:12px;font-family:monospace"></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
    <label style="font-size:12px;color:#a0aec0"><?= h(t('Method')) ?>:
        <select id="phpcfg_method" style="padding:5px 8px;background:#0f1117;border:1px solid #2d3148;border-radius:4px;color:#e2e8f0;font-size:12px">
            <option value="auto"><?= h(t('Auto-detect (test both)')) ?></option>
            <option value="htaccess">.htaccess (Apache)</option>
            <option value="user_ini">.user.ini (PHP-FPM/CGI)</option>
        </select>
    </label>
</div>
<p style="font-size:11px;color:#718096;margin-bottom:10px">
    <?= h(t('Test runs the proposed config in an isolated temporary subfolder and loads it over HTTP. If it would break the site (HTTP 500) you are warned and Apply stays disabled; the test folder only affects itself, never the live site.')) ?>
</p>
<div class="actions" style="margin-bottom:10px">
    <button class="btn btn-warning" id="phpcfg_test_btn" onclick="testPhpConfig()">🧪 <?= h(t('Test safely')) ?></button>
    <button class="btn btn-primary" id="phpcfg_apply_btn" onclick="savePhpConfig()" disabled style="opacity:.5;cursor:not-allowed">💾 <?= h(t('Apply')) ?></button>
</div>
<div id="tls_phpcfg_test"></div>
<div id="tls_phpcfg_log" class="log" style="display:none"></div>
</div>

<div class="card" style="border-color:#1e3a2f">
<h2>🔎 <?= h(t('SEO') ) ?></h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    <?= h(t('Audit SEO basics (indexability, title/tagline, friendly permalinks, sitemap, robots.txt, HTTPS), edit the title and tagline, toggle indexing, generate a sitemap, request indexing and check if the site is indexed. Uses the DB connection configured above.')) ?>
</p>
<div class="actions" style="margin-bottom:14px">
    <button id="tls_seo_btn" class="btn btn-primary" onclick="runSeoAudit()">🔎 <?= h(t('Run SEO Audit')) ?></button>
</div>
<div id="tls_seo_results" style="margin-bottom:14px"></div>

<div style="background:#0f1117;border:1px solid #2d3148;border-radius:6px;padding:14px;margin-bottom:14px">
    <div style="font-size:12px;color:#a0aec0;margin-bottom:8px"><?= h(t('Basic meta')) ?></div>
    <div class="row">
        <div class="field"><label><?= h(t('Site title')) ?></label><input type="text" id="seo_blogname"></div>
        <div class="field"><label><?= h(t('Tagline')) ?></label><input type="text" id="seo_blogdescription"></div>
    </div>
    <button class="btn btn-sm btn-success" onclick="seoUpdateMeta()"><?= h(t('Save meta')) ?></button>
</div>

<div style="background:#0f1117;border:1px solid #2d3148;border-radius:6px;padding:14px;margin-bottom:14px">
    <div style="font-size:12px;color:#a0aec0;margin-bottom:10px"><?= h(t('SEO actions')) ?></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <button class="btn btn-sm btn-warning" onclick="seoSetPermalinks()">🔗 <?= h(t('Set friendly permalinks')) ?></button>
        <button class="btn btn-sm btn-warning" onclick="seoGenerateSitemap()">🗺️ <?= h(t('Generate sitemap.xml')) ?></button>
        <span style="font-size:12px;color:#a0aec0;margin-left:8px"><?= h(t('Indexing')) ?>:</span>
        <button class="btn btn-sm btn-success" onclick="seoToggleIndex(true)">✅ <?= h(t('Allow')) ?></button>
        <button class="btn btn-sm btn-danger" onclick="seoToggleIndex(false)">🚫 <?= h(t('Block')) ?></button>
    </div>
    <div id="tls_seo_links" style="margin-top:12px;font-size:12px"></div>
</div>
<div id="tls_seo_log" class="log" style="display:none"></div>
</div>

<div class="card" style="border-color:#7f1d1d44">
<h2>🛡️ <?= h(t('Security Audit')) ?></h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    <?= h(t('Hardening checks across users, WordPress core, filesystem and attack surface: weak/common passwords, default admin username, outdated WP version, default security keys, file permissions, exposed dumps, XML-RPC and REST exposure. Uses the DB connection configured above.')) ?>
</p>
<div class="actions" style="margin-bottom:16px">
    <button id="tls_audit_btn" class="btn btn-danger" onclick="runSecurityAudit()">🛡️ <?= h(t('Run Security Audit')) ?></button>
</div>
<div id="tls_audit_results"></div>
</div>

<div class="card">
<h2>🏥 Site Health Check</h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    Audits media files, active plugins, themes, DB tables, WP core files, filesystem permissions, and PHP environment.
    Uses the DB connection configured above.
</p>
<div class="actions" style="margin-bottom:16px">
    <button id="tls_health_btn" class="btn btn-primary" onclick="runHealthCheck()">🔍 Run Health Check</button>
</div>
<div id="tls_health_results"></div>
</div>

<div class="card">
<h2>🧰 <?= h(t('WordPress Extras')) ?></h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    <?= h(t('Common post-migration fixes. Each action uses the DB connection configured above or writes to the site root.')) ?>
</p>
<div class="actions" style="flex-direction:column;align-items:stretch;gap:10px">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button class="btn btn-warning" onclick="wpFixHtaccess()">📝 <?= h(t('Regenerate .htaccess + permalinks')) ?></button>
        <span style="font-size:11px;color:#718096"><?= h(t('Fixes 404s on all pages after a migration.')) ?></span>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button class="btn btn-warning" onclick="wpRepairTables()">🔧 <?= h(t('Repair & optimize tables')) ?></button>
        <span style="font-size:11px;color:#718096"><?= h(t('Runs REPAIR and OPTIMIZE on every table.')) ?></span>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button class="btn btn-warning" onclick="wpFlushRewrite()">🔄 <?= h(t('Flush rewrite rules + transients')) ?></button>
        <span style="font-size:11px;color:#718096"><?= h(t('Clears cached rewrite rules and transients.')) ?></span>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <span style="font-size:12px;color:#a0aec0"><?= h(t('WP_DEBUG')) ?>:</span>
        <button class="btn btn-sm btn-success" onclick="wpToggleDebug(true)"><?= h(t('On')) ?></button>
        <button class="btn btn-sm" style="background:#2d3148;color:#a0aec0" onclick="wpToggleDebug(false)"><?= h(t('Off')) ?></button>
        <span style="font-size:12px;color:#a0aec0;margin-left:12px"><?= h(t('Maintenance mode')) ?>:</span>
        <button class="btn btn-sm btn-warning" onclick="wpToggleMaintenance(true)"><?= h(t('On')) ?></button>
        <button class="btn btn-sm" style="background:#2d3148;color:#a0aec0" onclick="wpToggleMaintenance(false)"><?= h(t('Off')) ?></button>
    </div>
</div>
<div id="tls_extras_log" class="log" style="display:none;margin-top:12px"></div>
</div>

<div class="card">
<h2>🗃️ phpMyAdmin</h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    <?= h(t('Download the latest phpMyAdmin and extract it into a /phpmyadmin folder. No config is written — set the connection on first access. Remove the folder when you are done.')) ?>
</p>
<div class="actions">
    <button id="tls_pma_btn" class="btn btn-primary" onclick="installPhpMyAdmin()">⬇️ <?= h(t('Download & install phpMyAdmin')) ?></button>
</div>
<div id="tls_pma_log" class="log" style="display:none;margin-top:12px"></div>
</div>

</div><!-- /tools -->

<?php endif; ?>

<!-- ===== EXPORT FILES ===== -->
<?php if ($authenticated): ?>
<div class="card" id="exports-card" style="margin-top:0">
<h2 style="display:flex;justify-content:space-between;align-items:center">
    <span>📦 Export files</span>
    <button class="btn btn-sm" style="background:#2d3148;color:#a0aec0" onclick="loadExports()">↺ Refresh</button>
</h2>
<div id="exports-list"><span style="color:#718096;font-size:13px">Loading...</span></div>
</div>
<script>
async function loadExports(){
    const el = document.getElementById('exports-list');
    el.innerHTML='<span style="color:#718096;font-size:13px">Loading...</span>';
    const r = await fetch('?action=list_exports',{method:'POST'});
    const j = await r.json();
    if(!j.files.length){ el.innerHTML='<span style="color:#718096;font-size:13px">No export files found.</span>'; return; }
    let html='<table style="width:100%;font-size:13px"><tr><th>File</th><th>Size</th><th>Date</th><th style="text-align:right">Actions</th></tr>';
    j.files.forEach(f=>{
        const icon = f.name.startsWith('hosting_') ? '📦' : '🗄️';
        html+=`<tr>
            <td>${icon} ${f.name}</td>
            <td style="white-space:nowrap">${f.size}</td>
            <td style="white-space:nowrap;color:#718096">${f.date}</td>
            <td style="text-align:right;white-space:nowrap">
                <a href="${f.dl_url}" class="btn btn-sm" style="background:#1e3a2f;color:#6ee7b7;text-decoration:none" target="_blank">⬇ Download</a>
                <button class="btn btn-sm btn-danger" onclick="deleteExport('${f.name}',this)">🗑 Delete</button>
            </td>
        </tr>`;
    });
    html+='</table>';
    el.innerHTML=html;
}
async function deleteExport(name, btn){
    if(!confirm('Delete '+name+'?')) return;
    btn.disabled=true; btn.textContent='...';
    const fd=new FormData(); fd.append('file',name);
    const r=await fetch('?action=delete_export',{method:'POST',body:fd});
    const j=await r.json();
    if(j.ok) loadExports(); else { btn.disabled=false; btn.textContent='🗑 Delete'; alert('Error deleting file.'); }
}
document.addEventListener('DOMContentLoaded', function(){
    loadExports();
    const saved = localStorage.getItem('migrate_target_url');
    if(saved){ const el = document.getElementById('exp_target_url'); if(el) el.value = saved; }
});
</script>
<?php endif; ?>

</div><!-- /wrap -->

<?php if ($authenticated): ?>
<script>
function showTab(name){
    document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.section').forEach(s=>s.classList.remove('active'));
    document.getElementById('tab-'+name).classList.add('active');
    event.target.classList.add('active');
}

function formData(prefix){
    return {
        db_host:   document.getElementById(prefix+'_db_host').value,
        db_name:   document.getElementById(prefix+'_db_name').value,
        db_user:   document.getElementById(prefix+'_db_user').value,
        db_pass:   document.getElementById(prefix+'_db_pass').value,
        db_prefix: document.getElementById(prefix+'_db_prefix').value,
    };
}

async function reloadWpConfig(){
    const r = await fetch('?action=get_wpconfig', {method:'POST'});
    const d = await r.json();
    if (!d.ok) { alert('Could not read wp-config.php'); return; }
    document.getElementById('exp_db_host').value   = d.host;
    document.getElementById('exp_db_name').value   = d.name;
    document.getElementById('exp_db_user').value   = d.user;
    document.getElementById('exp_db_pass').value   = d.pass;
    document.getElementById('exp_db_prefix').value = d.prefix;
    document.getElementById('exp_db_status').innerHTML = '<span style="color:#6ee7b7;font-size:12px">✓ Reloaded from wp-config.php</span>';
}

async function testDB(prefix){
    const el = document.getElementById(prefix+'_db_status');
    el.innerHTML='<span style="color:#718096;font-size:12px">Testing...</span>';
    const fd = new FormData();
    const d  = formData(prefix);
    Object.entries(d).forEach(([k,v])=>fd.append(k,v));
    const r = await fetch('?action=test_db', {method:'POST', body:fd});
    const j = await r.json();
    if(j.ok){
        el.innerHTML=`<span class="badge badge-ok">✓ Connected — site_url: ${j.siteurl||'—'}</span>`;
        if(prefix==='exp' && j.siteurl) document.getElementById('exp_source_url').value=j.siteurl;
    } else {
        el.innerHTML=`<span class="badge badge-err">✗ ${j.error}</span>`;
    }
}

function log(id, msg, append=true){
    const el = document.getElementById(id);
    el.style.display='block';
    if(append) el.textContent += msg+'\n';
    else el.textContent = msg+'\n';
    el.scrollTop = el.scrollHeight;
}

async function exportDB(){
    const target = document.getElementById('exp_target_url').value.trim();
    if(!target){ log('exp_log','⚠ Target URL is required before exporting.', false); return; }

    const d = formData('exp');
    const fd = new FormData();
    Object.entries(d).forEach(([k,v])=>fd.append(k,v));
    fd.append('source_url', document.getElementById('exp_source_url').value);
    fd.append('target_url', target);

    const split = document.getElementById('exp_split').checked;
    if (split) {
        fd.append('split', '1');
        fd.append('max_part_size', document.getElementById('exp_split_size').value);
    }

    document.getElementById('exp_parts').style.display = 'none';
    log('exp_log', split ? '⏳ Exporting and splitting into .zip parts...' : '⏳ Exporting DB...', false);
    document.getElementById('exp_progress').style.display='block';
    document.getElementById('exp_progress_bar').style.width='30%';

    const r = await fetch('?action=export_db', {method:'POST', body:fd});
    document.getElementById('exp_progress_bar').style.width='90%';
    const j = await r.json();
    document.getElementById('exp_progress_bar').style.width='100%';

    if(!j.ok){ log('exp_log','✗ Error: '+j.error); return; }

    if (j.split) {
        const el = document.getElementById('exp_log');
        el.innerHTML += `\n✓ DB exported via ${j.method} and split into ${j.count} part(s) under ${j.max_mb} MB each.\n`;
        const box = document.getElementById('exp_parts');
        let html = '<div style="font-size:13px;color:#a0aec0;margin-bottom:6px">Download each part and import in order:</div>';
        html += '<table style="width:100%;font-size:13px;border-collapse:collapse">';
        j.parts.forEach(p => {
            html += `<tr style="border-bottom:1px solid #2d3148">
                <td style="padding:6px 8px;color:#718096;width:48px">#${String(p.n).padStart(3,'0')}</td>
                <td style="padding:6px 8px;font-family:monospace"><a href="${p.url}" style="color:#7dd3fc" target="_blank">${p.name}</a></td>
                <td style="padding:6px 8px;text-align:right;color:#a0aec0">${p.size}</td></tr>`;
        });
        html += '</table>';
        box.innerHTML = html;
        box.style.display = 'block';
    } else {
        const el = document.getElementById('exp_log');
        el.innerHTML += `\n✓ DB exported via ${j.method} — ${j.size}\n📥 <a href="${j.url}" style="color:#7dd3fc" target="_blank">${j.filename}</a>\n`;
    }
}

async function exportZip(){
    const target = document.getElementById('exp_target_url').value.trim();
    if(!target){ log('exp_log','⚠ Target URL is required before exporting.', false); return; }

    const fd = new FormData();
    const d  = formData('exp');
    Object.entries(d).forEach(([k,v])=>fd.append(k,v));
    fd.append('source_url', document.getElementById('exp_source_url').value);
    fd.append('target_url', target);

    log('exp_log','⏳ Creating hosting zip (may take a while for large sites)...', false);
    document.getElementById('exp_progress').style.display='block';
    document.getElementById('exp_progress_bar').style.width='10%';

    const r = await fetch('?action=export_zip', {method:'POST', body:fd});
    document.getElementById('exp_progress_bar').style.width='100%';
    const j = await r.json();

    if(j.ok){
        const el = document.getElementById('exp_log');
        el.style.display='block';
        el.innerHTML += `✓ Zip created via ${j.method} — ${j.size}\n📥 <a href="${j.url}" style="color:#7dd3fc" target="_blank">${j.filename}</a>\n`;
        el.scrollTop = el.scrollHeight;
    } else {
        log('exp_log','✗ Error: '+j.error);
    }
}

async function importDB(){
    const fd = new FormData();
    const d  = formData('imp');
    Object.entries(d).forEach(([k,v])=>fd.append(k,v));
    fd.append('dest_url', document.getElementById('imp_dest_url').value);
    const file = document.getElementById('imp_sql_file').files[0];
    if(!file){ alert('Select a SQL file first'); return; }
    fd.append('sql_file', file);

    log('imp_log','⏳ Importing DB...', false);
    const r = await fetch('?action=import_db', {method:'POST', body:fd});
    const j = await r.json();

    if(j.ok){
        log('imp_log',`✓ Imported via ${j.method}`);
        log('imp_log',`  DB siteurl: ${j.db_url}`);
        log('imp_log',`  Current URL: ${j.cur_url}`);
        log('imp_log',`  URL match: ${j.url_match ? '✓ YES' : '⚠ NO — check wp-config.php and wp_options'}`);
        if(!j.url_match){
            log('imp_log',`\n⚠ Fix: UPDATE wp_options SET option_value='${j.cur_url}' WHERE option_name IN ('siteurl','home');`);
        }
    } else {
        log('imp_log','✗ Error: '+j.error);
    }
}

async function extractZip(){
    const fd = new FormData();
    const file = document.getElementById('imp_zip_file').files[0];
    if(!file){ alert('Select a zip file first'); return; }
    fd.append('zip_file', file);
    log('imp_zip_log','⏳ Extracting uploaded file...', false);
    const r = await fetch('?action=extract_zip', {method:'POST', body:fd});
    const j = await r.json();
    log('imp_zip_log', j.ok ? `✓ Extracted via ${j.method}` : `✗ Error: ${j.error}`);
}

async function scanFiles(){
    const el = document.getElementById('imp_scan_result');
    el.style.display='block';
    el.innerHTML = '<span style="color:#718096;font-size:13px">🔍 Scanning...</span>';
    const r = await fetch('?action=scan_files', {method:'POST'});
    const j = await r.json();
    if(!j.ok){ el.innerHTML='<span style="color:#f87171">Error scanning.</span>'; return; }
    if(j.files.length === 0){
        el.innerHTML='<span style="color:#718096;font-size:13px">No .zip / .tar.gz / .sql.gz / .sql files found in server root.</span>';
        return;
    }
    const typeIcon = {zip:'📦', 'tar.gz':'📦', 'sql.gz':'🗄️', sql:'🗄️'};
    const isZip  = f => f.ext==='zip' || f.ext==='tar.gz';
    const isSql  = f => f.ext==='sql.gz' || f.ext==='sql';
    let html = '<table style="width:100%;font-size:13px"><tr><th>File</th><th>Size</th><th>Action</th></tr>';
    j.files.forEach(f => {
        const icon = typeIcon[f.ext] || '📄';
        let actions = '';
        if(isZip(f))  actions += `<button class="btn btn-sm btn-warning" onclick="extractServerFile('${f.name}')">📁 Extract</button> `;
        if(isSql(f))  actions += `<button class="btn btn-sm btn-success" onclick="importServerSQL('${f.name}')">🗄️ Import DB</button>`;
        html += `<tr><td>${icon} ${f.name}</td><td style="white-space:nowrap">${f.size}</td><td>${actions}</td></tr>`;
    });
    html += '</table>';
    el.innerHTML = html;
}

async function extractServerFile(filename){
    log('imp_zip_log','⏳ Extracting ' + filename + '...', false);
    document.getElementById('imp_zip_log').style.display='block';
    const fd = new FormData();
    fd.append('server_file', filename);
    const r = await fetch('?action=extract_zip', {method:'POST', body:fd});
    const j = await r.json();
    log('imp_zip_log', j.ok ? `✓ Extracted via ${j.method}` : `✗ Error: ${j.error}`);
}

async function clearCache(){
    const btn = document.getElementById('clear-cache-btn');
    const el  = document.getElementById('cache_log');
    btn.disabled = true; btn.textContent = '⏳ Clearing...';
    el.style.display = 'block'; el.textContent = '⏳ Clearing cache...\n';
    try {
        const r = await fetch('?action=clear_cache', {method:'POST'});
        const j = await r.json();
        if (j.ok) {
            el.textContent = `✓ Deleted ${j.deleted} cached files.${j.errors ? '\n⚠ ' + j.errors + ' file(s) could not be deleted (check permissions).' : ''}`;
        } else {
            el.textContent = '✗ Error: ' + (j.error || 'Unknown error');
        }
    } catch(e) {
        el.textContent = '✗ Request failed: ' + e.message;
    }
    btn.disabled = false; btn.textContent = '🗑️ Clear WP + Divi Cache';
}

async function installWordPress() {
    const btn = document.getElementById('wp_install_btn');
    btn.disabled = true;
    btn.textContent = '⏳ Downloading WordPress...';
    log('wp_install_log', '⏳ Downloading latest WordPress from wordpress.org...', false);
    try {
        const r = await fetch('?action=wp_install', {method:'POST'});
        const j = await r.json();
        if (j.ok) {
            log('wp_install_log', `✓ Downloaded via ${j.dl_method}`);
            log('wp_install_log', `✓ Extracted via ${j.ex_method}`);
            log('wp_install_log', `✓ Moved ${j.moved} file(s)${j.errors ? ` — ⚠ ${j.errors} error(s)` : ''}`);
            log('wp_install_log', `\n🚀 WordPress ready. Redirecting to installer...`);
            btn.textContent = '🚀 Opening installer...';
            setTimeout(() => { window.location.href = j.install_url; }, 2000);
        } else {
            log('wp_install_log', `✗ ${j.error}`);
            btn.disabled = false;
            btn.textContent = '⬇️ Download & Install WordPress';
        }
    } catch(e) {
        log('wp_install_log', `✗ Request failed: ${e.message}`);
        btn.disabled = false;
        btn.textContent = '⬇️ Download & Install WordPress';
    }
}

async function updateSiteUrlProtocol(protocol) {
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    fd.append('protocol', protocol);
    log('tls_siteurl_log', `⏳ Switching to ${protocol}...`, false);
    const r = await fetch('?action=update_site_url_protocol', {method:'POST', body:fd});
    const j = await r.json();
    if (j.ok) {
        log('tls_siteurl_log', `✓ Updated: ${j.new_url}  (${j.affected} row(s) changed)`);
        document.getElementById('tls_siteurl_current').innerHTML =
            `Current DB site_url: <strong style="color:#e2e8f0">${j.new_url}</strong>`;
    } else {
        log('tls_siteurl_log', `✗ ${j.error}`);
    }
}

async function refreshSiteUrl() {
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    const r = await fetch('?action=test_db', {method:'POST', body:fd});
    const j = await r.json();
    if (j.ok && j.siteurl) {
        document.getElementById('tls_siteurl_current').innerHTML =
            `Current DB site_url: <strong style="color:#e2e8f0">${j.siteurl}</strong>`;
    } else if (!j.ok) {
        log('tls_siteurl_log', `✗ DB Error: ${j.error}`, false);
    }
}

async function scanMediaUrls() {
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    log('tls_media_log', '⏳ Scanning for absolute wp-content URLs...', false);
    document.getElementById('tls_fix_btn').style.display = 'none';
    const r = await fetch('?action=scan_media_urls', {method:'POST', body:fd});
    const j = await r.json();
    if (!j.ok) { log('tls_media_log', `✗ ${j.error}`); return; }
    if (j.total === 0) {
        log('tls_media_log', '✓ No absolute wp-content URLs found. Database is clean.', false);
        return;
    }
    let msg = `⚠ Found ${j.total} row(s) with absolute wp-content URLs:\n`;
    j.tables.forEach(t => { msg += `  • ${t.table}.${t.col}: ${t.count} row(s)\n`; });
    if (j.samples.length) {
        msg += `\nExamples:\n`;
        j.samples.slice(0,5).forEach(s => { msg += `  ${s}\n`; });
    }
    msg += `\nClick "Fix" to convert all to /wp-content/ paths.`;
    log('tls_media_log', msg, false);
    document.getElementById('tls_fix_btn').style.display = 'inline-flex';
}

async function fixMediaUrls() {
    if (!confirm('Convert all absolute http(s)://domain/wp-content/ URLs to /wp-content/ paths?\n\nThis modifies posts, postmeta and options. Make sure you have a DB backup.')) return;
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    log('tls_media_log', '\n⏳ Fixing...', true);
    const r = await fetch('?action=fix_media_urls', {method:'POST', body:fd});
    const j = await r.json();
    if (j.ok) {
        let m = `✓ Done. Updated ${j.updated} row(s).`;
        if (j.unverified) m += `\n⏭ Skipped ${j.unverified} URL(s): target file not found on disk (left absolute to avoid broken links).`;
        if (j.errors) m += `\n⚠ ${j.errors} error(s).`;
        log('tls_media_log', m, true);
        document.getElementById('tls_fix_btn').style.display = 'none';
    } else {
        log('tls_media_log', `✗ ${j.error}`, true);
    }
}

async function scanDomains() {
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    const el = document.getElementById('tls_domains_list');
    el.innerHTML = '<span style="color:#718096;font-size:13px">⏳ Scanning linked domains...</span>';
    document.getElementById('tls_dom_fix_btn').style.display = 'none';
    const r = await fetch('?action=scan_domains', {method:'POST', body:fd});
    const j = await r.json();
    if (!j.ok) { el.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; return; }
    if (!j.total) {
        el.innerHTML = '<span style="color:#6ee7b7;font-size:13px">✓ No external domains linked. Database is clean.</span>';
        return;
    }
    let html = `<p style="font-size:12px;color:#718096;margin-bottom:8px">Found <strong style="color:#e2e8f0">${j.total}</strong> linked domain(s) (current site <code>${j.current_host}</code> excluded). Check the ones to convert. The <em>base path to drop</em> is recommended from files verified on disk — edit it if needed:</p>`;
    html += '<div style="max-height:360px;overflow-y:auto;border:1px solid #2d3148;border-radius:6px">';
    j.domains.forEach((d, i) => {
        const sample = d.samples.length ? d.samples[0].replace(/</g,'&lt;') : '';
        // Disk-verification badge: green if the recommended strip resolves files.
        let vbadge;
        if (d.checked === 0) {
            vbadge = `<span class="badge" style="background:#1e2235;color:#718096" title="No asset URLs to verify">no assets</span>`;
        } else if (d.verified > 0) {
            vbadge = `<span class="badge badge-ok" title="${d.verified} of ${d.checked} probed files exist on disk with this base path">✓ ${d.verified}/${d.checked} on disk</span>`;
        } else {
            vbadge = `<span class="badge badge-err" title="None of the probed files exist on disk — files may not be uploaded yet">⚠ 0/${d.checked} verified</span>`;
        }
        // Build the final URL preview: the first sample link, with scheme+host
        // (+strip) replaced by the current site, exactly as fixDomains would.
        const finalUrl = domToFinalUrl(d.samples[0] || ('https://' + d.domain + '/'), d.strip);
        html += `<div style="display:flex;gap:10px;align-items:flex-start;padding:8px 12px;border-bottom:1px solid #2d3148;font-size:13px">
            <input type="checkbox" class="tls-dom-cb" data-domain="${d.domain}" id="tls_dom_${i}" style="margin-top:3px">
            <div style="flex:1">
                <label for="tls_dom_${i}" style="cursor:pointer">
                    <span style="color:#e2e8f0;font-family:monospace">${d.domain}</span>
                    <span class="badge badge-warn" style="margin-left:6px">${d.count} link(s)</span>
                    <span style="margin-left:6px">${vbadge}</span>
                </label>
                <div style="margin-top:6px;display:flex;align-items:center;gap:6px">
                    <span style="color:#718096;font-size:11px">drop base path:</span>
                    <input type="text" class="tls-dom-strip" data-domain="${d.domain}" value="${d.strip}" placeholder="(none)"
                        oninput="refreshFinalUrl(${i}, '${esc(d.domain)}')"
                        style="width:180px;padding:3px 8px;background:#0f1117;border:1px solid #2d3148;border-radius:4px;color:#e2e8f0;font-size:12px;font-family:monospace">
                    <button class="btn btn-sm" style="background:#2d3148;color:#a0aec0" onclick="verifyDomainRow(${i}, '${esc(d.domain)}', '${esc(d.samples[0]||'')}')">${T_DOM.verify}</button>
                </div>
                ${sample ? `<div style="color:#4a5568;font-size:11px;font-family:monospace;margin-top:4px">${T_DOM.existing}: ${sample} <span id="tls_dom_exist_${i}"></span></div>` : ''}
                <div style="color:#6ee7b7;font-size:11px;font-family:monospace;margin-top:2px">${T_DOM.final}: <span id="tls_dom_final_${i}" data-sample="${esc(d.samples[0]||('https://'+d.domain+'/'))}">${esc(finalUrl)}</span> <span id="tls_dom_finalchk_${i}"></span></div>
            </div></div>`;
    });
    html += '</div>';
    el.innerHTML = html;
    document.getElementById('tls_dom_fix_btn').style.display = 'inline-flex';
}

// Translations for the Domain Link Manager row widgets.
const T_DOM = {
    verify:   <?= json_encode(t('Verify')) ?>,
    existing: <?= json_encode(t('existing')) ?>,
    final:    <?= json_encode(t('final')) ?>,
    checking: <?= json_encode(t('checking…')) ?>,
};

// Convert an absolute sample URL of a legacy domain to the final URL under the
// current site, dropping the given base path. Mirrors the server-side rewrite.
function domToFinalUrl(sampleUrl, strip) {
    const origin = window.location.origin; // scheme + current host (+port)
    let m = sampleUrl.match(/^https?:\/\/[^\/]+(\/[^\s"'<>]*)?/i);
    let path = m && m[1] ? m[1] : '/';
    strip = (strip || '').trim();
    if (strip) {
        if (strip[0] !== '/') strip = '/' + strip;
        strip = strip.replace(/\/+$/, '');
        if (path === strip || path.indexOf(strip + '/') === 0) path = path.substring(strip.length);
    }
    if (!path) path = '/';
    return origin + path;
}

function refreshFinalUrl(i, domain) {
    const span  = document.getElementById('tls_dom_final_' + i);
    const strip = document.querySelector(`.tls-dom-strip[data-domain="${domain}"]`).value;
    span.textContent = domToFinalUrl(span.getAttribute('data-sample'), strip);
    document.getElementById('tls_dom_finalchk_' + i).innerHTML = ''; // stale check cleared
}

async function verifyDomainRow(i, domain, sample) {
    const finalSpan = document.getElementById('tls_dom_final_' + i);
    const finalUrl  = finalSpan.textContent;
    const existChk  = document.getElementById('tls_dom_exist_' + i);
    const finalChk  = document.getElementById('tls_dom_finalchk_' + i);
    finalChk.innerHTML = existChk.innerHTML = `<span style="color:#718096">${T_DOM.checking}</span>`;
    const fd = new FormData();
    fd.append('urls', JSON.stringify([finalUrl, sample].filter(Boolean)));
    const r = await fetch('?action=verify_url', {method:'POST', body:fd});
    const j = await r.json();
    if (!j.ok) { finalChk.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; return; }
    const badge = res => res && res.ok
        ? `<span class="badge badge-ok">✓ ${res.status}</span>`
        : `<span class="badge badge-err">✗ ${res && res.status ? res.status : 'no reply'}</span>`;
    finalChk.innerHTML = badge(j.results[finalUrl]);
    if (sample && j.results[sample]) existChk.innerHTML = badge(j.results[sample]);
}

async function fixDomains() {
    const checked = Array.from(document.querySelectorAll('.tls-dom-cb:checked'));
    if (!checked.length) { alert('Select at least one domain to convert.'); return; }
    // Build { domain: stripBasePath } from the selected rows.
    const map = {};
    checked.forEach(cb => {
        const dom   = cb.getAttribute('data-domain');
        const field = document.querySelector(`.tls-dom-strip[data-domain="${dom}"]`);
        map[dom] = field ? field.value.trim() : '';
    });
    const summary = Object.entries(map).map(([d,s]) => s ? `${d}  (drop ${s})` : d).join('\n');
    if (!confirm(`Convert all links of ${checked.length} domain(s) to root-relative /paths?\n\n${summary}\n\nThis modifies posts, postmeta and options. Make sure you have a DB backup.`)) return;
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    fd.append('map', JSON.stringify(map));
    log('tls_domains_log', '⏳ Converting selected domains...', false);
    const r = await fetch('?action=fix_domains', {method:'POST', body:fd});
    const j = await r.json();
    if (j.ok) {
        log('tls_domains_log', `✓ Done. Updated ${j.updated} row(s) across ${j.domains.length} domain(s).${j.errors ? `\n⚠ ${j.errors} error(s).` : ''}`, false);
        document.getElementById('tls_dom_fix_btn').style.display = 'none';
        document.getElementById('tls_domains_list').innerHTML = '<span style="color:#6ee7b7;font-size:13px">✓ Re-scan to confirm the domains are gone.</span>';
    } else {
        log('tls_domains_log', `✗ ${j.error}`, false);
    }
}

// --- WordPress Users ---
const WP_ROLES = ['administrator','editor','author','contributor','subscriber'];
const T_USERS = {
    change_pw: <?= json_encode(t('Change password')) ?>,
    edit:      <?= json_encode(t('Edit')) ?>,
    save:      <?= json_encode(t('Save')) ?>,
    username:  <?= json_encode(t('Username')) ?>,
    email:     <?= json_encode(t('Email')) ?>,
    display:   <?= json_encode(t('Display name')) ?>,
    role:      <?= json_encode(t('Role')) ?>,
    registered:<?= json_encode(t('Registered')) ?>,
    newpw:     <?= json_encode(t('New password')) ?>,
};

function toggleNewUserForm() {
    const f = document.getElementById('tls_newuser_form');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}

async function loadWpUsers() {
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    const el = document.getElementById('tls_users_list');
    el.innerHTML = '<span style="color:#718096;font-size:13px">⏳</span>';
    const r = await fetch('?action=wp_users_list', {method:'POST', body:fd});
    const j = await r.json();
    if (!j.ok) { el.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; return; }
    document.getElementById('tls_user_new_btn').style.display = 'inline-flex';
    let html = '<table style="width:100%;font-size:13px;border-collapse:collapse">';
    html += `<tr style="color:#718096;text-align:left"><th style="padding:6px">ID</th><th style="padding:6px">${T_USERS.username}</th><th style="padding:6px">${T_USERS.email}</th><th style="padding:6px">${T_USERS.role}</th><th style="padding:6px">${T_USERS.registered}</th><th style="padding:6px"></th></tr>`;
    j.users.forEach(u => {
        const roles = (u.roles || []).join(', ');
        html += `<tr style="border-top:1px solid #2d3148">
            <td style="padding:6px;color:#718096">${u.id}</td>
            <td style="padding:6px;font-family:monospace">${esc(u.login)}</td>
            <td style="padding:6px">${esc(u.email)}</td>
            <td style="padding:6px"><span class="badge badge-ok">${esc(roles)}</span></td>
            <td style="padding:6px;color:#718096">${esc((u.registered||'').substring(0,10))}</td>
            <td style="padding:6px;text-align:right">
                <button class="btn btn-sm" style="background:#2d3148;color:#a0aec0" onclick="editWpUser(${u.id},'${esc(u.email)}','${esc(u.display)}','${(u.roles||[])[0]||''}')">${T_USERS.edit}</button>
                <button class="btn btn-sm btn-warning" onclick="changeWpUserPw(${u.id},'${esc(u.login)}')">${T_USERS.change_pw}</button>
            </td></tr>`;
    });
    html += '</table>';
    el.innerHTML = html;
}

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function changeWpUserPw(id, login) {
    const pw = prompt(T_USERS.newpw + ' — ' + login);
    if (!pw) return;
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    fd.append('user_id', id); fd.append('new_password', pw);
    const r = await fetch('?action=wp_user_password', {method:'POST', body:fd});
    const j = await r.json();
    log('tls_users_log', j.ok ? `✓ #${id}: password changed` : `✗ ${j.error}`, false);
}

async function editWpUser(id, email, display, role) {
    const newEmail = prompt(T_USERS.email, email); if (newEmail === null) return;
    const newDisplay = prompt(T_USERS.display, display); if (newDisplay === null) return;
    const newRole = prompt(T_USERS.role + ' (' + WP_ROLES.join('/') + ')', role); if (newRole === null) return;
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    fd.append('user_id', id); fd.append('email', newEmail); fd.append('display_name', newDisplay); fd.append('role', newRole);
    const r = await fetch('?action=wp_user_update', {method:'POST', body:fd});
    const j = await r.json();
    log('tls_users_log', j.ok ? `✓ #${id}: updated` : `✗ ${j.error}`, false);
    if (j.ok) loadWpUsers();
}

async function createWpUser() {
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    fd.append('login', document.getElementById('nu_login').value);
    fd.append('email', document.getElementById('nu_email').value);
    fd.append('display_name', document.getElementById('nu_display').value);
    fd.append('new_password', document.getElementById('nu_pass').value);
    fd.append('role', document.getElementById('nu_role').value);
    const r = await fetch('?action=wp_user_create', {method:'POST', body:fd});
    const j = await r.json();
    log('tls_users_log', j.ok ? `✓ user created (#${j.id})` : `✗ ${j.error}`, false);
    if (j.ok) { document.getElementById('tls_newuser_form').style.display='none'; loadWpUsers(); }
}

async function loadPlugins() {
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    const el = document.getElementById('tls_plugins_list');
    el.innerHTML = '<span style="color:#718096;font-size:13px">⏳ Loading...</span>';
    document.getElementById('tls_deactivate_all_btn').style.display = 'none';
    document.getElementById('tls_save_plugins_btn').style.display   = 'none';
    const r = await fetch('?action=list_plugins', {method:'POST', body:fd});
    const j = await r.json();
    if (!j.ok) { el.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; return; }
    document.getElementById('tls_deactivate_all_btn').style.display = 'inline-flex';
    document.getElementById('tls_save_plugins_btn').style.display   = 'inline-flex';
    let html = `<p style="font-size:12px;color:#718096;margin-bottom:10px">${j.count_active} active / ${j.count_installed} installed — check to keep active, uncheck to deactivate</p>`;
    html += '<div style="max-height:400px;overflow-y:auto;border:1px solid #2d3148;border-radius:6px">';
    j.installed.forEach(p => {
        const checked = p.active ? 'checked' : '';
        const badge   = p.active ? ' <span class="badge badge-ok">active</span>' : '';
        html += `<label style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border-bottom:1px solid #2d3148;cursor:pointer">
            <input type="checkbox" value="${p.file}" ${checked} style="margin-top:2px;cursor:pointer;width:15px;height:15px;flex-shrink:0">
            <span>
                <span style="font-size:13px;color:#e2e8f0">${p.name}</span>${badge}<br>
                <span style="font-size:11px;color:#718096">${p.file}</span>
            </span>
        </label>`;
    });
    html += '</div>';
    el.innerHTML = html;
}

async function deactivateAllPlugins() {
    if (!confirm('Deactivate ALL plugins in the database?\nThis sets active_plugins to empty in wp_options.')) return;
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    fd.append('mode', 'deactivate_all');
    log('tls_plugins_log', '⏳ Deactivating all plugins...', false);
    const r = await fetch('?action=toggle_plugins', {method:'POST', body:fd});
    const j = await r.json();
    if (j.ok) {
        log('tls_plugins_log', '✓ All plugins deactivated.');
        document.querySelectorAll('#tls_plugins_list input[type=checkbox]').forEach(cb => cb.checked = false);
    } else {
        log('tls_plugins_log', `✗ ${j.error}`);
    }
}

const PHPCFG_KEYS = ['memory_limit','upload_max_filesize','post_max_size','max_execution_time','max_input_vars'];
let PHPCFG_VALIDATED_METHOD = null; // method proven safe by the last test

function phpcfgValues() {
    const fd = new FormData(); let hasVal = false;
    PHPCFG_KEYS.forEach(k => {
        const v = document.getElementById('phpcfg_' + k)?.value.trim();
        if (v) { fd.append(k, v); hasVal = true; }
    });
    return { fd, hasVal };
}

function phpcfgDisableApply() {
    PHPCFG_VALIDATED_METHOD = null;
    const b = document.getElementById('phpcfg_apply_btn');
    b.disabled = true; b.style.opacity = '.5'; b.style.cursor = 'not-allowed';
}
// Re-test required whenever a value or the method changes.
document.addEventListener('input', e => { if (e.target && e.target.id && e.target.id.indexOf('phpcfg_') === 0) phpcfgDisableApply(); });

async function testPhpConfig() {
    const { fd, hasVal } = phpcfgValues();
    if (!hasVal) { log('tls_phpcfg_log', '✗ Enter at least one value'); return; }
    const method = document.getElementById('phpcfg_method').value;
    fd.append('method', method);
    const box = document.getElementById('tls_phpcfg_test');
    box.innerHTML = '<span style="color:#718096;font-size:12px">🧪 Testing in isolated folder…</span>';
    phpcfgDisableApply();
    let j;
    try {
        const r = await fetch('?action=test_php_config', {method:'POST', body:fd});
        j = await r.json();
    } catch(e) { box.innerHTML = `<span style="color:#f87171">✗ ${e.message}</span>`; return; }
    if (!j.ok) { box.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; return; }

    let html = '<table style="width:100%;font-size:12px;border-collapse:collapse;margin-top:6px">';
    const label = {htaccess: '.htaccess (Apache)', user_ini: '.user.ini (PHP-FPM/CGI)'};
    for (const [mth, res] of Object.entries(j.report)) {
        let verdict;
        if (res.breaks) verdict = `<span class="badge badge-err">✗ HTTP ${res.status} — would break the site</span>`;
        else if (res.loaded) verdict = `<span class="badge badge-ok">✓ HTTP ${res.status} — safe</span>`;
        else verdict = `<span class="badge badge-warn">? HTTP ${res.status || 'no reply'}</span>`;
        html += `<tr style="border-top:1px solid #2d3148"><td style="padding:5px 8px;font-family:monospace">${label[mth]||mth}</td><td style="padding:5px 8px">${verdict}</td></tr>`;
        // Show whether the proposed values actually applied in the test folder.
        if (res.applied) {
            for (const [k, a] of Object.entries(res.applied)) {
                const mark = a.ok ? '<span style="color:#6ee7b7">✓</span>' : '<span style="color:#fcd34d">≈</span>';
                html += `<tr><td style="padding:2px 8px 2px 24px;color:#718096;font-family:monospace">${k}</td><td style="padding:2px 8px;color:#a0aec0">${mark} want ${a.want} / got ${esc(String(a.got))}</td></tr>`;
            }
        }
    }
    html += '</table>';
    box.innerHTML = html;

    if (j.recommended) {
        PHPCFG_VALIDATED_METHOD = j.recommended;
        // In auto mode, lock the selector choice to the method that passed.
        const sel = document.getElementById('phpcfg_method');
        if (sel.value === 'auto') sel.value = j.recommended;
        const b = document.getElementById('phpcfg_apply_btn');
        b.disabled = false; b.style.opacity = '1'; b.style.cursor = 'pointer';
        log('tls_phpcfg_log', `✓ Safe via ${label[j.recommended]}. Apply enabled.`, false);
    } else {
        log('tls_phpcfg_log', '✗ No method passed the isolated test. Apply stays disabled to protect the site.', false);
    }
}

async function savePhpConfig() {
    if (!PHPCFG_VALIDATED_METHOD) { log('tls_phpcfg_log', '✗ Run a successful Test first.'); return; }
    const { fd, hasVal } = phpcfgValues();
    if (!hasVal) { log('tls_phpcfg_log', '✗ Enter at least one value'); return; }
    fd.append('method', PHPCFG_VALIDATED_METHOD);
    log('tls_phpcfg_log', '⏳ Applying…');
    try {
        const r = await fetch('?action=update_php_config', {method:'POST', body:fd});
        const j = await r.json();
        if (j.ok) {
            log('tls_phpcfg_log', '✓ Saved to: ' + j.written.join(', '), false);
            if (j.skipped?.length) log('tls_phpcfg_log', '⚠ Skipped: ' + j.skipped.join(', '));
            log('tls_phpcfg_log', 'ℹ ' + j.note);
            phpcfgDisableApply();
        } else {
            log('tls_phpcfg_log', '✗ ' + j.error);
        }
    } catch(e) { log('tls_phpcfg_log', '✗ ' + e.message); }
}

// Shared renderer for category/severity reports (health check + security audit).
function renderCategories(categories) {
    const statusColor = {ok:'#6ee7b7', warn:'#fcd34d', error:'#f87171'};
    const statusBg    = {ok:'#064e3b', warn:'#78350f', error:'#7f1d1d'};
    const statusIcon  = {ok:'✅', warn:'⚠️', error:'❌'};
    const totals = categories.reduce((a,c) => { a.errors+=c.errors; a.warns+=c.warns; return a; }, {errors:0,warns:0});
    const overall = totals.errors ? 'error' : (totals.warns ? 'warn' : 'ok');
    const overallMsg = totals.errors ? `${totals.errors} error(s), ${totals.warns} warning(s)` : (totals.warns ? `${totals.warns} warning(s)` : 'All checks passed');
    let html = `<div style="padding:12px 16px;border-radius:6px;background:${statusBg[overall]};border:1px solid ${statusColor[overall]};margin-bottom:16px;font-size:13px;color:${statusColor[overall]}">
        <strong>${statusIcon[overall]} ${overallMsg}</strong></div>`;
    categories.forEach(cat => {
        const sc = statusColor[cat.status], sb = statusBg[cat.status];
        const badge = `<span style="background:${sb};color:${sc};padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">${statusIcon[cat.status]} ${cat.status.toUpperCase()}</span>`;
        const errCount = cat.errors ? ` <span style="color:#f87171;font-size:11px">${cat.errors} error(s)</span>` : '';
        const warnCount = cat.warns ? ` <span style="color:#fcd34d;font-size:11px">${cat.warns} warning(s)</span>` : '';
        html += `<details ${cat.status !== 'ok' ? 'open' : ''} style="border:1px solid #2d3148;border-radius:6px;margin-bottom:8px;overflow:hidden">
            <summary style="padding:10px 14px;cursor:pointer;display:flex;align-items:center;gap:10px;background:#1a1d27;font-size:13px;font-weight:600;list-style:none;user-select:none">
                <span>${cat.icon} ${cat.label}</span>${badge}${errCount}${warnCount}
            </summary>
            <div style="padding:4px 0;background:#0f1117">`;
        cat.items.forEach(item => {
            const ic = item.s === 'ok' ? '#6ee7b7' : (item.s === 'warn' ? '#fcd34d' : '#f87171');
            const ii = item.s === 'ok' ? '✓' : (item.s === 'warn' ? '⚠' : '✗');
            html += `<div style="padding:6px 14px;font-size:12px;font-family:monospace;color:${ic};border-bottom:1px solid #1a1d2733">${ii} ${esc(item.text)}</div>`;
        });
        html += `</div></details>`;
    });
    return html;
}

async function runHealthCheck() {
    const btn = document.getElementById('tls_health_btn');
    const out = document.getElementById('tls_health_results');
    btn.disabled = true; btn.textContent = '⏳ Checking...';
    out.innerHTML = '<p style="color:#718096;font-size:13px;padding:8px 0">⏳ Running checks — this may take a few seconds...</p>';
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    try {
        const r = await fetch('?action=health_check', {method:'POST', body:fd});
        const j = await r.json();
        if (!j.ok) { out.innerHTML = `<p style="color:#f87171">✗ ${j.error}</p>`; }
        else out.innerHTML = renderCategories(j.categories);
    } catch(e) { out.innerHTML = `<p style="color:#f87171">✗ Request failed: ${e.message}</p>`; }
    btn.disabled = false; btn.textContent = '🔍 Run Health Check';
}

async function runSecurityAudit() {
    const btn = document.getElementById('tls_audit_btn');
    const out = document.getElementById('tls_audit_results');
    btn.disabled = true; btn.textContent = '⏳ Auditing...';
    out.innerHTML = '<p style="color:#718096;font-size:13px;padding:8px 0">⏳ Running security checks...</p>';
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    try {
        const r = await fetch('?action=security_audit', {method:'POST', body:fd});
        const j = await r.json();
        if (!j.ok) { out.innerHTML = `<p style="color:#f87171">✗ ${j.error}</p>`; }
        else out.innerHTML = renderCategories(j.categories);
    } catch(e) { out.innerHTML = `<p style="color:#f87171">✗ Request failed: ${e.message}</p>`; }
    btn.disabled = false; btn.textContent = '🛡️ Run Security Audit';
}

// --- SEO ---
async function runSeoAudit() {
    const btn = document.getElementById('tls_seo_btn');
    const out = document.getElementById('tls_seo_results');
    btn.disabled = true; btn.textContent = '⏳';
    out.innerHTML = '<p style="color:#718096;font-size:13px;padding:8px 0">⏳ Running SEO checks…</p>';
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    try {
        const r = await fetch('?action=seo_audit', {method:'POST', body:fd});
        const j = await r.json();
        if (!j.ok) { out.innerHTML = `<p style="color:#f87171">✗ ${j.error}</p>`; }
        else out.innerHTML = renderCategories(j.categories);
    } catch(e) { out.innerHTML = `<p style="color:#f87171">✗ ${e.message}</p>`; }
    btn.disabled = false; btn.textContent = '🔎 Run SEO Audit';
}

async function seoUpdateMeta() {
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    fd.append('blogname', document.getElementById('seo_blogname').value);
    fd.append('blogdescription', document.getElementById('seo_blogdescription').value);
    const r = await fetch('?action=seo_update_meta', {method:'POST', body:fd});
    const j = await r.json();
    log('tls_seo_log', j.ok ? `✓ Updated: ${j.updated.join(', ')}` : `✗ ${j.error}`, false);
}

function seoFd() { const fd = new FormData(); Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v)); return fd; }

async function seoSetPermalinks() {
    const r = await fetch('?action=seo_set_permalinks', {method:'POST', body:seoFd()});
    const j = await r.json();
    log('tls_seo_log', j.ok ? `✓ ${j.note}` : `✗ ${j.error}`, false);
}

async function seoGenerateSitemap() {
    log('tls_seo_log', '⏳ Generating sitemap…', false);
    const r = await fetch('?action=seo_generate_sitemap', {method:'POST', body:seoFd()});
    const j = await r.json();
    if (j.ok) log('tls_seo_log', `✓ Sitemap with ${j.urls} URL(s): <a href="${j.sitemap_url}" target="_blank" style="color:#7dd3fc">${j.sitemap_url}</a>`, false);
    else log('tls_seo_log', `✗ ${j.error}`, false);
    seoRenderLinks();
}

async function seoToggleIndex(allow) {
    const fd = seoFd(); fd.append('allow', allow ? '1' : '0');
    const r = await fetch('?action=seo_toggle_index', {method:'POST', body:fd});
    const j = await r.json();
    log('tls_seo_log', j.ok ? `✓ ${j.note}` : `✗ ${j.error}`, false);
}

// Request-indexing and "is it indexed?" links for the current site (opened by
// the user; search engines block automated server-side checks).
function seoRenderLinks() {
    const origin = window.location.origin;
    const host = window.location.hostname;
    const u = encodeURIComponent(origin + '/');
    const site = encodeURIComponent('site:' + host);
    const box = document.getElementById('tls_seo_links');
    box.innerHTML = `
      <div style="color:#a0aec0;margin-bottom:4px">${T_SEO.request}:</div>
      <div style="margin-bottom:8px">
        <a href="https://search.google.com/search-console?resource_id=${u}" target="_blank" style="color:#7dd3fc">Google Search Console</a> ·
        <a href="https://www.bing.com/webmasters/" target="_blank" style="color:#7dd3fc">Bing Webmaster</a> ·
        <a href="https://www.google.com/ping?sitemap=${u}sitemap.xml" target="_blank" style="color:#7dd3fc">Google ping sitemap</a>
      </div>
      <div style="color:#a0aec0;margin-bottom:4px">${T_SEO.checkidx} (<code>site:${host}</code>):</div>
      <div>
        <a href="https://www.google.com/search?q=${site}" target="_blank" style="color:#7dd3fc">Google</a> ·
        <a href="https://www.bing.com/search?q=${site}" target="_blank" style="color:#7dd3fc">Bing</a> ·
        <a href="https://duckduckgo.com/?q=${site}" target="_blank" style="color:#7dd3fc">DuckDuckGo</a> ·
        <a href="https://yandex.com/search/?text=${site}" target="_blank" style="color:#7dd3fc">Yandex</a>
      </div>`;
}
const T_SEO = { request: <?= json_encode(t('Request indexing')) ?>, checkidx: <?= json_encode(t('Check if indexed')) ?> };
document.addEventListener('DOMContentLoaded', () => { if (document.getElementById('tls_seo_links')) seoRenderLinks(); });

// --- WordPress Extras ---
async function wpExtra(action, extra, okMsg) {
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    if (extra) Object.entries(extra).forEach(([k,v]) => fd.append(k,v));
    log('tls_extras_log', '⏳ …', false);
    try {
        const r = await fetch('?action=' + action, {method:'POST', body:fd});
        const j = await r.json();
        log('tls_extras_log', j.ok ? '✓ ' + okMsg(j) : '✗ ' + j.error, false);
    } catch(e) { log('tls_extras_log', '✗ ' + e.message, false); }
}
function wpFixHtaccess()   { wpExtra('wp_fix_htaccess', null, () => 'Standard WordPress .htaccess written (backup kept).'); }
function wpRepairTables()  { wpExtra('wp_repair_tables', null, j => `Repaired ${j.repaired}, optimized ${j.optimized} of ${j.tables} table(s).${j.errors?` ${j.errors} error(s).`:''}`); }
function wpFlushRewrite()  { wpExtra('wp_flush_rewrite', null, j => `Cleared rewrite rules (${j.rewrite_cleared}) and ${j.transients_cleared} transient(s).`); }
function wpToggleDebug(on) { wpExtra('wp_toggle_debug', {enable: on?'1':'0'}, j => `WP_DEBUG ${j.enabled?'enabled':'disabled'}.`); }
function wpToggleMaintenance(on) { wpExtra('wp_toggle_maintenance', {enable: on?'1':'0'}, j => `Maintenance mode ${j.enabled?'ON':'OFF'}.`); }

async function installPhpMyAdmin() {
    const btn = document.getElementById('tls_pma_btn');
    btn.disabled = true; btn.textContent = '⏳ Downloading…';
    log('tls_pma_log', '⏳ Downloading and extracting phpMyAdmin (may take a while)…', false);
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    try {
        const r = await fetch('?action=pma_install', {method:'POST', body:fd});
        const j = await r.json();
        if (j.ok) {
            log('tls_pma_log', `✓ Installed via ${j.dl_method}/${j.ex_method}.\n🔗 <a href="${j.url}" target="_blank" style="color:#7dd3fc">${j.url}</a>\n⚠ Remove the /phpmyadmin folder when done.`, false);
        } else {
            log('tls_pma_log', '✗ ' + j.error, false);
        }
    } catch(e) { log('tls_pma_log', '✗ ' + e.message, false); }
    btn.disabled = false; btn.textContent = '⬇️ Download & install phpMyAdmin';
}

async function savePluginSelection() {
    const boxes    = document.querySelectorAll('#tls_plugins_list input[type=checkbox]:checked');
    const selected = Array.from(boxes).map(cb => cb.value);
    if (!confirm(`Set ${selected.length} plugin(s) as active and deactivate the rest?`)) return;
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    fd.append('mode', 'set');
    fd.append('plugins', selected.join(','));
    log('tls_plugins_log', `⏳ Saving selection (${selected.length} active)...`, false);
    const r = await fetch('?action=toggle_plugins', {method:'POST', body:fd});
    const j = await r.json();
    if (j.ok) {
        log('tls_plugins_log', `✓ Saved. ${j.active_count} plugin(s) now active.`);
    } else {
        log('tls_plugins_log', `✗ ${j.error}`);
    }
}

async function importServerSQL(filename){
    log('imp_log','⏳ Importing ' + filename + ' from server...', false);
    document.getElementById('imp_log').style.display='block';
    const fd = new FormData();
    const d = formData('imp');
    Object.entries(d).forEach(([k,v])=>fd.append(k,v));
    fd.append('dest_url', document.getElementById('imp_dest_url').value);
    fd.append('server_file', filename);
    const r = await fetch('?action=import_db', {method:'POST', body:fd});
    const j = await r.json();
    if(j.ok){
        log('imp_log',`✓ Imported via ${j.method}`);
        log('imp_log',`  DB siteurl: ${j.db_url}`);
        log('imp_log',`  Current URL: ${j.cur_url}`);
        log('imp_log',`  URL match: ${j.url_match ? '✓ YES' : '⚠ NO — update wp_options'}`);
        if(!j.url_match) log('imp_log',`\n⚠ Fix: UPDATE wp_options SET option_value='${j.cur_url}' WHERE option_name IN ('siteurl','home');`);
    } else {
        log('imp_log','✗ Error: '+j.error);
    }
}
</script>
<?php endif; ?>
</body>
</html>
