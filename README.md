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

- **`cms_tools.php`** — a full WordPress migration and maintenance suite:
  database export/import with size-limited parts, URL and domain rewriting, media
  fixes, user management, plugin control, health checks, and a built-in WordPress
  installer. Password-protected. It **detects the CMS** on load — WordPress, Joomla,
  Drupal or MediaWiki — and reads the database credentials from that CMS's config file,
  so **export/import and phpMyAdmin work for all four**; the WordPress-specific tools
  stay available only on a WordPress site.
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
curl -O https://raw.githubusercontent.com/patchamama/Bigdump-customized-for-wordpress/main/cms_tools.php

# Staggered SQL importer
curl -O https://raw.githubusercontent.com/patchamama/Bigdump-customized-for-wordpress/main/bigdump.php
```

Then open the file in the browser from the directory that contains `wp-config.php`
(or a parent directory).

---

## cms_tools.php

A password-protected control panel for migrating and maintaining a WordPress site.
It reads database credentials from `wp-config.php` automatically and drives every
operation over PDO/mysqli.

### Site report / Reporte (read-only JSON)

1. Log in and open **Report** (**Reporte** in Spanish).
2. Click **Generate report** to inspect local metadata. Nothing runs automatically
   when opening the tab.
3. Review the displayed JSON, then click **Download JSON**. The download preserves
   the displayed report and its generation timestamp; it does not run another audit.

The versioned `cms-tools-site-report` schema includes UTC generation time, scope and
limits, PHP configuration/extension indicators, WordPress core and component versions,
single-site plugin/theme activity, allowlisted site settings, MySQL version and standard
WordPress table sizes/estimated row counts. Findings and summary counts distinguish
`critical`, `warning`, `ok`, and **`unavailable`**. An unavailable check is never a pass;
a report still works without WordPress or a database connection.

| Boundary | Behavior |
| --- | --- |
| Privacy | No credentials, tokens, salts, raw configuration/logs, user records, emails, or personal content. Only selected metadata is exported. |
| Read-only | Does not bootstrap WordPress or execute plugins/themes, probe the website, toggle plugins, repair tables, or modify site files. |
| Filename | Uses the lowercase domain from the database's authoritative WordPress `siteurl`, excluding URL credentials/path/query. Falls back to `site-report.json`, never the browser's Host header. |
| Limits | Default `wp-content/plugins` and `themes` only; bounded headers, directories and table metadata. Network activation/custom content paths are not resolved. Truncation is explicit. |
| Interpretation | Local indicators, not a vulnerability scan or assurance of safety. Current update/support status, TLS, dynamic SEO output and file integrity are not verified. Table row counts are estimates. |

The report remains in browser memory until refresh or regeneration; it is not saved on
the server. Treat exported infrastructure metadata as private and review it before
sharing. Private GitHub export and encrypted browser persistence are not implemented.
The authenticated HTTP endpoint is `POST ?action=site_report`; add `&download=1` for a
JSON attachment (this generates a fresh report).

Run isolated synthetic HTTP coverage with `php tests/site-report.php`, then lint with
`php -l cms_tools.php`. The runner uses a temporary installation and a namespaced PDO
fixture, never a real database or the deployment's configuration. Temporary synthetic
artifacts are left in the system temporary directory for inspection.

### Quick update (one-click post-migration)

Given only the real DB credentials, the tool knows the **old** site URL (from the database) and
the **current** URL (from the browser), so a freshly-moved site can be fixed in one guarded step:
rewrite `wp-config.php` (backed up first), set `siteurl`/`home`, convert absolute `wp-content`
URLs to relative, clear caches and optionally deactivate all plugins — each step reported.

### Asset recovery

Scans the database for `/wp-content/` files the site references, detects which are **missing on
disk**, and downloads them from the **original site** in batches with a progress bar — for when an
FTP copy was incomplete. The original domain is auto-filled from the old site URL and remembered.

It can also enable **automatic recovery while browsing**: an `.htaccess` rule routes any missing
`/wp-content/` file to a fallback that downloads it from the original site on the fly and caches it,
so the site fills in as visitors browse. For images you choose what to show if the file is missing
at the origin too (a "not found" placeholder, nothing, or a normal 404). Tested safely before
touching `.htaccess`.

### Clean Site

Rebuilds `wp-content/uploads` with only the files the site actually uses (referenced in the
database, together with their WordPress thumbnails). Analyze first for a dry-run summary (total
vs used files, reclaimable space); applying copies the used files into a fresh folder, renames the
current one to `uploads_old-remove-it_*` (kept as a backup, not deleted) and writes a restore log
so the change can be undone.

### Uploads optimization

Three safe cleanups for a crowded `wp-content/uploads`, each one locating files in the database
first, updating every reference and writing a reversible log:
- **Organize** referenced files into subfolders — `YYYYMMDD_name` → `YYYY/MM/name`, otherwise
  grouped by first letter — moving their WordPress thumbnails alongside.
- **Deduplicate** identical files (compared by size, then SHA-1): keep one, repoint references and
  delete the rest.
- **Sanitize** filenames with accents, spaces or symbols into safe ASCII names.

### Performance

Speed up the site with browser **gzip/deflate compression** and **cache headers** (written to
`.htaccess`, tested in an isolated subfolder first so they can't 500 the site), an optional
**static HTML page cache**, and a **performance check** — a quick internal measurement (TTFB, load
time, page size, asset count) plus one-click links to PageSpeed Insights, GTmetrix and Pingdom.

### Disk cleanup & Apache capabilities

Find **orphan uploads** (on disk but not referenced in the database) and **large/suspicious files**
(logs, backups, archives), moving them to a pre-delete folder with a restore log instead of
deleting. Test which **Apache/.htaccess features** the host supports (rewrite, redirects, Basic
auth, gzip, caching, headers) without touching the live `.htaccess`.

### Test Environment (isolated sandbox)

Builds a runnable WordPress sandbox in a subfolder without risking the live site: it **replicates
the database** tables under a test prefix (progress bar), and links the core, plugins, themes and
uploads (symlink, falling back to copy) with its own `wp-config.php`. Then it can **smoke-test each
plugin** one by one against the sandbox (flagging any that 500 / fatal), **vet new plugins** from the
wordpress.org API before installing them live, run a **PHP-compatibility** check, and produce a
report. Tables and folder are disposable.

The sandbox can optionally be built as a **multisite network** (subdirectory or subdomain),
creating the network tables in the copy and offering a form to **add sub-sites** to exercise
multisite. Besides the one-by-one smoke test, a **test-all-plugins** run activates every plugin at
once and, if the site breaks, **bisects** to the minimal conflicting plugin set.

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

### User management

- **List users** with their roles, email, display name and registration date.
- **Change password** using the WordPress-compatible phpass (`$P$`) algorithm; WordPress
  upgrades the hash to bcrypt on next login.
- **Edit** a user's email, display name and role; **create** new users with a chosen role,
  writing capabilities exactly as WordPress does.

### Security audit

- Hardening report across four areas: **users & passwords** (weak/common password probe,
  default `admin` login, administrator count), **WP core & config** (version, default
  security keys, `WP_DEBUG`, `DISALLOW_FILE_EDIT`, table prefix), **filesystem exposure**
  (`wp-config.php` permissions, fingerprint files, exposed dumps, `.htaccess`), and
  **attack surface** (`xmlrpc.php`, REST user enumeration, brute-force advisory).

### SEO

- **SEO audit** — indexability (`blog_public`, robots.txt), site title/tagline, friendly
  permalinks, sitemap presence, HTTPS in `siteurl`, and untitled published posts.
- **Edit title and tagline**, switch to friendly `/%postname%/` permalinks, and **generate a
  `sitemap.xml`** referenced from `robots.txt`.
- **Indexing toggle** — allow or block indexing via `robots.txt` and `blog_public`.
- **Request indexing** and **check if indexed** links for Google, Bing, DuckDuckGo and Yandex.

### Full-site password

- Protect the entire site with HTTP Basic auth (`.htaccess` + `.htpasswd`, bcrypt). An isolated
  test writes the rule into a throwaway subfolder and confirms the host answers 401 (auth
  required) rather than 500 before you apply it, with a probe URL to try in the browser.

### Post-migration extras

- **Regenerate `.htaccess`** with the standard WordPress rewrite rules (fixes the typical
  all-pages 404), backing up the previous file.
- **Repair & optimize** every database table.
- **Flush rewrite rules and transients**.
- **Toggle `WP_DEBUG`** and **maintenance mode** without hand-editing files.
- **PHP configuration with safe testing** — choose `.htaccess` or `.user.ini`, and a test
  writes the proposed config into an isolated throwaway subfolder and loads it over HTTP to
  confirm it does not break the site (HTTP 500) before letting you apply it.
- **phpMyAdmin installer** — download the latest phpMyAdmin and extract it into `/phpmyadmin`.

### Broken link checker

- Checks the links stored in the database over HTTP (scope: current site or all domains, with a
  configurable cap and a stoppable progress bar) and groups the broken ones (HTTP 400+ or no
  response) by domain. Each entry shows **where it lives in WordPress** — the post/page (with an
  edit shortcut to WP admin), the postmeta key, or the option — and opens in a new tab.

### Access control and language

- Password-protected UI with a server-side session. By default the **wp-config `DB_PASSWORD`**
  is the gate; if it is empty the tool forces you to create an encrypted password first, so it
  is never left open. A custom encrypted password can be set and then takes priority.
- **Bilingual UI** — English / Spanish, switchable from the header.
- **Light / dark theme** — toggle from the header, remembered across sessions.

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
cms_tools.php    WordPress migration and maintenance suite (single file)
bigdump.php              Staggered MySQL importer (single file)
.migrate_config          Auth hash for cms_tools.php (git-ignored)
.migrate_exports/        Generated exports and split parts (git-ignored)
bigdump.config.json      bigdump.php config — FTP credentials (git-ignored)
```

