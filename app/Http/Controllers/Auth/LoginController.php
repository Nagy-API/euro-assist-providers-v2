<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        // is_active is part of the credential check: deactivated accounts cannot authenticate.
        $attempt = Auth::guard('web')->attempt(
            [
                'email' => mb_strtolower($credentials['email']),
                'password' => $credentials['password'],
                'is_active' => true,
            ],
            $request->boolean('remember'),
        );

        if (! $attempt) {
            $this->audit->record('auth.login_failed', null, 'failure');

            // One generic message: never reveal whether the account exists or is disabled.
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();
        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.login', $user, 'success', [], $user);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->audit->record('auth.logout', $user, 'success', [], $user);

        return redirect()->route('login');
    }
}
