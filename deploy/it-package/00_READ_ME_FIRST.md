# Euro Assist Providers V2 (rc.6 FINAL) — IT delivery: start here

**Open `START_HERE_PLESK_INSTALL.md` first: it is the short, complete installation procedure** (backup, hosting integrity check, new database, scheduler, verification, switch-over and rollback). The other documents are the detailed references.

This archive replaces the old "website folder + `Providers.sql`" handoff with the same practical shape:

```
EuroAssist_Providers_V2_<version>/
├── 00_READ_ME_FIRST.md            ← this file
├── START_HERE_PLESK_INSTALL.md    ← THE short installation procedure (read this first)
├── tools/preflight.php            ← read-only check of the CURRENT installation (run before replacing it)
├── INSTALL_GUIDE.md               ← step-by-step installation (detailed reference)
├── DATABASE_IMPORT.md             ← how to import the SQL file safely
├── VERIFY_AND_SMOKE_TEST.md       ← how to prove the installation works
├── BACKUP_AND_ROLLBACK.md         ← backup, restore and return to the old site
├── KNOWN_LIMITATIONS_AND_OPEN_DECISIONS.md
├── production.env.example         ← configuration template (no secrets inside)
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
| `public/.htaccess` (Apache / Plesk Linux) and `public/web.config` (IIS / Plesk Windows) | Any password, key or token other than the ONE temporary bcrypt hash of the Admin account described below; sessions, API tokens |
| SQL file with **1,402 provider records** (790 of them are English+Arabic pairs merged after verification, 612 single-language), 3 featured providers, 30 provider types and full migration provenance | Files of the old `uploads` folder that no migrated record refers to |
| **16 migrated pictures** (5 provider photos, 3 featured logos, 8 default type pictures) under `providers/storage/app/private/` | Node.js (not needed on the server) |
| Installation, verification, backup and rollback guides | |

The legacy administrator `admin@euro-assist.com` (Admin role) is included **ready to sign in with a TEMPORARY password**. Only its bcrypt hash is in the SQL; King sends you the temporary password **separately** (never in the ZIP, never in the same message). The first sign-in forces the holder to replace it: until then nothing else in the application can be used (ADR-019).

## Ten steps (details in INSTALL_GUIDE.md)

1. Verify the ZIP checksum against the value King sent you separately; then `SHA256SUMS.txt`.
2. Confirm the host meets the requirements (PHP 8.3+, extensions including `gd` with WebP, MySQL/MariaDB, scheduled task).
3. Create a **test** host name, a **new empty** database and a dedicated database user.
4. Upload the contents of `providers/` to a folder outside the web root (e.g. `providers-v2`); set the document root to
   its `public` subfolder (`providers-v2/public`); enable Plesk's HTTP to HTTPS 301 redirect.
5. Create `providers/.env` from `production.env.example`; run `php artisan key:generate --force`.
6. Import the SQL into the empty database and compare the counts (DATABASE_IMPORT.md); run `php artisan migrate --force` (expects "Nothing to migrate").
7. Sign in as `admin@euro-assist.com` with the temporary password King sent you, **immediately after the import** and over HTTPS; the page asks for a new password (12+ characters, letters and numbers). Do this before anyone else can reach the site.
8. Cache configuration, add the every-minute scheduled task, check HTTPS.
9. Run VERIFY_AND_SMOKE_TEST.md and give King the results.
10. Switch the production host name to the new folder only after King's approval (King confirmed the business data is unchanged, so no data refresh is needed). The old installation and database stay untouched and are the rollback.

## Status of this release

* Built and verified **locally** (Windows, PHP 8.3/8.4, MariaDB 11.4): extraction, checksums, SQL import, migrations,
  account activation, login, roles, pictures, Excel import/export worker, API and backup/restore.
  The same code also passes the automated checks on GitHub Actions (PHP 8.3 and 8.4, MariaDB, frontend build).
* **Not yet verified on the company Plesk server.** The first installation on the real host is the final test; follow the verification table and report the results to King.
* References such as "ADR-014" point to the engineering team's decision records (`providers/docs/10_DECISIONS.md`).
* The data is the migrated business data approved by King (frozen legacy snapshot plus the owner-reviewed merges and migrated pictures); King confirmed it is unchanged.
* Open review items (104 English/Arabic pairs not merged, 195 open pair suggestions, 2 held "Eye Center" rows) are listed in
  `KNOWN_LIMITATIONS_AND_OPEN_DECISIONS.md`; none of them blocks the installation.
