<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), config('providers.rtl_locales')) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 p-6">
    <h1 class="text-xl font-semibold">{{ config('app.name') }}</h1>
    <p>{{ auth()->user()->name }} ({{ auth()->user()->role->label() }})</p>
    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="underline">{{ __('auth.sign_out') }}</button></form>
</body>
</html>
