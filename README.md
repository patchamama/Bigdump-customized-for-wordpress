# Bigdump — WordPress Edition

<p>
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white">
  <img alt="MySQL" src="https://img.shields.io/badge/MySQL%2FMariaDB-5.6%2B-4479A1?logo=mysql&logoColor=white">
  <img alt="WordPress" src="https://img.shields.io/badge/WordPress-ready-21759B?logo=wordpress&logoColor=white">
  <img alt="PDO" src="https://img.shields.io/badge/PDO-mysqli-4479A1">
  <img alt="JavaScript" src="https://img.shields.io/badge/JavaScript-vanilla-F7DF1E?logo=javascript&logoColor=black">
  <img alt="Single file" src="https://img.shields.io/badge/dependencies-none-success">
  <img alt="License" src="https://img.shields.io/badge/license-GPL-blue">
</p>

Two single-file, browser-based PHP tools for WordPress database migration and site
administration. No Composer, no framework, no build step — drop a file next to
`wp-config.php` and open it in the browser.

- **`migrate_wordpress.php`** — a full WordPress migration and maintenance suite:
  database export/import with size-limited parts, URL and domain rewriting, media
  fixes, user management, plugin control, health checks, and a built-in WordPress
  installer. Password-protected.
- **`bigdump.php`** — a staggered MySQL dump importer (based on Alexey Ozerov's
  BigDump) extended for WordPress: auto-config, URL migration, backup, and FTP/SFTP
  deployment.

