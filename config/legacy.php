<?php

/*
| Legacy migration pipeline configuration (docs/05_DATA_MIGRATION.md).
|
| Nothing here is secret. Connection credentials are read from LEGACY_DB_* environment variables
| by the read-only `legacy_audit` connection in config/database.php and are never committed.
*/

return [

    // LOCAL-ONLY (ADR-014): environments where the pipeline and pair review may run at all.
    // Production migration/review is NOT authorized; do not add staging/production here.
    'allowed_environments' => ['local', 'testing'],

    // Destination (application) databases the pipeline may write to: loopback host only, exact
    // names. Anything else (including a production database) is refused.
    'allowed_destination_databases' => explode(',', (string) env('LEGACY_ALLOWED_DESTINATIONS', 'euro_assist_v2_local,euro_assist_v2_rehearsal,euro_assist_v2_test')),

    // The frozen audit database the source connection must point at (no default: fail closed).
    'expected_source_database' => env('LEGACY_EXPECTED_DATABASE', ''),

    // Connection used to read the frozen audit database (SELECT only).
    'connection' => 'legacy_audit',

    // SHA-256 of the frozen SQL snapshot (recorded in docs/05_DATA_MIGRATION.md). Every staging
    // run must be given the same value through --snapshot-sha256 or it refuses to start.
    'snapshot_sha256' => env('LEGACY_SNAPSHOT_SHA256', '19a71fe301c1038b52473b76bec64d2a95ca606dd701c4a2054c72f5bb791814'),

    // Source tables and the locale each one represents. top_providers is brand-level (no locale).
    'sources' => [
        'providers' => ['locale' => 'en', 'kind' => 'provider'],
        'arabic_providers' => ['locale' => 'ar', 'kind' => 'provider'],
        'top_providers' => ['locale' => null, 'kind' => 'featured'],
    ],

    // Row counts recorded for the frozen snapshot (docs/05_DATA_MIGRATION.md). Used only by
    // `legacy:reconcile --expect-baseline`; the primary check compares with the live source.
    'baseline_counts' => [
        'providers' => 1090,
        'arabic_providers' => 1104,
        'top_providers' => 3,
        'types' => 30,
    ],

    'chunk_size' => 500,

    'suggestions' => [
        // A suggestion needs at least this many DISTINCT signal kinds to agree.
        'min_signals' => 2,
        // At most this many suggestions are kept per English and per Arabic source row.
        'max_per_row' => 3,
        // A candidate group (rows sharing one key) larger than this many EN x AR pairs is skipped
        // as a candidate generator: brand-wide phone numbers would otherwise explode (the frozen
        // snapshot has ~259k phone-equal pairs). Pairs reached through another signal are still
        // scored with every signal.
        'max_group_pairs' => 200,
        'insert_chunk' => 500,
    ],

    // Aggregate-only, sanitized reports (no names, phones, addresses, e-mails or URLs).
    'reports_path' => base_path('docs/legacy-reports'),
];
