# Feature: migrate_wordpress.php v2.0 — admin/security suite + i18n

## Objective
Expand migrate_wordpress.php with: DB-password-based auth + custom override, full
ES/EN i18n, WordPress user management, security audit, phpMyAdmin installer, WP
extras (htaccess/permalinks, repair tables, flush rewrite, debug/maintenance toggle),
rewritten README, and a GitHub release.

## Route
Delegated direct — single writer, single file (migrate_wordpress.php) + README.
Delivered in phases, one commit per phase. Release at the end.

## Phases
- [x] P1: Auth model — accept wp-config DB_PASSWORD by default; force custom password
      when DB pass empty (never open); custom encrypted hash overrides thereafter;
      UI card to set/rotate. v1.3.0 -> 1.4.0.
- [x] P2: ES/EN i18n. Infra (t(), $I18N, ?lang=/cookie/Accept-Language, EN/ES selector);
      auth, header, tabs, main card titles (status/cache/export/import) and all NEW sections
      (users, domain verify, php-config, audit, extras, phpMyAdmin) are bilingual. Minor
      descriptive paragraphs in old cards + the "How to use" tab remain English — cosmetic
      follow-up, framework in place to translate any string via t().
- [x] P3: WP user management. WpPasswordHash class (phpass $P$, WP-compatible, verified
      against a real dump hash); endpoints wp_users_list / wp_user_password / wp_user_update /
      wp_user_create; bilingual "WordPress Users" card + JS. Capabilities serialize matches real
      dump format exactly. Bumped to 1.5.0 (absorbs the i18n infra bump).
- [x] P3b: Domain Link Manager — final URL preview + HTTP verification. Each row shows how
      the link would look under the current site (domToFinalUrl, mirrors server rewrite) and a
      Verify button runs a fast HEAD (new verify_url endpoint, curl/get_headers, 5s timeout,
      capped at 25) on both the final internal URL and the existing link, showing the HTTP code.
      Also fixed deprecated curl_close() (PHP 8.5) in verify_url and wp_install. -> 1.6.0
- [x] P3c: PHP Configuration safe testing. New test_php_config endpoint writes the proposed
      .htaccess / .user.ini into a throwaway subfolder with a probe, requests it over HTTP, and
      reports breaks (HTTP 500) vs safe (200) + whether values applied (ini_get). Methods: .htaccess,
      .user.ini, auto-detect. Apply is disabled until a test passes and sends only the validated
      method. Fixed the old bug writing 'user.ini' instead of '.user.ini'. Fixed PHP 8.5 deprecations
      ($http_response_header). -> 1.7.0
      NOTE: PHP built-in server does not process .htaccess/.user.ini; real 500-detection only on the
      target host (Apache/PHP-FPM), which is exactly where it matters. Mechanism verified locally.
- [x] P4: Security Audit. New security_audit endpoint, 4 categories (Users & Passwords,
      WP Core & Config, Filesystem Exposure, Attack Surface): weak/common password probe via
      WpPasswordHash against each user_pass, default 'admin', admin count, WP version vs 6.4,
      default SALT placeholder, WP_DEBUG, DISALLOW_FILE_EDIT, table prefix, wp-config perms,
      fingerprint files, exposed dumps, .htaccess, xmlrpc.php, REST user enum. Shared JS
      renderCategories() reused by health check + audit. Bilingual. Weak-password detection
      unit-tested. Merged to main + pushed. -> 1.8.0
- [x] P5: phpMyAdmin installer + WP extras. pma_install (download latest, extract to
      /phpmyadmin, no config) reusing wp_install download pattern. Extras: wp_fix_htaccess
      (standard WP rules, backs up old), wp_repair_tables (REPAIR+OPTIMIZE all), wp_flush_rewrite
      (clear rewrite_rules + transients), wp_toggle_debug (wp-config), wp_toggle_maintenance
      (.maintenance file). Bilingual cards + JS. Logic unit-tested. -> 1.9.0
