<x-layouts.guest :title="__('auth.sign_in')">
    @if (session('status'))
        <p class="mb-4 rounded bg-green-50 p-3 text-sm text-green-800" role="status">{{ session('status') }}</p>
    @endif
    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf
        <div>
            <label for="email" class="mb-1 block text-sm font-medium">{{ __('auth.email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="w-full rounded border border-slate-300 px-3 py-2">
            @error('email') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password" class="mb-1 block text-sm font-medium">{{ __('auth.password') }}</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="w-full rounded border border-slate-300 px-3 py-2">
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="remember" value="1"> {{ __('auth.remember') }}
        </label>
        <button type="submit" class="w-full rounded bg-slate-900 px-4 py-2 font-medium text-white">{{ __('auth.sign_in') }}</button>
        <p class="text-center text-sm"><a class="underline" href="{{ route('password.request') }}">{{ __('auth.forgot') }}</a></p>
    </form>
</x-layouts.guest>
