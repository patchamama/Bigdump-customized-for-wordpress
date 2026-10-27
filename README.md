# Bigdump — WordPress Edition

[Español](README.es.md) · [Pending work and known limitations](TODO.md)

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

## Choose a script

| File | Purpose | Boundary |
| --- | --- | --- |
| `cms_tools.php` | Authenticated migration, maintenance, reports and token management | Can modify live files, tables and accounts. |
| `cms_tool.php` | Restricted compatibility URL for external report JSON | Requires `cms_tools.php` beside it; report tokens grant no administrative access. |
| `bigdump.php` | Extended staggered SQL/CSV importer and WordPress migration tools | Import can drop/replace tables; use a separate backup and access restrictions. |
| `bigdump_original.php` | Historical modified BigDump 0.36b reference with config discovery | Not the maintained panel or a hardened alternative; do not deploy merely because its name says original. |

### Implemented versus pending

- **Implemented and synthetically verified:** optional JSON report, private GitHub export,
  external token API with create/rotate/revoke and persistence-failure handling, and English
  internal comments. No passwords, database contents or personal records enter the report.
- **Existing legacy tools:** migration, users, media, SEO, filesystem and sandbox tools
  described below. Their presence does not mean every action was retested here.
- **Independently reviewed local implementation:** staged WordPress upgrade smoke checks on separately provisioned
  staging or optional offline Docker, with independent copies, restricted database accounts,
  per-plugin/active-set, HTTP/REST, asset and effective PHP checks. Independent technical
  review passed for this scope; smoke readiness is not complete application compatibility.
- **Explicitly excluded:** automatic replacement of production. The upgrade flow
  prepares/tests a separate installation and recommends manual migration after backup.
  Full progressive CMS installation, config-copy/replacement and recovery remain pending.

These additions are local feature-branch work until published. Deploy matching files from
one revision; download commands targeting `main` do not publish this branch or guarantee
that its new features are already available there.

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

### External read-only report API

Deploy `cms_tool.php` **beside** `cms_tools.php` to enable the singular compatibility
URL. The alias serves only external report JSON; keep using `cms_tools.php` for
administration. Both files are required, including when the tools live in a subdirectory.

1. Open the canonical tool over **HTTPS**, log in, and open **Report**.
2. Click **Create / rotate API token** and **Copy URL once**. The URL is shown only
   for this action, cleared after copying or leaving the page, and never restored.
3. Use the URL from a trusted HTTP client, or preferably send the token in a header:

```text
https://example.test/tools/cms_tool.php?action=site_report_api&token=YOUR_API_TOKEN
```

```bash
curl --fail --silent --show-error \
  -H 'Authorization: Bearer YOUR_API_TOKEN' \
  'https://example.test/tools/cms_tool.php?action=site_report_api'
```

**URL tokens can leak in server/proxy access logs and browser history.** Prefer
`Authorization: Bearer` for application calls; redact query strings in infrastructure
logs. Do not put live tokens in source control. Only a SHA-256 token hash is stored
in `.migrate_config`; the token is independent of the login password and GitHub token.
Rotating invalidates the previous token immediately; **Revoke API token** disables
access. Lifecycle changes use verified same-directory atomic writes; an unsuccessful
save returns HTTP 503 and never reports success or provides a new token URL. The error
is `API token could not be saved. Check configuration permissions and try again.`
Treat the previous token as potentially active until revocation succeeds. Temporary
configuration files use mode `0600` and are removed after a failed write. Block web
access to `.migrate_config` and `.migrate_config-*` temporary files at the web server.

The generated URL uses the server's accepted Host authority. Configure an expected-host
allowlist at your web server or trusted proxy and verify the URL before distributing it.

Each successful GET collects a **fresh** secret-excluding report, without login or
session cookies. Tokens grant no config, database download, repair, GitHub or write
access. Invalid credentials return JSON HTTP 401. Responses use `no-store` and
`nosniff`; HTTP token use is refused. HTTPS detection uses the server TLS state,
not untrusted forwarding headers: TLS-terminating proxies must configure trusted
server HTTPS state. OPTIONS does not collect a report or authenticate. No CORS is
enabled: server-side HTTP clients work from any app; cross-origin browser apps
should use their own backend rather than sending cookies or exposing this token.

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

