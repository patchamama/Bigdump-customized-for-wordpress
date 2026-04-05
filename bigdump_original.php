<?php

error_reporting(E_ALL);

// BigDump ver. 0.36b from 2015-04-30 (modified)
// Staggered import of an large MySQL Dump (like phpMyAdmin 2.x Dump)
// Even through the webservers with hard runtime limit and those in safe mode
// Works fine with latest Chrome, Internet Explorer and Firefox

// Author:       Alexey Ozerov (alexey at ozerov dot de)
//               AJAX & CSV functionalities: Krzysiek Herod (kr81uni at wp dot pl)
// Copyright:    GPL (C) 2003-2015
// More Infos:   http://www.ozerov.de/bigdump

// *******************************************************************************************
// AUTO-DETECT wp-config.php DATABASE SETTINGS
// Searches up to 3 directory levels above this script for wp-config.php
// *******************************************************************************************

$db_server   = 'localhost';
$db_name     = '';
$db_username = '';
$db_password = '';

$wpconfig_paths = array(
  dirname(__FILE__) . '/wp-config.php',
  dirname(__FILE__) . '/../wp-config.php',
  dirname(__FILE__) . '/../../wp-config.php',
  dirname(__FILE__) . '/../../../wp-config.php',
);

foreach ($wpconfig_paths as $wpconfig_path) {
  if (file_exists($wpconfig_path)) {
    $wpconfig_content = file_get_contents($wpconfig_path);
    if (preg_match("/define\s*\(\s*'DB_NAME'\s*,\s*'([^']*)'\s*\)/",     $wpconfig_content, $m)) $db_name     = $m[1];
    if (preg_match("/define\s*\(\s*'DB_USER'\s*,\s*'([^']*)'\s*\)/",     $wpconfig_content, $m)) $db_username = $m[1];
    if (preg_match("/define\s*\(\s*'DB_PASSWORD'\s*,\s*'([^']*)'\s*\)/", $wpconfig_content, $m)) $db_password = $m[1];
    if (preg_match("/define\s*\(\s*'DB_HOST'\s*,\s*'([^']*)'\s*\)/",     $wpconfig_content, $m)) $db_server   = $m[1];
    $wpconfig_found = $wpconfig_path;
    break;
  }
}

// Connection charset
$db_connection_charset = 'utf8';

// OPTIONAL SETTINGS
$filename           = '';     // Specify the dump filename to suppress the file selection dialog
$ajax               = true;   // AJAX mode: import will be done without refreshing the website
$linespersession    = 3000;   // Lines to be executed per one import session
$delaypersession    = 0;      // Sleep time in milliseconds after each session
$max_query_lines    = 50000;  // Max lines per query

// CSV related settings
$csv_insert_table   = '';
$csv_preempty_table = false;
$csv_delimiter      = ',';
$csv_add_quotes     = true;
$csv_add_slashes    = true;

// Allowed comment markers
$comment[] = '#';
$comment[] = '-- ';
$comment[] = 'DELIMITER';
$comment[] = '/*!';

// Default query delimiter
$delimiter = ';';

// String quotes character
$string_quotes = '\'';

// Where to put the upload files into (default: bigdump folder)
$upload_dir = dirname(__FILE__);

// *******************************************************************************************
// If not familiar with PHP please don't change anything below this line
// *******************************************************************************************

if ($ajax)
  ob_start();

define('VERSION', '0.36b');
define('DATA_CHUNK_LENGTH', 16384);
define('TESTMODE', false);
define('BIGDUMP_DIR', dirname(__FILE__));
define('PLUGIN_DIR', BIGDUMP_DIR . '/plugins/');

