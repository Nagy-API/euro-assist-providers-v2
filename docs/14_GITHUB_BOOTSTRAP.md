# GitHub bootstrap and repository controls

**Verified bootstrap date:** 2026-11-21
**Private repository:** [Nagy-API/euro-assist-providers-v2](https://github.com/Nagy-API/euro-assist-providers-v2)
**Default branch:** `main`
**Preserved seed commit:** `9d7437cb87e7a2d996dd3c506c3aadef756695f3`

The private remote was created and the original seed history pushed. Repository creation and the seed push are complete; do not initialize a replacement history or recreate the remote. The seed is a documentation scaffold, not a working V2 application.

## Verified security settings and platform limits

| Control | Observed result | Consequence |
|---|---|---|
| Private repository | Verified after creation and seed push | Keep source private; private visibility does not permit secrets or real data in Git. |
| Dependency vulnerability alerts | Enabled | Review alerts as dependency manifests are introduced. |
| Dependabot automatic security fixes | Verified enabled | Eligible dependency alerts can generate fix PRs. This does not replace dependency review. |
| GitHub secret scanning | Enable request returned HTTP 422: unavailable for this repository | Use the redacted Gitleaks history scan and manual review; do not claim GitHub secret scanning is enabled. |
| Branch protection | Endpoint returned HTTP 403: private repository requires a qualifying Pro plan | Protection is unavailable on the current plan; do not claim required review/status checks or force-push prevention are enforced. |
| Push protection | Update request succeeded; effective state awaits independent read-back verification | Do not treat the request response as proof that push protection is active. |

Visibility must remain private. Do not change visibility to obtain security features. A qualifying plan can be considered by the owner separately; no upgrade or visibility change is authorized by this document.

## Operational contribution workflow

1. Read `AGENTS.md`, current status, decisions and handoff before changing files.
2. Create a `feature/*` or `fix/*` branch linked to a scoped issue with acceptance criteria.
3. Run the local governance checks below; review the staged diff for secrets and actual company/provider data.
4. Update required project memory with actual commands/results and known limitations.
5. Push the branch and open a PR. Obtain independent review and inspect CI results before an authorized merge.
6. Verify the resulting commit and update the next handoff.

PRs, peer review and CI are operational controls. With branch protection unavailable, GitHub does not enforce this workflow and privileged contributors can bypass it. Follow the documented review process and explicitly record the platform limitation; a passing PR alone does not enable protection.

## Local governance checks

Run from the repository root with Python 3.12+ and Git available:

```powershell
python scripts/check_repo_hygiene.py
python -m unittest discover -s tests -v
git diff --check
```

The hygiene checker reads the real Git index, requires all numbered project documents plus `README.md` and `AGENTS.md` to exist and be tracked, and rejects obviously sensitive paths. It handles either slash convention, nested dependency directories, environment files, logs, archives, private storage and named raw exports. Only root `.env.example` and `.env.testing.example` are permitted environment templates, and their contents must remain safe examples. Laravel structural `.gitignore` marker files are allowed in storage directories, but generated files are rejected. Ordinary synthetic CSV/XLSX test fixtures are allowed outside prohibited directories; use clearly synthetic values only.

The checker deliberately evaluates indexed paths, so local ignored `.env` files and installed dependencies are not reported unless force-added. Run it after staging intended changes and before committing. It is a path guard, not a content/PII detector; neither it nor Gitleaks proves arbitrary files are free of company data.

## CI controls

`.github/workflows/repo-hygiene.yml` runs on pushes and PRs with `contents: read`, immutable official action commit pins, a complete history checkout and no persisted checkout credentials. It runs the checker, repository regression tests and the official Gitleaks CLI over all fetched Git history with findings redacted. Gitleaks 8.30.1 is downloaded from its official release and verified against the pinned archive SHA-256 before execution; no paid or licensed Gitleaks action is used.

The workflow is introduced on a feature branch. Its first hosted execution and results must be verified after pushing; local tests do not prove GitHub Actions execution. Full history means the refs fetched by checkout, not unpushed local objects or deleted remote history.

`.github/dependabot.yml` requests monthly grouped updates for GitHub Actions. Application-specific test/build workflows and dependency ecosystems will be added with the actual application and lockfiles.

For an equivalent local full-history content scan with the verified official CLI installed:

```powershell
gitleaks git --redact --no-banner --log-opts="--all" .
```

Never attach the legacy RAR/SQL, source secrets, production exports or raw records to issues, PRs, releases or CI artifacts. Do not print unredacted scanner findings.


## GitHub Actions execution status (verified 2026-11-21)

**Status: NOT EXECUTING. Account billing blocker, not a code failure.**

- Only one run exists: [Repository hygiene #44882712766](https://github.com/Nagy-API/euro-assist-providers-v2/actions/runs/44882712766) on the seed commit `9d7437c` (push to `main`). Conclusion `failure` after 2s; the job `safe-files` has zero steps.
- Check-run annotation (verbatim): "The job was not started because recent account payments have failed or your spending limit needs to be increased. Please check the 'Billing & plans' section in your settings".
- Repository Actions are enabled (`actions/permissions`: enabled, all actions allowed). The failure is account-level: failed payment or spending limit. Only the account owner can resolve it in GitHub Billing & plans. No billing setting, credit purchase or plan change was made or is authorized by this repository.
- Consequence: neither `.github/workflows/repo-hygiene.yml` nor `.github/workflows/application.yml` has ever executed. Their YAML is configured and pinned but **hosted CI has not passed**. Never report CI as green until a run actually completes with `success`.
- Equivalent local checks and results are in `docs/07_LOCAL_SETUP.md` ("PHP 8.3 evidence"). They are not a substitute for hosted evidence on Linux.
- After the owner fixes billing: re-run the failed workflow (`gh run rerun <id>`) or push, then record the real results in the PR, `docs/01_CURRENT_STATUS.md` and the work log.
