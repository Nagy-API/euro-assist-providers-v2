<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Sidebar structure. Visibility here is cosmetic: every route and every Livewire action
 * enforces the same permission server-side. Items owned by other areas appear only when their route exists. Rarely used
 * tools (legacy migration review) live on the Advanced administration page, not in this menu.
 */
class NavigationMenu
{
    /**
     * Each item: [route name, label key under ui.nav, icon, permission(s) (any of), active pattern].
     *
     * @var list<array{section: string, route: string, label: string, icon: string, permission: list<string>, active: string}>
     */
    private const ITEMS = [
        ['section' => 'main', 'route' => 'dashboard', 'label' => 'dashboard', 'icon' => 'chart', 'permission' => ['providers.view'], 'active' => 'dashboard'],
        ['section' => 'providers', 'route' => 'providers.index', 'label' => 'providers', 'icon' => 'building', 'permission' => ['providers.view'], 'active' => 'providers.index|providers.show|providers.edit'],
        ['section' => 'providers', 'route' => 'providers.create', 'label' => 'add_provider', 'icon' => 'plus', 'permission' => ['providers.create'], 'active' => 'providers.create'],
        ['section' => 'providers', 'route' => 'types.index', 'label' => 'types', 'icon' => 'tag', 'permission' => ['types.manage'], 'active' => 'types.*'],
        ['section' => 'providers', 'route' => 'featured.index', 'label' => 'featured', 'icon' => 'star', 'permission' => ['providers.view'], 'active' => 'featured.*'],
        ['section' => 'data', 'route' => 'imports.index', 'label' => 'import', 'icon' => 'upload', 'permission' => ['imports.run'], 'active' => 'imports.*'],
        ['section' => 'data', 'route' => 'exports.index', 'label' => 'export', 'icon' => 'download', 'permission' => ['exports.run'], 'active' => 'exports.*'],
        ['section' => 'data', 'route' => 'jobs.index', 'label' => 'history', 'icon' => 'clock', 'permission' => ['jobs.view.own', 'jobs.view.any'], 'active' => 'jobs.*'],
        ['section' => 'admin', 'route' => 'users.index', 'label' => 'users', 'icon' => 'users', 'permission' => ['users.manage'], 'active' => 'users.*'],
        ['section' => 'admin', 'route' => 'audit.index', 'label' => 'audit', 'icon' => 'clipboard', 'permission' => ['audit.view'], 'active' => 'audit.*'],
        ['section' => 'admin', 'route' => 'settings.advanced', 'label' => 'advanced', 'icon' => 'arrows', 'permission' => ['settings.manage'], 'active' => 'settings.advanced|migration.*'],
        ['section' => 'admin', 'route' => 'settings.status', 'label' => 'system_status', 'icon' => 'server', 'permission' => ['settings.manage'], 'active' => 'settings.*'],
    ];

    /**
     * @return list<array{key: string, items: list<array{route: string, url: string, label: string, icon: string, current: bool}>}>
     */
    public function for(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $sections = [];
        foreach (self::ITEMS as $item) {
            if (! Route::has($item['route'])) {
                continue;
            }
            if (! collect($item['permission'])->contains(fn (string $p) => Gate::forUser($user)->allows($p))) {
                continue;
            }

            $sections[$item['section']][] = [
                'route' => $item['route'],
                'url' => route($item['route']),
                'label' => __('ui.nav.'.$item['label']),
                'icon' => $item['icon'],
                'current' => request()->routeIs(...explode('|', $item['active'])),
            ];
        }

        $out = [];
        foreach (['main', 'providers', 'data', 'admin'] as $key) {
            if (isset($sections[$key])) {
                $out[] = ['key' => $key, 'items' => $sections[$key]];
            }
        }

        return $out;
    }
}
