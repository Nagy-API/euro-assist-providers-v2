# START HERE — install Euro Assist Providers V2 (rc.6 FINAL) on Plesk, step by step

This is a **complete fresh installation** of the approved rc.6 application with its data. It replaces the current installation by installing
**next to it** (new folder + new empty database), testing, and then switching the site over. The old installation and its database stay untouched
until King confirms, so they are your rollback.

King confirmed that the current production users were only test accounts and that the business data is unchanged. The package therefore ships the
complete SQL for a **new, empty** database. **Never import it into an existing or the old V1 database.**

`php` below means the **PHP 8.3 or 8.4 CLI** that Plesk shows for the subscription (on Windows typically
`"C:\Program Files (x86)\Plesk\Additional\PleskPHP83\php.exe"`). Not `php-cgi.exe`, not PHP 8.2 or older.

## A. Before you change anything

1. **Verify the ZIP:** its SHA-256 must equal the value King sent you separately. After extracting, `SHA256SUMS.txt` must match (`sha256sum -c SHA256SUMS.txt`, or `Get-FileHash`).
2. **Back up the existing installation** (files and database). Plesk → Backup Manager, or: export the current database (Plesk → Databases → Export Dump) and copy the
   whole application folder, including `storage/app/private`, to a dated folder **outside** the web root. Check that the dump file is not empty. Details: `BACKUP_AND_ROLLBACK.md`.
3. **Verify hosting integrity** (read-only; it changes nothing and prints no secret):
   ```
   php tools/preflight.php "<folder of the CURRENT installation that contains artisan>"
   ```
   It reports PHP version/extensions, `.env` safety settings, the database name and whether it is the V2 database, role values, queue state, and which code files of the
   current installation differ from the delivered rc.5. Also search **all** web folders of the subscription for planted code:
   ```
   findstr /S /I /M /C:"str_rot13" /C:"merdo" /C:"RaBot" "<subscription folder>\*.php"      (Windows)
   grep -rIl --include=*.php -e str_rot13 -e merdo -e RaBot "<subscription folder>"            (Linux)
   ```
   (A match is not always malicious, but each hit must be explained.) **Send the preflight output and any hits to King. Stop if it reports an unexplained difference or a hit.**
4. Requirements: PHP extensions `pdo_mysql, mbstring, intl, zip, fileinfo, xml, openssl, ctype, tokenizer, curl, gd` (gd with JPEG, PNG **and WebP**), MySQL 8 / MariaDB 10.6+,
   HTTPS certificate, IIS URL Rewrite (Windows) — see `INSTALL_GUIDE.md` §2.

## B. Install the new version (new folder, new database)

1. **New empty database** + **new database user** with rights on that database only (Plesk → Databases → Add database). Never reuse the old database or user.
2. Create a new folder outside the web root, e.g. `providers-v2-rc6`, and upload the **contents** of this ZIP's `providers/` folder into it
   (`providers-v2-rc6/artisan` and `providers-v2-rc6/public/index.php` must exist). Keep `storage/app/private` (the pictures are inside).
3. Give the PHP/application-pool user (and the scheduled-task user) **write access** to `storage` and `bootstrap/cache`.
4. Copy `production.env.example` to `providers-v2-rc6/.env` and fill in the `<...>` values: `APP_URL` (the final https address), `DB_*`, `TRUSTED_PROXIES`.
   Keep `APP_ENV=production`, `APP_DEBUG=false`. Then:
   ```
   cd providers-v2-rc6
   php artisan key:generate --force
   ```
5. **Import the data** into the NEW empty database (phpMyAdmin → Import, or `mysql ... < database/EuroAssist_Providers_V2_DATA_2.0.0-rc.6_FINAL.sql`). Compare the counts in `DATABASE_IMPORT.md`.
   ```
   php artisan migrate --force        # expected: "Nothing to migrate."
   ```
   Do **not** run `db:seed`, `migrate:fresh` or any import into another database.
6. Point a **test host name** (e.g. `providers-v2-test.<domain>`) at `providers-v2-rc6/public` (document root = the `public` folder), HTTPS on, with Plesk's HTTP→HTTPS 301 redirect. `APP_URL` must be that name for now.
7. **First sign-in, immediately:** open `https://<test host>/login`, sign in as `admin@euro-assist.com` with the **temporary password King sends separately**. The page "Replace your temporary password"
   opens and allows nothing else until you choose a new one (12+ characters, letters and numbers, typed twice). Do this before anyone else can reach the site.
8. Caches:
   ```
   php artisan optimize:clear
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan event:cache
   ```
   Repeat these five commands after every `.env` change.

## C. Scheduler (this is what processes Excel import/export — without it exports stay "Queued" forever)

Plesk → **Scheduled Tasks → Add task → Run a command**:

| Setting | Value |
|---|---|
| Command | `"<full path>\php.exe" "<full path>\providers-v2-rc6\artisan" schedule:run` |
| Schedule | every minute (`* * * * *`) |
| Run as | the **subscription system user** (the same user PHP runs the site as) |
| PHP | the **CLI** `php.exe` of 8.3 or 8.4 (not `php-cgi.exe`) |
| Working directory | the application folder (where `artisan` is) |
| Notification | send output on error to IT |

Wait two minutes, then run `php artisan ops:check`: *scheduler heartbeat* must say "last run … s ago", *pending jobs 0*, *exports queued > 5 min 0*. If the heartbeat is "NEVER seen",
the task is missing, uses the wrong PHP/user, or points at another copy of the application. A job that is "Queued" is **not** a finished export.

## D. Verify (details: `VERIFY_AND_SMOKE_TEST.md`)

1. `php artisan ops:check` → *No failing check* (a warning for route cache before step B8 is normal). It also proves the files equal the release.
2. Public API, no login (replace the host): `https://<host>/api/arabic-providers?fields=type` (30 types), `/api/providers?fields=type` (8 types), `/api/arabic-providers?fields=city`,
   `/api/top-providers` (3 featured with `logo_url`; open each URL: image 200). Provider e-mail addresses are **not** published: the `email` field is always `null` (leave `LEGACY_API_EXPOSE_EMAIL` unset).
3. Private areas: `https://<host>/api/v2/providers` → 401; `/dashboard` → redirects to `/login`; `/migration/review` → not available; no error page shows a stack trace.
4. Sign in as the administrator: Dashboard shows **1,402** providers; Users → *Add user* (name, e-mail, role, password twice — no e-mail is ever sent); *Manage → Reset password*;
   account menu → *Change password*; the login page has no "Forgot password". Export a few providers → **Completed** within about a minute → the file downloads.
5. Pictures: provider list shows pictures; Featured providers shows 3 logos.

## E. Switch over and roll back

1. When King approves, change the **production host name** to the new folder: Plesk → Hosting settings → document root = `providers-v2-rc6/public`; set `APP_URL` in `.env` to the production https address,
   then `php artisan optimize:clear` and the cache commands (B8). Move the scheduled task to the new folder if it still points to the old one.
2. Re-run D2–D4 on the production host name.
3. **Rollback:** set the document root back to the old folder and the scheduled task back to the old `artisan` (the old files and database were never modified); `php artisan optimize:clear` in the old folder.
   Delete nothing for at least two weeks.

## F. Do not

* Do not import this SQL into a non-empty or old database, do not set `CACHE_STORE=file` or `QUEUE_CONNECTION=sync`, do not leave `APP_DEBUG=true`.
* Do not create other administrators until the temporary password has been replaced; do not send `.env`, passwords or the temporary password by e-mail or in tickets.
* Do not serve the `providers-v2-rc6` folder itself, only its `public` subfolder.
