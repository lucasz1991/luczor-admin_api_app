@php
    $layoutUser = auth()->user();
    $layoutIsAdmin = ($layoutArea ?? null) === 'admin' || ($layoutUser?->isAdmin() ?? false);
    $currentAdminPage = request()->route('page');

    $adminNavigation = [
        'System' => [
            ['label' => 'Admin Control', 'href' => route('dashboard').'#admin-dashboard-panel-overview', 'icon' => 'home', 'active' => request()->routeIs('dashboard')],
            ['label' => 'Systemübersicht', 'href' => route('admin.page', 'overview'), 'icon' => 'activity', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'overview'],
            ['label' => 'Provider & Preise', 'href' => route('admin.page', 'providers'), 'icon' => 'cloud', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'providers'],
            ['label' => 'Modelle & Routing', 'href' => route('admin.page', 'models'), 'icon' => 'cpu', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'models'],
        ],
        'Optimierung' => [
            ['label' => 'Telemetrie & Kosten', 'href' => route('admin.page', 'telemetry'), 'icon' => 'activity', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'telemetry'],
            ['label' => 'Prompt & Kontext', 'href' => route('admin.page', 'optimizer'), 'icon' => 'prompt', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'optimizer'],
            ['label' => 'Experimente', 'href' => route('admin.page', 'experiments'), 'icon' => 'flask', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'experiments'],
        ],
        'Automation' => [
            ['label' => 'Workflows', 'href' => route('admin.page', 'workflows'), 'icon' => 'workflow', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'workflows'],
            ['label' => 'Agenten & Ereignisse', 'href' => route('admin.page', 'agents'), 'icon' => 'agents', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'agents'],
        ],
        'Betrieb' => [
            ['label' => 'Benutzer', 'href' => route('admin.users.index'), 'icon' => 'user', 'active' => request()->routeIs('admin.users.*')],
            ['label' => 'Lokale Modellstufen', 'href' => route('admin.local-models'), 'icon' => 'cpu', 'active' => request()->routeIs('admin.local-models')],
            ['label' => 'Mein Workspace', 'href' => route('account.workspace'), 'icon' => 'terminal', 'active' => request()->routeIs('account.workspace')],
            ['label' => 'Geräte-Debug', 'href' => route('admin.page', 'devices'), 'icon' => 'monitor', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'devices'],
            ['label' => 'Geräte & Keys', 'href' => route('admin.page', 'api-keys'), 'icon' => 'key', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'api-keys'],
            ['label' => 'Archive & Audit', 'href' => route('admin.page', 'archives'), 'icon' => 'archive', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'archives'],
            ['label' => 'Server Settings', 'href' => route('admin.page', 'settings'), 'icon' => 'settings', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'settings'],
        ],
    ];

    $customerNavigation = [
        'Luczor' => [
            ['label' => 'Chats & Steuerung', 'href' => route('account.workspace'), 'icon' => 'terminal', 'active' => request()->routeIs('account.workspace')],
            ['label' => 'Übersicht', 'href' => route('dashboard').'#member-dashboard-panel-overview', 'icon' => 'home', 'active' => request()->routeIs('dashboard')],
        ],
        'Arbeitsbereich' => [
            ['label' => 'Meine Geräte', 'href' => route('account.devices'), 'icon' => 'smartphone', 'active' => request()->routeIs('account.devices')],
            ['label' => 'Projekte & Aktivität', 'href' => route('dashboard').'#member-dashboard-panel-projects', 'icon' => 'folder', 'active' => false],
            ['label' => 'Verbindung erstellen', 'href' => route('dashboard').'#member-dashboard-panel-connect', 'icon' => 'link', 'active' => false],
        ],
        'Konto' => [
            ['label' => 'Profil', 'href' => route('profile.show'), 'icon' => 'user', 'active' => request()->routeIs('profile.show')],
        ],
    ];

    $navigation = $layoutIsAdmin ? $adminNavigation : $customerNavigation;
    $adminTitles = [
        'overview' => 'Systemübersicht',
        'providers' => 'Provider & Preise',
        'models' => 'Modelle & Routing',
        'telemetry' => 'Telemetrie & Kosten',
        'optimizer' => 'Prompt & Kontext',
        'experiments' => 'Experimente',
        'workflows' => 'Workflows',
        'agents' => 'Agenten & Ereignisse',
        'devices' => 'Geräte-Debug',
        'api-keys' => 'Geräte & Keys',
        'archives' => 'Archive & Audit',
        'settings' => 'Server Settings',
    ];
    $layoutTitle = match (true) {
        request()->routeIs('profile.show') => 'Profil & Konto',
        request()->routeIs('admin.users.index') => 'Benutzer',
        request()->routeIs('admin.users.show') => 'Benutzerprofil',
        request()->routeIs('admin.local-models') => 'Lokale Modellstufen',
        request()->routeIs('account.workspace') => 'Chats & Steuerung',
        request()->routeIs('account.devices') => 'Meine Geräte',
        default => $layoutIsAdmin ? ($adminTitles[$currentAdminPage] ?? 'Admin Control') : 'Mein Luczor',
    };
    $layoutEyebrow = $layoutIsAdmin ? 'Systemsteuerung' : 'Cloud Terminal';
    $layoutRole = $layoutIsAdmin ? 'Administrator' : 'Benutzer';
    $layoutInitials = collect(preg_split('/\s+/u', trim($layoutUser?->name ?? 'Luczor')))
        ->filter()
        ->take(2)
        ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="luczor-shell-document">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $layoutTitle }} | {{ config('app.name', 'Luczor') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body
    class="luczor-shell-body min-h-screen text-slate-100 antialiased"
    x-data="luczorShell"
    x-bind:class="{ 'sidebar-enable': mobileOpen, 'luczor-sidebar-expanded': desktopExpanded }"
    x-on:resize.window.debounce.150ms="handleResize()"
    x-on:keydown.escape.window="closeTransientUi(true)"
    x-on:touchstart.passive="handleTouchStart($event)"
    x-on:touchmove="handleTouchMove($event)"
    x-on:touchend.passive="handleTouchEnd($event)"
    x-on:touchcancel.passive="cancelTouchGesture()"
    x-on:click.capture="handleCapturedClick($event)"
>
    <a href="#main-content" class="luczor-skip-link">Zum Inhalt springen</a>

    <div class="luczor-shell min-h-screen">
        <x-ui.shell.topbar :layout-user="$layoutUser" :layout-is-admin="$layoutIsAdmin" :layout-title="$layoutTitle" :layout-eyebrow="$layoutEyebrow" :layout-initials="$layoutInitials" :layout-role="$layoutRole" />

        <x-ui.shell.navigation :navigation="$navigation" :layout-initials="$layoutInitials" :layout-role="$layoutRole" />

        <main id="main-content" class="luczor-main" tabindex="-1" x-bind:inert="isMobile && mobileOpen">
            <div class="luczor-shell-ambient" aria-hidden="true">
                <span class="luczor-shell-grid"></span>
                <span class="luczor-shell-orb luczor-shell-orb-one"></span>
                <span class="luczor-shell-orb luczor-shell-orb-two"></span>
                <span class="luczor-shell-rail"></span>
            </div>

            <div class="luczor-page-content">
                <div class="luczor-page-slot">
                    {{ $slot ?? '' }}
                    @yield('content')
                </div>

                <footer class="luczor-shell-footer">
                    <span>{{ config('app.name', 'Luczor') }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ $layoutIsAdmin ? 'Admin API & Control Plane' : 'Sichere Geräte- und Projektsynchronisation' }}</span>
                </footer>
            </div>
        </main>
    </div>

    @livewireScriptConfig
</body>
</html>
