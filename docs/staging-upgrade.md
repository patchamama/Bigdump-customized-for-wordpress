# Test a WordPress upgrade on separate staging

Run the upgrade workflow **only on separately provisioned staging**, never on production or the legacy symlink sandbox. It prepares an independent upgraded copy and reports bounded smoke checks; it does not promote, replace or restore production.

## Quick path

1. Keep a verified, restorable files backup **and full database backup**. Configuration snapshots and file-only exports are not a complete backup.
2. Have your host provision separate files, database accounts and execution permissions. Remove production credentials from copied plugins/configuration; block production databases, email, payments, webhooks and other outbound side effects at the infrastructure boundary. A declaration in PHP cannot enforce this separation.
3. Restore the baseline on staging. With a separate administrative connection, change `siteurl` and `home` to the staging HTTPS URL. Then give the baseline config a **direct SELECT-only database account**, restricted to that baseline schema. Do not use production's account.
4. Prepare another, empty schema and a different direct account for the upgraded copy. Enter this account in the upgrade form, not the baseline config. Different prefixes alone are not isolation.
5. Add the declarations below to a minimal staging `wp-config.php`, before the standard bootstrap. Open `cms_tools.php` at that exact staging HTTPS host/path, authenticate, and use **WordPress upgrade on separate staging**. Confirm both backup and separate-host acknowledgements.
6. Inspect every finding. Failed or unavailable required checks block readiness. Even a successful result needs manual application/integration review, another full backup, and a host-managed migration/rollback plan.

## Minimal staging config

Replace these synthetic values with staging-only values. The production URL is an identity comparison, not permission to contact production.

```php
<?php
define('WP_ENVIRONMENT_TYPE', 'staging');
define('CMS_TOOLS_PRODUCTION_URL', 'https://www.example.com');
define('CMS_TOOLS_STAGING_URL', 'https://staging.example.net');
define('CMS_TOOLS_ISOLATION_VERIFIED', 'separate-host');
define('DB_HOST', 'localhost');
define('DB_NAME', 'staging_baseline');
define('DB_USER', 'staging_reader');
define('DB_PASSWORD', 'replace-with-staging-reader-password');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'wp_';
define('WP_DEBUG', false);
// Add independent staging authentication keys/salts as literal strings.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
```

The tool reads and tokenizes this file; **it never includes or executes it**. Plugins in the source tree are inventoried, not executed there. The upgraded copy gets a newly generated config with its own database credentials.

### Supported literal grammar

This deliberately small grammar is not a PHP interpreter. Simplify a restored config rather than disabling admission checks.

| Construct | Supported form |
| --- | --- |
| File | One PHP opening tag, whitespace/comments, declarations, optional standard bootstrap and final closing tag |
| Database identity | Exactly one unconditional literal string for each of `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`; one literal `$table_prefix` assignment |
| Other declarations | Unconditional `define('NAME', scalar);`, with unique names from the list below |
| Scalars | Single/double-quoted strings without escapes or line breaks; nonnegative decimal integer literals; `true`/`false`. Dollar signs are allowed only in single-quoted strings, never interpolation |
| Bootstrap | `define('ABSPATH', __DIR__ . '/');` or `define('ABSPATH', dirname(__FILE__) . '/');`, optionally guarded by `if (!defined('ABSPATH'))` with/without braces, followed by `require_once ABSPATH . 'wp-settings.php';` |
| Formatting | Whitespace/comments and either quote style for string tokens are accepted; the bootstrap must be the final executable construct |

