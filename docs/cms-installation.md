# CMS installation discovery and draft wizard

**The Install CMS tab prepares a draft, not an installation.** Log in, explicitly
choose WordPress/Joomla/Drupal/MediaWiki and the app directory or a new isolated
sandbox, then prepare or resume the session draft. No directory is created.
After prepare/resume, enter an explicit loopback endpoint and transient credentials
for read-only database evidence. Installation and copying remain unavailable.
Existing report, database tools and legacy CMS detection are unchanged.

## Query the current application directory

Log in to `cms_tools.php` with the existing tool password/session, then request:

```text
GET cms_tools.php?action=cms_install_discover
```

The JSON schema is `cms-tools-install-discovery`, version 1. Responses use
`Cache-Control: no-store` and `X-Content-Type-Options: nosniff`. Unauthenticated
requests return JSON 401; authenticated non-GET requests return 405. Report API
tokens do not authorize discovery; existing machine-report dispatch takes priority.
No CSRF token is needed for this read-only GET. Draft routes are described below.

Discovery uses the directory containing `cms_tools.php` (`__DIR__`), even when
legacy tools detect a parent CMS. Arbitrary `path`/`directory` parameters are
ignored. It does not scan sandboxes or select a destination.

| CMS | Configuration evidence | Core evidence marker |
| --- | --- | --- |
| WordPress | `wp-config.php` | `wp-includes/version.php` |
| Joomla | `configuration.php` | `includes/version.php` |
| Drupal | `sites/default/settings.php` | `core/lib/Drupal.php` |
| MediaWiki | `LocalSettings.php` | `includes/DefaultSettings.php` |

Each CMS is reported independently, including mixed trees. `config_status` and
`core_marker_status` distinguish `absent`, `regular-file`, `unsafe-symlink` and
`unsafe-nonregular`. Symlinked intermediate directories are unsafe too. Discovery
only checks metadata; it never reads or executes these configuration/core files.
The exact discovery action bypasses legacy CMS configuration-content parsing,
including before authentication, so an unsafe configuration cannot inject PHP
diagnostics or absolute paths into discovery JSON. Other existing actions retain
their original bootstrap behavior; this unit does not harden legacy login or all
other routes against unsafe configurations.

`core_files_present` means **only the listed marker is a regular file**. A marker,
a configuration file or a PHP driver cannot prove complete core, a working service,
valid credentials, an authenticated database or an installed site. Both
`database_status` and `installation_status` remain `unverified` in every case.
There is intentionally no installation-ready flag. JSON contains no raw
configuration, credentials, absolute paths or parsed database connection details.

## Candidates are not validated installation options

`php_drivers` reports PHP extension availability only, without database connections
or network probes. Engine candidates are MySQL/MariaDB for WordPress core;
MySQL/MariaDB/PostgreSQL for Joomla; and MySQL/MariaDB/PostgreSQL/SQLite for Drupal
and MediaWiki. All profiles say `version-validation-pending`: exact target releases,
minimum versions, drivers and engine support must be validated before offering an
installation. These candidates are not a runtime compatibility matrix.

Unavailable here: selected-release compatibility, data copying,
configuration persistence, downloads, fresh installation, plugins/themes, sandbox
creation, migration/replacement, backups/recovery, and paid-product integration.
No config-only case is automatically reused or overwritten. Those require later
explicit authenticated workflows and independent validation.

## Session-only draft API

All fixed actions require the existing authenticated session. Machine-report
precedence still applies: query tokens/Bearer cannot authorize these routes. These
exact actions also bypass legacy config-content parsing before authentication.
Responses are no-store JSON with nosniff; no secrets or absolute paths are returned.

| Action | Method and input | Result |
| --- | --- | --- |
| `cms_install_prepare` | POST: independent installer `csrf`, scalar `cms`, `destination` (`app-directory` or `sandbox`) | 201 draft |
| `cms_install_state` | GET | 200 draft or `draft: null` for this app/session |
| `cms_install_step` | POST: installer `csrf`, `run_id`, `stage` | 503 `database_adapter_unavailable` for an owned current draft; no advancement |
| `cms_install_database` | POST: installer `csrf`, `run_id`, `engine`, `host`, optional `port`, `database`, `user`, `password`, optional `namespace` | Transient evidence; draft unchanged |

