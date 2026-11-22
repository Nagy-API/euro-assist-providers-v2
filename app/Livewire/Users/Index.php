<?php

namespace App\Livewire\Users;

use App\Enums\Role;
use App\Livewire\Concerns\HasNotices;
use App\Models\User;
use App\Services\UserService;
use App\Support\Like;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * User administration (Admin, users.manage): invite, change role, activate/deactivate.
 * Accounts are never deleted. All mutations go through UserService, which enforces the
 * last-administrator rule, locks rows, revokes sessions on deactivation and writes audit events.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use HasNotices, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** invite|manage|null */
    public ?string $dialog = null;

    #[Locked]
    public ?int $targetId = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'viewer';

    public string $manageRole = 'viewer';

    public bool $manageActive = true;

    public function paginationView(): string
    {
        return 'pagination.ui';
    }

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openInvite(): void
    {
        $this->authorize('create', User::class);
        $this->reset(['name', 'email']);
        $this->role = Role::Viewer->value;
        $this->resetErrorBag();
        $this->dialog = 'invite';
    }

    public function invite(UserService $users): void
    {
        $this->authorize('create', User::class);

        try {
            $user = $users->invite(auth()->user(), ['name' => $this->name, 'email' => $this->email, 'role' => $this->role]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->closeDialog();
        $this->notify(__('ui.users.invited', ['email' => $user->email]));
    }

    public function openManage(int $id): void
    {
        $target = User::query()->findOrFail($id);
        $this->authorize('update', $target);

        $this->targetId = $target->getKey();
        $this->manageRole = $target->role->value;
        $this->manageActive = (bool) $target->is_active;
        $this->resetErrorBag();
        $this->dialog = 'manage';
    }

    public function saveManage(UserService $users): void
    {
        $target = User::query()->findOrFail((int) $this->targetId);
        $this->authorize('update', $target);
        $actor = auth()->user();

        $role = Role::tryFrom($this->manageRole);
        if ($role === null) {
            $this->addError('manageRole', __('ui.users.invalid_role'));

            return;
        }

        $changed = false;
        try {
            if ($role !== $target->role) {
                $target = $users->changeRole($actor, $target, $role);
                $changed = true;
            }
            if ($this->manageActive !== (bool) $target->is_active) {
                $users->setActive($actor, $target, $this->manageActive);
                $changed = true;
            }
        } catch (ValidationException $e) {
            // The service's only validation failures here are the last-administrator rule: localize them.
            $errors = $e->errors();
            if (isset($errors['role'])) {
                $this->addError('manageRole', __('ui.users.last_admin_demote'));
            }
            if (isset($errors['is_active'])) {
                $this->addError('manageActive', __('ui.users.last_admin_deactivate'));
            }

            return;
        }

        $this->closeDialog();
        $this->notify($changed ? __('ui.users.updated') : __('ui.users.no_changes'), $changed ? 'success' : 'info');
    }

    public function closeDialog(): void
    {
        $this->dialog = null;
        $this->targetId = null;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()->orderBy('name')->orderBy('id');
        $term = mb_substr(trim($this->search), 0, 100);
        if ($term !== '') {
            $query->where(fn ($q) => Like::anyOf($q, ['name', 'email'], $term));
        }

        return view('livewire.users.index', [
            'users' => $query->paginate(25),
            'roles' => Role::cases(),
            'target' => $this->targetId !== null ? User::query()->find($this->targetId) : null,
            'me' => auth()->id(),
        ])->title(__('ui.nav.users'));
    }
}
