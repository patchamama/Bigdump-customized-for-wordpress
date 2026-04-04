# BigDump — WordPress Edition

> **⚠ SECURITY WARNING:** This script provides unauthenticated access to your database and server filesystem. **Never leave it on a production server.** See the [Security section](#-security-warning) below.

A powerful, browser-based MySQL import tool originally created by [Alexey Ozerov](http://www.ozerov.de/bigdump/), extended with a full WordPress management suite: auto-configuration, URL migration, backup, FTP/SFTP deployment, user management, and plugin control — all in a single PHP file.

---

## ✨ Features

### Core Import Engine
- **Staggered import** — splits large SQL dumps into sessions to work around PHP `max_execution_time` and memory limits
- **Auto-detects dump files** — `.sql`, `.gz` (GZip), `.zip` in the working directory
- **Auto-extracts** ZIP archives before importing
- **Ignores `CREATE DATABASE`** and `USE` statements automatically — safe to import dumps from different environments
- **Drops existing tables** before import to prevent duplicate-key errors
- **AJAX mode** — import runs progressively without page reloads, with a live progress bar
- **CSV import** support

### WordPress Integration
- **Auto-reads `wp-config.php`** — database credentials, charset, and table prefix loaded automatically (searches up to 5 parent directories)
- **Live DB connection test** — verifies connectivity before starting any operation
- **PHP parameter check** — audits `upload_max_filesize`, `post_max_size`, `memory_limit`, `max_execution_time`, cURL, ZIP, FTP, and SSH2 extension availability

### URL Migration
- **Find & replace URLs** in the SQL file before importing — essential when moving between local and production environments
- **WordPress URL validation** — verifies that both old and new URLs point to a live WordPress installation by probing `/wp-login.php`, `/wp-includes/version.php`, and `/readme.html`
- **Preview before applying** — shows count of occurrences and a sample of affected lines before writing changes

### Backup
- **Pre-import backup** — generates a full dump of the current database before overwriting it
- **Auto-detects `mysqldump`** — uses the system binary if available (faster, more reliable); falls back to a pure PHP implementation if not found
- Lists and manages existing backup files

### FTP / SFTP Deployment
- Deploy `bigdump.php` and SQL files directly to a remote server from the browser
- Supports **FTP**, **FTPS** (SSL), and **SFTP** (via PHP `ssh2` extension)
- Select which SQL files to include in the transfer

### Persistent Configuration (`bigdump.config.json`)
- Saves FTP credentials, migration URLs, and import preferences to a local JSON file
- Pre-fills all forms on page load
- **Bidirectional migration profiles** — store both local and remote URLs to support local→production and production→local workflows

### WordPress User Management
- **List all users** from `wp_users` with login, email, display name, and registration date
- **Change password** — uses the phpass `$P$` algorithm (fully compatible with WordPress; WP upgrades to bcrypt on next login)
- **Change email** — with client-side format validation
- **Delete user** — removes from `wp_users` and all `wp_usermeta` records
- **Add new user** — with login, display name, email, password, and role (Administrator, Editor, Author, Contributor, Subscriber); detects duplicate logins/emails; sets capabilities and user level in `wp_usermeta`

### WordPress Plugin Management
- **List active and inactive plugins** by reading `active_plugins` from `wp_options`
- **Activate / Deactivate** individual plugins without logging in to WP admin
- **Deactivate all plugins at once** — useful for diagnosing conflicts

### Self-Destruct
- **Delete script button** — removes `bigdump.php` and `bigdump.config.json` from the server in one click directly from the UI

---

## 🚨 Security Warning

**This script has serious security vulnerabilities by design.** It is a maintenance tool, not an application to leave running.

### Why it is dangerous
| Risk | Detail |
|------|--------|
| **No authentication** | Anyone who can reach the URL has full access to everything the script does |
| **Full database access** | Can read, modify, or delete any table — including user passwords and private data |
| **Remote code execution risk** | Accepts file uploads and can write to the filesystem |
| **Credential exposure** | Reads and displays `wp-config.php` database credentials |
| **Admin takeover** | Can create WordPress administrator accounts or change existing passwords |
| **Data loss** | Drops all tables before import — irreversible without a backup |
| **Config file** | `bigdump.config.json` stores FTP passwords in plaintext on disk |

### Safe usage rules
1. **Use only on local development environments or via a secure, password-protected directory**
2. Upload the script, perform your task, then **immediately delete it** (use the built-in "Delete this script" button)
3. Never commit `bigdump.config.json` to version control — it contains credentials
4. If you must use it on a remote server, restrict access via `.htaccess` IP filtering or HTTP basic auth first
5. After use, verify the script and config file are gone

---

## 🚀 Quick Start

### Local (XAMPP / MAMP / Laragon)
1. Place `bigdump.php` in the same directory as `wp-config.php` (or any parent directory up to 5 levels up)
2. Place your `.sql`, `.sql.gz`, or `.sql.zip` dump file in the same directory
3. Open `http://localhost/your-path/bigdump.php` in your browser
4. The script reads DB credentials automatically — verify the connection test passes
5. Click **▶ Import** next to your dump file
6. Wait for completion, then click **Delete this script**

### Remote server
1. Use the **FTP/SFTP Deploy** section to upload `bigdump.php` and your SQL file in one step
2. Navigate to the remote URL
3. Run the import
4. Delete the script

---

## 📋 Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| PHP | 8.0 | 8.2+ |
| MySQL/MariaDB | 5.6 | 8.0+ / 10.6+ |
| `mysqli` extension | Required | — |
| `zip` extension | Optional | Recommended (ZIP auto-extract) |
| `curl` extension | Optional | Recommended (URL validation) |
| `ftp` extension | Optional | Required for FTP deploy |
| `ssh2` extension | Optional | Required for SFTP deploy |
| `exec()` / `shell_exec()` | Optional | Required for `mysqldump` backup |

---

## ⚙ Advanced Notes

**Large tables with extended inserts:** BigDump cannot split a single INSERT containing all rows. If you see `allowed memory size exhausted` or `MySQL server has gone away`, your dump uses extended inserts. Re-export from phpMyAdmin with extended inserts disabled.

**Timeout errors:** Reduce `$linespersession` in the configuration section (default: 3000 lines). For very slow servers, also increase `$delaypersession`.

**Character encoding:** The `$db_connection_charset` variable (auto-set from `wp-config.php`) must match your dump file encoding. Mismatches cause garbled non-latin characters.

**GZip files:** PHP must decompress from the beginning of the file at each session start. Very large `.gz` files may exceed memory limits — use uncompressed `.sql` when possible.

**Multiple databases in one dump:** Not supported. BigDump skips `CREATE DATABASE` and `USE` statements but cannot route data to different databases.

---

## 📁 File Structure

```
bigdump.php           ← Main script (single file, no dependencies)
bigdump.config.json   ← Auto-generated config (credentials — do not commit)
your-dump.sql         ← Your MySQL dump file
backup_db_YYYYMMDD.sql ← Auto-generated backups
```

---

## 🔄 Migration Workflow

### Local → Production
1. Export your local DB from phpMyAdmin
2. Open BigDump locally, go to **URL Replacement**
3. Set old URL = `http://localhost/mysite`, new URL = `https://mysite.com`
4. Click **Preview** → **Apply replacement**
5. Use **FTP/SFTP Deploy** to upload `bigdump.php` + the modified SQL to your server
6. Open the remote BigDump URL, run the import
7. Delete the script

### Production → Local
1. Export the production DB
2. In BigDump, set old URL = `https://mysite.com`, new URL = `http://localhost/mysite`
3. Apply replacement, then import locally

---

## 📝 Changelog

| Version | Changes |
|---------|---------|
| `0.40-wp` | FTP deploy renames script to `bigdump_<random>.php` on remote server; backup files in `backups/` subfolder now appear in import table with ↩ Restore button; backup listing now shows all `.sql`/`.gz` files regardless of name prefix |
| `0.39-wp` | Security warning banner with self-delete; WordPress plugin management (list, activate, deactivate all); full English translation; fixed AJAX JSON empty response bug (missing function definitions before early router) |
| `0.38-wp` | URL validation against live WP installs; pre-import backup (mysqldump + PHP fallback); FTP/SFTP deployment; persistent `bigdump.config.json` config; WordPress user management (list, add, edit password/email, delete) |
| `0.37-wp` | wp-config.php auto-load; PHP parameter audit; auto-drop tables before import; ignore CREATE DATABASE; ZIP auto-extract; URL find & replace with preview; modern UI |
| `0.36b` | Original release by Alexey Ozerov — mysqli migration |

---

## 🗂 Pending / Roadmap

### Known improvements needed
- [ ] **Add HTTP Basic Auth protection** — the script should optionally require a username/password before showing anything, configurable in `bigdump.config.json`
- [ ] **Chunked file reading for URL replacement** — current implementation loads the entire SQL file into memory; for very large files (>500MB) this will fail
- [ ] **Progress persistence across browser reloads** — if the browser closes mid-import, there's no way to resume from the correct offset
- [ ] **SFTP key-based authentication** — currently only password auth is supported for SFTP
- [ ] **Encrypted config file** — `bigdump.config.json` stores FTP passwords in plaintext
- [ ] **Multi-site WordPress support** — currently assumes single-site table structure
- [ ] **Backup compression** — backup files should optionally be gzipped to save disk space
- [ ] **Dry-run import mode** — parse the SQL and report potential errors without executing queries

### Features to add
- [ ] **Support for other CMS platforms:**
  - **Joomla** — detect `configuration.php`, read `$db`, `$user`, `$password`, `$host`; handle `#__` table prefix replacement
  - **PrestaShop** — detect `app/config/parameters.php`; handle `_DB_PREFIX_` replacement
  - **Drupal** — detect `sites/default/settings.php`; handle variable table prefixes and multi-site configs
  - **MediaWiki** — detect `LocalSettings.php`; read `$wgDBname`, `$wgDBuser`, `$wgDBpassword`
  - **Magento** — detect `app/etc/env.php`
  - Generic mode: manual credential entry when no CMS is detected
- [ ] **Plugin installer** — scan the working directory for plugin `.zip` files (e.g. `elementor-pro.zip`, `divi.zip`) and install them directly into `wp-content/plugins/` with a single click, without needing WP admin access — useful when migrating a site that requires paid plugins
- [ ] **Theme installer** — same concept for `.zip` theme files in `wp-content/themes/`
- [ ] **wp-config.php editor** — allow editing key WP constants (siteurl, home, debug mode) directly from the UI after import
- [ ] **Search & replace with serialized data awareness** — WordPress stores serialized PHP arrays in the database; a naive string replace breaks serialized data. Implement a serialization-aware replacer (like the WP-CLI `search-replace` command)
- [ ] **Table prefix migration** — when importing a dump with a different table prefix than the target installation, auto-rename tables
- [ ] **Email log / notification** — send a summary email when import completes (useful for unattended remote imports)
- [ ] **CLI mode** — allow running the import from the command line for server-side use without a browser

---

## Credits

- Original BigDump by [Alexey Ozerov](http://www.ozerov.de/bigdump/) — GPL © 2003–2015
- WordPress Edition extensions — GPL

---

*Single-file WordPress database migration tool. No composer, no dependencies, no framework.*
