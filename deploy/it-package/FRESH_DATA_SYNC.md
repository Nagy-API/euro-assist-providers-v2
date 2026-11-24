# Fresh data before production (REQUIRED)

The SQL file in this package was built from the **frozen snapshot** of the old system
(`Providers.sql`, SHA-256 `19a71fe301c1038b52473b76bec64d2a95ca606dd701c4a2054c72f5bb791814`). Any provider added,
changed or deleted in the old system after that snapshot is **not** in it.

**Never load this older SQL into production as if it were current.** Use it for staging and acceptance only.

## What this release's data is made of (a rebuild must reproduce all three)

| Part | Where it lives | What a rebuild from the raw rows alone would lose |
|---|---|---|
| 1. The snapshot rows (2,197 source rows) | the old database dump | nothing |
| 2. The owner-reviewed **decision manifest** (private JSON, bound to the snapshot's SHA-256 and to every source row's fingerprint): 790 verified merges, 4 type-label approvals, 7 row decisions (5 "Eye Center" → Optics, 2 repaired map links) | held privately by the owner/engineering, never in Git | **all merges, the approved labels and the repairs** (rows would come back unpaired, on provisional types, or held) |
| 3. The **pictures** (16 files: 5 provider photos, 3 featured logos, 8 default type pictures) | the old site's `uploads/logos` folder on disk (not in the database) | **all pictures** |

So a fresh sync is *not* just "export the database again".

## Procedure (agree a date with King; production is read-only during the export)

1. **Freeze writes** in the old system for the cutover window (announce to staff; no edits, no Excel imports).
2. IT hands the engineering team, through a private channel, **two** read-only items and records the SHA-256 of each:
   * a new database dump (`mysqldump --single-transaction … > Providers-<date>.sql`, taken with a read-only account;
     keep the default `SET time_zone = "+00:00"` header: the migration reads timestamps as UTC);
   * a read-only copy of the old site's **`uploads/logos`** folder (the folder that contains the picture files).
3. Engineering (local, isolated machine — the migration tooling refuses to run against production, ADR-014):
   1. restores the dump into a new local audit database with a SELECT-only reader account; records its SHA-256 in the
      private environment (`LEGACY_SNAPSHOT_SHA256=<new sha>`; the default in `config/legacy.php` is the frozen snapshot and
      would refuse the new file), adds the new empty release database name to `LEGACY_ALLOWED_DESTINATIONS`, and updates the
      expected row counts (`legacy.baseline_counts`) from the new dump;
   2. runs into a new empty release database:
      `legacy:preflight --snapshot-sha256=<new sha>` → `legacy:stage --snapshot-sha256=<new sha>` →
      `legacy:map-types --from-legacy` → `legacy:apply --actor=<temporary local admin> --accept-provisional-types` →
      `legacy:suggest-pairs` → `legacy:reconcile --expect-baseline` →
      `legacy:import-users` (activation policy: identities and roles only, never the old passwords; report the account count to King);
   3. **re-applies the reviewed decisions.** The current manifest is *not* valid for the new data (it is bound to the old
      snapshot and the command refuses it). The independent reviewer repeats the review on the NEW snapshot and delivers a new
      manifest in the same schema (`schema_version` 1.0) with its SHA-256. Then:
      `legacy:apply-review-manifest <new manifest> --expect-sha256=<its sha> --actor=<temporary local admin> --dry-run --report=<file outside the repository>`
      first, then the same command without `--dry-run`. The command re-verifies every decision on the new data and applies only
      what passes; a source row that changed in the old system no longer matches its fingerprint and is **left unmerged** for
      review (this is the safe outcome, not an error). Compare the counts with this release (790 merges, 4 label approvals,
      7 row decisions); **every difference must be explained** and reported to King;
   4. **copies the pictures:** `legacy:import-media <folder that contains uploads/logos> --actor=<temporary local admin> --dry-run`,
      then without `--dry-run`. Every reference must be reported as copied, as the row's own type picture or as intentionally skipped;
      any `missing_file` or `not_an_accepted_image` is explained to King (a missing original is never replaced by an invented picture);
   5. runs `legacy:reconcile --expect-baseline` again, removes development accounts and transient tables exactly as for this
      release, re-runs the field-fidelity and integrity checks, and rehearses the undo of the merges on a disposable copy;
   6. builds the package with `build_release.py --data-sql <new SQL> --media-dir <folder with provider-photos/, type-icons/,
      featured-logos/>` (it refuses unless the media files are exactly the ones the SQL refers to) and produces a new
      reconciliation report (counts per source table, 0 unaccounted rows, 0 field differences except intentional repairs).
4. IT imports the **fresh** SQL into an empty production V2 database, copies the package's `storage/app/private` pictures,
   runs VERIFY_AND_SMOKE_TEST.md (with the counts of the NEW release, which the engineering team states in a replacement
   `DATABASE_IMPORT.md`), and King approves go-live. The old system stays read-only/stopped from writing; it remains the rollback.

## Decisions that carry over — and what does not

* Decisions in the reviewed manifest carry over only through step 3.3 above. Nothing is replayed automatically.
* Reviews done afterwards in the engineering workspace (pairs approved or rejected in V2) are stored only in that V2 database.
  Record them as manifest entries (source ids and reasons) so they can be re-verified and re-applied, or complete the reviews
  **once, on the final dataset**. Agree with King which applies before starting reviews.
* **After go-live V2 is the master record.** Do not rebuild or replace the V2 database from a new snapshot: pictures uploaded and
  edits made in V2 would be lost. Any later synchronization with the old system is a separate, agreed project.
