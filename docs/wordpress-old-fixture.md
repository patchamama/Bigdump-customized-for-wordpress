# Genuine WordPress 6.8.3 fixture (isolated testing only)

This is T7 tooling for S1 and future S9 old-source evidence, **not** a public
installer or production operation. It installs official old PHP and its real
schema/default content using `wp_install`; it never edits a version marker.
No Docker execution or genuine installation is proved by the local helper tests.

## Parent-owned prerequisites

Review both scripts first. Use the existing trusted PHP 8.3/MariaDB executor
image (including `/opt/upgrade/import.php`, ZIP, PDO/mysqli, POSIX, runuser,
timeout and MariaDB tools). Do not pull dependencies or reuse site credentials.
The parent must attest image identity and fresh, uniquely labelled cache/data
volumes and container names, with no pre-existing state. Set `IMAGE`, `OWNER`,
`CACHE`, `DATA`, `STAGER`, `FETCH`, `INSTALL` to those verified identities.
Commands below neither create labels nor establish ownership: that is a mandatory
parent preflight. Never substitute a production path, bind mount, or socket.
Do not add privileges, namespaces, ports, or capabilities to make a failure pass.

Stage just the reviewed scripts into the fresh cache (no site/data mounted):

```sh
COPYFILE_DISABLE=1 tar -cf - docker/wordpress-upgrade/old-fixture.php docker/wordpress-upgrade/old-fixture-entrypoint.sh |
  docker run --name "$STAGER" --label "cms.fixture.owner=$OWNER" --interactive --network none --read-only --cap-drop ALL --security-opt no-new-privileges --pids-limit 64 --memory 128m --cpus 1 --mount "type=volume,src=$CACHE,dst=/cache" --entrypoint sh "$IMAGE" -c 'mkdir -m 755 /cache/tools && tar -xf - -C /cache/tools --strip-components=2 --no-same-owner --no-same-permissions'
```

Require exit 0, empty stdout/stderr, and inspect cache contents/ownership before
proceeding. Only `tools` may exist at cache root initially. Never retry a partial
prefetch in the same volume.

## Phase 1: trusted networked prefetch, no site or database inputs

```sh
docker run --name "$FETCH" --label "cms.fixture.owner=$OWNER" --read-only --cap-drop ALL --security-opt no-new-privileges --pids-limit 64 --memory 512m --cpus 1 --mount "type=volume,src=$CACHE,dst=/cache" --entrypoint timeout "$IMAGE" 300 php /cache/tools/old-fixture.php prefetch
```

This phase needs outbound HTTPS only, and must have **no** `/data`, host mounts,
Docker sockets, source configuration, secrets, or credential environment inputs.
The only URLs are the exact official 6.8.3 ZIP and en_US checksum endpoint.
TLS verification is mandatory; redirects/non-200 statuses fail. Responses have
30-second socket timeouts, 120-second transfer deadlines and 64 MiB/1 MiB caps.
ZIP validation limits entries to 6,000, individual files to 8 MiB and total
expanded size to 160 MiB. It rejects unsafe paths, links/nonregular types,
encryption, duplicate paths, missing/extra files and official MD5 mismatches.
MD5 here is the official manifest format, authenticated by HTTPS, not an
independent release signature. No archive PHP executes in this phase.

Require exit 0 and exactly this stdout, with empty stderr:
`{"ok":true,"phase":"prefetch","version":"6.8.3"}` plus newline.
Thereafter treat the cache as immutable; mount it read-only below.

## Phase 2: offline, one-shot real installation

```sh
docker run --name "$INSTALL" --label "cms.fixture.owner=$OWNER" --network none --read-only --cap-drop ALL --cap-add CHOWN --cap-add SETUID --cap-add SETGID --cap-add KILL --cap-add DAC_OVERRIDE --security-opt no-new-privileges --pids-limit 128 --memory 768m --cpus 1 --tmpfs /run:rw,nosuid,nodev,noexec,size=64m --tmpfs /tmp:rw,nosuid,nodev,noexec,size=64m --mount "type=volume,src=$CACHE,dst=/cache,readonly" --mount "type=volume,src=$DATA,dst=/data" --entrypoint bash "$IMAGE" /cache/tools/old-fixture-entrypoint.sh
```

No other volumes, bind/socket mounts, host namespaces, public ports or privilege
are permitted. Parent inspection of the actual Docker configuration is required;
the scripts cannot independently attest Docker ownership/isolation. The shell
refuses any initial data entry. A persistent one-shot marker precedes mutation;
startup polling only checks socket readiness, never retries initialization/SQL.
MariaDB runs as mysql with skip-networking. Preparation revalidates the complete
archive offline before extraction, creates a synthetic private database/account,
and writes a private synthetic `wp-config.php` with guarded ABSPATH bootstrap.
Old PHP runs as UID 33, checks real/effective UID and zero effective capabilities,
then installs with cron, outbound HTTP, mail and automatic updates disabled.
Mail is suppressed by a fixture-process-only pluggable stub; no artificial
MU-plugin is installed into the exported source.
Initialization/preparation/install have 90/90/180-second timeouts respectively.
Only this shell's MariaDB child is shut down; no external resource cleanup occurs.

Require exit 0 and exactly this stdout, with empty stderr:
`{"ok":true,"phase":"installed","version":"6.8.3","metadata":"/data/fixture-metadata.json"}` plus newline.
Normal script failures exit 1, with empty stdout and only
`Old fixture failed closed.` plus newline on stderr. Docker/runtime/timeout
failures can instead have their own exit codes/diagnostics: they are not a pass.
PHP `prepare`/`install` are internal modes (success is silent); `--help` exits 0
and prints usage without touching artifacts. Invalid CLI arguments fail closed.

## Artifacts and honest limits

- `/data/site`: actual old release, all default core/content retained. Fixture
  mail suppression is process-local, not an added source plugin. Config credentials are
  random fixture-only secrets; never print/copy them into evidence.
- `/data/seed.sql`: <=32 MiB, schema-only CREATE/INSERT export, validated with the
  existing restricted importer. Known table charset/collation and AUTO_INCREMENT
  options are preserved and reordered before `ENGINE=InnoDB`, not discarded.
  Prefix `old_`, twelve default tables, actual
  installed `db_version`, at most 1,000 rows/table. No dump/client commands.
- `/data/fixture-metadata.json`: bounded aggregate version/db_version, table/row,
  default published-post/admin counts; no identities, passwords, config or SQL.
- `/data/fixture-failure.json`, when produced: bounded private phase/class/basename/line
  only, without exception messages, arguments, SQL, config or stack traces.
- `/data/logs` and `/data/mysql`: private runtime artifacts, never public output
  or runner backup inputs. Failed volumes are evidence, not retry candidates.

For the existing runner use the explicit offline `site` directory as backup and
`seed.sql` separately with prefix `old_`. Do not pass the whole data directory.
Retain `wp-content/index.php`, packaged `.htaccess`, default themes/plugins and
all other genuine files even if admission rejects them. Record that exact
limitation for the parent to reproduce/fix; deleting files is not proof.
Parent evidence now records actual 6.8.3 installation and a genuine 6.8.3 → 7.1.3 upgrade: ready/runtime verified, 17 OK / 1 environment warning / 0 failed, PHP log empty, all 3,233 source hashes unchanged, and exactly three owned upgrade resources cleaned. This is attributed parent evidence, not a runtime rerun or independent acceptance by the integration worker.
S9 gets a genuine old source only: config reuse, copied-prefix installation and
latest-plugin restoration are **not** implemented/proved here. S7 rewrite and
S11 replacement/rollback proofs also remain separate parent-owned work.