### Send the displayed report to a private GitHub repository

1. Generate and review the report, then enter an HTTPS repository URL such as
   `https://github.com/owner/private-repo` (an optional `.git` suffix is accepted).
2. Enter a fine-grained token with access to that repository and **Contents: read and
   write**. Organization policies may also require token approval or SSO authorization.
3. Keep `wordpress-report` or choose another report branch. Click **Send to private
   GitHub** and confirm. The tool creates a missing branch from the repository's
   default branch, then creates or updates `<wordpress-domain>.json` at its root.

Only private, already-initialized repositories on **github.com** are supported; create
an initial commit (for example a README) first. The default branch is protected from
report writes. Existing report files are updated using their SHA; concurrent updates
and branch-protection failures are reported without automatic retries. GitHub retains
old report versions in commit history. A branch created before a later upload failure
may remain; check the repository before retrying.

Use **HTTPS for the tool itself**, not only the GitHub URL. The token is sent in a POST
body to this authenticated tool and then only to `https://api.github.com` with certificate
validation and redirects disabled. It is never stored in configuration, session, JSON,
URLs or browser storage, and the token field is cleared after each attempted send.
Password managers or browser extensions remain outside the tool's control.

The browser keeps the displayed JSON until refresh or regeneration. A matching copy
is held in the authenticated PHP session for up to **30 minutes of export validity**
and replaced when another report is generated; it can remain in the host's session
storage until that session expires. Export uses that exact copy, not caller-supplied
JSON or a fresh audit. Another tab generating a report invalidates the old snapshot.
A missing authoritative WordPress domain allows download as `site-report.json` but
blocks GitHub export. Treat infrastructure metadata as private and review it before
sharing. No credential persistence or encrypted localStorage is added.
The authenticated HTTP endpoint is `POST ?action=site_report`; add `&download=1` for a
JSON attachment (this generates a fresh report).

Run isolated HTTP coverage with `php tests/site-report.php`, the synthetic cURL path
with `SITE_REPORT_TEST_CURL=1 php tests/site-report.php`, UI controller checks with
`node tests/site-report-ui.cjs`, and lint with `php -l cms_tools.php`. The runner uses a
temporary installation, namespaced PDO fixture and synthetic GitHub transport, never a
real database or repository. No real GitHub writes are made. Test artifacts use the
system temporary directory (a harness may remove them when the command exits).

### Quick update (one-click post-migration)

**This repairs a migration; it does not upgrade WordPress core.** It modifies the live
configuration/database. Back up both files and database before applying it.

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

### WordPress upgrade on separate staging or optional Docker

Use **WordPress upgrade on separate staging** in Test Environment only after provisioning
separate files, execution permissions and database credentials. Keep verified files **and full
database** backups. Follow the [staging preparation guide](docs/staging-upgrade.md) for literal
configuration, a SELECT-only baseline reader, an empty target schema/restricted writer and
trusted HTTPS identity. The staged controller prepares independent copies, validates official
core checksums, upgrades the copied database, tests plugins individually and as the original
active set, and checks HTTP/REST, assets and effective PHP. Required failed/unavailable checks
block readiness; reuse is restricted to unchanged upgrade-owned runs in the same session.
Target credentials remain in private session/configuration, never progress JSON.

[Optional Docker](docker/wordpress-upgrade/README.md) runs from a local CLI against an
explicit offline backup and restricted plain SQL, never from web PHP or a live production
path. The runner prefetches official core separately, then executes copied site code offline
without host mounts, published ports or a Docker socket in the container. Docker is not
required for reports, API or separate-host testing.

Parent-observed isolated proof: genuine WordPress **6.8.3 → 7.1.3**, ready/runtime verified,
17 OK / 1 environment warning / 0 failed, empty PHP log, 3,233 source hashes unchanged,
and exactly three owned runtime resources cleaned. Independent code/integration review
also passed. This is one fixture, **not** production compatibility, full installer or rollback proof.
See [old-fixture tooling and limits](docs/wordpress-old-fixture.md).

Known official Akismet rules are authenticated then omitted, never installed; passive
`wp-content/index.php` is hash-bound and token-inspected. Unknown server/PHP overrides,
links, MU plugins and drop-ins still fail closed. REST checks preserve source permalinks.
Production migration/promotion/restoration remains manual after another full backup.