---

## Changelog

| Version | Changes |
|---------|---------|
| `cms 3.17.0` | Environment Status now shows the **PHP and MySQL/MariaDB versions**; the tool **checks GitHub for a newer release** and, if one exists, highlights it near the version with a one-click **Update** button (downloads the release, validates it with `php -l`, backs up the current file as `cms_tools.php.bak-<old>`, then replaces it in place — reversible); new **anti-hotlinking** protection in Security that detects whether external sites can embed this site's assets and patches `.htaccess` with referer rules after testing them in an isolated subfolder (no HTTP 500) and backing up the current `.htaccess`; new **News tab** with the WordPress.org feed that raises a banner at the top of the app when a security/critical release is published |
| `cms 3.16.0` | The "Recover missing assets" scanner now **lists the exact missing files** (not just a count) in a scrollable panel, with a one-click **Copy list** button; during recovery each row is marked recovered (green) or not-found (red) |
| `cms 3.15.0` | **Site snapshots** under Quick Update: save a dated snapshot of the important configuration (`.htaccess`, `php.ini`, `.user.ini`, `wp-config.php`, `robots.txt`, `.htpasswd`) and the active-plugins list, then restore the site to that exact state in one click if a later change breaks it. Creating a snapshot changes nothing; a restore always takes a safety snapshot of the current state first, writes back only the known config filenames (no path traversal) and reactivates the exact plugins that were active |
| `cms 3.14.0` | Each plugin in the Plugin Manager can be exported as a ready-to-install `.zip` (folder packed at the zip root, path-traversal rejected); new one-click **Quick diagnostics** under Quick Update that scans the frequent reasons a site won't display (database, site URL, WP tables, maintenance mode, broken plugins, stale cache, `.htaccess` 500/redirects, PHP config, password locks, shadowing `index.html`) step by step with a progress bar and offers fixes without auto-applying them |
| `cms 3.12.0` | Sandbox can be built as a WordPress multisite network (fixing its DB copy and network tables) with a form to add sub-sites; new "test all plugins" that activates everything at once and bisects to the conflicting plugin(s) |
| `cms 3.11.0` | Renamed `migrate_wordpress.php` to `cms_tools.php`; detects the CMS on load (WordPress, Joomla, Drupal, MediaWiki) and reads DB credentials from its config, so export/import and phpMyAdmin work for all four (other tools remain WordPress-only) |
| `migrate 3.10.0` | Performance options (gzip, browser cache, static page cache, performance check with external-tool links); disk cleanup for orphan uploads and large/suspicious files; Apache capability tests; sandbox now fixes its DB-copy URL and relativizes assets; install popular/searched plugins into the sandbox and vet before going live |
| `migrate 3.6.0` | phpMyAdmin temporary access time is now configurable (5/15/30/60 min); automatic asset recovery while browsing via an `.htaccess` fallback that fetches missing `/wp-content/` files from the original site on the fly, with a choice of placeholder/nothing/404 for images |
| `migrate 3.4.0` | Fixed split export (no longer hangs — O(n) sizing) and parts now appear in Export files; fixed the Media URL Scanner count so it matches what Fix rewrites; added find & replace in links (Broken Link Checker) that verifies the resulting URL exists before saving |
| `migrate 3.3.0` | URL Replacement can now run directly against the live database: it finds the source URLs, HTTP-verifies each target URL and replaces only the ones that respond (serialized-safe), with a reversible log |
| `migrate 3.2.0` | Uploads optimization: organize files into subfolders (YYYY/MM for dated names, else by first letter), deduplicate identical files (size + hash, repointing references), and sanitize special-character filenames — each locating files in the database first, updating all references, and writing a reversible log |
| `migrate 3.1.0` | Health check links missing media/assets to the recovery flow; Clean Site rebuilds wp-content/uploads with only used files (DB references + WordPress thumbnails), keeping the old folder as a backup with a restore log and a summary of reclaimed space |
| `migrate 3.0.0` | Quick Update one-click post-migration (wp-config, siteurl, relativize, cache, plugins); asset recovery that downloads missing wp-content files from the original site; isolated Test Environment sandbox (table replication with progress bar, symlinked core/plugins/themes/uploads, per-plugin smoke test, wordpress.org plugin vetting, PHP-compat report) |
| `migrate 2.10.0` | Light/dark theme; fuller Spanish translation; broken link checker now shows each link's location in WordPress (post/meta/option) with an edit shortcut and covers page links, not just assets; friendly-permalink chooser with an isolated anti-500 test; WP-CLI shown in the CLI list; How-to-use tab reordered and rewritten; phpMyAdmin random folder with auto-expiring temporary credentials |
| `migrate 2.4.0` | SEO suite (audit, meta, friendly permalinks, sitemap, indexing toggle, request/check-indexed links); full-site HTTP Basic password with isolated anti-500 test; Get BigDump into the site; fix Media URL Scanner to rewrite URLs that carry a subfolder before `/wp-content/`, with opt-in disk verification |
| `migrate 2.0.0` | DB-password-based access with custom encrypted override; bilingual EN/ES UI; WordPress user management; security audit; post-migration extras (htaccess, repair tables, flush rewrite, debug/maintenance); PHP config with isolated safe testing; phpMyAdmin installer; Domain Link Manager final-URL preview and availability check |
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
