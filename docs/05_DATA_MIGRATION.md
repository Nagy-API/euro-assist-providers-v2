# Data model and migration — proposal requiring verification

**Reading order:** the original proposal is retained below. The 2026-11-21 schema inventory later in this document provides verified static details and supersedes the abbreviated legacy list where more precise. All V2 entities remain proposals, not an approved schema.

## Legacy schema (from uploaded SQL; schema names only)

- `providers`: id, name, type, address, city, district, discount_pct, hours, phone, email, website, map_url, logo_path, specialization, package, is_translated, created_at, updated_at.
- `arabic_providers`: same provider-detail fields without `is_translated`.
- `top_providers`: id, name_english, name_arabic, logo_path, type_en, type_ar, created_at, updated_at.
- `types`: id, type_key, name_en, name_ar, created_at, updated_at.
- `users`: id, name, email, email_verified_at, password, role, remember_token and timestamps.

## Proposed normalized V2 schema (draft)

- `providers` — canonical stable UUID/integer identifier, shared status, primary contact/business data where genuinely language-independent.
- `provider_translations` — provider_id + locale uniqueness; name/address/specialization and other genuinely localized fields. Consider localized city/district presentation separately.
- `provider_types` and `provider_type_translations` — stable machine keys, localized labels.
- `featured_providers` — canonical provider relationship, ranking, logo/assets; legacy Top Provider mapping only when canonical match verified.
- `provider_contacts` / `provider_locations` — optional if audits prove multi-contact/multi-location relationships are needed; do not prematurely normalize all legacy fields.
- `legacy_id_map` — source table, source ID, canonical provider ID, match method, reviewer, confidence/status and timestamps.
- `import_jobs`, `import_job_rows`, `export_jobs`, `audit_events`, `users`, role/permission tables.

## Migration rules

1. Import legacy SQL into a strictly separate local DB and confirm table counts; never point `DB_HOST`/credentials to production.
2. Retain original source ID and table in `legacy_id_map` even if new IDs differ.
3. Do **not** join Arabic/English records by equal numeric ID, or name alone. Evaluate multiple signals (business identifiers, name normalization, address, phone, map coordinates); ambiguous results go to manual review.
4. Preserve the original language-specific text. A blank English record does not mean translated Arabic can be silently overwritten.
5. Preserve phone and discount fields as lossless strings until business-specific data normalization is approved.
6. Use explicit dry-run diff and migration reports; store reports only as anonymized aggregates in Git.
7. Detect potential duplicate branches versus duplicated entities. Don't collapse multiple locations sharing the same brand name.
8. Test FK integrity, translation completeness, total mapped source rows, unresolved/ambiguous cases and reversibility.
9. Document how to import legacy photos/media into isolated private storage if explicitly approved; no uploads in Git.
10. Produce a repeatable migration script and versioned migration checksum to verify exact source snapshot (checksum itself okay to document if generated without exposing content).

## Acceptance report fields

Counts per legacy source table; mapped/new/duplicate candidates; unresolved and manually accepted matches; missing/invalid required fields; orphaned media; type normalization changes; package/discount handling; API field compatibility; reconciliation totals; migration script commit SHA and execution logs.

**Current counts:** not locked until independently recomputed on isolated local DB. Never represent a count as verified merely because it was mentioned in a conversation.

## 2026-11-21 — Verified schema-only inventory and migration dependencies

**Evidence boundary:** only `CREATE TABLE` and `ALTER TABLE` statements were selected from the frozen local private dump. No `INSERT` records were displayed, parsed for population counts, restored or copied into Git. Schema lines below refer to that local dump; it must not be added to the V2 repository. Source/model/migration observations are static; runtime integrity, actual values, record counts and match confidence remain unknown.

### Tables and column constraints in the dump

All ten tables use InnoDB. Unless explicitly stated otherwise, IDs below are unsigned bigint primary keys, auto-incrementing, and timestamps are nullable with null defaults. `AUTO_INCREMENT` next values are sequence state, **not row counts**, and are deliberately not used as counts here.