### Test Environment (legacy sandbox; not upgrade isolation)

**Do not use this environment to update WordPress or prove production safety.** It copies
tables under a derived prefix but shares core, plugins, themes and uploads through symlinks.
Root PHP linking can include `wp-config.php`; a later sandbox configuration write can affect
the live configuration. Different table prefixes do not restrict what code using production
database credentials can access.

Existing controls include per-plugin homepage smoke tests, all-plugin activation/bisection,
plugin vetting, PHP requirement indicators and optional multisite/sub-site creation. These
are not comprehensive compatibility checks. Network failures, redirects and missing assets
need independent verification; a page that loads does not establish admin/REST/cron or
business-function compatibility. Cleanup can affect all matching sandbox-prefix tables and
`test_env_*` folders, not an independently owned run.

Use the separate-staging or offline Docker upgrade workflow above instead. Neither the
new updater nor report/API features provide a production promotion operation.

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
  excluding selected caches/backups. This action does **not generate a database dump**.
  Archives can contain private configuration/content; keep them private.
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
  is the gate; if it is empty the tool forces creation of a custom password first.
  The custom password is stored as a salted hash, **not encrypted plaintext**, and takes
  priority. This is not WordPress login, multi-user authorization or a secrets vault.
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

## Backup and rollback boundaries

Before any write operation, export the **database** and **site files** separately, store
both outside the public web root, and verify restoration on a separate installation.
Keep the original WordPress/PHP/database versions and hosting rules needed for that restore.

Configuration snapshots cover selected configuration files and the active-plugin list;
they are **not** complete backups of database, core, plugins, themes and uploads. Restore
logs or renamed folders for individual cleanup operations do not replace a full backup.
Neither the JSON report nor GitHub report history can restore a site.

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
5. Verify scripts, dumps, temporary files and state files are gone after use. If intentionally
   keeping the report API available, retain both PHP files, restrict the canonical admin
   URL separately, protect state files and revoke the token before decommissioning.
6. Require HTTPS for the tool/API. Configure trusted TLS state behind a proxy and an
   expected-Host allowlist; never distribute an unverified generated URL.
7. Block HTTP access to configuration/state files, `.migrate_config-*` temporary files,
   backups and exports at the server. Mode `0600` alone is not a web access policy.

## Verification

The new report/API checks use synthetic temporary installations, fake PDO and simulated
GitHub transports, not a real deployment:

```bash
php -l cms_tools.php
php -l cms_tool.php
php tests/site-report.php
SITE_REPORT_TEST_CURL=1 php tests/site-report.php
php tests/site-report-api.php
node tests/site-report-ui.cjs
node tests/site-report-api-ui.cjs
```

PHP and Node are needed for these checks; Node is not a browser-runtime dependency. Coverage
includes privacy/unknowns, mocked GitHub writes, token scope/CSRF, legacy downloads, lifecycle
and failed atomic persistence. It does not prove real TLS/proxy settings, GitHub permissions,
full database behavior, Apache rules, all legacy actions or WordPress-upgrade compatibility.

---

## File structure

```
cms_tools.php            WordPress maintenance panel and report implementation
cms_tool.php             Restricted report alias; requires cms_tools.php
bigdump.php              Extended staggered MySQL importer
bigdump_original.php     Historical modified BigDump reference
README.es.md             Detailed Spanish usage and scope
TODO.md                  Pending work and known limitations
tests/                   Synthetic report/API regression tests
.migrate_config          Login/API hashes and tool settings (git-ignored)
.migrate_exports/        Generated exports and split parts (git-ignored)
bigdump.config.json      bigdump.php config — FTP credentials (git-ignored)
```

---

## Changelog

| Version | Changes |
|---------|---------|
| Unreleased feature work | Optional secret-excluding JSON report; private GitHub export with editable branch; restricted report API; independent hash-only tokens with verified atomic saves; English internal comments; bilingual documentation and explicit pending-work boundaries. Separate-staging/optional offline Docker upgrade smoke workflow passed bounded independent technical review; local integration and final delivery remain separate. Production promotion and full progressive installation/recovery are not delivered. |
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
