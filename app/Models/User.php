<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Api\Support\ApiPrincipal;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property Role|null $role
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Password value of an account that exists but has never been activated (ADR-016, migrated legacy staff).
     * It is a syntactically valid bcrypt string (cost 12), so the hasher never throws, but it can NEVER
     * verify: its 22nd salt character is one bcrypt cannot produce, so the recomputed hash always differs.
     * It is not derived from any password. Activation replaces it via `users:set-password` or a reset link.
     */
    public const ACTIVATION_REQUIRED_PASSWORD = '$2y$12$EuroAssistV2ActivationRequiredNoPasswordSetYet.LocKED';

    public function requiresActivation(): bool
    {
        return $this->getAttributes()['password'] === self::ACTIVATION_REQUIRED_PASSWORD;
    }

    /**
     * Role and activity are deliberately NOT mass assignable: privilege changes must go
     * through UserService, which enforces the last-admin rule and writes an audit event.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'password'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    public function hasPermission(Permission $permission): bool
    {
        return (bool) $this->is_active && ($this->role?->can($permission) ?? false);
    }

    public function isAdmin(): bool
    {
        return $this->is_active && $this->role === Role::Admin;
    }

    /**
     * End every existing way in for this account: "remember me" cookies, stored web sessions
     * (database driver; other drivers are covered by the AuthenticateSession middleware) and all
     * /api/v2 personal access tokens. Used on password reset and deactivation.
     */
    public function revokeAllAccess(): void
    {
        $this->forceFill(['remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $this->getKey())->delete();
        }

        PersonalAccessToken::query()
            ->where('tokenable_type', (new ApiPrincipal)->getMorphClass())
            ->where('tokenable_id', $this->getKey())
            ->delete();
    }
}
