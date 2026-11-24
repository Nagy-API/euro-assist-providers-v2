# Euro Assist Providers V2 — incremental update (rc.5 → rc.6)

**This is an UPDATE, not an installation.** It contains application files only. It contains **no database dump**.
Do **not** import any SQL, do **not** create a new database, do **not** reset any password, do **not** touch `.env`
secrets. Every existing provider, user, password hash, photo, audit entry and import/export record stays as it is.

Nothing in this package has been deployed by the author. Apply it only after King approves it.

## What changes

| Area | Change |
|---|---|
| Public website API | `https://providers.euro-assist.com/api/arabic-providers`, `/api/providers` (+ `?fields=city`, `?fields=type`, search, details, names, coordinates, statistics) and `/api/top-providers` answer again, read-only, from the current V2 database. Needed by `medicalcard.euro-assist.com/providers.php`. |
| Exports "Queued" | Scheduler lock now expires after 5 minutes (it used to live 24 hours), queue `retry_after` raised above the longest job, scheduler heartbeat, clear warning on the Export page, and a new self-check `php artisan ops:check`. |
| Accounts | Administrators create users with a password (entered twice) and reset passwords; users change their own password. No e-mail verification, invitation or "Forgot password". Roles stay exactly: Administrator, Editor, Viewer. A stored legacy `super_admin` is converted to `admin` (same rights). |
| Look | Euro Assist icon in the browser tab on every page; "Migration review" moved from the menu to *Advanced tools*. |
| Database | **One data-only migration**: `2026_11_25_000100_normalize_legacy_super_admin_role` changes `users.role` from `super_admin` to `admin` (if any) and writes one audit row. No table or column is created or dropped. |

## 0. Preflight - prove what is running BEFORE touching anything (read-only)

Production is known to differ from the delivered rc.5 (extra API routes, `roles.super_admin`, the migration page visible, an empty login
response). The command `php artisan ops:check` does **not exist yet** on the running site; it arrives with this update. So use the standalone,
read-only script `preflight.php` from this package. It needs no Laravel, writes nothing, runs no migration and prints no secret
(no keys, passwords, tokens, hosts or user names).

```text
"<full path>\php.exe" preflight.php "<application folder that contains artisan>"
```

Use the **same PHP 8.3/8.4 CLI that Plesk uses for the site** (not `php-cgi.exe`). It reports: PHP version/extensions, `APP_ENV`/`APP_DEBUG`/`APP_URL`,
a `DB_URL` override, cache files, folder write access, the **database name** and whether it is the V2 database, required tables, user role values,
provider counts, pending/stuck jobs and exports, and **which code files differ from the delivered rc.5** (modified / missing / extra, by name).

Send the whole output to King before going on. **Stop (do not apply the update) if** it says: *OLD V1 database*, *required tables MISSING*, or it cannot
connect. A stale configuration cache can point the site at a different database than `.env`: run `php artisan config:clear` first if the output looks wrong
(that only deletes a cache file) and run the preflight again. Files listed as **modified/extra** are production-only changes made outside rc.5:
the update overwrites the files it contains (all are backed up by `apply-update.ps1`); any leftover unknown file is inert. Send the list so King can decide
whether any of it must be kept.

## 1. Backup (mandatory)

1. Plesk → Databases → the **V2** database → *Export Dump* (or Backup Manager). Keep it outside the web root.
2. Copy the whole application folder and `storage/app/private` (photos, logos, icons, exports) to a dated backup folder.
3. Note the current `.env` file by copying it to the backup folder (do not e-mail it).

## 2. Apply the files

1. Put the site in maintenance mode: `<php> artisan down --retry=60`
2. Unzip this package outside the web root. Run (PowerShell, as the subscription user):

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\apply-update.ps1 -AppPath "D:\path\to\providers-v2" -WhatIf   # preview, changes nothing
   powershell -ExecutionPolicy Bypass -File .\apply-update.ps1 -AppPath "D:\path\to\providers-v2"           # apply
   ```

   The script refuses a folder without `artisan`, copies a backup of every file it will overwrite or delete to `..\update-backup-<timestamp>`,
   copies the new files, deletes the files listed in `removed-files.txt`, replaces `public\build` and **never** touches `.env`, `storage`, `vendor` or the database.
   Doing it by hand is equivalent: copy `files\*` over the application folder and delete the paths in `removed-files.txt`.
3. Confirm the files match the release: `<php> artisan ops:check` (available now) → section CODE must say *files identical to the release*.

## 3. Environment (check, do not rewrite secrets)

In the site's `.env` these must hold (add only what is missing):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://providers.euro-assist.com
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
LEGACY_API_ENABLED=true
```

`APP_DEBUG=true` or `APP_ENV` other than `production` is the likely reason the detailed error page and the migration-review route appeared:
the migration page is only registered when the environment is **not** production. Fix the `.env`, then rebuild the cache (step 5).

