# WordPress backup restoration

**Current scope: detection, preparation, private ZIP staging and restricted SQL preflight only.** The browser offers preparation
when the application directory contains the tool, a literal `wp-config.php`,
`hosting_*.zip` and `db_*.sql.gz` backups, but no installed core or unrelated files.
The tool's own `.migrate_config` is allowed. Multiple backups require explicit
selection; the tool does not guess which pair belongs together.

Log in, set a custom tool password, review the selected files and destination URL,
and choose **Prepare restoration**. This creates a private, server-owned preflight
run. It does **not** extract backup code, connect to the database, back up or drop
tables, import SQL, activate plugins, change configuration or replace the site.
Database replacement/import and public file placement remain unavailable. The
private file/SQL preflight adapters are available through the session protocol below;
this is not a complete restoration wizard. SQL readiness means a privately parsed
plan, not database compatibility, an old-data backup, recovery or permission to import.

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

## Private SQL preflight — no database execution

Use the same authenticated `POST ?action=wp_restore_step` fields and current server
revision, with `operation=sql`. It privately copies `db_*.sql.gz`, checks one complete
gzip member and its CRC/length, and verifies decoded-source/private SHA-256 equality.
Trailing bytes, multiple members, corrupt/truncated streams and oversized declarations
are rejected. Original dump/config/tool files are not rewritten. No PDO connection,
SQL execution, public file placement or library download occurs.

The normalizer accepts a deliberately restricted single-site dump profile:

- Complete InnoDB `CREATE TABLE` definitions with explicit UTF-8 charset/collation,
  scalar/default metadata, ordinary primary/unique/index keys and supported column
  types; complete literal `INSERT ... VALUES` rows, including extended inserts and
  explicit reordered lists containing every column. Strings, binary/hex values,
  NULL and numeric literal spelling remain typed; quotes/escapes and semicolons
  inside strings are not global SQL search-and-replace targets.
- One unambiguous prefix and `siteurl`/`home`, the twelve standard core table names,
  and no foreign-prefix tables. Split URL layouts, multisite/ambiguous inventories,
  database-qualified names, views/triggers/routines/events, generated columns,
  foreign keys, expressions, arbitrary SET operations and unsupported DDL fail
  admission. WordPress runtime validity and target-server collation/type support
  are **not** certified by this grammar check.
- Recognized mysqldump SET/lock/key-control metadata is interpreted, never sent to
  MySQL. Database selection/creation, file I/O and privileged operations are not
  imported. Session settings become typed private frames. Auto-increment cells
  require captured integer IDs; literal zero requires explicit
  `NO_AUTO_VALUE_ON_ZERO` beforehand. TIMESTAMP requires an explicit UTC dump,
  including UTC before timestamp INSERTs. Unknown escaping modes are rejected.
  Conditional metadata is limited to guards up to MySQL 50699/MariaDB 100600;
  only the exact MariaDB 999999 client-sandbox directive is separately ignored.
- Current quotas: 512 MiB compressed/expanded SQL, 16 MiB per statement/frame,
  8 MiB per decoded string token, 1,000 tables, 20,000 columns (1,000 per table),
  256 indexes/table, 64 parts/index, 16 MiB private catalog and 1 GiB private plan.
  Copy/expansion writes at most 8 MiB per request; expansion rereads the compressed
  prefix. Parsing stops between statements after 64 statements or 1 MiB scanned;
  one extended INSERT is indivisible and may generate more private plan bytes.
  These limits do not promise a universal memory/CPU/HTTP deadline or hosting capacity.
- Frames use private `.data`/`.ndjson` storage, not executable backup filenames.
  SHA-256 chunk receipts cover the generated source-derived plan; all chunks and
  boundaries are checked before readiness, rather than blessing a changed file at
  EOF. A changed catalog/checkpoint stops progression. A future execution adapter
  must check the final recorded plan fingerprint again before consuming it.

`GET ?action=wp_restore_state&manifest=sql` returns only table/column names, target
name mapping, row counts, canonical source URL and fingerprints—never row payloads,
column-default contents or private paths. `schema_sha256` hashes the canonical
source CREATE frame; `data_sha256` hashes INSERT frames in source order. Frames are
JSON with unescaped slashes and one trailing LF, with binary/string bytes encoded
as base64. `plan_sha256` covers all frames, including recognized settings. These are
private-parse receipts, not successful import or recovery reports. Existing
`manifest=1` still inspects only ZIP metadata.

**Español:** `operation=sql` valida el gzip completo y prepara un plan tipado privado;
no conecta a la base ni ejecuta el dump. Acepta un perfil acotado de CREATE/INSERT
InnoDB, con charset/collation explícitos, prefijo único y valores literales. Rechaza
instrucciones peligrosas, expresiones, perfiles ambiguos y corrupción antes de
cualquier importación. `manifest=sql` muestra metadatos y huellas, nunca filas ni
rutas privadas. «Listo» no certifica compatibilidad, respaldo previo, recuperación ni
WordPress funcionando; el reemplazo sigue deshabilitado.

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

For SQL admission use `valid`, `extended`, `large`, `ddl-metadata`, `zero`, `literals`,
`database`, `qualified`, `expression`, `global`, `out-file`, `trigger`,
`ddl-expression`, `foreign`, `reserved`, `truncated`, `conditional`, `future-conditional`, `gzip-crc`,
`gzip-trailing`, `timestamp-ambiguous`, `mode-zero` and `plan-tamper`:

```sh
TMPDIR="$PWD/.atl/wordpress-restore-proof/tmp" \
php tests/wordpress-restore-sql.php valid
```

The SQL tamper case modifies only its own synthetic run in an exclusive inherited
TMPDIR, then checks rejection and unchanged public checkpoint/counters. It never
searches or modifies another run. Schema tests distinguish quoted keyword defaults
from unexecuted timestamp-function metadata.

The fixtures use synthetic configuration/archives/dumps and a database double that
refuses connections. It does not execute WordPress or access a real database.
