# Handoff: open this document in every new chat

**Handoff date:** 2026-11-08.

## Current true state

- Frozen V1 uploaded source/dump were statically reviewed. The legacy site on Plesk stays unchanged.
- Developer chose to assume Plesk will support intended Laravel-based deployment; this has not been tested.
- A documentation-first **local** Git seed was prepared. It does **not** yet contain working Laravel V2 code.
- The GitHub connector can edit a repository once it exists, but a remote repository creation action was not available in this session. No remote URL, push, Issues or PR should be claimed as done.

## Exactly what to do next

1. Owner creates an **empty private** GitHub repo called `euro-assist-providers-v2` under `Nagy-API`, with no README/gitignore/license initialization.
2. Push the seed Git branch to `https://github.com/Nagy-API/euro-assist-providers-v2.git` (see `docs/14_GITHUB_BOOTSTRAP.md`).
3. Open the repository and verify `Private` status, `main` branch, all docs, no dumped data/secrets.
4. Record actual first commit SHA and remote URL in `docs/01_CURRENT_STATUS.md`, then create Issues for P1 tasks.
5. Install/check local runtime, bootstrap Laravel in a feature branch, add first smoke tests and documentation updates.

## Guardrails

- Never modify production or point to production DB.
- Never add `Providers.rar`, `Providers.sql`, the old `.env`, actual providers/company contact data, server backup ZIPs or credentials to Git.
- Don't claim Plesk compatibility verified; verify with a small isolated test before release.
- Don't mark tasks DONE merely because doc plans exist.

## Request for a future AI or teammate

Read `AGENTS.md`, then status, decisions, work log, this handoff, relevant code and recent GitHub activity. Give the user a concise verified status; continue the first open task and update all handoff files along with code/tests.
