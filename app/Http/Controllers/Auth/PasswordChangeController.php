<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Forced replacement of a temporary password (ADR-019). Only an account flagged with
 * must_change_password can use it; everyone else is sent to the dashboard.
 */
class PasswordChangeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->must_change_password) {
            return redirect()->route('dashboard');
        }

        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => ['required', 'string', 'max:1024', 'confirmed', 'different:current_password', PasswordRule::min(12)->letters()->numbers()],
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
