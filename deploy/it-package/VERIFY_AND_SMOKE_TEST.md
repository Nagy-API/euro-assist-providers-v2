# Verification and smoke test

Fill in the **Result** column and send the table to King. Use the staging host name. Do not use real staff on
production as the first test.

| # | Check | How | Expected | Result |
|---|---|---|---|---|
| 1 | PHP version | `php -v` (the Plesk CLI) and Plesk → PHP settings | 8.3.x or 8.4.x for both CLI and web | |
| 2 | Extensions | `php -m` | pdo_mysql, mbstring, intl, zip, fileinfo, xml, dom, xmlreader, openssl, ctype, tokenizer, curl, **gd** (`php -r "var_dump(gd_info());"` → JPEG, PNG and WebP support = true) | |
| 3 | Application boots | `php artisan about --only=environment` | Environment production, Debug Mode OFF, URL https://… | |
| 4 | Schema | `php artisan migrate:status` | all migrations "Ran"; `migrate --force` says "Nothing to migrate" | |
| 5 | Health URL | open `https://<host>/up` | HTTP 200 page | |
| 6 | HTTPS | open `http://<host>/login` | redirected (301) to https by the Plesk redirect setting; padlock valid | |
| 7 | App files not exposed | open `https://<host>/.env`, `https://<host>/../composer.json`, `https://<host>/storage/logs/` | 404/403, never file content | |
| 8 | Login page | `https://<host>/login` | Euro Assist branded page in Arabic/English | |
| 9 | Admin sign-in and forced password change | sign in as `admin@euro-assist.com` with the temporary password King sent; try to open `/dashboard` and `/providers` before changing it; then replace the password on the page that opened | before the change every other page redirects to "Replace your temporary password"; a wrong temporary password is refused; after the change the dashboard shows **1,402 total records, 1,402 active, 0 inactive, 0 deleted**; Featured providers lists 3; Users shows the account without the "Temporary password" badge; the temporary password no longer signs in | |
| 10 | Providers list | Providers → search a known name, filter by type and city | results appear, Arabic text correct; every row shows a small picture (own photo or the default picture of its type); none shows a broken-image icon | |
| 11 | Roles | create an Editor and a Viewer in Users; sign in as each | Viewer read-only; Editor cannot see Users/Audit | |
| 12 | Create/edit | as Admin: add a test provider, edit it, delete it, restore it | each step succeeds; Audit log shows the events. Then delete the test provider again. | |
| 13 | Export | Export → all providers (Arabic) → wait ≤ 1 minute | job "completed", the .xlsx downloads | |
| 14 | Import dry run | Import → upload the exported file in **dry-run** mode | validation completes with **1,104 rows, 0 errors, 1,104 unchanged, 0 to create, 0 to update**; nothing applied | |
| 15 | Queue/scheduler | Plesk scheduled task log | `schedule:run` runs every minute without errors | |
| 16 | API (optional) | `php artisan api:token <admin email> --issued-by=<admin email> --name=smoke --expires-days=1` then `curl -H "Authorization: Bearer <token>" https://<host>/api/v2/providers?per_page=1`; afterwards `php artisan api:token-revoke <admin email> --issued-by=<admin email> --all` | JSON with one provider; 401 without token | |
| 17 | Logs | `providers-v2/storage/logs/` | no `ERROR` lines after the test | |
| 18 | Backup | run the backup in BACKUP_AND_ROLLBACK.md (database **and** `storage/app/private`) and restore it to a scratch database | counts identical | |
| 19 | Imported counts | run the queries in DATABASE_IMPORT.md ("Check the import") if not already done | every number equals the expected value | |
| 20 | Pictures | Providers → default order: the provider records 1081, 1082, 2152, 2184 and 2185 have their own photo (open `https://<host>/providers/1081`); Featured providers | the photo shows on the provider page and in the list; the 3 featured logos show; opening `https://<host>/featured/1/logo` while signed out redirects to the login page | |
| 21 | Merged provider | open any provider with the Arabic **and** English badges both ticked (about 790 of them) | the page shows both language panels, one record, one phone, one map link | |
| 22 | Migration status strip | Admin → Dashboard, below the figures | one compact line: Unpaired **612**, Pairs to review **195**, Held rows **2**, Provisional types **0**; no large yellow banner | |
| 23 | Photo upload (optional) | as Editor/Admin: provider → "Choose a picture" → save → remove | the picture appears and is removed again; a Viewer sees no upload button | |

Commands for the API check are documented in `providers/README.md` (`/api/v2`). Revoke the smoke-test token
afterwards.

If any check fails: stop, do not continue to production, and send King the check number, the exact message and
the relevant lines from `storage/logs/laravel-<date>.log` (never paste `.env` content).