Other supported constant names: `DB_CHARSET`, `DB_COLLATE`; the eight standard authentication keys/salts (`AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, `NONCE_KEY`, `AUTH_SALT`, `SECURE_AUTH_SALT`, `LOGGED_IN_SALT`, `NONCE_SALT`); `WP_DEBUG`, `WP_DEBUG_LOG`, `WP_DEBUG_DISPLAY`, `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, `WP_MEMORY_LIMIT`, `WP_MAX_MEMORY_LIMIT`, `AUTOMATIC_UPDATER_DISABLED`, `WP_AUTO_UPDATE_CORE`, `DISABLE_WP_CRON`; and the four staging declarations shown above.

Environment lookups, arbitrary function calls, includes, expressions/concatenation in declarations, conditional configuration, duplicate definitions and unknown constants are rejected. Multisite, custom content/plugin/upload locations and drop-in layouts are unsupported. No unresolved PHP semantics are silently evaluated or ignored. A config that WordPress itself can run is not necessarily supported by this restricted workflow.

## Database admission and ordering

| Account | Required direct grants |
| --- | --- |
| Baseline reader | Only `SELECT` on the exact baseline schema, optionally `USAGE ON *.*` |
| Upgraded-copy writer | Schema-scoped `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER`; optional `INDEX`, `CREATE TEMPORARY TABLES`, `LOCK TABLES` |

Ask the host to inspect `SHOW GRANTS FOR CURRENT_USER` for both accounts. Roles, delegated privileges (`WITH GRANT OPTION`), global privileges, other-schema/table grants and wildcard schema permissions cannot establish the required boundary and are refused. Database underscores must be escaped as literal underscores in schema grants. The target schema and account names must differ from the baseline even across different hostnames; aliases/proxies make host-string differences insufficient.

Before requesting official release metadata or packages, admission verifies the source config grammar, source database identity and SELECT-only grants, and that the baseline `siteurl` matches the configured staging URL. Rejections at these boundaries create no run directory, do not write target tables, and do not change application settings. Source snapshots use repeatable-read, consistent-snapshot **READ ONLY** transactions with rollback; the reader is never used for target writes.

Both declared URLs must be literal HTTPS URLs without credentials, query or fragment, with different hostnames. The staging URL must match the tool's trusted HTTPS host and directory path. Forwarded headers are not accepted as evidence. Configure TLS termination with your host rather than bypassing this check.

## What the workflow protects—and what it cannot prove

- Official release metadata and packages use fixed allowlisted HTTPS endpoints, without following redirects. Archive paths, links, sizes and official checksums are checked before database preparation.
- Source content must be regular readable independent files, not symlinks/hardlinks. Root hosting rules are fingerprinted and must not be shared. Runtime mutations of the owned file manifest or baseline config/root rules stop the run.
- Hosting access rules are tested before copying site content/credentials. Effective PHP, core upgrade, individual plugins, the original active plugin set, HTTP identity/status and bounded assets contribute findings. Unavailable checks are not successes.
- Arbitrary copied PHP/plugins run with the staging host's permissions. WordPress filters are not a security sandbox. The operator's `separate-host` declaration records infrastructure verification; PHP does not certify network or filesystem containment.

## Sessions, credentials and retries

Ownership is held in the authenticated session, not an `upgrade_last_run` setting. Upgrade preparation leaves `.migrate_config`, including authentication/unrelated settings, unchanged. Reuse requires the same session, a completed upgrade-owned run and unchanged owned files; another authenticated session cannot adopt it by knowing its ID. Do not use the legacy sandbox as an upgrade target.

Target credentials remain in the session and generated owned config for staged processing/reuse; baseline credentials are also held in the run's session state. They are not returned in progress JSON. Protect server-side sessions and generated staging files. Treat a lost response as uncertain: inspect the owned run and target schema with your host before starting again. There is no automatic retry or destructive cleanup, and partial runs can retain files/tables.

## Verification scope

`php tests/wordpress-upgrade.php` exercises public HTTP admission and staged processing using synthetic files, fake PDO/HTTPS, and genuine synthetic ZIP bytes for native libzip coverage. `node tests/wordpress-upgrade-ui.cjs` exercises the actual browser controller. These checks do not install real WordPress, contact production, certify a hosting provider, or prove complete plugin compatibility. Optional Docker execution is a separate integration, not a prerequisite or a claim made by this guide.

### Bundled server rules

The official WordPress package can include `wp-content/plugins/akismet/.htaccess`. The upgrade workflow validates its official checksum but deliberately does not install that file: sandbox-owned hosting controls take precedence. Other archive server overrides (`.htaccess`, `.user.ini`, `php.ini`) and protected configuration files remain rejected. A corrupted Akismet rules file is rejected even though it would not be installed. This package exception is unchanged. Source admission separately verifies and omits (never installs) the exact-case `wp-content/plugins/akismet/.htaccess`, only as a regular unshared file up to 64 KiB with SHA-256 `6d8db93cbee3b408b9b8972684d20f33d739efd11d32ba584d6df272a7873011` (official WordPress 6.8.3 bundle) or `63553e7f320285fe2417cdb00664a136958d70baa07d7325e86f0847f11dd17c` (official 7.1.3 bundle). PHP reports the known omission as a `source-rules-omitted` warning, not routing compatibility. Original source bytes and private sandbox rules remain untouched. Unknown/modified files, other paths/case, links, PHP overrides, MU plugins and drop-ins still fail closed before network/database preparation. The Docker CLI also refuses root `.htaccess`; separate-host root-rule inspection is unchanged.

Only root `wp-content/index.php` may be copied as passive PHP: bounded `token_get_all` inspection must prove PHP opening tag/comments/whitespace only and match the existing inventory SHA-256. Executable tokens, inline HTML, oversized files and all other root PHP remain refused. The later independent copy verifies the same inventory hash, preventing inspection of benign bytes from authorizing different copied bytes.


## REST routes and permalink preservation

The isolated WordPress probe reports its core REST URL. Checks admit only standard
pretty (`/wp-json/`), index (`/index.php/wp-json/`), or plain
(`/?rest_route=/` or `/index.php?rest_route=/`, including their encoded-slash forms)
routes beneath the exact owned
sandbox URL. Older probes without this optional field retain the pretty-route
fallback. Foreign, traversing or malformed addresses fail before any authenticated
HTTP request. The workflow does not change the source permalink settings to make a
REST check pass; HTTP 200 HTML is not a successful REST response.
