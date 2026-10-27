# Optional offline Docker upgrade tests

Use Docker **instead of separately provisioned hosting only for this optional local test**. Reports, the token API and separate-host testing have no Docker dependency. The web option only shows instructions: web PHP never starts Docker or receives its socket.

**Status: integration candidate; final independent acceptance is pending.** Parent-observed isolated runtime proof upgraded genuine WordPress 6.8.3 to 7.1.3: ready/runtime verified, 17 OK / 1 environment warning / 0 failed, PHP log empty, all 3,233 source hashes unchanged, and exactly three owned runtime resources cleaned. This proof covers that fixture and executor only, not arbitrary plugins, production hosting, full installation or recovery. The integration includes literal-config/SELECT-only source admission. Worker checks are synthetic; they are not independent runtime certification.

## Quick path (after runtime operations are separately authorized)

Prerequisites: local Docker Engine/Desktop with a local Unix-socket context, Python 3, host PHP 8+, a trusted clean source checkout and enough disk space. Unset `DOCKER_*` overrides. The local daemon/operator and the image are trusted; this does not contain a malicious daemon or a kernel/container escape.

1. Keep a verified restorable full files **and database** backup, plus a rollback plan. Work from another **offline backup copy**, never a live site directory or live database. Remove embedded production credentials/integrations from plugins and content. The runner cannot recognize arbitrary secrets embedded in code/data.
2. Prepare plain single-database SQL: only supported `CREATE TABLE` and `INSERT INTO ... VALUES` statements, semicolon terminated, backtick table names with the supplied prefix, self-contained InnoDB tables. No `USE`, `CREATE DATABASE`, routines, triggers, events, references, grants, client commands, executable comments or general mysqldump directives. This intentionally narrow importer rejects unsupported dumps rather than guessing. Current CREATE syntax ends in `ENGINE=InnoDB` (strip unrelated table options during offline export preparation). It never runs the MariaDB command-line client; therefore no client command interpretation, `--force` or unsafe client fallback exists.
3. Build the image **only when explicitly authorized**, from a clean checkout containing no backups/secrets:
   ```sh
   docker build -f docker/wordpress-upgrade/Dockerfile -t cms-upgrade-local .
   docker image inspect cms-upgrade-local --format '{{.Id}}'
   ```
   The build installs PHP extensions, Apache, MariaDB and OpenSSL. It needs network access; the runner never builds/pulls automatically. Review the Dockerfile/base image and record the actual image ID/settings. A tag is not accepted as a runtime image identifier.
4. Read help and invoke the local runner, substituting your offline paths and the actual local image ID:
   ```sh
   bash docker/wordpress-upgrade/run.sh --help
   bash docker/wordpress-upgrade/run.sh \
     --backup /absolute/offline-backup \
     --sql /absolute/plain-single-database.sql \
     --production-url https://production.example \
     --prefix wp_ \
     --image sha256:REPLACE_WITH_64_HEX_LOCAL_IMAGE_ID
   ```
   No production URL is fetched. It is only an identity marker. Do not pass database passwords/tokens as CLI arguments.
5. Review the bounded JSON. `ready` requires all required staged smoke checks, not just successful container startup. `container_php` reports effective mod_php version/memory/execution/upload/post limits; `container_server` identifies Apache, **not production compatibility**. Plugin/assets/HTTP results are aggregated by severity to avoid exposing names, content or secrets. Findings are not comprehensive plugin/integration certification. Never automatically promote production.
6. Retained resources contain sensitive copied site/database data. The JSON includes an unpredictable `owner` and explicit resource names. When finished, explicitly clean only that run:
   ```sh
   bash docker/wordpress-upgrade/run.sh --cleanup REPLACE_WITH_32_HEX_OWNER_FROM_JSON
   ```
   Cleanup enumerates only this exact ownership label, checks the fixed expected names and labels before any removal, stops the owned container and removes its explicit volumes. No force, prune, shared-resource repair or cleanup of other runs. If cleanup fails, inspect the named owned resources locally; do not broad-prune. Failure/timeout may leave partial owned resources; no mutation is automatically retried.