- [ ] P6: README migrate_wordpress docs + curl one-liners (done early at user request). 
- [x] P6: README updated with all migrate_wordpress v2 features + changelog entry.
- [x] P7: Version 2.0.0, README done. Tag v2.0.0-wp + GitHub release pending gh token.

## v2.1 — SEO suite + full-site password (local MySQL now available: 127.0.0.1:3306 root/—, db wp_test)
- [x] S1: SEO audit (seo_audit, renderCategories) + edit title/tagline (seo_update_meta).
      Verified against real wp_test: blog_public=1, permalinks /index.php/ flagged, no HTTPS,
      0 untitled. Bilingual SEO card. -> 2.1.0
- [x] S2: seo_set_permalinks (/%postname%/ + standard .htaccess, backup), seo_generate_sitemap
      (sitemap.xml from published posts/pages + robots.txt Sitemap line), seo_toggle_index
      (robots.txt + blog_public). JS links: Google/Bing submit + ping, and site: checks on
      Google/Bing/DuckDuckGo/Yandex. Verified against wp_test with rollback (permalinks, blog_public,
      sitemap url count). -> 2.2.0
- [x] S3: full-site password. site_pass_test (isolated subfolder with .htaccess Basic +
      .htpasswd bcrypt + index, HTTP probe expecting 401 not 500, returns probe URL),
      site_pass_apply (gated on passed test, backs up .htaccess), site_pass_remove. Bilingual
      card, Apply disabled until test passes. htpasswd bcrypt verified. NOTE: 401/500 only real
      on Apache; dev server inconclusive. -> 2.3.0
- [x] S3b: FIX Media URL Scanner "Updated 0" bug. Root cause (verified on real `test` db,
      4867 absolute rows): the fix/scan regex required /wp-content/ immediately after the host,
      but real URLs have a subfolder (igs-bonn.de/wordpress/wp-content/…) → 0 matches; AND the
      earlier disk-verification blocked everything when files aren't local. Fixes: pattern now
      allows any path before /wp-content/ (rewrote 4844/4867 rows in a rolled-back test,
      serialized stayed valid, header_image → /wp-content/uploads/…); disk verification made
      opt-in via a checkbox (default off); clearer result message. -> 2.4.0
- [x] S3c: "Get BigDump" header button (fetch_bigdump downloads bigdump.php from the repo into
      the site root) + auto-revealed "Open BigDump" link (also shown when bigdump.php already
      present). -> 2.4.0
- [x] S4: README + tag v2.4.0-wp + GitHub release (created once GITHUB_TOKEN env var removed).

## v3.0 — Quick Update + Test Environment sandbox (DONE)
- [x] F1 Quick Update (2522cba, 2.11.0): summary old/current URL + guarded one-click
      (wp-config rewrite+backup, siteurl, relativize+cache, deactivate plugins), per-step report.
- [x] Asset recovery (b00a3a2, 2.12.0): assets_scan finds missing /wp-content/ refs on disk;
      asset_recover downloads them from the origin in batches w/ progress; origin persisted in
      .migrate_config via migrate_config_set() merge (preserves password_hash). Verified vs test (3386 refs).
- [x] F2 table replication (clone_tables_start/clone_table_one/clone_cleanup): CREATE LIKE +
      INSERT SELECT to wptest_* same DB, progress bar. Verified: options/posts/postmeta exact + cleanup.
- [x] F3 sandbox (sandbox_create/sandbox_destroy): test_env_<rand>/ with own wp-config (wptest_
      prefix, sandbox URL), symlink core/plugins/themes/uploads w/ copy fallback.
- [x] F4 plugin smoke test (sandbox_plugins_list/sandbox_plugin_test): activate one plugin in
      wptest_options, GET sandbox URL, flag 500/fatal. + sandbox_report via UI table.
- [x] F5 plugin vetting (plugin_search wordpress.org API w/ requires_php compat) + PHP-compat
      report + recommendations. Verified API returns requires_php.
- [x] Unified "Test Environment" card orchestrating build(replicate+sandbox)/smoke-test/search/destroy.
- NOTE: plugin smoke test needs runnable WP (symlinks or copy + replica); on the dev server
  .htaccess/permalinks aren't processed so some verdicts are Apache-only. -> 3.0.0

