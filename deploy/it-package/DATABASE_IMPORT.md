# Importing the business database

File: `database/EuroAssist_Providers_V2_DATA_<version>.sql` (UTF-8, utf8mb4 tables, ~3.8 MB).

## What is inside

| Content | Rows |
|---|---:|
| Providers (one record per branch **and** language until English/Arabic pairs are reviewed) | 2,185 |
| Provider translations | 2,185 |
| Featured providers | 3 |
| Provider types (+60 type names in Arabic/English) | 30 |
| Legacy source rows kept byte-for-byte as provenance (`legacy_staging_rows`) | 2,197 |
| Old-ID → new-ID mapping table (`legacy_id_maps`) | 2,188 |
| Open English/Arabic pair suggestions (nothing merged automatically) | 334 |
| Source rows **held** for a business decision (7 "Eye Center", 2 malformed map links) | 9 |
| Rows imported on a **provisional** type mapping (owner decision D3) | 972 |
| Migration audit events (no user, no IP) | 2,187 |
| Users, password hashes, sessions, API tokens, reset tokens, queue jobs | **0 (none)** |

Source: frozen snapshot `Providers.sql`, SHA-256 `19a71fe301c1038b52473b76bec64d2a95ca606dd701c4a2054c72f5bb791814`.
Reconciliation: every one of the 2,197 source rows is accounted for; 0 byte differences on 13 compared fields.

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

Run in the V2 database (any SQL client):
```sql
SELECT COUNT(*) FROM providers;                 -- 2185
SELECT COUNT(*) FROM provider_translations;     -- 2185
SELECT COUNT(*) FROM featured_providers;        -- 3
SELECT COUNT(*) FROM provider_types;            -- 30
SELECT COUNT(*) FROM legacy_staging_rows;       -- 2197
SELECT COUNT(*) FROM legacy_pair_suggestions WHERE status = 'suggested';  -- 334
SELECT COUNT(*) FROM users;                     -- 0 (before provisioning the first Admin)
SELECT name FROM provider_translations WHERE locale = 'ar' LIMIT 3;     -- Arabic must display correctly, not ????
```
Then on the server: `php artisan migrate --force` must print **Nothing to migrate**.

If the Arabic text shows as `????` or mojibake, the import used the wrong character set: drop the **V2** database
(only that one), recreate it with `utf8mb4`, and re-import with `--default-character-set=utf8mb4`.
