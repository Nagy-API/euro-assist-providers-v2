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
 * User administration (Admin, users.manage): create with a password, reset a password, change role,
 * activate/deactivate. No e-mail is involved anywhere (ADR-020).
 * Accounts are never deleted. All mutations go through UserService, which enforces the
 * last-administrator rule, locks rows, revokes sessions on deactivation and writes audit events.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use HasNotices, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** create|manage|reset|null */
    public ?string $dialog = null;

    #[Locked]
    public ?int $targetId = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'viewer';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $mustChange = true;

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

    public function openCreate(): void
    {
        $this->authorize('create', User::class);
        $this->reset(['name', 'email', 'password', 'password_confirmation']);
        $this->role = Role::Viewer->value;
        $this->mustChange = true;
        $this->resetErrorBag();
        $this->dialog = 'create';
    }

    public function create(UserService $users): void
    {
        $this->authorize('create', User::class);

        try {
            $user = $users->create(auth()->user(), [
                'name' => $this->name, 'email' => $this->email, 'role' => $this->role,
                'password' => $this->password, 'password_confirmation' => $this->password_confirmation,
                'must_change_password' => $this->mustChange,
            ]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->closeDialog();
        $this->notify(__('ui.users.created', ['email' => $user->email]));
    }

    public function openReset(int $id): void
    {
        $target = User::query()->findOrFail($id);
        $this->authorize('update', $target);

        $this->reset(['password', 'password_confirmation']);
        $this->mustChange = true;
        $this->targetId = $target->getKey();
        $this->resetErrorBag();
        $this->dialog = 'reset';
    }

    public function resetPassword(UserService $users): void
    {
        $target = User::query()->findOrFail((int) $this->targetId);
        $this->authorize('update', $target);

        try {
            $users->resetPassword(auth()->user(), $target, [
                'password' => $this->password, 'password_confirmation' => $this->password_confirmation,
                'must_change_password' => $this->mustChange,
            ]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->closeDialog();
        $this->notify(__('ui.users.password_reset_done', ['name' => $target->name]));
    }

    public function openManage(int $id): void
    {
        $target = User::query()->findOrFail($id);
        $this->authorize('update', $target);

        $this->targetId = $target->getKey();
        $this->manageRole = ($target->role ?? Role::Viewer)->value;
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
        // Never keep a typed password in component state once the dialog is gone.
        $this->reset(['password', 'password_confirmation']);
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
