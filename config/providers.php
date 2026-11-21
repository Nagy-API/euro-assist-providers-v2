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
        'retention_hours' => (int) env('IMPORTS_RETENTION_HOURS', 72),
        'error_report_limit' => 5000,
    ],

    'exports' => [
        'disk' => env('EXPORTS_DISK', 'local'),
        'directory' => 'exports',
        'chunk_size' => (int) env('EXPORTS_CHUNK_SIZE', 500),
        'retention_hours' => (int) env('EXPORTS_RETENTION_HOURS', 24),
        // Export-all above this many providers must run on the queue; below it still uses the queue.
        'max_rows' => (int) env('EXPORTS_MAX_ROWS', 100000),
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
        'max_per_page' => 100,
        'default_per_page' => 25,
        'coordinates_max' => 5000,
    ],

    'legacy' => [
        'mapping_version' => 'v1',
    ],
];