## 4. Database migration (data-only)

```text
<php> artisan migrate:status
<php> artisan migrate --force
```

Only `2026_11_25_000100_normalize_legacy_super_admin_role` should run. Run it **only on the confirmed V2 database** from step 0.
Check afterwards: `SELECT role, COUNT(*) FROM users GROUP BY role;` → only `admin`, `editor`, `viewer`.

## 5. Refresh caches, restart workers

```text
<php> artisan optimize:clear
<php> artisan config:cache
<php> artisan route:cache
<php> artisan view:cache
<php> artisan up
```

Restart anything long-running that caches configuration (IIS application pool for the site; any `queue:work` service).

## 6. Queue and scheduler — exact setup (this is why exports stayed "Queued")

An export is **not finished** while it says *Queued*. A queued job is only a row in the `jobs` table; a worker process must pick it up.
The application does this through its scheduler, so **one Plesk scheduled task must run every minute**:

| Plesk → Scheduled Tasks → Add Task | Value |
|---|---|
| Task type | Run a command |
| Command | `"<full path>\php.exe" "<app path>\artisan" schedule:run` |
| Run | Cron style `* * * * *` (every minute) |
| Run as | the **subscription system user** (the same user PHP runs the website as) |
| PHP | the **CLI** `php.exe` of PHP **8.3 or 8.4** (not `php-cgi.exe`, not PHP 8.1/8.2) |
| Working directory | the application folder (where `artisan` is) |
| Notification | send output on error to IT |

Server-side causes (configuration, not application defects): task missing, wrong PHP version or `php-cgi.exe`, wrong user (cannot write `storage`),
task not every minute, task pointing at another copy of the app or another database, the `cache` table missing (wrong database), pool/user without
write access to `storage/logs` and `storage/framework`. Application-side defects fixed in this update: overlap lock that survived a killed worker for 24 hours,
`retry_after` shorter than the longest job.

Verify: wait two minutes, then `<php> artisan ops:check` → QUEUE: *scheduler heartbeat last run … s ago*, *pending jobs 0*, *exports queued > 5 min 0*.
Then sign in as an Administrator or Editor → Export → request an export of a few providers: status must become **Completed** within about a minute and the
file must download and open in Excel. If it stays Queued, `ops:check` tells which part is missing. Manual drain for diagnosis only:
`<php> artisan queue:work database --stop-when-empty --max-time=50` (it prints each job it processes).

## 7. Verification after the update

1. `<php> artisan ops:check` → *No failing check*. (A WARN for "route cache" before step 5 is normal.)
2. Public API (from any computer; no login needed):
   * `https://providers.euro-assist.com/api/arabic-providers?fields=city` → JSON list of cities
   * `https://providers.euro-assist.com/api/arabic-providers?fields=type` → JSON list of types
   * `https://providers.euro-assist.com/api/providers?fields=type` → English types
   * `https://providers.euro-assist.com/api/top-providers` → `{"success":true,...}`
   * `https://medicalcard.euro-assist.com/providers.php` → both dropdowns filled; choosing a type and a governorate shows results; branch details open.
3. Privacy: `https://providers.euro-assist.com/api/v2/providers` without a token must answer **401**; `/dashboard` must redirect to `/login`; `/migration/review` must **not** open; no stack trace on any error page.
4. Login as an Administrator: Users → *Add user* (with password + confirmation), *Manage → Reset password*, menu → *Change password*; no "Forgot password" on the login page; menu shows *Advanced tools* (Migration review is there only where enabled).
5. Existing data: Dashboard provider count equals the count before the update; an existing user can still sign in with their **existing** password.

## 8. Rollback

The update changes files and (at most) renames the role value `super_admin` → `admin`, which carries identical rights.

1. `<php> artisan down`
2. Restore the files from the `update-backup-<timestamp>` folder created by `apply-update.ps1` (copy back over the application folder; recreate the deleted files from the same folder).
3. `<php> artisan optimize:clear` then `config:cache`, `route:cache`, `<php> artisan up`.
4. The database does **not** need restoring. (Only if King asks to undo the role rename: `UPDATE users SET role='super_admin' WHERE id IN (<ids from audit_events WHERE action='user.role_normalized'>)`; the rc.5 code treats an unknown role as having no permissions, so do not do this unless rc.5 files are also restored and an `admin` exists.)
5. Full restore from the step 1 dump is the last resort and loses everything entered after the backup.

## 9. What not to do

* No SQL import, no new database, no `migrate:fresh`, no `db:seed`.
* No new Admin user and no password reset for existing users unless King asks.
* Do not set `CACHE_STORE=file` or `QUEUE_CONNECTION=sync` to "make errors go away".
* Do not leave `APP_DEBUG=true`.
* Do not send `.env`, tokens or passwords by e-mail or in tickets.
