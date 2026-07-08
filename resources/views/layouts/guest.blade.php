<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-luczor-bg text-luczor-text antialiased">
    <main class="flex min-h-screen items-center justify-center px-4 py-10">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center">
                <x-application-logo class="justify-center" />
                <p class="mt-3 text-sm text-slate-400">Admin API, Modellsteuerung und Brain-Sync fuer Luczor.</p>
            </div>
            <div class="luczor-card p-6">
                {{ $slot ?? '' }}
                @yield('content')
            </div>
        </div>
    </main>
</body>
</html>
