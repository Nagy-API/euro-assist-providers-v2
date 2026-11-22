# Euro Assist Providers V2 — IT delivery: start here

This archive replaces the old "website folder + `Providers.sql`" handoff with the same practical shape:

```
EuroAssist_Providers_V2_<version>/
├── 00_READ_ME_FIRST.md            ← this file
├── INSTALL_GUIDE.md               ← step-by-step installation (staging first, then production)
├── DATABASE_IMPORT.md             ← how to import the SQL file safely
├── VERIFY_AND_SMOKE_TEST.md       ← how to prove the installation works
├── BACKUP_AND_ROLLBACK.md         ← backup, restore and return to the old site
├── FRESH_DATA_SYNC.md             ← REQUIRED before production: refresh the data from the live old system
├── KNOWN_LIMITATIONS_AND_OPEN_DECISIONS.md
├── env.production.example         ← configuration template (no secrets inside)
├── providers/                     ← the complete Laravel application (vendor/ and built assets included)
│   └── public/                    ← THE ONLY FOLDER THE WEB SERVER MAY SERVE (document root)
├── database/
│   └── EuroAssist_Providers_V2_DATA_<version>.sql   ← authentic migrated business data
├── MANIFEST.json                  ← every file with size and SHA-256
└── SHA256SUMS.txt                 ← checksum list for verification
```

## The one rule that differs from the old site

The old archive had the public files at the top level next to the application. **Do not copy that layout.**
Point the domain's **document root to `providers/public`**. Everything else in `providers/` (code, `.env`,
`storage/`, `vendor/`) must stay **outside** the web root and never be downloadable.

## What the package does and does not contain

| Included | Not included (by design) |
|---|---|
| Application code, production `vendor/`, built CSS/JS, Cairo font, official logos | `.env` or any real password, key or token |
| `public/.htaccess` (Apache / Plesk Linux) and `public/web.config` (IIS / Plesk Windows) | User accounts, password hashes, sessions, API tokens |
| SQL file with 2,185 migrated providers, 3 featured providers, 30 types and full migration provenance | Uploaded logos/files from the old site (decision D8 pending) |
| Installation, verification, backup and rollback guides | Node.js (not needed on the server) |

Administrator accounts are created on the server after the import (`php artisan users:provision`).

## Ten steps (details in INSTALL_GUIDE.md)

1. Verify the ZIP checksum against the value King sent you separately; then `SHA256SUMS.txt`.
2. Confirm the host meets the requirements (PHP 8.3+, extensions, MySQL/MariaDB, scheduled task).
3. Create a **staging** subdomain, a **new empty** database and a dedicated database user.
4. Upload `providers/` outside the web root; set the document root to `providers/public`.
5. Create `providers/.env` from `env.production.example`; run `php artisan key:generate --force`.
6. Import the SQL into the empty database (DATABASE_IMPORT.md); run `php artisan migrate --force` (expects "Nothing to migrate").
7. Create the first Admin: `php artisan users:provision <email> --role=admin` (hidden password prompt).
8. Cache configuration, add the every-minute scheduled task, check HTTPS.
9. Run VERIFY_AND_SMOKE_TEST.md and give King the results.
10. Production only after King's written approval **and** a fresh data sync (FRESH_DATA_SYNC.md). The old site stays untouched and is the rollback.

## Status of this release

* Built and verified **locally** (Windows, PHP 8.3/8.4, MariaDB 11.4): extraction, checksum, SQL import, migrations,
  account provisioning, login, roles, Excel import/export worker, API and backup/restore. See `RELEASE_VALIDATION.md`
  delivered next to this ZIP.
* **Not yet verified on the company Plesk server.** Staging on the real host is the next required step.
* The data is the frozen snapshot of the old system; it does not contain changes made in the old system afterwards.
