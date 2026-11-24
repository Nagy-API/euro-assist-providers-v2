# Importing the business database

File: `database/EuroAssist_Providers_V2_DATA_<version>.sql` (UTF-8, utf8mb4 tables, about 4.6 MB). Its SHA-256 is also
listed in `SHA256SUMS.txt`.

## What is inside

Expected values for this release. "Live" means not deleted. A **merged** English/Arabic pair keeps ONE live provider
(with both languages); the other record of the pair is kept, hidden, as a *merge record* (`merged_into_id` is set,
`deleted_at` is set) so the merge can be undone and nothing is lost. Merge records are **not** "deleted providers".

| Content | Rows |
|---|---:|
| Provider records stored (`providers`) | 2,192 |
| of which **live** (`deleted_at IS NULL`): what the application shows and counts | **1,402** |
| of which merge records (`merged_into_id IS NOT NULL`, hidden) | 790 |
| Live providers with both languages (approved merges) / with one language | 790 / 612 |
| Provider translations (1,088 English + 1,104 Arabic; none lost) | 2,192 |
| Featured providers (all 3 with a logo) | 3 |
| Provider types (+60 type names in Arabic/English; all 30 have a default picture) | 30 |
| Providers with their own photo (the others show their type's default picture) | 5 |
| Legacy source rows kept byte-for-byte as provenance (`legacy_staging_rows`): 2,195 imported + **2 held** ("Eye Center" rows awaiting a decision) | 2,197 |
| Old-ID → new-ID mapping table (`legacy_id_maps`): 1,580 in approved pairs + 615 unpaired | 2,195 |
| English/Arabic pair records (`legacy_pair_suggestions`): 790 approved, 1 rejected, **195 still open** | 986 |
| Rows whose type label was approved by the owner (Laboratories, Radiology Centers, Specialized Centers, Physiotherapy Center) | 972 |
| Rows still on a provisional type mapping | 0 |
| Owner row decisions (`legacy_row_decisions`: 5 "Eye Center" rows mapped to Optics, 2 malformed map links repaired) | 7 |
| Migration audit events (no user, no IP) | 2,999 |
| Staff accounts: `admin@euro-assist.com` (Admin, identity from the old system) with a **temporary password** (only its bcrypt hash is stored) and `must_change_password = 1` | 1 |
| Password hashes other than that one temporary hash, sessions, API tokens, reset tokens, queue jobs | **0 (none)** |

Pictures are **not** in the SQL file: the database stores only their names. The 16 image files are in
`providers/storage/app/private/` of the package (see `INSTALL_GUIDE.md`, section 5). Without them, pictures are missing.

Source: frozen snapshot `Providers.sql`, SHA-256 `19a71fe301c1038b52473b76bec64d2a95ca606dd701c4a2054c72f5bb791814`,
plus the owner-reviewed merge decisions (only the ones that passed a second verification on V2 were applied).
Reconciliation: every one of the 2,197 source rows is accounted for; 0 differences on 13 compared fields, except the
2 map links that were repaired on purpose (the original text is kept unchanged in the provenance table).

## Safety rules

* Import **only into a new, empty database** created for V2. The file has **no `DROP TABLE` and no
  `CREATE DATABASE`/`USE`** statements: if tables already exist, the import stops with an error instead of
  overwriting anything.
* Never import into the old site's database. Never run it against production without King's written approval.
* Keep the SQL file private. Delete uploaded copies from the server after a successful import and backup.

## Import (choose one)

**Command line (recommended)** — MySQL/MariaDB client, from the extracted ZIP folder:
```bash
mysql --default-character-set=utf8mb4 -h <db host> -u <v2 db user> -p <v2 database> < database/EuroAssist_Providers_V2_DATA_<version>.sql
```
On Windows (PowerShell does not support `<`), use `cmd /c "mysql ... < file.sql"` or the `source` command inside
the client: `mysql -u <user> -p <database>` then `source C:/path/EuroAssist_Providers_V2_DATA_<version>.sql`.

**Plesk → Databases → Import dump** — select the `.sql` file and the new empty database. If the upload limit is
smaller than the file, use the command line.

**phpMyAdmin** — select the empty database → Import → file → character set `utf-8`.

## Check the import

Run in the V2 database (any SQL client). Every result must equal the number after `--`:
```sql
SELECT COUNT(*) FROM providers;                                           -- 2192 (live + merge records)
SELECT COUNT(*) FROM providers WHERE deleted_at IS NULL;                  -- 1402 (live)
SELECT COUNT(*) FROM providers WHERE merged_into_id IS NOT NULL;          -- 790  (hidden merge records)
SELECT COUNT(*) FROM providers WHERE deleted_at IS NOT NULL AND merged_into_id IS NULL;  -- 0 (really deleted)
SELECT COUNT(*) FROM provider_translations;                               -- 2192
SELECT COUNT(*) FROM featured_providers;                                  -- 3
SELECT COUNT(*) FROM provider_types;                                      -- 30
SELECT COUNT(*) FROM providers WHERE photo_path IS NOT NULL;              -- 5
SELECT COUNT(*) FROM provider_types WHERE icon_path IS NOT NULL;          -- 30
SELECT COUNT(*) FROM featured_providers WHERE logo_path IS NOT NULL;      -- 3
SELECT COUNT(*) FROM legacy_staging_rows;                                 -- 2197
SELECT COUNT(*) FROM legacy_staging_rows WHERE state = 'needs_review';    -- 2 (held "Eye Center" rows)
SELECT COUNT(*) FROM legacy_id_maps;                                      -- 2195
SELECT COUNT(*) FROM legacy_pair_suggestions WHERE status = 'approved';   -- 790
SELECT COUNT(*) FROM legacy_pair_suggestions WHERE status = 'suggested';  -- 195
SELECT COUNT(*) FROM legacy_row_decisions;                                -- 7
SELECT COUNT(*) FROM legacy_type_mappings WHERE status = 'provisional';   -- 0
SELECT COUNT(*) FROM audit_events;                                        -- 2999
SELECT email, role, is_active, must_change_password FROM users;   -- 1 row: admin@euro-assist.com, admin, 1, 1
SELECT COUNT(*) FROM migrations;                                   -- 14
SELECT name FROM provider_translations WHERE locale = 'ar' LIMIT 3;       -- Arabic must display correctly, not ????
```
Then on the server: `php artisan migrate --force` must print **Nothing to migrate**. Do **not** print or query the password hash.

If a number differs, stop and send the query and its result to King; do not continue the installation.
If the Arabic text shows as `????` or mojibake, the import used the wrong character set: drop the **V2** database
(only that one), recreate it with `utf8mb4`, and re-import with `--default-character-set=utf8mb4`.
