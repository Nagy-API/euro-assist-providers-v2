# Append-only work log

## 2026-11-08 — Documentation/Git baseline

- **Goal:** Prepare project repository structure to preserve exact history and new-chat continuity.
- **Inputs reviewed:** local uploaded legacy archive, controller/routes/composer metadata and SQL **schema only** for documents.
- **Decisions:** V1 frozen/unmodified, V2 local, Plesk assumed, GitHub Private, Laravel-family new implementation target.
- **Deliverables:** README, AGENTS, context/status/plan, legacy audit, import/export, migration, API, setup, security, deployment, ADR, handoff, changelog, check script and GitHub templates.
- **Source code changes:** None — V2 application not started.
- **Tests performed:** Repo hygiene script run against this local Git seed; see initial generator validation output. **No application tests, server tests or production deployment.**
- **Remote GitHub:** Not created/pushed yet; no remote commit or PR to cite.
- **Remaining:** Create private repository, push initial commit, create Issues, begin P1 tooling and audit.

---

## Template for every future entry

### YYYY-MM-DD — Short task name

- **Issue / PR:** links or `not created`.
- **Branch / commit:** actual SHA or `not committed`.
- **Change summary:** exact files and behavior changed.
- **Commands/tests:** command, environment, exit/result, failures.
- **Security and data notes:** whether sensitive data was accessed; never copy values.
- **Decisions/risks:** ADR link or reason none.
- **Current status:** DONE / IN PROGRESS / BLOCKED with evidence.
- **Next steps:** actionable exact tasks, expected outputs.

## 2026-11-21 — Private repository, frozen-source audit and native foundation

- **Issues:** P0#1/P1#2; tasks#8–#16, complete phase/task backlog#1–#37. PR not created yet.
- **Branch/base:** feature/p0-governance-bootstrap; original seed9d7437cb87e7a2d996dd3c506c3aadef756695f3 preserved. This working-tree change is pending commit, independent whole-change review and hosted CI.
- **Governance:** extracted ZIP preserving.git; git fsck --full exit0; clean seed; required docs/path hygiene exit0. Created empty PRIVATE remote and pushed seed; read-back matched main SHA/tree/privacy. Enabled dependency alerts and verified automatic fixes. GitHub branch protection403(plan), secret scanning422(unavailable); effective push-protection state unverified. Created7milestones/37issues with priorities/dependencies/acceptance criteria. Added pinned least-privilege CI, redacted full-history Gitleaks, Dependabot and real-index hygiene regressions.
- **Audit:** read AGENTS/README/all docs before decisions. Inspected frozen controllers/routes/views/models/migrations/schema only, no legacy PHP execution. Source contracts show positional Logo Path/Package spreadsheet mismatch, absent declared API write handlers, missing governorate in dump, auth/guard inconsistencies and no bilingual FKs. Docs03/05/06 separate static/inferred/runtime unknown.
- **Private restore:** checksum-verified frozen SQL, lexical preflight55recognized top-level+7versioned SET directives, no unexpected/dangerous directives. Restricted import into empty loopback33316 audit DB: exit0,10tables. Counts1090English/1104Arabic/3featured; quality/byte-exact candidate aggregates recorded in docs05, no raw values. No canonical matching or V2 migration.
- **Native environment:** official checksum-verified PHP8.4.28/Composer2.11.2/Gitleaks8.30.1/MariaDB11.4.14 outside Git; Node24.14.1/npm11.11.0. Per-command PATH, no global service/Docker. Separate local/test/audit DBs/accounts, no production credentials.
- **Foundation:** selectively adopted official Laravel skeleton, preserved governance, added Livewire4/bundled Alpine/Tailwind4, PHP8.3 dependency floor, framework-only migrations/empty seeder, isolated SQLite/MySQL configs, database queue/cache probe, Pint/Larastan and application CI. No business schema/auth/provider/admin/import-export/API feature.
- **Implementation evidence (independent final verification pending):** composer check exit0,6PHPUnit tests/26assertions; MySQL infrastructure2tests/17assertions; Pint/Larastan level6 clean; composer/npm audits0known vulnerabilities; Vite asset build exit0. Contaminated inherited DB env originally triggered guarded failures, dual env/server overrides corrected it; contaminated SQLite and MySQL runs then passed. Governance18tests passed after review-driven ignore/cache fixes. Full-history seed Gitleaks1commit~54.79KB exit0/no leaks; combined change scan still required before publication.
- **Browser:** actual welcome inspected1280x800,768x1024,390x844; no horizontal overflow/captured warnings/errors. /up rendered. Livewire script asset200 and server-side component action passed; browser Alpine interaction not verified because read-only evaluation exposed an isolated world. Production dashboard URL redirected to public login; no credentials or write action.
- **Decisions:** ADR007native/runtime compatibility, ADR008owner-approved blank preservation/whole-file validation, ADR009owner-approved branch/service-location identity with manual uncertain matching. Detailed schema/roles/type/consumer decisions remain open.
- **Review:** governance first review found source-hiding seed ignore patterns; fix round1 narrowed exclusions and added real-index coverage; independent re-review PASS18tests. Generated bootstrap-cache force-add hardening also confirmed. Whole application/security/final reviews in progress.
- **Security/data:** legacy records touched only inside private local restore/aggregate queries. No source secrets/provider values/uploads/dumps in V2. Production unchanged. No push beyond original seed, merge, deployment or credential rotation.
- **Next:** finish independent reviews/verifier, resolve findings, record actual final commands/results, commit/push feature branch and PR; wait for hosted CI. Complete P1 business/parity gates before P2. Do not mark a phase done from this log alone.


