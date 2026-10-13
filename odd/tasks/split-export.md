# Feature: Size-limited split export (.zip parts)

## Objective
Let the user pick a max size (1M..10M) for DB export so a large generated SQL is
split into numbered .zip parts, each compressed under the chosen limit, each
importable standalone and in consecutive order. Enables importing into hosts that
reject large uploads.

## Problem / Why
Current export_db produces one .sql.gz. Hosts with small upload limits can't take
it. User needs parts guaranteed under a size cap, cut only at full SQL statement
boundaries so no part has a truncated INSERT/CREATE that would break import.

## Decisions (user-confirmed)
- Size guarantee: measure COMPRESSED size iteratively. Accumulate full statements;
  after each, measure the real .zip size; close the part before the next statement
  would exceed the limit. Guarantees each .zip < limit.
- Output: numbered .zip parts (part_001.zip ...). Importer must extract each .zip.
- Integrity: each part starts with SET FOREIGN_KEY_CHECKS=0 / SET NAMES header; cut
  only at full-statement boundary; last part restores FK=1. Importable alone and in order.

## Scope
- Allowed edit surface: `migrate_wordpress.php` only.
- Extend export_db: accept `split` + `max_part_size`; when set, produce parts.
- New PHP: statement-boundary splitter + per-part .zip + measure loop.
- New PHP action `list_export_parts` / reuse list_exports; download already works.
- Import of parts: extend import_db to accept a .zip (extract then run) and allow a
  server-side ordered list of part files.
- UI: size selector in Export card + parts result list; Import handles .zip parts.
- Bump TOOL_VERSION 1.1.0 -> 1.2.0.

## Tasks
- [x] T1: PHP helper split_sql_to_zip_parts — statement-bounded, measures REAL .zip size per part.
- [x] T2: export_db — split + max_part_size params; returns numbered parts list.
- [x] T3: import_db — accepts .zip (extracts .sql then imports); server_file allows .zip.
- [x] T4: UI — split checkbox + 1..10 MB <select> in Export card; parts result box.
- [x] T5: JS — exportDB sends split params; renders numbered parts table with download links.
- [x] T6: Bump TOOL_VERSION to 1.2.0.

## Verification
- php -l: No syntax errors. HTTP 200.
- Splitter unit-tested with low-compressibility data: every part < limit (REAL zip size,
  not gzencode estimate), 16KB→7 parts, 32KB→4 parts, all INSERTs intact.
- Integration test: 2 parts under 20KB, each has FK=0 header + FK=1 footer, no cut
  statements, 800/800 INSERTs reassembled. Each part is standalone-importable.

## Key learning / gotcha
- gzencode(level 6) UNDERESTIMATES ZipArchive output size → parts overshot the limit by
  ~86 bytes. Fixed by measuring the real .zip on disk per candidate (iterative), as the
  user specified. Do not estimate compressed size; measure the actual artifact.

## Acceptance
- With split + 2M, a >2M dump yields multiple .zip parts, each < 2M compressed.
- No part cuts a statement mid-way (each part unserialize/parse-safe, importable).
- Parts import in order into a clean DB with correct row counts.
- php -l passes; HTTP 200.

## Route
Delegated direct — single writer, one file.