header("Expires: Mon, 1 Dec 2003 01:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

@ini_set('auto_detect_line_endings', true);
@set_time_limit(0);

if (function_exists("date_default_timezone_set") && function_exists("date_default_timezone_get"))
  @date_default_timezone_set(@date_default_timezone_get());

// Clean and strip anything we don't want from user's input
foreach ($_REQUEST as $key => $val) {
  $val = preg_replace("/[^_A-Za-z0-9-\.&= ;\$]/i", '', $val);
  $_REQUEST[$key] = $val;
}

do_action('header');

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
<title>BigDump ver. <?php echo (VERSION); ?></title>
<meta http-equiv="CONTENT-TYPE" content="text/html; charset=utf-8"/>
<meta http-equiv="CONTENT-LANGUAGE" content="EN"/>
<meta http-equiv="Cache-Control" content="no-cache/"/>
<meta http-equiv="Pragma" content="no-cache"/>
<meta http-equiv="Expires" content="-1"/>
<meta name="robots" content="noindex, nofollow">

<?php do_action('head_meta'); ?>

<style type="text/css">
<!--

body { background-color:#FFFFF0; }

h1 {
  font-size:20px; line-height:24px;
  font-family:Arial,Helvetica,sans-serif;
  margin-top:5px; margin-bottom:5px;
}

p,td,th {
  font-size:14px; line-height:18px;
  font-family:Arial,Helvetica,sans-serif;
  margin-top:5px; margin-bottom:5px;
  text-align:justify; vertical-align:top;
}

p.centr    { text-align:center; }
p.smlcentr { font-size:10px; line-height:14px; text-align:center; }
p.error    { color:#FF0000; font-weight:bold; }
p.success  { color:#00DD00; font-weight:bold; }
p.successcentr { color:#00DD00; background-color:#DDDDFF; font-weight:bold; text-align:center; }
p.wpconfig { color:#006600; font-style:italic; font-size:12px; text-align:center; }

td { background-color:#F8F8F8; text-align:left; }
td.transparent { background-color:#FFFFF0; }
th { font-weight:bold; color:#FFFFFF; background-color:#AAAAEE; text-align:left; }
td.right { text-align:right; }
form { margin-top:5px; margin-bottom:5px; }

div.skin1 {
  border-color:#3333EE; border-width:5px; border-style:solid;
  background-color:#AAAAEE; text-align:center; vertical-align:middle;
  padding:3px; margin:1px;
}

td.bg3  { background-color:#EEEE99; text-align:left; vertical-align:top; width:20%; }
th.bg4  { background-color:#EEAA55; text-align:left; vertical-align:top; width:20%; }
td.bgpctbar { background-color:#EEEEAA; text-align:left; vertical-align:middle; width:80%; }

div.danger-banner {
  background-color:#FF0000; color:#FFFFFF;
  border:4px solid #990000; border-radius:6px;
  padding:20px 24px; margin:12px 0;
  font-family:Arial,Helvetica,sans-serif;
}
div.danger-banner h2 {
  font-size:20px; margin:0 0 10px 0; color:#FFFFFF; letter-spacing:1px;
}
div.danger-banner p {
  color:#FFFFFF; font-size:14px; line-height:20px;
  text-align:left; margin:6px 0;
}
div.danger-banner ul {
  color:#FFFFFF; font-size:14px; line-height:20px;
  margin:8px 0 12px 20px; padding:0;
}
div.danger-banner ul li { margin-bottom:4px; }
a.delete-btn {
  display:inline-block; background-color:#FFFFFF; color:#CC0000;
  font-weight:bold; font-size:15px; padding:10px 28px;
  border-radius:4px; text-decoration:none; border:2px solid #CC0000;
  margin-top:8px; cursor:pointer;
}
a.delete-btn:hover { background-color:#FFE0E0; }

<?php do_action('head_style'); ?>

-->
</style>

</head>
<body>
<center>
<table width="780" cellspacing="0" cellpadding="0">
<tr><td class="transparent">

<?php

function skin_open()  { echo('<div class="skin1">'); }
function skin_close() { echo('</div>'); }

skin_open();
echo('<h1>BigDump: Staggered MySQL Dump Importer v' . VERSION . '</h1>');
skin_close();

do_action('after_headline');

// *******************************************************************************************
// PRODUCTION ENVIRONMENT WARNING
// Detect if running outside localhost and show a danger banner with a self-delete button
// *******************************************************************************************

$is_localhost = in_array(
  $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '',
  ['localhost', '127.0.0.1', '::1']
) || preg_match('/^192\.168\.|^10\.|^172\.(1[6-9]|2[0-9]|3[01])\./', $_SERVER['SERVER_ADDR'] ?? '');

if (!$is_localhost) {
  $self_path   = __FILE__;
  $self_name   = basename($self_path);
  $delete_done = false;

  // Handle self-delete request
  if (isset($_POST['selfdelete']) && $_POST['selfdelete'] === 'CONFIRM') {
    if (@unlink($self_path)) {
      echo("<div class=\"danger-banner\" style=\"background-color:#006600;\">
        <h2>&#10003; Script deleted successfully</h2>
        <p>The file <b>$self_name</b> has been permanently removed from the server. Your site is now safe.</p>
      </div>");
      echo("</td></tr></table></center></body></html>");
      exit;
    } else {
      echo("<div class=\"danger-banner\">
        <h2>&#9888; Could not delete the script automatically</h2>
        <p>Please delete <b>$self_path</b> manually via FTP or SSH immediately.</p>
      </div>");
    }
  }

  // Show danger banner on every request when not localhost
  $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
               . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
  echo("
  <div class=\"danger-banner\">
    <h2>&#9888;&nbsp; DANGER: This script is exposed on a production server!</h2>
    <p>BigDump is a <strong>powerful database import tool</strong> that should NEVER be left on a live server. It was detected that you are running this script on:</p>
    <p><b>" . htmlspecialchars($current_url) . "</b></p>
    <p>Leaving this file on your server poses serious security risks:</p>
    <ul>
      <li><b>Full database access:</b> Anyone who finds this URL can read, overwrite or destroy your entire database without authentication.</li>
      <li><b>No password protection:</b> BigDump has no built-in login or access control. It is publicly accessible.</li>
      <li><b>Data loss:</b> The \"Drop tables &amp; Import\" feature can wipe your database in seconds.</li>
      <li><b>Data exfiltration:</b> An attacker can use this script to inject malicious SQL or extract all your data.</li>
      <li><b>Site takeover:</b> A WordPress or CMS database import can introduce backdoor admin accounts.</li>
    </ul>
    <p><strong>Delete this script immediately after use.</strong></p>
    <form method=\"POST\" action=\"" . htmlspecialchars($_SERVER['PHP_SELF']) . "\" onsubmit=\"return confirm('Are you sure you want to permanently delete this script from the server? This cannot be undone.');\">
      <input type=\"hidden\" name=\"selfdelete\" value=\"CONFIRM\">
      <button type=\"submit\" class=\"delete-btn\">&#128465;&nbsp; Delete this script from the server NOW</button>
    </form>
  </div>
  ");
}

// Show wp-config.php detection result
if (isset($wpconfig_found)) {
  echo("<p class=\"wpconfig\">&#10003; Database settings loaded from: <b>" . htmlspecialchars($wpconfig_found) . "</b> &mdash; DB: <b>$db_name</b> @ <b>$db_server</b></p>\n");
} else {
  echo("<p class=\"error\">&#9888; wp-config.php not found. Using empty database settings &mdash; edit this script manually.</p>\n");
}

$error = false;
$file  = false;

// Check PHP version
if (!$error && !function_exists('version_compare')) {
  echo("<p class=\"error\">PHP version 4.1.0 is required for BigDump to proceed. You have PHP " . phpversion() . " installed. Sorry!</p>\n");
  $error = true;
}

// Check if mysqli extension is available
if (!$error && !function_exists('mysqli_connect')) {
  echo("<p class=\"error\">There is no mySQLi extension found in your PHP installation.</p>\n");
  $error = true;
}

// Calculate PHP max upload size
if (!$error) {
  $upload_max_filesize = ini_get("upload_max_filesize");
  if (preg_match("/([0-9]+)K/i", $upload_max_filesize, $tempregs)) $upload_max_filesize = $tempregs[1] * 1024;
  if (preg_match("/([0-9]+)M/i", $upload_max_filesize, $tempregs)) $upload_max_filesize = $tempregs[1] * 1024 * 1024;
  if (preg_match("/([0-9]+)G/i", $upload_max_filesize, $tempregs)) $upload_max_filesize = $tempregs[1] * 1024 * 1024 * 1024;
}

do_action('script_runs');

// Handle file upload
if (!$error && isset($_REQUEST["uploadbutton"])) {
  if (is_uploaded_file($_FILES["dumpfile"]["tmp_name"]) && ($_FILES["dumpfile"]["error"]) == 0) {
    $uploaded_filename = str_replace(" ", "_", $_FILES["dumpfile"]["name"]);
    $uploaded_filename = preg_replace("/[^_A-Za-z0-9-\.]/i", '', $uploaded_filename);
    $uploaded_filepath = str_replace("\\", "/", $upload_dir . "/" . $uploaded_filename);

    do_action('file_uploaded');

    if (file_exists($uploaded_filepath)) {
      echo("<p class=\"error\">File $uploaded_filename already exists! Delete it and upload again.</p>\n");
    } elseif (!preg_match("/(\.(sql|gz|zip|csv))$/i", $uploaded_filename)) {
      echo("<p class=\"error\">You may only upload .sql, .gz, .zip or .csv files.</p>\n");
    } elseif (!@move_uploaded_file($_FILES["dumpfile"]["tmp_name"], $uploaded_filepath)) {
      echo("<p class=\"error\">Error moving uploaded file to $uploaded_filepath</p>\n");
      echo("<p>Check directory permissions for $upload_dir (must be writable).</p>\n");
    } else {
      echo("<p class=\"success\">File uploaded as $uploaded_filename</p>\n");
    }
  } else {
    echo("<p class=\"error\">Error uploading file " . htmlspecialchars($_FILES["dumpfile"]["name"]) . "</p>\n");
  }
}

// Handle file deletion
if (!$error && isset($_REQUEST["delete"]) && $_REQUEST["delete"] != basename($_SERVER["SCRIPT_FILENAME"])) {
  if (preg_match("/(\.(sql|gz|zip|csv))$/i", $_REQUEST["delete"]) && @unlink($upload_dir . '/' . $_REQUEST["delete"]))
    echo("<p class=\"success\">" . htmlspecialchars($_REQUEST["delete"]) . " was removed successfully</p>\n");
  else
    echo("<p class=\"error\">Can't remove " . htmlspecialchars($_REQUEST["delete"]) . "</p>\n");
}

// Connect to the database, set charset and execute pre-queries
if (!$error && !TESTMODE) {
  try {
    mysqli_report(MYSQLI_REPORT_STRICT);
    $mysqli = new mysqli($db_server, $db_username, $db_password, $db_name);
    mysqli_report(MYSQLI_REPORT_OFF);

    if (!$error && $db_connection_charset !== '') {
      if (!$mysqli->set_charset($db_connection_charset)) {
        echo("<div class=\"danger-banner\" style=\"background-color:#cc6600;\">
          <h2>&#9888; Charset Error</h2>
          <p>Could not set connection charset to <b>$db_connection_charset</b>.</p>
          <p><b>MySQL error:</b> " . htmlspecialchars($mysqli->error) . "</p>
        </div>\n");
      }
    }

    if (!$error && isset($pre_query) && sizeof($pre_query) > 0) {
      reset($pre_query);
      foreach ($pre_query as $pre_query_value) {
        if (!$mysqli->query($pre_query_value)) {
          echo("<div class=\"danger-banner\" style=\"background-color:#cc6600;\">
            <h2>&#9888; Pre-query Error</h2>
            <p><b>Query:</b> " . trim(nl2br(htmlentities($pre_query_value))) . "</p>
            <p><b>MySQL error:</b> " . htmlspecialchars($mysqli->error) . "</p>
          </div>\n");
          $error = true;
          break;
        }
      }
    }

  } catch (mysqli_sql_exception $e) {
    $conn_error_code = $e->getCode();
    $conn_error_msg  = $e->getMessage();

    // Map common error codes to human-readable explanations
    $explanations = [
      1045 => "Access denied — the username or password in wp-config.php is wrong.",
      1049 => "Unknown database — the database name in wp-config.php does not exist on this server.",
      2002 => "Can't connect to MySQL server — the DB host is unreachable or MySQL is not running.",
      2003 => "Can't connect to MySQL server on '$db_server' — check that MySQL is running and the host is correct.",
      2005 => "Unknown MySQL server host '$db_server' — the DB_HOST value in wp-config.php cannot be resolved.",
      1044 => "Access denied for user '$db_username' to database '$db_name' — check DB user permissions.",
    ];
    $explanation = $explanations[$conn_error_code] ?? "Unexpected connection error (code $conn_error_code).";

    echo("<div class=\"danger-banner\">
      <h2>&#9888; Database Connection Failed</h2>
      <p><b>Error ($conn_error_code):</b> " . htmlspecialchars($conn_error_msg) . "</p>
      <p><b>What this means:</b> $explanation</p>
      <ul>
        <li>Server: <b>" . htmlspecialchars($db_server)   . "</b></li>
        <li>Database: <b>" . htmlspecialchars($db_name)   . "</b></li>
        <li>Username: <b>" . htmlspecialchars($db_username) . "</b></li>
        <li>wp-config.php: <b>" . (isset($wpconfig_found) ? htmlspecialchars($wpconfig_found) : 'NOT FOUND — using empty defaults') . "</b></li>
      </ul>
      <p>Fix the credentials in wp-config.php or verify that the MySQL server is running.</p>
    </div>\n");
    $error = true;
  }
} else {
  $dbconnection = false;
}

do_action('database_connected');

// *******************************************************************************************
// DROP ALL TABLES in the database when "dropandimport" action is requested
// *******************************************************************************************

if (!$error && !TESTMODE && isset($_REQUEST["dropandimport"])) {
  $fn_to_import = isset($_REQUEST["fn_to_import"]) ? $_REQUEST["fn_to_import"] : '';
  if ($fn_to_import !== '' && preg_match("/(\.(sql|gz|zip))$/i", $fn_to_import)) {
    // Drop all tables
    $mysqli->query("SET foreign_key_checks = 0");
    $tables_result = $mysqli->query("SHOW TABLES");
    $dropped = 0;
    if ($tables_result) {
      while ($trow = $tables_result->fetch_array()) {
        $tname = $trow[0];
        $mysqli->query("DROP TABLE IF EXISTS `$tname`");
        $dropped++;
      }
    }
    $mysqli->query("SET foreign_key_checks = 1");
    echo("<p class=\"success\">Dropped $dropped existing table(s) from <b>$db_name</b>. Starting import...</p>\n");
    // Redirect to the actual import
    $redirect_url = $_SERVER["PHP_SELF"] . "?start=1&fn=" . urlencode($fn_to_import) . "&foffset=0&totalqueries=0&delimiter=" . urlencode($delimiter);
    echo("<script type=\"text/javascript\">window.setTimeout('location.href=\"" . $redirect_url . "\";', 1500);</script>\n");
    echo("<p class=\"centr\"><a href=\"" . htmlspecialchars($redirect_url) . "\">Click here if not redirected automatically</a></p>\n");
  } else {
    echo("<p class=\"error\">Invalid file specified for import.</p>\n");
  }
}

// List files in multifile mode
if (!$error && !isset($_REQUEST["fn"]) && !isset($_REQUEST["dropandimport"]) && $filename == "") {
  if ($dirhandle = opendir($upload_dir)) {
    $files = array();
    while (false !== ($f = readdir($dirhandle))) $files[] = $f;
    closedir($dirhandle);
    $dirhead = false;

    if (sizeof($files) > 0) {
      sort($files);
      foreach ($files as $dirfile) {
        if ($dirfile != "." && $dirfile != ".."
          && $dirfile != basename($_SERVER["SCRIPT_FILENAME"])
          && preg_match("/\.(sql|gz|zip)$/i", $dirfile))
        {
          if (!$dirhead) {
            echo("<table width=\"100%\" cellspacing=\"2\" cellpadding=\"2\">\n");
            echo("<tr><th>Filename</th><th>Size</th><th>Date &amp; Time</th><th>Type</th><th>Action</th><th>&nbsp;</th></tr>\n");
            $dirhead = true;
          }

          $fsize = number_format(filesize($upload_dir . '/' . $dirfile));
          $fdate = date("Y-m-d H:i:s", filemtime($upload_dir . '/' . $dirfile));

          if      (preg_match("/\.sql$/i",  $dirfile)) $ftype = 'SQL';
          elseif  (preg_match("/\.gz$/i",   $dirfile)) $ftype = 'GZip';
          elseif  (preg_match("/\.zip$/i",  $dirfile)) $ftype = 'ZIP';
          else                                          $ftype = 'Misc';

          $can_import = (
            (preg_match("/\.gz$/i",  $dirfile) && function_exists("gzopen")) ||
            preg_match("/\.sql$/i",  $dirfile) ||
            preg_match("/\.zip$/i",  $dirfile)
          );

          echo("<tr><td>" . htmlspecialchars($dirfile) . "</td>");
          echo("<td class=\"right\">$fsize</td>");
          echo("<td>$fdate</td>");
          echo("<td>$ftype</td>");

          if ($can_import) {
            $direct_url = $_SERVER["PHP_SELF"] . "?start=1&fn=" . urlencode($dirfile) . "&foffset=0&totalqueries=0&delimiter=" . urlencode($delimiter);
            $drop_url   = $_SERVER["PHP_SELF"] . "?dropandimport=1&fn_to_import=" . urlencode($dirfile);
            echo("<td>");
            echo("<a href=\"" . htmlspecialchars($direct_url) . "\">Import</a> into <b>$db_name</b>");
            echo(" &nbsp;|&nbsp; ");
            echo("<a href=\"" . htmlspecialchars($drop_url) . "\" onclick=\"return confirm('This will DROP ALL TABLES in $db_name before importing. Continue?');\">Drop tables &amp; Import</a>");
            echo("</td>\n");
          } else {
            echo("<td>&nbsp;</td>\n");
          }

          echo("<td><a href=\"" . $_SERVER["PHP_SELF"] . "?delete=" . urlencode($dirfile) . "\" onclick=\"return confirm('Delete " . htmlspecialchars($dirfile) . "?');\">Delete</a></td></tr>\n");
        }
      }
    }

    if ($dirhead)
      echo("</table>\n");
    else
      echo("<p>No SQL, GZ or ZIP files found in the working directory: <i>$upload_dir</i></p>\n");
  } else {
    echo("<p class=\"error\">Error listing directory $upload_dir</p>\n");
    $error = true;
  }
}

// Single file mode
if (!$error && !isset($_REQUEST["fn"]) && $filename != "") {
  echo("<p><a href=\"" . $_SERVER["PHP_SELF"] . "?start=1&amp;fn=" . urlencode($filename) . "&amp;foffset=0&amp;totalqueries=0\">Start Import</a> from $filename into $db_name at $db_server</p>\n");
}

// File Upload Form
if (!$error && !isset($_REQUEST["fn"]) && !isset($_REQUEST["dropandimport"]) && $filename == "") {
  do {
    $tempfilename = $upload_dir . '/' . time() . ".tmp";
  } while (file_exists($tempfilename));

  if (!($tempfile = @fopen($tempfilename, "w"))) {
    echo("<p>Upload form disabled. Directory <i>$upload_dir</i> must be writable. Upload files via FTP instead.</p>\n");
  } else {
    fclose($tempfile);
    unlink($tempfilename);
    echo("<p>Upload a dump file (max " . round($upload_max_filesize / 1024 / 1024) . " MB). Accepted formats: .sql, .gz, .zip</p>\n");
?>
<form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" enctype="multipart/form-data">
<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo $upload_max_filesize; ?>">
<p>Dump file: <input type="file" name="dumpfile" accept=".sql,.gz,.zip" size="60"></p>
<p><input type="submit" name="uploadbutton" value="Upload"></p>
</form>
<?php
  }
}

// Print the current MySQL connection charset
if (!$error && !TESTMODE && !isset($_REQUEST["fn"]) && !isset($_REQUEST["dropandimport"])) {
  $result = $mysqli->query("SHOW VARIABLES LIKE 'character_set_connection';");
  if ($result) {
    $row = $result->fetch_assoc();
    if ($row) {
      $charset = $row['Value'];
      echo("<p>Note: MySQL connection charset is <i>$charset</i>. Your dump must be encoded in <i>$charset</i>.</p>\n");
    }
    $result->free();
  }
}

// Open the file
if (!$error && isset($_REQUEST["start"])) {

  if ($filename != "")
    $curfilename = $filename;
  elseif (isset($_REQUEST["fn"]))
    $curfilename = urldecode($_REQUEST["fn"]);
  else
    $curfilename = "";

  // Recognize GZip or ZIP filename
  if (preg_match("/\.gz$/i", $curfilename))
    $gzipmode = true;
  else
    $gzipmode = false;

  // ZIP support: extract .sql from zip and treat as plain SQL
  $zipmode = false;
  if (preg_match("/\.zip$/i", $curfilename)) {
    $zipmode = true;
    if (!class_exists('ZipArchive')) {
      echo("<p class=\"error\">ZipArchive extension is required to import .zip files but is not available.</p>\n");
      $error = true;
    } else {
      $zip = new ZipArchive();
      if ($zip->open($upload_dir . '/' . $curfilename) === true) {
        // Find first .sql entry
        $sql_entry = '';
        for ($zi = 0; $zi < $zip->numFiles; $zi++) {
          $zname = $zip->getNameIndex($zi);
          if (preg_match("/\.sql$/i", $zname)) { $sql_entry = $zname; break; }
        }
        if ($sql_entry === '') {
          echo("<p class=\"error\">No .sql file found inside the ZIP archive.</p>\n");
          $error = true;
        } else {
          $tmp_sql = $upload_dir . '/' . time() . '_bigdump_extracted.sql';
          file_put_contents($tmp_sql, $zip->getFromName($sql_entry));
          $zip->close();
          $curfilename_real = $tmp_sql;
          register_shutdown_function(function() use ($tmp_sql) { @unlink($tmp_sql); });
        }
      } else {
        echo("<p class=\"error\">Can't open ZIP file: $curfilename</p>\n");
        $error = true;
      }
    }
  }

  if (!$error) {
    $open_path = isset($curfilename_real) ? $curfilename_real : $upload_dir . '/' . $curfilename;
    if ((!$gzipmode && !$file = @fopen($open_path, "r")) || ($gzipmode && !$file = @gzopen($open_path, "r"))) {
      echo("<p class=\"error\">Can't open " . htmlspecialchars($curfilename) . " for import</p>\n");
      echo("<p>Check that the filename contains only alphanumeric characters, or upload it first.</p>\n");
      $error = true;
    } elseif ((!$gzipmode && @fseek($file, 0, SEEK_END) == 0) || ($gzipmode && @gzseek($file, 0) == 0)) {
      if (!$gzipmode) $filesize = ftell($file);
      else            $filesize = gztell($file);
    } else {
      echo("<p class=\"error\">I can't seek into " . htmlspecialchars($curfilename) . "</p>\n");
      $error = true;
    }
  }

  if (!$error && ($csv_insert_table == "") && (preg_match("/(\.csv)$/i", $curfilename))) {
    echo("<p class=\"error\">You have to specify \$csv_insert_table when using a CSV file.</p>\n");
    $error = true;
  }
}

// *******************************************************************************************
// START IMPORT SESSION HERE
// *******************************************************************************************

if (!$error && isset($_REQUEST["start"]) && isset($_REQUEST["foffset"]) && preg_match("/(\.(sql|gz|zip))$/i", $curfilename)) {

  do_action('session_start');

  if (!is_numeric($_REQUEST["start"]) || !is_numeric($_REQUEST["foffset"])) {
    echo("<p class=\"error\">UNEXPECTED: Non-numeric values for start and foffset</p>\n");
    $error = true;
  } else {
    $_REQUEST["start"]   = floor($_REQUEST["start"]);
    $_REQUEST["foffset"] = floor($_REQUEST["foffset"]);
  }

  if (isset($_REQUEST["delimiter"]))
    $delimiter = $_REQUEST["delimiter"];

  if (!$error && $_REQUEST["start"] == 1 && $csv_insert_table != "" && $csv_preempty_table) {
    $query = "DELETE FROM `$csv_insert_table`";
    if (!TESTMODE && !$mysqli->query(trim($query))) {
      echo("<p class=\"error\">Error deleting entries from $csv_insert_table.</p>\n");
      echo("<p>MySQL: " . $mysqli->error . "</p>\n");
      $error = true;
    }
  }

  if (!$error) {
    skin_open();
    if (TESTMODE) echo("<p class=\"centr\">TEST MODE ENABLED</p>\n");
    echo("<p class=\"centr\">Processing file: <b>" . htmlspecialchars($curfilename) . "</b></p>\n");
    echo("<p class=\"smlcentr\">Starting from line: " . $_REQUEST["start"] . "</p>\n");
    skin_close();
  }

  if (!$error && !$gzipmode && $_REQUEST["foffset"] > $filesize) {
    echo("<p class=\"error\">UNEXPECTED: Can't set file pointer behind the end of file</p>\n");
    $error = true;
  }

  if (!$error && ((!$gzipmode && fseek($file, $_REQUEST["foffset"]) != 0) || ($gzipmode && gzseek($file, $_REQUEST["foffset"]) != 0))) {
    echo("<p class=\"error\">UNEXPECTED: Can't set file pointer to offset: " . $_REQUEST["foffset"] . "</p>\n");
    $error = true;
  }

  if (!$error) {
    $query       = "";
    $queries     = 0;
    $totalqueries = $_REQUEST["totalqueries"];
    $linenumber  = $_REQUEST["start"];
    $querylines  = 0;
    $inparents   = false;

    while ($linenumber < $_REQUEST["start"] + $linespersession || $query != "") {

      $dumpline = "";
      while (!feof($file) && substr($dumpline, -1) != "\n" && substr($dumpline, -1) != "\r") {
        if (!$gzipmode) $dumpline .= fgets($file, DATA_CHUNK_LENGTH);
        else            $dumpline .= gzgets($file, DATA_CHUNK_LENGTH);
      }
      if ($dumpline === "") break;

      if ($_REQUEST["foffset"] == 0)
        $dumpline = preg_replace('|^\xEF\xBB\xBF|', '', $dumpline);

      if (($csv_insert_table != "") && (preg_match("/(\.csv)$/i", $curfilename))) {
        if ($csv_add_slashes)  $dumpline = addslashes($dumpline);
        $dumpline = explode($csv_delimiter, $dumpline);
        if ($csv_add_quotes)   $dumpline = "'" . implode("','", $dumpline) . "'";
        else                   $dumpline = implode(",", $dumpline);
        $dumpline = 'INSERT INTO ' . $csv_insert_table . ' VALUES (' . $dumpline . ');';
      }

      $dumpline = str_replace("\r\n", "\n", $dumpline);
      $dumpline = str_replace("\r", "\n", $dumpline);

      if (!$inparents && strpos($dumpline, "DELIMITER ") === 0)
        $delimiter = str_replace("DELIMITER ", "", trim($dumpline));

      if (!$inparents) {
        $skipline = false;
        reset($comment);
        foreach ($comment as $comment_value) {
          if (trim($dumpline) == "" || strpos(trim($dumpline), $comment_value) === 0) {
            $skipline = true;
            break;
          }
        }
        if ($skipline) { $linenumber++; continue; }
      }

      $dumpline_deslashed = str_replace("\\\\", "", $dumpline);
      $parents = substr_count($dumpline_deslashed, $string_quotes) - substr_count($dumpline_deslashed, "\\$string_quotes");
      if ($parents % 2 != 0) $inparents = !$inparents;

      $query .= $dumpline;

      if (!$inparents) $querylines++;

      if ($querylines > $max_query_lines) {
        echo("<p class=\"error\">Stopped at line $linenumber. Query exceeds $max_query_lines lines.</p>");
        $error = true;
        break;
      }

      if ((preg_match('/' . preg_quote($delimiter, '/') . '$/', trim($dumpline)) || $delimiter == '') && !$inparents) {
        $query = substr(trim($query), 0, -1 * strlen($delimiter));

        if (!TESTMODE && !$mysqli->query($query)) {
          echo("<p class=\"error\">Error at line $linenumber: " . trim(htmlspecialchars($dumpline)) . "</p>\n");
          echo("<p>Query: " . trim(nl2br(htmlentities($query))) . "</p>\n");
          echo("<p>MySQL: " . $mysqli->error . "</p>\n");
          $error = true;
          break;
        }
        $totalqueries++;
        $queries++;
        $query      = "";
        $querylines = 0;
      }
      $linenumber++;
    }
  }

  // Get current file position
  if (!$error) {
    if (!$gzipmode) $foffset = ftell($file);
    else            $foffset = gztell($file);
    if (!$foffset) {
      echo("<p class=\"error\">UNEXPECTED: Can't read the file pointer offset</p>\n");
      $error = true;
    }
  }

  // Print statistics
  skin_open();

  if (!$error) {
    $lines_this = $linenumber - $_REQUEST["start"];
    $lines_done = $linenumber - 1;
    $lines_togo = ' ? ';
    $lines_tota = ' ? ';

    $queries_this = $queries;
    $queries_done = $totalqueries;
    $queries_togo = ' ? ';
    $queries_tota = ' ? ';

    $bytes_this  = $foffset - $_REQUEST["foffset"];
    $bytes_done  = $foffset;
    $kbytes_this = round($bytes_this / 1024, 2);
    $kbytes_done = round($bytes_done / 1024, 2);
    $mbytes_this = round($kbytes_this / 1024, 2);
    $mbytes_done = round($kbytes_done / 1024, 2);

    if (!$gzipmode) {
      $bytes_togo  = $filesize - $foffset;
      $bytes_tota  = $filesize;
      $kbytes_togo = round($bytes_togo / 1024, 2);
      $kbytes_tota = round($bytes_tota / 1024, 2);
      $mbytes_togo = round($kbytes_togo / 1024, 2);
      $mbytes_tota = round($kbytes_tota / 1024, 2);

      $pct_this = ceil($bytes_this / $filesize * 100);
      $pct_done = ceil($foffset / $filesize * 100);
      $pct_togo = 100 - $pct_done;
      $pct_tota = 100;

      if ($bytes_togo == 0) {
        $lines_togo   = '0';
        $lines_tota   = $linenumber - 1;
        $queries_togo = '0';
        $queries_tota = $totalqueries;
      }

      $pct_bar = "<div style=\"height:15px;width:$pct_done%;background-color:#000080;margin:0px;\"></div>";
    } else {
      $bytes_togo = $kbytes_togo = $mbytes_togo = ' ? ';
      $bytes_tota = $kbytes_tota = $mbytes_tota = ' ? ';
      $pct_this = $pct_done = $pct_togo = ' ? ';
      $pct_tota = 100;
      $pct_bar  = str_replace(' ', '&nbsp;', '<tt>[         Not available for gzipped files          ]</tt>');
    }

    echo("
    <center>
    <table width=\"520\" border=\"0\" cellpadding=\"3\" cellspacing=\"1\">
    <tr><th class=\"bg4\"> </th><th class=\"bg4\">Session</th><th class=\"bg4\">Done</th><th class=\"bg4\">To go</th><th class=\"bg4\">Total</th></tr>
    <tr><th class=\"bg4\">Lines</th><td class=\"bg3\">$lines_this</td><td class=\"bg3\">$lines_done</td><td class=\"bg3\">$lines_togo</td><td class=\"bg3\">$lines_tota</td></tr>
    <tr><th class=\"bg4\">Queries</th><td class=\"bg3\">$queries_this</td><td class=\"bg3\">$queries_done</td><td class=\"bg3\">$queries_togo</td><td class=\"bg3\">$queries_tota</td></tr>
    <tr><th class=\"bg4\">Bytes</th><td class=\"bg3\">$bytes_this</td><td class=\"bg3\">$bytes_done</td><td class=\"bg3\">$bytes_togo</td><td class=\"bg3\">$bytes_tota</td></tr>
    <tr><th class=\"bg4\">KB</th><td class=\"bg3\">$kbytes_this</td><td class=\"bg3\">$kbytes_done</td><td class=\"bg3\">$kbytes_togo</td><td class=\"bg3\">$kbytes_tota</td></tr>
    <tr><th class=\"bg4\">MB</th><td class=\"bg3\">$mbytes_this</td><td class=\"bg3\">$mbytes_done</td><td class=\"bg3\">$mbytes_togo</td><td class=\"bg3\">$mbytes_tota</td></tr>
    <tr><th class=\"bg4\">%</th><td class=\"bg3\">$pct_this</td><td class=\"bg3\">$pct_done</td><td class=\"bg3\">$pct_togo</td><td class=\"bg3\">$pct_tota</td></tr>
    <tr><th class=\"bg4\">% bar</th><td class=\"bgpctbar\" colspan=\"4\">$pct_bar</td></tr>
    </table>
    </center>
    \n");

    if ($linenumber < $_REQUEST["start"] + $linespersession) {
      echo("<p class=\"successcentr\">Congratulations: End of file reached, import completed successfully!</p>\n");
      echo("<p class=\"successcentr\">IMPORTANT: REMOVE YOUR DUMP FILE and BIGDUMP SCRIPT FROM SERVER NOW!</p>\n");
      do_action('script_finished');
      $error = true; // Semi-error: tells the script it's finished
    } else {
      if ($delaypersession != 0)
        echo("<p class=\"centr\">Waiting <b>$delaypersession ms</b> before next session...</p>\n");

      if (!$ajax)
        echo("<script language=\"JavaScript\" type=\"text/javascript\">window.setTimeout('location.href=\"" . $_SERVER["PHP_SELF"] . "?start=$linenumber&fn=" . urlencode($curfilename) . "&foffset=$foffset&totalqueries=$totalqueries&delimiter=" . urlencode($delimiter) . "\";',500+$delaypersession);</script>\n");

      echo("<noscript>\n");
      echo("<p class=\"centr\"><a href=\"" . $_SERVER["PHP_SELF"] . "?start=$linenumber&amp;fn=" . urlencode($curfilename) . "&amp;foffset=$foffset&amp;totalqueries=$totalqueries&amp;delimiter=" . urlencode($delimiter) . "\">Continue from line $linenumber</a> (Enable JavaScript to continue automatically)</p>\n");
      echo("</noscript>\n");
      echo("<p class=\"centr\">Press <b><a href=\"" . $_SERVER["PHP_SELF"] . "\">STOP</a></b> to abort the import <b>OR WAIT!</b></p>\n");
    }
  } else {
    echo("<p class=\"error\">Stopped on error</p>\n");
  }

  skin_close();
}

if ($error)
  echo("<p class=\"centr\"><a href=\"" . $_SERVER["PHP_SELF"] . "\">Start from the beginning</a></p>\n");

if (isset($mysqli) && $mysqli) $mysqli->close();
if (isset($file) && $file && !$gzipmode) fclose($file);
elseif (isset($file) && $file && $gzipmode) gzclose($file);

?>

<p class="centr">&copy; 2003-2015 <a href="mailto:alexey@ozerov.de">Alexey Ozerov</a></p>

</td></tr></table>
</center>
<?php do_action('end_of_body'); ?>
</body>
</html>

<?php

if ($error) {
  $out1 = ob_get_contents();
  ob_end_clean();
  echo $out1;
  die;
}

if ($ajax && isset($_REQUEST['start'])) {
  if (isset($_REQUEST['ajaxrequest'])) {
    ob_end_clean();
    create_xml_response();
    die;
  } else {
    create_ajax_script();
  }
}

ob_flush();

// *******************************************************************************************
// Plugin handling (EXPERIMENTAL)
// *******************************************************************************************

function do_action($tag) {
  global $plugin_actions;
  if (isset($plugin_actions[$tag])) {
    reset($plugin_actions[$tag]);
    foreach ($plugin_actions[$tag] as $action)
      call_user_func_array($action, array());
  }
}

function add_action($tag, $function) {
  global $plugin_actions;
  $plugin_actions[$tag][] = $function;
}

// *******************************************************************************************
// AJAX utilities
// *******************************************************************************************

function create_xml_response() {
  global $linenumber, $foffset, $totalqueries, $curfilename, $delimiter,
         $lines_this, $lines_done, $lines_togo, $lines_tota,
         $queries_this, $queries_done, $queries_togo, $queries_tota,
         $bytes_this, $bytes_done, $bytes_togo, $bytes_tota,
         $kbytes_this, $kbytes_done, $kbytes_togo, $kbytes_tota,
         $mbytes_this, $mbytes_done, $mbytes_togo, $mbytes_tota,
         $pct_this, $pct_done, $pct_togo, $pct_tota, $pct_bar;

  header('Content-Type: application/xml');
  header('Cache-Control: no-cache');

  echo '<?xml version="1.0" encoding="UTF-8"?>';
  echo "<root>";
  echo "<linenumber>$linenumber</linenumber>";
  echo "<foffset>$foffset</foffset>";
  echo "<fn>$curfilename</fn>";
  echo "<totalqueries>$totalqueries</totalqueries>";
  echo "<delimiter>$delimiter</delimiter>";
  echo "<elem1>$lines_this</elem1>";
  echo "<elem2>$lines_done</elem2>";
  echo "<elem3>$lines_togo</elem3>";
  echo "<elem4>$lines_tota</elem4>";
  echo "<elem5>$queries_this</elem5>";
  echo "<elem6>$queries_done</elem6>";
  echo "<elem7>$queries_togo</elem7>";
  echo "<elem8>$queries_tota</elem8>";
  echo "<elem9>$bytes_this</elem9>";
  echo "<elem10>$bytes_done</elem10>";
  echo "<elem11>$bytes_togo</elem11>";
  echo "<elem12>$bytes_tota</elem12>";
  echo "<elem13>$kbytes_this</elem13>";
  echo "<elem14>$kbytes_done</elem14>";
  echo "<elem15>$kbytes_togo</elem15>";
  echo "<elem16>$kbytes_tota</elem16>";
  echo "<elem17>$mbytes_this</elem17>";
  echo "<elem18>$mbytes_done</elem18>";
  echo "<elem19>$mbytes_togo</elem19>";
  echo "<elem20>$mbytes_tota</elem20>";
  echo "<elem21>$pct_this</elem21>";
  echo "<elem22>$pct_done</elem22>";
  echo "<elem23>$pct_togo</elem23>";
  echo "<elem24>$pct_tota</elem24>";
  echo "<elem_bar>" . htmlentities($pct_bar) . "</elem_bar>";
  echo "</root>";
}

function create_ajax_script() {
  global $linenumber, $foffset, $totalqueries, $delaypersession, $curfilename, $delimiter;
?>

<script type="text/javascript" language="javascript">

function get_url(linenumber, fn, foffset, totalqueries, delimiter) {
  return "<?php echo $_SERVER['PHP_SELF'] ?>?start=" + linenumber + "&fn=" + fn + "&foffset=" + foffset + "&totalqueries=" + totalqueries + "&delimiter=" + delimiter + "&ajaxrequest=true";
}

function get_xml_data(itemname, xmld) {
  return xmld.getElementsByTagName(itemname).item(0).firstChild.data;
}

function makeRequest(url) {
  http_request = false;
  if (window.XMLHttpRequest) {
    http_request = new XMLHttpRequest();
    if (http_request.overrideMimeType) http_request.overrideMimeType("text/xml");
  } else if (window.ActiveXObject) {
    try { http_request = new ActiveXObject("Msxml2.XMLHTTP"); }
    catch(e) { try { http_request = new ActiveXObject("Microsoft.XMLHTTP"); } catch(e) {} }
  }
  if (!http_request) { alert("Cannot create an XMLHTTP instance"); return false; }
  http_request.onreadystatechange = server_response;
  http_request.open("GET", url, true);
  http_request.send(null);
}

function server_response() {
  if (http_request.readyState != 4) return;
  if (http_request.status != 200) { alert("Page unavailable, or wrong url!"); return; }

  var r = http_request.responseXML;

  if (!r || r.getElementsByTagName('root').length == 0) {
    var text = http_request.responseText;
    document.open(); document.write(text); document.close();
    return;
  }

  document.getElementsByTagName('p').item(1).innerHTML =
    "Starting from line: " + r.getElementsByTagName('linenumber').item(0).firstChild.nodeValue;

  for (var i = 1; i <= 24; i++)
    document.getElementsByTagName('td').item(i).firstChild.data = get_xml_data('elem' + i, r);

  document.getElementsByTagName('td').item(25).innerHTML =
    r.getElementsByTagName('elem_bar').item(0).firstChild.nodeValue;

  url_request = get_url(
    get_xml_data('linenumber', r),
    get_xml_data('fn', r),
    get_xml_data('foffset', r),
    get_xml_data('totalqueries', r),
    get_xml_data('delimiter', r));

  window.setTimeout("makeRequest(url_request)", 500 + <?php echo $delaypersession; ?>);
}

var http_request = false;
var url_request = get_url(<?php echo ($linenumber . ',"' . urlencode($curfilename) . '",' . $foffset . ',' . $totalqueries . ',"' . urlencode($delimiter) . '"'); ?>);
window.setTimeout("makeRequest(url_request)", 500 + <?php echo $delaypersession; ?>);
</script>

<?php
}
?>
