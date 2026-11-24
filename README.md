# Euro Assist Providers System V2

> **Status (2026-11-22): release candidate `2.0.0-rc.1`, verified locally. NOT deployed. Production is untouched.**
> Owner: King · Repository: [Nagy-API/euro-assist-providers-v2](https://github.com/Nagy-API/euro-assist-providers-v2) (private) ·
> Live status and release gates: [`docs/01_CURRENT_STATUS.md`](docs/01_CURRENT_STATUS.md).

The bilingual (Arabic/English) administration system for Euro Assist's medical-provider network. It replaces the legacy
Laravel 12 site at `providers.euro-assist.com` with a secure, tested application: provider directory, types, featured
providers, safe Excel import/export, a versioned read-only API, roles, audit trail and a lossless legacy migration.

## Read this first

1. [`AGENTS.md`](AGENTS.md) — rules for every contributor / AI session.
2. [`docs/01_CURRENT_STATUS.md`](docs/01_CURRENT_STATUS.md) — the only live status snapshot and release gates.
3. [`docs/12_HANDOFF.md`](docs/12_HANDOFF.md) — next actions with commands.
4. [`docs/18_RELEASE_PACKAGE.md`](docs/18_RELEASE_PACKAGE.md) — how the IT delivery ZIP is built and verified.
5. [`deploy/it-package/`](deploy/it-package/) — the installation guides shipped to IT inside the ZIP.

## Business scope

| Module | What it does | Who |
|---|---|---|
| Dashboard | Totals, active/inactive, per-type distribution, translation coverage, data completeness, and an explanation of what the record count means while English/Arabic pairing is open | all roles |
| Providers | Search (name, address, city, district, specialization, phone, e-mail), filters, sorting, pagination, details, create/edit with revision-conflict protection, soft delete + restore, bulk update | Viewer read · Editor create/edit/bulk update · Admin all |
| Provider types | 30 bilingual types | Admin |
| Featured providers | Brand-level featured entries with logo | view all · manage Admin |
| Excel import | Template, whole-file validation before anything is written, preview, explicit confirmation, background apply, retry, error report; blank cells never erase data (ADR-008) | Editor, Admin |
| Excel export | All / filtered / selected, Arabic or English, background job, private download | Editor, Admin |
| Job history | Own jobs (Editor), all jobs (Admin) | Editor, Admin |
| Users & roles | Admin / Editor / Viewer (ADR-010 + Amendment A), activation, password reset | Admin |
| Audit log | Every sensitive action | Admin |
| System status | Runtime/queue health | Admin |
| API `/api/v2` | Read-only, token-based, rate-limited ([`docs/06_API_CONTRACT.md`](docs/06_API_CONTRACT.md), [`docs/openapi/`](docs/openapi/)) | token holders |
| Legacy migration | Local-only pipeline + pair review queue (ADR-014) | engineering, Admin |

Authorization is enforced server-side on every route, Livewire action and job (policies/gates); hidden buttons are
cosmetic only.

## Architecture and stack

Laravel 13 · PHP ≥ 8.3 (tested 8.3.37 / 8.4.28) · Livewire 4 (bundled Alpine.js) · Tailwind CSS 4 + Vite ·
MySQL/MariaDB (tested MariaDB 11.4.14) · database queue + scheduler · OpenSpout for streaming XLSX.

```
app/Livewire/          page components (dashboard, providers, types, featured, transfers, users, audit, migration review)
app/Services/          ProviderService, Transfers/* (import/export engine), Legacy/* (migration), DashboardStats
app/Policies, app/Enums/Role.php   role → permission matrix (docs/08_SECURITY.md)
app/Http/Controllers/Api           /api/v2 read-only API
resources/css/app.css  the Euro Assist design system (tokens + shell components)
resources/views/components/ui/     shared UI primitives (button, field, table, modal, toast, badges…)
deploy/it-package/     IT installation documents shipped in the release ZIP
scripts/release/       reproducible release builder
```

**Bilingual data model (ADR-005/009):** one canonical `providers` row per branch/service location holds
language-independent fields (phone, e-mail, website, map link, coordinates, type, status); `provider_translations`
holds Arabic and English text. A missing language is shown as missing — nothing is machine-translated.

**Legacy migration (ADR-014):** the old system stored English and Arabic as separate tables. Each legacy row became
one V2 provider (`unpaired`) with a byte-identical provenance copy in `legacy_staging_rows` and an old-ID mapping in
`legacy_id_maps`. 334 English/Arabic pair suggestions await human review; nothing is merged automatically.

## Design system

The UI follows the approved Euro Assist Cards System V3 identity (2026-11-08 release): ink navigation with a red
glow, the Euro Assist hero gradient for active items and hero bands, a bright cool canvas, white rounded surfaces,
restrained shadows and the Cairo typeface for Arabic and Latin. Tokens live in `resources/css/app.css`:
`brand-*` (Euro Assist red; `#d10000` is the accessible working colour), `slate-*` (ink neutrals), `red-*`
(errors, deliberately raspberry so status is never confused with the brand), `green-*`, `amber-*`, `sky-*`.
Motion is 150–250 ms and disabled under `prefers-reduced-motion`. Logos in `public/brand/` are the authentic files
(SHA-256 in `public/brand/README.md`); Cairo is SIL OFL (`resources/fonts/Cairo-OFL.txt`).

## Local development

Prerequisites: PHP 8.3+ with `pdo_mysql, mbstring, intl, zip, fileinfo, xml, openssl`; Composer 2; Node 20+;
MariaDB/MySQL. Full native setup: [`docs/07_LOCAL_SETUP.md`](docs/07_LOCAL_SETUP.md).

```bash
composer install
npm ci && npm run build
cp .env.example .env && php artisan key:generate      # point DB_* at a development-only database
php artisan migrate && php artisan db:seed               # provider types only
php artisan users:provision you@example.test --role=admin --generate   # password written to storage/app/private/provisioned/
php artisan serve
php artisan queue:work database --tries=1                # second terminal: Excel import/export
```
Synthetic demo data (never real data): `php artisan db:seed --class=SyntheticProvidersSeeder`.
Owner UAT on an isolated, resettable database: `powershell -ExecutionPolicy Bypass -File .\start-uat.ps1`
([`docs/16_UAT_CHECKLIST.md`](docs/16_UAT_CHECKLIST.md)).

## Tests and quality gates

```bash
php vendor/bin/phpunit                                   # SQLite, PHP 8.3 and 8.4
php vendor/bin/phpunit -c phpunit.mysql.xml              # MariaDB integration (euro_assist_v2_test only)
php vendor/bin/pint --test && php vendor/bin/phpstan analyse
composer validate --strict && composer audit && npm audit
python -m unittest discover -s tests/repository -t .     # repository / packaging guards
python scripts/check_repo_hygiene.py
gitleaks git --no-banner .                               # full history
```
Hosted GitHub Actions are blocked by account billing; all evidence is produced locally and recorded in
[`docs/11_WORK_LOG.md`](docs/11_WORK_LOG.md) and [`docs/15_QUALITY_GATES.md`](docs/15_QUALITY_GATES.md).

## Deployment (summary)

Built with `scripts/release/build_release.py` into one ZIP for IT: `providers/` (application with production
`vendor/` and built assets) + `database/…sql` (authentic migrated data, no accounts or credentials) + guides.
**Document root = `providers/public`.** IIS (`public/web.config`, the legacy host is Plesk for Windows) and Apache
(`public/.htaccess`) are both supported. Step by step: [`deploy/it-package/INSTALL_GUIDE.md`](deploy/it-package/INSTALL_GUIDE.md);
backup/rollback: [`deploy/it-package/BACKUP_AND_ROLLBACK.md`](deploy/it-package/BACKUP_AND_ROLLBACK.md).
The production boot guard refuses `APP_DEBUG=true`, a non-https `APP_URL`, insecure session cookies and
`TRUSTED_PROXIES=*`.

## Security controls (summary, details in [`docs/08_SECURITY.md`](docs/08_SECURITY.md))

Default-deny policies per role; rate-limited login; CSRF on every form; Blade escaping and a shared URL rule
(`javascript:` etc. rejected); Excel formula-injection neutralization on export; XLSX inflation/zip-bomb/DTD limits on
import; private file storage only (no public storage link); API tokens hashed, scoped, expiring and revocable;
host and proxy pinning; full audit log.

## What is NOT in this repository

Real database dumps, provider/contact datasets, `.env` files, passwords/hashes, API tokens, UAT password files,
exports, uploads, backups, logs and the release ZIP. They live in private local folders and are transferred only
by King to authorized staff.

## Known limitations and release gates

See [`deploy/it-package/KNOWN_LIMITATIONS_AND_OPEN_DECISIONS.md`](deploy/it-package/KNOWN_LIMITATIONS_AND_OPEN_DECISIONS.md)
and the gate table in [`docs/01_CURRENT_STATUS.md`](docs/01_CURRENT_STATUS.md): Plesk staging not verified, V1 API
consumers unknown (no `/api/...` V1 compatibility layer), data snapshot freshness, provisional type labels and held
rows, hosted CI billing.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `APP_URL must be an https:// URL…` on boot | production guard; set an https `APP_URL` (and `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false`). |
| 404 on every page except `/` (IIS) | URL Rewrite module missing, or document root is not `providers/public`. |
| Import/export stays "queued" | the every-minute scheduled task (`schedule:run`) or a queue worker is not running. |
| Arabic shows `????` | database or import not utf8mb4; re-import with `--default-character-set=utf8mb4`. |
| Styles missing | `public/build/` absent; run `npm run build` (development) — the release ZIP already contains it. |
