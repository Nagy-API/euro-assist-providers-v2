<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Self-service password change (account settings) and the forced replacement of a temporary
 * password (ADR-019 / ADR-020). The same form and policy serve both: an account flagged with
 * must_change_password sees the focused guest-style page and can use nothing else until it is
 * done; every other signed-in account reaches it from the account menu.
 */
class PasswordChangeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): View
    {
        return $request->user()->must_change_password
            ? view('auth.change-password')
            : view('account.password');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => ['required', 'string', 'max:1024', 'confirmed', 'different:current_password', UserService::passwordRule()],
        ]);

        if (! Hash::check($data['current_password'], $user->getAuthPassword())) {
            $this->audit->record('auth.password_change_failed', $user, 'failure', [], $user);

            throw ValidationException::withMessages(['current_password' => __('auth.current_password_wrong')]);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
            'remember_token' => Str::random(60), // old "remember me" cookies stop working
        ])->save();
        // Ends every other session and "remember me" cookie of this account; keeps this one.
        Auth::guard('web')->logoutOtherDevices($data['password']);
        $request->session()->regenerate();
        $this->audit->record('auth.password_changed', $user, 'success', [], $user);

        return redirect()->route('dashboard')->with('status', __('auth.password_changed'));
    }
}