| Table / CREATE line | Verified columns and important constraints |
|---|---|
| `providers` / 1254 | `id`; required `name,address` varchar(255). Nullable varchar(255): `type,city,district,discount_pct,hours,phone,email,website,logo_path,specialization,package`. Nullable `map_url` text, `is_translated` tinyint(1), `created_at,updated_at` timestamps. Charset utf8mb4 / unicode_ci. |
| `arabic_providers` / 30 | `id`; required `name` varchar(255). Nullable varchar(255): `type,specialization,address,city,district,discount_pct,phone,email,website,package,logo_path`. Nullable `hours,map_url` text; `created_at,updated_at` timestamps. **No is_translated**. Charset utf8mb3 / general_ci. |
| `top_providers` / 2401 | `id`; required `name_english,name_arabic` varchar(255); nullable `logo_path,type_en,type_ar` varchar(255); timestamps. No provider ID, rank or status column. |
| `types` / 2427 | `id`; required unique `type_key` varchar(100), required `name_en,name_ar` varchar(255); timestamps. |
| `users` / 2478 | `id`; required `name,email,password` varchar(255), unique email; nullable email_verified_at timestamp, remember_token varchar(100); required role varchar(50), default admin; timestamps. Column names only: no accounts, hashes or tokens reproduced. |
| `sessions` / 2386 | Required `id` varchar(255) PK (not auto-increment), nullable unsigned bigint user_id, nullable ip_address varchar(45)/user_agent text; required payload longtext and last_activity int. No created/updated timestamps. |
| `password_reset_tokens` / 1223 | Required `email` varchar(255) PK (not auto-increment), required token varchar(255), nullable created_at timestamp. |
| `personal_access_tokens` / 1235 | `id`; required tokenable_type varchar(255), tokenable_id unsigned bigint, name varchar(255), unique token varchar(64); nullable abilities text, last_used_at/expires_at timestamps; timestamps. |
| `failed_jobs` / 1174 | `id`; required unique uuid varchar(255), connection/queue text, payload/exception longtext; failed_at required timestamp default current timestamp. |
| `migrations` / 1190 | Unsigned int id auto-increment PK (the bigint exception); required migration varchar(255), batch int. |

The eight other tables use utf8mb4 / unicode_ci. Neither provider table has `governorate`, a stable bilingual link, soft-delete column, branch relation, audit trail or contact relation. No `jobs`, `job_batches` or queue-state table is declared in the dump (failed_jobs alone is not a working queue).

### Keys and relationships

`ALTER TABLE` blocks begin at line 2504. Both provider tables index name, city, type, district, `(city,type)`, discount_pct; English additionally indexes is_translated. These are **nonunique** indexes. Other unique keys: users.email, types.type_key, failed_jobs.uuid, personal_access_tokens.token. sessions has indexes on user_id and last_activity; personal_access_tokens indexes `(tokenable_type,tokenable_id)`.

**No FOREIGN KEY / REFERENCES constraint occurs in the selected CREATE/ALTER statements.** Neither locale table references the other or `types`; Top Providers is a standalone bilingual presentation table, not a declared association. sessions.user_id is a likely logical user reference and tokenable_type/tokenable_id a polymorphic association, **inferred from names/migration conventions**, not enforced dump FKs. The four legacy models contain no belongsTo/hasMany/hasOne/belongsToMany relationship methods. Do not invent provider-to-featured/type/translation joins.

### Migration files do not reconstruct this dump faithfully

Source: all twelve files under `providers/database/migrations/`; never run them against the frozen source/database.

| Migration evidence | Drift from dump and consequence |
|---|---|
| `2025_07_02_143834_create_providers_table.php` | Creates required city and governorate, type enum (includes `lab`), decimal discount_pct and short map_url; dump has nullable city, district instead of governorate, text discount and text map URL. |
| `2025_07_03_110722_create_arabic_providers_table.php` | Creates required address/city/governorate, enum type, `discount` (not discount_pct), varchar hours/map_url; dump has nullable address/city, district, discount_pct and text hours/map_url. |
| `2025_07_24_*` add package/specialization | Present in both dump tables; no persistent bilingual relationship added. |
| `2025_07_27_*`, `2025_07_31_*` | Featured table and nullable type_en/type_ar agree in broad shape; no canonical provider link. |
| `2025_08_01_000001_update_type_columns_to_string.php` | Converts both type columns to varchar(100); dump has varchar(255). Down path restores old enums and can reject newer types. |
| `2014_10_12_000000_create_users_table.php` | No role column, despite required users.role in dump. |
| Complete migration set | No types-table creation, district addition, Arabic discount rename or complete drift reconciliation found. sessions migration uses nullable indexed foreignId without constrained(), matching absence of an enforced FK. |

Treat the dump schema as frozen physical evidence and migrations as historical intent. Bootstrap V2 with separately designed/reviewed migrations; never copy/run the legacy migration set as an authoritative reconstruction. API search references governorate although the dump does not contain it; this is a contract defect to reproduce on synthetic schema, not evidence that a new business field is required.

