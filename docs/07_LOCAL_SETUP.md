# Local development setup — pending application bootstrap

**Important:** the initial Git seed is documentation only; do not claim `php artisan serve` works before the actual Laravel application is installed.

## Target prerequisites (to validate on developer PC)

- Git + GitHub account with private repo access.
- PHP >= 8.3 with Laravel-required extensions (`mbstring`, `openssl`, `pdo_mysql`, `fileinfo`, `xml`, etc.) and version-matched CLI.
- Composer, Node.js/npm, locally running MySQL/MariaDB (separate development database).
- PHPUnit/Pest and formatting/static analysis after chosen implementation.
- No Docker requirement. Prefer native PHP or a trusted Windows/PHP dev environment.

## Safe first steps

1. Clone private repo.
2. Run `python scripts/check_repo_hygiene.py`.
3. Verify `php -v`, `composer --version`, `node --version`, `npm --version`, `mysql --version`.
4. Document tool versions and install an actual Laravel 13 skeleton **on a dedicated feature branch**, without overwriting project docs.
5. Configure `.env` locally from `.env.example` (never commit it), with `APP_ENV=local`, `APP_DEBUG=true` and **separate** local DB credentials. Do not use production keys or DNS hosts.
6. Install dependencies via Composer/npm, generate a new local key and run migrations on an empty local DB.
7. Add smoke tests before implementing business features.
8. Record exact commands/results in `docs/11_WORK_LOG.md` and update handoff.

## Isolation checklist

- Local database name is dedicated to V2 and does not point to a reachable production host.
- No production user/password/SSH key in local `.env` or commit history.
- No production email/SMS integration while testing; use dummy providers for integrations.
- Private dump and uploaded media remain out of the Git working tree; use only anonymized or synthetic fixtures in tests.
