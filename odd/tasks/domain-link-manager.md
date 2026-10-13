# Feature: Domain Link Manager

## Objective
Add a "Domain Link Manager" panel to the DB Tools tab that scans posts, postmeta
and options for all linked external domains, lists them with occurrence counts,
and lets the user select which domains to rewrite to root-relative paths. This
cleans up links pointing to the old site locations (e.g. igs-bonn.de/wordpress,
esgbonn.de/test) while preserving legitimate external links (YouTube, etc.).

## Problem / Why
The existing Media URL Scanner only handles `domain/wp-content/` asset URLs. The
real dump (db70013935_1.sql) has 27k+ links to the old site `igs-bonn.de/wordpress`
and 5k+ to `esgbonn.de/test` that are NOT wp-content (page links, anchors). These
surface stale content from old hostings. User needs to list every linked domain
and selectively convert the site's own old domains to relative links.

## Decisions (user-confirmed)
- Old-site domains → convert to root-relative (strip scheme+domain[+known subfolder]).
- New panel in DB Tools, keep existing Media URL Scanner untouched.
- Serialized-safe writes (recalculate s:LENGTH), same as fix_media_urls.

## Scope
- Allowed edit surface: `migrate_wordpress.php` only.
- New PHP actions: `scan_domains`, `fix_domains`.
- New HTML card in `#tab-tools`.
- New JS functions `scanDomains()`, `fixDomains()`.
- Bump `TOOL_VERSION` 1.0.3 → 1.1.0 (banner uses same constant, auto-synced).

## Disk-verified base-path (user-confirmed)
Do NOT guess the old-host subfolder (/wordpress, /test). Instead, probe strip
candidates and verify on disk: for each candidate base-path, count how many
referenced asset files (with a file extension) actually exist under $abspath
with the resulting relative path. Recommend the candidate with the most existing
files. If none verify, mark the domain "unverified" and let the user pick the
strip manually (degraded mode — files may not be uploaded yet). Same idea to be
applied later to fix_media_urls and export_db (separate commit).

## Tasks
- [x] T1: PHP `scan_domains` — aggregate domains + counts, exclude current host.
- [x] T2: PHP `fix_domains` — rewrite selected to relative, serialized-safe.
- [x] T3: HTML card "Domain Link Manager".
- [x] T4: JS `scanDomains()` + `fixDomains()`.
- [x] T5: Bump TOOL_VERSION to 1.1.0.
- [x] T6: scan_domains — detect strip candidates per domain + verify asset paths on disk, recommend best.
- [x] T7: fix_domains — accept per-domain strip base-path (default = recommended).
- [x] T8: JS — show recommended strip per domain as editable field; send map to fix_domains.

## Verification
- php -l: No syntax errors detected.
- HTTP 200 on localhost:8082.
- Rewrite logic unit-tested: 7/7 cases pass (old-host→relative with strip, externals untouched, serialized-safe valid unserialize).

## Acceptance
- Scan lists every non-current domain with accurate counts and sample URLs.
- Selecting old-site domains + Fix converts their links to `/path`, serialized rows stay valid.
- Legitimate external domains left untouched unless selected.
- `php -l` passes; page renders HTTP 200.

## Route
Delegated direct — single writer, one file, multiple non-trivial regions.

## Progress
- Branch feat/domain-link-manager created from main.
- Dump analyzed: igs-bonn.de/wordpress (27145), esgbonn.de/test (5136), rest external.
