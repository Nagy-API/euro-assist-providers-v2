# Known limitations and open owner decisions

None of these blocks a **staging** installation. Items marked *before production* need King's decision.

| # | Item | Effect today | Needed |
|---|---|---|---|
| 1 | **Plesk not yet verified** | Package tested locally only (Windows, PHP 8.3/8.4, MariaDB 11.4). | Staging install on the real host (*before production*). |
| 2 | **Data freshness** | SQL = frozen snapshot; later edits in the old system are missing. | FRESH_DATA_SYNC.md (*before production*). |
| 3 | **334 English/Arabic pair suggestions** open | Each language of the same branch is a separate record; the dashboard explains that the total is not the number of unique branches. Search, export and API work per record. | Owner review of the pairs (can continue after go-live; the review screen runs only in the engineering migration workspace, ADR-014). |
| 4 | **972 rows on provisional type labels** (Laboratories, Radiology Centers, Specialized Centers, Physiotherapy Center) | Rows use the deterministic mapping; original type text kept. | Approve the 4 labels (D3) or ask for a re-map (*before production*). |
| 5 | **7 "Eye Center" rows held** | Not imported as providers; preserved unchanged in `legacy_staging_rows`. | Decide which type "Eye Center" maps to; then they are imported. |
| 6 | **2 rows with malformed map links held** | Not imported; preserved unchanged. | Approve correcting the link or importing without it. |
| 7 | **Logos/uploads not migrated** (D8) | Logo file references kept; image files not copied. | Decide whether to migrate the old `uploads/logos` folder. |
| 8 | **API consumers unknown** (D7) | `/api/v2` requires a token; old `/api/...` URLs of V1 are not served. | Name any system that calls the old API before switching the domain. |
| 9 | **Password-reset e-mail** | `MAIL_MAILER=log` until SMTP details are provided. | SMTP account for the no-reply address. |
| 10 | **Hosted CI** | GitHub Actions blocked by account billing; all test evidence is local. | Fix billing (optional for deployment). |
| 11 | **Old data timestamps** | Original created/updated dates kept in provenance, not shown on V2 screens. | None. |
| 12 | **Legacy staff accounts** (ADR-016) | The snapshot holds 1 account (`admin@euro-assist.com`), included with Admin role and no usable password; activation is a cutover step. Accounts added in the old system after the snapshot appear only after the fresh sync. | Authorize the activation at cutover. |
