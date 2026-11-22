# Installation guide — Euro Assist Providers V2

Audience: the hosting/IT team. Install on an **isolated staging** subdomain first. Production installation and
the switch of the real domain require King's **written** approval. The old site and its database are never
modified by any step below.

Commands are shown for both platforms. In Plesk, use the **PHP 8.3 (or 8.4) CLI** that Plesk shows for the
subscription — never an older system PHP. Below, `php` means that executable (on Windows Plesk typically
`"C:\Program Files (x86)\Plesk\Additional\PleskPHP83\php.exe"`; confirm the real path in Plesk).

---

## 1. Facts verified about the old installation (from the delivered archive, read-only)

* Hosting: **Plesk on Windows / IIS** (`web.config` files, `C:\Inetpub\vhosts\…` paths, IIS custom error pages,
  `.user.ini` for PHP settings). Domain: `providers.euro-assist.com`.
* Old app: Laravel 12 in a folder named `providers/` with its own `public/` folder; the archive's top level was a
  copy of that `public/` folder, plus `Providers.sql`.
* Its `public/index.php` loads `../vendor` and `../bootstrap`, i.e. the document root was the application's
  `public/` folder.

Not visible in the archive and therefore **to be confirmed on the server**: exact PHP version and extensions,
MySQL vs MariaDB version, whether IIS URL Rewrite is installed, the PHP CLI path, and the scheduled-task options.

## 2. Requirements

| Item | Required | Notes |
|---|---|---|
| PHP | **8.3 or 8.4** (CLI and web handler) | Tested 8.3.37 and 8.4.28. PHP 8.2 (old site) is **not** enough. |
| PHP extensions | `pdo_mysql`, `mbstring`, `intl`, `zip`, `fileinfo`, `xml`/`dom`/`xmlreader`, `openssl`, `ctype`, `tokenizer`, `curl` | `php -m` lists them. |
| PHP settings | `memory_limit` ≥ 256M, `upload_max_filesize` ≥ 12M, `post_max_size` ≥ 12M | Import files are limited to 10 MB. |
| Database | MySQL 8.0+ or MariaDB 10.6+, utf8mb4 | Tested on MariaDB 11.4.14. MySQL 8 must be confirmed on staging. |
| Web server | IIS with **URL Rewrite** module, or Apache/nginx (Plesk Linux) | `public/web.config` / `public/.htaccess` are included. |
| Scheduled task | one task **every minute** | Runs the queue for Excel import/export. |
| HTTPS | valid certificate on the host name | The app refuses to start in production without `https://` in `APP_URL`. |
| Composer / Node.js | **not required** | `vendor/` and `public/build/` are included. |

## 3. Verify the package

```powershell
# Windows (PowerShell)
Get-FileHash .\EuroAssist_Providers_V2_<version>.zip -Algorithm SHA256
```
```bash
# Linux
sha256sum EuroAssist_Providers_V2_<version>.zip
```
The value must equal the one King sent you **separately** (message, not inside the ZIP). After extracting:
```bash
cd EuroAssist_Providers_V2_<version>
sha256sum -c SHA256SUMS.txt          # Linux / Git Bash; every line must say OK
```
On Windows without `sha256sum`, use `Get-FileHash` on a few files and compare with `SHA256SUMS.txt`, or run the
check from Git Bash/WSL.

## 4. Create the staging site and database

1. Plesk → create a subdomain, e.g. `providers-v2-staging.<company domain>`; issue the HTTPS certificate and turn on
   **Hosting settings → "Permanent SEO-safe 301 redirect from HTTP to HTTPS"**. This is required: session cookies are
   HTTPS-only, so sign-in over plain `http://` fails with "Page expired" (419).
2. Plesk → Databases → **add a new, empty database** (e.g. `euro_assist_providers_v2`) with a **new user** that has
   rights on that database only. Never reuse the old site's database or user.

## 5. Upload the application

* Create a folder `providers-v2` in the subscription **outside** the public web folder, for example
  `…\vhosts\<domain>\providers-v2\` (Windows) or `/var/www/vhosts/<domain>/providers-v2/` (Linux), and upload the
  **contents** of the ZIP's `providers/` folder into it, so that `providers-v2\artisan` and
  `providers-v2\public\index.php` exist. The ZIP's `providers/public` is then `providers-v2/public`.
* Plesk → Hosting settings → **Document root = `providers-v2/public`**.
* Make these two folders writable by the web server's PHP identity (and by the scheduled-task user):
  * Windows/IIS: give the application-pool identity (or the subscription's system user / `IIS_IUSRS`, as configured
    in Plesk) **Modify** rights on `providers-v2\storage` and `providers-v2\bootstrap\cache`.
  * Linux: owner = subscription system user; `chmod -R ug+rwX storage bootstrap/cache`.
* Do **not** create the `public/storage` link (`storage:link`); all files are private by design.
* IIS: keep `public/web.config`, which uses the URL Rewrite module. If IIS reports a duplicate rule, a parent
  configuration already defines one — remove the parent rule, not ours. Apache/nginx: keep `public/.htaccess`.

## 6. Configure

```bash
cd <path>/providers-v2
# copy production.env.example from the extracted ZIP to <path>/providers-v2/.env, then
# edit .env: APP_URL (https://…), DB_* values, TRUSTED_PROXIES, MAIL_* when SMTP is available
php artisan key:generate --force          # once; keep a private copy of APP_KEY with the server records
```

## 7. Import the data and finish the schema

Follow `DATABASE_IMPORT.md` to load `database/EuroAssist_Providers_V2_DATA_<version>.sql` into the **empty**
database. Then:

```bash
php artisan migrate --force               # expected: "Nothing to migrate."
```
Do **not** run `db:seed` on an imported database: the 30 provider types are already in the SQL file.

## 8. Create the first administrator

```bash
php artisan users:provision it.admin@<company domain> --name="IT Administrator" --role=admin
```
In production the command first asks *"This is PRODUCTION. Create the account?"* — answer `yes`. The password is then
asked **hidden** (min. 12 characters, letters and numbers). Then sign in and create the other staff
accounts from **Users** in the application (Admin / Editor / Viewer). There are no default accounts in the package.

## 9. Production caches, queue and scheduler

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```
After every change to `.env`, run these five commands again.

Plesk → **Scheduled Tasks** → add (every minute, as the subscription user):
```
<php CLI path> <path>/providers-v2/artisan schedule:run
```
This drains the database queue (Excel import/export) with an overlap lock and prunes expired private files daily.
If the host offers a supervised long-running process, `php artisan queue:work database --max-time=3600 --tries=3`
is preferred; keep the scheduled task in either case (it also runs the daily clean-up).

## 10. Verify

Run every check in `VERIFY_AND_SMOKE_TEST.md` and send King the filled-in result table.

## Production (only after written approval)

1. Fresh data: follow `FRESH_DATA_SYNC.md` (the SQL in this ZIP is a frozen snapshot).
2. Back up the old site's files and database (`BACKUP_AND_ROLLBACK.md`), verify the backup.
3. Install exactly as above on the production host name; import the **fresh** SQL.
4. Smoke test, then switch DNS / the document root of the production host name.
5. Keep the old site and database intact and stopped from writing; it is the rollback path.

Named roles to agree **before** cutover: who approves (King), who executes (IT), who decides rollback (King).
