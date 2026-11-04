# WordPress backup restoration

**Current scope: detection, preparation, private ZIP staging, restricted SQL preflight and private value preparation only.** The browser offers preparation
when the application directory contains the tool, a literal `wp-config.php`,
`hosting_*.zip` and `db_*.sql.gz` backups, but no installed core or unrelated files.
The tool's own `.migrate_config` is allowed. Multiple backups require explicit
selection; the tool does not guess which pair belongs together.

Log in, set a custom tool password, review the selected files and destination URL,
and choose **Prepare restoration**. This creates a private, server-owned preflight
run. It does **not** extract backup code, connect to the database, back up or drop
tables, import SQL, activate plugins, change configuration or replace the site.
Database replacement/import and public file placement remain unavailable. The
private file/SQL/value preflight adapters are available through the session protocol below;
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

## Private value preparation — not a live migration

After SQL preflight is ready, use authenticated `POST ?action=wp_restore_step` with
`csrf`, `run_id`, `stage=preflight`, **`operation=values`** and the current `revision`.
This prepares a **new** typed private plan; it never changes the original SQL plan,
selected inputs, destination credentials or live database. Existing preparation,
state and manifest responses stay unchanged until values preparation advances.
There is no values button or import/placement adapter yet.

| Prepared-plan rule | Result |
|---|---|
| Source site-base URL | Rewrite to the confirmed target URL at conservative URL boundaries, including slash-escaped JSON URLs and supported nested serialized strings. Preserve other hosts, emails and neighboring paths. |
| `posts.guid` | Never rewrite, including its default: GUIDs are feed identities, not navigational links. |
| Tables and permissions | Structurally map table names to the literal destination config prefix. Map the exact role option name and prefix-starting usermeta keys, including compatible key defaults/type members. Do not blanket-replace prefixes in business values. |
| `active_plugins` | Require exactly one explicit option and prepare `a:0:{}`. `plugins_disabled` describes this private plan only, not a change to running WordPress. |
| Cache | Omit `_transient_` and `_site_transient_` option rows. Persistent business options, credentials and unrelated keys remain. T2 separately holds disk caches, MU plugins and drop-ins. |

### Supported value profile and limits

The byte-syntax reader supports PHP serialized strings, arrays and ordinary `O`
object representations **without instantiating classes or calling hooks**. It repairs
only changed string byte lengths; unchanged token spelling, numeric/NULL values and
reference markers remain exact. Serialized strings can contain nested serialization,
UTF-8, NULs and quotes. This is bounded syntax preparation, not certification that an
arbitrary class/reference graph or plugin will work in WordPress. No `unserialize`,
backup PHP/config execution, SQL-text replacement or legacy import helper is used.

- A value beginning with a serialization-like one-letter tag must parse completely.
  Malformed/unknown tokens fail closed. Custom `C` payloads and opaque `E` enum tokens
  are retained byte-for-byte only when no URL rewrite is needed; otherwise reject.
  Binary/hex SQL literals, non-text columns and numeric/NULL tokens remain typed and
  untouched. This is **not** a decoder for every encoded or hardcoded URL.
- Changed text must remain valid UTF-8 and fit its declared text/character capacity;
  prepared defaults, ENUM/SET choices and changed row values must agree. SET members
  containing commas, detected key/type-member collisions, missing required core
  columns and absent/duplicate plugin activation options reject readiness. Core
  option/usermeta key matching is restricted to printable ASCII and conservatively
  checks ASCII case-insensitive collisions; other key/collation layouts need offline
  migration. Target-server type/collation compatibility is still unverified.
- Limits: 8 MiB per decoded/output value; depth 32; 10,000 members/container;
  20,000 serialized nodes and 32 MiB aggregate input/emitted syntax bytes per step
  (nested/repeated passes count toward these conservative quotas). At most 32 source
  frames or 1 MiB are selected per step; one frame remains indivisible. Generated
  batches are limited to 32 MiB, catalogs to 16 MiB, scoped-key inventory to 100,000
  and plans to 1 GiB. Existing SQL frame limits also apply. These limits can reject
  large valid backups; they do not promise a universal HTTP/CPU/memory deadline.
- Every step rereads/authenticates the complete immutable source plan against its
  final SHA and original source-generated chunk ledger **before transformation**;
  the captured bytes are the authenticated bytes actually transformed. This costs
  repeated source reads. Private metadata binds hashes of generated output chunks;
  all chunks are verified before readiness, not merely hashed after tampering.
  A future executor **must recheck the final prepared-plan SHA before consumption**.

`GET ?action=wp_restore_state&manifest=values` adds `values_manifest` only when ready:
`tables` (destination names, column names, row counts, `schema_sha256`), `row_count`,
`omitted_rows`, `plugins_disabled`, `source_plan_sha256`, `plan_sha256` and
`data_sha256`. CREATE/INSERT fingerprints use the same canonical JSON/LF encoding
as SQL preflight; no cells, defaults, base64 data, credentials or private paths are
public. `preflight.values` adds only `ready`, `processed` and `total`; byte counters
refer to the original typed plan. Errors use the existing bounded preflight JSON
and do not advance the public checkpoint. `manifest=sql` and `manifest=1` keep their
original meanings. Backup/runtime/replacement flags remain **false**.

**Español:** después de `operation=sql`, `operation=values` prepara otro plan privado:
migra URLs con límites, serialización sin instanciar objetos, prefijos de permisos,
plugins desactivados en el plan y omisión de transients. Nunca cambia `posts.guid`,
los originales ni la base viva. `manifest=values` publica solamente contadores,
nombres y huellas; `plugins_disabled` no afirma haber modificado WordPress. Se
rechazan estructuras desconocidas, conversiones inciertas, colisiones y exceso de
cuotas. Los tokens binarios/hex y los formatos opacos sin cambios se conservan; no
se promete cubrir todas las URLs codificadas, clases ni compatibilidad de plugins.
Las claves core no ASCII requieren migración offline. «Listo» no certifica el
servidor, recuperación ni funcionamiento; el reemplazo permanece deshabilitado.

After a separately backed-up, explicitly confirmed future import, manually review
image/embedded URLs, permalinks and plugin-specific encodings; test functionality,
then enable ordinary plugins **one at a time**. Review held MU/drop-ins and hosting
rules separately. No automatic plugin activation or successful runtime test is implied.

References: [WordPress: never change GUIDs](https://developer.wordpress.org/advanced-administration/upgrade/migrating/#important-guid-note),
[PHP: untrusted unserialize is unsafe, even with allowed_classes](https://www.php.net/manual/en/function.unserialize.php).

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

For the complete new values matrix, run exactly:

```sh
php tests/wordpress-restore-values.php
```

It creates exclusive owner-labelled scratch below
`.atl/wordpress-restore-values-proof/tmp`, retains exact per-case stdout/stderr/exit
receipts outside `tmp`, and leaves state for **parent-owned cleanup**. Success checks
use public HTTP metadata and fingerprints, not private storage. Private access is
limited to source/output tampering in that case's exact owner-bound run. Cases cover
URL/serialization identity, schema/default/ENUM/SET migration, permissions, plugin
and transient rules, supported bounds, collisions and authenticated tamper rejection.
The namespaced wakeup trap must never execute. Existing regression/differential
checks remain separate; this matrix does not replace them.

The fixtures use synthetic configuration/archives/dumps and a database double that
refuses connections. It does not execute WordPress or access a real database.
