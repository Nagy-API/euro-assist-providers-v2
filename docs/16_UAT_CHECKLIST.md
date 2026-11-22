# Owner UAT guide — Euro Assist Providers V2

Everything runs on **your computer only**, against a separate test copy of the migrated data (`euro_assist_v2_uat`). You can add, edit, delete, import and export freely: the preserved migration database and the old system are never touched, and you can reset the test copy at any time.

## 1. Start

Open PowerShell in the project folder (`N:\Coding\programming\VS code\Providers\euro-assist-providers-v2`) and run:

```powershell
powershell -ExecutionPolicy Bypass -File .\start-uat.ps1
```

The script checks everything, starts the app and opens the login page in your browser. Keep that window open while you test. **Press Enter in it to stop.**

- Address: **http://127.0.0.1:8000/login**. If another program already uses port 8000, the script picks the next free one (e.g. `http://127.0.0.1:8001`) and prints it in yellow. Use the address it prints.
- If you closed the window without pressing Enter, run `powershell -ExecutionPolicy Bypass -File .\stop-uat.ps1`.

## 2. Accounts

| Role | E-mail |
|---|---|
| Admin | `admin@uat.local` |
| Editor | `editor@uat.local` |
| Viewer | `viewer@uat.local` |

Passwords: open the matching text file yourself in `storage\app\private\provisioned\uat\` (one file per account). They are never shown in the terminal and never uploaded to GitHub. Keep the files until UAT is finished (a reset restores the same passwords), then delete them.

Tip: use a private/incognito window per account to compare roles side by side.

## 3. What to test (expected behaviour in *italics*)

### Sign-in and roles
- [ ] Wrong password → *one generic error message*. Right password → *Dashboard*.
- [ ] Viewer → *only Dashboard, Providers, Featured; no add/edit/delete/import/export buttons; other pages say "forbidden"*.
- [ ] Editor → *can add/edit providers, import, export, see own import/export history; cannot delete/restore, manage featured, types, users, audit log, migration review*.
- [ ] Admin → *everything*.

### Dashboard (Admin)
- [ ] *2,185 providers, 3 featured, Laboratory is the largest type.*
- [ ] *Translation coverage: 1,103 Arabic-only, 1,082 English-only, 0 both (no pairs merged yet).*
- [ ] *"Legacy migration review backlog": 2,185 unpaired, 334 suggestions, 9 held rows, 972 on a provisional type.*
- [ ] The "View list" links open the filtered provider list.

### Providers
- [ ] Search an Arabic word, an English word, part of a phone number, part of an address.
- [ ] Filter by type; by city "القاهرة الكبري" *(645)* and "Great Cairo" *(608)* — *both work in either interface language*.
- [ ] Open a provider: *values look exactly like the old system (phone zeros, discount text, Arabic)*.
- [ ] Edit and save one field. Open the same provider in two tabs, save in tab 1, then save in tab 2 → *"changed by someone else", nothing overwritten*.
- [ ] Leave a field empty and save → *the stored value is kept (empty never erases)*.
- [ ] Admin: Delete (confirmation dialog) → *marked deleted*; Restore → *visible again*.

### Arabic / English and phone
- [ ] Switch language (globe button) → *Arabic right-to-left, English left-to-right*.
- [ ] Phone/tablet size: the app is reachable only from this computer, so use the browser's device mode (F12 → phone icon) → *no sideways scrolling, menu opens from the ☰ button*.
- [ ] A record with one language shows *"no translation in this language"*, never invented text.

### Excel import / export
- [ ] Import page → *Download the template*.
- [ ] Upload a file with a mistake (e.g. website `javascript:x`, a cell starting with `=`, an unknown type) → *the WHOLE file is refused with row numbers; "Download error report" works; no Confirm button*.
- [ ] Upload a correct file in "Create only" → *preview* → Confirm → *status becomes Completed within seconds; the providers appear*.
- [ ] Export "All providers" (Arabic and English) → Download → re-upload the same file in **Dry run** → *every row "Unchanged", 0 errors*.
- [ ] Log in as another account → *you cannot open someone else's import or download (forbidden / not found)*; Admin can see everyone's.

### Featured providers and types
- [ ] Featured: *the 3 legacy brand entries in both languages*; only Admin can add/edit/delete; logo upload accepts only real images.
- [ ] Types (Admin): *30 types*.

### Legacy migration review (Admin)
- [ ] *334 suggested pairs* with side-by-side comparison; *nothing is approved automatically*.
- [ ] Approve **without** a reason → *refused*. With a reason → *merged*. "Undo approval" with a reason → *both records separate again*.
- [ ] Bottom of the page: *9 held source rows (7 "Eye Center", 2 malformed map links)* and the *972 provisional* notice.

## 4. Reporting a problem

Copy `docs/17_UAT_BUG_REPORT.md` for each problem (or send the same fields in a message). Do not paste real provider phone numbers or addresses into GitHub or chat; describe the record by its page address (e.g. `/providers/123`).

## 5. Start over (reset the test copy)

Stop the app first (Enter in its window), then:

```powershell
powershell -ExecutionPolicy Bypass -File .\reset-uat.ps1
```

It shows what will be lost and asks you to type `RESET euro_assist_v2_uat`. It only ever replaces the test copy, and it checks that the preserved migration database and the old-system copy did not change.

## 6. Please do not

- Edit `.env`, the `.work` or `.tools` folders, or anything under `storage\`.
- Run `php artisan migrate:fresh`, `db:wipe` or the `legacy:*` commands.
- Share the password files or the local address outside this computer.

## 7. Decisions only you can take (not approved yet)

- [ ] D1–D8 (`docs/05_DATA_MIGRATION.md`, table at the end) — especially the **4 provisional type labels** (Laboratories, Radiology Centers, Specialized Centers, Physiotherapy Center → 972 rows) and what **"Eye Center"** should be (7 rows).
- [ ] The 2 held rows with malformed map links.
- [ ] The 334 pair suggestions (approve/reject with a reason).
- [ ] Who may clear a stored value (today: Admin only).
- [ ] Which external systems use the API (none verified).
- [ ] GitHub billing (automated CI has never run) and approval to merge the pull requests.
