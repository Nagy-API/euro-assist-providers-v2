# Verification and smoke test

Fill in the **Result** column and send the table to King. Use the staging host name. Do not use real staff on
production as the first test.

| # | Check | How | Expected | Result |
|---|---|---|---|---|
| 1 | PHP version | `php -v` (the Plesk CLI) and Plesk → PHP settings | 8.3.x or 8.4.x for both CLI and web | |
| 2 | Extensions | `php -m` | pdo_mysql, mbstring, intl, zip, fileinfo, xml, dom, xmlreader, openssl, ctype, tokenizer, curl | |
| 3 | Application boots | `php artisan about --only=environment` | Environment production, Debug Mode OFF, URL https://… | |
| 4 | Schema | `php artisan migrate:status` | all migrations "Ran"; `migrate --force` says "Nothing to migrate" | |
| 5 | Health URL | open `https://<host>/up` | HTTP 200 page | |
| 6 | HTTPS | open `http://<host>/login` | redirected to https; padlock valid | |
| 7 | App files not exposed | open `https://<host>/.env`, `https://<host>/../composer.json`, `https://<host>/storage/logs/` | 404/403, never file content | |
| 8 | Login page | `https://<host>/login` | Euro Assist branded page in Arabic/English | |
| 9 | Admin sign-in | account created with `users:provision` | dashboard shows 2,185 records, 3 featured | |
| 10 | Providers list | Providers → search a known name, filter by type and city | results appear, Arabic text correct | |
| 11 | Roles | create an Editor and a Viewer in Users; sign in as each | Viewer read-only; Editor cannot see Users/Audit | |
| 12 | Create/edit | as Admin: add a test provider, edit it, delete it, restore it | each step succeeds; Audit log shows the events. Then delete the test provider again. | |
| 13 | Export | Export → all providers (Arabic) → wait ≤ 1 minute | job "completed", the .xlsx downloads | |
| 14 | Import dry run | Import → upload the exported file in **dry-run** mode | validation completes, "unchanged" rows, nothing applied | |
| 15 | Queue/scheduler | Plesk scheduled task log | `schedule:run` runs every minute without errors | |
| 16 | API (optional) | `php artisan api:token <admin email> --issued-by=<admin email> --name=smoke --expires-days=1` then `curl -H "Authorization: Bearer <token>" https://<host>/api/v2/providers?per_page=1`; afterwards `php artisan api:token-revoke <admin email> --issued-by=<admin email> --all` | JSON with one provider; 401 without token | |
| 17 | Logs | `providers-v2/storage/logs/` | no `ERROR` lines after the test | |
| 18 | Backup | run the backup in BACKUP_AND_ROLLBACK.md and restore it to a scratch database | counts identical | |

Commands for the API check are documented in the application README (`/api/v2`). Revoke the smoke-test token
afterwards.

If any check fails: stop, do not continue to production, and send King the check number, the exact message and
the relevant lines from `storage/logs/laravel-<date>.log` (never paste `.env` content).