> **Security notice.** These tools expose database and filesystem operations through
> the browser. Protect them behind authentication and delete them after use. See
> [Security](#security).

---

## Quick download

Download either tool directly into your current directory with a single command.

```bash
# WordPress migration suite
curl -O https://raw.githubusercontent.com/patchamama/Bigdump-customized-for-wordpress/main/migrate_wordpress.php

# Staggered SQL importer
curl -O https://raw.githubusercontent.com/patchamama/Bigdump-customized-for-wordpress/main/bigdump.php
```

Then open the file in the browser from the directory that contains `wp-config.php`
(or a parent directory).

---

## migrate_wordpress.php

A password-protected control panel for migrating and maintaining a WordPress site.
It reads database credentials from `wp-config.php` automatically and drives every
operation over PDO/mysqli.

### Database export and import

- **Export** the full database via `mysqldump` (when available) or a pure-PHP PDO
  fallback that streams directly to disk.
- **Size-limited split export** — split a large dump into numbered `.zip` parts, each
  compressed to stay under a chosen limit (1–10 MB). Parts are cut only at full SQL
  statement boundaries and each repeats the `FOREIGN_KEY_CHECKS` header, so every part
  imports standalone and in order. Designed for hosts that reject large uploads.
- **Import** `.sql`, `.sql.gz`, `.gz` and `.zip` files (zip parts are extracted
  automatically) via the `mysql` CLI or a chunked PDO importer.
- **URL replacement during export** — rewrite the origin URL to the target URL,
  serialized-data safe (recalculates `s:LENGTH:` in serialized PHP strings).

### URL, domain and media rewriting

- **Domain Link Manager** — scan `posts`, `postmeta` and `options` for every absolute
  linked domain, list them with occurrence counts, and convert selected legacy domains
  to root-relative links. The base path to drop (e.g. an old `/wordpress` subfolder) is
  recommended by verifying the referenced files exist on disk, so the cleanup never
  creates links to missing assets. Serialized-data safe.
- **Media URL scanner / fixer** — convert absolute `http(s)://domain/wp-content/` asset
  URLs to relative `/wp-content/` paths, skipping any whose target file is not present
  on disk (reported as unverified) to avoid broken links.
- **Site URL manager** — switch `siteurl` and `home` between `http` and `https` without
  editing `wp-config.php`.

### Site tools

- **Health check** — audits PHP environment, filesystem and WP core files, database
  tables and row counts, active theme, active plugins, media attachments, and asset
  references found in content (reporting missing files on disk).
- **Plugin manager** — list installed and active plugins, toggle individual plugins, or
  deactivate all at once by editing `active_plugins` in the database.
- **PHP configuration** — compare current PHP settings against WordPress recommendations
  and write them to `.htaccess` and/or `.user.ini`.
- **Cache clear** — purge `wp-content/cache` and Divi `et-cache`.
- **Hosting export** — package the site files into a `.zip` (or `.tar.gz`) for download,
  excluding caches and backups.
- **WordPress installer** — download and install the latest WordPress into the current
  directory when none is present, preserving an existing `wp-content`.

### Access control

- Password-protected UI with a server-side session. The authentication hash is stored
  outside the web content and never committed.

---

## bigdump.php

A staggered MySQL dump importer that works around PHP execution-time and memory limits
by importing in sessions, with a live AJAX progress bar.

### Import engine

- Staggered import of large dumps, splitting work across sessions.
- Auto-detects `.sql`, `.gz` and `.zip` dump files in the working directory and
  auto-extracts ZIP archives.
- Skips `CREATE DATABASE` and `USE` statements; drops existing tables before import to
  prevent duplicate-key errors.
- CSV import support.

### WordPress integration

- Reads `wp-config.php` for credentials, charset and table prefix (searches parent
  directories).
- Live database connection test and a PHP parameter audit.
- URL find-and-replace with preview and live-WordPress validation.
- Pre-import backup via `mysqldump` or a PHP fallback.
- FTP / FTPS / SFTP deployment of the script and SQL files.
- WordPress user and plugin management.
- Persistent configuration in `bigdump.config.json`.
- Self-destruct button to remove the script after use.

---

## Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| PHP | 8.0 | 8.2+ |
| MySQL / MariaDB | 5.6 | 8.0+ / 10.6+ |
| `pdo_mysql` / `mysqli` | Required | — |
| `zip` extension | Optional | Recommended (ZIP parts, extraction) |
| `curl` extension | Optional | Recommended (installers, validation) |
| `ftp` / `ssh2` | Optional | Required for FTP/SFTP deploy (bigdump.php) |
| `exec()` | Optional | Required for `mysqldump` / `mysql` CLI |

---

## Security

These tools are maintenance utilities, not applications to leave running.

| Risk | Detail |
|------|--------|
| Full database access | Read, modify or delete any table, including user data |
| Filesystem writes | Accept uploads and write to the web root |
| Credential exposure | Read `wp-config.php` database credentials |
| Admin takeover | Can create or modify WordPress accounts |
| Data loss | Dropping tables before import is irreversible without a backup |

**Rules of use**

1. Prefer local development environments or a password-protected directory.
2. Perform the task, then delete the script immediately.
3. Never commit `wp-config.php`, `.migrate_config` or `bigdump.config.json`.
4. On a remote server, restrict access with `.htaccess` IP filtering or HTTP basic auth.
5. Verify the script and its state files are gone after use.

---

## File structure

```
migrate_wordpress.php    WordPress migration and maintenance suite (single file)
bigdump.php              Staggered MySQL importer (single file)
.migrate_config          Auth hash for migrate_wordpress.php (git-ignored)
.migrate_exports/        Generated exports and split parts (git-ignored)
bigdump.config.json      bigdump.php config — FTP credentials (git-ignored)
```

---

## Changelog

| Version | Changes |
|---------|---------|
| `migrate 1.3.0` | Domain Link Manager with disk-verified base path; size-limited split `.zip` export; disk-verified media URL fixing |
| `migrate 1.0` | WordPress migration suite: export/import, URL migration, health check, plugin manager, PHP config, WordPress installer |
| `bigdump 0.40-wp` | FTP deploy renames script on remote; backup restore from `backups/`; broader backup listing |
| `bigdump 0.39-wp` | Security banner and self-delete; plugin management; full English translation |
| `bigdump 0.38-wp` | URL validation; pre-import backup; FTP/SFTP deploy; persistent config; user management |
| `bigdump 0.37-wp` | wp-config auto-load; PHP audit; auto-drop tables; ZIP auto-extract; URL find and replace |
| `bigdump 0.36b` | Original release by Alexey Ozerov — mysqli migration |

---

## Credits

- Original BigDump by [Alexey Ozerov](http://www.ozerov.de/bigdump/) — GPL (C) 2003–2015.
- WordPress Edition extensions — GPL.

Single-file WordPress database migration tooling. No Composer, no dependencies, no framework.
