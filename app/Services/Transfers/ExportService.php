<?php

namespace App\Services\Transfers;

use App\Models\ExportJob;
use App\Models\User;
use LogicException;

/**
 * PLACEHOLDER with the frozen public signature. The import/export owner replaces the body.
 * Other code (e.g. the provider list) may call dispatch() but must not depend on its internals.
 */
class ExportService
{
    /**
     * Authorize (exports.run), validate, create an ExportJob and queue generation.
     *
     * @param  'all'|'filtered'|'selected'  $scope
     * @param  'ar'|'en'  $locale
     * @param  array<string, mixed>  $filters  provider list filters (search, type, city, district, status, trashed)
     * @param  list<int>  $selectedIds
     */
    public function dispatch(User $actor, string $scope, string $locale, array $filters = [], array $selectedIds = []): ExportJob
    {
        throw new LogicException('ExportService is not implemented yet.');
    }
}
