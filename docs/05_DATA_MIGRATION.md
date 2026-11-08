# Data model and migration — proposal requiring verification

## Legacy schema (from uploaded SQL; schema names only)

- `providers`: id, name, type, address, city, district, discount_pct, hours, phone, email, website, map_url, logo_path, specialization, package, is_translated, created_at, updated_at.
- `arabic_providers`: same provider-detail fields without `is_translated`.
- `top_providers`: id, name_english, name_arabic, logo_path, type_en, type_ar, created_at, updated_at.
- `types`: id, type_key, name_en, name_ar, created_at, updated_at.
- `users`: id, name, email, email_verified_at, password, role, remember_token and timestamps.

## Proposed normalized V2 schema (draft)

- `providers` — canonical stable UUID/integer identifier, shared status, primary contact/business data where genuinely language-independent.
- `provider_translations` — provider_id + locale uniqueness; name/address/specialization and other genuinely localized fields. Consider localized city/district presentation separately.
- `provider_types` and `provider_type_translations` — stable machine keys, localized labels.
- `featured_providers` — canonical provider relationship, ranking, logo/assets; legacy Top Provider mapping only when canonical match verified.
- `provider_contacts` / `provider_locations` — optional if audits prove multi-contact/multi-location relationships are needed; do not prematurely normalize all legacy fields.
- `legacy_id_map` — source table, source ID, canonical provider ID, match method, reviewer, confidence/status and timestamps.
- `import_jobs`, `import_job_rows`, `export_jobs`, `audit_events`, `users`, role/permission tables.

## Migration rules

1. Import legacy SQL into a strictly separate local DB and confirm table counts; never point `DB_HOST`/credentials to production.
2. Retain original source ID and table in `legacy_id_map` even if new IDs differ.
3. Do **not** join Arabic/English records by equal numeric ID, or name alone. Evaluate multiple signals (business identifiers, name normalization, address, phone, map coordinates); ambiguous results go to manual review.
4. Preserve the original language-specific text. A blank English record does not mean translated Arabic can be silently overwritten.
5. Preserve phone and discount fields as lossless strings until business-specific data normalization is approved.
6. Use explicit dry-run diff and migration reports; store reports only as anonymized aggregates in Git.
7. Detect potential duplicate branches versus duplicated entities. Don't collapse multiple locations sharing the same brand name.
8. Test FK integrity, translation completeness, total mapped source rows, unresolved/ambiguous cases and reversibility.
9. Document how to import legacy photos/media into isolated private storage if explicitly approved; no uploads in Git.
10. Produce a repeatable migration script and versioned migration checksum to verify exact source snapshot (checksum itself okay to document if generated without exposing content).

## Acceptance report fields

Counts per legacy source table; mapped/new/duplicate candidates; unresolved and manually accepted matches; missing/invalid required fields; orphaned media; type normalization changes; package/discount handling; API field compatibility; reconciliation totals; migration script commit SHA and execution logs.

**Current counts:** not locked until independently recomputed on isolated local DB. Never represent a count as verified merely because it was mentioned in a conversation.
