# Current project status — update after EVERY task

> **Current product status — 2026-11-25: COMPLETED / LIVE / OWNER-ACCEPTED.** Final release `2.0.0-rc.6_FINAL` was deployed and tested by the owner on company infrastructure. Providers: https://providers.euro-assist.com/ · Medical Card directory: https://medicalcard.euro-assist.com/providers.php (read-only API integration). The owner provided evidence of a completed production export. Final archive manifest source: `111399000d96ebaa6d9097297f7e3c093be2a6fc`; all 6,743 manifest-listed files verified. Source application is still on stacked PRs, including [PR #52](https://github.com/Nagy-API/euro-assist-providers-v2/pull/52); the default branch is a historical documentation scaffold, not the deployed tree. GitHub repository hygiene remains a separate outstanding check. Earlier planning/no-deploy notes below are historical, not current product status. No private archive, database or production credentials are published.

**Status date:** 2026-11-08  
**Current phase:** P0 — repository and documentation governance.  
**Current state:** Documentation-first local Git scaffold prepared; Laravel V2 application has **NOT** been bootstrapped.  
**Production:** Untouched per project rule.  
**Plesk compatibility:** Assumed for planning; not verified.  
**Latest verified application commit:** N/A (V2 application source not started).  
**Latest documentation commit:** Initial local Git commit. On GitHub: not pushed yet.  
**Open blockers:** New private GitHub repository must be created in `Nagy-API` and local seed pushed. Independent local runtime setup needs confirmation before scaffolding.

| Workstream | Status | Next action | Evidence |
|---|---|---|---|
| Repo/docs governance | Prepared locally, GitHub pending | Create private remote and push | This source tree |
| Legacy audit | Partial static audit | Finish feature matrix + defect tests | `docs/03_LEGACY_AUDIT.md` |
| Environment | Not started | PHP/Composer/MySQL verification + install | `docs/07_LOCAL_SETUP.md` |
| Laravel V2 skeleton | Not started | Bootstrap compatible Laravel after tooling check | No V2 app code yet |
| Database model | Designed conceptually | Review entity/branch/translation design | `docs/05_DATA_MIGRATION.md` |
| Auth + roles | Not started | Threat model and policies | `docs/08_SECURITY.md` |
| Import/export | Spec drafted, no implementation | Formalize template + test fixtures | `docs/04_IMPORT_EXPORT_SPEC.md` |
| API parity | Inventory started | Capture consumer requirements and response snapshots | `docs/06_API_CONTRACT.md` |
| UI/RTL | Not started | Define page inventory, accessibility checks | `docs/02_MASTER_PLAN.md` |
| Plesk deployment | Assumption only | Run isolated smoke test before final deployment | `docs/09_PLESK_DEPLOYMENT.md` |

## Immediate next actions

1. Create **empty private** GitHub repository `Nagy-API/euro-assist-providers-v2` (do not initialize README/license), then push this Git history.
2. Verify repo privacy, default branch and access permissions. Enable GitHub secret scanning if available.
3. Create Issues for P1/P2 tasks in `docs/02_MASTER_PLAN.md` and begin P1 local tooling/audit.
4. Update this status and `docs/12_HANDOFF.md` with the actual first remote commit SHA and issue links. Do not claim these exist until verified.
