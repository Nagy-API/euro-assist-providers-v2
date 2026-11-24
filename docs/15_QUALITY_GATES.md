# Quality gates and Definition of Done

## Before implementation

- Requirements, failure modes, acceptance criteria and security impact are written in the issue.
- Dependencies and data contract are understood, documented assumptions explicit.

## Before PR merge

- Changes are scoped and reversible; no unrelated formatting churn.
- Relevant unit/feature/integration tests pass and results are copied into PR with versions and command.
- Import/export changes must have negative tests, crash/retry coverage, and integration/memory benchmarks where relevant.
- Auth/API changes must have role/permission and contract tests.
- CI green; `python scripts/check_repo_hygiene.py` passes; no secrets, PII, source dumps or unintended binary assets.
- README/ADR/status/work log/handoff/changelog updated as appropriate.
- Review comments resolved; documented limitations clear.

## Release/staging readiness

- Plesk smoke test has measured actual PHP CLI/web, composer, SQL, scheduler and queue behavior.
- All old features either proven compatible or intentionally changed with owner signoff.
- DB migration reconciliation: every original row accounted for; tests preserve record/translation/legacy IDs.
- Staging UAT passes on isolated/synthetic/sanitized data; export files protected and purgeable.
- Backups and rollback tested; explicit production approval required.

## Evidence template

- Commit SHA:
- Environment:
- Commands executed:
- Observed results:
- Test count/failures:
- Security considerations:
- Known limitations:
- Updated docs:

## Manual CI while hosted GitHub Actions is blocked (billing)

Run on the final commit, from the repository root, and paste the results into the PR (versions, counts, exit codes):

```text
php8.3 vendor/bin/phpunit                                  # SQLite
php8.4 vendor/bin/phpunit                                  # SQLite
php8.3 vendor/bin/phpunit -c phpunit.mysql.xml             # MariaDB, euro_assist_v2_test only (DB_PORT/DB_USERNAME/DB_PASSWORD from private config)
php vendor/bin/pint --test
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
composer validate --strict && composer audit --locked
npm audit && npm run build
python -m unittest discover -s tests/repository -t .
python scripts/check_repo_hygiene.py
gitleaks git --no-banner .                                 # full history
git diff --cached --check                                  # before each commit
```
Release candidates additionally require the package verification in `docs/18_RELEASE_PACKAGE.md` §4 on the built ZIP.