## v3.1 — Health-check asset recovery link + Clean Site (DONE)
- [x] Health Check → asset recovery: when Media/Asset categories report missing files, a banner
      button jumps to the existing recovery card (prefilling DB creds). Reuses asset_recover; no dup.
- [x] Clean Site (clean_site_analyze/apply/restore): detects USED uploads (DB refs + WP thumbnails
      via <base>-WxH stripping), dry-run shows totals/reclaimable space, apply copies used into a
      fresh uploads/, renames the old to uploads_old-remove-it_* (kept, not deleted), writes a JSON
      restore log; restore undoes it. Thumbnail-keep logic + apply/restore cycle unit-tested. -> 3.1.0

## v3.2 — Uploads optimization (organize / dedupe / sanitize) (DONE)
- [x] Shared helpers: uploads_replace_ref (serialized-safe path swap across posts/postmeta/options),
      uploads_siblings (thumbnails), sanitize_name (WP-style ASCII). Verified vs real DB w/ rollback.
- [x] Organize (organize_analyze/apply): referenced top-level files → YYYY/MM/name (if YYYYMMDD_)
      else FirstLetter/name; moves thumbnails; locate-in-DB → copy → update refs → delete; batch + log.
- [x] Dedupe (dedupe_analyze/apply): size buckets then sha1; keep most-referenced/shortest, repoint
      refs of the rest, delete; reclaimable-space preview; batch + log. Size+hash grouping unit-tested.
- [x] Sanitize (sanitize_analyze/apply): accents/spaces/symbols → ASCII; locate-in-DB → update → rename; batch + log.
- [x] Unified "Optimize uploads" card: 3 sub-sections, each analyze(dry-run)+apply(progress bar), reversible JSON logs. -> 3.2.0
- All destructive ops order: locate in DB first → confirm → move/copy → update DB refs → delete original.

## v3.3 — URL Replacement direct in DB with target verification (DONE)
- [x] dburl_analyze: finds distinct absolute URLs under the source origin in posts/postmeta/options
      and computes each origin→target. dburl_apply: replaces only browser-verified pairs
      (serialized-safe exact swap, plain + JSON-escaped slashes) with a reversible .dburl-replace.json log.
- [x] UI in the URL Replacement card: "Find & verify" (lists origin URLs, HEAD-verifies each target
      in batches via verify_url with a progress bar; only reachable targets kept) + "Replace verified in DB".
- Verified vs real test db: analyze found 353 distinct igs-bonn.de URLs; exact swap updated 25 rows (rollback). -> 3.3.0

## v2.5–v2.7 follow-ups
- [x] v2.5.0: WP-CLI (wp) + curl in CLI row; How-to-use tab moved after DB Tools and rewritten
      bilingual covering the full v2 feature set.
- [x] v2.6.0: Broken Link Checker — scan_broken_collect extracts linked URLs (scope site/all,
      capped), browser verifies in batches with a stoppable progress bar, broken links (400+/no
      reply) grouped by domain, each opening in a new tab. Verified collection vs real `test` db.
- [x] v2.7.0: phpMyAdmin hardening — random folder name phpmyadmin_<rand> (detected on load to
      always show the path), pma_config_on writes an auto-EXPIRING config.inc.php (self-deletes
      server-side after 30s even if the tab closes; verified), auto-opens phpMyAdmin logged in,
      JS countdown + pma_config_off reinforcement. Config self-destruct unit-tested.

## P1 verification
- php -l OK, HTTP 200.
- Auth decision logic unit-tested, 6/6 cases: DB-empty forces must_set (never open),
  DB-set logs in, custom hash takes priority and disables DB pass.
- .migrate_config chmod 0600; hash_equals used for constant-time compare.

## Notes
- README already rewritten + pushed (commit e0b5ea7): modern, tech badges, no emojis,
  both tools documented, curl download.
- GitHub release deferred to the end (gh token was expired — 401; user to renew).
- No MySQL locally: user-management and audit cycles will be unit-tested, not end-to-end.
