# Mandatory instructions for any contributor or AI session

This file is the handoff protocol and operational contract for Euro Assist Providers System V2.

## First 5 minutes of every session

1. Read `README.md`, `docs/00_PROJECT_CONTEXT.md`, `docs/01_CURRENT_STATUS.md`, `docs/10_DECISIONS.md`, `docs/11_WORK_LOG.md`, `docs/12_HANDOFF.md`.
2. Inspect `git status`, `git log -10 --oneline`, relevant source and tests, and any related GitHub issues/PRs.
3. Identify the first **uncompleted** item and its acceptance criteria. Do not assume previous chat claims are verified.
4. Say briefly: current verified state, next task, risks and proposed modifications.
5. Work on a `feature/*` or `fix/*` branch; don't silently rewrite `main` or bypass tests.

## Immutable project requirements

- Speak to King in Egyptian Arabic when discussing tasks; documentation and code may be in professional English.
- Legacy uploaded snapshot is the frozen source of truth for old behavior. Keep original site live and untouched.
- Develop on local clone, using a separate sanitized/development-only database. Treat any source secrets as compromised until reviewed, and never echo them in logs or Git.
- Do not change the agreed technology choice casually. Challenge it with evidence and record the decision.
- Never perform production actions, migrations, DNS changes, credentials rotation, or cutover without explicit authorization.
- No fabricated test results or assertions that site/hosting/queue is working unless verified.
- Version public API behavior and maintain a compatibility matrix; preserve old IDs in a dedicated mapping table.
- Security and import/export stability take priority over cosmetic UI changes.

## Required end-of-task update — same commit/PR

- `docs/01_CURRENT_STATUS.md`: update status, last verified commit and blockers.
- `docs/11_WORK_LOG.md`: append date, branch/commit, files modified, tests run and actual results, decisions, remaining work.
- `docs/12_HANDOFF.md`: next actions with commands/paths, and any known risks.
- `docs/13_CHANGELOG.md`: update when user-visible behavior changes.
- `docs/10_DECISIONS.md`: add a dated ADR for meaningful architecture/contract changes.
- GitHub Issue/PR: link task, attach validation evidence, state expected versus actual.
- Leave honest `TODO`/blocked states; never label untested work done.

## Definition of Done

A task is done only when requirements and acceptance criteria are satisfied, implementation code is committed, relevant automated tests pass (or an explicit verified limitation is documented), security checks are clean, and docs/work log/handoff are updated. PR review before merging to main.

## Safety

Never commit `.env*` (except safe `.env.example`), SQL/database dumps, production exports, PII, JWT/API keys, password hashes, raw backups, logs, user-uploaded media, Plesk configs with secrets, vendor dependencies, or generated cache. Run `python scripts/check_repo_hygiene.py` before commit. This checker is a basic guard, **not a replacement** for secret scanning and review.

## Prompt to paste into a new chat

> This is Euro Assist Providers System V2. Open the private GitHub repository I linked. Read AGENTS.md, README.md, docs/00_PROJECT_CONTEXT.md, docs/01_CURRENT_STATUS.md, docs/02_MASTER_PLAN.md, docs/10_DECISIONS.md, docs/11_WORK_LOG.md and docs/12_HANDOFF.md; inspect relevant source, open GitHub issues and the latest commits. Summarize verified current state and continue the next task in a branch. Production must remain untouched. Update all project memory files with the commit before finishing. Do not invent test results.
