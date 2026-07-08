<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-luczor-bg text-luczor-text antialiased">
    <div class="min-h-screen">
        <header class="border-b border-cyan-300/10 bg-slate-950/70">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <a href="{{ route('dashboard') }}"><x-application-logo /></a>
                <nav class="flex items-center gap-4 text-sm text-slate-300">
                    <a class="hover:text-cyan-200" href="{{ route('profile.show') }}">Profil</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="hover:text-cyan-200" type="submit">Logout</button>
                    </form>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-6 py-8">
            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>
    @livewireScripts
</body>
</html>
