# WordPress backup restoration

**Current scope: detection and preparation only.** The browser offers preparation
when the application directory contains the tool, a literal `wp-config.php`,
`hosting_*.zip` and `db_*.sql.gz` backups, but no installed core or unrelated files.
The tool's own `.migrate_config` is allowed. Multiple backups require explicit
selection; the tool does not guess which pair belongs together.

Log in, set a custom tool password, review the selected files and destination URL,
and choose **Prepare restoration**. This creates a private, server-owned preflight
run. It does **not** extract backup code, connect to the database, back up or drop
tables, import SQL, activate plugins, change configuration or replace the site.
Execution is deliberately unavailable until its guarded adapters are implemented.

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

## Selected shared-host fallback

A directory on the same hosting account is not an execution sandbox. The selected
future flow permits a separately confirmed restoration with a verified prior
backup but **without runtime certification**: plugins remain disabled, the backup's
`.htaccess` is withheld, and functionality is explicitly unverified. It must not
present unexecuted plugin/HTTP tests as passing. Image URL checks and controlled
plugin/hosting-rule activation remain explicit next steps.

## Local checks

```sh
# Use a project-owned TMPDIR when running the disposable HTTP fixtures.
mkdir -p .atl/wordpress-restore-proof/tmp
TMPDIR="$PWD/.atl/wordpress-restore-proof/tmp" \
WORDPRESS_RESTORE_HTML_OUT="$PWD/.atl/wordpress-restore-proof/page.html" \
php tests/wordpress-restore.php
node tests/wordpress-restore-ui.cjs .atl/wordpress-restore-proof/page.html
```

The fixture uses synthetic configuration/archives and a database double that
refuses connections. It does not execute WordPress or access a real database.
