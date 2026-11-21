<x-layouts.guest :title="__('auth.forgot')">
    @if (session('status'))
        <p class="mb-4 rounded bg-green-50 p-3 text-sm text-green-800" role="status">{{ session('status') }}</p>
    @endif
    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf
        <div>
            <label for="email" class="mb-1 block text-sm font-medium">{{ __('auth.email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                   class="w-full rounded border border-slate-300 px-3 py-2">
            @error('email') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="w-full rounded bg-slate-900 px-4 py-2 font-medium text-white">{{ __('auth.send_link') }}</button>
        <p class="text-center text-sm"><a class="underline" href="{{ route('login') }}">{{ __('auth.back_to_login') }}</a></p>
    </form>
</x-layouts.guest>
