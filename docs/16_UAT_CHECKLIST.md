# Owner UAT checklist (local release candidate)

Run against the LOCAL application only (`docs/12_HANDOFF.md` → "Local startup"). It uses the locally migrated legacy data in `euro_assist_v2_local`; never expose this server publicly. Tick each line; note anything unexpected with the page URL and what you did.

Accounts: `admin@local.test`, `editor@local.test`, `viewer@local.test` (passwords in `storage/app/private/provisioned/*.txt`, never committed; delete these files when UAT is finished).

## 1. Sign-in and roles
- [ ] Wrong password shows one generic message; correct password opens the Dashboard.
- [ ] Viewer sees only Dashboard, Providers, Featured; cannot open create/edit/import/export/users/audit (403).
- [ ] Editor can create/edit, import/export and see own jobs; cannot delete, restore, manage featured, types, users, audit, migration review.
- [ ] Admin sees everything, including the "Legacy migration review backlog" panel on the Dashboard.

## 2. Dashboard (migrated data)
- [ ] Totals: 2,185 providers (+ any you add), 3 featured, types list (Laboratory largest).
- [ ] Translation coverage shows Arabic-only and English-only records (no pairs merged yet).
- [ ] Backlog panel: unpaired ≈2,185, suggestions 334, held rows 9, provisional mappings 972.

## 3. Providers
- [ ] Search an Arabic word, an English word, part of a phone number, an address word.
- [ ] Filter by type, by city "القاهرة الكبري" (Arabic) and "Great Cairo" (English) — both work from either interface language.
- [ ] Open a migrated provider: values look exactly like the old system (phone zeros, discount text, Arabic).
- [ ] Edit one field and save; reopen and confirm. Open the same record in two tabs, save in one, then save in the other → "changed by someone else" (no lost update).
- [ ] Admin: delete a test record, restore it from "Deleted records".
- [ ] Leaving a field empty when editing does NOT erase the stored value.

## 4. Arabic / English
- [ ] Switch language: Arabic is right-to-left, English left-to-right, no sideways scrolling on a phone.
- [ ] A record with only one language shows a clear "no translation" note, never invented text.

## 5. Excel
- [ ] Download the template (Import page).
- [ ] Upload a small file in **Dry run**: preview shows creates/updates; nothing is written.
- [ ] Upload a file with one bad row (e.g. website `javascript:x`, a formula `=1+1`, an unknown type): the WHOLE file is refused with row numbers; download the error report.
- [ ] Upload a valid file in Create only → Confirm → (worker running) status Completed; records appear.
- [ ] Export "All providers" (Arabic and English), download, re-import the same file in Dry run → all rows "Unchanged".
- [ ] Another user cannot open your import/export (404).

## 6. Featured and types
- [ ] Featured shows the 3 brand entries in both languages; only Admin can add/edit/delete; logo upload rejects non-images.
- [ ] Types page (Admin) lists the 30 types.

## 7. Legacy migration review (Admin)
- [ ] Review screen lists 334 suggested pairs with comparison fields.
- [ ] Approving without a reason is refused; approve with a reason; then "Undo approval" with a reason restores both records.
- [ ] "Source rows held for review" lists 9 rows (7 "Eye Center", 2 malformed map links) — decide what to do with them.

## 8. Decisions only you can take
- [ ] D1–D8 (`docs/05_DATA_MIGRATION.md`, table at the end), especially the 4 provisional type labels and "Eye Center".
- [ ] Who may clear a stored value (today: Admin only).
- [ ] Which external systems use the API (today none is verified).
- [ ] GitHub billing (hosted CI has never run); approval to merge PR #38 then PR #40.
