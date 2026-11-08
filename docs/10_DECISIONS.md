# Architecture decision records (append-only)

Use `ADR-XXX`, date, status, context, decision, alternatives, consequences and revisit trigger. Never silently rewrite a prior accepted choice; supersede it.

## ADR-001 — Frozen V1 and untouched production

**Date:** 2026-11-08 | **Status:** Accepted by King.

**Decision:** Uploaded source and SQL are the final V1 baseline. V1 Production continues to run unmodified. V2 development uses a separate local environment and a controlled eventual cutover.

**Why:** Avoid data drift and outages during redesign. **Consequence:** Deployment/migration rehearsals must use isolated environments.

## ADR-002 — GitHub as durable project memory

**Date:** 2026-11-08 | **Status:** Accepted by King; remote creation pending.

**Decision:** Private `Nagy-API/euro-assist-providers-v2` as source of code, issues, decisions, status, work logs and AI handoff notes. Every task must update documentation and link its implementation/test evidence.

**Why:** Preserve context across conversations and collaborators. **Consequence:** Documentation changes are part of Definition of Done, not optional.

## ADR-003 — Candidate platform for V2

**Date:** 2026-11-08 | **Status:** Provisional target; compatibility assumed, not verified.

**Decision:** Prefer Laravel 13 / PHP >= 8.3, Livewire, Alpine.js, Tailwind CSS, MySQL/MariaDB and Plesk-compatible worker/scheduler. No Docker dependency.

**Alternatives considered:** Fix legacy Laravel in place; rewrite in Node.js/React; rewrite in Django/React. A separate new backend incurs a higher operational rewrite without established functional need.

**Revisit trigger:** failed local compatibility checks or explicit Plesk staging evidence; avoid redesign based on guesses.

## ADR-004 — Build import/export as separate resilient services

**Date:** 2026-11-08 | **Status:** Accepted direction, implementation pending.

**Decision:** Template schema with header mapping, staging/preview, per-row validation, controlled batch transactions, resumable jobs, idempotence and chunked export. Keep implementation independent of the queue runner.

**Consequence:** More initial engineering effort but much lower risk of taking down web requests or corrupting records.

## ADR-005 — Canonical provider + translations

**Date:** 2026-11-08 | **Status:** Proposed, final schema pending real-data matching audit.

**Decision:** Consider a canonical provider entity with separate locale translations and a legacy mapping table. Preserve both Arabic/English source rows during migration.

**Non-negotiable:** Numeric ID equality and same name do not prove a record pair. Uncertain matches require review.

## ADR-006 — Protect private data from Git

**Date:** 2026-11-08 | **Status:** Accepted.

**Decision:** Legacy dump/source secrets/real provider records and customer media stay out of all Git commits, even if repository is private. Use only schema notes and synthetic fixtures.

**Why:** Security and privacy; private repositories do not make copied credentials safe.
