<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), config('providers.rtl_locales')) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-4 py-10">
        <div class="mb-6 text-center">
            <h1 class="text-xl font-semibold">{{ config('app.name') }}</h1>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            {{ $slot }}
        </div>
        <form method="POST" action="{{ route('locale.update', app()->getLocale() === 'ar' ? 'en' : 'ar') }}" class="mt-4 text-center">
            @csrf
            <button type="submit" class="text-sm text-slate-600 underline">{{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}</button>
        </form>
    </main>
</body>
</html>
