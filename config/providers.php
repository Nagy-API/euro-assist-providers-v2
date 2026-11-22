<?php

return [

    /*
    | Supported content locales. The UI and the provider translations use the same set.
    */
    'locales' => ['ar', 'en'],
    'default_locale' => 'en',
    'rtl_locales' => ['ar'],

    'pagination' => [
        'default' => 25,
        'max' => 100,
    ],

    /*
    | Import/export limits. All values are configurable; defaults are provisional and must be
    | confirmed by the benchmark recorded in docs/04_IMPORT_EXPORT_SPEC.md.
    */
    'imports' => [
        'disk' => env('IMPORTS_DISK', 'local'),
        'directory' => 'imports',
        'max_file_bytes' => (int) env('IMPORTS_MAX_FILE_BYTES', 10 * 1024 * 1024),
        'max_rows' => (int) env('IMPORTS_MAX_ROWS', 20000),
        'chunk_size' => (int) env('IMPORTS_CHUNK_SIZE', 200),
        // Zip-bomb guard: maximum ratio of uncompressed to compressed size of an .xlsx.
        'max_uncompressed_bytes' => (int) env('IMPORTS_MAX_UNCOMPRESSED_BYTES', 100 * 1024 * 1024),
        // S1: measured while actually inflating each entry (not the declared size).
        'max_entry_bytes' => (int) env('IMPORTS_MAX_ENTRY_BYTES', 60 * 1024 * 1024),
        // S1: parser limits (template has < 30 columns; Excel's own cell limit is 32,767 chars).
        'max_columns' => (int) env('IMPORTS_MAX_COLUMNS', 100),
        'max_cell_chars' => (int) env('IMPORTS_MAX_CELL_CHARS', 32767),
        'retention_hours' => (int) env('IMPORTS_RETENTION_HOURS', 72),
        'error_report_limit' => 5000,
        // Rows parsed/staged per validation batch (one set of batched lookups + one bulk insert).
        'validation_chunk_size' => (int) env('IMPORTS_VALIDATION_CHUNK_SIZE', 500),
        // Zip-bomb guard: maximum uncompressed/compressed ratio (per entry larger than 1 MiB and overall)
        // and maximum number of archive entries. Sizes come from the central directory (never extracted).
        'max_zip_ratio' => (int) env('IMPORTS_MAX_ZIP_RATIO', 150),
        'max_zip_entries' => (int) env('IMPORTS_MAX_ZIP_ENTRIES', 500),
        // Rows shown per page on the preview screen (diffs and errors).
        'preview_per_page' => 25,
        // Global apply lock (Cache::lock on the configured store; null = default cache store, which must
        // support atomic locks: database/redis/memcached/array). Two imports never write concurrently.
        'lock_store' => env('IMPORTS_LOCK_STORE'),
        // Must outlive ApplyImportJob::$timeout (3600 s) so a redelivered job can never take the
        // lock while the first run still writes (security review L3).
        'lock_seconds' => (int) env('IMPORTS_LOCK_SECONDS', 3900),
        'lock_release_delay' => (int) env('IMPORTS_LOCK_RELEASE_DELAY', 20),
        // A job stuck in "applying" without progress for this many minutes may be retried by its owner.
        'stale_apply_minutes' => (int) env('IMPORTS_STALE_APPLY_MINUTES', 15),
    ],

    'exports' => [
        'disk' => env('EXPORTS_DISK', 'local'),
        'directory' => 'exports',
        'chunk_size' => (int) env('EXPORTS_CHUNK_SIZE', 500),
        'retention_hours' => (int) env('EXPORTS_RETENTION_HOURS', 24),
        // Export-all above this many providers must run on the queue; below it still uses the queue.
        'max_rows' => (int) env('EXPORTS_MAX_ROWS', 100000),
        // Maximum number of ids accepted for a "selected" export.
        'selected_max' => (int) env('EXPORTS_SELECTED_MAX', 20000),
    ],

    'featured' => [
        'disk' => env('FEATURED_DISK', 'local'),
        'directory' => 'featured-logos',
        'max_logo_kb' => 1024,
    ],

    /*
    | API. Public read exposure of provider data is OFF by default until the owner approves it
    | (D7). When off, the read endpoints require an authenticated session/token with providers.view.
    */
    'api' => [
        'public_read' => (bool) env('PROVIDERS_API_PUBLIC_READ', false),
        'rate_limit_per_minute' => (int) env('PROVIDERS_API_RATE_LIMIT', 60),
        // Per-IP ceiling checked BEFORE authentication (bounds invalid-token guessing).
        'ip_rate_limit_per_minute' => (int) env('PROVIDERS_API_IP_RATE_LIMIT', 300),
        'max_per_page' => 100,
        'default_per_page' => 25,
        'coordinates_max' => 5000,
        'coordinates_default_per_page' => 1000,
        // Personal access tokens expire; issued by an Admin with `php artisan api:token`.
        'token_ttl_days' => (int) env('PROVIDERS_API_TOKEN_TTL_DAYS', 90),
    ],

    'legacy' => [
        'mapping_version' => 'v1',
    ],
];
