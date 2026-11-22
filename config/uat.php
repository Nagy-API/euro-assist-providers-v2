<?php

/*
| Owner UAT environment (start-uat.ps1, stop-uat.ps1, reset-uat.ps1; docs/16_UAT_CHECKLIST.md).
|
| The UAT database name is deliberately NOT configurable through the environment: the scripts and
| `php artisan uat:check` only ever accept this exact local database.
*/

return [
    'database' => 'euro_assist_v2_uat',

    // Databases UAT tooling must never target.
    'protected_databases' => [
        'euro_assist_v2_local',             // preserved migration reference
        'euro_assist_legacy_audit_20261121', // frozen legacy restore
        'euro_assist_v2_test',
        'euro_assist_v2_rehearsal',
    ],
];
