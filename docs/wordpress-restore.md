# WordPress backup restoration

**Current scope: detection, preparation and private ZIP preflight/staging only.** The browser offers preparation
when the application directory contains the tool, a literal `wp-config.php`,
`hosting_*.zip` and `db_*.sql.gz` backups, but no installed core or unrelated files.
The tool's own `.migrate_config` is allowed. Multiple backups require explicit
selection; the tool does not guess which pair belongs together.

Log in, set a custom tool password, review the selected files and destination URL,
and choose **Prepare restoration**. This creates a private, server-owned preflight
run. It does **not** extract backup code, connect to the database, back up or drop
tables, import SQL, activate plugins, change configuration or replace the site.
Database replacement/import and public file placement remain unavailable. The
private file-preflight adapter is available through the session protocol below;
this is not a complete restoration wizard.

## Safety and limits

- The destination is the directory of the running application, not a submitted path
  or a parent-directory CMS. Existing credentials come from its `wp-config.php`.
- Configuration is parsed without execution. Literal, unconditional, standard
  single-site configurations are supported; dynamic definitions, ambiguous values,
  custom content/multisite layouts and unsupported DB addresses are rejected.
- Restoration endpoints use authenticated browser sessions, an independent CSRF
  token and an application-bound run identifier. Named preflight operations also
  require the current server revision; stale/unknown operations cannot select
  arbitrary handlers. Report API tokens are not restore
  authority. Preparation additionally requires a custom tool password, rather than
  relying on the older regex-based DB-password login fallback. Removing or changing
  that custom authority invalidates an existing run.
- Private checkpoints live outside the document root in a mode-0700 run directory.
  Checkpoint digests are bound to the session. Input identity/configuration changes,
  a changed application, expired state or integrity failures stop progression.
  Source archive byte validation belongs to the subsequent preflight, not this
  preparation receipt. A run expires after 24 hours; expiry never deletes backups.
- A private temporary checkpoint is **not a durable database backup**. No backup
  verification, database compatibility, recovery or successful restoration is
  established by preparing a run.

## Private archive preflight

After preparation, authenticated `POST ?action=wp_restore_step` requests with the
independent `csrf`, `run_id`, `stage=preflight`, `operation=files` and current
`revision` progressively copy and validate the selected ZIP. Each successful
response supplies the next server revision. Never automatically resend a stale
request. `GET ?action=wp_restore_state&manifest=1` exposes only member names,
dispositions, sizes and verified SHA-256 values—not member contents or private paths.

- No `extractTo`, shell extraction or backup PHP execution. Regular member payloads
  use hashed `.data` filenames in private storage, not archive-controlled paths.
- Native ZipArchive and a bundled ZIP32/zlib reader support stored/deflated members.
  Both verify complete lengths, CRCs and staged/source SHA-256 equality. No runtime
  library download is required. The portable reader rejects ZIP64 and legacy
  names without UTF-8 flags or valid Info-ZIP Unicode-path extra fields; native
  libzip may handle legacy encodings within the same quotas.
- Root-level WordPress layout is required, including `index.php`, `wp-load.php`,
  `wp-settings.php` and `wp-includes/version.php`. Links, traversal/absolute/backslash
  paths, duplicate case-folded names, file/directory collisions, encryption,
  unsupported compression and corrupt/truncated members fail preflight.
- Current quotas: 4 GiB minus one byte compressed input, 20,000 entries, 20 GiB
  declared total, 1 GiB per member and 2 MiB of canonical path text. Private free
  space is checked. These are supported-profile limits, not universal hosting
  capacity guarantees.
- At most 8 MiB of new source/member bytes are copied per step. Deflated entry
  resumption rereads its prefix; this is not a universal CPU or HTTP deadline.
  Uncertain manifest/checkpoint integrity fails closed rather than blessing partial
  state. A prepared checkpoint is still not a durable database backup.
- Destination config, the running/known tools and named original backups are
  skipped, but their source bytes are checked. Original files remain unchanged.
  `.htaccess`, other hosting controls, hidden paths, cache files, MU plugins and
  executable drop-ins are held privately, not eligible for automatic placement.
  Ordinary plugin files are not executed; later database preparation must disable
  their activation before any public placement.

## Selected shared-host fallback

A directory on the same hosting account is not an execution sandbox. The selected
future flow permits a separately confirmed restoration with a verified prior
backup but **without runtime certification**: plugins remain disabled, the backup's
`.htaccess` is withheld, and functionality is explicitly unverified. It must not
present unexecuted plugin/HTTP tests as passing. Image URL checks and controlled
plugin/hosting-rule activation remain explicit next steps.

If the hosting cannot perform an automated database-recovery check, the selected
backup fallback will accept explicit operator evidence that recovery of the prior
backup was already verified externally. Reports must identify that provenance;
operator verification is not an app-performed recovery test. An unverified backup,
a readable/checksummed file alone, or renamed old tables is not this prerequisite.
This database adapter/confirmation flow is not implemented yet.

## Local checks

```sh
# Use a project-owned TMPDIR when running the disposable HTTP fixtures.
mkdir -p .atl/wordpress-restore-proof/tmp
TMPDIR="$PWD/.atl/wordpress-restore-proof/tmp" \
WORDPRESS_RESTORE_HTML_OUT="$PWD/.atl/wordpress-restore-proof/page.html" \
php tests/wordpress-restore.php
node tests/wordpress-restore-ui.cjs .atl/wordpress-restore-proof/page.html
```

For each archive backend, run the public staging fixtures with `valid`, `large`,
`large_deflate`, `unicode_extra`, `traversal`, `backslash`, `absolute`, `symlink`, `duplicate`, `parent`,
`crc`, `encrypted`, `unsupported`, `oversize` and `truncated`:

```sh
TMPDIR="$PWD/.atl/wordpress-restore-proof/tmp" \
php tests/wordpress-restore-files.php native valid
TMPDIR="$PWD/.atl/wordpress-restore-proof/tmp" \
php tests/wordpress-restore-files.php portable valid
```

The fixtures use synthetic configuration/archives and a database double that
refuses connections. It does not execute WordPress or access a real database.
