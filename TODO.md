# Pending work and known limitations

[English guide](README.md) · [Guía en español](README.es.md)

This is an implementation backlog, not a list of available features. Report generation,
private GitHub export and the external token API are implemented; the complete isolated
WordPress-upgrade workflow is **not integrated or verified**. The historical sandbox and
installer do not provide that workflow. No production replacement is authorized.

## P0 — Isolation and legacy safety

- [ ] Resolve the owner decision about the execution boundary for plugin tests. A copy
  of files plus a separate database does not prevent PHP running under the production
  identity from reaching production files, embedded credentials or external services.
  Prefer a separate hosting/container/user/filesystem/database boundary; do not claim
  OS isolation from WordPress filters or table prefixes.
- [ ] Eliminate the legacy sandbox's shared writable core/config/plugin/theme/upload
  links. In particular, never link live `wp-config.php` and then write sandbox config
  through it. Existing `sandbox_create` is unsafe as an update target.
- [ ] Replace broad derived-prefix / `test_env_*` cleanup with per-run ownership and
  checked resource manifests, so cleanup cannot affect other runs or live resources.
- [ ] Define backup, retention and failure cleanup for run files, private snapshots and
  partially populated target schemas. Never claim the configuration snapshot or a
  filesystem-only hosting export is a complete, tested recovery backup.

## P1 — Complete the requested WordPress upgrade workflow

An interrupted implementation is preserved in the registered development worktree
`.atl/worktrees/wordpress-upgrade-sandbox`. It is **not a deployable script**. Its backend
advanced beyond admission preview, but its browser/controller/documentation/tests are
not a completed, consistent delivery. Preservation is not approval or verification.

- [ ] Confirm authoritative source installation/database identity conservatively and
  reject configurations whose identity cannot be established; distinct names/prefixes
  and static configuration regexes are not sufficient proof for every layout.
- [ ] Finish and review bounded download/extraction of the latest official stable core,
  including TLS/origin restrictions, checksum coverage, real ZIP handling, traversal,
  entry types, duplicates and resource limits. Metadata success is not archive integrity.
- [ ] Complete verified independent copies of site resources, a restricted target
  database clone and isolated core DB upgrade. No writes to production resources.
- [ ] Reconcile browser actions with the staged backend: own the run ID, drive every
  step, show truthful progress, support only verified-owned reuse and disclose any
  credential/session retention accurately. Never say no sandbox was created if it was.
- [ ] Keep a trusted pre-execution file baseline; do not silently accept plugin-induced
  mutations by replacing the trusted manifest after execution.
- [ ] Verify individual plugins and the original active set, restore activation after
  failures, and distinguish load smoke tests from activation-hook and business testing.
- [ ] Check sandbox identity and HTTP failures, redirects, assets (including discovery
  limits), effective PHP settings, hosting rules, permalinks, admin and REST behavior.
  Missing proof, status 0, 4xx/5xx or incomplete coverage must not yield an all-clear.
- [ ] Provide specific failure tips, backup guidance and **manual migration advice only**.
  Automatic production promotion/replacement/restore is outside the agreed scope.
- [ ] Finish deterministic public-interface fixtures, genuine synthetic ZIP coverage,
  independent high-risk verification and integration/regression checks before publishing
  the workflow as available. Real hosting/WordPress compatibility remains unproven.

## P2 — Report/API improvements and deployment proof

These are follow-up items, not regressions being silently accepted as resolved:

- [ ] Consider a trusted configured report-URL origin or application-level Host allowlist.
  Generated API URLs currently use a syntactically validated request Host; operators
  must configure expected hosts at the server/proxy and verify copied URLs.
- [ ] Establish deployment checks for real TLS termination, state-file HTTP denial,
  filesystem permissions and private GitHub authorization/branch policies. Synthetic
  tests do not certify a particular hosting account or GitHub repository.
- [ ] Define optional browser-origin/CORS requirements if direct browser integrations
  are needed. The API currently supports server HTTP clients; it enables no CORS.
- [ ] Extend audit coverage only with explicit privacy/scope decisions: custom content
  paths, network activation/multisite metadata, dynamic configuration, current update/
  vulnerability feeds, full integrity and live TLS/SEO are not established today.
- [ ] Review broader legacy administrative CSRF, transport, path and cleanup boundaries;
  the new report/token checks do not certify every maintenance action as hardened.

## Documentation and delivery discipline

- [ ] Update both language guides and this backlog whenever a pending capability really
  ships; do not promote an interrupted preview or mock-only success into a safety claim.
- [ ] Publish feature-branch work only after a separate owner decision. Local commits do
  not make new download URLs/features available on the repository's `main` branch.

## Verification already available

The maintained synthetic suites are `tests/site-report.php`,
`tests/site-report-api.php`, `tests/site-report-ui.cjs` and
`tests/site-report-api-ui.cjs`. They cover report/API behavior, privacy, simulated GitHub
writes, token lifecycle and persistence failures, not a real WordPress core upgrade.

See [README.md](README.md#verification) for exact commands and test boundaries.