## Trust and isolation boundaries

| Phase | Controls |
| --- | --- |
| Local admission | Explicit offline directory and SQL only; regular independent files; path/file/byte limits; reject symlinks/hardlinks/devices. Exclude every original `wp-config.php` and `.migrate_config`; reject hidden configs/logs/dumps. Validate SQL before daemon mutations. |
| Trusted prefetch | Fresh uniquely labeled cache volume only; **no site/code/SQL/data volume**. Fixed official HTTPS `api.wordpress.org` and `downloads.wordpress.org` URLs, TLS peer/name validation, no redirects, bounded responses. Downloads metadata/checksums/core only; never executes site PHP. |
| Offline executor | `--network none` (only loopback), no published ports, bind mounts, Docker socket, privileged mode or host network/PID/IPC/UTS. Independent labeled volumes for cache/data; read-only image/cache; bounded RAM/CPU/PIDs/tmpfs. Local MariaDB socket and Apache HTTPS share this offline namespace. An internal bridge is deliberately not used—it can reach host/gateway services. |
| Setup identity | Root performs trusted initialization only; exact limited setup capabilities are inspected. MariaDB runs as mysql. Actual mod_php identity must be non-root with zero effective capabilities before any upgrade is driven. Imported SQL uses a temporary schema-restricted PDO account without FILE/global/admin grants; multi-statements are disabled and each parsed statement is separately executed. Source becomes a separate direct SELECT-only reader; target is an independent empty schema/restricted writer. |
| TLS | New ephemeral CA/leaf for `staging.invalid`, stored/trusted only inside the container. No host trust modification or published HTTPS port. |
| Effective checks | Host performs bounded context/image/volume/container inspection before starting the executor and again before invoking the staged driver. Unsafe/missing isolation, labels, namespaces, mounts or config fail closed. |
| Staged execution | Existing authenticated `upgrade_prepare` / `upgrade_step`, CSRF/session ownership, official archive checksum/path/size checks, consistent source snapshot, target emptiness, generated upgrade rules, core/plugin/HTTP/assets/effective PHP smoke checks. No production promotion. |

The baseline config contains literal `WP_ENVIRONMENT_TYPE='staging'`, `CMS_TOOLS_PRODUCTION_URL=<origin>`, `CMS_TOOLS_STAGING_URL='https://staging.invalid:8443'`, `CMS_TOOLS_ISOLATION_VERIFIED='docker-network-none'`. This marker is **runner/operator attestation, not PHP proof of OS isolation**. Never place it on production.

The root-owned cache has four single regular files: raw official `release.json`, raw `checksums.json`, `package.zip`, and `provenance.json` (`schema:1`, version, capture Unix seconds, official package URL, SHA-256). PHP requires root-controlled ancestors, cache not writable by PHP, freshness at most one hour, matching provenance/package, and unchanged fingerprint between staged calls. Missing/stale/changed/malformed cache stops execution; no network fallback. Existing official release/archive verification remains required.

## Intentional failures and limitations

- Source `.htaccess`, `.user.ini` and `php.ini` are **refused**, except for the exact-case `wp-content/plugins/akismet/.htaccess` with one of the two authenticated official hashes listed below. This single regular, unshared file (at most 64 KiB) is verified and **omitted**, never installed/executed; successful CLI JSON reports `source_rules_omitted`. Modified bytes, other paths/case and links still fail before daemon changes. Docker's generated private Apache rules remain in force; omission is not source-routing compatibility proof. Root `.htaccess` is still refused by the CLI.
- Standard layout only: custom content/plugin/upload directories, drop-ins, multisite and unsupported SQL/layouts fail closed through existing admission. Large sites exceed bounded copy/snapshot/step limits; use dedicated staging.
- SQL/client logs and database contents are never printed by the runner. Logs and copied data remain private inside retained owned volumes; treat those resources as sensitive. Original production configs are not copied. New isolated credentials stay in container config/private session/runtime files, not client argv or public JSON.
- No live backup creation, deployment provisioning, production replacement/restore, remote Docker context, automatic dependency installation at runtime, automatic retry/repair or external integration testing.
- **Remaining acceptance:** independent review of the integrated candidate, representative application/plugin business flows, real separate-host behavior and recovery. The parent-observed genuine-old fixture run above does not establish these; synthetic tests do not substitute for runtime proof.

