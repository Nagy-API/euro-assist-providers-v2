# Fresh data before production (REQUIRED)

The SQL file in this package was built from the **frozen snapshot** of the old system
(`Providers.sql`, SHA-256 `19a71fe301c1038b52473b76bec64d2a95ca606dd701c4a2054c72f5bb791814`). Any provider added,
changed or deleted in the old system after that snapshot is **not** in it.

**Never load this older SQL into production as if it were current.** Use it for staging and acceptance only.

## Procedure (agree a date with King; production is read-only during the export)

1. **Freeze writes** in the old system for the cutover window (announce to staff; no edits, no Excel imports).
2. IT exports the old production database to a new file (`mysqldump --single-transaction … > Providers-<date>.sql`)
   with a read-only account, records its SHA-256, and hands it to the engineering team through a private channel.
3. Engineering (local, isolated machine — the migration tooling refuses to run against production, ADR-014):
   * restores that file into a new local audit database with a SELECT-only reader account;
   * records the new file's SHA-256 in the private environment (`LEGACY_SNAPSHOT_SHA256=<new sha>`; the default in
     `config/legacy.php` is the frozen snapshot and would refuse the new file), updates the expected row counts
     (`legacy.baseline_counts`) from the new dump, and runs into a new empty release database:
     `legacy:preflight --snapshot-sha256=<new sha>` → `legacy:stage --snapshot-sha256=<new sha>` →
     `legacy:map-types --from-legacy` → `legacy:apply --actor=<temporary local admin> --accept-provisional-types`
     (omit the flag if King rejected the provisional labels) → `legacy:suggest-pairs` → `legacy:reconcile --expect-baseline`
     → `legacy:import-users` (activation policy: identities and roles only, never the old passwords; report the account count to King)
     (the same steps that produced this package; see `docs/18_RELEASE_PACKAGE.md` in the source repository);
   * removes development accounts and transient tables exactly as for this release, re-runs the field-fidelity and
     integrity checks, and produces `EuroAssist_Providers_V2_DATA_<new version>.sql` with a new reconciliation
     report (counts per source table, 0 unaccounted rows, 0 field differences).
4. IT imports the **fresh** SQL into an empty production V2 database, runs VERIFY_AND_SMOKE_TEST.md, and King
   approves go-live. The old system stays read-only/stopped from writing; it remains the rollback.

## Decisions that carry over

Owner decisions taken on the review queues (pairs, held rows, type labels) are recorded in the V2 database
(`legacy_pair_suggestions`, `legacy_staging_rows`). Reviews done on staging must be re-applied after a fresh
sync, or completed only once on the final dataset. Agree with King which applies before starting reviews.
