# Known limitations and open owner decisions

None of these blocks a **staging** installation. Items marked *before production* need King's decision.

| # | Item | Effect today | Needed |
|---|---|---|---|
| 1 | **Plesk not yet verified** | Package tested locally only (Windows, PHP 8.3/8.4, MariaDB 11.4). | Staging install on the real host (*before production*). |
| 2 | **Data freshness** | SQL = frozen snapshot; later edits in the old system are missing. | FRESH_DATA_SYNC.md (*before production*). |
| 3 | **English/Arabic pairs still for human review** | 790 pairs were merged after a second verification on V2 (reversible, reason recorded). **104** pairs proposed by the owner's review failed a V2 check and were **not** merged, and **195** suggestions of the automatic matcher are still open (two overlapping groups: 10 pairs are in both). Everything not merged stays as two separate, visible records, so a branch may appear once in Arabic and once in English; the dashboard explains that the total is not the number of unique branches. Search, export and API work per record. | Owner review of the remaining pairs (can continue after go-live; the review screen runs only in the engineering migration workspace, ADR-014; the Admin dashboard shows the open counts). |
| 4 | **Type labels** (Laboratories, Radiology Centers, Specialized Centers, Physiotherapy Center; 972 rows) | **Approved** on the owner's instruction (ADR-017): they map to the existing types `laboratory`, `radiology_center`, `specialized_center`, `physical_therapy`. The original label text is kept in the provenance table. | None. |
| 5 | **2 "Eye Center" rows still held** | The other 5 "Eye Center" rows were mapped to Optics because their Arabic counterparts are classified as optics. The 2 held rows have a duplicate in the English source and are **not** shown as providers; they are preserved unchanged in `legacy_staging_rows`. | Decide which type those 2 rows belong to; then they can be imported. |
| 6 | **2 malformed map links** | Repaired: each held two web addresses joined by a space; the second, complete address is used and both providers are imported. The original text is kept unchanged in the provenance table. | None. |
| 7 | **Pictures** (decision D8, closed) | The 16 referenced image files from the old `uploads/logos` folder are included (5 provider photos, 3 featured logos, 8 default type pictures); the original references are kept. Files of that folder that no record uses were not copied. Photos can now be uploaded, replaced and removed on the provider page. | None. Back up `storage/app/private` together with the database. |
| 8 | **API consumers unknown** (D7) | `/api/v2` requires a token; old `/api/...` URLs of V1 are not served. | Name any system that calls the old API before switching the domain. |
| 9 | **Password-reset e-mail** | `MAIL_MAILER=log` until SMTP details are provided. | SMTP account for the no-reply address. |
| 10 | **Automated checks** | The same code passes the GitHub Actions checks (PHP 8.3 and 8.4, MariaDB, frontend build). They do not test your server. | None; staging on the real host is item 1. |
| 11 | **Old data timestamps** | Original created/updated dates kept in provenance, not shown on V2 screens. | None. |
| 12 | **Legacy staff accounts** (ADR-016) | The snapshot holds 1 account (`admin@euro-assist.com`), included with Admin role and no usable password; activation is a cutover step. Accounts added in the old system after the snapshot appear only after the fresh sync. | Authorize the activation at cutover. |
| 13 | **PHP `gd` extension** | Uploaded pictures are re-encoded by `gd` to remove hidden content. Without `gd` an upload is still checked but stored as sent. | Enable `gd` with JPEG, PNG and WebP support (see INSTALL_GUIDE.md). |
