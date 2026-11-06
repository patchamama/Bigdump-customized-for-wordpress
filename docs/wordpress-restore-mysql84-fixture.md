# Isolated MySQL 8.4 SQL premise fixture

This is one synthetic engine-viability experiment, not product integration, T14
content assurance, hosting migration, recovery certification or final cutover.
Existing MariaDB scenarios and product runtime are untouched.

The runner reads the parent's immutable image binding under
`.atl/wordpress-restore-mysql84-proof/`. It never pulls/builds images. Two owned,
nonroot, read-only, network-disabled containers share only a fresh labelled
Docker socket volume (read-only for PHP). Datadirs and scratch are private tmpfs;
image-declared database volumes are overridden to prevent anonymous volumes.
Neither host files nor the Docker daemon socket are mounted. Ownership, image,
role, security flags and mount allowlists are checked before start and cleanup.

The SQL probe bootstraps a schema/account, then uses only `restore_fixture` for
both participants. It builds private independent typed expectations before locks,
sets autocommit=0 before LOCK TABLES, compares exact normalized schema and sorted
row digests, tests bounded competing mutations, then requires invalid-destination
and valid multi-RENAME plus committed journal acknowledgement in the same lock
window. Only known own leading identifiers and TEMPORARY marker are normalized;
defaults, indexes and AUTO_INCREMENT remain part of the comparison.

Run only with separate owner consent; the authorized invocation is limited to
30 seconds in the foreground, once, with no fallback or retry:

```sh
python3 tests/wordpress-restore-mysql84.py --endpoint unix:///Users/mandy/.docker/run/docker.sock
```

Raw command streams/exits and resource identities go into a new `native-*`
receipt directory. Failures stop subsequent SQL phases; ordinary validated
owned-container stop/remove and socket-volume removal are attempted on all paths.
A leftover resource is reported, never force-removed or adopted on a later run.
No process-kill, server-crash, TLS, generic MySQL or WordPress claim follows from
success. The separate proof HANDOFF records this invocation's observed outcome.

The single authorized invocation passed on MySQL 8.4.13: private reference and
locked comparison, collision 1050 with unchanged state, valid locked multi-RENAME,
committed journal, transferred exclusion and external acknowledgement. Both
normal accounts were verified; owned containers and socket volume were removed.
This does not admit the profile into the application or implement content seals.