## 2026-11-21 — Recovery of interrupted Codex session, publication and authorization decision

- **Issues / PR:** P0#1, P1#2, P2.3#19 (comment added); PR from `feature/p0-governance-bootstrap` to `main` (link in the PR list; not merged).
- **Branch / commit:** `feature/p0-governance-bootstrap` on base seed `9d7437cb87e7a2d996dd3c506c3aadef756695f3`; exact head SHA is the PR head.
- **Recovery:** the single Git repository is `Providers/euro-assist-providers-v2` (one `.git`, no extra worktrees, no stash, remote `origin` = the private repo). Found 17 modified tracked files and 61 untracked paths (Laravel foundation, tests, CI) from Codex; none lost. Outer `.work/` and `.tools/` are non-Git scratch/tooling and were not touched.
- **Changes by recovery session:** removed trailing whitespace in the upstream `resources/views/welcome.blade.php` (so `git diff --check` is clean); recorded ADR-010 (Admin/Editor/Viewer) and the authorization matrix in `docs/08_SECURITY.md`; documented PHP 8.3 evidence and the exact Actions billing blocker; updated status and handoff.
- **Commands/results (LOCAL, Windows, portable PHP):** `composer check-platform-reqs` exit0 (8.3.37); `composer check` exit0 on 8.3.37 (Pint passed, PHPStan no errors, PHPUnit 6 tests/26 assertions); same suite on 8.4.28 exit0; MariaDB suite (`phpunit.mysql.xml`) exit0, 2 tests/17 assertions; `composer audit --locked` no advisories; `npm audit --audit-level=low` 0 vulnerabilities; `npm run build` exit0; Python repository tests 18 OK; `check_repo_hygiene.py` PASS; Gitleaks 8.30.1 on staged changes (~629 KB) and full history: no leaks; `git diff --cached --check` exit0.
- **GitHub:** repository verified PRIVATE; default branch `main`; issues #1–#37 and 7 milestones present. Actions: the only run (#44882712766) failed in 2s with the account billing annotation; zero steps ran. Hosted CI has therefore never executed. No billing change was made.
- **Security/data:** no `.env`, dump, DB files, logs or credentials staged (hygiene + Gitleaks + path grep). Private DB credentials file was read only to run the local MariaDB test suite and never printed. Production untouched.
- **Review limitation:** no independent reviewer/verifier agent was run; the review is a same-context self-review. Independent review of the foundation PR remains open.
- **Decisions:** ADR-010 (owner-approved roles). Editor delete/bulk/featured/audit-log are undecided and default to deny.
- **Current status:** bootstrap recovered and published; P0 not closed (hosted CI + owner review pending); P1 open; P2 not started.
- **Next:** owner fixes billing and reviews the PR; then P2.1 (#17) after the remaining P1.4 (#13) owner decisions.


## 2026-11-21 — ADR-010 finalization, independent PR #38 review, P1.4/P2.1 proposal

- **Issues / PR:** #19 (final role matrix), #13/#17 (decision proposal), #39 (new: review follow-ups), PR #38 (review comment). Not merged.
- **Branch / commit:** `feature/p0-governance-bootstrap`; this docs-only change is committed on top of `f1bffd0` (see PR #38 head).
- **Roles:** owner finalized Editor permissions (create/edit, bulk create/update with validation+preview+confirmation+audit, import/export, own job history allowed; delete/restore, bulk delete/restore, featured management, global audit log denied). ADR-010 Amendment A, `docs/08_SECURITY.md` matrix with proposed permission keys, `docs/04_IMPORT_EXPORT_SPEC.md` and #19 updated. Only "who may clear a stored value" remains open (default Admin only). Documentation only; no authorization code exists yet.
- **Independent review:** separate reviewer agent on the committed state of PR #38 (it did not receive the implementer's claims). Verdict PASS_WITH_MINOR_NOTES: no code-level blocking finding; one process gate (hosted CI never ran, billing). 12 non-blocking groups (B1-B12) tracked in #39; B1, B4, B5 and B7 were independently re-checked by the coordinating session. Reviewer local runs: PHPUnit on PHP 8.3.37 6 tests/26 assertions OK (also with a hostile inherited env), hygiene PASS, Python 18 OK, `composer validate --strict` valid, Gitleaks no leaks. It did not run the MariaDB suite or touch `.work`. Hosted Linux CI remains unverified.
- **Proposal:** `docs/05_DATA_MIGRATION.md` "P1.4 / P2.1 decision proposal" with decisions D1-D8. New read-only aggregates against the isolated snapshot restore (counts and shapes only; frozen source SHA-256 `19a71fe3...1814`); e.g. email and hours blank in all rows, 3 featured names each match 81-409 provider rows, same-ID pairs have 0 equal names. Not independently re-run. Nothing implemented.
- **Docs fixes:** handoff numbering, master-plan status, docs07 guard-limit wording (review B1/B11).
- **Commands (LOCAL):** `check_repo_hygiene.py` PASS; `git diff --cached --check` exit 0; Gitleaks 8.30.1 on staged docs: no leaks. No PHP code changed, so the earlier PHP results are unchanged and were not re-run.
- **Security/data:** only aggregate counts/vocabulary shapes read from the private local audit DB with its SELECT-capable importer account; nothing printed or committed beyond aggregates. Production untouched.
- **Current status:** PR #38 waiting on owner billing fix and approval. P2.1 blocked on D1, D3, D4, D5, D6.
- **Next:** owner answers decisions; then P2.1 approval, P2.2, P2.3. Hardening in #39 as capacity allows.

## 2026-11-22 — Emergency recovery of interrupted multi-agent session, integration, security review R2

- **Issues / PR:** PR #40 (Draft, stacked on #38); #19, #21–#29, #30–#33 progressed. Not merged.
- **Branch / commits:** `feature/p1-foundation-hardening`: `f5e5a81` (recovery checkpoint) + follow-up commit with R2 fixes and these docs (see PR head).
- **Recovery:** found 35 modified/deleted tracked files and ~100 untracked paths (Livewire UI, transfers, API, legacy, tests) plus one unpushed local commit `1bc6ea4`. Backed up `git diff` and an untracked-files tarball to `.work/recovery-20261122/` before any edit. No reset/clean/checkout/worktree. Removed only an agent's leftover debug test (`tests/Feature/Ui/DbgTest.php`, var_dump probe; real coverage exists in `FeaturedTest`).
- **Fresh first run:** PHP 8.3 suite 472 tests: 5 errors, 2 failures. Root causes: URL regex delimiter bug (every URL validation raised a warning -> 500), login limiter crash on array input, unexposed Sanctum CSRF route, two Livewire tests reusing a component after a 403, a second-dependent N+1 test, production-boot test without https URL. MariaDB run also exposed a legacy test that forced the default connection to sqlite.
- **Integration/security fixes:** host/proxy trust moved off `env()` (config-cache safe) with tests; Sanctum stateless-only config; import lock store must support atomic locks; Larastan 82 -> 0 via precise model `@property` types and removing dead checks (no ignores/baseline); Pint; export page inputs had no visible borders (missing `border`); "no translation" note used the content direction instead of the UI direction.
- **Independent security review R2** (separate read-only agent, no implementer claims): CHANGES_REQUIRED. Fixed M1, L1, L2, L3, L4 with regression tests (ADR-013). Open suspicions S1–S3 listed in status/handoff. Re-review not yet run.
- **Commands/results (LOCAL):** PHPUnit 479 tests / 2788 assertions OK on PHP 8.3.37, PHP 8.4.28 (SQLite) and MariaDB 11.4.14 (`phpunit.mysql.xml`, full suite); Pint passed; Larastan level 6 no errors; `composer validate --strict` valid; `composer audit --locked` none; `npm audit` 0; `npm run build` OK; hygiene PASS; Python repo tests 23 OK; Gitleaks staged no leaks; `git diff --cached --check` clean.
- **Browser (local dev DB `euro_assist_v2_local`, synthetic seeder, provisioned local test accounts):** failed/successful login; dashboard; list + live search; create provider incl. `javascript:` rejection; detail; Arabic RTL (dir=rtl, no overflow); export -> queue worker -> private download 200 `.xlsx`; Viewer/Editor route and nav access match ADR-010. Import upload not browser-tested.
- **Security/data:** no production access; legacy audit DB not touched; only synthetic data; provisioned password files are gitignored under `storage/app/private`. D1–D8 remain provisional.
- **Next:** see `docs/12_HANDOFF.md`.
