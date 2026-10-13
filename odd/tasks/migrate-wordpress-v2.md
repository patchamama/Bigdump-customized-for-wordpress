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
- [~] P2: Full ES/EN i18n. Infra done (t(), $I18N, ?lang=/cookie/Accept-Language, EN/ES
      selector); auth screen + header converted. Strategy: each new phase ships bilingual;
      a final i18n pass converts remaining old sections (status, tabs, health check). -> 1.5.0
- [x] P3: WP user management. WpPasswordHash class (phpass $P$, WP-compatible, verified
      against a real dump hash); endpoints wp_users_list / wp_user_password / wp_user_update /
      wp_user_create; bilingual "WordPress Users" card + JS. Capabilities serialize matches real
      dump format exactly. Bumped to 1.5.0 (absorbs the i18n infra bump).
- [ ] P4: Security Audit (DB + files: weak passwords, admin default, WP version,
      default SALTs, file perms, XML-RPC, REST users, debug, prefix). -> 1.7.0
- [ ] P5: phpMyAdmin installer (download+extract to /phpmyadmin) + WP extras. -> 1.8.0
- [ ] P6: README migrate_wordpress docs + curl one-liners (done early at user request). 
- [ ] P7: Final version 2.0.0, tag v2.0.0-wp, GitHub release (push authorized).

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
