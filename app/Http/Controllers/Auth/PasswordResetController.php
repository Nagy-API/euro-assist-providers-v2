<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function requestForm(): View
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // Throttled by the broker; the response is identical whether or not the account exists.
        Password::sendResetLink(['email' => mb_strtolower((string) $request->input('email'))]);

        return back()->with('status', __('auth.reset_link_sent'));
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()],
        ]);

        $status = Password::reset(
            ['email' => mb_strtolower($data['email']), 'password' => $data['password'],
                'password_confirmation' => $request->input('password_confirmation'), 'token' => $data['token']],
            function ($user, string $password) {
                abort_unless($user->is_active, 403);
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
                $this->audit->record('auth.password_reset', $user, 'success', [], $user);
            }
        );

        return $status === Password::PasswordReset
            ? redirect()->route('login')->with('status', __('auth.password_updated'))
            : back()->withErrors(['email' => __('auth.reset_invalid')]);
    }
}
