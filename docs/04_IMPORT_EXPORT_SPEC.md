# Import / Export V2 — design contract, not implemented

## Intended behavior

### Import modes (explicit, never guessed)

1. **Create-only:** accepts new records only; duplicates are reported without changes.
2. **Update-only:** updates known records by stable identity; unknown IDs are rejected.
3. **Upsert:** creates or updates under strict matching policy after preview.
4. **Dry run:** validates input and presents exact proposed changes without touching canonical tables.

No implicit "replace all" behavior. If full replacement is ever requested, it requires a separate reviewed operation, full backup and explicit confirmation.

### Input contract

- Template includes version (`schema_version`), locale (`ar` or `en`), `provider_id` when updating, and stable English machine-readable column keys, independent of column ordering.
- Legacy template conversion is a separate adapter with documented, tested mapping.
- Identify encodings, reject macros/executables/unexpected extensions and oversized files; validate MIME/content and zip bomb limits.
- Trim/normalize values carefully: preserve phone numbers as **strings** (leading zeros, multiple numbers); preserve free-text discount/working hours until rules are agreed.
- Locale-specific columns (`name`, `address`, `specialization`) must map to the correct `provider_translations` record.
- **Owner-approved 2026-11-21:** absent column = no change; an empty cell in an update also preserves the existing value. Clearing an existing value requires a separate explicit operation, never an inferred blank-cell deletion. Create-mode required fields must still pass validation.
- Duplicate/matched providers must have stable identifiers; no automatic same-name match without confidence review.
- A user must see counts and differences for inserts, updates, duplicates, rejected rows and warnings before execution.
- **Owner-approved 2026-11-21:** any row-level validation errors block application of the entire file until corrected and revalidated. Preview/error reports remain available. Applying only the valid rows is not an available default or implicit fallback.

### Processing architecture

`Upload -> quarantine -> scan/validate -> parse in chunks -> stage normalized rows -> preview -> confirm -> queue chunk application -> reconciliation -> downloadable report -> expire/purge`.

- Raw input stays in private storage, with user authorization and limited retention.
- Use per-job database state (`import_jobs` and `import_rows` or equivalent); track `queued/running/failed/complete/cancelled` and progress safely.
- Store a file SHA-256 checksum and idempotency token. Duplicate submission should not apply the same side effect twice.
- Database transaction per **controlled chunk**, not an unbounded multi-hour transaction. Never promise global atomicity without designing a compensating strategy.
- Explicitly define partial commit policy and recovery: either rollback from checkpoints or deterministic resume with verified idempotency.
- Whole-file validation must finish before confirmation/application. This validation gate does not promise a single global database transaction: an infrastructure interruption after application starts may leave committed chunks. Show that state explicitly and resume deterministically from checkpoints without duplicate effects. Never label an interrupted partially applied job complete.
- Retry safe transient failures only. Row-level validation failures should not crash the worker.
- Log job ID, row numbers, error category and counts; never write full raw provider PII to public logs.
- Time limits, memory, file row caps and concurrency caps must be configurable; benchmarks determine defaults.
- Application-level progress must reflect committed work, not just parsed rows.

### Export contract

- Modes: all, current filters, selected IDs; export uses stable machine-readable headers and schema version.
- Snapshot/consistency behavior documented for concurrent edits; preserve exact locale, IDs and text types.
- Run expensive export asynchronously. Stream rows/chunks, use a temp file in private storage, provide an authenticated expiring download.
- Prevent spreadsheet formula injection for user-controlled strings beginning `=`, `+`, `-`, `@`, tabs or control characters where applicable.
- Export respects role/permission scope, logs who generated it, and cleans up old files.
- **Round-trip invariant:** `Export -> Import(dry run)` on unchanged data must yield zero unintended changes or mismatched columns.

### Non-negotiable automated tests

| Test | Expected |
|---|---|
| Import empty file | Clear validation error, no DB changes |
| Reordered/missing/duplicate columns | Correct mapping or explicit rejection |
| Arabic+English/mixed data | Correct `locale` and RTL-safe values |
| Re-import same file twice | No duplicate records or unexpected mutation |
| Update with unknown legacy ID | Rejected, not silently created |
| Partial invalid rows | Preview lists rejects; entire file blocked from application, zero canonical changes |
| Empty update cells / absent columns | Existing values preserved; clearing requires a separate explicit operation |
| Worker interrupted mid-batch | Safe resumability, correct total counts |
| Two overlapping imports | Locking/conflict handling prevents lost updates |
| Large synthetic file | Bounded peak memory, responsive UI |
| Formula-like string in export | Preserved as text and not executable formula |
| Export selected/filtered | Exactly requested IDs and locale |
| Export -> Import(dry run) | No unintended changes |
| Viewer attempts import/export | Role policy enforced |

**Definition of Done:** tests and benchmarks have recorded actual data, machine specs and results in a PR, not just an assertion that processing is "optimized".
