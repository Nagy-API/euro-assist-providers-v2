<?php

namespace App\Enums;

/**
 * Permission keys from the owner-approved matrix (ADR-010 Amendment A, docs/08_SECURITY.md).
 * The keys are identifiers; the per-role effects are the approved business rule.
 */
enum Permission: string
{
    case ProvidersView = 'providers.view';
    case ProvidersCreate = 'providers.create';
    case ProvidersUpdate = 'providers.update';
    case ProvidersDelete = 'providers.delete';
    case ProvidersRestore = 'providers.restore';
    case ProvidersBulkWrite = 'providers.bulk.write';
    case ProvidersBulkDelete = 'providers.bulk.delete';
    case ProvidersClearValues = 'providers.clear_values'; // ADR-008 explicit clear: Admin only (default deny)
    case TypesManage = 'types.manage';
    case FeaturedManage = 'featured.manage';
    case ImportsRun = 'imports.run';
    case ExportsRun = 'exports.run';
    case JobsViewOwn = 'jobs.view.own';
    case JobsViewAny = 'jobs.view.any';
    case AuditView = 'audit.view';
    case UsersManage = 'users.manage';
    case SettingsManage = 'settings.manage';
    case MigrationReview = 'migration.review';
}
