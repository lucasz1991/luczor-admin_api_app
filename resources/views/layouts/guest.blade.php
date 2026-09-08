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
<body class="antialiased">
    <main class="ui-guest ui-enter">
        <section class="ui-guest__story">
            <x-application-logo />
            <h1>Dein Raum.<br>Deine Möglichkeiten.</h1>
            <p>Projekte, Erinnerungen und deine Geräte. Ein gemeinsamer Arbeitsbereich mit Luczor.</p>
        </section>
        <div class="ui-guest__form">
            <x-ui.panel>
                {{ $slot ?? '' }}
                @yield('content')
            </x-ui.panel>
            <p class="ui-guest__footer">Luczor · Persönlicher Workspace</p>
        </div>
    </main>
    @livewireScriptConfig
</body>
</html>
