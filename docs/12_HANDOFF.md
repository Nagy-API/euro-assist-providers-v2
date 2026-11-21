# Handoff: open this document in every new chat

**Date:** 2026-11-21. Read AGENTS, README, status, plan, ADRs and work log first; inspect Git/Issues/PRs instead of trusting chat summaries.

## Current true state

- Private remote: https://github.com/Nagy-API/euro-assist-providers-v2. Original main seed SHA `9d7437cb87e7a2d996dd3c506c3aadef756695f3` preserved. Current feature branch `feature/p0-governance-bootstrap` has foundation/audit/governance work; status records publication/review evidence.
- Seven milestones and 37 phase/task issues exist with task lists on epics#1–#7. Dependency alerts/automatic fixes enabled. Private-repository branch protection unavailable on current plan; secret scanning unavailable; effective push-protection state not established. PR/CI conventions do not enforce protected main.
- Static legacy inventory/schema/API docs03/05/06 and restricted local restore aggregates exist. No legacy app executed. No records/media/secrets belong in Git. SQL totals are snapshot baselines, not V2 migration evidence.
- Native Laravel13/Livewire4/Tailwind4 foundation: framework-only migrations, empty seed, isolated tests, database queue/cache probe and CI. Upstream welcome only; no default account/auth/provider schema/CRUD/import/export/V2 API.
- Browser checked welcome at desktop/tablet/mobile and health, no horizontal overflow/captured errors. Asset delivery and server-side Livewire action tested; browser Alpine interaction unverified. Supplied production dashboard URL only exposed login redirect; no credentials submitted.
- Owner approved blank updates preserving existing values, clearing via explicit separate action, and any validation error blocking whole-file application. Infrastructure interruption after validated application begins remains resumable and visibly incomplete (ADR-008).
- Plesk unverified; production unchanged.

## Local paths (environment details)

Repository is `euro-assist-providers-v2/` under the user's Providers workspace. Outer hosting snapshot/Providers.sql are private frozen inputs. Providers.rar was not found in the searched Downloads tree; extracted source/SQL were available.
Portable tools/caches are in outer `.tools/`; private databases/configuration/process/review artifacts in outer `.work/euro-assist-20261121/`. Never copy those into Git. MariaDB is a hidden native process at127.0.0.1:33316, no Windows service. Dedicated local/test/audit databases and accounts are separate. Follow docs07 for startup/private credentials, never reinitialize data or use audit account for V2. No global PATH change. Sandbox networking/PHP readability required escalated local commands in this session.

## Recovery note (2026-11-21)

The earlier Codex session stopped at its usage limit with the foundation, audit docs and governance edits uncommitted. A Claude recovery session verified the working tree, re-ran checks, committed everything on `feature/p0-governance-bootstrap`, pushed and opened a PR. **One repository, one checkout:** `N:\Coding\programming\VS code\Providers\euro-assist-providers-v2`. Do not clone again, create worktrees or make per-task folders; use branches. The earlier path `...\Providers\work\euro-assist-20261121` does not exist: Codex's scratch folder is `...\Providers\.work\euro-assist-20261121` (private DB data/logs/evidence, never in Git).

Facts established by the recovery session: PHP 8.3.37 is available at `.tools/php-8.3.37` and the suite passes on it; local MariaDB runs on 127.0.0.1:33316; GitHub Actions cannot start any job because of an account-level billing failure (not a code problem, see `docs/14_GITHUB_BOOTSTRAP.md`); the role matrix is approved (ADR-010) but unimplemented.

## Exactly what to do next

1. `git status --short`, `git branch --show-current`, `git log -10 --oneline`; inspect PR #38 and latest Actions. Never re-extract/reinitialize or overwrite existing work.
2. **Owner:** fix GitHub Billing & plans (hosted CI cannot start any job), then `gh run rerun` the failed runs and record real results. Require green `PHP 8.3`, `PHP 8.4`, `assets` and `safe-files` before merging [PR #38](https://github.com/Nagy-API/euro-assist-providers-v2/pull/38). No merge without the owner's explicit approval; no force-push.
3. **Owner decisions needed for P2.1 ([#17](https://github.com/Nagy-API/euro-assist-providers-v2/issues/17)):** D1 field ownership, D3 type vocabulary, D4 required/nullable, D5 featured model, D6 pairing/review policy (also D2, D7, D8 before P2.2). Evidence and recommended defaults: `docs/05_DATA_MIGRATION.md` section "P1.4 / P2.1 decision proposal". Do not implement schema before approval.
4. Then P2.2 (#18) synthetic migrations, then P2.3 (#19) auth and Admin/Editor/Viewer policies per the final matrix (ADR-010 Amendment A, `docs/08_SECURITY.md`). Open minor item: who may run the explicit clear-a-value operation (default Admin only).
5. Follow-up hardening from the independent PR #38 review (non-blocking, tracked in its own issue): late test-isolation guard, no MariaDB CI job, Dependabot only for Actions, hygiene checker false positives/negatives, production env guidance, `laravel/tinker` in `require`.
6. Re-run local checks per docs07 after any edit; historical results do not prove new edits. Prioritize versioned headers and tested round trip instead of copying positional legacy imports. No implicit merge/replacement or valid-row-only application.
## Guardrails

No production DB/credentials/deploy/DNS/Plesk changes/key rotation/cutover without separate express authorization. Never commit raw archives/dumps, .env, caches, real records/contact values, source secrets or uploaded media. Keep uncertainties explicit and update existing memory files with each meaningful commit/PR.
