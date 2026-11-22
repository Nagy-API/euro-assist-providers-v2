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


## 2026-11-21 — P1.4 / P2.1 decision proposal (AWAITING OWNER APPROVAL; nothing below is implemented)

Tags: **[FACT]** verified legacy evidence; **[REC]** architectural recommendation (not a business rule); **[DECIDE]** owner decision required. Already-approved decisions (ADR-008 blank preservation and whole-file validation; ADR-009 one canonical provider per branch/service location, no auto-merge; ADR-010 roles) are not reopened. No schema in this section is approved or implemented; P2.2 (#18) must not start until the [DECIDE] items for P2.1 (#17) are answered.

**Evidence basis:** static source audit (docs 03/06) plus read-only SELECT aggregates run on 2026-11-21 against the isolated restore of the frozen snapshot (source SHA-256 `19a71fe3…1814`, MariaDB `127.0.0.1:33316`, private audit DB). Only counts/shapes of vocabulary are recorded; no provider names, phones, addresses or other values. Aggregates below were produced by one session and have not been independently re-run. Legacy production was not touched.

### 1. Arabic/English field ownership

- **[FACT]** Two independent tables (`providers` EN 1,090 rows, `arabic_providers` AR 1,104 rows) with the same business columns; no FK, no pair ID, no relationships in models. The AR table has 49 IDs absent from EN and EN has 35 IDs absent from AR; 1,055 IDs exist in both. In same-ID pairs the name is never byte-equal (expected for two languages), the phone is byte-equal in only 240 pairs and the map URL in 0 pairs, so same-ID is not evidence of the same provider and contact values are not consistently shared across locales.
- **[FACT]** Free text everywhere: discount is prose (e.g. a "N% to N% discount" shape in 694 EN and ~690 AR rows, plus "N% off local products and N% off imported" variants), hours/package/specialization are prose, city has 32 (EN) and 26 (AR) distinct raw values, district 304 (EN) and 419 (AR). `governorate` does not exist in the dump.
- **[REC]** Canonical `providers` owns language-independent data: type (FK), phone(s), email, website, map URL (original text kept, parsed coordinates nullable), logo path (raw), status, timestamps. `provider_translations` (unique `provider_id`+`locale`) owns language-dependent text: name, address, city, district, specialization, hours, discount text, package text. Store discount, phone, hours and package as lossless strings; no numeric parsing, no new governorate/city lookup tables until approved.
- **[DECIDE] D1:** Approve this split? In particular, should phone/email/website/map/logo be canonical (one value per branch) or per-locale? If a reviewed pair has different phones in EN and AR, which wins (REC: keep both raw source values in the legacy snapshot columns, canonical takes the value the reviewer selects)?
- **[DECIDE] D2:** Keep city/district as free text per locale for launch (REC), or define a controlled list now?

### 2. Provider types and category normalization

- **[FACT]** `types` has 30 rows (`type_key`, `name_en`, `name_ar`). The AR table uses exactly 30 distinct raw values and every one equals a `types.name_ar` value (0 mismatches); 22 of the 30 types are used by exactly one AR row, the largest are laboratory (926), pharmacy (48), hospital (35), specialized center (19), clinic (17), physical therapy (15), radiology (13), optics (9). The EN table uses only 12 raw values: three labels carry a trailing space byte (956 rows), three are key-style lowercase (15 rows), one is an Arabic string, and the rest are display labels (e.g. plural "Laboratories", "Radiology Centers") that differ from `types.name_en` ("Laboratory", "Radiology Center"). All 3 featured rows use a `types.type_key` value. Controllers/views use hardcoded 30-key arrays, not the `types` table, and the dashboard distribution applies a separate alias map.
- **[REC]** `provider_types` with the `types.type_key` machine keys and `provider_type_translations` (en/ar). Migrate through a reviewed, versioned mapping table (raw legacy value -> `type_key`); any raw value without a mapping goes to the review queue, never a guess. Single-valued type per provider. Keep dashboard aliasing separate from the type vocabulary.
- **[DECIDE] D3:** Approve the 30 existing keys as the launch vocabulary (including the 22 single-use types) and the mapping of the 12 EN raw values to them (e.g. "Laboratories" -> `laboratory`)? Who may add or edit types (REC: Admin only)?

### 3. Nullable and required fields

- **[FACT]** Blank counts (NULL or empty after TRIM) EN/AR of 1,090/1,104: name 0/0, address 0/0, type 0/0, phone 0/23, city 1/1, district 3/1, discount 2/24, map URL 3/25, package 389/406, logo 1,080/1,077 blank, specialization 1,089/1,098 blank, website 1,089/1,102 blank, **email 1,090/1,104 and hours 1,090/1,104 blank (never populated in the snapshot)**. `created_at`/`updated_at` are NULL in 1,080/1,080 EN and 1,087/1,008 AR rows; max lengths are within the 255 columns (EN name 74, address 190; AR name 68, address 174). 1 EN row is a translation placeholder (`is_translated=0`).
- **[REC]** Required: at least one translation per provider; per translation `name` and `address` (data has 0 blanks; ADR-008 requires create-mode validation); `type` required. Everything else nullable. Do not require legacy timestamps; add a migration batch/imported-at stamp instead. `is_translated` is not carried as business state; keep it only in the legacy snapshot columns.
- **[DECIDE] D4:** Confirm required = {type, and name+address per translation}? May a provider exist with only one language (REC: yes)? Are `email` and `hours` retained as fields although currently unused (REC: yes, nullable, since the import template defines them)?

### 4. Featured-provider relationships

- **[FACT]** `top_providers` has 3 rows: standalone bilingual name, type_en/type_ar and logo; no provider ID, rank, status or date. Each featured name byte-matches many provider rows: 292 EN/291 AR, 409 EN/406 AR, 81 EN/81 AR. These are brand names that appear on hundreds of branch rows, not single providers. Featured entries are maintained independently and hard-deleted with the logo file; admin routes had no authentication in source.
- **[REC]** Keep featured entries as their own bilingual records (name, type, logo) with no foreign key to a provider, as in legacy. Optionally add a separate, owner-approved link table to specific locations later. Add no rank, status or scheduling until the owner defines them (legacy has none).
- **[DECIDE] D5:** Are featured entries brand-level display cards (REC) or must each point to specific provider locations? Do you want ordering/active-inactive for them now (new rule), or later? (Management is Admin-only per ADR-010.)

### 5. Legacy ID mapping and uncertain matches

- **[FACT]** 2,194 locale rows are not 2,194 providers. Candidate join pairs: 1,055 by ID, 259,947 by phone, 1,173 by map URL (many-to-many). Within one locale, 20 EN (41 rows) and 8 AR (16 rows) (name,address) groups repeat; 34 EN (name,phone) groups cover 926 rows, so a shared phone usually means a brand-wide call number, not identity. 194 EN and 189 AR phone values contain a separator (`,` `;` `/` or newline).
- **[REC]** `legacy_id_map`: `source_table`, `source_id`, `locale`, nullable `provider_id`, `status` (`unpaired`, `suggested`, `approved`, `rejected`), `match_method`, `reviewer`, `reviewed_at`, `snapshot_sha256`, unique (`source_table`,`source_id`). First load creates one canonical provider per source locale row with status `unpaired`; the pairing queue only suggests pairs (signals: same ID, normalized name/transliteration, address, phone, map URL) and a human approves each. Approved merges are reversible (record both prior provider IDs). Identical numeric ID, name or phone alone never merges.
- **[DECIDE] D6:** Approve "everything unpaired until reviewed"? Who reviews and what is the acceptable volume (up to ~1,055 ID-sharing suggestions)? Do you want a private sample review of ID-sharing pairs first to measure how reliable the ID signal is (REC) before deciding whether bulk-suggestion by ID is acceptable (still requiring approval)?

### 6. Existing API consumers and compatibility

- **[FACT]** Source only; no HTTP call or consumer registry. Public unauthenticated reads for providers/search/names/lat-lng/distribution/top-providers; write routes are declared without handlers; JWT login/logout has several static inconsistencies (docs 06/L-11); responses expose raw model attributes; search references the nonexistent `governorate` column; `per_page` has no maximum; two response shapes for the list endpoint.
- **[REC]** New versioned `/api/v2` with an explicit field allowlist, bounded pagination and tests. A legacy-shaped read adapter only for consumers the owner confirms, behind contract tests. Do not reproduce unauthenticated writes, the governorate defect, unbounded results or the JWT mix. No parity claim without tests.
- **[DECIDE] D7:** Who or what calls the V1 API today (website pages, mobile app, partners), and which endpoints must keep working unchanged? Should read endpoints stay public? Is the `governorate` filter a wanted feature? Is the dashboard "max(EN,AR) per type" distribution meant to continue, or should it count canonical providers?

### 7. Migration integrity and rollback

- **[FACT]** No FKs or transactions in legacy; migration files do not reproduce the dump; the frozen dump checksum and aggregate counts are recorded (above and earlier sections); logos exist for 10 EN/27 AR providers and 3 featured rows but copying media needs a handling decision.
- **[REC]** Deterministic, idempotent loader: raw staging tables (source table/ID, row hash, snapshot SHA-256) -> reviewed transform -> canonical tables, every created row tagged with a `migration_batch_id`. Reconciliation must place every source row in exactly one state (mapped, new, needs review, rejected-with-reason) and totals must equal 1,090 + 1,104 + 3. Rollback = delete by batch ID plus reversible down-migrations; rehearse on synthetic data in CI and on the private local restore only; aggregate-only reports in Git. Production is never connected; cutover needs separate written approval.
- **[DECIDE] D8:** Approve the reconciliation rule (no row dropped silently) and batch-delete rollback? Approve or defer copying legacy logo files into private local storage (REC: defer; use synthetic logos until legal/handling clearance)?

### Decisions needed before P2.1 (#17) can be approved

Required: **D1, D3, D4, D5, D6.** Needed before P2.2 data-loading and API tasks but not for the table design: D2, D7, D8. Each has a recommended default above; reply "approve REC" per item or give the alternative. Remaining unverified: actual API consumers, runtime behavior of the legacy app, true bilingual pairing quality.

## 2026-11-22 — Full local migration rehearsal of the frozen snapshot (LOCAL ONLY)

Aggregates only; no record values. Production was never connected. Report: `docs/legacy-reports/reconciliation-19a71fe3-v1-rehearsal.md`.

### Safeguards verified before any read (ADR-014)

- Frozen file SHA-256 recomputed: `19a71fe301c1038b52473b76bec64d2a95ca606dd701c4a2054c72f5bb791814` (matches the recorded value) before and after.
- Source read through a NEW account `legacy_reader@127.0.0.1` with `GRANT SELECT` on `euro_assist_legacy_audit_20261121` only. The pre-existing `legacy_import` account (SELECT, INSERT, CREATE, REFERENCES, INDEX, ALTER) is **refused** by the live grant check (`legacy:preflight` exit 1, verified).
- `legacy:preflight` (read-only) verified: environment `local`; source resolves to `127.0.0.1`, live port 33316, live database `euro_assist_legacy_audit_20261121`; destination `euro_assist_v2_local` (allow-listed). Pointing the destination at the audit database is refused (verified). A wrong checksum is refused (verified).
- Source fingerprint (row counts + `CHECKSUM TABLE … EXTENDED` of all 9 tables) recorded before and after all runs: **identical**.

### Commands run (destination `euro_assist_v2_local`, freshly migrated; backup of the previous dev DB kept outside Git)

`legacy:preflight` → `legacy:stage --dry-run` → `legacy:stage` → `legacy:map-types --from-legacy` → `legacy:apply --dry-run` → `legacy:apply --actor=<local admin> --accept-provisional-types` → (after URL-rule fix) re-apply → `legacy:suggest-pairs` → `legacy:reconcile --expect-baseline --report`.

### Results

| Source table | Source rows | Staged | Mapped | Needs review | Rejected |
|---|---:|---:|---:|---:|---:|
| providers (EN) | 1,090 | 1,090 | 1,082 | 8 | 0 |
| arabic_providers (AR) | 1,104 | 1,104 | 1,103 | 1 | 0 |
| top_providers (featured) | 3 | 3 | 3 | 0 | 0 |
| **total** | **2,197** | **2,197** | **2,188** | **9** | **0** |

- Created: 2,185 canonical providers (one per source locale row, each with exactly one translation in its source locale) and 3 brand-level featured entries; 2,188 identity maps, all `unpaired`. 30/30 V2 types available; the 30-row legacy `types` table produced no new mapping (all its labels already present).
- Held for review (visible on the Admin review screen and the dashboard): 7 rows with the ambiguous label "Eye Center" (`type_needs_review`), 2 rows whose map link contains inner whitespace (`validation_failed:map_url`). Their original values are preserved unchanged in staging.
- 972 mapped rows use a **provisional** type mapping (deterministic plural/synonym labels: "Laboratories", "Radiology Centers", "Specialized Centers", "Physiotherapy Center"), tagged `provisional_type`. Owner approval of D3 is still required; if rejected, roll the batch back and re-apply without `--accept-provisional-types`.
- Pair suggestions: 334 created for human review (signals: map URL 333, phone 299, coordinates 263, same id 1); **0 approved automatically**. All 2,185 migrated providers remain separate until an Admin approves a pair with a written reason.
- Integrity checks (all 0): duplicate source mappings, providers shared by two maps, providers without map, providers with ≠1 translation, locale mismatches, orphan type/translation FKs, mapped rows without map, held rows with a map, automatic approvals, soft-deleted providers.
- **Field fidelity:** byte-exact comparison (`BINARY`) of 13 fields (name, address, city, district, specialization, hours, discount, package, phone, email, website, map_url, logo_path) for all 2,185 providers and both featured names: **0 differences** after the documented normalization (leading/trailing whitespace trimmed, blank → NULL). Phones keep leading zeros and spacing; Arabic text intact.
- **Provenance:** every column of every source row (including `created_at`, `updated_at`, `is_translated`, raw `type`, `logo_path`) is stored byte-identical in `legacy_staging_rows.payload` (verified per column, 0 differences).
- **Idempotency:** re-running map-types/apply/suggest-pairs/reconcile changed nothing (same counts, no new revisions or timestamps). Re-staging an applied snapshot is refused until the batch is rolled back.
- **Rollback rehearsal** on the separate disposable database `euro_assist_v2_rehearsal`: full pipeline → `legacy:rollback` removed exactly 2,185 providers, 2,185 translations, 2,188 maps, 334 suggestions, 3 featured; batch `rolled_back`; staging provenance kept; re-staging and re-applying afterwards reproduced the same totals.

### Known limitations (not lossy, but not modelled in the canonical tables)

- Legacy `created_at`/`updated_at` and `is_translated` live only in staging provenance; canonical timestamps are the V2 creation time.
- Logos are not copied (D8); the raw logo reference is kept on the provider (`logo_path`) and in staging.
- No English/Arabic pair is merged until reviewed; the dashboard therefore shows 0 "Arabic and English" providers today.

### Provisional decisions D1–D8 after the rehearsal (no owner approval is claimed)

| Decision | Technical default in use | Affects migrated data? | Reversible? | Lossy risk | Owner action needed |
|---|---|---|---|---|---|
| D1 field ownership | language-independent fields on provider, text per translation | yes (all rows) | yes (new migrations) | none found (0 diffs) | approve or change split |
| D2 city/district | free localized text | yes | yes | none | approve |
| D3 type vocabulary | 30 keys; plural/synonym labels PROVISIONAL; "Eye Center" unmapped | yes (972 provisional, 7 held) | yes (rollback + re-apply) | none (raw type kept) | approve the 4 provisional labels; decide "Eye Center" |
| D4 required fields | type + name + address per translation | yes (2 held rows are URL-format, not required-field) | yes | none | approve |
| D5 featured model | brand-level, no provider FK | yes (3 rows) | yes | none | approve |
| D6 pairing | unpaired + suggestions + reasoned Admin review | yes (334 pending) | yes (undo) | none | review the 334 suggestions |
| D7 API exposure | `/api/v2`, authenticated, public read OFF | no data change | yes (config) | n/a | name the real consumers |
| D8 media/rollback | logos not copied; rollback by batch | logos only | yes | none (references kept) | decide media migration |