### Source behavior that affects lossless migration

- `ProviderController::storeArabic` creates two independent records in sequence; English placeholder has `is_translated=0` and Arabic text, but omits the package saved on Arabic row. Excel Arabic helper can optionally create a second English record (including package); English creation does not create Arabic counterpart. No transaction or persisted pair ID in either path. Equal IDs and translation flag are insufficient matching evidence.
- Web updates/deletes affect one locale table only. Featured names/type/logo are independently maintained. Type labels come from hardcoded controller/view/API arrays rather than an Eloquent `types` relation; the table's existence does not prove those lists agree.
- Provider models expose `logo_path` through an accessor: trim, preserve HTTP(S), strip leading slash, or return type/default relative image. This **changes presentation** and may hide a null raw value. TopProvider stores filename and generates `asset('uploads/logos/' + filename)`. Migration must preserve raw storage separately from any resolved URL; do not migrate accessor fallback as proof a real logo existed.
- Coordinates are not DB columns. Both provider models parse only map URLs containing `?q=`/`&q=lat,lng` or `@lat,lng,`; otherwise `[null,null]`. No URL dereference/geocoding/short-link resolution or bounds validation is present. Keep original map_url; parsed null does not mean location is absent.
- `discount_pct` is text in both dump tables. Laravel spreadsheet path preserves trimmed text; standalone importer extracts a numeric fragment and casts to float. Never apply that lossy numeric behavior to historic discounts without owner decision.
- Phone, hours, package and addresses remain scalar text. Package comma splitting is display/import convention, not proof that commas safely delimit business entities. utf8mb3 vs utf8mb4, Arabic nullable address, longer schema widths than web validators, and mixed timestamp nullability need explicit staging validation; reject/record rather than silently truncate or normalize.
- Default legacy exports do not include is_translated or timestamps and can expose accessor-resolved logos; imports have positional/Logo Path mismatch and no reliable round trip. Exported XLSX is **not** an authoritative replacement for raw source fields. Exact mappings: [legacy audit](03_LEGACY_AUDIT.md#exact-legacy-spreadsheet-contracts).

### Deferred decisions and acceptance evidence

No new tables, keys, pairing rules or V2 normalization decisions were accepted in this pass. Keep ADR-005 proposed. Before data implementation depends on it, decide with the owner: entity vs branch identity; bilingual matching evidence/review policy; treatment of untranslated English placeholders; whether Featured entries map to canonical providers; approved type vocabulary; nullable address/blank update semantics; package/discount meaning; retention and authorized media handling; identity strategy and reversible conflict resolution.

Next evidence must come from an approved isolated local restore/rehearsal: per-table exact counts, missing-field distributions, duplicate/branch candidates, ambiguous matches, raw-to-normalized type mapping, charset checks, source-to-target ID reconciliation, preserved timestamps/fields and rollback proof. Store aggregate sanitized reports only. Historical approximate counts in project context remain **unverified** and must not be used as migration acceptance totals.

### Read-only restore preflight (2026-11-21; no restore performed in this pass)

A quote/comment-aware lexical scan masked quoted values, identified statement boundaries and reported command categories only: **55 top-level statements** comprising 10 CREATE TABLE, 18 ALTER TABLE, 23 INSERT INTO, 2 SET, 1 START TRANSACTION and 1 COMMIT, plus **7 executable versioned SET comments**. INSERT payloads were not displayed or semantically audited. No unrecognized top-level statement was found. No database CREATE/USE/DROP, DEFINER, trigger/routine/event, user/privilege command, OUTFILE/DUMPFILE/LOAD DATA/LOAD_FILE, or shell/client command directive was detected outside quoted data/comments. This is static preflight, not a guarantee that a restore succeeds.

Plain SET directives select NO_AUTO_VALUE_ON_ZERO and UTC time_zone; versioned comments save/restore charset state and set NAMES utf8mb4. A separate restore operator should precreate a dedicated disposable local database, explicitly select its name and loopback host/port, disable client local-infile, and use a database-scoped account without FILE, GRANT or user-management privileges. Do not reuse a legacy environment file or credentials. DDL implicitly commits, so START TRANSACTION/COMMIT does **not** guarantee full-restore rollback; a failed rehearsal should recreate only the verified disposable target. All aggregate reconciliation still needs fresh evidence from that separate task.

## 2026-11-21 — Separate isolated restore and aggregate verification

This later task supersedes the earlier **not restored / counts unverified** statements for the specific frozen input below. It does not turn static legacy behavior into executed application evidence or approve any V2 identity mapping.

- **Frozen input SHA-256:** `19a71fe301c1038b52473b76bec64d2a95ca606dd701c4a2054c72f5bb791814`; the source remains outside Git.
- **Target:** portable MariaDB `11.4.14-MariaDB`, `127.0.0.1:33316`, dedicated database `euro_assist_legacy_audit_20261121`. No Windows service, production connection or legacy `.env` was used. Server/client local-infile was disabled; server bind address was read back as loopback.
- **Privileges:** a separate database-scoped importer had only SELECT, INSERT, CREATE, ALTER, INDEX and REFERENCES; no FILE, DROP, global, user-management or GRANT privileges. Root credentials and client defaults files stayed outside Git and were not printed.
- **Preconditions:** fresh source checksum, quote/comment-aware preflight (55 recognized statements plus 7 versioned SET directives; zero unexpected/dangerous directives), explicit selected database and zero existing target tables. The exact inspected file was then passed to the noninteractive MariaDB client with `--binary-mode --skip-reconnect --local-infile=0`.
- **Result:** restore exit 0; aggregate SQL queries exit 0. No raw record, password/hash, provider contact value or session payload was returned to the report or committed. The restored database is private local audit material, not a fixture or an application database.

### Verified snapshot counts

| Table | Rows |
|---|---:|
| providers | 1,090 |
| arabic_providers | 1,104 |
| top_providers | 3 |
| types | 30 |
| users | 1 |
| sessions | 0 |
| password_reset_tokens | 0 |
| personal_access_tokens | 0 |
| failed_jobs | 0 |
| migrations | 15 |

The combined **2,194 locale rows are not 2,194 proven unique providers**. Information-schema queries confirmed ten tables and zero FK constraints in this isolated restore.

### Sanitized data-quality aggregates

"Blank" means SQL NULL or an empty string after `TRIM`; it does not classify placeholder text, validate phone numbers or normalize values. Repeated groups and cross-locale equality below were independently re-queried using `BINARY` comparisons/grouping so collation does not silently broaden the equality test.

| Measure | English | Arabic |
|---|---:|---:|
| Blank name | 0 | 0 |
| Blank address | 0 | 0 |
| Blank phone | 0 | 23 |
| Blank map_url | 3 | 25 |
| Distinct nonblank types (database collation) | 11 | 30 |
| Byte-equal repeated name groups | 41 | 35 |
| Byte-equal repeated nonblank phone groups | 33 | 28 |
| Largest byte-equal nonblank phone group, rows | 409 | 406 |

There are 1,055 cross-locale pairs sharing a numeric ID, **259,947** nonblank byte-equal phone candidate pairs, and **1,173** nonblank byte-equal map-URL candidate pairs. These are join pair counts, potentially many-to-many, **not matched entities**. Even byte-equal phone/map values cannot establish unique bilingual identities. No merge, normalization, deletion or pairing was applied. Entity/branch meaning, placeholders, untranslated English records, shared contact patterns and uncertain pairs require review under ADR-005.

### Repeatable aggregate query definitions

Use an external private client defaults file pointing explicitly to the isolated host/port/database and a read-only account for future checks. Never paste real connection secrets into shell history or Git. For example, the following queries expose only aggregates:

```sql
SELECT COUNT(*) FROM providers;
SELECT COUNT(*) FROM arabic_providers;
SELECT COUNT(*) FROM top_providers;
SELECT COUNT(*) FROM information_schema.table_constraints
WHERE constraint_schema = DATABASE() AND constraint_type = 'FOREIGN KEY';
SELECT COUNT(*) FROM (
    SELECT BINARY name FROM providers
    WHERE name IS NOT NULL AND TRIM(name) <> ''
    GROUP BY BINARY name HAVING COUNT(*) > 1
) AS repeated_names;
SELECT COUNT(*) FROM providers AS e
JOIN arabic_providers AS a ON BINARY a.phone = BINARY e.phone
WHERE e.phone IS NOT NULL AND TRIM(e.phone) <> '';
```

Count the other tables with the same fixed-table `COUNT(*)` pattern. Preserve the source checksum and exact SQL predicate alongside any new aggregate result. Do not infer a value-level match from these totals. A successful V2 migration still requires deterministic staging, explicit source-ID mappings, reviewed exceptions, full row reconciliation and rollback tests; none of that has been implemented in this restore task.
