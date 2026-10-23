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
define('TOOL_VERSION',  '3.17.0');
define('AUTH_SALT',     'odc_migrate_2026_x9k');
define('PASSWORD_HASH', ''); // vacío = primera ejecución, se pedirá crear contraseña

// ============================================================
// DETECCIÓN DE CMS Y CREDENCIALES DE BASE DE DATOS
// ============================================================
// Supports WordPress, Joomla, Drupal and MediaWiki: finds the config file in the
// current or parent directory, identifies the CMS, and reads the DB credentials
// into a normalized $db array used by export/import and phpMyAdmin. Other,
// WordPress-only features stay gated on $detected_cms === 'wordpress'.
$db = ['host' => 'localhost', 'name' => '', 'user' => '', 'pass' => '', 'prefix' => 'wp_', 'charset' => 'utf8mb4'];
$site_url       = '';
$abspath        = __DIR__ . '/';
$detected_cms   = '';            // '', wordpress, joomla, drupal, mediawiki
$cms_config_path = null;
$wp_config_path  = null;         // kept for the WordPress-specific code paths

// A CMS descriptor: config file name + a parser returning the normalized creds.
$cms_defs = [
    'wordpress' => [
        'file' => 'wp-config.php', 'label' => 'WordPress',
        'parse' => function ($cfg) {
            $d = ['prefix' => 'wp_'];
            foreach (['DB_HOST'=>'host','DB_NAME'=>'name','DB_USER'=>'user','DB_PASSWORD'=>'pass','DB_CHARSET'=>'charset'] as $const=>$k) {
                $rx = '/define\s*\(\s*[\'"]' . preg_quote($const, '/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"\s]/';
                if (preg_match($rx, $cfg, $m)) $d[$k] = $m[1];
            }
            if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $cfg, $m)) $d['prefix'] = $m[1];
            return $d;
        },
    ],
    'joomla' => [
        'file' => 'configuration.php', 'label' => 'Joomla',
        'parse' => function ($cfg) {
            $d = ['prefix' => ''];
            foreach (['host'=>'host','user'=>'user','password'=>'pass','db'=>'name','dbprefix'=>'prefix'] as $var=>$k) {
                $rx = '/public\s+\$' . $var . '\s*=\s*[\'"]([^\'"]*)[\'"]/';
                if (preg_match($rx, $cfg, $m)) $d[$k] = $m[1];
            }
            return $d;
        },
    ],
    'drupal' => [
        'file' => 'sites/default/settings.php', 'label' => 'Drupal',
        'parse' => function ($cfg) {
            // $databases['default']['default'] = [ 'database'=>, 'username'=>, 'password'=>, 'host'=>, 'prefix'=> ];
            $d = ['prefix' => ''];
            foreach (['database'=>'name','username'=>'user','password'=>'pass','host'=>'host','prefix'=>'prefix'] as $key=>$k) {
                $rx = '/[\'"]' . $key . '[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/';
                if (preg_match($rx, $cfg, $m)) $d[$k] = $m[1];
            }
            return $d;
        },
    ],
    'mediawiki' => [
        'file' => 'LocalSettings.php', 'label' => 'MediaWiki',
        'parse' => function ($cfg) {
            $d = ['prefix' => ''];
            foreach (['wgDBserver'=>'host','wgDBname'=>'name','wgDBuser'=>'user','wgDBpassword'=>'pass','wgDBprefix'=>'prefix'] as $var=>$k) {
                $rx = '/\$' . $var . '\s*=\s*[\'"]([^\'"]*)[\'"]/';
                if (preg_match($rx, $cfg, $m)) $d[$k] = $m[1];
            }
            return $d;
        },
    ],
];

// Look in the current dir and the parent for any known config file.
foreach ([__DIR__, dirname(__DIR__)] as $base) {
    if ($detected_cms) break;
    foreach ($cms_defs as $cms => $def) {
        $path = $base . '/' . $def['file'];
        // Skip a fresh WordPress download (sample present, real config absent).
        if ($cms === 'wordpress' && file_exists($base . '/wp-config-sample.php') && !file_exists($path)) continue;
        if (file_exists($path)) {
            $creds = $def['parse'](file_get_contents($path));
            $db = array_merge($db, array_filter($creds, fn($v) => $v !== null));
            if ($db['charset'] === '' || empty($db['charset'])) $db['charset'] = 'utf8mb4';
            $detected_cms    = $cms;
            $cms_config_path = $path;
            $abspath         = ($cms === 'drupal' ? $base : dirname($path)) . '/';
            if ($cms === 'wordpress') $wp_config_path = $path;
            break;
        }
    }
}
$cms_label = $detected_cms ? $cms_defs[$detected_cms]['label'] : '';

// Detect an already-installed phpMyAdmin (not a CMS; just reported as present).
$pma_present_dir = '';
foreach (glob(rtrim($abspath, '/') . '/phpmyadmin*', GLOB_ONLYDIR) ?: [] as $d) {
    if (is_file($d . '/index.php')) { $pma_present_dir = basename($d); break; }
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
        'Toggle light/dark'                   => 'Alternar claro/oscuro',
        'Get BigDump'                         => 'Obtener BigDump',
        'Open BigDump'                        => 'Abrir BigDump',
        'WordPress Migration · Export & Import' => 'Migración de WordPress · Exportar e importar',
        'Running on live server.'             => 'Ejecutándose en un servidor en producción.',
        'Delete this file after use!'         => '¡Eliminá este archivo después de usarlo!',
        // Tabs + main card titles
        'Export (Origin)'                     => 'Exportar (Origen)',
        'Import (Destination)'                => 'Importar (Destino)',
        'How to use'                          => 'Cómo usar',
        'DB Tools'                            => 'Herramientas DB',
        'CLI available'                       => 'CLI disponible',
        // How to use / info tab
        'How to migrate'                      => 'Cómo migrar',
        'Migration step by step'              => 'Migración paso a paso',
        'On the <strong>origin</strong>: open the <strong>Export</strong> tab, set the source and target URLs, and export the database (optionally split into size-limited <code>.zip</code> parts for hosts with upload limits). Use <strong>Export Hosting</strong> for the full site zip.'
            => 'En el <strong>origen</strong>: abrí la pestaña <strong>Exportar</strong>, definí las URLs de origen y destino, y exportá la base de datos (opcionalmente dividida en partes <code>.zip</code> con límite de tamaño para hostings con restricciones de subida). Usá <strong>Exportar Hosting</strong> para el zip completo del sitio.',
        'On the <strong>destination</strong>: create the database and user in phpMyAdmin, upload and extract the site files, and update <code>wp-config.php</code> with the new credentials.'
            => 'En el <strong>destino</strong>: creá la base de datos y el usuario en phpMyAdmin, subí y extraé los archivos del sitio, y actualizá <code>wp-config.php</code> con las nuevas credenciales.',
        'Open the <strong>Import</strong> tab on the destination and import the <code>.sql</code>, <code>.gz</code> or <code>.zip</code> parts in order. The tool verifies the URL matches first.'
            => 'Abrí la pestaña <strong>Importar</strong> en el destino e importá las partes <code>.sql</code>, <code>.gz</code> o <code>.zip</code> en orden. La herramienta verifica primero que la URL coincida.',
        'For very large dumps, use the embedded <a href="?action=bigdump" style="color:#7dd3fc">BigDump</a> importer.'
            => 'Para volcados muy grandes, usá el importador <a href="?action=bigdump" style="color:#7dd3fc">BigDump</a> integrado.',
        'After migrating (DB Tools tab)'      => 'Después de migrar (pestaña Herramientas DB)',
        '<strong>Domain Link Manager</strong> — rewrite links to the old site to root-relative paths, and verify the final/existing URLs resolve.'
            => '<strong>Gestor de enlaces de dominio</strong> — reescribe los enlaces al sitio viejo como rutas relativas a la raíz, y verifica que las URLs finales/existentes resuelvan.',
        '<strong>Media URL Scanner</strong> — convert absolute <code>wp-content</code> URLs to relative, serialized-data safe.'
            => '<strong>Escáner de URLs de medios</strong> — convierte URLs absolutas de <code>wp-content</code> a relativas, de forma segura con datos serializados.',
        '<strong>Site URL manager</strong> — switch between http and https.'
            => '<strong>Gestor de URL del sitio</strong> — cambia entre http y https.',
        '<strong>WordPress Users</strong> — list, create, edit and reset passwords without the WP admin.'
            => '<strong>Usuarios de WordPress</strong> — listá, creá, editá y restablecé contraseñas sin el panel de WP.',
        '<strong>SEO</strong> — audit, edit title/tagline, friendly permalinks, sitemap, indexing toggle and indexing checks.'
            => '<strong>SEO</strong> — auditoría, editar título/descripción, enlaces permanentes amigables, sitemap, activar/desactivar indexación y verificación de indexación.',
        '<strong>Security Audit</strong> and <strong>Site Health Check</strong> — review hardening and integrity.'
            => '<strong>Auditoría de seguridad</strong> y <strong>Chequeo de salud del sitio</strong> — revisá endurecimiento e integridad.',
        '<strong>Extras</strong> — regenerate <code>.htaccess</code>, repair/optimize tables, flush caches, toggle debug/maintenance.'
            => '<strong>Extras</strong> — regenerá <code>.htaccess</code>, reparar/optimizar tablas, limpiar cachés, activar/desactivar depuración/mantenimiento.',
        '<strong>Site Password</strong> — protect the whole site with HTTP Basic auth (tested safely first).'
            => '<strong>Contraseña del sitio</strong> — protegé todo el sitio con autenticación HTTP Basic (probada sin riesgo primero).',
        '<strong>phpMyAdmin</strong> — install it on demand to manage the database directly.'
            => '<strong>phpMyAdmin</strong> — instalalo a demanda para administrar la base de datos directamente.',
        'Divi / WordPress notes'              => 'Notas de Divi / WordPress',
        'URL replacement handles <strong>serialized PHP strings</strong> (Divi/Elementor store layout data serialized).'
            => 'El reemplazo de URLs maneja <strong>cadenas PHP serializadas</strong> (Divi/Elementor guardan datos de maquetación serializados).',
        'If the layout looks broken after import, clear caches: <code>wp-content/cache/</code> and <code>wp-content/et-cache/</code> (or use Clear Cache above).'
            => 'Si la maquetación se ve rota tras importar, limpiá las cachés: <code>wp-content/cache/</code> y <code>wp-content/et-cache/</code> (o usá Limpiar caché arriba).',
        'Access to this tool requires the wp-config database password by default; set your own encrypted password from the Access Password card.'
            => 'El acceso a esta herramienta requiere por defecto la contraseña de la base de datos de wp-config; establecé tu propia contraseña cifrada desde la tarjeta Contraseña de acceso.',
        'Environment Status'                  => 'Estado del entorno',
        'PHP version'                         => 'Versión de PHP',
        // News
        'News'                                => 'Noticias',
        'WordPress News'                      => 'Noticias de WordPress',
        'Latest posts from the official WordPress.org news feed. Security or critical releases are highlighted and also shown as a banner at the top of the tool.'
            => 'Últimas publicaciones del feed oficial de noticias de WordPress.org. Las versiones de seguridad o críticas se resaltan y además se muestran como un aviso en la parte superior de la herramienta.',
        'Refresh news'                        => 'Actualizar noticias',
        'Loading news…'                       => 'Cargando noticias…',
        'No news available'                   => 'No hay noticias disponibles',
        'Critical WordPress news'             => 'Noticia crítica de WordPress',
        'see News tab'                        => 'ver pestaña Noticias',
        // Self-update
        'Update available:'                   => 'Actualización disponible:',
        'Update now'                          => 'Actualizar ahora',
        'Updating…'                           => 'Actualizando…',
        'Updated'                             => 'Actualizado',
        'The tool was updated to'             => 'La herramienta se actualizó a',
        'Backup kept as'                      => 'Copia de seguridad guardada como',
        'The page will now reload.'           => 'La página se recargará ahora.',
        'Update the tool to the latest version? A backup of the current file is kept so you can revert.'
            => '¿Actualizar la herramienta a la última versión? Se guarda una copia del archivo actual para poder revertir.',
        'MySQL version'                       => 'Versión de MySQL',
        'WordPress recommends 7.4+'           => 'WordPress recomienda 7.4+',
        'unavailable (DB not connected)'      => 'no disponible (BD sin conexión)',
        'Detected CMS'                        => 'CMS detectado',
        'none detected'                       => 'ninguno detectado',
        'Migration & maintenance tools · Export & Import' => 'Herramientas de migración y mantenimiento · Exportar e importar',
        // Quick Update
        'Quick Update (after moving the site)' => 'Actualización rápida (tras mover el sitio)',
        'The tool reads the OLD site URL from the database and knows the CURRENT URL from your browser. With the real DB credentials below it can update wp-config, change the site URL, convert absolute URLs to relative, clear caches and deactivate plugins in one step. A backup of wp-config.php is made and every step is reported.'
            => 'La herramienta lee la URL ANTIGUA del sitio desde la base de datos y conoce la URL ACTUAL por el navegador. Con las credenciales reales de la base de datos de abajo puede actualizar wp-config, cambiar la URL del sitio, convertir URLs absolutas a relativas, limpiar cachés y desactivar plugins en un solo paso. Se hace una copia de seguridad de wp-config.php y se informa cada paso.',
        'Old URL (from database)'             => 'URL antigua (de la base de datos)',
        'Current URL (this browser)'          => 'URL actual (este navegador)',
        'Steps to run'                        => 'Pasos a ejecutar',
        'Update wp-config.php with these credentials (backup first)' => 'Actualizar wp-config.php con estas credenciales (copia de seguridad primero)',
        'Set siteurl/home to the current URL' => 'Fijar siteurl/home a la URL actual',
        'Convert absolute wp-content URLs to relative + clear cache' => 'Convertir URLs absolutas de wp-content a relativas + limpiar caché',
        'Deactivate all plugins'              => 'Desactivar todos los plugins',
        'Run quick update'                    => 'Ejecutar actualización rápida',
        // Quick diagnostics
        'Quick diagnostics'                   => 'Diagnóstico rápido',
        'Scans the frequent reasons a site won\'t display: database, site URL, WP tables, maintenance mode, broken plugins, stale cache, .htaccess (500/redirects), PHP config, password locks and shadowing index.html. Runs step by step with a progress bar; fixes are offered, never auto-applied.'
            => 'Revisa las causas frecuentes de que un sitio no se muestre: base de datos, URL del sitio, tablas de WP, modo mantenimiento, plugins que rompen, caché obsoleta, .htaccess (500/redirecciones), configuración PHP, bloqueo por contraseña y un index.html que tape el sitio. Avanza paso a paso con barra de progreso; las soluciones se ofrecen, nunca se aplican solas.',
        'Run quick diagnostics'               => 'Ejecutar diagnóstico rápido',
        'Database'                            => 'Base de datos',
        'Site URL'                            => 'URL del sitio',
        'WordPress tables'                    => 'Tablas de WordPress',
        'Plugins'                             => 'Plugins',
        'Cache'                               => 'Caché',
        'PHP config'                          => 'Configuración PHP',
        'Password lock'                       => 'Bloqueo por contraseña',
        'Redirects / index.html'              => 'Redirecciones / index.html',
        'Diagnostics complete'                => 'Diagnóstico completo',
        'Fixes available'                     => 'Soluciones disponibles',
        'Clear cache'                         => 'Limpiar caché',
        'Lift maintenance'                    => 'Quitar mantenimiento',
        'Use Quick Update to fix the site URL' => 'Usá Actualización rápida para corregir la URL del sitio',
        'Recreate .htaccess from WordPress Extras' => 'Recreá el .htaccess desde Extras de WordPress',
        // Site snapshots
        'Site snapshots'                      => 'Instantáneas del sitio',
        'Saves a dated snapshot of the important configuration (.htaccess, php.ini, .user.ini, wp-config.php, robots.txt, .htpasswd) and the active plugins, so you can restore the site to that exact state if a later change breaks it. Nothing is changed when you create one; a restore always takes a safety snapshot of the current state first.'
            => 'Guarda una instantánea fechada de la configuración importante (.htaccess, php.ini, .user.ini, wp-config.php, robots.txt, .htpasswd) y los plugins activos, para poder restaurar el sitio a ese estado exacto si un cambio posterior lo rompe. Crear una no cambia nada; una restauración siempre toma primero una instantánea de seguridad del estado actual.',
        'Optional note (e.g. before plugin update)' => 'Nota opcional (ej.: antes de actualizar plugin)',
        'Save snapshot'                       => 'Guardar instantánea',
        'Snapshot saved'                      => 'Instantánea guardada',
        'files'                               => 'archivos',
        'active plugins'                      => 'plugins activos',
        'No snapshots yet'                    => 'Aún no hay instantáneas',
        'Restore'                             => 'Restaurar',
        'Restored'                            => 'Restaurado',
        'Deleted'                             => 'Eliminada',
        'failed'                              => 'falló',
        'no DB'                               => 'sin BD',
        'safety snapshot'                     => 'instantánea de seguridad',
        'Restore this snapshot? The current config files and active plugins will be overwritten (a safety snapshot is taken first).'
            => '¿Restaurar esta instantánea? Los archivos de configuración actuales y los plugins activos se sobrescribirán (primero se toma una instantánea de seguridad).',
        'Delete this snapshot permanently?'   => '¿Eliminar esta instantánea de forma permanente?',
        // Asset recovery
        'Recover missing assets from the original site' => 'Recuperar archivos faltantes del sitio original',
        'Scans the database for /wp-content/ files referenced by the site, checks which are missing on disk, and downloads them from the original site (useful when an FTP copy was incomplete). The original domain is auto-filled from the old site URL and remembered for later.'
            => 'Escanea la base de datos por archivos de /wp-content/ referenciados por el sitio, verifica cuáles faltan en disco, y los descarga del sitio original (útil cuando una copia por FTP quedó incompleta). El dominio original se autocompleta desde la URL antigua del sitio y se recuerda para después.',
        'Original site URL (domain + optional path)' => 'URL del sitio original (dominio + ruta opcional)',
        'Save origin'                         => 'Guardar origen',
        'Scan for missing assets'             => 'Buscar archivos faltantes',
        'Download missing from origin'        => 'Descargar faltantes del origen',
        'Copy list'                           => 'Copiar lista',
        'missing file(s)'                     => 'archivo(s) faltante(s)',
        'Copied'                              => 'Copiado',
        // Automatic asset recovery (.htaccess)
        'Automatic recovery while browsing (.htaccess)' => 'Recuperación automática al navegar (.htaccess)',
        'Routes any missing /wp-content/ file to a fallback that downloads it from the original site on the fly and caches it, so the site fills in as visitors browse. Tested safely before touching .htaccess. For images, choose what to show when the file is not at the origin either.'
            => 'Dirige cualquier archivo de /wp-content/ que falte a un mecanismo que lo descarga del sitio original al vuelo y lo cachea, de modo que el sitio se va completando mientras los visitantes navegan. Se prueba sin riesgo antes de tocar el .htaccess. Para imágenes, elegí qué mostrar cuando el archivo tampoco está en el origen.',
        'If not found'                        => 'Si no se encuentra',
        'Show "image not found" placeholder'  => 'Mostrar imagen de "no encontrada"',
        'Show nothing'                        => 'No mostrar nada',
        'Normal 404'                          => '404 normal',
        'Enable auto-recovery'                => 'Activar recuperación automática',
        'Disable'                             => 'Desactivar',
        'Active'                              => 'Activo',
        'Inactive'                            => 'Inactivo',
        'Auto-recovery enabled'               => 'Recuperación automática activada',
        'Auto-recovery disabled'              => 'Recuperación automática desactivada',
        'Missing files detected. You can download them from the original site.' => 'Se detectaron archivos faltantes. Podés descargarlos del sitio original.',
        'Go to asset recovery'                => 'Ir a recuperación de archivos',
        // Clean Site
        'Clean Site (remove unused uploads)'  => 'Limpiar sitio (quitar archivos sin usar)',
        'Finds which files in wp-content/uploads are actually used (referenced in the database, with their WordPress thumbnails) and builds a fresh uploads folder with only those. The current folder is renamed to uploads_old-remove-it_* (kept as backup, not deleted) and a restore log is written. Analyze first to see how much space would be freed; nothing changes until you apply.'
            => 'Detecta qué archivos de wp-content/uploads se usan realmente (referenciados en la base de datos, con sus miniaturas de WordPress) y crea una carpeta uploads nueva solo con esos. La carpeta actual se renombra a uploads_old-remove-it_* (se conserva como respaldo, no se borra) y se escribe un registro para restaurar. Analizá primero para ver cuánto espacio se liberaría; nada cambia hasta que apliques.',
        'Analyze (dry-run)'                   => 'Analizar (simulación)',
        'Build clean uploads (keep old as backup)' => 'Crear uploads limpio (conservar el viejo como respaldo)',
        // Optimize uploads
        'Optimize uploads (organize / dedupe / sanitize)' => 'Optimizar uploads (organizar / deduplicar / sanear)',
        'Three safe optimizations for a crowded wp-content/uploads. Each one locates files in the database first, moves/renames/merges them on disk, updates every reference, and writes a reversible JSON log. Analyze first (dry-run); apply runs in batches with a progress bar.'
            => 'Tres optimizaciones seguras para un wp-content/uploads saturado. Cada una localiza primero los archivos en la base de datos, los mueve/renombra/fusiona en disco, actualiza todas las referencias, y escribe un registro JSON reversible. Analizá primero (simulación); aplicar corre por lotes con barra de progreso.',
        'WordPress will keep uploading new files to its own year/month layout; this reorganizes existing files and updates their references. Changes are logged and reversible.'
            => 'WordPress seguirá subiendo archivos nuevos a su propia estructura año/mes; esto reorganiza los archivos existentes y actualiza sus referencias. Los cambios se registran y son reversibles.',
        'Organize into subfolders'            => 'Organizar en subcarpetas',
        'YYYYMMDD_name → YYYY/MM/name; otherwise → FirstLetter/name. Moves thumbnails too.'
            => 'YYYYMMDD_nombre → AAAA/MM/nombre; si no → PrimeraLetra/nombre. Mueve también las miniaturas.',
        'Deduplicate identical files'         => 'Deduplicar archivos idénticos',
        'Same size + same hash → keep one, repoint references, delete the rest.'
            => 'Mismo tamaño + mismo hash → conservar uno, reapuntar referencias, borrar el resto.',
        'Sanitize special-character names'    => 'Sanear nombres con caracteres especiales',
        'Accents, spaces and symbols → safe ASCII names. Updates references too.'
            => 'Acentos, espacios y símbolos → nombres ASCII seguros. Actualiza también las referencias.',
        'Found'                               => 'Encontrados',
        'Nothing to optimize'                 => 'Nada que optimizar',
        'Hashing files…'                      => 'Calculando hash…',
        'Apply this optimization? Files on disk and database references will change (logged and reversible).'
            => '¿Aplicar esta optimización? Cambiarán archivos en disco y referencias en la base de datos (registrado y reversible).',
        'Changes written to a reversible log.' => 'Cambios escritos en un registro reversible.',
        // Test Environment (sandbox)
        'Test Environment (sandbox)'          => 'Entorno de prueba (sandbox)',
        'Builds an isolated WordPress sandbox in a subfolder: it replicates the database tables under a test prefix and links the core, plugins, themes and uploads (symlink, falling back to copy). Then it can smoke-test each plugin one by one against the sandbox, vet new plugins from wordpress.org before installing them live, and produce a report — all without risking the live site. Uses the DB connection from the DB Tools card above.'
            => 'Construye un WordPress aislado en una subcarpeta: replica las tablas de la base de datos con un prefijo de prueba y enlaza el core, plugins, temas y uploads (symlink, con copia como respaldo). Luego puede probar cada plugin uno a uno contra el sandbox, evaluar plugins nuevos de wordpress.org antes de instalarlos en producción, y generar un informe — todo sin arriesgar el sitio real. Usa la conexión de BD de la tarjeta Herramientas DB de arriba.',
        'Build sandbox'                       => 'Construir sandbox',
        'Smoke-test plugins'                  => 'Probar plugins',
        'Test plugins one by one'             => 'Probar plugins uno por uno',
        'Test all plugins (find conflicts)'   => 'Probar todos los plugins (hallar conflictos)',
        'Build as multisite network'          => 'Construir como red multisite',
        'Subdirectory'                        => 'Subdirectorio',
        'Subdomain (needs wildcard DNS)'      => 'Subdominio (requiere DNS comodín)',
        'Multisite network'                   => 'Red multisite',
        'Sub-site path/subdomain'             => 'Ruta/subdominio del subsitio',
        'Admin email'                         => 'Correo del administrador',
        'Add sub-site'                        => 'Agregar subsitio',
        'multisite'                           => 'multisite',
        'Testing all plugins together'        => 'Probando todos los plugins juntos',
        'All plugins load together'           => 'Todos los plugins cargan juntos',
        'The site breaks with all plugins active' => 'El sitio se rompe con todos los plugins activos',
        'isolating the culprit'               => 'aislando el culpable',
        'Breaking plugin'                     => 'Plugin que rompe',
        'Minimal breaking set'                => 'Conjunto mínimo que rompe',
        'These plugins conflict with each other.' => 'Estos plugins entran en conflicto entre sí.',
        'Sub-sites'                           => 'Subsitios',
        'Enter a sub-site path/subdomain'     => 'Ingresá una ruta/subdominio de subsitio',
        'Sub-site added'                      => 'Subsitio agregado',
        'Destroy sandbox'                     => 'Destruir sandbox',
        'Vet a new plugin from wordpress.org' => 'Evaluar un plugin nuevo de wordpress.org',
        'Plugin name…'                        => 'Nombre del plugin…',
        'Search'                              => 'Buscar',
        'Replicating table'                   => 'Replicando tabla',
        'rows'                                => 'filas',
        'Creating sandbox folder…'            => 'Creando carpeta del sandbox…',
        'Sandbox ready'                       => 'Sandbox listo',
        'Testing plugin'                      => 'Probando plugin',
        'Plugin compatibility report'         => 'Informe de compatibilidad de plugins',
        'Recommend disabling'                 => 'Se recomienda desactivar',
        'All plugins load in the sandbox'     => 'Todos los plugins cargan en el sandbox',
        'URLs relativized'                    => 'URLs relativizadas',
        'prefix'                              => 'prefijo',
        'symlinks'                            => 'symlinks',
        'files copied'                        => 'archivos copiados',
        'Popular'                             => 'Populares',
        'Install'                             => 'Instalar',
        'incompatible PHP'                    => 'PHP incompatible',
        'Loading popular plugins…'            => 'Cargando plugins populares…',
        'Installing'                          => 'Instalando',
        'No sandbox yet — install directly into the live site?' => 'Aún no hay sandbox — ¿instalar directamente en el sitio real?',
        'breaks the sandbox'                  => 'rompe el sandbox',
        'Not installing live.'                => 'No se instala en producción.',
        'loads fine in the sandbox'           => 'carga bien en el sandbox',
        '%s passed the test. Install it into the live site now?' => '%s pasó la prueba. ¿Instalarlo en el sitio real ahora?',
        // Disk cleanup & Apache tests
        'Disk cleanup & Apache capabilities'  => 'Limpieza de disco y capacidades de Apache',
        'Orphan assets (on disk, not in the database)' => 'Archivos huérfanos (en disco, no en la base de datos)',
        'Finds uploads not referenced anywhere in the database. They are moved to wp-content/_orphans-remove-it with a restore log (not deleted).'
            => 'Encuentra archivos de uploads no referenciados en la base de datos. Se mueven a wp-content/_orphans-remove-it con un registro para restaurar (no se borran).',
        'Scan orphans'                        => 'Buscar huérfanos',
        'Move to pre-delete'                  => 'Mover a pre-borrado',
        'Large / suspicious files'            => 'Archivos grandes / sospechosos',
        'Finds logs, backups, archives and oversized files that are usually safe to remove. Moved to _junk-remove-it with a restore log.'
            => 'Encuentra logs, copias, archivos comprimidos y archivos demasiado grandes que suelen ser seguros de eliminar. Se mueven a _junk-remove-it con un registro para restaurar.',
        'Min size'                            => 'Tamaño mínimo',
        'Scan files'                          => 'Buscar archivos',
        'Move selected to pre-delete'         => 'Mover seleccionados a pre-borrado',
        'Apache capability tests'             => 'Pruebas de capacidades de Apache',
        'Tests which Apache/.htaccess features the host supports (rewrite, redirects, Basic auth, gzip, caching, headers) in isolated subfolders — never touching the live .htaccess.'
            => 'Prueba qué funciones de Apache/.htaccess soporta el hosting (reescritura, redirecciones, autenticación Basic, gzip, caché, cabeceras) en subcarpetas aisladas — sin tocar el .htaccess real.',
        'Run capability tests'                => 'Ejecutar pruebas de capacidades',
        'orphans'                             => 'huérfanos',
        'files'                               => 'archivos',
        'file(s) moved to'                    => 'archivo(s) movidos a',
        'Move orphan uploads to the pre-delete folder? Reversible via log.' => '¿Mover los uploads huérfanos a la carpeta de pre-borrado? Reversible mediante registro.',
        'Move these files to the pre-delete folder? Reversible via log.' => '¿Mover estos archivos a la carpeta de pre-borrado? Reversible mediante registro.',
        'Running Apache tests…'               => 'Ejecutando pruebas de Apache…',
        'Only on Apache; the PHP dev server does not process .htaccess.' => 'Solo en Apache; el servidor de desarrollo PHP no procesa .htaccess.',
        // Performance
        'Performance'                         => 'Rendimiento',
        'Speed up the site with browser compression and caching (via .htaccess, tested safely first), an optional static HTML snapshot cache, and a performance check — both a quick internal measurement and links to external tools.'
            => 'Acelerá el sitio con compresión y caché del navegador (vía .htaccess, probado sin riesgo primero), una caché opcional de HTML estático, y un chequeo de rendimiento — una medición interna rápida y enlaces a herramientas externas.',
        'Browser optimizations (.htaccess)'   => 'Optimizaciones del navegador (.htaccess)',
        'Gzip/deflate compression'            => 'Compresión gzip/deflate',
        'Browser cache (Expires/Cache-Control)' => 'Caché del navegador (Expires/Cache-Control)',
        'Cache headers and ETag tuning'       => 'Cabeceras de caché y ajuste de ETag',
        'Apply optimizations'                 => 'Aplicar optimizaciones',
        'Static page cache'                   => 'Caché de página estática',
        'Saves an HTML snapshot of published pages to wp-content/cache (a static full-page cache).'
            => 'Guarda una instantánea HTML de las páginas publicadas en wp-content/cache (una caché estática de página completa).',
        'Generate static cache'               => 'Generar caché estática',
        'Performance check'                   => 'Chequeo de rendimiento',
        'Run internal check'                  => 'Ejecutar chequeo interno',
        'Select at least one optimization'    => 'Seleccioná al menos una optimización',
        'Optimizations applied'               => 'Optimizaciones aplicadas',
        'Optimizations disabled'              => 'Optimizaciones desactivadas',
        'Generating static cache…'            => 'Generando caché estática…',
        'Pages cached'                        => 'Páginas cacheadas',
        'Load time'                           => 'Tiempo de carga',
        'Page size'                           => 'Tamaño de página',
        'Asset references'                    => 'Referencias de assets',
        'External tools'                      => 'Herramientas externas',
        'Vet in the sandbox before installing live.' => 'Evaluá en el sandbox antes de instalar en producción.',
        'Destroy the sandbox folder and its test tables?' => '¿Destruir la carpeta del sandbox y sus tablas de prueba?',
        'Clear Cache'                         => 'Limpiar caché',
        'Database Export'                     => 'Exportar base de datos',
        'Import SQL'                          => 'Importar SQL',
        // Common form labels (export / import / tools)
        'DB Host'                             => 'Host de BD',
        'DB Name'                             => 'Nombre de BD',
        'DB User'                             => 'Usuario de BD',
        'DB Password'                         => 'Contraseña de BD',
        'Table prefix'                        => 'Prefijo de tablas',
        'Test connection'                     => 'Probar conexión',
        'Source URL (origin)'                 => 'URL de origen',
        'Target URL (destination)'            => 'URL de destino',
        'DB Connection for Tools'             => 'Conexión de BD para Herramientas',
        'URL Replacement'                     => 'Reemplazo de URL',
        'Replace directly in the current database' => 'Reemplazar directamente en la base de datos actual',
        'Replaces the source URL with the target across the live database (assets and links, serialized-safe). Each target URL is checked over HTTP first and only replaced if it responds — broken targets are skipped. Reversible log.'
            => 'Reemplaza la URL de origen por la de destino en toda la base de datos actual (assets y enlaces, seguro con datos serializados). Cada URL de destino se verifica por HTTP primero y solo se reemplaza si responde — los destinos rotos se omiten. Registro reversible.',
        'Find & verify URLs'                  => 'Buscar y verificar URLs',
        'Replace verified in DB'              => 'Reemplazar verificadas en BD',
        'Finding URLs in the database…'       => 'Buscando URLs en la base de datos…',
        'No source URLs found in the database.' => 'No se encontraron URLs de origen en la base de datos.',
        'reachable'                           => 'accesibles',
        'skipped'                             => 'omitidas',
        'Replace %d verified URL(s) in the live database? Reversible via log.' => '¿Reemplazar %d URL(s) verificada(s) en la base de datos actual? Reversible mediante registro.',
        'rows updated'                        => 'filas actualizadas',
        'Export Actions'                      => 'Acciones de exportación',
        'Destination DB'                      => 'BD de destino',
        'Extract Hosting'                     => 'Extraer hosting',
        'Site URL Manager'                    => 'Gestor de URL del sitio',
        'Media URL Scanner'                   => 'Escáner de URLs de medios',
        'Plugin Manager'                      => 'Gestor de plugins',
        'PHP Configuration'                   => 'Configuración PHP',
        'Site Health Check'                   => 'Chequeo de salud del sitio',
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
        // Anti-hotlinking
        'Anti-hotlinking protection'          => 'Protección anti-hotlinking',
        'Hotlinking is when other websites embed this site\'s images or assets directly, so their visitors consume this server\'s bandwidth. This checks whether a referer-based protection exists, tests the proposed .htaccess rules in an isolated subfolder (they must not cause an HTTP 500), and only then applies them — backing up the current .htaccess first so you can revert.'
            => 'El hotlinking ocurre cuando otros sitios insertan directamente las imágenes o recursos de este sitio, de modo que sus visitantes consumen el ancho de banda de este servidor. Esto comprueba si existe una protección basada en referer, prueba las reglas propuestas del .htaccess en una subcarpeta aislada (no deben provocar un error HTTP 500) y solo entonces las aplica, haciendo primero una copia de seguridad del .htaccess actual para poder revertir.',
        'Check protection'                    => 'Comprobar protección',
        'Test rules (isolated)'               => 'Probar reglas (aislado)',
        'Apply protection'                    => 'Aplicar protección',
        'Run the isolated test first.'        => 'Ejecutá primero la prueba aislada.',
        'Anti-hotlink protection applied'     => 'Protección anti-hotlink aplicada',
        'backup'                              => 'copia',
        'Apply anti-hotlink rules to .htaccess? The current .htaccess is backed up first so you can revert.'
            => '¿Aplicar reglas anti-hotlink al .htaccess? Primero se hace una copia del .htaccess actual para poder revertir.',
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
        'Download the latest phpMyAdmin into a randomly-named folder (harder for bots to find). The path is shown below. "Temporary access" drops a config with the DB credentials and opens phpMyAdmin logged in; the config self-destructs after the chosen time so afterwards you must log in manually.'
            => 'Descarga la última versión de phpMyAdmin en una carpeta con nombre aleatorio (más difícil de encontrar para bots). El path se muestra abajo. "Acceso temporal" escribe una configuración con las credenciales de la base de datos y abre phpMyAdmin ya autenticado; la configuración se autodestruye al pasar el tiempo elegido, así que después hay que iniciar sesión manualmente.',
        'Download & install phpMyAdmin'       => 'Descargar e instalar phpMyAdmin',
        'Installed at'                        => 'Instalado en',
        'Not installed yet.'                  => 'Aún no instalado.',
        'Temporary access'                    => 'Acceso temporal',
        'Access time'                         => 'Tiempo de acceso',
        'Remove credentials now'              => 'Quitar credenciales ahora',
        'Credentials expire in'               => 'Las credenciales expiran en',
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
        'Friendly permalinks'                 => 'Enlaces permanentes amigables',
        'Post name (recommended)'             => 'Nombre del post (recomendado)',
        'Day and name'                        => 'Día y nombre',
        'Month and name'                      => 'Mes y nombre',
        'Numeric'                             => 'Numérico',
        'Plain (?p=123, no rewrite)'          => 'Simple (?p=123, sin reescritura)',
        'Testing in isolated folder…'         => 'Probando en carpeta aislada…',
        'No rewrite needed — safe to apply'   => 'No requiere reescritura — seguro para aplicar',
        'Generate sitemap.xml'                => 'Generar sitemap.xml',
        'Indexing'                            => 'Indexación',
        'Allow'                               => 'Permitir',
        'Block'                               => 'Bloquear',
        'Request indexing'                    => 'Solicitar indexación',
        'Check if indexed'                    => 'Verificar si está indexado',
        // Broken Link Checker
        'Broken Link Checker'                 => 'Verificador de enlaces rotos',
        'Checks the links stored in the database over HTTP and groups the broken ones (HTTP 400+ or no response) by domain. Click a domain to see its broken URLs and open each in a new tab.'
            => 'Verifica por HTTP los enlaces guardados en la base de datos y agrupa los rotos (HTTP 400+ o sin respuesta) por dominio. Pulsá un dominio para ver sus URLs rotas y abrir cada una en una pestaña nueva.',
        'Scope'                               => 'Alcance',
        'Current site only'                   => 'Solo el sitio actual',
        'All linked domains'                  => 'Todos los dominios enlazados',
        'Max URLs'                            => 'URLs máx.',
        'Check links'                         => 'Verificar enlaces',
        'Stop'                                => 'Detener',
        'No broken links found'               => 'No se encontraron enlaces rotos',
        'checked'                             => 'verificadas',
        'Broken links found'                  => 'Enlaces rotos encontrados',
        'broken'                              => 'rotos',
        'Open in WordPress admin'             => 'Abrir en el panel de WordPress',
        // Broken-link find & replace
        'Find & replace in links'             => 'Buscar y reemplazar en enlaces',
        'Replace a substring inside links across the database. Before saving, the resulting URL is checked over HTTP and the change is applied only if it responds (exists). Serialized-safe, reversible log.'
            => 'Reemplaza un texto dentro de los enlaces en toda la base de datos. Antes de guardar, la URL resultante se verifica por HTTP y el cambio se aplica solo si responde (existe). Seguro con datos serializados, registro reversible.',
        'Find (text in the link)'             => 'Buscar (texto en el enlace)',
        'Replace with'                        => 'Reemplazar por',
        'Find, verify & replace'              => 'Buscar, verificar y reemplazar',
        'Code'                                => 'Código',
        'Location in WordPress'               => 'Ubicación en WordPress',
        // Site Password
        'Site Password (HTTP Basic)'          => 'Contraseña del sitio (HTTP Basic)',
        'Protect the whole site with an HTTP Basic password (.htaccess + .htpasswd). Before applying, a test writes the rule into an isolated subfolder and checks the host returns 401 (auth required), not 500. Open the test URL to confirm the login prompt appears; Apply stays disabled until the test passes.'
            => 'Protegé todo el sitio con una contraseña HTTP Basic (.htaccess + .htpasswd). Antes de aplicar, una prueba escribe la regla en una subcarpeta aislada y verifica que el servidor devuelva 401 (pide autenticación), no 500. Abrí la URL de prueba para confirmar que aparece el diálogo de acceso; Aplicar queda deshabilitado hasta que la prueba pase.',
        'Apply to whole site'                 => 'Aplicar a todo el sitio',
        'Remove protection'                   => 'Quitar protección',
        'open to confirm the login prompt'    => 'abrir para confirmar el diálogo de acceso',
        'Only rewrite URLs whose file exists on disk (use on the destination with files already uploaded)'
            => 'Solo reescribir URLs cuyo archivo exista en disco (usar en el destino con los archivos ya subidos)',
    ],
];

// Theme (dark default). Resolved server-side from ?theme= or a cookie to avoid a flash.
if (isset($_GET['theme']) && in_array($_GET['theme'], ['dark', 'light'], true)) {
    $THEME = $_GET['theme'];
    setcookie('migrate_theme', $THEME, time() + 31536000, '/');
} else {
    $THEME = (isset($_COOKIE['migrate_theme']) && $_COOKIE['migrate_theme'] === 'light') ? 'light' : 'dark';
}
$GLOBALS['__THEME'] = $THEME;

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
// Fetches a URL body with the same curl-CLI → curl-PHP → file_get_contents fallback
// used across the tool. Returns the body string, or null on any failure. Used by the
// self-update check and the news feed.
function http_fetch_body($url, $headers = []) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_HTTPHEADER => $headers]);
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); unset($ch);
        if ($code >= 200 && $code < 400 && $body !== false && $body !== '') return $body;
    }
    if (ini_get('allow_url_fopen')) {
        $hdr = $headers ? implode("\r\n", $headers) . "\r\n" : "User-Agent: cms_tools\r\n";
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 30, 'follow_location' => 1, 'header' => $hdr]]));
        if ($body !== false && $body !== '') return $body;
    }
    return null;
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
// Ensure the multisite network tables exist in the sandbox copy and seed site 1.
// Creates {dst}site, {dst}blogs, {dst}sitemeta (CREATE LIKE the live network tables
// when present, otherwise a minimal schema) and inserts the primary site/blog rows.
function cms_ms_setup($pdo, $live_prefix, $dst, $host, $path, $subdomain) {
    $out = ['created' => [], 'seeded' => false, 'error' => ''];
    try {
        $exists = fn($t) => (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
        // site
        if (!$exists($dst.'site')) {
            if ($exists($live_prefix.'site')) $pdo->exec("CREATE TABLE `{$dst}site` LIKE `{$live_prefix}site`");
            else $pdo->exec("CREATE TABLE `{$dst}site` (id bigint(20) unsigned NOT NULL auto_increment, domain varchar(200) NOT NULL default '', path varchar(100) NOT NULL default '', PRIMARY KEY (id), KEY domain (domain(140),path(51)))");
            $out['created'][] = $dst.'site';
        }
        // blogs
        if (!$exists($dst.'blogs')) {
            if ($exists($live_prefix.'blogs')) $pdo->exec("CREATE TABLE `{$dst}blogs` LIKE `{$live_prefix}blogs`");
            else $pdo->exec("CREATE TABLE `{$dst}blogs` (blog_id bigint(20) NOT NULL auto_increment, site_id bigint(20) NOT NULL default '0', domain varchar(200) NOT NULL default '', path varchar(100) NOT NULL default '', registered datetime NOT NULL default '1970-01-01 00:00:00', last_updated datetime NOT NULL default '1970-01-01 00:00:00', public tinyint(2) NOT NULL default '1', archived tinyint(2) NOT NULL default '0', mature tinyint(2) NOT NULL default '0', spam tinyint(2) NOT NULL default '0', deleted tinyint(2) NOT NULL default '0', lang_id int(11) NOT NULL default '0', PRIMARY KEY (blog_id), KEY domain (domain(50),path(5)))");
            $out['created'][] = $dst.'blogs';
        }
        // sitemeta
        if (!$exists($dst.'sitemeta')) {
            if ($exists($live_prefix.'sitemeta')) $pdo->exec("CREATE TABLE `{$dst}sitemeta` LIKE `{$live_prefix}sitemeta`");
            else $pdo->exec("CREATE TABLE `{$dst}sitemeta` (meta_id bigint(20) NOT NULL auto_increment, site_id bigint(20) NOT NULL default '0', meta_key varchar(255) default NULL, meta_value longtext, PRIMARY KEY (meta_id), KEY meta_key (meta_key(191)), KEY site_id (site_id))");
            $out['created'][] = $dst.'sitemeta';
        }
        // Seed the primary site + primary blog (row 1) if empty.
        if ((int)$pdo->query("SELECT COUNT(*) FROM `{$dst}site`")->fetchColumn() === 0) {
            $st = $pdo->prepare("INSERT INTO `{$dst}site` (id, domain, path) VALUES (1, ?, ?)"); $st->execute([$host, $path]);
        }
        if ((int)$pdo->query("SELECT COUNT(*) FROM `{$dst}blogs`")->fetchColumn() === 0) {
            $st = $pdo->prepare("INSERT INTO `{$dst}blogs` (blog_id, site_id, domain, path, registered, last_updated, public) VALUES (1, 1, ?, ?, NOW(), NOW(), 1)");
            $st->execute([$host, $path]);
        }
        // Minimal network options so WP boots the network.
        foreach (['site_name'=>'Sandbox Network','admin_email'=>'admin@example.com','subdomain_install'=>($subdomain?'1':'0'),'wpmu_upgrade_site'=>'1'] as $k=>$v) {
            if ((int)$pdo->query("SELECT COUNT(*) FROM `{$dst}sitemeta` WHERE site_id=1 AND meta_key=".$pdo->quote($k))->fetchColumn() === 0)
                $pdo->prepare("INSERT INTO `{$dst}sitemeta` (site_id, meta_key, meta_value) VALUES (1, ?, ?)")->execute([$k,$v]);
        }
        $out['seeded'] = true;
    } catch (Exception $e) { $out['error'] = $e->getMessage(); }
    return $out;
}

// Add one sub-site to the sandbox multisite network: allocate a blog_id, insert
// the blogs row, and create the 11 per-blog tables (CREATE LIKE the base tables)
// with minimal seeded options. Returns the new blog id + url.
function cms_ms_add_site($pdo, $dst, $host, $base_path, $slug, $title, $email, $subdomain) {
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
    if ($slug === '') return ['ok' => false, 'error' => 'Invalid sub-site slug'];
    // Compute domain/path for the new blog.
    if ($subdomain) { $domain = $slug . '.' . $host; $path = rtrim($base_path,'/').'/'; }
    else            { $domain = $host; $path = rtrim($base_path,'/') . '/' . $slug . '/'; }
    // Reject duplicates.
    $dup = $pdo->prepare("SELECT blog_id FROM `{$dst}blogs` WHERE domain=? AND path=? LIMIT 1"); $dup->execute([$domain,$path]);
    if ($dup->fetchColumn()) return ['ok' => false, 'error' => 'A sub-site with that path already exists'];
    $pdo->prepare("INSERT INTO `{$dst}blogs` (site_id, domain, path, registered, last_updated, public) VALUES (1, ?, ?, NOW(), NOW(), 1)")->execute([$domain,$path]);
    $blog_id = (int)$pdo->lastInsertId();
    // Per-blog tables: wp_N_* created from the base wp_* ones.
    $base_tables = ['posts','postmeta','options','terms','termmeta','term_taxonomy','term_relationships','comments','commentmeta','links'];
    $created = 0;
    foreach ($base_tables as $bt) {
        $src = $dst . $bt; $new = $dst . $blog_id . '_' . $bt;
        try { if ((bool)$pdo->query("SHOW TABLES LIKE ".$pdo->quote($src))->fetchColumn()) { $pdo->exec("DROP TABLE IF EXISTS `$new`"); $pdo->exec("CREATE TABLE `$new` LIKE `$src`"); $created++; } } catch (Exception $e) {}
    }
    // Seed minimal options for the sub-site.
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $suburl = $proto . '://' . $domain . $path;
    $optsT = $dst . $blog_id . '_options';
    if ((bool)$pdo->query("SHOW TABLES LIKE ".$pdo->quote($optsT))->fetchColumn()) {
        foreach (['siteurl'=>$suburl,'home'=>$suburl,'blogname'=>$title,'admin_email'=>$email,'template'=>'twentytwentyfour','stylesheet'=>'twentytwentyfour','active_plugins'=>serialize([])] as $k=>$v) {
            $pdo->prepare("INSERT INTO `$optsT` (option_name, option_value, autoload) VALUES (?, ?, 'yes')")->execute([$k,$v]);
        }
    }
    return ['ok' => true, 'blog_id' => $blog_id, 'url' => $suburl, 'tables_created' => $created];
}

function split_sql_to_zip_parts($sql_path, $out_dir, $base_name, $max_bytes) {
    if (!is_file($sql_path)) return ['ok' => false, 'error' => 'SQL file not found for splitting'];
    if (!class_exists('ZipArchive')) return ['ok' => false, 'error' => 'ZipArchive not available on this server'];

    $header = "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET NAMES utf8mb4;\n\n";
    $footer = "\nSET FOREIGN_KEY_CHECKS=1;\n";

    $fh = fopen($sql_path, 'rb');
    if (!$fh) return ['ok' => false, 'error' => 'Could not open SQL file'];

    $parts      = [];       // committed part file paths
    $part_index = 0;
    $buffer     = $header;   // statements already in the current part
    $in_part    = 0;         // statements in current part (beyond header)
    $stmt       = '';        // statement being read
    $in_string  = false;
    $quote      = '';
    $escaped    = false;

    // Write $content as a .zip once (only when a part is finalized).
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

    // Size tracking in O(n): track the raw byte length and estimate the compressed
    // size as rawLen * ratio. The ratio is refined from a real gzencode sample only
    // periodically (every N statements), never per-statement, so incompressible data
    // can't degrade this to O(n²). A conservative budget absorbs estimation error.
    $budget    = (int)max(16384, $max_bytes * 0.90 - 2048); // room for zip overhead + estimate slack
    $ratio     = 1.0;                 // start pessimistic (assume incompressible)
    $rawLen    = strlen($buffer);     // raw bytes currently in the part
    $footerZ   = strlen($footer);
    $sinceSample = 0;                 // statements since last real sample

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

        // Refresh the ratio from a real sample periodically (bounded cost): every
        // 500 statements normally, or every 25 when near the budget for a precise
        // cut. Never per-statement, so incompressible data stays O(n).
        $candRaw = $rawLen + strlen($stmt) + $footerZ;
        $est     = $candRaw * $ratio;
        $sinceSample++;
        $near    = ($est > $budget * 0.80);
        if ($in_part > 0 && ($sinceSample >= 500 || ($near && $sinceSample >= 25))) {
            $ratio = strlen(gzencode($buffer . $stmt . $footer, 6)) / max(1, $candRaw);
            $est   = $candRaw * $ratio;
            $sinceSample = 0;
        }
        if ($in_part > 0 && $est > $budget) {
            $part_index++;
            [$sz, $path] = $write_zip($buffer . $footer, $part_index);
            if ($sz === -1) { fclose($fh); return ['ok' => false, 'error' => 'Failed writing a zip part']; }
            $parts[] = $path;
            $buffer  = $header . $stmt;
            $in_part = 1;
            $rawLen  = strlen($buffer);
        } else {
            $buffer .= $stmt;
            $in_part++;
            $rawLen  = $rawLen + strlen($stmt);
        }
        $stmt = '';
    }

    fclose($fh);

    // Write the final part (buffer holds the header + remaining statements).
    if ($in_part > 0 || $buffer !== $header) {
        $part_index++;
        [$sz, $path] = $write_zip($buffer . $footer, $part_index);
        if ($sz === -1) return ['ok' => false, 'error' => 'Failed writing the final zip part'];
        $parts[] = $path;
    }

    return ['ok' => true, 'parts' => $parts, 'count' => count($parts)];
}

// ============================================================
// AUTENTICACIÓN
// ============================================================
session_start();

$config_file = __DIR__ . '/.migrate_config';
$stored_hash = '';
$cfg_data = [];
if (file_exists($config_file)) {
    $cfg_data = json_decode(file_get_contents($config_file), true) ?: [];
    $stored_hash = $cfg_data['password_hash'] ?? '';
}
// Merge-and-save the tool's JSON config so writers never clobber other keys
// (e.g. saving origin_domain must not drop password_hash).
function migrate_config_set(array $kv) {
    global $config_file;
    $cur = file_exists($config_file) ? (json_decode(file_get_contents($config_file), true) ?: []) : [];
    $cur = array_merge($cur, $kv);
    file_put_contents($config_file, json_encode($cur));
    @chmod($config_file, 0600);
    return $cur;
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
        migrate_config_set(['password_hash' => $hash]);
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

// --- DYNAMIC ASSET FALLBACK (public; invoked by .htaccess on missing files) ---
// When a /wp-content/ asset 404s, .htaccess routes the request here. We fetch it
// from the original site (origin_domain in .migrate_config), save it to the right
// local path so the next request is served by the web server directly, and stream
// it now. If it isn't at the origin either, serve a placeholder image / 204 / 404
// per the stored preference, and log the unrecoverable URL.
if (isset($_GET['action']) && $_GET['action'] === 'asset_fallback') {
    $root = __DIR__;
    // The requested path, forced to a safe /wp-content/ relative path.
    $p = '/' . ltrim(preg_replace('/[?].*$/', '', (string)($_GET['p'] ?? '')), '/');
    $cfg = file_exists(__DIR__ . '/.migrate_config') ? (json_decode(file_get_contents(__DIR__ . '/.migrate_config'), true) ?: []) : [];
    $origin = rtrim((string)($cfg['origin_domain'] ?? ''), '/');
    $miss   = $cfg['asset_miss'] ?? 'placeholder'; // placeholder | none | 404
    $is_img = (bool)preg_match('/\.(jpe?g|png|gif|webp|svg|avif|bmp|ico)$/i', $p);
    $ctypes = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml','avif'=>'image/avif','ico'=>'image/x-icon','pdf'=>'application/pdf','css'=>'text/css','js'=>'application/javascript','mp4'=>'video/mp4','woff2'=>'font/woff2','woff'=>'font/woff'];
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    $serve_placeholder = function () {
        // Tiny gray "image not found" SVG.
        header('Content-Type: image/svg+xml'); header('Cache-Control: no-store');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="200"><rect width="100%" height="100%" fill="#eee"/><text x="50%" y="50%" fill="#999" font-family="sans-serif" font-size="14" text-anchor="middle" dominant-baseline="middle">image not found</text></svg>';
    };
    $log_miss = function ($url) use ($root) {
        @file_put_contents($root . '/.asset-misses.log', date('c') . "\t" . $url . "\n", FILE_APPEND);
    };

    // Reject anything outside /wp-content/ or with traversal.
    if (strpos($p, '/wp-content/') !== 0 || strpos($p, '..') !== false) { http_response_code(404); exit; }
    $dest = $root . $p;
    // Already present (race): serve it.
    if (is_file($dest)) { if ($ext && isset($ctypes[$ext])) header('Content-Type: ' . $ctypes[$ext]); readfile($dest); exit; }

    if ($origin !== '' && preg_match('#^https?://#i', $origin)) {
        $url = $origin . $p;
        $body = false; $code = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'WP-Migration-Tool/fallback']);
            $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); unset($ch);
        } elseif (ini_get('allow_url_fopen')) {
            $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20, 'follow_location' => 1, 'ignore_errors' => true]]));
            $code = ($body !== false) ? 200 : 0;
        }
        if ($code === 200 && $body !== false && strlen($body) > 0) {
            @mkdir(dirname($dest), 0755, true);
            @file_put_contents($dest, $body);   // cache for next time
            if ($ext && isset($ctypes[$ext])) header('Content-Type: ' . $ctypes[$ext]);
            header('Cache-Control: no-store');
            echo $body; exit;
        }
        $log_miss($url); // couldn't recover
    }
    // Not recoverable: placeholder / nothing / 404 per preference.
    if ($is_img && $miss === 'placeholder') { $serve_placeholder(); exit; }
    if ($miss === 'none') { http_response_code(204); exit; }
    http_response_code(404); exit;
}

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
            // Token is 12 hex chars followed by "." or "_" (split parts add _part_NNN).
            if (preg_match('/_([a-f0-9]{12})[._]/', $name, $m)) $token = $m[1];
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

    // --- UPLOADS OPTIMIZATION: shared helpers (organize / dedupe / sanitize) ---
    // Replace an uploads-relative path with a new one across posts/postmeta/options,
    // serialized-safe (recalculates s:LENGTH). Returns the number of rows updated.
    // $from and $to are relative to wp-content/uploads (e.g. "2023/foto.jpg").
    $uploads_replace_ref = function ($pdo, $prefix, $from, $to) {
        if ($from === $to) return 0;
        $tables = [[$prefix.'posts','post_content','ID'],[$prefix.'postmeta','meta_value','meta_id'],[$prefix.'options','option_value','option_id']];
        $updated = 0;
        // Match the path with or without a leading /wp-content/uploads/ and with escaped slashes.
        $needles = ['wp-content/uploads/' . $from];
        $swap = function ($s) use ($from, $to) {
            $a = 'wp-content/uploads/' . $from;
            $b = 'wp-content/uploads/' . $to;
            // Handle both plain and JSON-escaped (\/) slashes.
            return str_replace([$a, str_replace('/', '\\/', $a)], [$b, str_replace('/', '\\/', $b)], $s);
        };
        foreach ($tables as [$tbl,$col,$id]) {
            try {
                $st = $pdo->prepare("SELECT `$id`,`$col` FROM `$tbl` WHERE `$col` LIKE ?");
                $st->execute(['%' . $needles[0] . '%']);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $old = $row[$col];
                    // Serialized strings first (recalc length), then plain.
                    $new = preg_replace_callback('/s:(\d+):"((?:[^"\\\\]|\\\\.)*)"/s', function($m) use ($swap){ $x=$swap($m[2]); return $x===$m[2]?$m[0]:'s:'.strlen($x).':"'.$x.'"'; }, $old);
                    $new = $swap($new);
                    if ($new !== $old) { $pdo->prepare("UPDATE `$tbl` SET `$col`=? WHERE `$id`=?")->execute([$new,$row[$id]]); $updated++; }
                }
            } catch (Exception $e) {}
        }
        return $updated;
    };
    // The thumbnails of an uploads file: same dir, <base>-WxH.<ext>.
    $uploads_siblings = function ($root, $rel) {
        $uploads = $root . '/wp-content/uploads';
        $dir = (strpos($rel, '/') !== false) ? substr($rel, 0, strrpos($rel, '/')) : '';
        $file = basename($rel);
        if (!preg_match('/^(.+?)(\.[a-z0-9]+)$/i', $file, $m)) return [$rel];
        $base = preg_replace('/-\d+x\d+$/', '', $m[1]); $ext = $m[2];
        $out = [];
        $glob = $uploads . ($dir !== '' ? '/' . $dir : '') . '/' . $base . '*' . $ext;
        foreach (glob($glob) ?: [] as $f) {
            $fn = basename($f);
            if ($fn === $base . $ext || preg_match('/^' . preg_quote($base, '/') . '-\d+x\d+' . preg_quote($ext, '/') . '$/i', $fn))
                $out[] = ($dir !== '' ? $dir . '/' : '') . $fn;
        }
        return $out ?: [$rel];
    };
    // WordPress-style filename sanitizer (ASCII, dashes, lowercase-safe).
    $sanitize_name = function ($name) {
        $ext = '';
        if (preg_match('/^(.*)(\.[A-Za-z0-9]+)$/', $name, $m)) { $name = $m[1]; $ext = strtolower($m[2]); }
        $tr = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u','à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u','ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n','Ü'=>'u'];
        $name = strtr($name, $tr);
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9._-]+/', '-', $name);  // spaces & symbols -> dash
        $name = preg_replace('/-+/', '-', trim($name, '-. '));
        return ($name === '' ? 'file' : $name) . $ext;
    };

    // --- CLEAN SITE: shared helper to collect USED uploads ---
    // A file in wp-content/uploads is "used" when its path is referenced in the DB,
    // OR it is a WordPress-generated thumbnail of a referenced original
    // (<base>-<W>x<H>.<ext>), OR the referenced file is a thumbnail whose original we keep.
    // Returns [usedRelSet, allFiles, totals].
    $clean_collect = function ($pdo, $prefix, $root) {
        $uploads = $root . '/wp-content/uploads';
        // 1) Gather referenced upload paths from the DB.
        $refRx = '#(?:/)?wp-content/uploads/([^\s"\'<>()\[\]\\\\?#]+?\.[a-z0-9]{2,5})#i';
        $referenced = []; // relative-to-uploads => true
        foreach ([[$prefix.'posts','post_content'],[$prefix.'postmeta','meta_value'],[$prefix.'options','option_value']] as $tc) {
            [$tbl,$col] = $tc;
            try {
                $st = $pdo->query("SELECT `$col` FROM `$tbl` WHERE `$col` LIKE '%wp-content/uploads/%'");
                while (($v = $st->fetchColumn()) !== false) {
                    if (!preg_match_all($refRx, $v, $m)) continue;
                    foreach ($m[1] as $rel) { $referenced[str_replace('\\/', '/', $rel)] = true; }
                }
            } catch (Exception $e) {}
        }
        // 2) Build the set of base names to keep: each referenced file AND its
        //    originals/thumbnails sharing the same base (strip a -WxH suffix).
        $keepBase = [];
        foreach (array_keys($referenced) as $rel) {
            $dir = (strpos($rel, '/') !== false) ? substr($rel, 0, strrpos($rel, '/') + 1) : '';
            $file = basename($rel);
            $base = preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $file); // original of a thumbnail
            $keepBase[$dir . $base] = true;
        }
        // 3) Walk uploads; a file is used if it equals a kept base or is a thumbnail of one.
        $all = []; $used = []; $usedBytes = 0; $totalBytes = 0;
        if (is_dir($uploads)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $rel = ltrim(str_replace($uploads, '', $f->getPathname()), '/\\');
                $rel = str_replace('\\', '/', $rel);
                $size = $f->getSize();
                $all[$rel] = $size; $totalBytes += $size;
                $dir = (strpos($rel, '/') !== false) ? substr($rel, 0, strrpos($rel, '/') + 1) : '';
                $file = basename($rel);
                $base = preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $file);
                // keep if the file itself OR its stripped-base is referenced/kept
                if (isset($referenced[$rel]) || isset($keepBase[$dir . $file]) || isset($keepBase[$dir . $base])) {
                    $used[$rel] = $size; $usedBytes += $size;
                }
            }
        }
        return [$used, $all, $usedBytes, $totalBytes];
    };

    // --- CLEAN SITE: analyze (dry-run, changes nothing) ---
    if ($action === 'clean_site_analyze') {
        @set_time_limit(0);
        $cs_db = [
            'host' => $_POST['db_host'] ?? $db['host'], 'name' => $_POST['db_name'] ?? $db['name'],
            'user' => $_POST['db_user'] ?? $db['user'], 'pass' => $_POST['db_pass'] ?? $db['pass'],
            'prefix' => $_POST['db_prefix'] ?? $db['prefix'], 'charset' => 'utf8mb4',
        ];
        $r = try_db_connect($cs_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $root = rtrim($abspath, '/');
        if (!is_dir($root . '/wp-content/uploads')) { echo json_encode(['ok' => false, 'error' => 'wp-content/uploads not found']); exit; }
        [$used, $all, $usedBytes, $totalBytes] = $clean_collect($r['pdo'], $cs_db['prefix'], $root);
        $unusedCount = count($all) - count($used);
        $isImg = fn($p) => (bool)preg_match('/\.(jpe?g|png|gif|webp|svg|avif|bmp|ico|tiff?)$/i', $p);
        $usedImg = count(array_filter(array_keys($used), $isImg));
        echo json_encode(['ok' => true,
            'total_files' => count($all), 'used_files' => count($used), 'unused_files' => $unusedCount,
            'used_images' => $usedImg,
            'total_size' => human_size($totalBytes), 'used_size' => human_size($usedBytes),
            'reclaimable' => human_size($totalBytes - $usedBytes), 'reclaimable_bytes' => $totalBytes - $usedBytes,
        ]);
        exit;
    }

    // --- CLEAN SITE: apply (destructive — copies used, moves old aside) ---
    if ($action === 'clean_site_apply') {
        @set_time_limit(0);
        $cs_db = [
            'host' => $_POST['db_host'] ?? $db['host'], 'name' => $_POST['db_name'] ?? $db['name'],
            'user' => $_POST['db_user'] ?? $db['user'], 'pass' => $_POST['db_pass'] ?? $db['pass'],
            'prefix' => $_POST['db_prefix'] ?? $db['prefix'], 'charset' => 'utf8mb4',
        ];
        $r = try_db_connect($cs_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $root = rtrim($abspath, '/');
        $uploads = $root . '/wp-content/uploads';
        if (!is_dir($uploads) || !is_writable($root . '/wp-content')) { echo json_encode(['ok' => false, 'error' => 'uploads missing or wp-content not writable']); exit; }
        [$used] = $clean_collect($r['pdo'], $cs_db['prefix'], $root);

        $ts    = date('YmdHis');
        $clean = $root . '/wp-content/uploads_clean_' . $ts;
        $oldnm = 'uploads_old-remove-it_' . $ts;
        $old   = $root . '/wp-content/' . $oldnm;
        if (!@mkdir($clean, 0755, true)) { echo json_encode(['ok' => false, 'error' => 'Could not create the clean folder']); exit; }

        // Copy each used file into the clean folder, preserving structure.
        $copied = 0; $copyErr = 0;
        foreach (array_keys($used) as $rel) {
            $src = $uploads . '/' . $rel; $dst = $clean . '/' . $rel;
            @mkdir(dirname($dst), 0755, true);
            if (@copy($src, $dst)) $copied++; else $copyErr++;
        }
        // Swap: uploads -> uploads_old-remove-it_TS ; uploads_clean_TS -> uploads.
        if (!@rename($uploads, $old)) {
            // Roll back the clean folder.
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($clean, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath()); }
            @rmdir($clean);
            echo json_encode(['ok' => false, 'error' => 'Could not move the current uploads aside; nothing changed']); exit;
        }
        @rename($clean, $uploads);

        // Restore log (JSON) so the change can be undone.
        $log = ['ts' => $ts, 'old_folder' => $oldnm, 'copied' => $copied, 'copy_errors' => $copyErr,
                'created' => date('c'), 'uploads' => 'wp-content/uploads'];
        @file_put_contents($root . '/wp-content/.clean-site-' . $ts . '.json', json_encode($log, JSON_PRETTY_PRINT));

        echo json_encode(['ok' => true, 'copied' => $copied, 'copy_errors' => $copyErr,
            'old_folder' => 'wp-content/' . $oldnm, 'log' => 'wp-content/.clean-site-' . $ts . '.json', 'ts' => $ts]);
        exit;
    }

    // --- CLEAN SITE: restore from a log (undo) ---
    if ($action === 'clean_site_restore') {
        $root = rtrim($abspath, '/');
        $ts = preg_replace('/[^0-9]/', '', $_POST['ts'] ?? '');
        $logf = $root . '/wp-content/.clean-site-' . $ts . '.json';
        if (!$ts || !is_file($logf)) { echo json_encode(['ok' => false, 'error' => 'Restore log not found']); exit; }
        $log = json_decode(file_get_contents($logf), true) ?: [];
        $old = $root . '/wp-content/' . ($log['old_folder'] ?? '');
        $uploads = $root . '/wp-content/uploads';
        if (!is_dir($old)) { echo json_encode(['ok' => false, 'error' => 'Backup folder ' . ($log['old_folder'] ?? '') . ' not found']); exit; }
        // Move the current (cleaned) uploads out of the way, restore the old one.
        $discard = $root . '/wp-content/uploads_discarded_' . date('YmdHis');
        if (is_dir($uploads)) @rename($uploads, $discard);
        $ok = @rename($old, $uploads);
        echo json_encode(['ok' => $ok, 'restored_from' => $log['old_folder'] ?? '', 'cleaned_moved_to' => $ok ? basename($discard) : null,
            'error' => $ok ? '' : 'Could not restore']);
        exit;
    }

    // --- OPTIMIZE: shared — connect + list referenced uploads (with source rows) ---
    $opt_db = [
        'host' => $_POST['db_host'] ?? $db['host'], 'name' => $_POST['db_name'] ?? $db['name'],
        'user' => $_POST['db_user'] ?? $db['user'], 'pass' => $_POST['db_pass'] ?? $db['pass'],
        'prefix' => $_POST['db_prefix'] ?? $db['prefix'], 'charset' => 'utf8mb4',
    ];
    // Append one entry to a rotating optimize log and return its file path.
    $opt_log = function ($root, $kind, $entry) {
        $f = $root . '/wp-content/.optimize-' . $kind . '.json';
        $data = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
        $data[] = $entry;
        @file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT));
        return '.optimize-' . $kind . '.json';
    };

    // ============ 1) ORGANIZE uploads into subfolders ============
    // Target: YYYYMMDD_name -> YYYY/MM/name ; otherwise -> <FirstCharUpper>/name.
    // Only files REFERENCED in the DB are moved (so we don't touch orphans), together
    // with their thumbnails, updating every reference. Reversible via log.
    if ($action === 'organize_analyze' || $action === 'organize_apply') {
        @set_time_limit(0);
        $r = try_db_connect($opt_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $opt_db['prefix']; $root = rtrim($abspath, '/');
        $uploads = $root . '/wp-content/uploads';
        if (!is_dir($uploads)) { echo json_encode(['ok' => false, 'error' => 'wp-content/uploads not found']); exit; }

        // Destination (relative to uploads) for a referenced file basename.
        $dest_for = function ($rel) {
            $base = basename($rel);
            if (preg_match('/^(\d{4})(\d{2})(\d{2})[_-]?(.*)$/', $base, $m)
                && $m[1] >= '1990' && $m[1] <= '2099' && $m[2] >= '01' && $m[2] <= '12') {
                return $m[1] . '/' . $m[2] . '/' . ($m[4] !== '' ? $m[4] : $base);
            }
            $first = strtoupper(substr($base, 0, 1));
            if (!preg_match('/[A-Z0-9]/', $first)) $first = '_';
            return $first . '/' . $base;
        };

        // Collect referenced files that live directly under uploads/ (depth 0) — those
        // not already organized. Files already in YYYY/MM or Letter/ folders are skipped.
        [$used] = $clean_collect($pdo, $prefix, $root);
        $candidates = [];
        foreach (array_keys($used) as $rel) {
            if (strpos($rel, '/') !== false) continue;          // only top-level files
            $dst = $dest_for($rel);
            if ($dst === $rel) continue;
            $candidates[$rel] = $dst;
        }

        if ($action === 'organize_analyze') {
            $sample = array_slice($candidates, 0, 20, true);
            $preview = [];
            foreach ($sample as $from => $to) $preview[] = ['from' => $from, 'to' => $to];
            echo json_encode(['ok' => true, 'count' => count($candidates), 'preview' => $preview,
                'files' => array_keys($candidates)]);
            exit;
        }

        // organize_apply — process a batch passed by the browser.
        $batch = json_decode($_POST['files'] ?? '', true);
        $batch = is_array($batch) ? array_slice($batch, 0, 50) : [];
        $moved = 0; $errors = 0; $logname = '';
        foreach ($batch as $rel) {
            if (!isset($candidates[$rel])) continue;
            $to = $candidates[$rel];
            // Move the file and its thumbnails together.
            $siblings = $uploads_siblings($root, $rel);
            foreach ($siblings as $sib) {
                $sibDir = (strpos($rel,'/')!==false)?substr($rel,0,strrpos($rel,'/')+1):'';
                $sibDst = dirname($to) . '/' . basename($sib);
                $src = $uploads . '/' . $sib; $dst = $uploads . '/' . $sibDst;
                if (!is_file($src)) continue;
                // 1) locate in DB first (confirm it's referenced), 2) copy, 3) update DB, 4) delete original.
                $refs = $uploads_replace_ref($pdo, $prefix, $sib, $sibDst);
                @mkdir(dirname($dst), 0755, true);
                if (@copy($src, $dst)) { @unlink($src); $moved++; $logname = $opt_log($root, 'organize', ['from' => $sib, 'to' => $sibDst, 'refs' => $refs, 'at' => date('c')]); }
                else { $errors++; }
            }
        }
        echo json_encode(['ok' => true, 'moved' => $moved, 'errors' => $errors, 'log' => $logname]);
        exit;
    }

    // ============ 2) DEDUPLICATE identical files ============
    // Group uploads files by size, then by SHA-1; for each duplicate group keep one
    // (most-referenced, else shortest path), repoint refs of the rest, delete them.
    if ($action === 'dedupe_analyze' || $action === 'dedupe_apply') {
        @set_time_limit(0);
        $r = try_db_connect($opt_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $opt_db['prefix']; $root = rtrim($abspath, '/');
        $uploads = $root . '/wp-content/uploads';
        if (!is_dir($uploads)) { echo json_encode(['ok' => false, 'error' => 'wp-content/uploads not found']); exit; }

        // Build size buckets first (cheap), then hash only within collisions.
        $bySize = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = str_replace('\\','/', ltrim(str_replace($uploads,'',$f->getPathname()),'/\\'));
            $bySize[$f->getSize()][] = $rel;
        }
        $groups = []; // sha1 => [rel,...]
        foreach ($bySize as $size => $files) {
            if (count($files) < 2) continue;              // unique size -> not a dup
            foreach ($files as $rel) {
                $h = @sha1_file($uploads . '/' . $rel);
                if ($h) $groups[$h][] = $rel;
            }
        }
        $dupGroups = array_values(array_filter($groups, fn($g) => count($g) > 1));

        // Reference counter to pick the keeper.
        $ref_count = function ($rel) use ($pdo, $prefix) {
            $n = 0; $needle = '%wp-content/uploads/' . str_replace('%','\%',$rel) . '%';
            foreach ([[$prefix.'posts','post_content'],[$prefix.'postmeta','meta_value'],[$prefix.'options','option_value']] as [$t,$c]) {
                try { $st=$pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE `$c` LIKE ?"); $st->execute([$needle]); $n += (int)$st->fetchColumn(); } catch (Exception $e) {}
            }
            return $n;
        };

        if ($action === 'dedupe_analyze') {
            $preview = []; $dupFiles = 0; $wasteBytes = 0; $all = [];
            foreach ($dupGroups as $g) {
                // keeper = most referenced, tie -> shortest path
                usort($g, fn($a,$b) => ($ref_count($b) <=> $ref_count($a)) ?: (strlen($a) <=> strlen($b)));
                $keep = $g[0]; $dups = array_slice($g, 1);
                $dupFiles += count($dups);
                $sz = @filesize($uploads . '/' . $keep) ?: 0; $wasteBytes += $sz * count($dups);
                $all[] = $g; // full group (ordered keeper-first) for apply
                if (count($preview) < 20) $preview[] = ['keep' => $keep, 'dups' => $dups];
            }
            echo json_encode(['ok' => true, 'groups' => count($dupGroups), 'dup_files' => $dupFiles,
                'reclaimable' => human_size($wasteBytes), 'preview' => $preview, 'all_groups' => $all]);
            exit;
        }

        // dedupe_apply — process a batch of groups.
        $batch = json_decode($_POST['groups'] ?? '', true);
        $batch = is_array($batch) ? array_slice($batch, 0, 50) : [];
        $repointed = 0; $deleted = 0; $logname = '';
        foreach ($batch as $g) {
            if (!is_array($g) || count($g) < 2) continue;
            usort($g, fn($a,$b) => ($ref_count($b) <=> $ref_count($a)) ?: (strlen($a) <=> strlen($b)));
            $keep = $g[0];
            foreach (array_slice($g, 1) as $dup) {
                if (!is_file($uploads . '/' . $dup)) continue;
                $refs = $uploads_replace_ref($pdo, $prefix, $dup, $keep);  // repoint refs to keeper
                $repointed += $refs;
                @unlink($uploads . '/' . $dup); $deleted++;
                $logname = $opt_log($root, 'dedupe', ['deleted' => $dup, 'kept' => $keep, 'refs' => $refs, 'at' => date('c')]);
            }
        }
        echo json_encode(['ok' => true, 'deleted' => $deleted, 'refs_repointed' => $repointed, 'log' => $logname]);
        exit;
    }

    // ============ 3) SANITIZE special-character filenames ============
    if ($action === 'sanitize_analyze' || $action === 'sanitize_apply') {
        @set_time_limit(0);
        $r = try_db_connect($opt_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $opt_db['prefix']; $root = rtrim($abspath, '/');
        $uploads = $root . '/wp-content/uploads';
        if (!is_dir($uploads)) { echo json_encode(['ok' => false, 'error' => 'wp-content/uploads not found']); exit; }

        // Files whose basename changes under sanitization.
        $candidates = []; // rel => newRel
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = str_replace('\\','/', ltrim(str_replace($uploads,'',$f->getPathname()),'/\\'));
            $dir = (strpos($rel,'/')!==false)?substr($rel,0,strrpos($rel,'/')+1):'';
            $clean = $sanitize_name(basename($rel));
            if ($clean !== basename($rel)) $candidates[$rel] = $dir . $clean;
        }

        if ($action === 'sanitize_analyze') {
            $preview = []; foreach (array_slice($candidates, 0, 20, true) as $from => $to) $preview[] = ['from' => $from, 'to' => $to];
            echo json_encode(['ok' => true, 'count' => count($candidates), 'preview' => $preview, 'files' => array_keys($candidates)]);
            exit;
        }

        // sanitize_apply — batch.
        $batch = json_decode($_POST['files'] ?? '', true);
        $batch = is_array($batch) ? array_slice($batch, 0, 50) : [];
        $renamed = 0; $errors = 0; $logname = '';
        foreach ($batch as $rel) {
            if (!isset($candidates[$rel])) continue;
            $to = $candidates[$rel];
            $src = $uploads . '/' . $rel; $dst = $uploads . '/' . $to;
            if (!is_file($src) || is_file($dst)) { $errors++; continue; }
            // locate+update DB first, then rename on disk.
            $refs = $uploads_replace_ref($pdo, $prefix, $rel, $to);
            if (@rename($src, $dst)) { $renamed++; $logname = $opt_log($root, 'sanitize', ['from' => $rel, 'to' => $to, 'refs' => $refs, 'at' => date('c')]); }
            else { $errors++; }
        }
        echo json_encode(['ok' => true, 'renamed' => $renamed, 'errors' => $errors, 'log' => $logname]);
        exit;
    }

    // --- DB URL REPLACE: analyze (find distinct origin URLs + their target) ---
    // Finds every distinct absolute URL under the source origin referenced in the
    // DB (assets and page links), and computes the target URL (origin -> dest). The
    // browser then HEAD-verifies each target and only verified ones are replaced.
    if ($action === 'dburl_analyze') {
        @set_time_limit(0);
        $u_db = [
            'host' => $_POST['db_host'] ?? $db['host'], 'name' => $_POST['db_name'] ?? $db['name'],
            'user' => $_POST['db_user'] ?? $db['user'], 'pass' => $_POST['db_pass'] ?? $db['pass'],
            'prefix' => $_POST['db_prefix'] ?? $db['prefix'], 'charset' => 'utf8mb4',
        ];
        $origin = rtrim(trim($_POST['source_url'] ?? ''), '/');
        $target = rtrim(trim($_POST['target_url'] ?? ''), '/');
        if ($origin === '' || $target === '' || $origin === $target) { echo json_encode(['ok' => false, 'error' => 'Provide distinct source and target URLs']); exit; }
        if (!preg_match('#^https?://#i', $origin)) { echo json_encode(['ok' => false, 'error' => 'Source must be a full URL']); exit; }
        $r = try_db_connect($u_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $u_db['prefix'];
        // Distinct full URLs that start with the origin.
        $rx = '#' . preg_quote($origin, '#') . '[^\s"\'<>()\[\]\\\\]*#i';
        $urls = [];
        foreach ([[$prefix.'posts','post_content'],[$prefix.'postmeta','meta_value'],[$prefix.'options','option_value']] as [$tbl,$col]) {
            try {
                $st = $pdo->query("SELECT `$col` FROM `$tbl` WHERE `$col` LIKE " . $pdo->quote('%' . $origin . '%'));
                while (($v = $st->fetchColumn()) !== false) {
                    if (!preg_match_all($rx, $v, $m)) continue;
                    foreach ($m[0] as $u) {
                        $u = rtrim(str_replace('\\/', '/', $u), '.,;:)\'"');
                        if (!isset($urls[$u])) $urls[$u] = $target . substr($u, strlen($origin));
                        if (count($urls) >= 5000) break 2;
                    }
                }
            } catch (Exception $e) {}
        }
        $out = [];
        foreach ($urls as $from => $to) $out[] = ['from' => $from, 'to' => $to];
        echo json_encode(['ok' => true, 'origin' => $origin, 'target' => $target, 'count' => count($out), 'urls' => $out]);
        exit;
    }

    // --- DB URL REPLACE: apply (only the verified URLs) ---
    // Receives a batch of {from,to} pairs the browser confirmed reachable and
    // replaces each exact URL across the DB (serialized-safe), with a reversible log.
    if ($action === 'dburl_apply') {
        @set_time_limit(0);
        $u_db = [
            'host' => $_POST['db_host'] ?? $db['host'], 'name' => $_POST['db_name'] ?? $db['name'],
            'user' => $_POST['db_user'] ?? $db['user'], 'pass' => $_POST['db_pass'] ?? $db['pass'],
            'prefix' => $_POST['db_prefix'] ?? $db['prefix'], 'charset' => 'utf8mb4',
        ];
        $pairs = json_decode($_POST['pairs'] ?? '', true);
        $pairs = is_array($pairs) ? array_slice($pairs, 0, 200) : [];
        if (!$pairs) { echo json_encode(['ok' => false, 'error' => 'No verified URLs provided']); exit; }
        $r = try_db_connect($u_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $u_db['prefix']; $root = rtrim($abspath, '/');
        $tables = [[$prefix.'posts','post_content','ID'],[$prefix.'postmeta','meta_value','meta_id'],[$prefix.'options','option_value','option_id']];
        $updated = 0; $logged = [];
        foreach ($pairs as $p) {
            $from = (string)($p['from'] ?? ''); $to = (string)($p['to'] ?? '');
            if ($from === '' || $to === '' || $from === $to) continue;
            // Replace the exact URL, both plain and JSON-escaped.
            $swap = function ($s) use ($from, $to) {
                return str_replace([$from, str_replace('/', '\\/', $from)], [$to, str_replace('/', '\\/', $to)], $s);
            };
            $rows = 0;
            foreach ($tables as [$tbl,$col,$id]) {
                try {
                    $st = $pdo->prepare("SELECT `$id`,`$col` FROM `$tbl` WHERE `$col` LIKE ?");
                    $st->execute(['%' . $from . '%']);
                    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                        $old = $row[$col];
                        $new = preg_replace_callback('/s:(\d+):"((?:[^"\\\\]|\\\\.)*)"/s', function($m) use ($swap){ $x=$swap($m[2]); return $x===$m[2]?$m[0]:'s:'.strlen($x).':"'.$x.'"'; }, $old);
                        $new = $swap($new);
                        if ($new !== $old) { $pdo->prepare("UPDATE `$tbl` SET `$col`=? WHERE `$id`=?")->execute([$new,$row[$id]]); $rows++; }
                    }
                } catch (Exception $e) {}
            }
            if ($rows) { $updated += $rows; $logged[] = ['from' => $from, 'to' => $to, 'rows' => $rows]; }
        }
        // Reversible log.
        $logname = '';
        if ($logged) {
            $f = $root . '/wp-content/.dburl-replace.json';
            $data = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
            $data[] = ['at' => date('c'), 'changes' => $logged];
            @file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT));
            $logname = '.dburl-replace.json';
        }
        echo json_encode(['ok' => true, 'updated_rows' => $updated, 'urls' => count($logged), 'log' => $logname]);
        exit;
    }

    // --- BROKEN LINK FIND & REPLACE: analyze (find substring, compute new URL) ---
    // Finds absolute URLs containing the "find" substring, computes the URL after
    // replacing find->replace, and returns the pairs for the browser to HTTP-verify.
    if ($action === 'blreplace_analyze') {
        @set_time_limit(0);
        $u_db = [
            'host' => $_POST['db_host'] ?? $db['host'], 'name' => $_POST['db_name'] ?? $db['name'],
            'user' => $_POST['db_user'] ?? $db['user'], 'pass' => $_POST['db_pass'] ?? $db['pass'],
            'prefix' => $_POST['db_prefix'] ?? $db['prefix'], 'charset' => 'utf8mb4',
        ];
        $find    = (string)($_POST['find'] ?? '');
        $replace = (string)($_POST['replace'] ?? '');
        if ($find === '' || $find === $replace) { echo json_encode(['ok' => false, 'error' => 'Provide a non-empty "find" different from "replace"']); exit; }
        $r = try_db_connect($u_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $u_db['prefix'];
        // Absolute URLs that contain the find substring.
        $rx = '#https?://[^\s"\'<>()\[\]\\\\]*' . preg_quote($find, '#') . '[^\s"\'<>()\[\]\\\\]*#i';
        $pairs = [];
        foreach ([[$prefix.'posts','post_content'],[$prefix.'postmeta','meta_value'],[$prefix.'options','option_value']] as [$tbl,$col]) {
            try {
                $st = $pdo->query("SELECT `$col` FROM `$tbl` WHERE `$col` LIKE " . $pdo->quote('%' . $find . '%'));
                while (($v = $st->fetchColumn()) !== false) {
                    if (!preg_match_all($rx, $v, $m)) continue;
                    foreach ($m[0] as $u) {
                        $u = rtrim(str_replace('\\/', '/', $u), '.,;:)\'"');
                        if (strpos($u, $find) === false) continue;
                        $to = str_replace($find, $replace, $u);
                        if ($u !== $to && !isset($pairs[$u])) $pairs[$u] = $to;
                        if (count($pairs) >= 5000) break 2;
                    }
                }
            } catch (Exception $e) {}
        }
        $out = [];
        foreach ($pairs as $from => $to) $out[] = ['from' => $from, 'to' => $to];
        echo json_encode(['ok' => true, 'count' => count($out), 'urls' => $out]);
        exit;
    }

    // Note: the actual replacement reuses the dburl_apply endpoint (same {from,to}
    // verified-pairs contract); the browser calls it directly after verification.

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
        // SQL prefilter (fast), then confirm per row with the SAME pattern the fix
        // uses, so the reported count matches exactly what Fix will rewrite. The
        // previous LIKE-only count produced false positives (rows with a plain http
        // link AND a separate relative /wp-content/ path), reporting rows the fix
        // then left untouched.
        $like    = '%http%://%/wp-content%';
        $pattern = '#https?://[^\s\'"<>]*?/wp-content/[^\s\'"<>()\[\]\\\\]*#i';

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
                $stmt = $pdo->prepare("SELECT `{$t['col']}` FROM `{$t['table']}` WHERE `{$t['col']}` LIKE ?");
                $stmt->execute([$like]);
                $count = 0;
                while (($val = $stmt->fetchColumn()) !== false) {
                    if (!preg_match_all($pattern, $val, $m)) continue; // confirm a real absolute URL
                    $count++;
                    foreach (array_slice($m[0], 0, 2) as $url) { if (count($samples) < 10) $samples[$url] = true; }
                }
                if ($count > 0) {
                    $results[] = ['table' => $t['table'], 'col' => $t['col'], 'count' => $count];
                    $total    += $count;
                }
            } catch (Exception $e) {}
        }

        echo json_encode([
            'ok'      => true,
            'total'   => $total,
            'tables'  => $results,
            'samples' => array_values(array_keys($samples)),
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
        // The host may be followed by a subfolder (e.g. /wordpress/wp-content/…),
        // so match any path segment before /wp-content/ and drop it too.
        $pattern = '#https?://[^\s\'"<>]*?(/wp-content/[^\s\'"<>()\[\]\\\\]*)#i';
        $root    = rtrim($abspath, '/');

        // Disk verification is OPT-IN. By default every absolute wp-content URL is
        // rewritten to a relative path. With verify_disk=1, a URL is only rewritten
        // when its target file exists on disk (useful on the destination where the
        // files are already uploaded); others are left absolute and counted.
        $verify_disk = ($_POST['verify_disk'] ?? '') === '1';
        $unverified = 0;
        $rewrite = function ($str) use ($pattern, $root, $verify_disk, &$unverified) {
            return preg_replace_callback($pattern, function ($m) use ($root, $verify_disk, &$unverified) {
                if (!$verify_disk) return $m[1]; // always rewrite to the relative path
                $rel = preg_replace('/[?#].*$/', '', $m[1]); // strip query/hash for the disk check
                if (is_file($root . $rel)) return $m[1];
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

    // --- COLLECT URLS TO CHECK FOR BROKEN LINKS ---
    // Extracts unique absolute http(s) URLs from content so the browser can verify
    // them in batches (with a progress bar) via the verify_url endpoint. Scope:
    //   all      – every external/linked domain (excluding the current host)
    //   site     – only the current host plus the detected legacy site domains
    // The frontend does the HTTP checking so long scans don't time out the script.
    if ($action === 'scan_broken_collect') {
        $c_db = [
            'host' => $_POST['db_host'] ?? $db['host'], 'name' => $_POST['db_name'] ?? $db['name'],
            'user' => $_POST['db_user'] ?? $db['user'], 'pass' => $_POST['db_pass'] ?? $db['pass'],
            'prefix' => $_POST['db_prefix'] ?? $db['prefix'], 'charset' => 'utf8mb4',
        ];
        $scope = in_array($_POST['scope'] ?? '', ['all', 'site'], true) ? $_POST['scope'] : 'site';
        $cap   = max(10, min(5000, (int)($_POST['cap'] ?? 200)));
        $r = try_db_connect($c_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $c_db['prefix'];

        $cur_host = preg_replace('#^www\.#', '', strtolower(parse_url(current_url(), PHP_URL_HOST) ?? ''));
        // Base WP admin URL to build "edit this post" links shown next to each URL.
        $home = rtrim((string)$pdo->query("SELECT option_value FROM `{$prefix}options` WHERE option_name='siteurl' LIMIT 1")->fetchColumn(), '/');
        $url_rx = '#https?://[^\s"\'<>()\[\]\\\\]+#i';
        $urls = []; // url => ['host'=>, 'where'=>, 'edit'=>, 'type'=>]

        // Capture the source: which post/page/meta/option each URL came from, plus a
        // WP-admin edit link when it is a post/page, so the user can locate it.
        $add = function ($u, $where, $edit, $type) use (&$urls, $scope, $cur_host, $cap) {
            $u = rtrim($u, '.,;:)\'"');
            $host = preg_replace('#^www\.#', '', strtolower(parse_url($u, PHP_URL_HOST) ?? ''));
            if ($host === '') return true;
            if ($scope === 'site' && $host !== $cur_host) return true;
            if (!isset($urls[$u])) $urls[$u] = ['host' => $host, 'where' => $where, 'edit' => $edit, 'type' => $type];
            return count($urls) < $cap;
        };

        // 1) posts/pages — content with title + edit link.
        try {
            $stmt = $pdo->query("SELECT ID, post_title, post_type, post_content FROM `{$prefix}posts` WHERE post_content LIKE '%http%' AND post_status IN ('publish','draft','private','pending')");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!preg_match_all($url_rx, $row['post_content'], $m)) continue;
                $title = $row['post_title'] !== '' ? $row['post_title'] : ('#' . $row['ID']);
                $where = ucfirst($row['post_type']) . ': ' . $title;
                $edit  = $home . '/wp-admin/post.php?post=' . (int)$row['ID'] . '&action=edit';
                foreach ($m[0] as $u) { if (!$add($u, $where, $edit, 'post')) break 2; }
            }
        } catch (Exception $e) {}
        // 2) postmeta — attach to the owning post (Elementor/ACF/Divi data).
        try {
            $stmt = $pdo->query("SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type FROM `{$prefix}postmeta` pm LEFT JOIN `{$prefix}posts` p ON p.ID = pm.post_id WHERE pm.meta_value LIKE '%http%'");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!preg_match_all($url_rx, $row['meta_value'], $m)) continue;
                $title = ($row['post_title'] ?? '') !== '' ? $row['post_title'] : ('#' . (int)$row['post_id']);
                $where = 'Meta (' . $row['meta_key'] . ') · ' . ucfirst((string)($row['post_type'] ?? 'post')) . ': ' . $title;
                $edit  = $row['post_id'] ? $home . '/wp-admin/post.php?post=' . (int)$row['post_id'] . '&action=edit' : '';
                foreach ($m[0] as $u) { if (!$add($u, $where, $edit, 'meta')) break 2; }
            }
        } catch (Exception $e) {}
        // 3) options — settings/widgets, no edit link.
        try {
            $stmt = $pdo->query("SELECT option_name, option_value FROM `{$prefix}options` WHERE option_value LIKE '%http%'");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!preg_match_all($url_rx, $row['option_value'], $m)) continue;
                $where = 'Option: ' . $row['option_name'];
                foreach ($m[0] as $u) { if (!$add($u, $where, '', 'option')) break 2; }
            }
        } catch (Exception $e) {}

        $out = [];
        foreach ($urls as $u => $meta) $out[] = ['url' => $u] + $meta;
        echo json_encode(['ok' => true, 'scope' => $scope, 'cap' => $cap, 'total' => count($out), 'urls' => $out]);
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

    // --- QUICK UPDATE (one-click post-migration) ---
    // Orchestrates the common "just moved the site" steps and returns a per-step
    // report. The DB already knows the OLD url (wp_options); the NEW url is passed
    // from the browser. Each step is independent so a failure doesn't abort the rest.
    if ($action === 'quick_update') {
        @set_time_limit(0);
        $qu_db = [
            'host'    => trim($_POST['db_host']   ?? $db['host']),
            'name'    => trim($_POST['db_name']   ?? $db['name']),
            'user'    => trim($_POST['db_user']   ?? $db['user']),
            'pass'    => (string)($_POST['db_pass'] ?? $db['pass']),
            'prefix'  => trim($_POST['db_prefix'] ?? $db['prefix']),
            'charset' => 'utf8mb4',
        ];
        $new_url = rtrim(trim($_POST['new_url'] ?? ''), '/');
        $steps   = is_array($_POST['steps'] ?? null) ? $_POST['steps'] : explode(',', (string)($_POST['steps'] ?? ''));
        $want = fn($s) => in_array($s, $steps, true);
        $root = rtrim($abspath, '/');
        $report = [];

        // Step 1 — rewrite wp-config.php DB credentials (backup first).
        if ($want('wpconfig')) {
            if (!$wp_config_path || !is_writable($wp_config_path)) {
                $report[] = ['step' => 'wpconfig', 'ok' => false, 'msg' => 'wp-config.php not found or not writable'];
            } else {
                @copy($wp_config_path, $wp_config_path . '.bak-' . date('YmdHis'));
                $cfg = file_get_contents($wp_config_path);
                $repl = ['DB_NAME' => $qu_db['name'], 'DB_USER' => $qu_db['user'], 'DB_PASSWORD' => $qu_db['pass'], 'DB_HOST' => $qu_db['host']];
                foreach ($repl as $const => $val) {
                    $cfg = preg_replace(
                        "/(define\s*\(\s*['\"]" . $const . "['\"]\s*,\s*)['\"][^'\"]*['\"]/",
                        '${1}' . var_export($val, true),
                        $cfg, 1, $cnt
                    );
                }
                $ok = @file_put_contents($wp_config_path, $cfg) !== false;
                $report[] = ['step' => 'wpconfig', 'ok' => $ok, 'msg' => $ok ? 'wp-config.php updated (backup saved)' : 'Write failed'];
            }
        }

        // Connect for the DB-side steps.
        $pdo = null;
        if ($want('siteurl') || $want('relativize') || $want('plugins')) {
            $r = try_db_connect($qu_db);
            if (!$r['ok']) {
                $report[] = ['step' => 'db', 'ok' => false, 'msg' => 'DB connection failed: ' . $r['error']];
            } else { $pdo = $r['pdo']; }
        }
        $prefix = $qu_db['prefix'];

        // Step 2 — siteurl/home → current URL.
        if ($want('siteurl') && $pdo) {
            if ($new_url === '') { $report[] = ['step' => 'siteurl', 'ok' => false, 'msg' => 'No target URL provided']; }
            else {
                $old = rtrim((string)$pdo->query("SELECT option_value FROM `{$prefix}options` WHERE option_name='siteurl' LIMIT 1")->fetchColumn(), '/');
                $st = $pdo->prepare("UPDATE `{$prefix}options` SET option_value = ? WHERE option_name IN ('siteurl','home')");
                $st->execute([$new_url]);
                $report[] = ['step' => 'siteurl', 'ok' => true, 'msg' => "siteurl/home: " . ($old ?: '(empty)') . " → $new_url"];
            }
        }

        // Step 3 — absolute DB URLs → relative (+ clear cache). Serialized-safe.
        if ($want('relativize') && $pdo) {
            $pattern = '#https?://[^\s\'"<>]*?(/wp-content/[^\s\'"<>()\[\]\\\\]*)#i';
            $rewrite = fn($s) => preg_replace_callback($pattern, fn($m) => $m[1], $s);
            $tables = [[$prefix.'posts','post_content','ID'],[$prefix.'postmeta','meta_value','meta_id'],[$prefix.'options','option_value','option_id']];
            $updated = 0;
            foreach ($tables as [$tbl,$col,$id]) {
                try {
                    $st = $pdo->prepare("SELECT `$id`,`$col` FROM `$tbl` WHERE `$col` LIKE '%http%://%/wp-content%'");
                    $st->execute();
                    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                        $old = $row[$col];
                        $new = preg_replace_callback('/s:(\d+):"((?:[^"\\\\]|\\\\.)*)"/s', function($m) use ($rewrite){ $x=$rewrite($m[2]); return $x===$m[2]?$m[0]:'s:'.strlen($x).':"'.$x.'"'; }, $old);
                        $new = $rewrite($new);
                        if ($new !== $old) { $pdo->prepare("UPDATE `$tbl` SET `$col`=? WHERE `$id`=?")->execute([$new,$row[$id]]); $updated++; }
                    }
                } catch (Exception $e) {}
            }
            $report[] = ['step' => 'relativize', 'ok' => true, 'msg' => "Rewrote $updated row(s) to relative /wp-content/ URLs"];
            // Clear cache dirs.
            $deleted = 0;
            foreach ([$root.'/wp-content/cache', $root.'/wp-content/et-cache'] as $cdir) {
                if (!is_dir($cdir)) continue;
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cdir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) { $f->isFile() ? (@unlink($f->getRealPath()) && $deleted++) : @rmdir($f->getRealPath()); }
            }
            $report[] = ['step' => 'cache', 'ok' => true, 'msg' => "Cache cleared ($deleted file(s))"];
        }

        // Step 4 — deactivate all plugins.
        if ($want('plugins') && $pdo) {
            $pdo->prepare("UPDATE `{$prefix}options` SET option_value = ? WHERE option_name = 'active_plugins'")->execute([serialize([])]);
            $report[] = ['step' => 'plugins', 'ok' => true, 'msg' => 'All plugins deactivated (re-enable via the tested plugin flow)'];
        }

        echo json_encode(['ok' => true, 'report' => $report]);
        exit;
    }

    // ============================================================
    // QUICK DIAGNOSTICS — one endpoint per check (fast, safe, reversible)
    // Each returns {ok, status: ok|warn|error, text, fix?}.
    // ============================================================
    if (strpos($action, 'diag_') === 0) {
        $diag_db = [
            'host' => $_POST['db_host'] ?? $db['host'], 'name' => $_POST['db_name'] ?? $db['name'],
            'user' => $_POST['db_user'] ?? $db['user'], 'pass' => $_POST['db_pass'] ?? $db['pass'],
            'prefix' => $_POST['db_prefix'] ?? $db['prefix'], 'charset' => 'utf8mb4',
        ];
        $root = rtrim($abspath, '/');
        $cur  = current_url();

        if ($action === 'diag_db') {
            $r = try_db_connect($diag_db);
            if ($r['ok']) {
                $ver = '';
                try { $ver = (string)$r['pdo']->query('SELECT VERSION()')->fetchColumn(); } catch (Exception $e) {}
                echo json_encode(['ok'=>true,'status'=>'ok','text'=>'Database connection OK' . ($ver?" (MySQL $ver)":'')]);
            } else {
                echo json_encode(['ok'=>true,'status'=>'error','text'=>'Database connection FAILED: ' . $r['error']]);
            }
            exit;
        }

        if ($action === 'diag_url') {
            $r = try_db_connect($diag_db);
            if (!$r['ok']) { echo json_encode(['ok'=>true,'status'=>'warn','text'=>'Cannot check siteurl — DB connection failed']); exit; }
            $pdo = $r['pdo']; $prefix = $diag_db['prefix'];
            $su = rtrim((string)$pdo->query("SELECT option_value FROM `{$prefix}options` WHERE option_name='siteurl' LIMIT 1")->fetchColumn(), '/');
            if ($su === '') { echo json_encode(['ok'=>true,'status'=>'warn','text'=>'siteurl not found in the database']); exit; }
            $match = rtrim($su,'/') === rtrim($cur,'/');
            echo json_encode(['ok'=>true,'status'=>$match?'ok':'error',
                'text'=>"DB siteurl: $su " . ($match ? '(matches current URL)' : "≠ current URL ($cur) — fix via Quick Update"),
                'fix'=>$match?null:'siteurl']);
            exit;
        }

        if ($action === 'diag_tables') {
            $r = try_db_connect($diag_db);
            if (!$r['ok']) { echo json_encode(['ok'=>true,'status'=>'error','text'=>'DB connection failed']); exit; }
            $pdo = $r['pdo']; $prefix = $diag_db['prefix'];
            $have = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $std = ['posts','postmeta','users','usermeta','options','terms','term_relationships','term_taxonomy','comments','commentmeta','links'];
            $missing = [];
            foreach ($std as $t) if (!in_array($prefix.$t, $have, true)) $missing[] = $prefix.$t;
            echo json_encode(['ok'=>true,'status'=>$missing?'error':'ok',
                'text'=>$missing ? ('Missing WP tables: ' . implode(', ', $missing)) : 'All standard WP tables present']);
            exit;
        }

        if ($action === 'diag_maintenance') {
            $m = is_file($root . '/.maintenance');
            $bp = '1';
            $r = try_db_connect($diag_db);
            if ($r['ok']) { $bp = (string)$r['pdo']->query("SELECT option_value FROM `{$diag_db['prefix']}options` WHERE option_name='blog_public' LIMIT 1")->fetchColumn(); }
            $issue = $m || $bp === '0';
            echo json_encode(['ok'=>true,'status'=>$issue?'warn':'ok',
                'text'=>$m ? '.maintenance file present — site is in maintenance mode' : ($bp==='0' ? 'blog_public=0 (discouraging indexing)' : 'No maintenance mode'),
                'fix'=>$m?'maintenance':null]);
            exit;
        }

        if ($action === 'diag_plugins') {
            // Save active_plugins, deactivate, GET home, find a culprit if it was broken,
            // and ALWAYS restore the original list at the end.
            $r = try_db_connect($diag_db);
            if (!$r['ok']) { echo json_encode(['ok'=>true,'status'=>'warn','text'=>'DB connection failed']); exit; }
            $pdo = $r['pdo']; $prefix = $diag_db['prefix'];
            $orig_raw = (string)$pdo->query("SELECT option_value FROM `{$prefix}options` WHERE option_name='active_plugins' LIMIT 1")->fetchColumn();
            $orig = @unserialize($orig_raw); if (!is_array($orig)) $orig = [];
            $getHome = function () use ($cur) {
                $st=0;$body='';
                if (function_exists('curl_init')) { $ch=curl_init($cur.'/'); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>false]); $body=(string)curl_exec($ch); $st=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); unset($ch); }
                $fatal=(stripos($body,'Fatal error')!==false)||(stripos($body,'critical error')!==false);
                return ['breaks'=>($st>=500||$fatal),'status'=>$st];
            };
            $setActive = fn($list) => $pdo->prepare("UPDATE `{$prefix}options` SET option_value=? WHERE option_name='active_plugins'")->execute([serialize(array_values($list))]);
            $culprit = ''; $before = $getHome();
            try {
                if (!empty($orig) && $before['breaks']) {
                    $setActive([]); $none = $getHome();
                    if (!$none['breaks']) {
                        // Reactivate one by one to find the first that breaks.
                        $acc = [];
                        foreach ($orig as $pl) { $acc[] = $pl; $setActive($acc); $t = $getHome(); if ($t['breaks']) { $culprit = $pl; break; } }
                    }
                }
            } finally {
                $setActive($orig); // ALWAYS restore the original active plugins
            }
            if ($culprit) echo json_encode(['ok'=>true,'status'=>'error','text'=>"Plugin breaks the site: $culprit (original state restored)"]);
            elseif ($before['breaks']) echo json_encode(['ok'=>true,'status'=>'error','text'=>'Home returns an error, but not isolated to a single plugin (restored)']);
            else echo json_encode(['ok'=>true,'status'=>'ok','text'=>'Home loads with current plugins ('.count($orig).' active)']);
            exit;
        }

        if ($action === 'diag_cache') {
            $dirs = array_filter([$root.'/wp-content/cache', $root.'/wp-content/et-cache'], 'is_dir');
            $files = 0;
            foreach ($dirs as $d) foreach (glob($d.'/*') ?: [] as $f) $files++;
            echo json_encode(['ok'=>true,'status'=>$files?'warn':'ok',
                'text'=>$files ? ($files.' cached item(s) in '.count($dirs).' cache dir(s)') : 'No stale cache found',
                'fix'=>$files?'cache':null]);
            exit;
        }

        if ($action === 'diag_htaccess') {
            $ht = $root.'/.htaccess';
            if (!is_file($ht)) { echo json_encode(['ok'=>true,'status'=>'ok','text'=>'.htaccess not present']); exit; }
            $c = file_get_contents($ht);
            $issues = [];
            if (preg_match('#Redirect\s+(301|302|permanent)?\s*/?\s+https?://#i', $c) || preg_match('#RewriteRule\s+.*https?://[^\s]+\s+\[[^\]]*R#i', $c))
                $issues[] = 'external redirect';
            // Isolated 500 test of the live rules.
            $breaks = false; $tested = 'skipped';
            if (is_writable($root)) {
                $dir = $root.'/_ht_diag_'.bin2hex(random_bytes(3));
                if (@mkdir($dir, 0755)) {
                    @file_put_contents($dir.'/.htaccess', $c);
                    @file_put_contents($dir.'/ok.php', "<?php echo 'OK';");
                    $proto=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
                    $base=rtrim(dirname($proto.'://'.($_SERVER['HTTP_HOST']??'localhost').($_SERVER['REQUEST_URI']??'/')),'/');
                    $st=0; if(function_exists('curl_init')){$ch=curl_init($base.'/'.basename($dir).'/ok.php');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false]);curl_exec($ch);$st=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);unset($ch);}
                    @unlink($dir.'/.htaccess');@unlink($dir.'/ok.php');@rmdir($dir);
                    $breaks = ($st>=500); $tested = $breaks?'500':'ok';
                }
            }
            if ($breaks) $issues[] = 'returns HTTP 500 in isolation';
            echo json_encode(['ok'=>true,'status'=>$issues?'error':'ok',
                'text'=>$issues ? ('.htaccess issues: '.implode(', ',$issues)) : '.htaccess looks fine (tested: '.$tested.')',
                'fix'=>$issues?'htaccess':null]);
            exit;
        }

        if ($action === 'diag_phpconfig') {
            $hits = [];
            foreach ([$root.'/.htaccess'=>'php_value', $root.'/.user.ini'=>'ini', $root.'/php.ini'=>'ini'] as $f=>$kind) {
                if (!is_file($f)) continue;
                $c = file_get_contents($f);
                if ($kind==='php_value' && preg_match('/php_value\s+/i',$c)) $hits[] = basename($f).' has php_value directives';
                if ($kind==='ini' && trim($c)!=='') $hits[] = basename($f).' present';
            }
            echo json_encode(['ok'=>true,'status'=>'ok','text'=>$hits ? ('PHP config: '.implode('; ',$hits).' (verify on Apache)') : 'No custom PHP config files']);
            exit;
        }

        if ($action === 'diag_passwords') {
            $ht = $root.'/.htaccess'; $has = false;
            if (is_file($ht) && preg_match('/AuthType\s+Basic|Require\s+valid-user/i', file_get_contents($ht))) $has = true;
            $hp = is_file($root.'/.htpasswd');
            echo json_encode(['ok'=>true,'status'=>$has?'warn':'ok',
                'text'=>$has ? 'Site-wide HTTP Basic auth is active (.htaccess'.($hp?' + .htpasswd':'').') — visitors are blocked' : 'No site-wide password protection']);
            exit;
        }

        if ($action === 'diag_redirects') {
            $issues = [];
            foreach (['index.html','index.htm'] as $f) {
                if (is_file($root.'/'.$f)) {
                    $issues[] = "$f present (may shadow index.php)";
                    $c = @file_get_contents($root.'/'.$f, false, null, 0, 8192) ?: '';
                    if (preg_match('#<meta[^>]+http-equiv=["\']refresh["\'][^>]*https?://#i', $c) || preg_match('#<(iframe|frame)[^>]+src=["\']https?://#i', $c) || preg_match('#location\.(href|replace)\s*=\s*["\']https?://#i', $c))
                        $issues[] = "$f redirects/frames to another site";
                }
            }
            echo json_encode(['ok'=>true,'status'=>$issues?'error':'ok',
                'text'=>$issues ? implode('; ',$issues) : 'No shadowing index.html / external redirect']);
            exit;
        }

        echo json_encode(['ok'=>false,'error'=>'Unknown diagnostic']);
        exit;
    }

    // --- SITE SNAPSHOT / RESTORE ---------------------------------------------
    // Saves a dated snapshot of the important configuration (the config files that
    // are present on disk + the active_plugins list) so the site can be restored to
    // that exact state quickly if something later breaks it. Nothing live is touched
    // on create; restore always takes a safety snapshot of the current state first.
    if (strpos($action, 'snapshot_') === 0) {
        $snap_dir = __DIR__ . '/.migrate_snapshots';
        $root     = rtrim($abspath, '/');
        // The config files worth snapshotting, relative to the site root.
        $snap_files = ['.htaccess', 'php.ini', '.user.ini', 'wp-config.php', 'robots.txt', '.htpasswd'];
        $snap_db = [
            'host' => $_POST['db_host'] ?? $db['host'], 'name' => $_POST['db_name'] ?? $db['name'],
            'user' => $_POST['db_user'] ?? $db['user'], 'pass' => $_POST['db_pass'] ?? $db['pass'],
            'prefix' => $_POST['db_prefix'] ?? $db['prefix'], 'charset' => 'utf8mb4',
        ];

        // Builds one snapshot into $snap_dir, returning the manifest. Reused by create
        // and by the safety snapshot taken before a restore.
        $do_snapshot = function ($note) use ($snap_dir, $root, $snap_files, $snap_db) {
            $stamp = date('Ymd-His');
            $id    = 'snap_' . $stamp;
            // Avoid clobbering a snapshot made in the same second.
            if (is_dir($snap_dir . '/' . $id)) $id .= '_' . bin2hex(random_bytes(2));
            $base  = $snap_dir . '/' . $id;
            if (!@mkdir($base . '/files', 0755, true) && !is_dir($base . '/files'))
                return ['ok' => false, 'error' => 'Cannot create snapshot folder'];
            $saved = [];
            foreach ($snap_files as $f) {
                $src = $root . '/' . $f;
                if (is_file($src) && @copy($src, $base . '/files/' . $f)) $saved[] = $f;
            }
            // active_plugins (raw serialized value, restored verbatim).
            $active_raw = null; $active_list = [];
            $r = try_db_connect($snap_db);
            if ($r['ok']) {
                $active_raw = (string)$r['pdo']->query("SELECT option_value FROM `{$snap_db['prefix']}options` WHERE option_name='active_plugins' LIMIT 1")->fetchColumn();
                $u = @unserialize($active_raw); if (is_array($u)) $active_list = $u;
            }
            $manifest = [
                'id' => $id, 'date' => date('c'), 'note' => (string)$note,
                'files' => $saved, 'prefix' => $snap_db['prefix'],
                'active_plugins' => $active_raw, 'active_count' => count($active_list),
                'db_available' => $r['ok'], 'version' => TOOL_VERSION,
            ];
            file_put_contents($base . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return ['ok' => true, 'manifest' => $manifest];
        };

        if ($action === 'snapshot_create') {
            $res = $do_snapshot(trim($_POST['note'] ?? ''));
            if (!$res['ok']) { echo json_encode($res); exit; }
            $m = $res['manifest'];
            echo json_encode(['ok' => true, 'id' => $m['id'], 'files' => $m['files'],
                'active_count' => $m['active_count'], 'date' => $m['date']]);
            exit;
        }

        if ($action === 'snapshot_list') {
            $out = [];
            foreach (glob($snap_dir . '/snap_*', GLOB_ONLYDIR) ?: [] as $d) {
                $mf = $d . '/manifest.json';
                if (!is_file($mf)) continue;
                $m = json_decode((string)file_get_contents($mf), true);
                if (!is_array($m)) continue;
                $out[] = ['id' => $m['id'] ?? basename($d), 'date' => $m['date'] ?? '',
                    'note' => $m['note'] ?? '', 'files' => $m['files'] ?? [],
                    'active_count' => $m['active_count'] ?? 0, 'db_available' => $m['db_available'] ?? false];
            }
            usort($out, fn($a, $b) => strcmp($b['id'], $a['id'])); // newest first
            echo json_encode(['ok' => true, 'snapshots' => $out]);
            exit;
        }

        if ($action === 'snapshot_restore') {
            $id = preg_replace('/[^a-z0-9_\-]/i', '', $_POST['id'] ?? '');
            $base = $snap_dir . '/' . $id;
            if ($id === '' || !is_dir($base) || !is_file($base . '/manifest.json')) {
                echo json_encode(['ok' => false, 'error' => 'Snapshot not found']); exit;
            }
            $m = json_decode((string)file_get_contents($base . '/manifest.json'), true);
            if (!is_array($m)) { echo json_encode(['ok' => false, 'error' => 'Corrupt manifest']); exit; }
            // Safety snapshot of the current state before overwriting anything.
            $safety = $do_snapshot('auto-before-restore of ' . $id);
            $restored = []; $errors = [];
            foreach (($m['files'] ?? []) as $f) {
                // Only the known config filenames are ever written (no traversal).
                if (!in_array($f, $snap_files, true)) continue;
                $src = $base . '/files/' . $f;
                if (is_file($src) && @copy($src, $root . '/' . $f)) $restored[] = $f;
                else $errors[] = $f;
            }
            // Reactivate exactly the plugins that were active when the snapshot was taken.
            $plugins_restored = false;
            if (!empty($m['active_plugins'])) {
                $r = try_db_connect($snap_db);
                if ($r['ok']) {
                    $list = @unserialize($m['active_plugins']);
                    if (is_array($list)) {
                        $r['pdo']->prepare("UPDATE `{$snap_db['prefix']}options` SET option_value=? WHERE option_name='active_plugins'")
                            ->execute([serialize(array_values($list))]);
                        $plugins_restored = true;
                    }
                }
            }
            echo json_encode(['ok' => true, 'restored' => $restored, 'errors' => $errors,
                'plugins_restored' => $plugins_restored,
                'safety_id' => $safety['ok'] ? $safety['manifest']['id'] : null]);
            exit;
        }

        if ($action === 'snapshot_delete') {
            $id = preg_replace('/[^a-z0-9_\-]/i', '', $_POST['id'] ?? '');
            $base = $snap_dir . '/' . $id;
            if ($id === '' || !is_dir($base)) { echo json_encode(['ok' => false, 'error' => 'Snapshot not found']); exit; }
            // Recursively remove only inside .migrate_snapshots/<id>.
            $rm = function ($p) use (&$rm) {
                foreach (glob($p . '/*') ?: [] as $c) { is_dir($c) ? $rm($c) : @unlink($c); }
                @rmdir($p);
            };
            $rm($base);
            echo json_encode(['ok' => !is_dir($base)]);
            exit;
        }

        echo json_encode(['ok' => false, 'error' => 'Unknown snapshot action']);
        exit;
    }

    // --- SET ORIGIN DOMAIN (persist in .migrate_config) ---
    // Remembers the original WordPress site (domain + optional base path) so missing
    // assets can later be fetched from it. Kept for future processes too.
    if ($action === 'set_origin_domain') {
        $origin = trim($_POST['origin'] ?? '');
        if ($origin !== '' && !preg_match('#^https?://[^\s]+#i', $origin)) {
            echo json_encode(['ok' => false, 'error' => 'Origin must be a full URL (https://old-site.com[/path])']); exit;
        }
        migrate_config_set(['origin_domain' => rtrim($origin, '/')]);
        echo json_encode(['ok' => true, 'origin' => rtrim($origin, '/')]);
        exit;
    }

    // --- SCAN MISSING ASSETS ---
    // Collects /wp-content/ asset references from the DB, keeps those whose file is
    // NOT present on disk, so the browser can fetch them from the origin in batches.
    if ($action === 'assets_scan') {
        $a_db = [
            'host' => $_POST['db_host'] ?? $db['host'], 'name' => $_POST['db_name'] ?? $db['name'],
            'user' => $_POST['db_user'] ?? $db['user'], 'pass' => $_POST['db_pass'] ?? $db['pass'],
            'prefix' => $_POST['db_prefix'] ?? $db['prefix'], 'charset' => 'utf8mb4',
        ];
        $r = try_db_connect($a_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $a_db['prefix'];
        $root = rtrim($abspath, '/');
        // Asset paths under /wp-content/ with a real file extension.
        $rx = '#/wp-content/[^\s"\'<>()\[\]\\\\]+?\.(?:jpe?g|png|gif|webp|svg|avif|ico|bmp|tiff?|mp4|webm|ogg|mp3|wav|pdf|docx?|xlsx?|pptx?|zip|css|js|woff2?|ttf|eot|otf)#i';
        $paths = [];
        foreach ([[$prefix.'posts','post_content'],[$prefix.'postmeta','meta_value'],[$prefix.'options','option_value']] as [$tbl,$col]) {
            try {
                $st = $pdo->query("SELECT `$col` FROM `$tbl` WHERE `$col` LIKE '%/wp-content/%'");
                while (($val = $st->fetchColumn()) !== false) {
                    if (!preg_match_all($rx, $val, $m)) continue;
                    foreach ($m[0] as $p) {
                        $p = preg_replace('/[?#].*$/', '', $p);        // drop query/hash
                        $p = str_replace('\\/', '/', $p);              // unescape JSON slashes
                        $paths[$p] = true;
                        if (count($paths) >= 10000) break 2;
                    }
                }
            } catch (Exception $e) {}
        }
        // Keep only the ones missing on disk.
        $missing = [];
        foreach (array_keys($paths) as $p) {
            if (!is_file($root . $p)) $missing[] = $p;
        }
        $origin = $cfg_data['origin_domain'] ?? '';
        echo json_encode(['ok' => true, 'total_refs' => count($paths), 'missing' => $missing, 'missing_count' => count($missing), 'origin' => $origin]);
        exit;
    }

    // --- RECOVER ASSETS (download a batch from the origin site) ---
    // For each missing relative path, fetch originDomain + path and save it to the
    // matching local path, creating directories as needed. Returns per-file results.
    if ($action === 'asset_recover') {
        @set_time_limit(0);
        $root   = rtrim($abspath, '/');
        $origin = rtrim(trim($_POST['origin'] ?? ($cfg_data['origin_domain'] ?? '')), '/');
        $batch  = json_decode($_POST['paths'] ?? '', true);
        $batch  = is_array($batch) ? array_slice($batch, 0, 25) : [];
        if ($origin === '' || !preg_match('#^https?://#i', $origin)) { echo json_encode(['ok' => false, 'error' => 'No valid origin domain set']); exit; }
        if (!$batch) { echo json_encode(['ok' => false, 'error' => 'No paths provided']); exit; }

        $fetch = function ($url) {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'WP-Migration-Tool/recover']);
                $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); unset($ch);
                return [$code, $body];
            }
            $ctx = stream_context_create(['http' => ['timeout' => 30, 'follow_location' => 1, 'ignore_errors' => true]]);
            $body = @file_get_contents($url, false, $ctx);
            $code = 0;
            $hdrs = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
            if (isset($hdrs[0]) && preg_match('#\s(\d{3})\s#', $hdrs[0], $m)) $code = (int)$m[1];
            return [$code, $body];
        };

        $results = [];
        foreach ($batch as $path) {
            $path = '/' . ltrim(preg_replace('/[?#].*$/', '', (string)$path), '/');
            // Only allow wp-content asset paths (no traversal).
            if (strpos($path, '/wp-content/') !== 0 || strpos($path, '..') !== false) {
                $results[] = ['path' => $path, 'ok' => false, 'msg' => 'rejected path']; continue;
            }
            $url = $origin . $path;
            [$code, $body] = $fetch($url);
            if ($code === 200 && $body !== false && strlen($body) > 0) {
                $dest = $root . $path;
                @mkdir(dirname($dest), 0755, true);
                $ok = @file_put_contents($dest, $body) !== false;
                $results[] = ['path' => $path, 'ok' => $ok, 'status' => $code, 'size' => $ok ? strlen($body) : 0, 'msg' => $ok ? 'recovered' : 'write failed'];
            } else {
                $results[] = ['path' => $path, 'ok' => false, 'status' => $code, 'msg' => 'not found at origin (' . $code . ')'];
            }
        }
        echo json_encode(['ok' => true, 'results' => $results]);
        exit;
    }

    // --- TEST ENVIRONMENT: shared DB connection + sandbox prefix ---
    $sandbox_db = function () use ($db) {
        return [
            'host' => $_POST['db_host'] ?? $db['host'], 'name' => $_POST['db_name'] ?? $db['name'],
            'user' => $_POST['db_user'] ?? $db['user'], 'pass' => $_POST['db_pass'] ?? $db['pass'],
            'prefix' => $_POST['db_prefix'] ?? $db['prefix'], 'charset' => 'utf8mb4',
        ];
    };
    // The sandbox table prefix derives from the live prefix (wp_ -> wptest_).
    $sandbox_prefix = function ($live_prefix) { return rtrim($live_prefix, '_') . 'test_'; };

    // --- CLONE TABLES: list the source tables + row counts ---
    if ($action === 'clone_tables_start') {
        $c_db = $sandbox_db();
        $r = try_db_connect($c_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $src = $c_db['prefix']; $dst = $sandbox_prefix($src);
        $all = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $tables = [];
        foreach ($all as $t) {
            // Only source tables, and never the sandbox tables themselves.
            if (strpos($t, $src) !== 0 || strpos($t, $dst) === 0) continue;
            $rows = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $tables[] = ['name' => $t, 'rows' => $rows];
        }
        echo json_encode(['ok' => true, 'src_prefix' => $src, 'dst_prefix' => $dst, 'tables' => $tables, 'count' => count($tables)]);
        exit;
    }

    // --- CLONE TABLES: replicate one table (CREATE LIKE + INSERT SELECT) ---
    if ($action === 'clone_table_one') {
        @set_time_limit(0);
        $c_db = $sandbox_db();
        $table = trim($_POST['table'] ?? '');
        $r = try_db_connect($c_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $src = $c_db['prefix']; $dst = $sandbox_prefix($src);
        if ($table === '' || strpos($table, $src) !== 0) { echo json_encode(['ok' => false, 'error' => 'Invalid table']); exit; }
        $new = $dst . substr($table, strlen($src));
        try {
            $pdo->exec("DROP TABLE IF EXISTS `$new`");
            $pdo->exec("CREATE TABLE `$new` LIKE `$table`");
            $pdo->exec("INSERT INTO `$new` SELECT * FROM `$table`");
            $rows = (int)$pdo->query("SELECT COUNT(*) FROM `$new`")->fetchColumn();
            echo json_encode(['ok' => true, 'table' => $table, 'into' => $new, 'rows' => $rows]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'table' => $table]);
        }
        exit;
    }

    // --- CLONE TABLES: drop all sandbox tables ---
    if ($action === 'clone_cleanup') {
        $c_db = $sandbox_db();
        $r = try_db_connect($c_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $dst = $sandbox_prefix($c_db['prefix']);
        $all = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $dropped = 0;
        $pdo->exec("SET foreign_key_checks = 0");
        foreach ($all as $t) { if (strpos($t, $dst) === 0) { $pdo->exec("DROP TABLE IF EXISTS `$t`"); $dropped++; } }
        $pdo->exec("SET foreign_key_checks = 1");
        echo json_encode(['ok' => true, 'dropped' => $dropped, 'prefix' => $dst]);
        exit;
    }

    // --- SANDBOX: create an isolated WordPress folder (multisite-style) ---
    // Builds test_env_<rand>/ linking the live core, plugins, themes and uploads
    // (symlink, falling back to copy) and writing its OWN wp-config.php that points
    // at the sandbox table prefix and sandbox URL — so plugins/permalinks/PHP config
    // can be exercised against a runnable WP without touching the live site.
    if ($action === 'sandbox_create') {
        @set_time_limit(0);
        $c_db = $sandbox_db();
        $root = rtrim($abspath, '/');
        $live_prefix = $c_db['prefix'];
        $dst_prefix  = $sandbox_prefix($live_prefix);
        // Reuse an existing sandbox if present.
        $existing = glob($root . '/test_env_*', GLOB_ONLYDIR);
        $dir = ($existing && is_dir($existing[0])) ? $existing[0] : $root . '/test_env_' . bin2hex(random_bytes(4));
        $fresh = !is_dir($dir);
        if ($fresh && !@mkdir($dir, 0755)) { echo json_encode(['ok' => false, 'error' => 'Could not create sandbox folder (web root not writable)']); exit; }

        // Link or copy a path from the live root into the sandbox, counting how.
        $link_method = 'symlink';
        $n_symlinks = 0; $n_copied = 0;
        $provide = function ($name) use ($root, $dir, &$link_method, &$n_symlinks, &$n_copied) {
            $src = $root . '/' . $name; $dest = $dir . '/' . $name;
            if (!file_exists($src) || file_exists($dest)) return;
            if (@symlink($src, $dest)) { $n_symlinks++; return; }  // preferred: no duplication
            $link_method = 'copy';
            if (is_dir($src)) {
                @mkdir($dest, 0755, true);
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($it as $f) {
                    $target = $dest . '/' . $it->getSubPathName();
                    $f->isDir() ? @mkdir($target, 0755, true) : (@copy($f->getRealPath(), $target) && $n_copied++);
                }
            } else { if (@copy($src, $dest)) $n_copied++; }
        };
        // Core dirs/files and content. uploads is shared (symlink only — never copied).
        foreach (['wp-admin', 'wp-includes'] as $d) $provide($d);
        foreach (glob($root . '/*.php') ?: [] as $f) $provide(basename($f)); // index.php, wp-load.php, etc.
        @mkdir($dir . '/wp-content', 0755);
        foreach (['plugins', 'themes', 'mu-plugins'] as $d) {
            if (is_dir($root . '/wp-content/' . $d) && !file_exists($dir . '/wp-content/' . $d)) {
                if (@symlink($root . '/wp-content/' . $d, $dir . '/wp-content/' . $d)) $n_symlinks++;
                else $provide('wp-content/' . $d);
            }
        }
        // uploads: symlink only (large; shared with the live site).
        if (is_dir($root . '/wp-content/uploads') && !file_exists($dir . '/wp-content/uploads'))
            if (@symlink($root . '/wp-content/uploads', $dir . '/wp-content/uploads')) $n_symlinks++;

        // Sandbox URL + its own wp-config.php (sandbox prefix).
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base  = rtrim(dirname($proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/')), '/');
        $sandbox_url = $base . '/' . basename($dir);
        // Optional multisite network for the sandbox (subdirectory is the safe default).
        $multisite = !empty($_POST['multisite']) && $_POST['multisite'] !== '0';
        $ms_subdomain = ($_POST['ms_type'] ?? 'subdir') === 'subdomain';
        $ms_host = parse_url($sandbox_url, PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $ms_path = rtrim(parse_url($sandbox_url, PHP_URL_PATH) ?: '/', '/') . '/';

        $wpcfg  = "<?php\n/* CMS Tools sandbox — isolated test environment */\n";
        $wpcfg .= "define('DB_NAME', " . var_export($c_db['name'], true) . ");\n";
        $wpcfg .= "define('DB_USER', " . var_export($c_db['user'], true) . ");\n";
        $wpcfg .= "define('DB_PASSWORD', " . var_export($c_db['pass'], true) . ");\n";
        $wpcfg .= "define('DB_HOST', " . var_export($c_db['host'], true) . ");\n";
        $wpcfg .= "define('DB_CHARSET', 'utf8mb4');\n\$table_prefix = " . var_export($dst_prefix, true) . ";\n";
        if ($multisite) {
            // Multisite defines must come BEFORE wp-settings.php is required.
            $wpcfg .= "define('WP_ALLOW_MULTISITE', true);\ndefine('MULTISITE', true);\n";
            $wpcfg .= "define('SUBDOMAIN_INSTALL', " . ($ms_subdomain ? 'true' : 'false') . ");\n";
            $wpcfg .= "define('DOMAIN_CURRENT_SITE', " . var_export($ms_host, true) . ");\n";
            $wpcfg .= "define('PATH_CURRENT_SITE', " . var_export($ms_path, true) . ");\n";
            $wpcfg .= "define('SITE_ID_CURRENT_SITE', 1);\ndefine('BLOG_ID_CURRENT_SITE', 1);\n";
        } else {
            $wpcfg .= "define('WP_HOME', " . var_export($sandbox_url, true) . ");\ndefine('WP_SITEURL', " . var_export($sandbox_url, true) . ");\n";
        }
        $wpcfg .= "define('WP_DEBUG', false);\nif (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');\nrequire_once ABSPATH . 'wp-settings.php';\n";
        @file_put_contents($dir . '/wp-config.php', $wpcfg);
        @chmod($dir . '/wp-config.php', 0600);

        // Multisite .htaccess for the sandbox folder (its own; subdir ruleset).
        if ($multisite && !$ms_subdomain) {
            $msht = "RewriteEngine On\nRewriteBase {$ms_path}\nRewriteRule ^index\\.php$ - [L]\n"
                . "RewriteRule ^(wp-(content|admin|includes).*) $1 [L]\n"
                . "RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]\n"
                . "RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\\.php)$ $2 [L]\n"
                . "RewriteRule . index.php [L]\n";
            @file_put_contents($dir . '/.htaccess', $msht);
        }

        // Make the sandbox DB copy actually usable: point siteurl/home at the
        // sandbox URL and convert absolute asset URLs to relative, so WordPress
        // renders correctly instead of redirecting to the old/live domain.
        $db_fixed = ['siteurl' => false, 'relativized' => 0, 'error' => ''];
        $r = try_db_connect($c_db);
        if ($r['ok']) {
            $pdo = $r['pdo'];
            // Ensure the sandbox tables exist (replicated beforehand).
            $has = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($dst_prefix . 'options'))->fetchColumn();
            if ($has) {
                $pdo->prepare("UPDATE `{$dst_prefix}options` SET option_value = ? WHERE option_name IN ('siteurl','home')")->execute([$sandbox_url]);
                $db_fixed['siteurl'] = true;
                // Relativize absolute wp-content URLs in the sandbox copy (serialized-safe).
                $pattern = '#https?://[^\s\'"<>]*?(/wp-content/[^\s\'"<>()\[\]\\\\]*)#i';
                $rewrite = fn($s) => preg_replace_callback($pattern, fn($m) => $m[1], $s);
                foreach ([[$dst_prefix.'posts','post_content','ID'],[$dst_prefix.'postmeta','meta_value','meta_id'],[$dst_prefix.'options','option_value','option_id']] as [$tbl,$col,$id]) {
                    try {
                        $st = $pdo->prepare("SELECT `$id`,`$col` FROM `$tbl` WHERE `$col` LIKE '%http%://%/wp-content%'"); $st->execute();
                        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                            $old = $row[$col];
                            $new = preg_replace_callback('/s:(\d+):"((?:[^"\\\\]|\\\\.)*)"/s', function($m) use ($rewrite){ $x=$rewrite($m[2]); return $x===$m[2]?$m[0]:'s:'.strlen($x).':"'.$x.'"'; }, $old);
                            $new = $rewrite($new);
                            if ($new !== $old) { $pdo->prepare("UPDATE `$tbl` SET `$col`=? WHERE `$id`=?")->execute([$new,$row[$id]]); $db_fixed['relativized']++; }
                        }
                    } catch (Exception $e) {}
                }
                // Also deactivate plugins so the sandbox starts clean for plugin-by-plugin testing.
                $pdo->prepare("UPDATE `{$dst_prefix}options` SET option_value = ? WHERE option_name = 'active_plugins'")->execute([serialize([])]);

                // Multisite: ensure the network tables exist in the copy and seed site 1.
                if ($multisite) {
                    $db_fixed['multisite'] = cms_ms_setup($pdo, $live_prefix, $dst_prefix, $ms_host, $ms_path, $ms_subdomain);
                }
            } else {
                $db_fixed['error'] = 'sandbox tables not found — replicate the database first';
            }
        } else { $db_fixed['error'] = $r['error']; }

        echo json_encode(['ok' => true, 'folder' => basename($dir), 'url' => $sandbox_url,
            'link_method' => $link_method, 'symlinks' => $n_symlinks, 'copied' => $n_copied,
            'dst_prefix' => $dst_prefix, 'fresh' => $fresh, 'multisite' => $multisite, 'db' => $db_fixed]);
        exit;
    }

    // --- SANDBOX: destroy folder (and optionally the sandbox tables) ---
    if ($action === 'sandbox_destroy') {
        $root = rtrim($abspath, '/');
        $removed = 0;
        foreach (glob($root . '/test_env_*', GLOB_ONLYDIR) ?: [] as $dir) {
            // Remove the sandbox folder. Symlinks are unlinked (never follow into live dirs).
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $path = $f->getPathname();
                if (is_link($path)) { @unlink($path); }
                elseif ($f->isDir()) { @rmdir($path); }
                else { @unlink($path); }
            }
            @rmdir($dir); $removed++;
        }
        $drop_tables = ($_POST['drop_tables'] ?? '') === '1';
        $dropped = 0;
        if ($drop_tables) {
            $c_db = $sandbox_db();
            $r = try_db_connect($c_db);
            if ($r['ok']) {
                $pdo = $r['pdo']; $dst = $sandbox_prefix($c_db['prefix']);
                $pdo->exec("SET foreign_key_checks = 0");
                foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) { if (strpos($t, $dst) === 0) { $pdo->exec("DROP TABLE IF EXISTS `$t`"); $dropped++; } }
                $pdo->exec("SET foreign_key_checks = 1");
            }
        }
        echo json_encode(['ok' => true, 'folders_removed' => $removed, 'tables_dropped' => $dropped]);
        exit;
    }

    // --- SANDBOX: list installed plugins with PHP-compat header ---
    // Reads plugin headers from disk (Plugin Name, Requires PHP) so the UI can show
    // a static PHP-compatibility check plus drive the per-plugin smoke test.
    if ($action === 'sandbox_plugins_list') {
        $root = rtrim($abspath, '/');
        $plugins_dir = $root . '/wp-content/plugins';
        $server_php  = phpversion();
        $list = [];
        $read_header = function ($file) {
            $head = @file_get_contents($file, false, null, 0, 8192) ?: '';
            $name = preg_match('/Plugin Name:\s*(.+)/i', $head, $m) ? trim($m[1]) : '';
            $req  = preg_match('/Requires PHP:\s*([0-9.]+)/i', $head, $m) ? trim($m[1]) : '';
            return [$name, $req];
        };
        if (is_dir($plugins_dir)) {
            foreach (glob($plugins_dir . '/*.php') ?: [] as $php) {
                [$name, $req] = $read_header($php);
                if ($name === '') continue;
                $file = basename($php);
                $list[] = ['file' => $file, 'name' => $name, 'requires_php' => $req,
                    'php_ok' => ($req === '' || version_compare($server_php, $req, '>='))];
            }
            foreach (scandir($plugins_dir) ?: [] as $item) {
                if ($item[0] === '.' || !is_dir($plugins_dir . '/' . $item)) continue;
                foreach (glob($plugins_dir . '/' . $item . '/*.php') ?: [] as $php) {
                    [$name, $req] = $read_header($php);
                    if ($name === '') continue;
                    $list[] = ['file' => $item . '/' . basename($php), 'name' => $name, 'requires_php' => $req,
                        'php_ok' => ($req === '' || version_compare($server_php, $req, '>='))];
                    break;
                }
            }
        }
        usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        echo json_encode(['ok' => true, 'server_php' => $server_php, 'plugins' => $list, 'count' => count($list)]);
        exit;
    }

    // --- SANDBOX: smoke-test a single plugin ---
    // Sets active_plugins in the SANDBOX tables to just this plugin, then GETs the
    // sandbox URL and reports whether it loads (ok) or returns 500 / a PHP fatal.
    if ($action === 'sandbox_plugin_test') {
        @set_time_limit(0);
        $c_db = $sandbox_db();
        $plugin = trim($_POST['plugin'] ?? '');
        $sandbox_url = trim($_POST['sandbox_url'] ?? '');
        if ($plugin === '' || $sandbox_url === '') { echo json_encode(['ok' => false, 'error' => 'Missing plugin or sandbox URL']); exit; }
        $r = try_db_connect($c_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $dst = $sandbox_prefix($c_db['prefix']);
        // Guard: sandbox tables must exist (replicated first).
        $has = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($dst . 'options'))->fetchColumn();
        if (!$has) { echo json_encode(['ok' => false, 'error' => 'Sandbox tables not found — replicate first']); exit; }
        // Activate only this plugin in the sandbox options.
        $pdo->prepare("UPDATE `{$dst}options` SET option_value = ? WHERE option_name = 'active_plugins'")->execute([serialize([$plugin])]);
        // GET the sandbox home and classify.
        $status = 0; $body = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($sandbox_url . '/');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false]);
            $body = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); unset($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
            $body = (string)@file_get_contents($sandbox_url . '/', false, $ctx);
            $hdrs = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
            if (isset($hdrs[0]) && preg_match('#\s(\d{3})\s#', $hdrs[0], $m)) $status = (int)$m[1];
        }
        $fatal = (stripos($body, 'Fatal error') !== false) || (stripos($body, 'There has been a critical error') !== false);
        $breaks = ($status >= 500) || $fatal;
        echo json_encode(['ok' => true, 'plugin' => $plugin, 'status' => $status, 'breaks' => $breaks,
            'loads' => ($status >= 200 && $status < 400 && !$fatal)]);
        exit;
    }

    // --- SANDBOX MULTISITE: list sub-sites ---
    if ($action === 'sandbox_ms_list_sites') {
        $c_db = $sandbox_db();
        $r = try_db_connect($c_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $dst = $sandbox_prefix($c_db['prefix']);
        if (!$pdo->query("SHOW TABLES LIKE " . $pdo->quote($dst . 'blogs'))->fetchColumn()) {
            echo json_encode(['ok' => true, 'multisite' => false, 'sites' => []]); exit;
        }
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $sites = [];
        foreach ($pdo->query("SELECT blog_id, domain, path FROM `{$dst}blogs` ORDER BY blog_id")->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $sites[] = ['blog_id' => (int)$b['blog_id'], 'url' => $proto . '://' . $b['domain'] . $b['path']];
        }
        echo json_encode(['ok' => true, 'multisite' => true, 'sites' => $sites]);
        exit;
    }

    // --- SANDBOX MULTISITE: add a sub-site ---
    if ($action === 'sandbox_ms_add_site') {
        @set_time_limit(0);
        $c_db = $sandbox_db();
        $r = try_db_connect($c_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $dst = $sandbox_prefix($c_db['prefix']);
        if (!$pdo->query("SHOW TABLES LIKE " . $pdo->quote($dst . 'blogs'))->fetchColumn()) {
            echo json_encode(['ok' => false, 'error' => 'The sandbox is not a multisite network — rebuild it with multisite enabled']); exit;
        }
        // Primary site domain/path from blog 1.
        $base = $pdo->query("SELECT domain, path FROM `{$dst}blogs` WHERE blog_id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: ['domain'=>($_SERVER['HTTP_HOST']??'localhost'),'path'=>'/'];
        $subdomain = ($_POST['ms_type'] ?? 'subdir') === 'subdomain';
        $res = cms_ms_add_site($pdo, $dst, $base['domain'], $base['path'],
            $_POST['slug'] ?? '', trim($_POST['title'] ?? 'Sub site'), trim($_POST['email'] ?? 'admin@example.com'), $subdomain);
        echo json_encode($res);
        exit;
    }

    // --- SANDBOX: set active plugins to an arbitrary list (for all/bisection tests) ---
    if ($action === 'sandbox_plugins_set') {
        $c_db = $sandbox_db();
        $list = json_decode($_POST['plugins'] ?? '[]', true); $list = is_array($list) ? array_values(array_filter($list)) : [];
        $sandbox_url = trim($_POST['sandbox_url'] ?? '');
        if ($sandbox_url === '') { echo json_encode(['ok' => false, 'error' => 'Missing sandbox URL']); exit; }
        $r = try_db_connect($c_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $dst = $sandbox_prefix($c_db['prefix']);
        if (!$pdo->query("SHOW TABLES LIKE " . $pdo->quote($dst . 'options'))->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'Sandbox tables not found — replicate first']); exit; }
        $pdo->prepare("UPDATE `{$dst}options` SET option_value = ? WHERE option_name = 'active_plugins'")->execute([serialize($list)]);
        // GET the sandbox home and classify.
        $status = 0; $body = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($sandbox_url . '/');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => false]);
            $body = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); unset($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 25, 'ignore_errors' => true]]);
            $body = (string)@file_get_contents($sandbox_url . '/', false, $ctx);
            $hdrs = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
            if (isset($hdrs[0]) && preg_match('#\s(\d{3})\s#', $hdrs[0], $m)) $status = (int)$m[1];
        }
        $fatal = (stripos($body, 'Fatal error') !== false) || (stripos($body, 'There has been a critical error') !== false);
        echo json_encode(['ok' => true, 'count' => count($list), 'status' => $status,
            'breaks' => ($status >= 500 || $fatal), 'loads' => ($status >= 200 && $status < 400 && !$fatal)]);
        exit;
    }

    // --- ADVANCED .htaccess / APACHE CAPABILITY TESTS (isolated subfolders) ---
    // Writes each directive block into its own throwaway subfolder with a probe and
    // requests it over HTTP, reporting which Apache features the host supports
    // (never touching the live .htaccess). 500 = not supported; 2xx/expected = ok.
    if ($action === 'htaccess_tests') {
        @set_time_limit(120);
        $root = rtrim($abspath, '/');
        if (!is_writable($root)) { echo json_encode(['ok' => false, 'error' => 'Web root not writable for test folders']); exit; }
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base  = rtrim(dirname($proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/')), '/');

        // Each test: name, the .htaccess body, the probe request path, and a checker.
        $tests = [
            ['mod_rewrite (pretty URLs)', "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /%DIR%/\nRewriteRule ^go/(.*)$ ok.php?x=$1 [L]\n</IfModule>\n", 'go/hello', fn($s,$b)=>strpos($b,'OK:hello')!==false],
            ['301 redirect (Redirect)', "Redirect 301 /%DIR%/old.html /%DIR%/ok.php\n", 'old.html', fn($s,$b)=>$s>=200&&$s<400&&strpos($b,'OK')!==false],
            ['HTTP Basic auth', "AuthType Basic\nAuthName \"x\"\n<RequireAll>\nRequire valid-user\n</RequireAll>\n", 'ok.php', fn($s,$b)=>$s===401],
            ['gzip/deflate (mod_deflate)', "<IfModule mod_deflate.c>\nAddOutputFilterByType DEFLATE text/plain text/html\n</IfModule>\n", 'ok.php', fn($s,$b,$h)=>$s>=200&&$s<400],
            ['Expires/Cache headers (mod_expires)', "<IfModule mod_expires.c>\nExpiresActive On\nExpiresByType text/plain \"access plus 1 day\"\n</IfModule>\n", 'ok.php', fn($s,$b)=>$s>=200&&$s<400],
            ['Custom headers (mod_headers)', "<IfModule mod_headers.c>\nHeader set X-WP-Migrate-Test \"1\"\n</IfModule>\n", 'ok.php', fn($s,$b,$h)=>stripos($h,'X-WP-Migrate-Test')!==false||($s>=200&&$s<400)],
            ['Directory listing off (Options)', "Options -Indexes\n", 'ok.php', fn($s,$b)=>$s>=200&&$s<400],
        ];
        $get = function ($url) {
            $status=0;$body='';$hdr='';
            if (function_exists('curl_init')) {
                $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false]);
                $resp=(string)curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $hs=(int)curl_getinfo($ch,CURLINFO_HEADER_SIZE); unset($ch);
                $hdr=substr($resp,0,$hs); $body=substr($resp,$hs);
            }
            return [$status,$body,$hdr];
        };
        $results = [];
        foreach ($tests as [$name,$body_rules,$probe,$check]) {
            $dir = $root . '/_ht_test_' . bin2hex(random_bytes(3));
            if (!@mkdir($dir, 0755)) { $results[]=['name'=>$name,'status'=>0,'supported'=>false,'note'=>'cannot create test dir']; continue; }
            $rules = str_replace('%DIR%', basename($dir), $body_rules);
            file_put_contents($dir.'/.htaccess', $rules);
            file_put_contents($dir.'/ok.php', "<?php echo 'OK:'.(\$_GET['x']??'');");
            [$st,$bd,$hd] = $get($base.'/'.basename($dir).'/'.$probe);
            $breaks = ($st>=500);
            $supported = !$breaks && (bool)$check($st,$bd,$hd);
            $results[] = ['name'=>$name,'status'=>$st,'supported'=>$supported,'breaks'=>$breaks];
            foreach (glob($dir.'/{,.}*',GLOB_BRACE)?:[] as $f){ if(is_file($f))@unlink($f); } @rmdir($dir);
        }
        echo json_encode(['ok'=>true,'results'=>$results]);
        exit;
    }

    // --- ORPHAN ASSETS: scan (files on disk NOT referenced in the DB) ---
    if ($action === 'orphans_scan') {
        @set_time_limit(0);
        $o_db = $sandbox_db();
        $r = try_db_connect($o_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $root = rtrim($abspath, '/'); $uploads = $root . '/wp-content/uploads';
        if (!is_dir($uploads)) { echo json_encode(['ok' => false, 'error' => 'uploads not found']); exit; }
        [$used, $all, , $totalBytes] = $clean_collect($r['pdo'], $o_db['prefix'], $root);
        $orphans = []; $orphanBytes = 0;
        foreach ($all as $rel => $size) { if (!isset($used[$rel])) { $orphans[$rel] = $size; $orphanBytes += $size; } }
        $preview = array_slice(array_keys($orphans), 0, 20);
        echo json_encode(['ok'=>true,'total'=>count($all),'orphans'=>count($orphans),'orphan_size'=>human_size($orphanBytes),
            'preview'=>$preview,'files'=>array_keys($orphans)]);
        exit;
    }

    // --- ORPHAN ASSETS: quarantine (move to a pre-delete folder + restore log) ---
    if ($action === 'orphans_quarantine') {
        @set_time_limit(0);
        $root = rtrim($abspath, '/'); $uploads = $root . '/wp-content/uploads';
        $batch = json_decode($_POST['files'] ?? '', true); $batch = is_array($batch) ? array_slice($batch, 0, 500) : [];
        if (!$batch) { echo json_encode(['ok' => false, 'error' => 'No files']); exit; }
        $qdir = $root . '/wp-content/_orphans-remove-it';
        @mkdir($qdir, 0755, true);
        $moved = 0; $log = [];
        foreach ($batch as $rel) {
            $rel = ltrim(str_replace('\\','/',preg_replace('/[?#].*$/','',(string)$rel)),'/');
            if ($rel === '' || strpos($rel,'..') !== false) continue;
            $src = $uploads . '/' . $rel; $dst = $qdir . '/' . $rel;
            if (!is_file($src)) continue;
            @mkdir(dirname($dst), 0755, true);
            if (@rename($src, $dst)) { $moved++; $log[] = ['from' => 'wp-content/uploads/'.$rel, 'to' => 'wp-content/_orphans-remove-it/'.$rel]; }
        }
        if ($log) { @file_put_contents($root.'/wp-content/.orphans-'.date('YmdHis').'.json', json_encode($log, JSON_PRETTY_PRINT)); }
        echo json_encode(['ok'=>true,'moved'=>$moved,'folder'=>'wp-content/_orphans-remove-it']);
        exit;
    }

    // --- JUNK FILES: scan large/suspicious files (logs, backups, archives) ---
    if ($action === 'junk_scan') {
        @set_time_limit(0);
        $root = rtrim($abspath, '/');
        $min = max(1, (int)($_POST['min_mb'] ?? 10)) * 1024 * 1024;
        $junk = []; $totalBytes = 0;
        // Suspicious by extension/name anywhere, plus any file over the size threshold.
        $susp_rx = '/\.(log|bak|old|sql|sql\.gz|tar|tar\.gz|tgz|zip|tmp|swp|~)$|error_log$|debug\.log$/i';
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $rel = str_replace('\\','/', ltrim(str_replace($root,'',$f->getPathname()),'/\\'));
                if (strpos($rel, '.migrate_') === 0 || strpos($rel, '_orphans-remove-it') !== false || strpos($rel, 'uploads_old-remove-it') !== false) continue;
                $size = $f->getSize();
                $big = $size >= $min;
                $sus = (bool)preg_match($susp_rx, $rel);
                if ($big || $sus) {
                    $junk[] = ['path' => $rel, 'size' => human_size($size), 'bytes' => $size, 'reason' => $sus ? ($big?'suspicious+large':'suspicious') : 'large'];
                    $totalBytes += $size;
                }
                if (count($junk) >= 2000) break;
            }
        } catch (Exception $e) {}
        usort($junk, fn($a,$b)=>$b['bytes']<=>$a['bytes']);
        echo json_encode(['ok'=>true,'count'=>count($junk),'total_size'=>human_size($totalBytes),'files'=>array_slice($junk,0,500)]);
        exit;
    }

    // --- JUNK FILES: quarantine (move to pre-delete + restore log) ---
    if ($action === 'junk_quarantine') {
        $root = rtrim($abspath, '/');
        $batch = json_decode($_POST['files'] ?? '', true); $batch = is_array($batch) ? array_slice($batch, 0, 500) : [];
        if (!$batch) { echo json_encode(['ok' => false, 'error' => 'No files']); exit; }
        $qdir = $root . '/_junk-remove-it'; @mkdir($qdir, 0755, true);
        $moved = 0; $log = [];
        foreach ($batch as $rel) {
            $rel = ltrim(str_replace('\\','/',(string)$rel),'/');
            if ($rel === '' || strpos($rel,'..') !== false) continue;
            $src = $root . '/' . $rel; $dst = $qdir . '/' . $rel;
            if (!is_file($src)) continue;
            @mkdir(dirname($dst), 0755, true);
            if (@rename($src, $dst)) { $moved++; $log[] = ['from' => $rel, 'to' => '_junk-remove-it/'.$rel]; }
        }
        if ($log) { @file_put_contents($root.'/.junk-'.date('YmdHis').'.json', json_encode($log, JSON_PRETTY_PRINT)); }
        echo json_encode(['ok'=>true,'moved'=>$moved,'folder'=>'_junk-remove-it']);
        exit;
    }

    // --- PERFORMANCE: enable/disable/test .htaccess optimizations ---
    // Writes a managed block with gzip (mod_deflate), browser cache (mod_expires),
    // and a few headers. Tested in an isolated subfolder first (anti-500).
    if ($action === 'perf_status' || $action === 'perf_enable' || $action === 'perf_disable') {
        $root = rtrim($abspath, '/'); $htaccess = $root . '/.htaccess';
        $ms = '# BEGIN WP-Migrate Performance'; $me = '# END WP-Migrate Performance';
        $active = is_file($htaccess) && strpos(file_get_contents($htaccess), $ms) !== false;
        if ($action === 'perf_status') { echo json_encode(['ok'=>true,'active'=>$active]); exit; }
        if ($action === 'perf_disable') {
            if (is_file($htaccess)) { $c = trim(preg_replace('/'.preg_quote($ms,'/').'.*?'.preg_quote($me,'/').'/s','',file_get_contents($htaccess))); @file_put_contents($htaccess, $c.($c?"\n":'')); }
            echo json_encode(['ok'=>true,'active'=>false]); exit;
        }
        // enable: build the block from the selected features.
        $opts = is_array($_POST['opts'] ?? null) ? $_POST['opts'] : explode(',', (string)($_POST['opts'] ?? 'gzip,cache,headers'));
        $block = "$ms\n";
        if (in_array('gzip', $opts, true)) {
            $block .= "<IfModule mod_deflate.c>\nAddOutputFilterByType DEFLATE text/plain text/html text/xml text/css application/javascript application/json application/xml image/svg+xml\n</IfModule>\n";
        }
        if (in_array('cache', $opts, true)) {
            $block .= "<IfModule mod_expires.c>\nExpiresActive On\nExpiresByType image/jpeg \"access plus 1 year\"\nExpiresByType image/png \"access plus 1 year\"\nExpiresByType image/gif \"access plus 1 year\"\nExpiresByType image/webp \"access plus 1 year\"\nExpiresByType image/svg+xml \"access plus 1 year\"\nExpiresByType text/css \"access plus 1 month\"\nExpiresByType application/javascript \"access plus 1 month\"\nExpiresByType application/font-woff2 \"access plus 1 year\"\nExpiresDefault \"access plus 2 days\"\n</IfModule>\n";
        }
        if (in_array('headers', $opts, true)) {
            $block .= "<IfModule mod_headers.c>\nHeader unset ETag\n<FilesMatch \"\\.(ico|jpe?g|png|gif|webp|svg|css|js|woff2?)$\">\nHeader set Cache-Control \"public, max-age=2592000\"\n</FilesMatch>\n</IfModule>\nFileETag None\n";
        }
        $block .= $me;

        // Isolated anti-500 test (the directives are guarded by IfModule so a missing
        // module is harmless; we still confirm the host doesn't 500 on them).
        $tested = 'skipped';
        if (is_writable($root)) {
            $dir = $root . '/_perf_test_' . bin2hex(random_bytes(3));
            if (@mkdir($dir, 0755)) {
                file_put_contents($dir.'/.htaccess', str_replace([$ms,$me],'',$block));
                file_put_contents($dir.'/ok.php', "<?php echo 'OK';");
                $proto=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
                $base=rtrim(dirname($proto.'://'.($_SERVER['HTTP_HOST']??'localhost').($_SERVER['REQUEST_URI']??'/')),'/');
                $st=0; if(function_exists('curl_init')){$ch=curl_init($base.'/'.basename($dir).'/ok.php');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false]);curl_exec($ch);$st=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);unset($ch);}
                @unlink($dir.'/.htaccess');@unlink($dir.'/ok.php');@rmdir($dir);
                if ($st>=500) { echo json_encode(['ok'=>false,'error'=>'The host returned HTTP 500 for these directives — not applied.']); exit; }
                $tested = ($st>=200&&$st<400)?'passed':'inconclusive';
            }
        }
        if (!is_writable($root) && !(is_file($htaccess)&&is_writable($htaccess))) { echo json_encode(['ok'=>false,'error'=>'.htaccess not writable']); exit; }
        if (is_file($htaccess)) @copy($htaccess, $htaccess.'.bak-'.date('YmdHis'));
        $existing = is_file($htaccess) ? file_get_contents($htaccess) : '';
        $existing = trim(preg_replace('/'.preg_quote($ms,'/').'.*?'.preg_quote($me,'/').'/s','',$existing));
        $ok = @file_put_contents($htaccess, $block."\n\n".$existing."\n") !== false;
        echo json_encode(['ok'=>$ok,'active'=>$ok,'tested'=>$tested,'error'=>$ok?'':'Write failed']);
        exit;
    }

    // --- PERFORMANCE: generate a static full-page cache ---
    // Fetches each published page/post over HTTP and stores the HTML under
    // wp-content/cache/wpmigrate-static/<path>/index.html. A companion .htaccess
    // rule (added by perf_enable if requested) can serve it for anonymous visitors.
    if ($action === 'perf_static_cache') {
        @set_time_limit(0);
        $sc_db = $sandbox_db();
        $r = try_db_connect($sc_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $sc_db['prefix'];
        $home = rtrim((string)$pdo->query("SELECT option_value FROM `{$prefix}options` WHERE option_name='home' LIMIT 1")->fetchColumn(), '/');
        if ($home === '') { echo json_encode(['ok'=>false,'error'=>'home URL not found']); exit; }
        $root = rtrim($abspath, '/'); $cacheDir = $root . '/wp-content/cache/wpmigrate-static';
        @mkdir($cacheDir, 0755, true);
        if (!is_dir($cacheDir) || !is_writable($cacheDir)) { echo json_encode(['ok'=>false,'error'=>'cache dir not writable']); exit; }
        // URLs: home + published pages/posts (limit for safety).
        $rows = $pdo->query("SELECT post_name, post_type FROM `{$prefix}posts` WHERE post_status='publish' AND post_type IN ('post','page') AND post_name <> '' LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        $urls = ['' => $home . '/'];
        foreach ($rows as $p) $urls[$p['post_name']] = $home . '/' . rawurlencode($p['post_name']) . '/';
        $saved = 0; $failed = 0;
        foreach ($urls as $slug => $url) {
            $body = false; $code = 0;
            if (function_exists('curl_init')) { $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>false]);$body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);unset($ch); }
            if ($code === 200 && $body && stripos($body, '</html>') !== false) {
                $dir = $cacheDir . ($slug === '' ? '' : '/' . preg_replace('/[^a-z0-9\-_]/i','_',$slug));
                @mkdir($dir, 0755, true);
                if (@file_put_contents($dir . '/index.html', $body) !== false) $saved++; else $failed++;
            } else { $failed++; }
        }
        echo json_encode(['ok'=>true,'saved'=>$saved,'failed'=>$failed,'dir'=>'wp-content/cache/wpmigrate-static',
            'note'=>'Static HTML generated. Serving it to anonymous visitors requires a rewrite rule / caching plugin; this is a snapshot cache.']);
        exit;
    }

    // --- PERFORMANCE: quick internal check (TTFB, size, asset count) ---
    if ($action === 'perf_check') {
        @set_time_limit(60);
        $url = current_url() . '/';
        $ttfb = null; $total = null; $size = 0; $body = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>false]);
            $body = (string)curl_exec($ch);
            $ttfb = curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
            $total = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $size = strlen($body);
            unset($ch);
        } else { echo json_encode(['ok'=>false,'error'=>'curl required for the internal check']); exit; }
        // Count referenced assets in the HTML.
        $assets = 0;
        if (preg_match_all('#<(?:script|link|img|source)\b[^>]*\b(?:src|href)=["\'][^"\']+["\']#i', $body, $m)) $assets = count($m[0]);
        echo json_encode(['ok'=>true,'url'=>$url,
            'ttfb_ms'=>$ttfb!==null?round($ttfb*1000):null,'total_ms'=>$total!==null?round($total*1000):null,
            'size'=>human_size($size),'assets'=>$assets]);
        exit;
    }

    // --- PLUGIN SEARCH (wordpress.org API) ---
    // Lets the user find a plugin to vet in the sandbox before installing it live.
    if ($action === 'plugin_search' || $action === 'plugin_popular') {
        // Search by term, or browse the most popular plugins (empty/absent query).
        if ($action === 'plugin_popular') {
            $api = 'https://api.wordpress.org/plugins/info/1.2/?action=query_plugins&request[browse]=popular&request[per_page]=12';
        } else {
            $q = trim($_POST['q'] ?? '');
            if ($q === '') { echo json_encode(['ok' => false, 'error' => 'Empty query']); exit; }
            $api = 'https://api.wordpress.org/plugins/info/1.2/?action=query_plugins&request[search]=' . urlencode($q) . '&request[per_page]=12';
        }
        $body = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($api);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'WP-Migration-Tool']);
            $body = curl_exec($ch); unset($ch);
        } elseif (ini_get('allow_url_fopen')) {
            $body = @file_get_contents($api, false, stream_context_create(['http' => ['timeout' => 20]]));
        }
        $data = $body ? json_decode($body, true) : null;
        if (!is_array($data) || !isset($data['plugins'])) { echo json_encode(['ok' => false, 'error' => 'wordpress.org API unavailable']); exit; }
        $server_php = phpversion();
        $out = [];
        foreach ($data['plugins'] as $p) {
            $req = $p['requires_php'] ?? '';
            $out[] = [
                'slug' => $p['slug'] ?? '', 'name' => strip_tags($p['name'] ?? ''),
                'version' => $p['version'] ?? '', 'requires_php' => $req,
                'active_installs' => $p['active_installs'] ?? 0, 'rating' => $p['rating'] ?? 0,
                'php_ok' => ($req === '' || version_compare($server_php, $req, '>=')),
                'download' => $p['download_link'] ?? '',
            ];
        }
        echo json_encode(['ok' => true, 'server_php' => $server_php, 'plugins' => $out]);
        exit;
    }

    // --- PLUGIN INSTALL (download a wordpress.org plugin into sandbox or live) ---
    // target=sandbox installs into the newest test_env_* /wp-content/plugins (its
    // symlink points to the live plugins dir, so "sandbox" and "live" may share it
    // unless copied); target=live installs into the real wp-content/plugins.
    if ($action === 'plugin_install') {
        @set_time_limit(0);
        $slug   = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['slug'] ?? ''));
        $dl     = trim($_POST['download'] ?? '');
        $target = ($_POST['target'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
        if ($slug === '') { echo json_encode(['ok' => false, 'error' => 'Invalid plugin slug']); exit; }
        if (!preg_match('#^https?://downloads\.wordpress\.org/plugin/#i', $dl)) {
            // Rebuild the canonical download URL from the slug if not provided/trusted.
            $dl = 'https://downloads.wordpress.org/plugin/' . $slug . '.latest-stable.zip';
        }
        $root = rtrim($abspath, '/');
        if ($target === 'sandbox') {
            $envs = glob($root . '/test_env_*', GLOB_ONLYDIR);
            if (!$envs) { echo json_encode(['ok' => false, 'error' => 'No sandbox found — build the Test Environment first']); exit; }
            $plugins_dir = $envs[0] . '/wp-content/plugins';
            // If plugins is a symlink to live, resolve to the real dir.
            if (is_link($plugins_dir)) $plugins_dir = readlink($plugins_dir);
        } else {
            $plugins_dir = $root . '/wp-content/plugins';
        }
        if (!is_dir($plugins_dir) || !is_writable($plugins_dir)) { echo json_encode(['ok' => false, 'error' => 'plugins directory not writable']); exit; }

        // Download the zip.
        $tmp = sys_get_temp_dir() . '/plugin_' . $slug . '_' . time() . '.zip';
        $okdl = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($dl); $fp = fopen($tmp, 'wb');
            curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => false]);
            curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); unset($ch); fclose($fp);
            $okdl = ($code === 200 && is_file($tmp) && filesize($tmp) > 100);
        } elseif (ini_get('allow_url_fopen')) {
            $data = @file_get_contents($dl, false, stream_context_create(['http' => ['timeout' => 120, 'follow_location' => 1]]));
            if ($data) { file_put_contents($tmp, $data); $okdl = filesize($tmp) > 100; }
        }
        if (!$okdl) { @unlink($tmp); echo json_encode(['ok' => false, 'error' => 'Could not download plugin from wordpress.org']); exit; }

        // Extract into the plugins dir.
        $extracted = false;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tmp) === true) { $zip->extractTo($plugins_dir); $zip->close(); $extracted = true; }
        }
        @unlink($tmp);
        if (!$extracted) { echo json_encode(['ok' => false, 'error' => 'Could not extract the plugin zip']); exit; }
        // Find the main plugin file to activate later.
        $main = '';
        foreach (glob($plugins_dir . '/' . $slug . '/*.php') ?: [] as $php) {
            if (strpos(@file_get_contents($php, false, null, 0, 4096) ?: '', 'Plugin Name:') !== false) { $main = $slug . '/' . basename($php); break; }
        }
        echo json_encode(['ok' => true, 'slug' => $slug, 'target' => $target, 'main' => $main,
            'note' => $target === 'sandbox' ? 'Installed into the sandbox — smoke-test it before installing live.' : 'Installed into the live site.']);
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

    // --- EXPORT A PLUGIN AS A READY-TO-INSTALL .zip ---
    // Packages a plugin's folder (or single .php file) from wp-content/plugins into
    // a zip with the plugin dir at its root, downloadable via the token endpoint.
    if ($action === 'plugin_export') {
        @set_time_limit(0);
        $root = rtrim($abspath, '/');
        $plugins_dir = $root . '/wp-content/plugins';
        $file = ltrim(str_replace('\\', '/', (string)($_POST['plugin'] ?? '')), '/');
        if ($file === '' || strpos($file, '..') !== false) { echo json_encode(['ok' => false, 'error' => 'Invalid plugin']); exit; }
        // The plugin lives at plugins/<slug>/... (dir) or plugins/<file>.php (single).
        $slug = (strpos($file, '/') !== false) ? substr($file, 0, strpos($file, '/')) : preg_replace('/\.php$/i', '', $file);
        $src  = $plugins_dir . '/' . $slug;
        $single = false;
        if (!is_dir($src)) { $src = $plugins_dir . '/' . $file; $single = true; }
        if (!file_exists($src) || strpos(realpath($src) ?: '', realpath($plugins_dir) ?: '~') !== 0) {
            echo json_encode(['ok' => false, 'error' => 'Plugin not found']); exit;
        }
        if (!class_exists('ZipArchive')) { echo json_encode(['ok' => false, 'error' => 'ZipArchive not available']); exit; }
        $dl_dir = __DIR__ . '/.migrate_exports';
        if (!is_dir($dl_dir)) @mkdir($dl_dir, 0750, true);
        $token = bin2hex(random_bytes(6));
        $safe  = preg_replace('/[^a-z0-9._-]/i', '-', $single ? basename($file, '.php') : $slug);
        $zip_name = 'plugin_' . $safe . '_' . $token . '.zip';
        $zip_path = $dl_dir . '/' . $zip_name;
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { echo json_encode(['ok' => false, 'error' => 'Could not create zip']); exit; }
        if ($single) {
            $zip->addFile($src, basename($src));
        } else {
            // Add the folder with <slug>/ as the zip root so it installs cleanly.
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $f) {
                $rel = $slug . '/' . $it->getSubPathName();
                if ($f->isDir()) $zip->addEmptyDir($rel); else $zip->addFile($f->getRealPath(), $rel);
            }
        }
        $zip->close();
        echo json_encode(['ok' => true, 'name' => $zip_name, 'size' => human_size(filesize($zip_path)),
            'url' => basename(__FILE__) . '?action=download&token=' . $token . '&file=' . urlencode($zip_name)]);
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

    // --- ANTI-HOTLINKING -----------------------------------------------------
    // Hotlinking = other sites embedding this site's images/assets directly, so
    // their visitors consume this server's bandwidth. These endpoints detect
    // whether a referer-based protection is present, test the proposed .htaccess
    // rules in an isolated subfolder (must not 500), and apply them with a backup.
    if (strpos($action, 'hotlink_') === 0) {
        $root = rtrim($abspath, '/');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $hl_begin = '# BEGIN WP-Migrate Anti-Hotlink';
        $hl_end   = '# END WP-Migrate Anti-Hotlink';
        // The protection block: allow empty referer (direct hits, bookmarks) and the
        // site's own host; block everything else for static asset types.
        $build_rules = function ($host) use ($hl_begin, $hl_end) {
            $h = preg_quote($host, '#');
            return "$hl_begin\n"
                . "<IfModule mod_rewrite.c>\n"
                . "RewriteEngine On\n"
                . "RewriteCond %{HTTP_REFERER} !^$ [NC]\n"
                . "RewriteCond %{HTTP_REFERER} !^https?://(www\\.)?$h [NC]\n"
                . "RewriteRule \\.(jpe?g|png|gif|webp|svg|bmp|ico|css|js)$ - [NC,F,L]\n"
                . "</IfModule>\n"
                . "$hl_end";
        };

        if ($action === 'hotlink_check') {
            $htaccess = $root . '/.htaccess';
            $content  = is_file($htaccess) ? (string)file_get_contents($htaccess) : '';
            $protected = strpos($content, $hl_begin) !== false
                || preg_match('/RewriteCond\s+%\{HTTP_REFERER\}/i', $content);
            echo json_encode(['ok' => true, 'protected' => (bool)$protected,
                'has_htaccess' => is_file($htaccess),
                'text' => $protected
                    ? 'A referer-based protection is already present in .htaccess'
                    : 'No anti-hotlink protection found — external sites can embed this site\'s assets and use its bandwidth']);
            exit;
        }

        if ($action === 'hotlink_test') {
            if (!is_writable($root)) { echo json_encode(['ok' => false, 'error' => 'Web root is not writable; cannot create a test folder.']); exit; }
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base  = rtrim(dirname($proto . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/')), '/');
            $dir   = $root . '/_hotlink_test_' . bin2hex(random_bytes(4));
            if (!@mkdir($dir, 0755)) { echo json_encode(['ok' => false, 'error' => 'Could not create test folder']); exit; }
            $cleanup = function () use ($dir) {
                foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
                foreach (glob($dir . '/.*') ?: [] as $f) { if (!in_array(basename($f), ['.', '..'], true)) @unlink($f); }
                @rmdir($dir);
            };
            // Write the rules + a tiny asset to request with/without a referer.
            file_put_contents($dir . '/.htaccess', $build_rules($host));
            file_put_contents($dir . '/t.css', "body{}");
            $probe = function ($referer) use ($base, $dir) {
                $url = $base . '/' . basename($dir) . '/t.css';
                $st = 0;
                if (function_exists('curl_init')) {
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
                        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false]);
                    if ($referer !== null) curl_setopt($ch, CURLOPT_REFERER, $referer);
                    curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); unset($ch);
                }
                return $st;
            };
            $direct   = $probe(null);                           // no referer → should load (200)
            $external = $probe('http://evil-example.com/page'); // foreign referer → should be blocked (403)
            $cleanup();
            $breaks  = ($direct >= 500 || $external >= 500);
            // The protection works when mod_rewrite is active: direct loads, external forbidden.
            $enforced = ($direct >= 200 && $direct < 400 && $external === 403);
            echo json_encode(['ok' => true, 'breaks' => $breaks, 'direct' => $direct, 'external' => $external,
                'enforced' => $enforced,
                'note' => $breaks ? 'The rules would break the site (HTTP 500) — not safe to apply'
                    : ($enforced ? 'Rules verified: direct requests load, external referers are blocked'
                        : 'No 500, but enforcement could not be confirmed (mod_rewrite may be off on this host / dev server). Apply is allowed since it does not break the site.')]);
            exit;
        }

        if ($action === 'hotlink_apply') {
            $htaccess = $root . '/.htaccess';
            $writable = (is_file($htaccess) && is_writable($htaccess)) || (!is_file($htaccess) && is_writable($root));
            if (!$writable) { echo json_encode(['ok' => false, 'error' => '.htaccess is not writable']); exit; }
            $backup = null;
            if (is_file($htaccess)) {
                $backup = $htaccess . '.bak-' . date('Ymd-His');
                if (!@copy($htaccess, $backup)) { echo json_encode(['ok' => false, 'error' => 'Could not back up .htaccess']); exit; }
            }
            $content = is_file($htaccess) ? (string)file_get_contents($htaccess) : '';
            // Replace any previous block, then prepend the fresh one.
            $content = trim(preg_replace('/' . preg_quote($hl_begin, '/') . '.*?' . preg_quote($hl_end, '/') . '/s', '', $content));
            $content = $build_rules($host) . ($content ? "\n\n" . $content : "\n");
            if (@file_put_contents($htaccess, $content) === false) {
                echo json_encode(['ok' => false, 'error' => 'Could not write .htaccess' . ($backup ? ' (backup kept: ' . basename($backup) . ')' : '')]); exit;
            }
            echo json_encode(['ok' => true, 'backup' => $backup ? basename($backup) : null,
                'note' => 'Anti-hotlink rules applied. Revert by restoring the backup or removing the marked block.']);
            exit;
        }

        echo json_encode(['ok' => false, 'error' => 'Unknown hotlink action']);
        exit;
    }

    // --- WORDPRESS NEWS ------------------------------------------------------
    // Fetches the official WordPress.org news RSS feed, returns the latest items,
    // and flags any that look security/critical so the UI can banner them.
    if ($action === 'wp_news') {
        $body = http_fetch_body('https://wordpress.org/news/feed/', ['User-Agent: cms_tools/' . TOOL_VERSION]);
        if ($body === null) { echo json_encode(['ok' => false, 'error' => 'Could not reach the WordPress news feed']); exit; }
        $items = [];
        $prev = libxml_use_internal_errors(true);
        $xml  = simplexml_load_string($body);
        libxml_use_internal_errors($prev);
        if ($xml === false || !isset($xml->channel->item)) { echo json_encode(['ok' => false, 'error' => 'Could not parse the news feed']); exit; }
        // Critical = the title/summary mentions a security or urgent release.
        $crit_re = '/\b(security|vulnerab|critical|exploit|CVE|urgent|hotfix|security release|patch release)\b/i';
        foreach ($xml->channel->item as $it) {
            $title = trim((string)$it->title);
            $link  = trim((string)$it->link);
            $date  = trim((string)$it->pubDate);
            $desc  = trim(strip_tags((string)$it->description));
            if (mb_strlen($desc) > 220) $desc = mb_substr($desc, 0, 220) . '…';
            $critical = (bool)preg_match($crit_re, $title . ' ' . $desc);
            $items[] = compact('title', 'link', 'date', 'desc', 'critical');
            if (count($items) >= 12) break;
        }
        $critical_items = array_values(array_filter($items, fn($i) => $i['critical']));
        echo json_encode(['ok' => true, 'items' => $items, 'critical' => $critical_items,
            'has_critical' => count($critical_items) > 0]);
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

    // --- SEO: FRIENDLY PERMALINKS (test + apply) ---
    // Friendly permalinks need WordPress rewrite rules in .htaccess. A host that
    // disallows mod_rewrite / AllowOverride would 500 the whole site, so with
    // mode=test we write the exact rewrite block into an isolated subfolder and
    // request it over HTTP first (expecting not-500). mode=apply only writes the
    // real .htaccess (backing up the old one) and sets the chosen structure.
    if ($action === 'seo_set_permalinks') {
        $s_db = $seo_db();
        $root = rtrim($abspath, '/');
        $mode = in_array($_POST['mode'] ?? '', ['test', 'apply'], true) ? $_POST['mode'] : 'apply';
        // The valid WordPress permalink structures (plus the editable custom one).
        $valid = ['/%postname%/', '/%year%/%monthnum%/%day%/%postname%/', '/%year%/%monthnum%/%postname%/',
                  '/archives/%post_id%', '/%post_id%', '/?p=%post_id%'];
        $structure = $_POST['structure'] ?? '/%postname%/';
        if (strpos($structure, '?p=') === false && !in_array($structure, $valid, true) && !preg_match('#^/[%a-z0-9_\-/]*$#i', $structure)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid permalink structure']); exit;
        }

        // The standard WP rewrite block (same for every pretty structure).
        $rules = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n";

        if ($mode === 'test') {
            if (!is_writable($root)) { echo json_encode(['ok' => false, 'error' => 'Web root is not writable; cannot create a test folder.']); exit; }
            $dir = $root . '/_perma_test_' . bin2hex(random_bytes(4));
            if (!@mkdir($dir, 0755)) { echo json_encode(['ok' => false, 'error' => 'Could not create test folder']); exit; }
            // A test rewrite that routes everything to ok.php so we can confirm the
            // directives load (200) rather than 500 from a disallowed override.
            $test_rules = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /" . basename($dir) . "/\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule . ok.php [L]\n</IfModule>\n";
            file_put_contents($dir . '/.htaccess', $test_rules);
            file_put_contents($dir . '/ok.php', "<?php echo 'PERMALINK-REWRITE-OK';");
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base  = rtrim(dirname($proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/')), '/');
            $probe = $base . '/' . basename($dir) . '/anything-here';
            $status = 0; $body = '';
            if (function_exists('curl_init')) {
                $ch = curl_init($probe);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true]);
                $body = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); unset($ch);
            } else {
                $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
                $body = (string)@file_get_contents($probe, false, $ctx);
                $hdrs = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
                if (isset($hdrs[0]) && preg_match('#\s(\d{3})\s#', $hdrs[0], $m)) $status = (int)$m[1];
            }
            // Cleanup.
            @unlink($dir . '/.htaccess'); @unlink($dir . '/ok.php'); @rmdir($dir);
            $breaks  = ($status >= 500);
            $works   = (strpos($body, 'PERMALINK-REWRITE-OK') !== false) || ($status >= 200 && $status < 400);
            echo json_encode(['ok' => true, 'mode' => 'test', 'status' => $status, 'breaks' => $breaks,
                'supported' => ($works && !$breaks), 'probe_url' => $base . '/' . basename($dir) . '/',
                'note' => $breaks ? 'HTTP 500 — mod_rewrite/AllowOverride is NOT allowed here; pretty permalinks would break the site.'
                         : ($works ? 'Rewrite works — safe to apply pretty permalinks.'
                         : "HTTP $status — inconclusive (dev server does not process .htaccess; test on the real host).")]);
            exit;
        }

        // mode=apply
        $r = try_db_connect($s_db);
        if (!$r['ok']) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        $pdo = $r['pdo']; $prefix = $s_db['prefix'];
        $st = $pdo->prepare("UPDATE `{$prefix}options` SET option_value = ? WHERE option_name = 'permalink_structure'");
        $st->execute([$structure]);
        if ($st->rowCount() === 0) {
            $pdo->prepare("INSERT INTO `{$prefix}options` (option_name, option_value, autoload) VALUES ('permalink_structure', ?, 'yes')")->execute([$structure]);
        }
        $pdo->prepare("DELETE FROM `{$prefix}options` WHERE option_name = 'rewrite_rules'")->execute();

        $htaccess = $root . '/.htaccess';
        $wrote_htaccess = false;
        // Plain structures (?p=) don't need rewrite rules.
        $needs_rewrite = strpos($structure, '?p=') === false && $structure !== '';
        if ($needs_rewrite && (is_writable($root) || (is_file($htaccess) && is_writable($htaccess)))) {
            if (is_file($htaccess)) @copy($htaccess, $htaccess . '.bak-' . date('YmdHis'));
            $existing = is_file($htaccess) ? file_get_contents($htaccess) : '';
            $existing = trim(preg_replace('/# BEGIN WordPress.*?# END WordPress/s', '', $existing));
            @file_put_contents($htaccess, $rules . ($existing ? "\n" . $existing . "\n" : ''));
            $wrote_htaccess = true;
        }
        echo json_encode(['ok' => true, 'mode' => 'apply', 'structure' => $structure, 'htaccess' => $wrote_htaccess,
            'note' => !$needs_rewrite ? 'Plain permalinks set (no .htaccess needed).'
                     : ($wrote_htaccess ? 'Permalinks set and .htaccess updated.' : 'Permalinks set, but .htaccess is not writable — add the WordPress rewrite rules manually.')]);
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

    // --- SITE PASSWORD: isolated test (.htaccess + .htpasswd) ---
    // Writes a throwaway subfolder protected with HTTP Basic auth and requests it
    // over HTTP. The server should answer 401 (auth required), NOT 500 (the host
    // does not allow AuthType in .htaccess). Returns a probe URL the user can open
    // in the browser to confirm the login prompt appears before applying site-wide.
    if ($action === 'site_pass_test') {
        $root = rtrim($abspath, '/');
        $user = trim($_POST['user'] ?? '');
        $pass = (string)($_POST['pass'] ?? '');
        if ($user === '' || $pass === '') { echo json_encode(['ok' => false, 'error' => 'User and password required']); exit; }
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $user)) { echo json_encode(['ok' => false, 'error' => 'User may contain only letters, digits, . _ -']); exit; }
        if (!is_writable($root)) { echo json_encode(['ok' => false, 'error' => 'Web root is not writable; cannot create a test folder.']); exit; }

        $dir = $root . '/_sitepass_test_' . bin2hex(random_bytes(4));
        if (!@mkdir($dir, 0755)) { echo json_encode(['ok' => false, 'error' => 'Could not create test folder']); exit; }
        $hash   = password_hash($pass, PASSWORD_BCRYPT); // Apache 2.4 accepts $2y$
        $htpw   = $dir . '/.htpasswd';
        file_put_contents($htpw, "$user:$hash\n");
        // AuthUserFile needs an absolute server path.
        $ht = "AuthType Basic\nAuthName \"Protected\"\nAuthUserFile \"" . $htpw . "\"\nRequire valid-user\n";
        file_put_contents($dir . '/.htaccess', $ht);
        file_put_contents($dir . '/index.html', '<!doctype html><title>OK</title><h1>Site-password test OK</h1><p>If you can read this only after entering the user/password, Basic auth works on this host.</p>');

        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base  = rtrim(dirname($proto . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/')), '/');
        $probe_url = $base . '/' . basename($dir) . '/';

        // Request the folder WITHOUT credentials: expect 401 (good) not 500 (bad).
        $status = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($probe_url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false]);
            curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); unset($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
            @file_get_contents($probe_url, false, $ctx);
            $hdrs = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
            if (isset($hdrs[0]) && preg_match('#\s(\d{3})\s#', $hdrs[0], $m)) $status = (int)$m[1];
        }

        // Keep the folder a short while so the user can open the probe URL, then
        // schedule cleanup on shutdown is not reliable here — we leave it and the
        // apply/remove actions (and a stale sweep) clean it. Return the verdict.
        $breaks  = ($status >= 500);
        $prompts = ($status === 401);
        echo json_encode(['ok' => true, 'status' => $status, 'breaks' => $breaks, 'prompts_auth' => $prompts,
            'supported' => ($prompts && !$breaks), 'probe_url' => $probe_url, 'test_dir' => basename($dir),
            'note' => $breaks ? 'The host returned HTTP 500 — Basic auth via .htaccess is NOT supported here.'
                     : ($prompts ? 'HTTP 401 — Basic auth works. Open the probe URL to try the login, then Apply.'
                     : "HTTP $status — inconclusive (the dev server does not process .htaccess; test on the real host).")]);
        exit;
    }

    // --- SITE PASSWORD: apply to the whole site ---
    if ($action === 'site_pass_apply') {
        $root = rtrim($abspath, '/');
        $user = trim($_POST['user'] ?? '');
        $pass = (string)($_POST['pass'] ?? '');
        if ($user === '' || $pass === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $user)) { echo json_encode(['ok' => false, 'error' => 'Invalid user/password']); exit; }
        if (!is_writable($root) && !(is_file($root . '/.htaccess') && is_writable($root . '/.htaccess'))) {
            echo json_encode(['ok' => false, 'error' => 'Web root / .htaccess not writable']); exit;
        }
        $htpw = $root . '/.htpasswd';
        file_put_contents($htpw, "$user:" . password_hash($pass, PASSWORD_BCRYPT) . "\n");
        @chmod($htpw, 0644);
        $htaccess = $root . '/.htaccess';
        if (is_file($htaccess)) @copy($htaccess, $htaccess . '.bak-' . date('YmdHis'));
        $ms = '# BEGIN WP-Migrate Site Password'; $me = '# END WP-Migrate Site Password';
        $block = "$ms\nAuthType Basic\nAuthName \"Restricted\"\nAuthUserFile \"$htpw\"\nRequire valid-user\n$me";
        $existing = is_file($htaccess) ? file_get_contents($htaccess) : '';
        $existing = trim(preg_replace('/' . preg_quote($ms, '/') . '.*?' . preg_quote($me, '/') . '/s', '', $existing));
        @file_put_contents($htaccess, $block . ($existing ? "\n\n" . $existing : '') . "\n");
        echo json_encode(['ok' => true, 'note' => 'Site password applied. The whole site now requires HTTP Basic auth.']);
        exit;
    }

    // --- SITE PASSWORD: remove ---
    if ($action === 'site_pass_remove') {
        $root = rtrim($abspath, '/');
        $htaccess = $root . '/.htaccess';
        $ms = '# BEGIN WP-Migrate Site Password'; $me = '# END WP-Migrate Site Password';
        if (is_file($htaccess)) {
            $c = trim(preg_replace('/' . preg_quote($ms, '/') . '.*?' . preg_quote($me, '/') . '/s', '', file_get_contents($htaccess)));
            @file_put_contents($htaccess, $c . ($c ? "\n" : ''));
        }
        @unlink($root . '/.htpasswd');
        // Sweep any leftover test folders.
        foreach (glob($root . '/_sitepass_test_*') ?: [] as $d) {
            if (is_dir($d)) { foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) @unlink($f); } @rmdir($d); }
        }
        echo json_encode(['ok' => true, 'note' => 'Site password removed.']);
        exit;
    }

    // --- INSTALL PHPMYADMIN ---
    // Downloads the latest phpMyAdmin into a randomly-named folder (phpmyadmin_<rand>)
    // so the admin path is not guessable by bots/scanners. No config is written at
    // install time; credentials are placed on demand by pma_config_on and auto-expire.
    if ($action === 'pma_install') {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        $root    = rtrim($abspath, '/');
        // Reuse an existing random folder if present; otherwise create a new one.
        $existing = glob($root . '/phpmyadmin_*', GLOB_ONLYDIR);
        $dest    = ($existing && is_dir($existing[0])) ? $existing[0] : $root . '/phpmyadmin_' . bin2hex(random_bytes(5));
        $tmp_zip = sys_get_temp_dir() . '/pma_' . time() . '.zip';
        // Latest stable English build.
        $url     = 'https://www.phpmyadmin.net/downloads/phpMyAdmin-latest-english.zip';
        if (is_dir($dest)) { echo json_encode(['ok' => false, 'error' => 'phpMyAdmin is already installed at ' . basename($dest) . '. Remove it first to reinstall.']); exit; }

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
        if (!is_dir($dest)) { echo json_encode(['ok' => false, 'error' => 'Extraction succeeded but moving to the phpMyAdmin folder failed.']); exit; }

        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base  = rtrim(dirname($proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/')), '/');
        $folder = basename($dest);
        echo json_encode(['ok' => true, 'dl_method' => $dl_method, 'ex_method' => $ex_method, 'folder' => $folder, 'url' => $base . '/' . $folder . '/']);
        exit;
    }

    // --- PHPMYADMIN: write an auto-expiring config with credentials ---
    // Drops a config.inc.php carrying the DB credentials plus a self-destruct guard:
    // the file deletes itself when loaded more than TTL seconds after creation, so
    // credentials never linger even if the browser tab is closed. Returns the URL.
    if ($action === 'pma_config_on') {
        $root = rtrim($abspath, '/');
        $dirs = glob($root . '/phpmyadmin_*', GLOB_ONLYDIR);
        if (!$dirs) { echo json_encode(['ok' => false, 'error' => 'phpMyAdmin is not installed yet.']); exit; }
        $dir = $dirs[0];
        // TTL in minutes (default 15); accepted values 5/15/30/60.
        $mins = (int)($_POST['ttl_min'] ?? 15);
        if (!in_array($mins, [5, 15, 30, 60], true)) $mins = 15;
        $ttl = $mins * 60;
        $host = $_POST['db_host'] ?? $db['host'];
        $user = $_POST['db_user'] ?? $db['user'];
        $pass = $_POST['db_pass'] ?? $db['pass'];
        $created = time();
        $secret  = bin2hex(random_bytes(16));
        $self    = $dir . '/config.inc.php';
        // The guard runs first: past TTL it unlinks this very file and stops.
        $php  = "<?php\n";
        $php .= "\$__pma_created = $created; \$__pma_ttl = $ttl;\n";
        $php .= "if (time() - \$__pma_created > \$__pma_ttl) { @unlink(__FILE__); header('Location: index.php'); exit; }\n";
        $php .= "\$cfg['blowfish_secret'] = '$secret';\n";
        $php .= "\$i = 0; \$i++;\n";
        $php .= "\$cfg['Servers'][\$i]['auth_type'] = 'config';\n";
        $php .= "\$cfg['Servers'][\$i]['host'] = " . var_export($host, true) . ";\n";
        $php .= "\$cfg['Servers'][\$i]['user'] = " . var_export($user, true) . ";\n";
        $php .= "\$cfg['Servers'][\$i]['password'] = " . var_export($pass, true) . ";\n";
        $php .= "\$cfg['Servers'][\$i]['AllowNoPassword'] = true;\n";
        if (@file_put_contents($self, $php) === false) { echo json_encode(['ok' => false, 'error' => 'Could not write config.inc.php (folder not writable)']); exit; }
        @chmod($self, 0600);
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base  = rtrim(dirname($proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/')), '/');
        echo json_encode(['ok' => true, 'ttl' => $ttl, 'url' => $base . '/' . basename($dir) . '/']);
        exit;
    }

    // --- PHPMYADMIN: remove the credentials config now ---
    if ($action === 'pma_config_off') {
        $root = rtrim($abspath, '/');
        $removed = 0;
        foreach (glob($root . '/phpmyadmin_*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (@unlink($dir . '/config.inc.php')) $removed++;
        }
        echo json_encode(['ok' => true, 'removed' => $removed]);
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

    // --- AUTO ASSET RECOVERY: enable / disable / status (.htaccess fallback) ---
    // Adds a rewrite block so any missing /wp-content/ file is routed to the
    // asset_fallback action, which fetches it from the origin on demand.
    if ($action === 'autorecover_status' || $action === 'autorecover_enable' || $action === 'autorecover_disable') {
        $root     = rtrim($abspath, '/');
        $htaccess = $root . '/.htaccess';
        $self     = basename(__FILE__);
        $ms = '# BEGIN WP-Migrate AutoRecover'; $me = '# END WP-Migrate AutoRecover';
        $cfg = file_exists($config_file) ? (json_decode(file_get_contents($config_file), true) ?: []) : [];
        $active = is_file($htaccess) && strpos(file_get_contents($htaccess), $ms) !== false;

        if ($action === 'autorecover_status') {
            echo json_encode(['ok' => true, 'active' => $active, 'origin' => $cfg['origin_domain'] ?? '', 'miss' => $cfg['asset_miss'] ?? 'placeholder']);
            exit;
        }
        if ($action === 'autorecover_disable') {
            if (is_file($htaccess)) {
                $c = trim(preg_replace('/' . preg_quote($ms, '/') . '.*?' . preg_quote($me, '/') . '/s', '', file_get_contents($htaccess)));
                @file_put_contents($htaccess, $c . ($c ? "\n" : ''));
            }
            echo json_encode(['ok' => true, 'active' => false]);
            exit;
        }

        // enable
        $origin = rtrim(trim($_POST['origin'] ?? ($cfg['origin_domain'] ?? '')), '/');
        $miss   = in_array($_POST['miss'] ?? '', ['placeholder','none','404'], true) ? $_POST['miss'] : 'placeholder';
        if ($origin === '' || !preg_match('#^https?://#i', $origin)) { echo json_encode(['ok' => false, 'error' => 'Set a valid original site URL first']); exit; }
        migrate_config_set(['origin_domain' => $origin, 'asset_miss' => $miss]);
        if (!is_writable($root) && !(is_file($htaccess) && is_writable($htaccess))) { echo json_encode(['ok' => false, 'error' => 'Web root / .htaccess not writable']); exit; }

        // The rewrite block: missing files under /wp-content/ go to asset_fallback.
        $block = "$ms\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_URI} ^/.*wp-content/ [NC]\nRewriteRule ^(.*)$ /$self?action=asset_fallback&p=/%{REQUEST_URI} [L,QSA]\n</IfModule>\n$me";

        // Test the directives in an isolated subfolder first (anti-500).
        $dir = $root . '/_autorec_test_' . bin2hex(random_bytes(4));
        $tested = 'skipped';
        if (is_writable($root) && @mkdir($dir, 0755)) {
            file_put_contents($dir . '/.htaccess', "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /" . basename($dir) . "/\n</IfModule>\n");
            file_put_contents($dir . '/ok.php', "<?php echo 'OK';");
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base  = rtrim(dirname($proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/')), '/');
            $st = 0;
            if (function_exists('curl_init')) { $ch=curl_init($base.'/'.basename($dir).'/ok.php'); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false]); curl_exec($ch); $st=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); unset($ch); }
            @unlink($dir.'/.htaccess'); @unlink($dir.'/ok.php'); @rmdir($dir);
            if ($st >= 500) { echo json_encode(['ok' => false, 'error' => 'The host returned HTTP 500 for mod_rewrite — auto-recovery not supported here.']); exit; }
            $tested = ($st >= 200 && $st < 400) ? 'passed' : 'inconclusive';
        }

        if (is_file($htaccess)) @copy($htaccess, $htaccess . '.bak-' . date('YmdHis'));
        $existing = is_file($htaccess) ? file_get_contents($htaccess) : '';
        $existing = trim(preg_replace('/' . preg_quote($ms, '/') . '.*?' . preg_quote($me, '/') . '/s', '', $existing));
        // Put our block BEFORE the WordPress block so missing assets are caught first.
        $ok = @file_put_contents($htaccess, $block . "\n\n" . $existing . "\n") !== false;
        echo json_encode(['ok' => $ok, 'active' => $ok, 'tested' => $tested, 'origin' => $origin, 'miss' => $miss,
            'error' => $ok ? '' : 'Write failed']);
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

    // --- SELF-UPDATE: check the latest GitHub release and replace this file ---
    // check_update queries the repo's latest release (tag vX.Y.Z-wp) and compares it
    // with TOOL_VERSION. do_update downloads that release's cms_tools.php, validates it
    // with `php -l`, backs up the current file, then replaces it in place (reversible).
    if ($action === 'check_update') {
        $api = 'https://api.github.com/repos/patchamama/Bigdump-customized-for-wordpress/releases/latest';
        $body = http_fetch_body($api, ['User-Agent: cms_tools/' . TOOL_VERSION, 'Accept: application/vnd.github+json']);
        if ($body === null) { echo json_encode(['ok' => false, 'error' => 'Could not reach GitHub']); exit; }
        $data = json_decode($body, true);
        $tag  = $data['tag_name'] ?? '';
        if (!preg_match('/(\d+\.\d+\.\d+)/', $tag, $m)) { echo json_encode(['ok' => false, 'error' => 'No release version found']); exit; }
        $latest = $m[1];
        $newer  = version_compare($latest, TOOL_VERSION, '>');
        echo json_encode(['ok' => true, 'current' => TOOL_VERSION, 'latest' => $latest, 'update_available' => $newer,
            'url' => $data['html_url'] ?? '', 'notes' => mb_substr((string)($data['body'] ?? ''), 0, 600)]);
        exit;
    }

    if ($action === 'do_update') {
        $self = __FILE__;
        if (!is_writable($self)) { echo json_encode(['ok' => false, 'error' => 'This file is not writable — cannot self-update']); exit; }
        // Resolve the latest release's cms_tools.php download URL.
        $api  = 'https://api.github.com/repos/patchamama/Bigdump-customized-for-wordpress/releases/latest';
        $body = http_fetch_body($api, ['User-Agent: cms_tools/' . TOOL_VERSION, 'Accept: application/vnd.github+json']);
        if ($body === null) { echo json_encode(['ok' => false, 'error' => 'Could not reach GitHub']); exit; }
        $data = json_decode($body, true);
        if (!preg_match('/(\d+\.\d+\.\d+)/', (string)($data['tag_name'] ?? ''), $m)) { echo json_encode(['ok' => false, 'error' => 'No release version found']); exit; }
        $latest = $m[1];
        if (version_compare($latest, TOOL_VERSION, '<=')) { echo json_encode(['ok' => false, 'error' => 'Already up to date (' . TOOL_VERSION . ')']); exit; }
        $dl = '';
        foreach (($data['assets'] ?? []) as $a) {
            if (($a['name'] ?? '') === 'cms_tools.php') { $dl = $a['browser_download_url'] ?? ''; break; }
        }
        // Fall back to the tagged raw file if the asset isn't attached.
        if ($dl === '') $dl = 'https://raw.githubusercontent.com/patchamama/Bigdump-customized-for-wordpress/' . $data['tag_name'] . '/cms_tools.php';
        $new = http_fetch_body($dl, ['User-Agent: cms_tools/' . TOOL_VERSION]);
        if ($new === null || strlen($new) < 5000 || strpos($new, '<?php') === false) {
            echo json_encode(['ok' => false, 'error' => 'Downloaded file looks invalid']); exit;
        }
        // Validate the new file with `php -l` before swapping anything in.
        $tmp = sys_get_temp_dir() . '/cms_tools_update_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($tmp, $new);
        $lint_ok = true; $lint_msg = '';
        if (check_cmd('php')) {
            exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $o, $ret);
            $lint_ok = ($ret === 0); $lint_msg = implode("\n", $o);
        }
        if (!$lint_ok) { @unlink($tmp); echo json_encode(['ok' => false, 'error' => 'New file failed php -l: ' . $lint_msg]); exit; }
        // Back up the current file, then replace it in place.
        $bak = dirname($self) . '/cms_tools.php.bak-' . TOOL_VERSION;
        if (!@copy($self, $bak)) { @unlink($tmp); echo json_encode(['ok' => false, 'error' => 'Could not write backup ' . basename($bak)]); exit; }
        if (!@rename($tmp, $self) && !(@copy($tmp, $self) && @unlink($tmp))) {
            echo json_encode(['ok' => false, 'error' => 'Could not overwrite the tool file (backup kept at ' . basename($bak) . ')']); exit;
        }
        @chmod($self, 0644);
        echo json_encode(['ok' => true, 'from' => TOOL_VERSION, 'to' => $latest, 'backup' => basename($bak)]);
        exit;
    }

    // --- FETCH bigdump.php INTO THE SITE ---
    // Downloads bigdump.php from the project repo into the WordPress root so it
    // can be opened directly. Returns the URL to open it in a new tab.
    if ($action === 'fetch_bigdump') {
        $dest = rtrim($abspath, '/') . '/bigdump.php';
        $url  = 'https://raw.githubusercontent.com/patchamama/Bigdump-customized-for-wordpress/main/bigdump.php';
        if (!is_writable(dirname($dest)) && !is_file($dest)) { echo json_encode(['ok' => false, 'error' => 'WordPress root is not writable']); exit; }

        $data = false; $method = '';
        if (check_cmd('curl')) {
            exec(sprintf('curl -L --silent -o %s %s 2>&1', escapeshellarg($dest), escapeshellarg($url)), $o, $ret);
            if ($ret === 0 && is_file($dest) && filesize($dest) > 1000) { $data = true; $method = 'curl CLI'; }
        }
        if (!$data && function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false]);
            $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); unset($ch);
            if ($code === 200 && $body && strlen($body) > 1000) { file_put_contents($dest, $body); $data = true; $method = 'curl PHP'; }
        }
        if (!$data && ini_get('allow_url_fopen')) {
            $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60, 'follow_location' => true]]));
            if ($body && strlen($body) > 1000) { file_put_contents($dest, $body); $data = true; $method = 'file_get_contents'; }
        }
        if (!$data || !is_file($dest)) { echo json_encode(['ok' => false, 'error' => 'Could not download bigdump.php. Check server connectivity.']); exit; }

        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base  = rtrim(dirname($proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/')), '/');
        echo json_encode(['ok' => true, 'method' => $method, 'size' => human_size(filesize($dest)), 'url' => $base . '/bigdump.php']);
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
// siteurl lives in wp_options — only meaningful for WordPress.
$site_url    = ($detected_cms === 'wordpress') ? detect_site_url($db) : '';
$current_url = current_url();
$db_test     = try_db_connect($db);
// MySQL/MariaDB server version, read once from the live connection for the status card.
$mysql_version = '';
if ($db_test['ok'] && !empty($db_test['pdo'])) {
    try { $mysql_version = (string)$db_test['pdo']->query('SELECT VERSION()')->fetchColumn(); } catch (Exception $e) {}
}
$abspath_size = is_dir($abspath) ? dir_size($abspath) : 0;
// Is bigdump.php already present next to the site? If so we can link to open it.
$bigdump_present = is_file(rtrim($abspath, '/') . '/bigdump.php');
$bigdump_url     = rtrim(dirname($current_url), '/') . '/bigdump.php';
// Detect an installed phpMyAdmin folder (random name) to always show its path.
$pma_dirs    = glob(rtrim($abspath, '/') . '/phpmyadmin_*', GLOB_ONLYDIR) ?: [];
$pma_folder  = $pma_dirs ? basename($pma_dirs[0]) : '';
$pma_url     = $pma_folder ? rtrim(dirname($current_url), '/') . '/' . $pma_folder . '/' : '';

?><!DOCTYPE html>
<html lang="<?= h($LANG) ?>" data-theme="<?= h($THEME) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CMS Tools<?= $cms_label ? ' — ' . h($cms_label) : '' ?></title>
<style>
/* Theme tokens. Dark is the default; [data-theme=light] overrides them. The base
   colors used across the UI (#0f1117, #1a1d27, #2d3148, #e2e8f0, #a0aec0, #718096)
   map to these variables so the whole app re-themes from one place. */
:root{
    --bg:#0f1117; --surface:#1a1d27; --surface2:#0f1117; --border:#2d3148;
    --text:#e2e8f0; --text-muted:#a0aec0; --text-dim:#718096; --heading:#fff; --link:#7dd3fc;
}
[data-theme=light]{
    --bg:#f4f5f7; --surface:#ffffff; --surface2:#f0f1f4; --border:#d7dbe3;
    --text:#1a1d27; --text-muted:#4a5568; --text-dim:#718096; --heading:#0f1117; --link:#2563eb;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;transition:background .2s,color .2s}
.wrap{max-width:900px;margin:0 auto;padding:32px 16px}
h1{font-size:22px;font-weight:700;color:var(--heading);margin-bottom:4px}
.subtitle{color:var(--text-dim);font-size:13px;margin-bottom:32px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:24px;margin-bottom:20px}
.card h2{font-size:14px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:16px}
.field{margin-bottom:12px}
.field label{display:block;font-size:12px;color:var(--text-dim);margin-bottom:4px}
.field input,.field select{width:100%;padding:8px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:14px;outline:none}
.field input:focus{border-color:#4f6ef7}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:600px){.row{grid-template-columns:1fr}}
/* Light-mode overrides for the most common hardcoded inline colors. */
[data-theme=light] .info-val,[data-theme=light] input,[data-theme=light] select,[data-theme=light] textarea{color:var(--text)!important}
[data-theme=light] .log{background:var(--surface2)!important;color:#15803d!important;border-color:var(--border)!important}
[data-theme=light] .tabs{background:var(--surface2)!important}
[data-theme=light] .tab.active{background:var(--surface)!important;color:var(--heading)!important}
[data-theme=light] .instructions{background:var(--surface2)!important;color:var(--text-muted)!important}
[data-theme=light] .instructions code{background:var(--surface)!important}
[data-theme=light] a{color:var(--link)}
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
.info-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}
.info-row:last-child{border-bottom:none}
.info-label{color:var(--text-dim)}
.info-val{color:var(--text);font-family:monospace;font-size:12px}
.log{background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:12px;font-family:monospace;font-size:12px;color:#6ee7b7;min-height:60px;max-height:200px;overflow-y:auto;margin-top:12px;white-space:pre-wrap}
.tabs{display:flex;gap:2px;margin-bottom:20px;background:var(--surface2);border-radius:8px;padding:4px}
.tab{flex:1;padding:8px;text-align:center;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500;color:var(--text-dim);border:none;background:none}
.tab.active{background:var(--surface);color:var(--heading)}
.section{display:none}.section.active{display:block}
.alert{padding:12px 16px;border-radius:6px;font-size:13px;margin-bottom:16px}
.alert-warn{background:#78350f22;border:1px solid #d97706;color:#fcd34d}
.alert-ok{background:#064e3b22;border:1px solid #22c55e;color:#6ee7b7}
.alert-err{background:#7f1d1d22;border:1px solid #ef4444;color:#fca5a5}
.instructions{background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:16px;font-size:12px;color:var(--text-muted);line-height:1.7}
.instructions h4{color:var(--text);margin-bottom:8px;font-size:13px}
.instructions ol{padding-left:18px}
.instructions code{background:var(--surface);padding:1px 5px;border-radius:3px;color:var(--link)}
.progress{height:6px;background:var(--border);border-radius:3px;overflow:hidden;margin-top:8px}
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
<h1>🔐 CMS Tools</h1>
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
    <h1>🚀 CMS Tools <span style="font-size:13px;font-weight:400;color:#4f6ef7;margin-left:6px">v<?= TOOL_VERSION ?></span>
        <?php if ($cms_label): ?><span class="badge badge-ok" style="margin-left:6px;vertical-align:middle"><?= h($cms_label) ?></span><?php endif; ?>
        <span id="update_badge" style="display:none;margin-left:8px;vertical-align:middle"></span></h1>
    <div class="subtitle"><?= h(t('Migration & maintenance tools · Export & Import')) ?></div>
</div>
<div style="display:flex;gap:8px;align-items:center">
<button id="theme_toggle" class="btn btn-sm" style="background:var(--border);color:var(--text-muted)" onclick="toggleTheme()" title="<?= h(t('Toggle light/dark')) ?>"><?= $THEME === 'light' ? '🌙' : '☀️' ?></button>
<span style="display:inline-flex;border:1px solid var(--border);border-radius:6px;overflow:hidden;font-size:12px">
    <a href="?lang=en" style="padding:5px 10px;text-decoration:none;<?= $LANG==='en'?'background:#4f6ef7;color:#fff':'background:var(--border);color:var(--text-muted)' ?>">EN</a>
    <a href="?lang=es" style="padding:5px 10px;text-decoration:none;<?= $LANG==='es'?'background:#4f6ef7;color:#fff':'background:var(--border);color:var(--text-muted)' ?>">ES</a>
</span>
<a href="?action=download_self" class="btn btn-sm" style="background:#2d3148;color:#a0aec0;text-decoration:none" download>⬇️ <?= h(t('Download tool')) ?></a>
<button id="bigdump_btn" class="btn btn-sm" style="background:#2d3148;color:#a0aec0" onclick="fetchBigdump()">⬇️ <?= h(t('Get BigDump')) ?></button>
<a id="bigdump_open" href="<?= h($bigdump_url) ?>" target="_blank" class="btn btn-sm" style="background:#1e3a2f;color:#6ee7b7;text-decoration:none;<?= $bigdump_present ? '' : 'display:none' ?>">↗ <?= h(t('Open BigDump')) ?></a>
<a href="?logout" class="btn btn-sm" style="background:#2d3148;color:#a0aec0"><?= h(t('Logout')) ?></a>
</div>
</div>

<?php if (!is_localhost()): ?>
<div class="alert alert-warn">⚠️ <strong><?= h(t('Running on live server.')) ?></strong> <?= h(t('Delete this file after use!')) ?></div>
<?php endif; ?>

<div id="news_banner" class="alert" style="display:none;background:#3b1a1a;border-color:#7f1d1d;color:#fca5a5"></div>

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
    <span class="info-label"><?= h(t('Detected CMS')) ?></span>
    <span class="info-val">
        <?php if ($cms_label): ?>
            <span class="badge badge-ok"><?= h($cms_label) ?></span>
            <span style="color:#4a5568;font-size:11px"><?= h(basename($cms_config_path)) ?></span>
        <?php else: ?>
            <span class="badge badge-err"><?= h(t('none detected')) ?></span>
        <?php endif; ?>
        <?php if ($pma_present_dir): ?>
            <span class="badge" style="background:#1e3a2f;color:#6ee7b7;margin-left:6px">phpMyAdmin: <?= h($pma_present_dir) ?>/</span>
        <?php endif; ?>
    </span>
</div>
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
    <span class="info-label"><?= h(t('PHP version')) ?></span>
    <span class="info-val">
        <span class="badge <?= version_compare(PHP_VERSION, '7.4', '>=') ? 'badge-ok' : 'badge-warn' ?>"><?= h(PHP_VERSION) ?></span>
        <?php if (version_compare(PHP_VERSION, '7.4', '<')): ?><span style="color:var(--text-dim);font-size:11px"><?= h(t('WordPress recommends 7.4+')) ?></span><?php endif; ?>
    </span>
</div>
<div class="info-row">
    <span class="info-label"><?= h(t('MySQL version')) ?></span>
    <span class="info-val">
        <?php if ($mysql_version): ?>
            <span class="badge badge-ok"><?= h($mysql_version) ?></span>
        <?php else: ?>
            <span class="badge badge-warn"><?= h(t('unavailable (DB not connected)')) ?></span>
        <?php endif; ?>
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
    <span class="info-label"><?= h(t('CLI available')) ?></span>
    <span class="info-val">
        <?php foreach (['mysqldump','mysql','zip','unzip','tar','wp','curl'] as $cmd): ?>
        <span class="badge <?= check_cmd($cmd) ? 'badge-ok' : 'badge-err' ?>" title="<?= $cmd === 'wp' ? 'WP-CLI' : '' ?>"><?= $cmd ?></span>
        <?php endforeach; ?>
    </span>
</div>
</div>

<!-- QUICK UPDATE -->
<div class="card" style="border-color:#4f6ef7">
<h2>⚡ <?= h(t('Quick Update (after moving the site)')) ?></h2>
<p style="font-size:12px;color:var(--text-dim);margin-bottom:14px">
    <?= h(t('The tool reads the OLD site URL from the database and knows the CURRENT URL from your browser. With the real DB credentials below it can update wp-config, change the site URL, convert absolute URLs to relative, clear caches and deactivate plugins in one step. A backup of wp-config.php is made and every step is reported.')) ?>
</p>
<div class="info-row">
    <span class="info-label"><?= h(t('Old URL (from database)')) ?></span>
    <span class="info-val" id="qu_old_url"><?= h($site_url ?: '—') ?></span>
</div>
<div class="info-row">
    <span class="info-label"><?= h(t('Current URL (this browser)')) ?></span>
    <span class="info-val"><?= h($current_url) ?></span>
</div>
<div class="row" style="margin-top:14px">
    <div class="field"><label><?= h(t('DB Host')) ?></label><input type="text" id="qu_db_host" value="<?= h($db['host']) ?>"></div>
    <div class="field"><label><?= h(t('DB Name')) ?></label><input type="text" id="qu_db_name" value="<?= h($db['name']) ?>"></div>
</div>
<div class="row">
    <div class="field"><label><?= h(t('DB User')) ?></label><input type="text" id="qu_db_user" value="<?= h($db['user']) ?>"></div>
    <div class="field"><label><?= h(t('DB Password')) ?></label><input type="password" id="qu_db_pass" value="<?= h($db['pass']) ?>"></div>
</div>
<div class="field"><label><?= h(t('Table prefix')) ?></label><input type="text" id="qu_db_prefix" value="<?= h($db['prefix']) ?>" style="max-width:200px"></div>
<div style="font-size:12px;color:var(--text-muted);margin:10px 0 6px"><?= h(t('Steps to run')) ?>:</div>
<div style="display:flex;flex-direction:column;gap:6px;font-size:13px;margin-bottom:14px">
    <label><input type="checkbox" class="qu-step" value="wpconfig" checked> <?= h(t('Update wp-config.php with these credentials (backup first)')) ?></label>
    <label><input type="checkbox" class="qu-step" value="siteurl" checked> <?= h(t('Set siteurl/home to the current URL')) ?></label>
    <label><input type="checkbox" class="qu-step" value="relativize" checked> <?= h(t('Convert absolute wp-content URLs to relative + clear cache')) ?></label>
    <label><input type="checkbox" class="qu-step" value="plugins"> <?= h(t('Deactivate all plugins')) ?></label>
</div>
<div class="actions">
    <button class="btn btn-primary" id="qu_btn" onclick="runQuickUpdate()">⚡ <?= h(t('Run quick update')) ?></button>
</div>
<div id="qu_log" class="log" style="display:none"></div>

<div style="border-top:1px solid var(--border);margin-top:14px;padding-top:12px">
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">🩺 <?= h(t('Quick diagnostics')) ?></div>
    <p style="font-size:11px;color:var(--text-dim);margin-bottom:8px">
        <?= h(t('Scans the frequent reasons a site won\'t display: database, site URL, WP tables, maintenance mode, broken plugins, stale cache, .htaccess (500/redirects), PHP config, password locks and shadowing index.html. Runs step by step with a progress bar; fixes are offered, never auto-applied.')) ?>
    </p>
    <div class="actions" style="margin-bottom:8px">
        <button class="btn btn-sm btn-primary" id="diag_btn" onclick="runQuickDiagnostics()">🩺 <?= h(t('Run quick diagnostics')) ?></button>
    </div>
    <div id="diag_progress" style="display:none;margin-bottom:8px"><div class="progress"><div class="progress-bar" id="diag_bar" style="width:0%"></div></div><div id="diag_ptxt" style="font-size:11px;color:var(--text-dim);margin-top:3px"></div></div>
    <div id="diag_results" style="font-size:12px"></div>
    <div id="diag_log" class="log" style="display:none"></div>
</div>

<div style="border-top:1px solid var(--border);margin-top:14px;padding-top:12px">
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">💾 <?= h(t('Site snapshots')) ?></div>
    <p style="font-size:11px;color:var(--text-dim);margin-bottom:8px">
        <?= h(t('Saves a dated snapshot of the important configuration (.htaccess, php.ini, .user.ini, wp-config.php, robots.txt, .htpasswd) and the active plugins, so you can restore the site to that exact state if a later change breaks it. Nothing is changed when you create one; a restore always takes a safety snapshot of the current state first.')) ?>
    </p>
    <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:8px">
        <input type="text" id="snap_note" placeholder="<?= h(t('Optional note (e.g. before plugin update)')) ?>" style="flex:1;min-width:220px">
        <button class="btn btn-sm btn-primary" id="snap_btn" onclick="createSnapshot()">💾 <?= h(t('Save snapshot')) ?></button>
    </div>
    <div id="snap_list" style="font-size:12px"></div>
    <div id="snap_log" class="log" style="display:none"></div>
</div>
</div>

<!-- ASSET RECOVERY -->
<div class="card" style="border-color:#1e3a5f">
<h2>🩹 <?= h(t('Recover missing assets from the original site')) ?></h2>
<p style="font-size:12px;color:var(--text-dim);margin-bottom:14px">
    <?= h(t('Scans the database for /wp-content/ files referenced by the site, checks which are missing on disk, and downloads them from the original site (useful when an FTP copy was incomplete). The original domain is auto-filled from the old site URL and remembered for later.')) ?>
</p>
<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px">
    <div class="field" style="flex:1;min-width:260px;margin:0">
        <label><?= h(t('Original site URL (domain + optional path)')) ?></label>
        <input type="text" id="ar_origin" placeholder="https://old-site.com" value="<?= h($cfg_data['origin_domain'] ?? ($site_url ?: '')) ?>">
    </div>
    <button class="btn btn-sm" style="background:var(--border);color:var(--text-muted)" onclick="saveOrigin()">💾 <?= h(t('Save origin')) ?></button>
</div>
<div class="actions" style="margin-bottom:10px">
    <button class="btn btn-primary" id="ar_scan_btn" onclick="scanMissingAssets()">🔍 <?= h(t('Scan for missing assets')) ?></button>
    <button class="btn btn-success" id="ar_recover_btn" onclick="recoverAssets()" style="display:none">🩹 <?= h(t('Download missing from origin')) ?></button>
    <button class="btn btn-sm" style="background:var(--border);color:var(--text-muted);display:none" id="ar_stop" onclick="AR_ABORT=true">⏹ <?= h(t('Stop')) ?></button>
</div>
<div id="ar_progress" style="display:none;margin-bottom:10px">
    <div class="progress"><div class="progress-bar" id="ar_bar" style="width:0%"></div></div>
    <div id="ar_progress_txt" style="font-size:11px;color:var(--text-dim);margin-top:4px"></div>
</div>
<div id="ar_log" class="log" style="display:none"></div>
<div id="ar_missing_list" style="display:none;margin-top:10px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
        <strong style="font-size:12px" id="ar_missing_title"></strong>
        <button class="btn btn-sm" style="background:var(--border);color:var(--text-muted)" onclick="copyMissingAssets(this)">📋 <?= h(t('Copy list')) ?></button>
    </div>
    <div id="ar_missing_items" style="max-height:260px;overflow:auto;border:1px solid var(--border);border-radius:6px;padding:6px 10px;font-family:monospace;font-size:11px;line-height:1.6"></div>
</div>

<div style="border-top:1px solid var(--border);margin-top:14px;padding-top:12px">
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px">⚡ <?= h(t('Automatic recovery while browsing (.htaccess)')) ?></div>
    <p style="font-size:11px;color:var(--text-dim);margin-bottom:8px">
        <?= h(t('Routes any missing /wp-content/ file to a fallback that downloads it from the original site on the fly and caches it, so the site fills in as visitors browse. Tested safely before touching .htaccess. For images, choose what to show when the file is not at the origin either.')) ?>
    </p>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
        <label style="font-size:12px;color:var(--text-muted)"><?= h(t('If not found')) ?>:
            <select id="ar_miss" style="padding:5px 8px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;color:var(--text);font-size:12px">
                <option value="placeholder"><?= h(t('Show "image not found" placeholder')) ?></option>
                <option value="none"><?= h(t('Show nothing')) ?></option>
                <option value="404"><?= h(t('Normal 404')) ?></option>
            </select>
        </label>
        <button class="btn btn-sm btn-success" id="ar_auto_on" onclick="autoRecoverEnable()">⚡ <?= h(t('Enable auto-recovery')) ?></button>
        <button class="btn btn-sm" style="background:var(--border);color:var(--text-muted)" id="ar_auto_off" onclick="autoRecoverDisable()"><?= h(t('Disable')) ?></button>
        <span id="ar_auto_status" style="font-size:12px"></span>
    </div>
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
    <button class="tab" onclick="showTab('tools')">🛠️ <?= h(t('DB Tools')) ?></button>
    <button class="tab" onclick="showTab('news')">📰 <?= h(t('News')) ?></button>
    <button class="tab" onclick="showTab('info')">ℹ️ <?= h(t('How to use')) ?></button>
</div>

<!-- ===== EXPORT TAB ===== -->
<div id="tab-export" class="section active">

<div class="card">
<h2>🗄️ <?= h(t('Database Export')) ?></h2>
<div class="row">
    <div class="field"><label><?= h(t('DB Host')) ?></label><input type="text" id="exp_db_host" value="<?= h($db['host']) ?>"></div>
    <div class="field"><label><?= h(t('DB Name')) ?></label><input type="text" id="exp_db_name" value="<?= h($db['name']) ?>"></div>
</div>
<div class="row">
    <div class="field"><label><?= h(t('DB User')) ?></label><input type="text" id="exp_db_user" value="<?= h($db['user']) ?>"></div>
    <div class="field"><label><?= h(t('DB Password')) ?></label><input type="password" id="exp_db_pass" value="<?= h($db['pass']) ?>"></div>
</div>
<div class="row">
    <div class="field"><label><?= h(t('Table prefix')) ?></label><input type="text" id="exp_db_prefix" value="<?= h($db['prefix']) ?>"></div>
    <div class="field"><label></label>
        <div style="display:flex;gap:8px;margin-top:22px">
            <button class="btn btn-sm" style="background:#2d3148;color:#a0aec0" onclick="testDB('exp')">🔌 <?= h(t('Test connection')) ?></button>
            <button class="btn btn-sm" style="background:#1e3a2f;color:#6ee7b7" onclick="reloadWpConfig()">↺ wp-config</button>
        </div>
    </div>
</div>
<div id="exp_db_status"></div>
</div>

<div class="card">
<h2>🔗 <?= h(t('URL Replacement')) ?></h2>
<div class="row">
    <div class="field">
        <label><?= h(t('Source URL (origin)')) ?></label>
        <input type="text" id="exp_source_url" value="<?= h($site_url ?: $current_url) ?>" placeholder="https://origin.com">
    </div>
    <div class="field">
        <label><?= h(t('Target URL (destination)')) ?></label>
        <input type="text" id="exp_target_url" placeholder="https://destination.com" oninput="localStorage.setItem('migrate_target_url',this.value)">
    </div>
</div>
<p style="font-size:11px;color:#718096;margin-top:4px">
    URL will be replaced everywhere in the SQL including serialized data.
</p>
<div style="border-top:1px solid var(--border);margin-top:12px;padding-top:12px">
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">🗄️ <?= h(t('Replace directly in the current database')) ?></div>
    <p style="font-size:11px;color:var(--text-dim);margin-bottom:8px">
        <?= h(t('Replaces the source URL with the target across the live database (assets and links, serialized-safe). Each target URL is checked over HTTP first and only replaced if it responds — broken targets are skipped. Reversible log.')) ?>
    </p>
    <div class="actions" style="margin-bottom:8px">
        <button class="btn btn-sm btn-primary" id="dbu_analyze_btn" onclick="dbUrlAnalyze()">🔍 <?= h(t('Find & verify URLs')) ?></button>
        <button class="btn btn-sm btn-warning" id="dbu_apply_btn" onclick="dbUrlApply()" style="display:none">▶ <?= h(t('Replace verified in DB')) ?></button>
        <button class="btn btn-sm" style="background:var(--border);color:var(--text-muted);display:none" id="dbu_stop" onclick="DBU_ABORT=true">⏹ <?= h(t('Stop')) ?></button>
    </div>
    <div id="dbu_progress" style="display:none;margin-bottom:8px"><div class="progress"><div class="progress-bar" id="dbu_bar" style="width:0%"></div></div><div id="dbu_ptxt" style="font-size:11px;color:var(--text-dim);margin-top:3px"></div></div>
    <div id="dbu_result" style="font-size:12px"></div>
    <div id="dbu_log" class="log" style="display:none"></div>
</div>
</div>

<div class="card">
<h2>📦 <?= h(t('Export Actions')) ?></h2>
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
<h2>🗄️ <?= h(t('Destination DB')) ?></h2>
<div class="row">
    <div class="field"><label><?= h(t('DB Host')) ?></label><input type="text" id="imp_db_host" value="<?= h($db['host']) ?>"></div>
    <div class="field"><label><?= h(t('DB Name')) ?></label><input type="text" id="imp_db_name" value="<?= h($db['name']) ?>"></div>
</div>
<div class="row">
    <div class="field"><label><?= h(t('DB User')) ?></label><input type="text" id="imp_db_user" value="<?= h($db['user']) ?>"></div>
    <div class="field"><label><?= h(t('DB Password')) ?></label><input type="password" id="imp_db_pass" value="<?= h($db['pass']) ?>"></div>
</div>
<div class="row">
    <div class="field"><label><?= h(t('Table prefix')) ?></label><input type="text" id="imp_db_prefix" value="<?= h($db['prefix']) ?>"></div>
    <div class="field"><label>Expected destination URL</label>
        <input type="text" id="imp_dest_url" value="<?= h($current_url) ?>" placeholder="https://destination.com">
    </div>
</div>
<button class="btn btn-sm" style="background:#2d3148;color:#a0aec0;margin-top:8px" onclick="testDB('imp')">🔌 <?= h(t('Test connection')) ?></button>
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
<h2>📁 <?= h(t('Extract Hosting')) ?></h2>

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
<h2>📖 <?= h(t('How to migrate')) ?></h2>

<div class="instructions">
<h4><?= h(t('Migration step by step')) ?></h4>
<ol>
<li><?= t('On the <strong>origin</strong>: open the <strong>Export</strong> tab, set the source and target URLs, and export the database (optionally split into size-limited <code>.zip</code> parts for hosts with upload limits). Use <strong>Export Hosting</strong> for the full site zip.') ?></li>
<li><?= t('On the <strong>destination</strong>: create the database and user in phpMyAdmin, upload and extract the site files, and update <code>wp-config.php</code> with the new credentials.') ?></li>
<li><?= t('Open the <strong>Import</strong> tab on the destination and import the <code>.sql</code>, <code>.gz</code> or <code>.zip</code> parts in order. The tool verifies the URL matches first.') ?></li>
<li><?= t('For very large dumps, use the embedded <a href="?action=bigdump" style="color:#7dd3fc">BigDump</a> importer.') ?></li>
</ol>
</div>

<div class="instructions" style="margin-top:16px">
<h4><?= h(t('After migrating (DB Tools tab)')) ?></h4>
<ul style="padding-left:18px">
<li><?= t('<strong>Domain Link Manager</strong> — rewrite links to the old site to root-relative paths, and verify the final/existing URLs resolve.') ?></li>
<li><?= t('<strong>Media URL Scanner</strong> — convert absolute <code>wp-content</code> URLs to relative, serialized-data safe.') ?></li>
<li><?= t('<strong>Site URL manager</strong> — switch between http and https.') ?></li>
<li><?= t('<strong>WordPress Users</strong> — list, create, edit and reset passwords without the WP admin.') ?></li>
<li><?= t('<strong>SEO</strong> — audit, edit title/tagline, friendly permalinks, sitemap, indexing toggle and indexing checks.') ?></li>
<li><?= t('<strong>Security Audit</strong> and <strong>Site Health Check</strong> — review hardening and integrity.') ?></li>
<li><?= t('<strong>Extras</strong> — regenerate <code>.htaccess</code>, repair/optimize tables, flush caches, toggle debug/maintenance.') ?></li>
<li><?= t('<strong>Site Password</strong> — protect the whole site with HTTP Basic auth (tested safely first).') ?></li>
<li><?= t('<strong>phpMyAdmin</strong> — install it on demand to manage the database directly.') ?></li>
</ul>
</div>

<div class="instructions" style="margin-top:16px">
<h4><?= h(t('Divi / WordPress notes')) ?></h4>
<ul style="padding-left:18px">
<li><?= t('URL replacement handles <strong>serialized PHP strings</strong> (Divi/Elementor store layout data serialized).') ?></li>
<li><?= t('If the layout looks broken after import, clear caches: <code>wp-content/cache/</code> and <code>wp-content/et-cache/</code> (or use Clear Cache above).') ?></li>
<li><?= t('Access to this tool requires the wp-config database password by default; set your own encrypted password from the Access Password card.') ?></li>
</ul>
</div>
</div>
</div><!-- /info -->

<!-- ===== NEWS TAB ===== -->
<div id="tab-news" class="section">
<div class="card">
<h2>📰 <?= h(t('WordPress News')) ?></h2>
<p style="font-size:12px;color:var(--text-dim);margin-bottom:14px">
    <?= h(t('Latest posts from the official WordPress.org news feed. Security or critical releases are highlighted and also shown as a banner at the top of the tool.')) ?>
</p>
<div class="actions" style="margin-bottom:12px">
    <button class="btn btn-sm btn-primary" id="news_btn" onclick="loadNews(true)">🔄 <?= h(t('Refresh news')) ?></button>
</div>
<div id="news_list" style="font-size:13px"></div>
</div>
</div><!-- /news -->

<!-- ===== TOOLS TAB ===== -->
<div id="tab-tools" class="section">

<div class="card">
<h2>🔌 <?= h(t('DB Connection for Tools')) ?></h2>
<div class="row">
    <div class="field"><label><?= h(t('DB Host')) ?></label><input type="text" id="tls_db_host" value="<?= h($db['host']) ?>"></div>
    <div class="field"><label><?= h(t('DB Name')) ?></label><input type="text" id="tls_db_name" value="<?= h($db['name']) ?>"></div>
</div>
<div class="row">
    <div class="field"><label><?= h(t('DB User')) ?></label><input type="text" id="tls_db_user" value="<?= h($db['user']) ?>"></div>
    <div class="field"><label><?= h(t('DB Password')) ?></label><input type="password" id="tls_db_pass" value="<?= h($db['pass']) ?>"></div>
</div>
<div class="row">
    <div class="field"><label><?= h(t('Table prefix')) ?></label><input type="text" id="tls_db_prefix" value="<?= h($db['prefix']) ?>"></div>
    <div class="field"><label>&nbsp;</label>
        <button class="btn btn-sm" style="background:#2d3148;color:#a0aec0;margin-top:22px" onclick="testDB('tls')">🔌 <?= h(t('Test connection')) ?></button>
    </div>
</div>
<div id="tls_db_status"></div>
</div>

<div class="card">
<h2>🌐 <?= h(t('Site URL Manager')) ?></h2>
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
<h2>🖼️ <?= h(t('Media URL Scanner')) ?></h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    Scans posts, postmeta and options for absolute <code>http(s)://domain/wp-content/</code> URLs in images, icons and videos,
    then converts them to root-relative <code>/wp-content/</code> paths. Handles serialized data correctly.
</p>
<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#a0aec0;margin-bottom:10px;cursor:pointer">
    <input type="checkbox" id="media_verify_disk">
    <?= h(t('Only rewrite URLs whose file exists on disk (use on the destination with files already uploaded)')) ?>
</label>
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
<h2>🔗 <?= h(t('Broken Link Checker')) ?></h2>
<p style="font-size:12px;color:#718096;margin-bottom:12px">
    <?= h(t('Checks the links stored in the database over HTTP and groups the broken ones (HTTP 400+ or no response) by domain. Click a domain to see its broken URLs and open each in a new tab.')) ?>
</p>
<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
    <label style="font-size:12px;color:#a0aec0"><?= h(t('Scope')) ?>:
        <select id="bl_scope" style="padding:5px 8px;background:#0f1117;border:1px solid #2d3148;border-radius:4px;color:#e2e8f0;font-size:12px">
            <option value="site"><?= h(t('Current site only')) ?></option>
            <option value="all"><?= h(t('All linked domains')) ?></option>
        </select>
    </label>
    <label style="font-size:12px;color:#a0aec0"><?= h(t('Max URLs')) ?>:
        <input type="number" id="bl_cap" value="200" min="10" max="5000" style="width:80px;padding:5px 8px;background:#0f1117;border:1px solid #2d3148;border-radius:4px;color:#e2e8f0;font-size:12px">
    </label>
    <button class="btn btn-sm btn-primary" id="bl_btn" onclick="checkBrokenLinks()">🔎 <?= h(t('Check links')) ?></button>
    <button class="btn btn-sm" style="background:#2d3148;color:#a0aec0;display:none" id="bl_stop" onclick="BL_ABORT=true">⏹ <?= h(t('Stop')) ?></button>
</div>
<div id="bl_progress" style="display:none;margin-bottom:10px">
    <div class="progress"><div class="progress-bar" id="bl_bar" style="width:0%"></div></div>
    <div id="bl_progress_txt" style="font-size:11px;color:#718096;margin-top:4px"></div>
</div>
<div id="bl_results"></div>

<div style="border-top:1px solid var(--border);margin-top:14px;padding-top:12px">
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">🔁 <?= h(t('Find & replace in links')) ?></div>
    <p style="font-size:11px;color:var(--text-dim);margin-bottom:8px">
        <?= h(t('Replace a substring inside links across the database. Before saving, the resulting URL is checked over HTTP and the change is applied only if it responds (exists). Serialized-safe, reversible log.')) ?>
    </p>
    <div class="row">
        <div class="field" style="margin:0"><label><?= h(t('Find (text in the link)')) ?></label><input type="text" id="blr_find" placeholder="http://old-domain.com/old-path"></div>
        <div class="field" style="margin:0"><label><?= h(t('Replace with')) ?></label><input type="text" id="blr_replace" placeholder="https://new-domain.com/new-path"></div>
    </div>
    <div class="actions" style="margin-top:8px">
        <button class="btn btn-sm btn-primary" id="blr_btn" onclick="blrFindReplace()">🔁 <?= h(t('Find, verify & replace')) ?></button>
    </div>
    <div id="blr_progress" style="display:none;margin-top:8px"><div class="progress"><div class="progress-bar" id="blr_bar" style="width:0%"></div></div><div id="blr_ptxt" style="font-size:11px;color:var(--text-dim);margin-top:3px"></div></div>
    <div id="blr_result" style="font-size:12px;margin-top:6px"></div>
</div>
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
<h2>🔌 <?= h(t('Plugin Manager')) ?></h2>
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
<h2>⚙️ <?= h(t('PHP Configuration')) ?></h2>
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

<div style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:14px;margin-bottom:14px">
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px"><?= h(t('Friendly permalinks')) ?></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
        <select id="seo_perma_struct" style="padding:6px 8px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;color:var(--text);font-size:12px">
            <option value="/%postname%/">/%postname%/ — <?= h(t('Post name (recommended)')) ?></option>
            <option value="/%year%/%monthnum%/%day%/%postname%/">/%year%/%monthnum%/%day%/%postname%/ — <?= h(t('Day and name')) ?></option>
            <option value="/%year%/%monthnum%/%postname%/">/%year%/%monthnum%/%postname%/ — <?= h(t('Month and name')) ?></option>
            <option value="/archives/%post_id%">/archives/%post_id% — <?= h(t('Numeric')) ?></option>
            <option value="/?p=%post_id%"><?= h(t('Plain (?p=123, no rewrite)')) ?></option>
        </select>
        <button class="btn btn-sm btn-warning" id="seo_perma_test_btn" onclick="seoPermaTest()">🧪 <?= h(t('Test safely')) ?></button>
        <button class="btn btn-sm btn-primary" id="seo_perma_apply_btn" onclick="seoPermaApply()" disabled style="opacity:.5;cursor:not-allowed">✅ <?= h(t('Apply')) ?></button>
    </div>
    <div id="seo_perma_result" style="font-size:11px"></div>
</div>
<div style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:14px;margin-bottom:14px">
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px"><?= h(t('SEO actions')) ?></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <button class="btn btn-sm btn-warning" onclick="seoGenerateSitemap()">🗺️ <?= h(t('Generate sitemap.xml')) ?></button>
        <span style="font-size:12px;color:var(--text-muted);margin-left:8px"><?= h(t('Indexing')) ?>:</span>
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

<div style="border-top:1px solid var(--border);margin-top:16px;padding-top:14px">
    <h3 style="font-size:14px;margin:0 0 6px">🔗 <?= h(t('Anti-hotlinking protection')) ?></h3>
    <p style="font-size:12px;color:var(--text-dim);margin-bottom:12px">
        <?= h(t('Hotlinking is when other websites embed this site\'s images or assets directly, so their visitors consume this server\'s bandwidth. This checks whether a referer-based protection exists, tests the proposed .htaccess rules in an isolated subfolder (they must not cause an HTTP 500), and only then applies them — backing up the current .htaccess first so you can revert.')) ?>
    </p>
    <div class="actions" style="margin-bottom:10px">
        <button class="btn btn-sm btn-primary" id="hl_check_btn" onclick="hotlinkCheck()">🔍 <?= h(t('Check protection')) ?></button>
        <button class="btn btn-sm" style="background:var(--border);color:var(--text-muted);display:none" id="hl_test_btn" onclick="hotlinkTest()">🧪 <?= h(t('Test rules (isolated)')) ?></button>
        <button class="btn btn-sm btn-success" id="hl_apply_btn" onclick="hotlinkApply()" style="display:none">🔗 <?= h(t('Apply protection')) ?></button>
    </div>
    <div id="hl_results" style="font-size:12px"></div>
    <div id="hl_log" class="log" style="display:none"></div>
</div>
</div>

<div class="card">
<h2>🏥 <?= h(t('Site Health Check')) ?></h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    Audits media files, active plugins, themes, DB tables, WP core files, filesystem permissions, and PHP environment.
    Uses the DB connection configured above.
</p>
<div class="actions" style="margin-bottom:16px">
    <button id="tls_health_btn" class="btn btn-primary" onclick="runHealthCheck()">🔍 Run Health Check</button>
</div>
<div id="tls_health_results"></div>
</div>

<!-- CLEAN SITE -->
<div class="card" style="border-color:#78350f44">
<h2>🧹 <?= h(t('Clean Site (remove unused uploads)')) ?></h2>
<p style="font-size:12px;color:var(--text-dim);margin-bottom:14px">
    <?= h(t('Finds which files in wp-content/uploads are actually used (referenced in the database, with their WordPress thumbnails) and builds a fresh uploads folder with only those. The current folder is renamed to uploads_old-remove-it_* (kept as backup, not deleted) and a restore log is written. Analyze first to see how much space would be freed; nothing changes until you apply.')) ?>
</p>
<div class="actions" style="margin-bottom:10px">
    <button class="btn btn-primary" id="cs_analyze_btn" onclick="cleanSiteAnalyze()">📊 <?= h(t('Analyze (dry-run)')) ?></button>
    <button class="btn btn-danger" id="cs_apply_btn" onclick="cleanSiteApply()" style="display:none">🧹 <?= h(t('Build clean uploads (keep old as backup)')) ?></button>
</div>
<div id="cs_result"></div>
<div id="cs_log" class="log" style="display:none"></div>
</div>

<!-- OPTIMIZE UPLOADS -->
<div class="card" style="border-color:#1e3a5f">
<h2>🗂️ <?= h(t('Optimize uploads (organize / dedupe / sanitize)')) ?></h2>
<p style="font-size:12px;color:var(--text-dim);margin-bottom:6px">
    <?= h(t('Three safe optimizations for a crowded wp-content/uploads. Each one locates files in the database first, moves/renames/merges them on disk, updates every reference, and writes a reversible JSON log. Analyze first (dry-run); apply runs in batches with a progress bar.')) ?>
</p>
<div class="alert alert-warn" style="margin-bottom:14px;font-size:12px">
    ⚠ <?= h(t('WordPress will keep uploading new files to its own year/month layout; this reorganizes existing files and updates their references. Changes are logged and reversible.')) ?>
</div>

<div style="border:1px solid var(--border);border-radius:6px;padding:12px;margin-bottom:12px">
    <div style="font-weight:600;font-size:13px;margin-bottom:4px">📁 <?= h(t('Organize into subfolders')) ?></div>
    <div style="font-size:11px;color:var(--text-dim);margin-bottom:8px"><?= h(t('YYYYMMDD_name → YYYY/MM/name; otherwise → FirstLetter/name. Moves thumbnails too.')) ?></div>
    <div class="actions"><button class="btn btn-sm btn-primary" id="org_btn" onclick="optOrganizeAnalyze()">📊 <?= h(t('Analyze') ) ?></button>
        <button class="btn btn-sm btn-warning" id="org_apply" onclick="optApply('organize')" style="display:none">▶ <?= h(t('Apply') ) ?></button></div>
    <div id="org_progress" style="display:none;margin-top:8px"><div class="progress"><div class="progress-bar" id="org_bar" style="width:0%"></div></div><div id="org_ptxt" style="font-size:11px;color:var(--text-dim);margin-top:3px"></div></div>
    <div id="org_result" style="font-size:12px;margin-top:6px"></div>
</div>

<div style="border:1px solid var(--border);border-radius:6px;padding:12px;margin-bottom:12px">
    <div style="font-weight:600;font-size:13px;margin-bottom:4px">🧬 <?= h(t('Deduplicate identical files')) ?></div>
    <div style="font-size:11px;color:var(--text-dim);margin-bottom:8px"><?= h(t('Same size + same hash → keep one, repoint references, delete the rest.')) ?></div>
    <div class="actions"><button class="btn btn-sm btn-primary" id="dedup_btn" onclick="optDedupeAnalyze()">📊 <?= h(t('Analyze') ) ?></button>
        <button class="btn btn-sm btn-warning" id="dedup_apply" onclick="optApply('dedupe')" style="display:none">▶ <?= h(t('Apply') ) ?></button></div>
    <div id="dedup_progress" style="display:none;margin-top:8px"><div class="progress"><div class="progress-bar" id="dedup_bar" style="width:0%"></div></div><div id="dedup_ptxt" style="font-size:11px;color:var(--text-dim);margin-top:3px"></div></div>
    <div id="dedup_result" style="font-size:12px;margin-top:6px"></div>
</div>

<div style="border:1px solid var(--border);border-radius:6px;padding:12px">
    <div style="font-weight:600;font-size:13px;margin-bottom:4px">🔤 <?= h(t('Sanitize special-character names')) ?></div>
    <div style="font-size:11px;color:var(--text-dim);margin-bottom:8px"><?= h(t('Accents, spaces and symbols → safe ASCII names. Updates references too.')) ?></div>
    <div class="actions"><button class="btn btn-sm btn-primary" id="san_btn" onclick="optSanitizeAnalyze()">📊 <?= h(t('Analyze') ) ?></button>
        <button class="btn btn-sm btn-warning" id="san_apply" onclick="optApply('sanitize')" style="display:none">▶ <?= h(t('Apply') ) ?></button></div>
    <div id="san_progress" style="display:none;margin-top:8px"><div class="progress"><div class="progress-bar" id="san_bar" style="width:0%"></div></div><div id="san_ptxt" style="font-size:11px;color:var(--text-dim);margin-top:3px"></div></div>
    <div id="san_result" style="font-size:12px;margin-top:6px"></div>
</div>
<div id="opt_log" class="log" style="display:none;margin-top:12px"></div>
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
    <?= h(t('Download the latest phpMyAdmin into a randomly-named folder (harder for bots to find). The path is shown below. "Temporary access" drops a config with the DB credentials and opens phpMyAdmin logged in; the config self-destructs after the chosen time so afterwards you must log in manually.')) ?>
</p>
<div id="tls_pma_path" style="font-size:12px;margin-bottom:12px">
    <?php if ($pma_folder): ?>
        <?= h(t('Installed at')) ?>: <a href="<?= h($pma_url) ?>" target="_blank" style="color:#7dd3fc;font-family:monospace"><?= h($pma_folder) ?>/</a>
    <?php else: ?>
        <span style="color:#718096"><?= h(t('Not installed yet.')) ?></span>
    <?php endif; ?>
</div>
<div class="actions" style="align-items:center">
    <button id="tls_pma_btn" class="btn btn-primary" onclick="installPhpMyAdmin()"<?= $pma_folder ? ' style="display:none"' : '' ?>>⬇️ <?= h(t('Download & install phpMyAdmin')) ?></button>
    <label style="font-size:12px;color:var(--text-muted);display:<?= $pma_folder ? 'inline-flex' : 'none' ?>;align-items:center;gap:4px" id="tls_pma_ttl_wrap"><?= h(t('Access time')) ?>:
        <select id="tls_pma_ttl" style="padding:5px 8px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;color:var(--text);font-size:12px">
            <option value="5">5 min</option>
            <option value="15" selected>15 min</option>
            <option value="30">30 min</option>
            <option value="60">60 min</option>
        </select>
    </label>
    <button id="tls_pma_cfg_btn" class="btn btn-warning" onclick="pmaTempAccess()"<?= $pma_folder ? '' : ' style="display:none"' ?>>⏱️ <?= h(t('Temporary access')) ?></button>
    <button id="tls_pma_off_btn" class="btn btn-sm" style="background:#2d3148;color:#a0aec0;<?= $pma_folder ? '' : 'display:none' ?>" onclick="pmaConfigOff()"><?= h(t('Remove credentials now')) ?></button>
</div>
<div id="tls_pma_countdown" style="font-size:12px;color:#fcd34d;margin-top:10px"></div>
<div id="tls_pma_log" class="log" style="display:none;margin-top:12px"></div>
</div>

<div class="card" style="border-color:#7f1d1d44">
<h2>🔒 <?= h(t('Site Password (HTTP Basic)')) ?></h2>
<p style="font-size:12px;color:#718096;margin-bottom:14px">
    <?= h(t('Protect the whole site with an HTTP Basic password (.htaccess + .htpasswd). Before applying, a test writes the rule into an isolated subfolder and checks the host returns 401 (auth required), not 500. Open the test URL to confirm the login prompt appears; Apply stays disabled until the test passes.')) ?>
</p>
<div class="row">
    <div class="field"><label><?= h(t('Username')) ?></label><input type="text" id="sp_user" value="admin"></div>
    <div class="field"><label><?= h(t('Password')) ?></label><input type="text" id="sp_pass"></div>
</div>
<div class="actions" style="margin-bottom:10px">
    <button class="btn btn-warning" id="sp_test_btn" onclick="sitePassTest()">🧪 <?= h(t('Test safely')) ?></button>
    <button class="btn btn-danger" id="sp_apply_btn" onclick="sitePassApply()" disabled style="opacity:.5;cursor:not-allowed">🔒 <?= h(t('Apply to whole site')) ?></button>
    <button class="btn btn-sm" style="background:#2d3148;color:#a0aec0" onclick="sitePassRemove()"><?= h(t('Remove protection')) ?></button>
</div>
<div id="tls_sp_result" style="margin-bottom:8px"></div>
<div id="tls_sp_log" class="log" style="display:none"></div>
</div>

<!-- TEST ENVIRONMENT (sandbox) -->
<div class="card" style="border-color:#1e3a5f">
<h2>🧪 <?= h(t('Test Environment (sandbox)')) ?></h2>
<p style="font-size:12px;color:var(--text-dim);margin-bottom:14px">
    <?= h(t('Builds an isolated WordPress sandbox in a subfolder: it replicates the database tables under a test prefix and links the core, plugins, themes and uploads (symlink, falling back to copy). Then it can smoke-test each plugin one by one against the sandbox, vet new plugins from wordpress.org before installing them live, and produce a report — all without risking the live site. Uses the DB connection from the DB Tools card above.')) ?>
</p>
<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;font-size:13px">
    <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
        <input type="checkbox" id="te_multisite" onchange="document.getElementById('te_ms_type').disabled=!this.checked">
        <?= h(t('Build as multisite network')) ?>
    </label>
    <select id="te_ms_type" disabled style="padding:5px 8px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;color:var(--text);font-size:12px">
        <option value="subdir"><?= h(t('Subdirectory')) ?></option>
        <option value="subdomain"><?= h(t('Subdomain (needs wildcard DNS)')) ?></option>
    </select>
</div>
<div class="actions" style="margin-bottom:10px">
    <button class="btn btn-primary" id="te_build_btn" onclick="teBuild()">🏗️ <?= h(t('Build sandbox')) ?></button>
    <button class="btn btn-warning" id="te_plugins_btn" onclick="teSmokeTestPlugins()" style="display:none">🔌 <?= h(t('Test plugins one by one')) ?></button>
    <button class="btn btn-warning" id="te_allplugins_btn" onclick="teTestAllPlugins()" style="display:none">🧩 <?= h(t('Test all plugins (find conflicts)')) ?></button>
    <button class="btn btn-sm" style="background:var(--border);color:var(--text-muted);display:none" id="te_stop" onclick="TE_ABORT=true">⏹ <?= h(t('Stop')) ?></button>
    <button class="btn btn-sm btn-danger" id="te_destroy_btn" onclick="teDestroy()" style="display:none">🗑️ <?= h(t('Destroy sandbox')) ?></button>
</div>
<div id="te_progress" style="display:none;margin-bottom:10px">
    <div class="progress"><div class="progress-bar" id="te_bar" style="width:0%"></div></div>
    <div id="te_progress_txt" style="font-size:11px;color:var(--text-dim);margin-top:4px"></div>
</div>
<div id="te_sandbox_info" style="font-size:12px;margin-bottom:10px"></div>

<div id="te_ms_card" style="display:none;border:1px solid var(--border);border-radius:6px;padding:12px;margin-bottom:10px">
    <div style="font-weight:600;font-size:13px;margin-bottom:6px">🌐 <?= h(t('Multisite network')) ?></div>
    <div id="te_ms_sites" style="font-size:12px;margin-bottom:8px"></div>
    <div class="row">
        <div class="field" style="margin:0"><label><?= h(t('Sub-site path/subdomain')) ?></label><input type="text" id="te_ms_slug" placeholder="site2"></div>
        <div class="field" style="margin:0"><label><?= h(t('Title')) ?></label><input type="text" id="te_ms_title" placeholder="Second site"></div>
    </div>
    <div class="row">
        <div class="field" style="margin:0"><label><?= h(t('Admin email')) ?></label><input type="email" id="te_ms_email" placeholder="admin@example.com"></div>
        <div class="field" style="margin:0"><label>&nbsp;</label><button class="btn btn-sm btn-success" style="margin-top:0" onclick="teMsAddSite()">➕ <?= h(t('Add sub-site')) ?></button></div>
    </div>
</div>
<div id="te_results" style="margin-bottom:10px"></div>

<div style="border-top:1px solid var(--border);margin-top:12px;padding-top:12px">
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px"><?= h(t('Vet a new plugin from wordpress.org')) ?></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input type="text" id="te_plugin_q" placeholder="<?= h(t('Plugin name…')) ?>" style="flex:1;min-width:200px;padding:6px 10px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;color:var(--text);font-size:13px">
        <button class="btn btn-sm btn-primary" onclick="tePluginSearch()">🔍 <?= h(t('Search')) ?></button>
        <button class="btn btn-sm" style="background:var(--border);color:var(--text-muted)" onclick="tePluginPopular()">⭐ <?= h(t('Popular')) ?></button>
    </div>
    <div id="te_plugin_results" style="margin-top:8px;font-size:12px"></div>
</div>
<div id="te_log" class="log" style="display:none;margin-top:12px"></div>
</div>

<!-- DISK CLEANUP & APACHE TESTS -->
<div class="card" style="border-color:#78350f44">
<h2>🧽 <?= h(t('Disk cleanup & Apache capabilities')) ?></h2>

<div style="border:1px solid var(--border);border-radius:6px;padding:12px;margin-bottom:12px">
    <div style="font-weight:600;font-size:13px;margin-bottom:4px">🗑️ <?= h(t('Orphan assets (on disk, not in the database)')) ?></div>
    <div style="font-size:11px;color:var(--text-dim);margin-bottom:8px"><?= h(t('Finds uploads not referenced anywhere in the database. They are moved to wp-content/_orphans-remove-it with a restore log (not deleted).')) ?></div>
    <div class="actions"><button class="btn btn-sm btn-primary" id="orph_btn" onclick="orphansScan()">📊 <?= h(t('Scan orphans')) ?></button>
        <button class="btn btn-sm btn-danger" id="orph_q" onclick="orphansQuarantine()" style="display:none">🗑️ <?= h(t('Move to pre-delete')) ?></button></div>
    <div id="orph_result" style="font-size:12px;margin-top:6px"></div>
</div>

<div style="border:1px solid var(--border);border-radius:6px;padding:12px;margin-bottom:12px">
    <div style="font-weight:600;font-size:13px;margin-bottom:4px">📦 <?= h(t('Large / suspicious files')) ?></div>
    <div style="font-size:11px;color:var(--text-dim);margin-bottom:8px"><?= h(t('Finds logs, backups, archives and oversized files that are usually safe to remove. Moved to _junk-remove-it with a restore log.')) ?></div>
    <div class="actions" style="align-items:center">
        <label style="font-size:12px;color:var(--text-muted)"><?= h(t('Min size')) ?>:
            <select id="junk_min" style="padding:5px 8px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;color:var(--text);font-size:12px">
                <option value="5">5 MB</option><option value="10" selected>10 MB</option><option value="50">50 MB</option><option value="100">100 MB</option>
            </select></label>
        <button class="btn btn-sm btn-primary" id="junk_btn" onclick="junkScan()">📊 <?= h(t('Scan files')) ?></button>
        <button class="btn btn-sm btn-danger" id="junk_q" onclick="junkQuarantine()" style="display:none">🗑️ <?= h(t('Move selected to pre-delete')) ?></button>
    </div>
    <div id="junk_result" style="font-size:12px;margin-top:6px"></div>
</div>

<div style="border:1px solid var(--border);border-radius:6px;padding:12px">
    <div style="font-weight:600;font-size:13px;margin-bottom:4px">🧪 <?= h(t('Apache capability tests')) ?></div>
    <div style="font-size:11px;color:var(--text-dim);margin-bottom:8px"><?= h(t('Tests which Apache/.htaccess features the host supports (rewrite, redirects, Basic auth, gzip, caching, headers) in isolated subfolders — never touching the live .htaccess.')) ?></div>
    <div class="actions"><button class="btn btn-sm btn-primary" id="ht_btn" onclick="htaccessTests()">🧪 <?= h(t('Run capability tests')) ?></button></div>
    <div id="ht_result" style="font-size:12px;margin-top:6px"></div>
</div>
<div id="cleanup_log" class="log" style="display:none;margin-top:12px"></div>
</div>

<!-- PERFORMANCE -->
<div class="card" style="border-color:#1e3a2f">
<h2>🚀 <?= h(t('Performance')) ?></h2>
<p style="font-size:12px;color:var(--text-dim);margin-bottom:12px">
    <?= h(t('Speed up the site with browser compression and caching (via .htaccess, tested safely first), an optional static HTML snapshot cache, and a performance check — both a quick internal measurement and links to external tools.')) ?>
</p>

<div style="border:1px solid var(--border);border-radius:6px;padding:12px;margin-bottom:12px">
    <div style="font-weight:600;font-size:13px;margin-bottom:6px">⚙️ <?= h(t('Browser optimizations (.htaccess)')) ?> <span id="perf_status" style="font-size:11px"></span></div>
    <div style="display:flex;flex-direction:column;gap:4px;font-size:13px;margin-bottom:8px">
        <label><input type="checkbox" class="perf-opt" value="gzip" checked> <?= h(t('Gzip/deflate compression')) ?></label>
        <label><input type="checkbox" class="perf-opt" value="cache" checked> <?= h(t('Browser cache (Expires/Cache-Control)')) ?></label>
        <label><input type="checkbox" class="perf-opt" value="headers" checked> <?= h(t('Cache headers and ETag tuning')) ?></label>
    </div>
    <div class="actions"><button class="btn btn-sm btn-success" onclick="perfEnable()">⚡ <?= h(t('Apply optimizations')) ?></button>
        <button class="btn btn-sm" style="background:var(--border);color:var(--text-muted)" onclick="perfDisable()"><?= h(t('Disable')) ?></button></div>
</div>

<div style="border:1px solid var(--border);border-radius:6px;padding:12px;margin-bottom:12px">
    <div style="font-weight:600;font-size:13px;margin-bottom:4px">📄 <?= h(t('Static page cache')) ?></div>
    <div style="font-size:11px;color:var(--text-dim);margin-bottom:8px"><?= h(t('Saves an HTML snapshot of published pages to wp-content/cache (a static full-page cache).')) ?></div>
    <div class="actions"><button class="btn btn-sm btn-primary" onclick="perfStaticCache()">📄 <?= h(t('Generate static cache')) ?></button></div>
</div>

<div style="border:1px solid var(--border);border-radius:6px;padding:12px">
    <div style="font-weight:600;font-size:13px;margin-bottom:6px">📈 <?= h(t('Performance check')) ?></div>
    <div class="actions" style="margin-bottom:6px"><button class="btn btn-sm btn-primary" onclick="perfCheck()">📈 <?= h(t('Run internal check')) ?></button></div>
    <div id="perf_check_result" style="font-size:12px;margin-bottom:8px"></div>
    <div id="perf_ext_links" style="font-size:12px"></div>
</div>
<div id="perf_log" class="log" style="display:none;margin-top:12px"></div>
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

// Quick Update: runs the guarded one-click post-migration flow with a per-step log.
async function runQuickUpdate(){
    const steps = Array.from(document.querySelectorAll('.qu-step:checked')).map(c => c.value);
    if (!steps.length){ alert('Select at least one step.'); return; }
    const summary = steps.map(s => '• ' + s).join('\n');
    if (!confirm('Run quick update?\n\nThis will:\n' + summary + '\n\nwp-config.php is backed up first. Make sure you have a database backup too.')) return;
    const btn = document.getElementById('qu_btn');
    btn.disabled = true; btn.textContent = '⏳ Running…';
    const fd = new FormData();
    fd.append('db_host', document.getElementById('qu_db_host').value);
    fd.append('db_name', document.getElementById('qu_db_name').value);
    fd.append('db_user', document.getElementById('qu_db_user').value);
    fd.append('db_pass', document.getElementById('qu_db_pass').value);
    fd.append('db_prefix', document.getElementById('qu_db_prefix').value);
    fd.append('new_url', window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, ''));
    steps.forEach(s => fd.append('steps[]', s));
    try {
        const r = await fetch('?action=quick_update', {method:'POST', body:fd});
        const j = await r.json();
        if (!j.ok){ log('qu_log', '✗ ' + (j.error||'failed'), false); }
        else {
            const lines = j.report.map(s => (s.ok ? '✓' : '✗') + ' [' + s.step + '] ' + s.msg).join('\n');
            log('qu_log', lines, false);
        }
    } catch(e){ log('qu_log', '✗ ' + e.message, false); }
    btn.disabled = false; btn.textContent = '⚡ Run quick update';
}

// --- Quick diagnostics: run each check in sequence with a live progress bar ---
async function runQuickDiagnostics(){
    const btn = document.getElementById('diag_btn');
    const out = document.getElementById('diag_results');
    const prog = document.getElementById('diag_progress'), bar = document.getElementById('diag_bar'), ptxt = document.getElementById('diag_ptxt');
    btn.disabled = true; out.innerHTML = ''; prog.style.display = 'block';
    const checks = [
        ['diag_db', T_DIAG.db], ['diag_url', T_DIAG.url], ['diag_tables', T_DIAG.tables],
        ['diag_maintenance', T_DIAG.maintenance], ['diag_plugins', T_DIAG.plugins], ['diag_cache', T_DIAG.cache],
        ['diag_htaccess', T_DIAG.htaccess], ['diag_phpconfig', T_DIAG.phpconfig], ['diag_passwords', T_DIAG.passwords],
        ['diag_redirects', T_DIAG.redirects],
    ];
    const icon = {ok:'✅', warn:'⚠️', error:'❌'};
    const color = {ok:'#6ee7b7', warn:'#fcd34d', error:'#f87171'};
    const fixes = [];
    for (let i=0; i<checks.length; i++){
        const [action, label] = checks[i];
        ptxt.textContent = `${i+1}/${checks.length} — ${label}`;
        const fd = new FormData();
        ['host','name','user','pass','prefix'].forEach(k => fd.append('db_'+k, document.getElementById('qu_db_'+k)?.value || document.getElementById('tls_db_'+k)?.value || ''));
        let j;
        try { j = await (await fetch('?action='+action, {method:'POST', body:fd})).json(); } catch(e){ j = {status:'warn', text:'check failed: '+e.message}; }
        const s = j.status || (j.ok===false?'error':'warn');
        out.innerHTML += `<div style="padding:4px 0;border-bottom:1px solid var(--border);color:${color[s]||'#a0aec0'}">${icon[s]||'•'} <strong>${esc(label)}</strong>: ${esc(j.text||j.error||'')}</div>`;
        if (j.fix) fixes.push(j.fix);
        bar.style.width = Math.round((i+1)/checks.length*100) + '%';
    }
    ptxt.textContent = T_DIAG.done;
    // Offer one-click fixes for the issues found.
    if (fixes.length){
        let fb = `<div style="margin-top:10px;font-size:12px;color:var(--text-muted)">${T_DIAG.fixesAvail}:</div><div class="actions" style="margin-top:6px">`;
        if (fixes.includes('cache')) fb += `<button class="btn btn-sm btn-warning" onclick="diagFixCache()">🗑️ ${T_DIAG.fixCache}</button>`;
        if (fixes.includes('maintenance')) fb += `<button class="btn btn-sm btn-warning" onclick="diagFixMaintenance()">🚧 ${T_DIAG.fixMaint}</button>`;
        if (fixes.includes('siteurl')) fb += `<span style="font-size:11px;color:var(--text-dim);align-self:center">↑ ${T_DIAG.fixUrl}</span>`;
        if (fixes.includes('htaccess')) fb += `<span style="font-size:11px;color:var(--text-dim);align-self:center">${T_DIAG.fixHt}</span>`;
        fb += '</div>';
        out.innerHTML += fb;
    }
    btn.disabled = false;
}
async function diagFixCache(){
    const fd = new FormData(); Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    const j = await (await fetch('?action=clear_cache', {method:'POST', body:fd})).json();
    log('diag_log', j.ok ? `✓ Cache cleared (${j.deleted} files)` : '✗ cache clear failed', false);
}
async function diagFixMaintenance(){
    const fd = new FormData(); Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v)); fd.append('enable','0');
    const j = await (await fetch('?action=wp_toggle_maintenance', {method:'POST', body:fd})).json();
    log('diag_log', j.ok ? '✓ Maintenance mode lifted' : '✗ '+j.error, false);
}
const T_DIAG = {
    db: <?= json_encode(t('Database')) ?>, url: <?= json_encode(t('Site URL')) ?>, tables: <?= json_encode(t('WordPress tables')) ?>,
    maintenance: <?= json_encode(t('Maintenance mode')) ?>, plugins: <?= json_encode(t('Plugins')) ?>, cache: <?= json_encode(t('Cache')) ?>,
    htaccess: <?= json_encode(t('.htaccess')) ?>, phpconfig: <?= json_encode(t('PHP config')) ?>, passwords: <?= json_encode(t('Password lock')) ?>,
    redirects: <?= json_encode(t('Redirects / index.html')) ?>, done: <?= json_encode(t('Diagnostics complete')) ?>,
    fixesAvail: <?= json_encode(t('Fixes available')) ?>, fixCache: <?= json_encode(t('Clear cache')) ?>, fixMaint: <?= json_encode(t('Lift maintenance')) ?>,
    fixUrl: <?= json_encode(t('Use Quick Update to fix the site URL')) ?>, fixHt: <?= json_encode(t('Recreate .htaccess from WordPress Extras')) ?>,
};

// --- Site snapshots: save/restore the important config + active plugins ---
function snapCreds(fd){ ['host','name','user','pass','prefix'].forEach(k => fd.append('db_'+k, document.getElementById('qu_db_'+k)?.value || document.getElementById('tls_db_'+k)?.value || '')); return fd; }
async function createSnapshot(){
    const btn = document.getElementById('snap_btn'); btn.disabled = true;
    const fd = snapCreds(new FormData()); fd.append('note', document.getElementById('snap_note').value.trim());
    try {
        const j = await (await fetch('?action=snapshot_create', {method:'POST', body:fd})).json();
        if (j.ok){ log('snap_log', `✓ ${T_SNAP.saved}: ${j.id} (${j.files.length} ${T_SNAP.files}, ${j.active_count} ${T_SNAP.plugins})`, false); document.getElementById('snap_note').value=''; loadSnapshots(); }
        else log('snap_log', '✗ ' + (j.error||''), false);
    } catch(e){ log('snap_log', '✗ ' + e.message, false); }
    btn.disabled = false;
}
async function loadSnapshots(){
    const box = document.getElementById('snap_list');
    try {
        const j = await (await fetch('?action=snapshot_list', {method:'POST', body:new FormData()})).json();
        if (!j.ok || !j.snapshots.length){ box.innerHTML = `<div style="font-size:11px;color:var(--text-dim)">${T_SNAP.none}</div>`; return; }
        let html = '';
        for (const s of j.snapshots){
            const d = s.date ? new Date(s.date).toLocaleString() : s.id;
            html += `<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--border)">
                <span style="flex:1">📅 <strong>${esc(d)}</strong>${s.note?` — <span style="color:var(--text-muted)">${esc(s.note)}</span>`:''}
                <br><span style="font-size:11px;color:var(--text-dim)">${s.files.length} ${T_SNAP.files} · ${s.active_count} ${T_SNAP.plugins}${s.db_available?'':' · '+T_SNAP.noDb}</span></span>
                <button class="btn btn-sm btn-primary" onclick="restoreSnapshot('${esc(s.id)}', this)">↺ ${T_SNAP.restore}</button>
                <button class="btn btn-sm btn-danger" onclick="deleteSnapshot('${esc(s.id)}')">🗑️</button>
            </div>`;
        }
        box.innerHTML = html;
    } catch(e){ box.innerHTML = `<div style="font-size:11px;color:#f87171">${esc(e.message)}</div>`; }
}
async function restoreSnapshot(id, btn){
    if (!confirm(T_SNAP.confirmRestore)) return;
    btn.disabled = true;
    const fd = snapCreds(new FormData()); fd.append('id', id);
    try {
        const j = await (await fetch('?action=snapshot_restore', {method:'POST', body:fd})).json();
        if (j.ok) log('snap_log', `✓ ${T_SNAP.restored}: ${j.restored.join(', ')||'—'}${j.plugins_restored?' + '+T_SNAP.plugins:''}${j.errors.length?' ('+T_SNAP.failed+': '+j.errors.join(', ')+')':''}${j.safety_id?' · '+T_SNAP.safety+' '+j.safety_id:''}`, false);
        else log('snap_log', '✗ ' + (j.error||''), false);
    } catch(e){ log('snap_log', '✗ ' + e.message, false); }
    btn.disabled = false; loadSnapshots();
}
async function deleteSnapshot(id){
    if (!confirm(T_SNAP.confirmDelete)) return;
    const fd = new FormData(); fd.append('id', id);
    const j = await (await fetch('?action=snapshot_delete', {method:'POST', body:fd})).json();
    log('snap_log', j.ok ? `✓ ${T_SNAP.deleted}: ${id}` : '✗ ' + (j.error||''), false);
    loadSnapshots();
}
const T_SNAP = {
    saved: <?= json_encode(t('Snapshot saved')) ?>, files: <?= json_encode(t('files')) ?>, plugins: <?= json_encode(t('active plugins')) ?>,
    none: <?= json_encode(t('No snapshots yet')) ?>, restore: <?= json_encode(t('Restore')) ?>, restored: <?= json_encode(t('Restored')) ?>,
    deleted: <?= json_encode(t('Deleted')) ?>, failed: <?= json_encode(t('failed')) ?>, noDb: <?= json_encode(t('no DB')) ?>,
    safety: <?= json_encode(t('safety snapshot')) ?>,
    confirmRestore: <?= json_encode(t('Restore this snapshot? The current config files and active plugins will be overwritten (a safety snapshot is taken first).')) ?>,
    confirmDelete: <?= json_encode(t('Delete this snapshot permanently?')) ?>,
};
document.addEventListener('DOMContentLoaded', loadSnapshots);

// --- Asset recovery from the original site ---
let AR_ABORT = false;
let AR_MISSING = [];
function arCreds(fd){ ['host','name','user','pass','prefix'].forEach(k => fd.append('db_'+k, document.getElementById('qu_db_'+k)?.value || '')); return fd; }

async function saveOrigin(){
    const fd = new FormData(); fd.append('origin', document.getElementById('ar_origin').value.trim());
    const r = await fetch('?action=set_origin_domain', {method:'POST', body:fd});
    const j = await r.json();
    log('ar_log', j.ok ? '✓ Origin saved: ' + (j.origin||'(cleared)') : '✗ ' + j.error, false);
}

async function scanMissingAssets(){
    const btn = document.getElementById('ar_scan_btn');
    btn.disabled = true; btn.textContent = '⏳';
    log('ar_log', '⏳ Scanning database for asset references…', false);
    const fd = arCreds(new FormData());
    try {
        const r = await fetch('?action=assets_scan', {method:'POST', body:fd});
        const j = await r.json();
        if (!j.ok){ log('ar_log', '✗ ' + j.error, false); }
        else {
            AR_MISSING = j.missing || [];
            log('ar_log', `Scanned ${j.total_refs} asset reference(s): ${j.missing_count} missing on disk.`, false);
            renderMissingAssets();
            const rbtn = document.getElementById('ar_recover_btn');
            if (AR_MISSING.length && document.getElementById('ar_origin').value.trim()) rbtn.style.display = 'inline-flex';
            else if (AR_MISSING.length) log('ar_log', 'Set the original site URL above to recover them.', false);
        }
    } catch(e){ log('ar_log', '✗ ' + e.message, false); }
    btn.disabled = false; btn.textContent = '🔍 Scan for missing assets';
}

// Lists the exact missing asset paths found by the scan, so the user can see which
// files are gone (and copy them) instead of only a count.
function renderMissingAssets(){
    const box = document.getElementById('ar_missing_list');
    const items = document.getElementById('ar_missing_items');
    const title = document.getElementById('ar_missing_title');
    if (!AR_MISSING.length){ box.style.display = 'none'; items.innerHTML = ''; return; }
    title.textContent = `${AR_MISSING.length} ${T_AR.missingFiles}`;
    items.innerHTML = AR_MISSING.map((p, i) =>
        `<div id="ar_miss_${i}" style="padding:1px 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${esc(p)}">${esc(p)}</div>`
    ).join('');
    box.style.display = 'block';
}
function copyMissingAssets(btn){
    const text = AR_MISSING.join('\n');
    navigator.clipboard?.writeText(text).then(() => {
        const old = btn.textContent; btn.textContent = '✓ ' + T_AR.copied;
        setTimeout(() => { btn.textContent = old; }, 1500);
    });
}

async function recoverAssets(){
    if (!AR_MISSING.length){ return; }
    const origin = document.getElementById('ar_origin').value.trim();
    if (!origin){ alert('Set the original site URL first.'); return; }
    AR_ABORT = false;
    const stop = document.getElementById('ar_stop'); stop.style.display = 'inline-flex';
    const prog = document.getElementById('ar_progress'); prog.style.display = 'block';
    const bar = document.getElementById('ar_bar'); const ptxt = document.getElementById('ar_progress_txt');
    let done = 0, ok = 0, fail = 0;
    const BATCH = 5;
    for (let i = 0; i < AR_MISSING.length && !AR_ABORT; i += BATCH){
        const slice = AR_MISSING.slice(i, i + BATCH);
        const fd = new FormData(); fd.append('origin', origin); fd.append('paths', JSON.stringify(slice));
        try {
            const r = await fetch('?action=asset_recover', {method:'POST', body:fd});
            const j = await r.json();
            if (j.ok) j.results.forEach(res => {
                res.ok ? ok++ : fail++;
                // Mark the matching row so the user sees exactly what was recovered.
                const idx = AR_MISSING.indexOf(res.path);
                const row = idx >= 0 && document.getElementById('ar_miss_' + idx);
                if (row){ row.style.color = res.ok ? '#6ee7b7' : '#f87171'; row.textContent = (res.ok ? '✓ ' : '✗ ') + row.textContent.replace(/^[✓✗]\s*/, ''); }
            });
        } catch(e){ fail += slice.length; }
        done += slice.length;
        const pct = Math.round(done / AR_MISSING.length * 100);
        bar.style.width = pct + '%';
        ptxt.textContent = `${done}/${AR_MISSING.length} (${pct}%) — ${ok} recovered, ${fail} not found`;
    }
    stop.style.display = 'none';
    log('ar_log', `✓ Done. Recovered ${ok}, not found ${fail}${AR_ABORT ? ' (stopped)' : ''}. Re-scan to confirm.`, false);
}

// --- Automatic asset recovery (.htaccess fallback) ---
async function autoRecoverStatus(){
    try {
        const r = await fetch('?action=autorecover_status', {method:'POST'});
        const j = await r.json();
        if (j.ok){
            const el = document.getElementById('ar_auto_status');
            el.innerHTML = j.active ? `<span class="badge badge-ok">${T_AR.active}</span>` : `<span class="badge" style="background:var(--border);color:var(--text-muted)">${T_AR.inactive}</span>`;
            if (j.miss) document.getElementById('ar_miss').value = j.miss;
        }
    } catch(e){}
}
async function autoRecoverEnable(){
    const origin = document.getElementById('ar_origin').value.trim();
    if (!origin){ alert(T_AR.needOrigin); return; }
    const fd = new FormData(); fd.append('origin', origin); fd.append('miss', document.getElementById('ar_miss').value);
    const r = await fetch('?action=autorecover_enable', {method:'POST', body:fd});
    const j = await r.json();
    if (j.ok){ log('ar_log', `✓ ${T_AR.enabled} (origin: ${origin}, test: ${j.tested}).`, false); autoRecoverStatus(); }
    else log('ar_log', '✗ ' + j.error, false);
}
async function autoRecoverDisable(){
    const r = await fetch('?action=autorecover_disable', {method:'POST'});
    const j = await r.json();
    if (j.ok){ log('ar_log', `✓ ${T_AR.disabled}`, false); autoRecoverStatus(); }
}
const T_AR = {
    active: <?= json_encode(t('Active')) ?>, inactive: <?= json_encode(t('Inactive')) ?>,
    enabled: <?= json_encode(t('Auto-recovery enabled')) ?>, disabled: <?= json_encode(t('Auto-recovery disabled')) ?>,
    needOrigin: <?= json_encode(t('Set the original site URL first.')) ?>,
    missingFiles: <?= json_encode(t('missing file(s)')) ?>, copied: <?= json_encode(t('Copied')) ?>,
};
document.addEventListener('DOMContentLoaded', () => { if (document.getElementById('ar_auto_status')) autoRecoverStatus(); });

// --- Test Environment (sandbox) ---
let TE_ABORT = false;
let TE_SANDBOX = null; // {folder, url, dst_prefix, link_method}
function teCreds(fd){ ['host','name','user','pass','prefix'].forEach(k => fd.append('db_'+k, document.getElementById('tls_db_'+k)?.value || '')); return fd; }

async function teBuild(){
    const btn = document.getElementById('te_build_btn');
    btn.disabled = true; btn.textContent = '⏳';
    const bar = document.getElementById('te_bar'), ptxt = document.getElementById('te_progress_txt');
    document.getElementById('te_progress').style.display = 'block';
    document.getElementById('te_results').innerHTML = '';
    // 1) list tables
    log('te_log', '⏳ Listing tables…', false);
    let start;
    try { const r = await fetch('?action=clone_tables_start', {method:'POST', body:teCreds(new FormData())}); start = await r.json(); }
    catch(e){ log('te_log','✗ '+e.message,false); btn.disabled=false; btn.textContent='🏗️ Build sandbox'; return; }
    if (!start.ok){ log('te_log','✗ '+start.error,false); btn.disabled=false; btn.textContent='🏗️ Build sandbox'; return; }
    // 2) replicate table by table with a progress bar
    let done = 0;
    for (const tb of start.tables){
        const fd = teCreds(new FormData()); fd.append('table', tb.name);
        try { await (await fetch('?action=clone_table_one', {method:'POST', body:fd})).json(); } catch(e){}
        done++;
        const pct = Math.round(done / start.tables.length * 100);
        bar.style.width = pct + '%';
        ptxt.textContent = `${T_TE.replicating} ${done}/${start.tables.length} — ${tb.name} (${tb.rows} ${T_TE.rows})`;
    }
    // 3) create the sandbox folder (optionally as a multisite network)
    ptxt.textContent = T_TE.creatingFolder;
    const cf = teCreds(new FormData());
    const ms = document.getElementById('te_multisite').checked;
    if (ms){ cf.append('multisite', '1'); cf.append('ms_type', document.getElementById('te_ms_type').value); }
    let sb;
    try { const r = await fetch('?action=sandbox_create', {method:'POST', body:cf}); sb = await r.json(); }
    catch(e){ log('te_log','✗ '+e.message,false); btn.disabled=false; btn.textContent='🏗️ Build sandbox'; return; }
    if (!sb.ok){ log('te_log','✗ '+sb.error,false); btn.disabled=false; btn.textContent='🏗️ Build sandbox'; return; }
    TE_SANDBOX = sb;
    const dbinfo = sb.db || {};
    const dbline = dbinfo.siteurl
        ? `<span class="badge badge-ok">DB: siteurl set, ${dbinfo.relativized} ${T_TE.relativized}</span>`
        : `<span class="badge badge-err">DB: ${esc(dbinfo.error || 'not updated')}</span>`;
    const msline = sb.multisite ? ` · <span class="badge badge-ok">${T_TE.multisite}</span>` : '';
    document.getElementById('te_sandbox_info').innerHTML =
        `✓ ${T_TE.sandboxReady}: <a href="${sb.url}/" target="_blank" style="color:#7dd3fc;font-family:monospace">${esc(sb.folder)}/</a><br>` +
        `<div style="margin-top:4px;font-size:11px;color:var(--text-dim)">` +
        `${T_TE.prefix}: <code>${esc(sb.dst_prefix)}</code> · ` +
        `<span class="badge ${sb.link_method==='symlink'?'badge-ok':'badge-warn'}">${sb.link_method}</span> ` +
        `${sb.symlinks} ${T_TE.symlinks}, ${sb.copied} ${T_TE.copiedFiles} · ${dbline}${msline}</div>`;
    log('te_log', `✓ ${start.count} tables replicated; sandbox created (${sb.symlinks} symlinks, ${sb.copied} copied); DB ${dbinfo.siteurl?'fixed':'NOT fixed'}${sb.multisite?'; multisite':''}.`, false);
    document.getElementById('te_plugins_btn').style.display = 'inline-flex';
    document.getElementById('te_allplugins_btn').style.display = 'inline-flex';
    document.getElementById('te_destroy_btn').style.display = 'inline-flex';
    // Multisite management card.
    if (sb.multisite){ document.getElementById('te_ms_card').style.display = 'block'; teMsListSites(); }
    else document.getElementById('te_ms_card').style.display = 'none';
    btn.disabled = false; btn.textContent = '🏗️ Rebuild sandbox';
}

// Test ALL plugins at once, then bisect to isolate the breaking plugin(s).
async function teTestAllPlugins(){
    if (!TE_SANDBOX){ alert('Build the sandbox first.'); return; }
    let pl;
    try { pl = await (await fetch('?action=sandbox_plugins_list', {method:'POST', body:teCreds(new FormData())})).json(); } catch(e){ log('te_log','✗ '+e.message,false); return; }
    if (!pl.ok){ log('te_log','✗ '+pl.error,false); return; }
    const all = pl.plugins.map(p=>p.file);
    const out = document.getElementById('te_results');
    const setAndTest = async (list) => {
        const fd = teCreds(new FormData()); fd.append('plugins', JSON.stringify(list)); fd.append('sandbox_url', TE_SANDBOX.url);
        try { return await (await fetch('?action=sandbox_plugins_set', {method:'POST', body:fd})).json(); } catch(e){ return {ok:false,breaks:true}; }
    };
    out.innerHTML = `<span style="color:var(--text-dim)">⏳ ${T_TE.testingAll} (${all.length})…</span>`;
    const full = await setAndTest(all);
    if (!full.ok){ out.innerHTML = `<span style="color:#f87171">✗ ${full.error||'test failed'}</span>`; await setAndTest([]); return; }
    if (!full.breaks){
        out.innerHTML = `<div style="color:#6ee7b7">✓ ${T_TE.allTogetherOk} (${all.length} plugins, HTTP ${full.status}).</div>`;
        await setAndTest([]); return;
    }
    // Bisect: find a minimal breaking subset.
    out.innerHTML = `<span style="color:#fcd34d">⚠ ${T_TE.breaksAll} — ${T_TE.bisecting}…</span>`;
    // Confirm each plugin alone is fine; collect the culprit via halving.
    let suspects = all.slice();
    while (suspects.length > 1){
        const half = Math.ceil(suspects.length/2);
        const a = suspects.slice(0, half), b = suspects.slice(half);
        const ra = await setAndTest(a);
        if (ra.breaks){ suspects = a; continue; }       // culprit in first half
        const rb = await setAndTest(b);
        if (rb.breaks){ suspects = b; continue; }        // culprit in second half
        break; // neither half breaks alone -> it's a conflict across halves; stop at current set
    }
    await setAndTest([]); // restore clean
    const names = suspects.map(f => (pl.plugins.find(p=>p.file===f)||{}).name || f);
    out.innerHTML = suspects.length === 1
        ? `<div style="color:#fca5a5">⚠ ${T_TE.culprit}: <strong>${esc(names[0])}</strong></div>`
        : `<div style="color:#fca5a5">⚠ ${T_TE.minimalSet}: ${esc(names.join(', '))}</div><div style="font-size:11px;color:var(--text-dim)">${T_TE.conflictNote}</div>`;
    log('te_log', `✓ All-plugins test: breaking subset = ${names.join(', ')}`, false);
}

// Multisite sub-site management.
async function teMsListSites(){
    const el = document.getElementById('te_ms_sites');
    let j; try { j = await (await fetch('?action=sandbox_ms_list_sites', {method:'POST', body:teCreds(new FormData())})).json(); } catch(e){ return; }
    if (!j.ok || !j.multisite){ el.innerHTML = ''; return; }
    el.innerHTML = `<div style="color:var(--text-dim);margin-bottom:4px">${T_TE.subSites} (${j.sites.length}):</div>` +
        j.sites.map(s => `<div style="font-family:monospace;font-size:11px">#${s.blog_id} <a href="${s.url}" target="_blank" style="color:#7dd3fc">${esc(s.url)}</a></div>`).join('');
}
async function teMsAddSite(){
    const slug = document.getElementById('te_ms_slug').value.trim();
    if (!slug){ alert(T_TE.needSlug); return; }
    const fd = teCreds(new FormData());
    fd.append('slug', slug); fd.append('title', document.getElementById('te_ms_title').value || slug);
    fd.append('email', document.getElementById('te_ms_email').value || 'admin@example.com');
    fd.append('ms_type', document.getElementById('te_ms_type').value);
    const j = await (await fetch('?action=sandbox_ms_add_site', {method:'POST', body:fd})).json();
    log('te_log', j.ok ? `✓ ${T_TE.subSiteAdded} #${j.blog_id}: ${j.url} (${j.tables_created} tables)` : `✗ ${j.error}`, false);
    if (j.ok){ document.getElementById('te_ms_slug').value=''; teMsListSites(); }
}

async function teSmokeTestPlugins(){
    if (!TE_SANDBOX){ alert('Build the sandbox first.'); return; }
    TE_ABORT = false;
    document.getElementById('te_stop').style.display = 'inline-flex';
    // list plugins (+ PHP compat)
    let pl;
    try { const r = await fetch('?action=sandbox_plugins_list', {method:'POST', body:teCreds(new FormData())}); pl = await r.json(); }
    catch(e){ log('te_log','✗ '+e.message,false); return; }
    if (!pl.ok){ log('te_log','✗ '+pl.error,false); return; }
    const bar = document.getElementById('te_bar'), ptxt = document.getElementById('te_progress_txt');
    document.getElementById('te_progress').style.display = 'block';
    const rows = [];
    let done = 0;
    for (const p of pl.plugins){
        if (TE_ABORT) break;
        const fd = teCreds(new FormData()); fd.append('plugin', p.file); fd.append('sandbox_url', TE_SANDBOX.url);
        let res = {loads:false, breaks:true, status:0};
        try { res = await (await fetch('?action=sandbox_plugin_test', {method:'POST', body:fd})).json(); } catch(e){}
        rows.push({name:p.name, file:p.file, requires_php:p.requires_php, php_ok:p.php_ok, loads:res.loads, breaks:res.breaks, status:res.status});
        done++;
        bar.style.width = Math.round(done/pl.plugins.length*100) + '%';
        ptxt.textContent = `${T_TE.testingPlugins} ${done}/${pl.plugins.length} — ${p.name}`;
    }
    document.getElementById('te_stop').style.display = 'none';
    // render report
    let html = `<div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">${T_TE.reportTitle} (PHP ${pl.server_php})</div>`;
    html += '<table style="width:100%;border-collapse:collapse;font-size:12px"><tr style="color:var(--text-dim);text-align:left"><th style="padding:4px 8px">Plugin</th><th style="padding:4px 8px">PHP</th><th style="padding:4px 8px">Test</th></tr>';
    rows.forEach(r => {
        const phpBadge = r.requires_php ? (r.php_ok ? `<span class="badge badge-ok">≥${r.requires_php} ✓</span>` : `<span class="badge badge-err">needs ${r.requires_php}</span>`) : '<span style="color:var(--text-dim)">—</span>';
        const testBadge = r.breaks ? `<span class="badge badge-err">✗ ${r.status||'fatal'}</span>` : (r.loads ? '<span class="badge badge-ok">✓ loads</span>' : `<span class="badge badge-warn">? ${r.status||'—'}</span>`);
        html += `<tr style="border-top:1px solid var(--border)"><td style="padding:4px 8px">${esc(r.name)}</td><td style="padding:4px 8px">${phpBadge}</td><td style="padding:4px 8px">${testBadge}</td></tr>`;
    });
    html += '</table>';
    const breakers = rows.filter(r => r.breaks).map(r => r.name);
    if (breakers.length) html += `<div style="margin-top:8px;font-size:12px;color:#fca5a5">⚠ ${T_TE.recommend}: ${esc(breakers.join(', '))}</div>`;
    else html += `<div style="margin-top:8px;font-size:12px;color:#6ee7b7">✓ ${T_TE.allPluginsOk}</div>`;
    document.getElementById('te_results').innerHTML = html;
    log('te_log', `✓ Smoke test done: ${breakers.length} plugin(s) break the sandbox.`, false);
}

function teDecode(s){ const t=document.createElement('textarea'); t.innerHTML=s; return t.value; } // HTML entities in plugin names
function teRenderPlugins(j){
    const out = document.getElementById('te_plugin_results');
    if (!j.ok){ out.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; return; }
    let html = '<table style="width:100%;border-collapse:collapse;font-size:12px">';
    j.plugins.forEach(p => {
        const php = p.requires_php ? (p.php_ok ? `<span class="badge badge-ok">PHP ≥${p.requires_php}</span>` : `<span class="badge badge-err">needs PHP ${p.requires_php}</span>`) : '';
        const inst = p.active_installs ? `<span style="color:var(--text-dim)">${Number(p.active_installs).toLocaleString()}+</span>` : '';
        const canInstall = p.php_ok && p.slug;
        const btn = canInstall
            ? `<button class="btn btn-sm btn-primary" onclick="tePluginInstall('${esc(p.slug)}','${esc(p.download)}')">⬇️ ${T_TE.installBtn}</button>`
            : `<span style="color:#fca5a5;font-size:11px">${T_TE.incompatible}</span>`;
        html += `<tr style="border-top:1px solid var(--border)">
            <td style="padding:4px 8px">${esc(teDecode(p.name))} <span style="color:var(--text-dim)">v${esc(p.version)}</span></td>
            <td style="padding:4px 8px">${php} ${inst}</td>
            <td style="padding:4px 8px;text-align:right">${btn}</td></tr>`;
    });
    html += '</table><div style="font-size:11px;color:var(--text-dim);margin-top:4px">' + T_TE.vetNote + '</div>';
    out.innerHTML = html;
}
async function tePluginSearch(){
    const q = document.getElementById('te_plugin_q').value.trim();
    if (!q) return;
    document.getElementById('te_plugin_results').innerHTML = '<span style="color:var(--text-dim)">⏳</span>';
    const fd = new FormData(); fd.append('q', q);
    try { teRenderPlugins(await (await fetch('?action=plugin_search', {method:'POST', body:fd})).json()); }
    catch(e){ document.getElementById('te_plugin_results').innerHTML = `<span style="color:#f87171">✗ ${e.message}</span>`; }
}
async function tePluginPopular(){
    document.getElementById('te_plugin_results').innerHTML = '<span style="color:var(--text-dim)">⏳ ' + T_TE.loadingPopular + '</span>';
    try { teRenderPlugins(await (await fetch('?action=plugin_popular', {method:'POST'})).json()); }
    catch(e){ document.getElementById('te_plugin_results').innerHTML = `<span style="color:#f87171">✗ ${e.message}</span>`; }
}
async function tePluginInstall(slug, download){
    // Install into the sandbox first (vet), then offer to install live if it passes.
    if (!TE_SANDBOX){ if (!confirm(T_TE.noSandboxInstall)) return; }
    const target = TE_SANDBOX ? 'sandbox' : 'live';
    const fd = new FormData(); fd.append('slug', slug); fd.append('download', download || ''); fd.append('target', target);
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    log('te_log', `⏳ ${T_TE.installing} ${slug} (${target})…`, false);
    let j; try { j = await (await fetch('?action=plugin_install', {method:'POST', body:fd})).json(); } catch(e){ log('te_log','✗ '+e.message,false); return; }
    if (!j.ok){ log('te_log', '✗ ' + j.error, false); return; }
    log('te_log', `✓ ${slug}: ${j.note}`, false);
    // If installed in the sandbox, smoke-test just this plugin right away.
    if (target === 'sandbox' && j.main && TE_SANDBOX){
        const tf = teCreds(new FormData()); tf.append('plugin', j.main); tf.append('sandbox_url', TE_SANDBOX.url);
        let t; try { t = await (await fetch('?action=sandbox_plugin_test', {method:'POST', body:tf})).json(); } catch(e){ return; }
        if (t && t.ok){
            if (t.breaks){ log('te_log', `⚠ ${slug} ${T_TE.breaksSandbox} (HTTP ${t.status}). ${T_TE.notLive}`, false); }
            else {
                log('te_log', `✓ ${slug} ${T_TE.loadsSandbox}.`, false);
                if (confirm(T_TE.offerLive.replace('%s', slug))){
                    const lf = new FormData(); lf.append('slug', slug); lf.append('download', download||''); lf.append('target','live');
                    const l = await (await fetch('?action=plugin_install', {method:'POST', body:lf})).json();
                    log('te_log', l.ok ? `✓ ${slug}: ${l.note}` : `✗ ${l.error}`, false);
                }
            }
        }
    }
}

async function teDestroy(){
    if (!confirm(T_TE.confirmDestroy)) return;
    const fd = teCreds(new FormData()); fd.append('drop_tables', '1');
    const r = await fetch('?action=sandbox_destroy', {method:'POST', body:fd});
    const j = await r.json();
    log('te_log', j.ok ? `✓ Sandbox removed (${j.folders_removed} folder, ${j.tables_dropped} tables).` : `✗ ${j.error}`, false);
    if (j.ok){ TE_SANDBOX = null; document.getElementById('te_sandbox_info').innerHTML=''; document.getElementById('te_results').innerHTML='';
        document.getElementById('te_plugins_btn').style.display='none'; document.getElementById('te_destroy_btn').style.display='none';
        document.getElementById('te_progress').style.display='none'; }
}

// --- Disk cleanup (orphans / junk) + Apache capability tests ---
let ORPH_FILES = [], JUNK_FILES = [];
async function orphansScan(){
    const out = document.getElementById('orph_result'); out.innerHTML = '⏳';
    let j; try { j = await (await fetch('?action=orphans_scan', {method:'POST', body:teCreds(new FormData())})).json(); }
    catch(e){ out.innerHTML = `<span style="color:#f87171">✗ ${e.message}</span>`; return; }
    if (!j.ok){ out.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; return; }
    ORPH_FILES = j.files || [];
    out.innerHTML = `<div>${j.orphans} ${T_CL.orphansFound} / ${j.total} — ↓ ${j.orphan_size}</div>`
        + (j.preview.length ? '<div style="font-family:monospace;font-size:11px;color:var(--text-dim);max-height:140px;overflow:auto;margin-top:4px">'+j.preview.map(p=>'• '+esc(p)).join('<br>')+(j.orphans>j.preview.length?'<br>…':'')+'</div>' : '');
    document.getElementById('orph_q').style.display = j.orphans ? 'inline-flex' : 'none';
}
async function orphansQuarantine(){
    if (!ORPH_FILES.length || !confirm(T_CL.confirmOrph)) return;
    const fd = new FormData(); fd.append('files', JSON.stringify(ORPH_FILES));
    const j = await (await fetch('?action=orphans_quarantine', {method:'POST', body:fd})).json();
    log('cleanup_log', j.ok ? `✓ ${j.moved} ${T_CL.movedTo} ${j.folder}` : `✗ ${j.error}`, false);
    if (j.ok) document.getElementById('orph_q').style.display='none';
}
async function junkScan(){
    const out = document.getElementById('junk_result'); out.innerHTML = '⏳';
    const fd = new FormData(); fd.append('min_mb', document.getElementById('junk_min').value);
    let j; try { j = await (await fetch('?action=junk_scan', {method:'POST', body:fd})).json(); }
    catch(e){ out.innerHTML = `<span style="color:#f87171">✗ ${e.message}</span>`; return; }
    if (!j.ok){ out.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; return; }
    JUNK_FILES = (j.files||[]).map(f=>f.path);
    let html = `<div>${j.count} ${T_CL.filesFound} — ${j.total_size}</div><div style="max-height:160px;overflow:auto;margin-top:4px">`;
    (j.files||[]).slice(0,40).forEach(f=>{ html += `<div style="font-size:11px;font-family:monospace;color:var(--text-dim)">${f.size.padStart?f.size:f.size} · <span class="badge ${f.reason==='large'?'badge-warn':'badge-err'}">${f.reason}</span> ${esc(f.path)}</div>`; });
    html += '</div>'; out.innerHTML = html;
    document.getElementById('junk_q').style.display = j.count ? 'inline-flex' : 'none';
}
async function junkQuarantine(){
    if (!JUNK_FILES.length || !confirm(T_CL.confirmJunk)) return;
    const fd = new FormData(); fd.append('files', JSON.stringify(JUNK_FILES));
    const j = await (await fetch('?action=junk_quarantine', {method:'POST', body:fd})).json();
    log('cleanup_log', j.ok ? `✓ ${j.moved} ${T_CL.movedTo} ${j.folder}` : `✗ ${j.error}`, false);
    if (j.ok) document.getElementById('junk_q').style.display='none';
}
async function htaccessTests(){
    const out = document.getElementById('ht_result'); const btn = document.getElementById('ht_btn');
    btn.disabled = true; out.innerHTML = '⏳ ' + T_CL.testing;
    let j; try { j = await (await fetch('?action=htaccess_tests', {method:'POST'})).json(); }
    catch(e){ out.innerHTML = `<span style="color:#f87171">✗ ${e.message}</span>`; btn.disabled=false; return; }
    if (!j.ok){ out.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; btn.disabled=false; return; }
    let html = '<table style="width:100%;border-collapse:collapse;font-size:12px">';
    j.results.forEach(r=>{
        const b = r.breaks ? '<span class="badge badge-err">✗ 500</span>' : (r.supported ? '<span class="badge badge-ok">✓ supported</span>' : `<span class="badge badge-warn">? ${r.status||'—'}</span>`);
        html += `<tr style="border-top:1px solid var(--border)"><td style="padding:4px 8px">${esc(r.name)}</td><td style="padding:4px 8px;text-align:right">${b}</td></tr>`;
    });
    html += '</table><div style="font-size:11px;color:var(--text-dim);margin-top:4px">'+T_CL.htNote+'</div>';
    out.innerHTML = html; btn.disabled = false;
}
const T_CL = {
    orphansFound: <?= json_encode(t('orphans')) ?>, filesFound: <?= json_encode(t('files')) ?>, movedTo: <?= json_encode(t('file(s) moved to')) ?>,
    confirmOrph: <?= json_encode(t('Move orphan uploads to the pre-delete folder? Reversible via log.')) ?>,
    confirmJunk: <?= json_encode(t('Move these files to the pre-delete folder? Reversible via log.')) ?>,
    testing: <?= json_encode(t('Running Apache tests…')) ?>, htNote: <?= json_encode(t('Only on Apache; the PHP dev server does not process .htaccess.')) ?>,
};

// --- Performance ---
async function perfStatus(){
    try { const j = await (await fetch('?action=perf_status', {method:'POST'})).json();
        if (j.ok){ const el=document.getElementById('perf_status'); el.innerHTML = j.active ? `<span class="badge badge-ok">${T_PF.active}</span>` : ''; } } catch(e){}
}
async function perfEnable(){
    const opts = Array.from(document.querySelectorAll('.perf-opt:checked')).map(c=>c.value);
    if (!opts.length){ alert(T_PF.pickOne); return; }
    const fd = new FormData(); opts.forEach(o=>fd.append('opts[]',o));
    const j = await (await fetch('?action=perf_enable', {method:'POST', body:fd})).json();
    log('perf_log', j.ok ? `✓ ${T_PF.applied} (test: ${j.tested}).` : `✗ ${j.error}`, false); perfStatus();
}
async function perfDisable(){
    const j = await (await fetch('?action=perf_disable', {method:'POST'})).json();
    log('perf_log', j.ok ? `✓ ${T_PF.disabled}` : `✗ ${j.error}`, false); perfStatus();
}
async function perfStaticCache(){
    log('perf_log', '⏳ ' + T_PF.generating, false);
    const j = await (await fetch('?action=perf_static_cache', {method:'POST', body:teCreds(new FormData())})).json();
    log('perf_log', j.ok ? `✓ ${T_PF.cached}: ${j.saved} (${j.failed} ${T_PF.failed}) → ${j.dir}` : `✗ ${j.error}`, false);
}
async function perfCheck(){
    const out = document.getElementById('perf_check_result'); out.innerHTML = '⏳';
    let j; try { j = await (await fetch('?action=perf_check', {method:'POST'})).json(); }
    catch(e){ out.innerHTML = `<span style="color:#f87171">✗ ${e.message}</span>`; return; }
    if (!j.ok){ out.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; }
    else {
        const g = v => v<300?'#6ee7b7':(v<800?'#fcd34d':'#f87171');
        out.innerHTML = `<table style="font-size:12px;border-collapse:collapse">
            <tr><td style="padding:3px 10px;color:var(--text-dim)">TTFB</td><td style="padding:3px 10px;color:${g(j.ttfb_ms)}">${j.ttfb_ms} ms</td></tr>
            <tr><td style="padding:3px 10px;color:var(--text-dim)">${T_PF.loadTime}</td><td style="padding:3px 10px;color:${g(j.total_ms)}">${j.total_ms} ms</td></tr>
            <tr><td style="padding:3px 10px;color:var(--text-dim)">${T_PF.pageSize}</td><td style="padding:3px 10px">${j.size}</td></tr>
            <tr><td style="padding:3px 10px;color:var(--text-dim)">${T_PF.assetRefs}</td><td style="padding:3px 10px">${j.assets}</td></tr></table>`;
    }
    // External tools with the current URL.
    const u = encodeURIComponent(window.location.origin + '/');
    document.getElementById('perf_ext_links').innerHTML =
        `<div style="color:var(--text-dim);margin-bottom:4px">${T_PF.externalTools}:</div>
         <a href="https://pagespeed.web.dev/report?url=${u}" target="_blank" style="color:#7dd3fc">PageSpeed Insights</a> ·
         <a href="https://gtmetrix.com/?url=${u}" target="_blank" style="color:#7dd3fc">GTmetrix</a> ·
         <a href="https://tools.pingdom.com/#${u}" target="_blank" style="color:#7dd3fc">Pingdom</a>`;
}
const T_PF = {
    active: <?= json_encode(t('Active')) ?>, pickOne: <?= json_encode(t('Select at least one optimization')) ?>,
    applied: <?= json_encode(t('Optimizations applied')) ?>, disabled: <?= json_encode(t('Optimizations disabled')) ?>,
    generating: <?= json_encode(t('Generating static cache…')) ?>, cached: <?= json_encode(t('Pages cached')) ?>, failed: <?= json_encode(t('failed')) ?>,
    loadTime: <?= json_encode(t('Load time')) ?>, pageSize: <?= json_encode(t('Page size')) ?>, assetRefs: <?= json_encode(t('Asset references')) ?>,
    externalTools: <?= json_encode(t('External tools')) ?>,
};
document.addEventListener('DOMContentLoaded', () => { if (document.getElementById('perf_status')) perfStatus(); });

const T_TE = {
    replicating: <?= json_encode(t('Replicating table')) ?>, rows: <?= json_encode(t('rows')) ?>,
    creatingFolder: <?= json_encode(t('Creating sandbox folder…')) ?>, sandboxReady: <?= json_encode(t('Sandbox ready')) ?>,
    testingPlugins: <?= json_encode(t('Testing plugin')) ?>, reportTitle: <?= json_encode(t('Plugin compatibility report')) ?>,
    recommend: <?= json_encode(t('Recommend disabling')) ?>, allPluginsOk: <?= json_encode(t('All plugins load in the sandbox')) ?>,
    vetNote: <?= json_encode(t('Vet in the sandbox before installing live.')) ?>, confirmDestroy: <?= json_encode(t('Destroy the sandbox folder and its test tables?')) ?>,
    relativized: <?= json_encode(t('URLs relativized')) ?>, prefix: <?= json_encode(t('prefix')) ?>,
    symlinks: <?= json_encode(t('symlinks')) ?>, copiedFiles: <?= json_encode(t('files copied')) ?>,
    installBtn: <?= json_encode(t('Install')) ?>, incompatible: <?= json_encode(t('incompatible PHP')) ?>,
    loadingPopular: <?= json_encode(t('Loading popular plugins…')) ?>, installing: <?= json_encode(t('Installing')) ?>,
    noSandboxInstall: <?= json_encode(t('No sandbox yet — install directly into the live site?')) ?>,
    breaksSandbox: <?= json_encode(t('breaks the sandbox')) ?>, notLive: <?= json_encode(t('Not installing live.')) ?>,
    loadsSandbox: <?= json_encode(t('loads fine in the sandbox')) ?>, offerLive: <?= json_encode(t('%s passed the test. Install it into the live site now?')) ?>,
    multisite: <?= json_encode(t('multisite')) ?>, testingAll: <?= json_encode(t('Testing all plugins together')) ?>,
    allTogetherOk: <?= json_encode(t('All plugins load together')) ?>, breaksAll: <?= json_encode(t('The site breaks with all plugins active')) ?>,
    bisecting: <?= json_encode(t('isolating the culprit')) ?>, culprit: <?= json_encode(t('Breaking plugin')) ?>,
    minimalSet: <?= json_encode(t('Minimal breaking set')) ?>, conflictNote: <?= json_encode(t('These plugins conflict with each other.')) ?>,
    subSites: <?= json_encode(t('Sub-sites')) ?>, needSlug: <?= json_encode(t('Enter a sub-site path/subdomain')) ?>,
    subSiteAdded: <?= json_encode(t('Sub-site added')) ?>,
};

// Light/dark toggle: flips data-theme live and remembers it in a cookie (read
// server-side next load to avoid a flash).
function toggleTheme(){
    const html = document.documentElement;
    const next = html.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    html.setAttribute('data-theme', next);
    document.cookie = 'migrate_theme=' + next + ';path=/;max-age=31536000';
    const b = document.getElementById('theme_toggle');
    if (b) b.textContent = next === 'light' ? '🌙' : '☀️';
}

// --- Self-update: check GitHub for a newer release on load, offer to update ---
async function checkForUpdate(){
    const badge = document.getElementById('update_badge');
    if (!badge) return;
    try {
        const j = await (await fetch('?action=check_update', {method:'POST', body:new FormData()})).json();
        if (!j.ok || !j.update_available) return;
        badge.style.display = 'inline-flex';
        badge.innerHTML = `<span class="badge badge-warn" style="font-size:12px">⬆️ ${T_UPD.available} v${esc(j.latest)}</span>
            <button class="btn btn-sm btn-primary" style="margin-left:6px" onclick="runSelfUpdate(this)">${T_UPD.updateNow}</button>`;
    } catch(e){}
}
async function runSelfUpdate(btn){
    if (!confirm(T_UPD.confirm)) return;
    btn.disabled = true; btn.textContent = '⏳ ' + T_UPD.updating;
    try {
        const j = await (await fetch('?action=do_update', {method:'POST', body:new FormData()})).json();
        if (j.ok){
            btn.textContent = '✓ ' + T_UPD.done;
            alert(`${T_UPD.updatedTo} v${j.to}.\n${T_UPD.backupKept}: ${j.backup}\n\n${T_UPD.reloadNow}`);
            location.reload();
        } else { btn.disabled = false; btn.textContent = T_UPD.updateNow; alert('✗ ' + (j.error||'')); }
    } catch(e){ btn.disabled = false; btn.textContent = T_UPD.updateNow; alert('✗ ' + e.message); }
}
const T_UPD = {
    available: <?= json_encode(t('Update available:')) ?>, updateNow: <?= json_encode(t('Update now')) ?>,
    updating: <?= json_encode(t('Updating…')) ?>, done: <?= json_encode(t('Updated')) ?>,
    updatedTo: <?= json_encode(t('The tool was updated to')) ?>, backupKept: <?= json_encode(t('Backup kept as')) ?>,
    reloadNow: <?= json_encode(t('The page will now reload.')) ?>,
    confirm: <?= json_encode(t('Update the tool to the latest version? A backup of the current file is kept so you can revert.')) ?>,
};
document.addEventListener('DOMContentLoaded', checkForUpdate);

// --- WordPress news: list in the News tab + banner critical items on top ---
let NEWS_LOADED = false;
async function loadNews(force){
    if (NEWS_LOADED && !force) return;
    const box = document.getElementById('news_list');
    if (box) box.innerHTML = `<p style="color:var(--text-dim)">⏳ ${T_NEWS.loading}</p>`;
    try {
        const j = await (await fetch('?action=wp_news', {method:'POST', body:new FormData()})).json();
        if (!j.ok){ if (box) box.innerHTML = `<p style="color:#f87171">✗ ${esc(j.error||'')}</p>`; return; }
        NEWS_LOADED = true;
        if (box) box.innerHTML = j.items.map(it => `
            <div style="padding:10px 0;border-bottom:1px solid var(--border)">
                <a href="${esc(it.link)}" target="_blank" rel="noopener" style="color:var(--link);font-weight:600;text-decoration:none">${it.critical?'🔴 ':''}${esc(it.title)}</a>
                <div style="font-size:11px;color:var(--text-dim);margin:2px 0">${esc(it.date)}</div>
                <div style="font-size:12px;color:var(--text-muted)">${esc(it.desc)}</div>
            </div>`).join('') || `<p style="color:var(--text-dim)">${T_NEWS.none}</p>`;
        // Critical banner at the top of the app.
        const banner = document.getElementById('news_banner');
        if (banner && j.has_critical){
            const c = j.critical[0];
            banner.style.display = 'block';
            banner.innerHTML = `🔴 <strong>${T_NEWS.critical}:</strong> <a href="${esc(c.link)}" target="_blank" rel="noopener" style="color:#fca5a5">${esc(c.title)}</a> — <a href="#" onclick="showTab('news');return false" style="color:#fca5a5">${T_NEWS.seeAll}</a>`;
        }
    } catch(e){ if (box) box.innerHTML = `<p style="color:#f87171">✗ ${esc(e.message)}</p>`; }
}
const T_NEWS = {
    loading: <?= json_encode(t('Loading news…')) ?>, none: <?= json_encode(t('No news available')) ?>,
    critical: <?= json_encode(t('Critical WordPress news')) ?>, seeAll: <?= json_encode(t('see News tab')) ?>,
};
document.addEventListener('DOMContentLoaded', () => loadNews(false));

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
    // Refresh the "Export files" list so the new file(s)/parts show up there too.
    if (typeof loadExports === 'function') loadExports();
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
    if (document.getElementById('media_verify_disk').checked) fd.append('verify_disk', '1');
    log('tls_media_log', '\n⏳ Fixing...', true);
    const r = await fetch('?action=fix_media_urls', {method:'POST', body:fd});
    const j = await r.json();
    if (j.ok) {
        let m = `✓ Done. Updated ${j.updated} row(s).`;
        if (j.unverified) m += `\n⏭ Skipped ${j.unverified} URL(s): "verify on disk" is on and the target file was not found (left absolute to avoid broken links). Uncheck it to rewrite anyway.`;
        if (j.updated === 0 && !j.unverified) m += `\n(Nothing matched — the values may already be relative.)`;
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

// --- Broken Link Checker ---
let BL_ABORT = false;
async function checkBrokenLinks() {
    const btn = document.getElementById('bl_btn');
    const stop = document.getElementById('bl_stop');
    const out = document.getElementById('bl_results');
    const prog = document.getElementById('bl_progress');
    const bar = document.getElementById('bl_bar');
    const ptxt = document.getElementById('bl_progress_txt');
    BL_ABORT = false;
    btn.disabled = true; out.innerHTML = '';
    // 1) Collect URLs from the DB (fast, server-side).
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    fd.append('scope', document.getElementById('bl_scope').value);
    fd.append('cap', document.getElementById('bl_cap').value);
    out.innerHTML = '<span style="color:#718096;font-size:12px">⏳ Collecting URLs…</span>';
    let c;
    try { const r = await fetch('?action=scan_broken_collect', {method:'POST', body:fd}); c = await r.json(); }
    catch(e) { out.innerHTML = `<span style="color:#f87171">✗ ${e.message}</span>`; btn.disabled=false; return; }
    if (!c.ok) { out.innerHTML = `<span style="color:#f87171">✗ ${c.error}</span>`; btn.disabled=false; return; }
    if (!c.total) { out.innerHTML = '<span style="color:#6ee7b7;font-size:13px">✓ No URLs found to check.</span>'; btn.disabled=false; return; }

    // 2) Verify in batches with a progress bar; the user can stop.
    prog.style.display = 'block'; stop.style.display = 'inline-flex';
    const broken = {}; // host => [ {url,status} ]
    const BATCH = 10;
    let done = 0;
    for (let i = 0; i < c.urls.length && !BL_ABORT; i += BATCH) {
        const slice = c.urls.slice(i, i + BATCH);
        const bf = new FormData();
        bf.append('urls', JSON.stringify(slice.map(x => x.url)));
        let j;
        try { const r = await fetch('?action=verify_url', {method:'POST', body:bf}); j = await r.json(); }
        catch(e) { j = {ok:false}; }
        slice.forEach(x => {
            const res = (j.ok && j.results[x.url]) ? j.results[x.url] : {ok:false, status:0};
            if (!res.ok) { (broken[x.host] = broken[x.host] || []).push({url:x.url, status:res.status, where:x.where||'', edit:x.edit||'', type:x.type||''}); }
        });
        done += slice.length;
        const pct = Math.round(done / c.urls.length * 100);
        bar.style.width = pct + '%';
        const bt = Object.values(broken).reduce((a,v)=>a+v.length,0);
        ptxt.textContent = `${done}/${c.urls.length} (${pct}%) — ${bt} broken so far`;
    }
    stop.style.display = 'none'; btn.disabled = false;

    // 3) Render broken URLs grouped by domain (click to expand; links open in a new tab).
    const hosts = Object.keys(broken).sort((a,b) => broken[b].length - broken[a].length);
    if (!hosts.length) { out.innerHTML = `<div style="color:#6ee7b7;font-size:13px">✓ ${T_BL.allok} (${done} ${T_BL.checked}).</div>`; return; }
    let html = `<div style="font-size:12px;color:#fcd34d;margin-bottom:8px">${T_BL.found}: ${hosts.reduce((a,h)=>a+broken[h].length,0)} (${done} ${T_BL.checked})</div>`;
    html += '<div style="border:1px solid var(--border);border-radius:6px;overflow:hidden">';
    const typeIcon = {post:'📝', meta:'🧩', option:'⚙️'};
    hosts.forEach(h => {
        const items = broken[h].map(b => {
            // "Where" cell: location text + an edit icon that opens WP admin in a new tab.
            const icon = typeIcon[b.type] || '🔗';
            const whereHtml = b.where
                ? `<span style="color:var(--text-muted)">${icon} ${esc(b.where)}</span>` +
                  (b.edit ? ` <a href="${esc(b.edit)}" target="_blank" title="${T_BL.openwp}" style="color:#7dd3fc;text-decoration:none">✎</a>` : '')
                : '<span style="color:var(--text-dim)">—</span>';
            return `<tr style="border-top:1px solid var(--border)">
                <td style="padding:5px 10px;vertical-align:top"><span class="badge badge-err">${b.status||'—'}</span></td>
                <td style="padding:5px 10px;font-size:11px;font-family:monospace;vertical-align:top">
                    <a href="${esc(b.url)}" target="_blank" style="color:#7dd3fc;word-break:break-all">${esc(b.url)}</a></td>
                <td style="padding:5px 10px;font-size:11px;vertical-align:top">${whereHtml}</td>
            </tr>`;
        }).join('');
        html += `<details style="border-bottom:1px solid var(--border)">
            <summary style="padding:8px 14px;cursor:pointer;font-size:13px;background:var(--surface);list-style:none">
                <span style="font-family:monospace;color:var(--text)">${esc(h)}</span>
                <span class="badge badge-err" style="margin-left:6px">${broken[h].length} ${T_BL.broken}</span>
            </summary>
            <table style="width:100%;border-collapse:collapse;background:var(--surface2)">
                <tr style="color:var(--text-dim);font-size:10px;text-transform:uppercase">
                    <th style="padding:4px 10px;text-align:left">${T_BL.code}</th>
                    <th style="padding:4px 10px;text-align:left">URL</th>
                    <th style="padding:4px 10px;text-align:left">${T_BL.location}</th>
                </tr>${items}
            </table></details>`;
    });
    html += '</div>';
    out.innerHTML = html;
}
const T_BL = {
    allok:   <?= json_encode(t('No broken links found')) ?>,
    checked: <?= json_encode(t('checked')) ?>,
    found:   <?= json_encode(t('Broken links found')) ?>,
    broken:  <?= json_encode(t('broken')) ?>,
    openwp:  <?= json_encode(t('Open in WordPress admin')) ?>,
    code:    <?= json_encode(t('Code')) ?>,
    location:<?= json_encode(t('Location in WordPress')) ?>,
};

// --- Broken Link find & replace (verify target exists before saving) ---
function blrCreds(fd){ ['host','name','user','pass','prefix'].forEach(k => fd.append('db_'+k, document.getElementById('exp_db_'+k)?.value || document.getElementById('tls_db_'+k)?.value || '')); return fd; }
async function blrFindReplace(){
    const find = document.getElementById('blr_find').value;
    const repl = document.getElementById('blr_replace').value;
    if (!find || find === repl){ alert('Enter a "find" value different from "replace".'); return; }
    const out = document.getElementById('blr_result');
    const btn = document.getElementById('blr_btn'); btn.disabled = true; btn.textContent = '⏳';
    out.innerHTML = '<span style="color:var(--text-dim)">⏳ ' + T_BLR.finding + '</span>';
    // 1) find candidate URLs + their replaced form
    const fd = blrCreds(new FormData()); fd.append('find', find); fd.append('replace', repl);
    let j;
    try { j = await (await fetch('?action=blreplace_analyze', {method:'POST', body:fd})).json(); }
    catch(e){ out.innerHTML = `<span style="color:#f87171">✗ ${e.message}</span>`; btn.disabled=false; btn.textContent='🔁 '+T_BLR.btn; return; }
    if (!j.ok){ out.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; btn.disabled=false; btn.textContent='🔁 '+T_BLR.btn; return; }
    if (!j.count){ out.innerHTML = `<span style="color:#6ee7b7">✓ ${T_BLR.none}</span>`; btn.disabled=false; btn.textContent='🔁 '+T_BLR.btn; return; }
    // 2) HTTP-verify each resulting URL in batches; keep only reachable ones
    document.getElementById('blr_progress').style.display = 'block';
    const bar = document.getElementById('blr_bar'), ptxt = document.getElementById('blr_ptxt');
    const verified = []; let done = 0, ok = 0, fail = 0; const B = 10;
    for (let i=0; i<j.urls.length; i+=B){
        const slice = j.urls.slice(i, i+B);
        const vf = new FormData(); vf.append('urls', JSON.stringify(slice.map(x => x.to)));
        let v; try { v = await (await fetch('?action=verify_url', {method:'POST', body:vf})).json(); } catch(e){ v={ok:false}; }
        slice.forEach(x => { const res=(v.ok&&v.results[x.to])?v.results[x.to]:{ok:false}; if(res.ok){verified.push(x);ok++;}else fail++; });
        done += slice.length; const pct=Math.round(done/j.urls.length*100); bar.style.width=pct+'%';
        ptxt.textContent = `${T_BLR.verifying} ${done}/${j.urls.length} — ${ok} ✓ / ${fail} ✗`;
    }
    if (!verified.length){ out.innerHTML = `<span style="color:#fcd34d">${T_BLR.foundButNone} (${j.count} ${T_BLR.foundN}, 0 ${T_BLR.reachable})</span>`; btn.disabled=false; btn.textContent='🔁 '+T_BLR.btn; return; }
    if (!confirm(T_BLR.confirm.replace('%d', verified.length))){ btn.disabled=false; btn.textContent='🔁 '+T_BLR.btn; return; }
    // 3) apply only the verified pairs (reuses dburl_apply)
    let rows = 0; done = 0;
    for (let i=0; i<verified.length; i+=50){
        const slice = verified.slice(i, i+50);
        const af = blrCreds(new FormData()); af.append('pairs', JSON.stringify(slice));
        try { const a = await (await fetch('?action=dburl_apply', {method:'POST', body:af})).json(); if (a.ok) rows += a.updated_rows||0; } catch(e){}
        done += slice.length; bar.style.width = Math.round(done/verified.length*100)+'%'; ptxt.textContent = `${T_BLR.applying} ${done}/${verified.length}`;
    }
    out.innerHTML = `<span style="color:#6ee7b7">✓ ${T_BLR.done}: ${verified.length} URL(s), ${rows} ${T_BLR.rows} (${fail} ${T_BLR.skipped})</span>`;
    btn.disabled = false; btn.textContent = '🔁 ' + T_BLR.btn;
}
const T_BLR = {
    btn: <?= json_encode(t('Find, verify & replace')) ?>, finding: <?= json_encode(t('Finding links…')) ?>,
    verifying: <?= json_encode(t('Verifying')) ?>, applying: <?= json_encode(t('Applying')) ?>,
    none: <?= json_encode(t('No links contain that text.')) ?>, foundButNone: <?= json_encode(t('No replaced URL responds — nothing changed')) ?>,
    foundN: <?= json_encode(t('found')) ?>, reachable: <?= json_encode(t('reachable')) ?>, skipped: <?= json_encode(t('skipped (unreachable)')) ?>,
    confirm: <?= json_encode(t('Replace in %d verified link(s) in the database?')) ?>, done: <?= json_encode(t('Done')) ?>, rows: <?= json_encode(t('rows updated')) ?>,
};

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
        html += `<div style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border-bottom:1px solid var(--border)">
            <input type="checkbox" value="${esc(p.file)}" ${checked} style="margin-top:2px;cursor:pointer;width:15px;height:15px;flex-shrink:0">
            <span style="flex:1">
                <span style="font-size:13px;color:var(--text)">${esc(p.name)}</span>${badge}<br>
                <span style="font-size:11px;color:var(--text-dim)">${esc(p.file)}</span>
            </span>
            <button class="btn btn-sm" style="background:var(--border);color:var(--text-muted);flex-shrink:0" onclick="exportPlugin('${esc(p.file)}', this)">⤓ .zip</button>
        </div>`;
    });
    html += '</div>';
    el.innerHTML = html;
}

async function exportPlugin(file, btn){
    const prev = btn.textContent; btn.disabled = true; btn.textContent = '⏳';
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    fd.append('plugin', file);
    try {
        const r = await fetch('?action=plugin_export', {method:'POST', body:fd});
        const j = await r.json();
        if (j.ok){
            log('tls_plugins_log', `✓ ${file}: <a href="${j.url}" target="_blank" style="color:#7dd3fc">${j.name}</a> (${j.size})`, false);
            window.open(j.url, '_blank');
        } else { log('tls_plugins_log', `✗ ${j.error}`, false); }
    } catch(e){ log('tls_plugins_log', '✗ ' + e.message, false); }
    btn.disabled = false; btn.textContent = prev;
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
        else {
            out.innerHTML = renderCategories(j.categories);
            // If media / asset categories reported missing files, offer to recover
            // them from the original site using the existing recovery flow.
            const hasMissing = j.categories.some(c =>
                /Media|Asset|Medios|activos|Attachment|adjunto/i.test(c.label) && c.errors > 0);
            if (hasMissing) {
                out.innerHTML += `<div class="alert alert-warn" style="margin-top:12px">
                    🩹 ${T_HC.missingAssets}
                    <button class="btn btn-sm btn-warning" style="margin-left:8px" onclick="goToRecovery()">${T_HC.recoverBtn}</button>
                </div>`;
            }
        }
    } catch(e) { out.innerHTML = `<p style="color:#f87171">✗ Request failed: ${e.message}</p>`; }
    btn.disabled = false; btn.textContent = '🔍 Run Health Check';
}
// Jump to the asset recovery card and prefill its DB credentials from the tools card.
function goToRecovery(){
    ['host','name','user','pass','prefix'].forEach(k => {
        const src = document.getElementById('tls_db_'+k), dst = document.getElementById('qu_db_'+k);
        if (src && dst && !dst.value) dst.value = src.value;
    });
    const card = document.getElementById('ar_origin');
    if (card){ card.scrollIntoView({behavior:'smooth', block:'center'}); card.focus(); }
}
const T_HC = {
    missingAssets: <?= json_encode(t('Missing files detected. You can download them from the original site.')) ?>,
    recoverBtn:    <?= json_encode(t('Go to asset recovery')) ?>,
};

// --- Clean Site (remove unused uploads) ---
let CS_READY = false;
async function cleanSiteAnalyze(){
    const btn = document.getElementById('cs_analyze_btn');
    btn.disabled = true; btn.textContent = '⏳';
    const out = document.getElementById('cs_result');
    out.innerHTML = '<span style="color:var(--text-dim);font-size:13px">⏳ ' + T_CS.analyzing + '</span>';
    const fd = new FormData(); Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    let j;
    try { const r = await fetch('?action=clean_site_analyze', {method:'POST', body:fd}); j = await r.json(); }
    catch(e){ out.innerHTML = `<span style="color:#f87171">✗ ${e.message}</span>`; btn.disabled=false; btn.textContent='📊 '+T_CS.analyze; return; }
    if (!j.ok){ out.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; btn.disabled=false; btn.textContent='📊 '+T_CS.analyze; return; }
    out.innerHTML = `<table style="width:100%;border-collapse:collapse;font-size:13px">
        <tr style="border-bottom:1px solid var(--border)"><td style="padding:5px 8px;color:var(--text-dim)">${T_CS.totalFiles}</td><td style="padding:5px 8px;text-align:right">${j.total_files}</td><td style="padding:5px 8px;text-align:right;color:var(--text-dim)">${j.total_size}</td></tr>
        <tr style="border-bottom:1px solid var(--border)"><td style="padding:5px 8px;color:#6ee7b7">${T_CS.usedFiles}</td><td style="padding:5px 8px;text-align:right">${j.used_files}</td><td style="padding:5px 8px;text-align:right;color:var(--text-dim)">${j.used_size}</td></tr>
        <tr style="border-bottom:1px solid var(--border)"><td style="padding:5px 8px;color:#fcd34d">${T_CS.unusedFiles}</td><td style="padding:5px 8px;text-align:right">${j.unused_files}</td><td style="padding:5px 8px;text-align:right;color:#6ee7b7">↓ ${j.reclaimable}</td></tr>
        <tr><td style="padding:5px 8px;color:var(--text-dim)">${T_CS.usedImages}</td><td style="padding:5px 8px;text-align:right">${j.used_images}</td><td></td></tr>
    </table>`;
    CS_READY = (j.unused_files > 0);
    const ab = document.getElementById('cs_apply_btn');
    ab.style.display = CS_READY ? 'inline-flex' : 'none';
    if (!CS_READY) out.innerHTML += `<div style="color:#6ee7b7;font-size:12px;margin-top:8px">✓ ${T_CS.nothingToClean}</div>`;
    btn.disabled = false; btn.textContent = '📊 ' + T_CS.analyze;
}

async function cleanSiteApply(){
    if (!CS_READY){ return; }
    if (!confirm(T_CS.confirmApply)) return;
    const btn = document.getElementById('cs_apply_btn');
    btn.disabled = true; btn.textContent = '⏳';
    log('cs_log', '⏳ ' + T_CS.building, false);
    const fd = new FormData(); Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    let j;
    try { const r = await fetch('?action=clean_site_apply', {method:'POST', body:fd}); j = await r.json(); }
    catch(e){ log('cs_log','✗ '+e.message,false); btn.disabled=false; btn.textContent='🧹'; return; }
    if (j.ok){
        log('cs_log', `✓ ${T_CS.done}: ${j.copied} ${T_CS.filesCopied}${j.copy_errors?(' ('+j.copy_errors+' errors)'):''}.\n`
            + `📦 ${T_CS.oldKept}: ${j.old_folder}\n🧾 ${T_CS.restoreLog}: ${j.log}\n`
            + `↩ ${T_CS.restoreHint}`, false);
        document.getElementById('cs_apply_btn').style.display = 'none';
        // offer a restore button bound to this ts
        const out = document.getElementById('cs_result');
        out.innerHTML += `<div style="margin-top:10px"><button class="btn btn-sm" style="background:var(--border);color:var(--text-muted)" onclick="cleanSiteRestore('${j.ts}')">↩ ${T_CS.restore}</button></div>`;
    } else { log('cs_log','✗ '+j.error,false); }
    btn.disabled = false; btn.textContent = '🧹 ' + T_CS.build;
}

async function cleanSiteRestore(ts){
    if (!confirm(T_CS.confirmRestore)) return;
    const fd = new FormData(); Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v)); fd.append('ts', ts);
    const r = await fetch('?action=clean_site_restore', {method:'POST', body:fd});
    const j = await r.json();
    log('cs_log', j.ok ? `✓ ${T_CS.restored} (${j.restored_from})` : `✗ ${j.error}`, false);
}
const T_CS = {
    analyzing: <?= json_encode(t('Analyzing uploads…')) ?>, analyze: <?= json_encode(t('Analyze (dry-run)')) ?>,
    build: <?= json_encode(t('Build clean uploads')) ?>, building: <?= json_encode(t('Building clean uploads…')) ?>,
    totalFiles: <?= json_encode(t('Total files')) ?>, usedFiles: <?= json_encode(t('Used files')) ?>,
    unusedFiles: <?= json_encode(t('Unused (reclaimable)')) ?>, usedImages: <?= json_encode(t('Used images')) ?>,
    nothingToClean: <?= json_encode(t('Nothing to clean — all files are used.')) ?>,
    confirmApply: <?= json_encode(t('Build a clean uploads folder with only used files? The current folder is kept as uploads_old-remove-it_* (not deleted).')) ?>,
    done: <?= json_encode(t('Done')) ?>, filesCopied: <?= json_encode(t('files copied')) ?>,
    oldKept: <?= json_encode(t('Old folder kept as backup')) ?>, restoreLog: <?= json_encode(t('Restore log')) ?>,
    restoreHint: <?= json_encode(t('Verify the site, then delete the old folder manually.')) ?>,
    restore: <?= json_encode(t('Restore (undo)')) ?>, restored: <?= json_encode(t('Restored')) ?>,
    confirmRestore: <?= json_encode(t('Restore the original uploads folder from backup?')) ?>,
};

// --- Optimize uploads (organize / dedupe / sanitize) ---
// Each optimization: analyze (dry-run) stores the work items, apply runs them in
// batches via its endpoint, updating a progress bar.
const OPT = { organize: {items:[]}, dedupe: {items:[]}, sanitize: {items:[]} };
function optCreds(fd){ Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v)); return fd; }

async function optOrganizeAnalyze(){
    const out = document.getElementById('org_result');
    out.innerHTML = '⏳';
    const r = await fetch('?action=organize_analyze', {method:'POST', body:optCreds(new FormData())});
    const j = await r.json();
    if (!j.ok){ out.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; return; }
    OPT.organize.items = j.files || [];
    out.innerHTML = optPreview(j.count, (j.preview||[]).map(p => `${esc(p.from)} → ${esc(p.to)}`));
    document.getElementById('org_apply').style.display = j.count ? 'inline-flex' : 'none';
}
async function optDedupeAnalyze(){
    const out = document.getElementById('dedup_result');
    out.innerHTML = '⏳ ' + T_OPT.hashing;
    const r = await fetch('?action=dedupe_analyze', {method:'POST', body:optCreds(new FormData())});
    const j = await r.json();
    if (!j.ok){ out.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; return; }
    OPT.dedupe.items = j.all_groups || []; // full groups (keeper-first) for apply
    out.innerHTML = optPreview(j.dup_files + ' dup / ' + j.groups + ' groups · ↓' + j.reclaimable,
        (j.preview||[]).map(p => `keep ${esc(p.keep)} — drop ${esc(p.dups.join(', '))}`));
    document.getElementById('dedup_apply').style.display = j.groups ? 'inline-flex' : 'none';
}
async function optSanitizeAnalyze(){
    const out = document.getElementById('san_result');
    out.innerHTML = '⏳';
    const r = await fetch('?action=sanitize_analyze', {method:'POST', body:optCreds(new FormData())});
    const j = await r.json();
    if (!j.ok){ out.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; return; }
    OPT.sanitize.items = j.files || [];
    out.innerHTML = optPreview(j.count, (j.preview||[]).map(p => `${esc(p.from)} → ${esc(p.to)}`));
    document.getElementById('san_apply').style.display = j.count ? 'inline-flex' : 'none';
}
function optPreview(count, lines){
    let h = `<div style="color:var(--text-muted);margin-bottom:4px">${T_OPT.found}: ${count}</div>`;
    if (lines.length) h += '<div style="font-family:monospace;font-size:11px;color:var(--text-dim);max-height:160px;overflow:auto">' + lines.map(l=>'• '+l).join('<br>') + (count>lines.length?'<br>…':'') + '</div>';
    else h += `<span style="color:#6ee7b7">✓ ${T_OPT.nothing}</span>`;
    return h;
}

async function optApply(kind){
    if (!confirm(T_OPT.confirm)) return;
    const map = {organize:{btn:'org_apply',prog:'org_progress',bar:'org_bar',ptxt:'org_ptxt',action:'organize_apply',param:'files',items:OPT.organize.items},
                 dedupe:{btn:'dedup_apply',prog:'dedup_progress',bar:'dedup_bar',ptxt:'dedup_ptxt',action:'dedupe_apply',param:'groups',items:OPT.dedupe.items},
                 sanitize:{btn:'san_apply',prog:'san_progress',bar:'san_bar',ptxt:'san_ptxt',action:'sanitize_apply',param:'files',items:OPT.sanitize.items}};
    const c = map[kind];
    if (!c.items || !c.items.length){ return; }
    document.getElementById(c.prog).style.display = 'block';
    const bar = document.getElementById(c.bar), ptxt = document.getElementById(c.ptxt);
    const BATCH = kind==='dedupe' ? 10 : 50;
    let done = 0, total = c.items.length, agg = {};
    for (let i=0; i<total; i+=BATCH){
        const slice = c.items.slice(i, i+BATCH);
        const fd = optCreds(new FormData()); fd.append(c.param, JSON.stringify(slice));
        try { const r = await fetch('?action='+c.action, {method:'POST', body:fd}); const j = await r.json();
            if (j.ok) Object.keys(j).forEach(k => { if (typeof j[k]==='number') agg[k]=(agg[k]||0)+j[k]; });
        } catch(e){}
        done += slice.length;
        const pct = Math.round(done/total*100); bar.style.width = pct+'%'; ptxt.textContent = `${done}/${total} (${pct}%)`;
    }
    const summary = Object.entries(agg).map(([k,v]) => `${k}: ${v}`).join(', ');
    log('opt_log', `✓ ${kind}: ${summary}. ${T_OPT.logged}`, false);
    document.getElementById(c.btn).style.display = 'none';
}
const T_OPT = {
    found: <?= json_encode(t('Found')) ?>, nothing: <?= json_encode(t('Nothing to optimize')) ?>,
    hashing: <?= json_encode(t('Hashing files…')) ?>, confirm: <?= json_encode(t('Apply this optimization? Files on disk and database references will change (logged and reversible).')) ?>,
    logged: <?= json_encode(t('Changes written to a reversible log.')) ?>,
};

// --- DB URL replacement with per-URL target verification ---
let DBU_ABORT = false;
let DBU_VERIFIED = []; // {from,to} pairs whose target responded
function dbuCreds(fd){ ['host','name','user','pass','prefix'].forEach(k => fd.append('db_'+k, document.getElementById('exp_db_'+k)?.value || document.getElementById('tls_db_'+k)?.value || '')); return fd; }

async function dbUrlAnalyze(){
    const src = document.getElementById('exp_source_url').value.trim();
    const dst = document.getElementById('exp_target_url').value.trim();
    if (!src || !dst || src === dst){ alert('Set distinct source and target URLs.'); return; }
    DBU_ABORT = false; DBU_VERIFIED = [];
    const out = document.getElementById('dbu_result');
    const btn = document.getElementById('dbu_analyze_btn');
    btn.disabled = true; btn.textContent = '⏳';
    out.innerHTML = '<span style="color:var(--text-dim)">⏳ ' + T_DBU.finding + '</span>';
    const fd = dbuCreds(new FormData()); fd.append('source_url', src); fd.append('target_url', dst);
    let j;
    try { const r = await fetch('?action=dburl_analyze', {method:'POST', body:fd}); j = await r.json(); }
    catch(e){ out.innerHTML = `<span style="color:#f87171">✗ ${e.message}</span>`; btn.disabled=false; btn.textContent='🔍 '+T_DBU.find; return; }
    if (!j.ok){ out.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; btn.disabled=false; btn.textContent='🔍 '+T_DBU.find; return; }
    if (!j.count){ out.innerHTML = `<span style="color:#6ee7b7">✓ ${T_DBU.none}</span>`; btn.disabled=false; btn.textContent='🔍 '+T_DBU.find; return; }

    // Verify each distinct target URL over HTTP in batches.
    document.getElementById('dbu_progress').style.display = 'block';
    document.getElementById('dbu_stop').style.display = 'inline-flex';
    const bar = document.getElementById('dbu_bar'), ptxt = document.getElementById('dbu_ptxt');
    let done = 0, okCount = 0, failCount = 0; const BATCH = 10;
    for (let i=0; i<j.urls.length && !DBU_ABORT; i+=BATCH){
        const slice = j.urls.slice(i, i+BATCH);
        const vf = new FormData(); vf.append('urls', JSON.stringify(slice.map(x => x.to)));
        let v;
        try { v = await (await fetch('?action=verify_url', {method:'POST', body:vf})).json(); } catch(e){ v = {ok:false}; }
        slice.forEach(x => {
            const res = (v.ok && v.results[x.to]) ? v.results[x.to] : {ok:false};
            if (res.ok){ DBU_VERIFIED.push(x); okCount++; } else { failCount++; }
        });
        done += slice.length;
        const pct = Math.round(done/j.urls.length*100); bar.style.width = pct+'%';
        ptxt.textContent = `${done}/${j.urls.length} (${pct}%) — ${okCount} ${T_DBU.reachable}, ${failCount} ${T_DBU.skipped}`;
    }
    document.getElementById('dbu_stop').style.display = 'none';
    out.innerHTML = `<div style="font-size:12px">${T_DBU.found}: ${j.count} · ${T_DBU.reachable}: <strong style="color:#6ee7b7">${okCount}</strong> · ${T_DBU.skipped}: <strong style="color:#fcd34d">${failCount}</strong></div>`;
    document.getElementById('dbu_apply_btn').style.display = DBU_VERIFIED.length ? 'inline-flex' : 'none';
    btn.disabled = false; btn.textContent = '🔍 ' + T_DBU.find;
}

async function dbUrlApply(){
    if (!DBU_VERIFIED.length){ return; }
    if (!confirm(T_DBU.confirm.replace('%d', DBU_VERIFIED.length))) return;
    const btn = document.getElementById('dbu_apply_btn'); btn.disabled = true; btn.textContent = '⏳';
    const bar = document.getElementById('dbu_bar'), ptxt = document.getElementById('dbu_ptxt');
    document.getElementById('dbu_progress').style.display = 'block';
    let done = 0, rows = 0; const BATCH = 50;
    for (let i=0; i<DBU_VERIFIED.length; i+=BATCH){
        const slice = DBU_VERIFIED.slice(i, i+BATCH);
        const fd = dbuCreds(new FormData()); fd.append('pairs', JSON.stringify(slice));
        try { const j = await (await fetch('?action=dburl_apply', {method:'POST', body:fd})).json(); if (j.ok) rows += j.updated_rows||0; } catch(e){}
        done += slice.length;
        bar.style.width = Math.round(done/DBU_VERIFIED.length*100)+'%';
        ptxt.textContent = `${done}/${DBU_VERIFIED.length}`;
    }
    log('dbu_log', `✓ ${T_DBU.done}: ${DBU_VERIFIED.length} URL(s), ${rows} ${T_DBU.rowsUpd}. ${T_DBU.logged}`, false);
    document.getElementById('dbu_apply_btn').style.display = 'none';
    btn.disabled = false; btn.textContent = '▶ ' + T_DBU.replace;
}
const T_DBU = {
    find: <?= json_encode(t('Find & verify URLs')) ?>, finding: <?= json_encode(t('Finding URLs in the database…')) ?>,
    replace: <?= json_encode(t('Replace verified in DB')) ?>, none: <?= json_encode(t('No source URLs found in the database.')) ?>,
    found: <?= json_encode(t('Found')) ?>, reachable: <?= json_encode(t('reachable')) ?>, skipped: <?= json_encode(t('skipped')) ?>,
    confirm: <?= json_encode(t('Replace %d verified URL(s) in the live database? Reversible via log.')) ?>,
    done: <?= json_encode(t('Done')) ?>, rowsUpd: <?= json_encode(t('rows updated')) ?>, logged: <?= json_encode(t('Changes written to a reversible log.')) ?>,
};

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

// --- Anti-hotlinking: check → test isolated (no 500) → apply with backup ---
let HL_TESTED = false;
async function hotlinkCheck(){
    const out = document.getElementById('hl_results');
    const j = await (await fetch('?action=hotlink_check', {method:'POST', body:new FormData()})).json();
    if (!j.ok){ out.innerHTML = `<span style="color:#f87171">✗ ${esc(j.error||'')}</span>`; return; }
    const color = j.protected ? '#6ee7b7' : '#fcd34d';
    out.innerHTML = `<div style="color:${color};padding:4px 0">${j.protected?'✅':'⚠️'} ${esc(j.text)}</div>`;
    // Offer the test (then apply) only when the site is not already protected.
    document.getElementById('hl_test_btn').style.display = j.protected ? 'none' : 'inline-flex';
    document.getElementById('hl_apply_btn').style.display = 'none';
    HL_TESTED = false;
}
async function hotlinkTest(){
    const btn = document.getElementById('hl_test_btn'); btn.disabled = true; btn.textContent = '⏳';
    try {
        const j = await (await fetch('?action=hotlink_test', {method:'POST', body:new FormData()})).json();
        if (!j.ok){ log('hl_log', '✗ ' + (j.error||''), false); }
        else {
            log('hl_log', `${j.breaks?'❌':(j.enforced?'✅':'⚠️')} ${j.note} (direct=${j.direct}, external=${j.external})`, false);
            // Apply is allowed only when the rules do NOT break the site (no 500).
            HL_TESTED = !j.breaks;
            document.getElementById('hl_apply_btn').style.display = j.breaks ? 'none' : 'inline-flex';
        }
    } catch(e){ log('hl_log', '✗ ' + e.message, false); }
    btn.disabled = false; btn.textContent = '🧪 ' + T_HL.test;
}
async function hotlinkApply(){
    if (!HL_TESTED){ alert(T_HL.testFirst); return; }
    if (!confirm(T_HL.confirm)) return;
    const btn = document.getElementById('hl_apply_btn'); btn.disabled = true;
    try {
        const j = await (await fetch('?action=hotlink_apply', {method:'POST', body:new FormData()})).json();
        if (j.ok) log('hl_log', `✓ ${T_HL.applied}${j.backup?` · ${T_HL.backup}: ${j.backup}`:''}`, false);
        else log('hl_log', '✗ ' + (j.error||''), false);
    } catch(e){ log('hl_log', '✗ ' + e.message, false); }
    btn.disabled = false; hotlinkCheck();
}
const T_HL = {
    test: <?= json_encode(t('Test rules (isolated)')) ?>, testFirst: <?= json_encode(t('Run the isolated test first.')) ?>,
    applied: <?= json_encode(t('Anti-hotlink protection applied')) ?>, backup: <?= json_encode(t('backup')) ?>,
    confirm: <?= json_encode(t('Apply anti-hotlink rules to .htaccess? The current .htaccess is backed up first so you can revert.')) ?>,
};

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

let SEO_PERMA_OK = false;
function seoPermaDisableApply() {
    SEO_PERMA_OK = false;
    const b = document.getElementById('seo_perma_apply_btn');
    b.disabled = true; b.style.opacity = '.5'; b.style.cursor = 'not-allowed';
}
document.addEventListener('change', e => { if (e.target && e.target.id === 'seo_perma_struct') seoPermaDisableApply(); });

async function seoPermaTest() {
    const struct = document.getElementById('seo_perma_struct').value;
    const box = document.getElementById('seo_perma_result');
    // Plain ?p= needs no rewrite, so no 500 risk — enable Apply directly.
    if (struct.indexOf('?p=') !== -1) {
        box.innerHTML = `<span class="badge badge-ok">✓ ${T_PERMA.noRewrite}</span>`;
        SEO_PERMA_OK = true;
        const b = document.getElementById('seo_perma_apply_btn');
        b.disabled = false; b.style.opacity = '1'; b.style.cursor = 'pointer';
        return;
    }
    box.innerHTML = `<span style="color:var(--text-dim)">🧪 ${T_PERMA.testing}</span>`;
    seoPermaDisableApply();
    const fd = seoFd(); fd.append('mode', 'test'); fd.append('structure', struct);
    let j;
    try { const r = await fetch('?action=seo_set_permalinks', {method:'POST', body:fd}); j = await r.json(); }
    catch(e) { box.innerHTML = `<span style="color:#f87171">✗ ${e.message}</span>`; return; }
    if (!j.ok) { box.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; return; }
    let badge;
    if (j.breaks)         badge = `<span class="badge badge-err">✗ HTTP ${j.status}</span>`;
    else if (j.supported) badge = `<span class="badge badge-ok">✓ HTTP ${j.status||'OK'}</span>`;
    else                  badge = `<span class="badge badge-warn">? HTTP ${j.status||'—'}</span>`;
    box.innerHTML = `${badge} <span style="color:var(--text-muted)">${esc(j.note)}</span>`;
    if (j.supported) {
        SEO_PERMA_OK = true;
        const b = document.getElementById('seo_perma_apply_btn');
        b.disabled = false; b.style.opacity = '1'; b.style.cursor = 'pointer';
    }
}

async function seoPermaApply() {
    if (!SEO_PERMA_OK) { log('tls_seo_log', '✗ Run a successful permalink test first.', false); return; }
    const struct = document.getElementById('seo_perma_struct').value;
    const fd = seoFd(); fd.append('mode', 'apply'); fd.append('structure', struct);
    const r = await fetch('?action=seo_set_permalinks', {method:'POST', body:fd});
    const j = await r.json();
    log('tls_seo_log', j.ok ? `✓ ${j.note}` : `✗ ${j.error}`, false);
    if (j.ok) seoPermaDisableApply();
}
const T_PERMA = {
    testing:   <?= json_encode(t('Testing in isolated folder…')) ?>,
    noRewrite: <?= json_encode(t('No rewrite needed — safe to apply')) ?>,
};

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
            log('tls_pma_log', `✓ Installed (${j.dl_method}/${j.ex_method}) at ${j.folder}/`, false);
            // Reveal path + temporary-access controls, hide the install button.
            document.getElementById('tls_pma_path').innerHTML =
                `${T_PMA.installed}: <a href="${j.url}" target="_blank" style="color:#7dd3fc;font-family:monospace">${esc(j.folder)}/</a>`;
            btn.style.display = 'none';
            document.getElementById('tls_pma_cfg_btn').style.display = 'inline-flex';
            document.getElementById('tls_pma_off_btn').style.display = 'inline-flex';
            const ttlw = document.getElementById('tls_pma_ttl_wrap'); if (ttlw) ttlw.style.display = 'inline-flex';
        } else {
            log('tls_pma_log', '✗ ' + j.error, false);
            btn.disabled = false; btn.textContent = '⬇️ Download & install phpMyAdmin';
        }
    } catch(e) { log('tls_pma_log', '✗ ' + e.message, false); btn.disabled = false; btn.textContent = '⬇️ Download & install phpMyAdmin'; }
}

let PMA_TIMER = null;
async function pmaTempAccess() {
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    fd.append('ttl_min', document.getElementById('tls_pma_ttl')?.value || '15');
    log('tls_pma_log', '⏱️ Writing temporary credentials…', false);
    let j;
    try { const r = await fetch('?action=pma_config_on', {method:'POST', body:fd}); j = await r.json(); }
    catch(e) { log('tls_pma_log', '✗ ' + e.message, false); return; }
    if (!j.ok) { log('tls_pma_log', '✗ ' + j.error, false); return; }
    // Open phpMyAdmin (already logged in via the config) in a new tab.
    window.open(j.url, '_blank');
    const mins = Math.round(j.ttl/60);
    log('tls_pma_log', `✓ Opened ${j.url} — credentials self-destruct in ${mins} min.`, false);
    // Countdown; at the end remove the config as reinforcement (server also self-expires).
    let left = j.ttl;
    const cd = document.getElementById('tls_pma_countdown');
    const fmt = s => s >= 60 ? Math.floor(s/60)+'m '+(s%60)+'s' : s+'s';
    clearInterval(PMA_TIMER);
    PMA_TIMER = setInterval(() => {
        left--;
        cd.textContent = left > 0 ? `${T_PMA.expiring} ${fmt(left)}…` : '';
        if (left <= 0) { clearInterval(PMA_TIMER); pmaConfigOff(true); }
    }, 1000);
    cd.textContent = `${T_PMA.expiring} ${left}s…`;
}

async function pmaConfigOff(silent) {
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    try {
        const r = await fetch('?action=pma_config_off', {method:'POST', body:fd});
        const j = await r.json();
        if (!silent) log('tls_pma_log', j.ok ? `✓ Credentials removed (${j.removed}). Manual login required now.` : `✗ ${j.error}`, false);
        else log('tls_pma_log', '🔒 Temporary credentials expired and removed.', false);
    } catch(e) { if (!silent) log('tls_pma_log', '✗ ' + e.message, false); }
    document.getElementById('tls_pma_countdown').textContent = '';
}
const T_PMA = {
    installed: <?= json_encode(t('Installed at')) ?>,
    expiring:  <?= json_encode(t('Credentials expire in')) ?>,
};

// --- Site Password (HTTP Basic) ---
let SP_VALIDATED = false;
function spDisableApply() {
    SP_VALIDATED = false;
    const b = document.getElementById('sp_apply_btn');
    b.disabled = true; b.style.opacity = '.5'; b.style.cursor = 'not-allowed';
}
['sp_user','sp_pass'].forEach(id => document.addEventListener('input', e => { if (e.target && e.target.id === id) spDisableApply(); }));

async function sitePassTest() {
    const user = document.getElementById('sp_user').value.trim();
    const pass = document.getElementById('sp_pass').value;
    if (!user || !pass) { log('tls_sp_log', '✗ Enter a username and password'); return; }
    const box = document.getElementById('tls_sp_result');
    box.innerHTML = '<span style="color:#718096;font-size:12px">🧪 Testing in isolated folder…</span>';
    spDisableApply();
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    fd.append('user', user); fd.append('pass', pass);
    let j;
    try { const r = await fetch('?action=site_pass_test', {method:'POST', body:fd}); j = await r.json(); }
    catch(e) { box.innerHTML = `<span style="color:#f87171">✗ ${e.message}</span>`; return; }
    if (!j.ok) { box.innerHTML = `<span style="color:#f87171">✗ ${j.error}</span>`; return; }
    let badge;
    if (j.breaks)        badge = `<span class="badge badge-err">✗ HTTP ${j.status} — not supported (would 500)</span>`;
    else if (j.prompts_auth) badge = `<span class="badge badge-ok">✓ HTTP 401 — Basic auth works</span>`;
    else                 badge = `<span class="badge badge-warn">? HTTP ${j.status || 'no reply'} — inconclusive (test on real host)</span>`;
    box.innerHTML = `${badge}<div style="margin-top:6px;font-size:12px;color:#a0aec0">${esc(j.note)}</div>
        <div style="margin-top:6px;font-size:12px">🔗 <a href="${j.probe_url}" target="_blank" style="color:#7dd3fc">${esc(j.probe_url)}</a> <span style="color:#718096">(${T_SP.openprobe})</span></div>`;
    if (j.supported) {
        SP_VALIDATED = true;
        const b = document.getElementById('sp_apply_btn');
        b.disabled = false; b.style.opacity = '1'; b.style.cursor = 'pointer';
        log('tls_sp_log', '✓ Test passed. Apply enabled.', false);
    } else {
        log('tls_sp_log', 'ℹ Open the probe URL to confirm, or test on the real host. Apply stays disabled.', false);
    }
}

async function sitePassApply() {
    if (!SP_VALIDATED) { log('tls_sp_log', '✗ Run a successful Test first.'); return; }
    if (!confirm('Apply HTTP Basic auth to the WHOLE site? You will need the username/password to access any page.')) return;
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    fd.append('user', document.getElementById('sp_user').value.trim());
    fd.append('pass', document.getElementById('sp_pass').value);
    const r = await fetch('?action=site_pass_apply', {method:'POST', body:fd});
    const j = await r.json();
    log('tls_sp_log', j.ok ? `✓ ${j.note}` : `✗ ${j.error}`, false);
    if (j.ok) spDisableApply();
}

async function sitePassRemove() {
    if (!confirm('Remove the site-wide password protection?')) return;
    const fd = new FormData();
    Object.entries(formData('tls')).forEach(([k,v]) => fd.append(k,v));
    const r = await fetch('?action=site_pass_remove', {method:'POST', body:fd});
    const j = await r.json();
    log('tls_sp_log', j.ok ? `✓ ${j.note}` : `✗ ${j.error}`, false);
}
const T_SP = { openprobe: <?= json_encode(t('open to confirm the login prompt')) ?> };

// Download bigdump.php into the site; on success reveal the "Open BigDump" link.
async function fetchBigdump() {
    const btn = document.getElementById('bigdump_btn');
    btn.disabled = true; const prev = btn.textContent; btn.textContent = '⏳';
    try {
        const r = await fetch('?action=fetch_bigdump', {method:'POST'});
        const j = await r.json();
        if (j.ok) {
            const open = document.getElementById('bigdump_open');
            open.href = j.url; open.style.display = '';
            btn.textContent = '✓ ' + j.size;
            window.open(j.url, '_blank');
        } else {
            alert(j.error); btn.textContent = prev; btn.disabled = false;
        }
    } catch(e) { alert(e.message); btn.textContent = prev; btn.disabled = false; }
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
