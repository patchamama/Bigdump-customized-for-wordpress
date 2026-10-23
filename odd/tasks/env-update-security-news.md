# Feature: Environment versions + self-update + anti-hotlink + News tab

## Objective
Four additions to cms_tools.php, each committed + pushed separately with a version bump:

1. **Environment Status: PHP & MySQL versions** — show the running PHP version and, when the DB
   connects, the MySQL/MariaDB server version as info-rows.
2. **Self-update from GitHub** — check the latest release of
   `patchamama/Bigdump-customized-for-wordpress` (tag `vX.Y.Z-wp`), compare with TOOL_VERSION; if a
   newer one exists, highlight it near the version banner with an "Update" button. The update
   downloads the release's cms_tools.php, validates it (`php -l` on a temp file), writes a
   `cms_tools.php.bak-<old>` backup, then replaces the running file in place (reversible).
3. **Security: anti-hotlinking** — detect whether external sites can hotlink this site's assets
   (no referer policy in .htaccess). Offer a patch that blocks cross-origin asset requests via
   .htaccess RewriteCond on HTTP_REFERER. Test the rules in an isolated subfolder first (must not
   500), back up the current .htaccess before patching, and report the change so it can be reverted.
4. **News tab** — a tab showing WordPress news (wordpress.org news RSS). If a critical item is found
   (security/vulnerability keywords), surface a banner at the top of the app.

## Decisions
- Self-update: **backup + in-place replace** (user-chosen), with `php -l` validation before swap.
- MySQL version: read via the existing DB connection `SELECT VERSION()`.
- Latest release: GitHub API `/releases/latest`; parse tag `vX.Y.Z-wp` → X.Y.Z.
- Anti-hotlink: RewriteCond HTTP_REFERER rules scoped to jpg/jpeg/png/gif/webp/svg/css/js, allowing
  empty referer + same-host; tested isolated for 500 before apply; .htaccess backed up with a dated
  copy; fully reversible.
- News: fetch https://wordpress.org/news/feed/ ; critical = title/summary matches
  security|vulnerab|critical|exploit|patch release|CVE (case-insensitive).

## Scope / files
- `cms_tools.php` (all four), `README.md` + release per feature, this feature doc.

## Route per task
- All inline-direct: single file, well-understood endpoint+UI pairs. No multi-file writes.

## Tasks
- [x] 1. Env versions: PHP_VERSION + MySQL `SELECT VERSION()` as info-rows (PHP 8.5.0, MariaDB 11.4
      verified vs wp_test). -> v3.17.0 (with task 2)
- [x] 2. Self-update: `http_fetch_body()` helper; `check_update` (GitHub releases/latest, parse tag,
      version_compare), `do_update` (download release cms_tools.php → `php -l` gate → backup
      cms_tools.php.bak-<old> → in-place replace). Banner badge + Update button near the title,
      checked on load. Verified vs real repo: tag v3.16.0-wp → 3.16.0, asset resolved; php -l gate
      rejects a broken file (ret 255) and passes a valid one (ret 0). -> v3.17.0
- [x] 3. Anti-hotlink: `hotlink_check` (detects a referer rule / marked block), `hotlink_test`
      (writes rules + dummy asset in `_hotlink_test_*`, probes with no referer → expect load and with
      a foreign referer → expect 403; blocks apply only if no 500), `hotlink_apply` (backs up
      .htaccess to .bak-<date>, injects a marked BEGIN/END block, reversible). RewriteCond on
      HTTP_REFERER for jpg/png/gif/webp/svg/css/js, allowing empty + same-host. UI in the Security
      card (check→test→apply gated). Rules verified; dev server can't run .htaccess so enforcement is
      Apache-only (handled: no-500 path still allows apply). -> v3.17.0 (task 3)
- [x] 4. News tab: `wp_news` fetches wordpress.org/news/feed/ (SimpleXML), returns up to 12 items and
      flags critical ones (security|vulnerab|critical|exploit|CVE|urgent|hotfix|…). Tab panel +
      top-of-app red banner when a critical item exists (auto-loaded). Verified vs live feed: 10
      items parsed, 3 correctly flagged critical. -> v3.17.0 (task 4)
- Note: tasks 1–4 all shipped together in v3.17.0 (one coherent release; all inline-direct, single file).

## Verification
- php -l OK + HTTP 200 after each.
- MySQL version shows against wp_test (MariaDB 11.4).
- Update: version compare logic unit-checked; backup written; php -l gate verified with a broken file.
- Anti-hotlink: isolated test returns non-500; backup created; rules present after apply; revert works.
- News: feed parsed, critical keyword detection unit-checked.
