# GitHub remote bootstrapping — one-time manual action

**Expected GitHub owner:** `Nagy-API`  
**Repository name:** `euro-assist-providers-v2`  
**Visibility:** `Private`  
**Important:** private repo creation was not available through the connected GitHub actions in this conversation. The local Git repo is ready but the remote is NOT created yet.

## Method A — GitHub website + push from VS Code terminal

1. Visit https://github.com/new (while signed in as `Nagy-API`).
2. Set Owner=`Nagy-API`, Repository name=`euro-assist-providers-v2`, Visibility=`Private`.
3. Leave **Add a README**, **Add .gitignore**, **Choose a license** all **unchecked** so the remote is empty. Click Create repository.
4. Extract the provided ZIP (contains `.git` local history) and open its root in VS Code.
5. Run these from its root:

```powershell
git status
git remote add origin https://github.com/Nagy-API/euro-assist-providers-v2.git
git push -u origin main
```

6. Verify in browser that README/AGENTS/docs are present and the repo badge says Private. Only then mark P0 completed and log actual SHA.

If you extracted a **source-only** copy without `.git`, initialize and commit first:

```powershell
git init
git branch -M main
python scripts/check_repo_hygiene.py
git add .
git commit -m "docs: initialize Providers V2 project governance"
git remote add origin https://github.com/Nagy-API/euro-assist-providers-v2.git
git push -u origin main
```

## Method B — populate existing new private repo via GitHub connector

After creation, provide its URL to the assistant. It can write repository files, Issues and PRs using the connected GitHub tools, assuming repository access is granted. Prefer to push the prepared Git history rather than manually duplicating the same files.

## Recommended GitHub settings

- General: default branch `main`; private repository; restrict collaborators.
- Branch protection/ruleset: PR required for merge once team workflow established, prevent force-push/deletion of main.
- Security: dependabot alerts and secret scanning if account/repo supports them.
- Issues: use `type:feature`, `type:bug`, `type:security`, `area:import-export`, `area:api`, `area:database`, `area:deployment`, `status:blocked` labels once created.
- Do not upload the original RAR/SQL as an issue attachment, a release asset, or code file.
