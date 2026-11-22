# Backup, restore and rollback

## What must be backed up (V2)

1. The V2 database (all tables).
2. `providers-v2/storage/app/private/` (featured logos, private import/export files).
3. `providers-v2/.env` (contains `APP_KEY` and passwords) — store it in the company's password vault, not in a
   shared folder. Losing `APP_KEY` invalidates encrypted sessions only; it does not lose business data.

## Backup (daily, and always before an upgrade or data change)

```bash
mysqldump --single-transaction --routines --default-character-set=utf8mb4 -h <db host> -u <v2 user> -p <v2 database> > v2-backup-YYYYMMDD-HHMM.sql
```
Plesk → Backup Manager can schedule the database and files backup instead; include `storage/app/private`.
Keep at least one copy off the server. Record the SHA-256 of each backup file.

## Prove the backup works (do this on staging)

1. Create an **empty scratch database** (never the live V2 database).
2. Import the backup into it (`mysql ... scratch_db < v2-backup-….sql`).
3. Compare: `SELECT COUNT(*) FROM providers; SELECT COUNT(*) FROM provider_translations; SELECT COUNT(*) FROM users;`
   must equal the live database at backup time.
4. Drop the scratch database.

## Restore V2 after a bad change

1. Put the site in maintenance: `php artisan down`.
2. Restore the last verified backup into a **new** database; point `DB_DATABASE` in `.env` to it;
   `php artisan config:cache`.
3. `php artisan up`; run checks 8–14 of VERIFY_AND_SMOKE_TEST.md.
Keep the damaged database for investigation until King agrees to delete it.

## Roll back to the OLD site (after a production switch)

The cutover never modifies the old application folder or its database, so rollback is a routing change:

1. Plesk → set the production host name's document root back to the **old** site's folder
   (exactly the value recorded before cutover), or restore the previous DNS record.
2. Confirm `https://providers.euro-assist.com/dashboard` shows the old login page and works.
3. Put V2 in maintenance (`php artisan down`) so nobody keeps writing to it.
4. Data entered in V2 after cutover is **not** in the old database. Export it from V2 (Export → all) before
   switching back, so it can be re-entered or re-imported later.

Record before cutover (needed for rollback): old document root path, old DNS values, old PHP version, the
verified backup file names and SHA-256 values, and the time V2 went live.

## Upgrading V2 later

New release folder next to the current one → copy `.env` → `php artisan down` on the current one → back up →
switch the document root to the new folder's `public` → `php artisan migrate --force` → caches → `php artisan up`.
Rollback = point the document root back and restore the pre-upgrade backup if a migration ran.