The tab provides the independent installer CSRF token. Prepare returns a random
`run_id`, chosen CMS/destination, `stage: awaiting_database_validation`, and Unix
`created_at`/`expires_at` timestamps (30-minute lifetime). The server binds the draft
to the authenticated session and this application directory, never a parent CMS.
Arbitrary paths/extra credentials are ignored, never stored. State is session-only:
no config, CMS files, directories, database calls or counters are written.

Wrong methods return 405, invalid CSRF 403, invalid inputs 400, unknown/foreign run
IDs 404, stale stages/existing drafts 409, expired drafts 410. Expiry is not automatic
repair: existing drafts, including expired/foreign-app drafts, cannot be overwritten.
A fresh authenticated session is needed for another draft. Resume queries state;
it does not resume backend installation. Nonempty-root replacement requires a later
separately protected workflow. Config-only/no-CMS/parent-CMS cases can select a draft,
but neither configuration reuse nor destination emptiness is inferred.

## Local read-only database evidence

1. Prepare or resume the owned draft in **Install CMS**.
2. Explicitly enter `localhost`, `127.0.0.1`, or `::1`, database and credentials.
   `localhost` becomes `127.0.0.1`; arbitrary remote hosts/socket paths are rejected.
3. Leave namespace blank for a generated ASCII proposal, or enter a custom ASCII
   identifier. The conservative input policy is not a vendor/release length guarantee.
4. Check local DB. User/password fields clear immediately; errors never echo input.

WordPress native PostgreSQL is rejected before connection. Joomla, Drupal and
MediaWiki can probe PostgreSQL. Driver availability, attempted endpoint, authenticated
service and observed engine/version are separate evidence. Unknown vendor/version
stays unverified. MariaDB's `5.5.5-` compatibility prefix is not its observed version.
There are no inherited minimum-version gates or selected-release compatibility claims.

The action performs at most two fixed SELECTs: bounded version metadata and up to
257 namespace rows. More than 256 rows means **unknown**, not collision-free.
MySQL/MariaDB tables **and views** are inspected in the connected database; Drupal
PostgreSQL uses relation prefixes in the connection-default schema. MediaWiki
PostgreSQL uses schema names (`wgDBmwschema`), not table prefixes. Other PostgreSQL
relations (including views) participate. Matching is conservatively case-insensitive;
actual engine case semantics remain unverified. A collision means preserve data:
fresh reuse/copy is not implemented. Absence means only **not observed in visible
metadata**, never globally unused, permissions sufficient, or ready to install.

mysqli must accept connect timeout (2 seconds), command-result/read timeout
(2 seconds), and LOCAL INFILE disabled **before** connection. Missing/rejected
options never fall back to an unprotected connection. Read protection is not
universal server-side cancellation or a hard end-to-end deadline. PostgreSQL uses
fixed libpq `connect_timeout=2` and startup `statement_timeout=1000` plus
`default_transaction_read_only=on`; it does not use runtime SET or PDO timeout as
statement protection. Requested limits are not claimed as independently observed.
Partial/failing metadata is unknown. Results/connections are released on all paths.

Credentials are used only for the attempt, never saved in session/config/browser
storage or reflected in errors. No connection inputs, DSNs, object lists or raw
exceptions are persisted. Evidence itself is transient and does not advance the
30-minute draft or enable installation. Config files are not read for credentials.
Verification here uses public HTTP with isolated namespaced mysqli/PDO doubles:
**synthetic evidence only**, not real driver/database acceptance.

## Resumen en español

La pestaña **Instalar CMS** permite elegir CMS y destino y preparar/retomar un
borrador de sesión de 30 minutos. **No instala CMS.** Tras preparar/retomar,
podés comprobar una BD de loopback con credenciales transitorias, que no se guardan.
La evidencia de colisiones se limita a metadatos visibles; no prueba permisos,
compatibilidad de versiones ni disponibilidad global. Copiar/reutilizar sigue bloqueado.
Configuraciones, marcadores y controladores no prueban instalación ni conexión.
No se crean directorios ni se reutilizan/sobrescriben configuraciones o datos.
Los motores y prefijos requieren validación posterior; plugins, temas, paquetes,
copias, migración y reemplazo siguen pendientes.
