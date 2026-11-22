<?php

namespace App\Services\Transfers;

use App\Models\ExportJob;
use App\Models\ImportJob;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Gate;

/**
 * Single place that decides how a denied job request is answered, so controllers, Livewire pages
 * and downloads stay consistent:
 *  - lacks the role permission (Viewer)            -> 403
 *  - may use jobs but this job is not theirs      -> 404 (existence is not disclosed)
 *  - may view but not manage (not the owner/Admin) -> 403
 */
final class JobAccess
{
    public static function view(User $user, ImportJob|ExportJob $job): void
    {
        if (! Gate::forUser($user)->allows('viewAny', $job::class)) {
            self::deny($user, $job, 403);
        }
        if (! Gate::forUser($user)->allows('view', $job)) {
            self::deny($user, $job, 404);
        }
    }

    public static function viewAny(User $user, string $modelClass): void
    {
        if (! Gate::forUser($user)->allows('viewAny', $modelClass)) {
            self::deny($user, null, 403);
        }
    }

    public static function manage(User $user, ImportJob $job): void
    {
        self::view($user, $job);
        if (! Gate::forUser($user)->allows('manage', $job)) {
            self::deny($user, $job, 403);
        }
    }

    public static function create(User $user, string $modelClass): void
    {
        if (! Gate::forUser($user)->allows('create', $modelClass)) {
            self::deny($user, null, 403);
        }
    }

    private static function deny(User $user, ImportJob|ExportJob|null $job, int $status): never
    {
        app(AuditLogger::class)->record('authorization.denied', $job, 'denied', ['http' => $status], $user);
        abort($status);
    }
}
