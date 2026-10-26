# CMS installation discovery (foundation only)

**No CMS installation or public HTML wizard is available in this unit.** This
read-only foundation provides evidence for a later, separately protected workflow.
It does not replace the existing report, database tools or legacy CMS detection.

## Query the current application directory

Log in to `cms_tools.php` with the existing tool password/session, then request:

```text
GET cms_tools.php?action=cms_install_discover
```

The JSON schema is `cms-tools-install-discovery`, version 1. Responses use
`Cache-Control: no-store` and `X-Content-Type-Options: nosniff`. Unauthenticated
requests return JSON 401; authenticated non-GET requests return 405. Report API
tokens do not authorize discovery; existing machine-report dispatch takes priority.
No CSRF token is needed for this read-only GET. There is no installer write route.

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

Unavailable here: credential entry/validation, prefix checks, data copying,
configuration persistence, downloads, fresh installation, plugins/themes, sandbox
creation, migration/replacement, backups/recovery, and paid-product integration.
No config-only case is automatically reused or overwritten. Those require later
explicit authenticated workflows and independent validation.

## Resumen en español

Esta entrega solo descubre metadatos de la carpeta de la aplicación. **No instala
CMS ni ofrece todavía un asistente HTML.** Un archivo de configuración, un marcador
del núcleo o un controlador PHP no demuestran una instalación ni una conexión
válida. Los motores son candidatos pendientes de validar para cada versión; no se
leen configuraciones desde los nuevos ayudantes, no se conecta a bases de datos y
no se escriben ajustes. La detección y las herramientas anteriores se conservan.