## Synthetic checks (no Docker daemon/network/site/database)

```sh
php tests/wordpress-upgrade-docker.php
bash tests/wordpress-upgrade-docker.sh
node tests/wordpress-upgrade-ui.cjs
bash -n docker/wordpress-upgrade/run.sh
php -l cms_tools.php
```

The PHP provider fixture replaces ownership checks only inside its synthetic namespace and hard-refuses HTTPS. The CLI fixture substitutes a fake `docker` executable in a temporary PATH and never connects to a daemon. Neither test mode weakens production controls.

## Resumen en español

Docker es **opcional**; reportes, API y hosting separado siguen funcionando sin Docker. La opción web muestra instrucciones locales y nunca ejecuta Docker/plugins ni envía producción. Usa un backup independiente sin conexión y SQL simple de una sola base; no una ruta o base activa. El CLI descarga solo core/metadatos oficiales con TLS verificado, luego usa copias y recursos propios sin red externa, puertos publicados, montajes del host ni socket Docker. MariaDB y Apache/TLS se ejecutan dentro del mismo entorno offline; PHP web no es root.

Ejecuta los pasos anteriores solo tras autorizar operaciones reales. Los resultados describen el PHP/Apache del contenedor, no la compatibilidad del hosting original. `.htaccess` y overrides PHP originales se rechazan, salvo las dos versiones oficiales fijadas del archivo Akismet indicado abajo: se verifican y omiten, nunca se instalan; el JSON informa `source_rules_omitted`. Esto no prueba compatibilidad de las reglas originales. La importación SQL es restringida, sin comandos de cliente ni privilegios globales. No se promueve/restaura producción. Conserva otro backup completo antes de cualquier migración manual.

Parent-observed real-runtime evidence is summarized above; final independent integration acceptance remains pending. Partial resources can retain sensitive data: clean only with `--cleanup OWNER` from the JSON, without prune or destructive repairs.

### Bundled server rules

The official WordPress package can include `wp-content/plugins/akismet/.htaccess`. The upgrade workflow validates its official checksum but deliberately does not install that file: sandbox-owned hosting controls take precedence. Other archive server overrides (`.htaccess`, `.user.ini`, `php.ini`) and protected configuration files remain rejected. A corrupted Akismet rules file is rejected even though it would not be installed. This package exception is unchanged. Separately, source admission permits **omission only** of the exact Akismet path with SHA-256 `6d8db93cbee3b408b9b8972684d20f33d739efd11d32ba584d6df272a7873011` (official WordPress 6.8.3 bundle) or `63553e7f320285fe2417cdb00664a136958d70baa07d7325e86f0847f11dd17c` (official 7.1.3 bundle). The original file is untouched; no source override is copied. PHP reports a `source-rules-omitted` warning. Other overrides, MU plugins and drop-ins remain refused.

The standard root `wp-content/index.php` is copied only when bounded PHP token inspection proves it contains an opening PHP tag, comments and whitespace only, with the inspected bytes matching the captured inventory hash. Executable tokens, inline HTML and other root content PHP remain refused. This is not a blanket ignore of content PHP.


## REST routes and permalink preservation

The isolated WordPress probe reports its core REST URL. Checks admit only standard
pretty (`/wp-json/`), index (`/index.php/wp-json/`), or plain
(`/?rest_route=/` or `/index.php?rest_route=/`, including their encoded-slash forms)
routes beneath the exact owned
sandbox URL. Older probes without this optional field retain the pretty-route
fallback. Foreign, traversing or malformed addresses fail before any authenticated
HTTP request. The workflow does not change the source permalink settings to make a
REST check pass; HTTP 200 HTML is not a successful REST response.
