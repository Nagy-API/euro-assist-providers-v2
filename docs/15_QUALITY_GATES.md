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
