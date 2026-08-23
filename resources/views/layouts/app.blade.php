@php
    $layoutUser = auth()->user();
    $layoutIsAdmin = ($layoutArea ?? null) === 'admin' || ($layoutUser?->isAdmin() ?? false);
    $currentAdminPage = request()->route('page');

    $adminNavigation = [
        'System' => [
            ['label' => 'Admin Control', 'href' => route('dashboard'), 'icon' => 'home', 'active' => request()->routeIs('dashboard')],
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
            ['label' => 'Geräte-Debug', 'href' => route('admin.page', 'devices'), 'icon' => 'monitor', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'devices'],
            ['label' => 'Geräte & Keys', 'href' => route('admin.page', 'api-keys'), 'icon' => 'key', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'api-keys'],
            ['label' => 'Archive & Audit', 'href' => route('admin.page', 'archives'), 'icon' => 'archive', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'archives'],
            ['label' => 'Server Settings', 'href' => route('admin.page', 'settings'), 'icon' => 'settings', 'active' => request()->routeIs('admin.page') && $currentAdminPage === 'settings'],
        ],
    ];

    $customerNavigation = [
        'Luczor' => [
            ['label' => 'Terminal', 'href' => route('dashboard'), 'icon' => 'terminal', 'active' => request()->routeIs('dashboard')],
        ],
        'Arbeitsbereich' => [
            ['label' => 'Meine Geräte', 'href' => route('dashboard').'#devices', 'icon' => 'smartphone', 'active' => false],
            ['label' => 'Meine Projekte', 'href' => route('dashboard').'#projects', 'icon' => 'folder', 'active' => false],
            ['label' => 'GitHub / Cloud', 'href' => route('dashboard').'#github', 'icon' => 'github', 'active' => false],
            ['label' => 'Verbindung erstellen', 'href' => route('dashboard').'#connect', 'icon' => 'link', 'active' => false],
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
    $layoutTitle = request()->routeIs('profile.show')
        ? 'Profil & Konto'
        : ($layoutIsAdmin ? ($adminTitles[$currentAdminPage] ?? 'Admin Control') : 'Mein Luczor');
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
    class="luczor-shell-body min-h-screen bg-[#050b12] text-slate-100 antialiased"
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
        <header class="luczor-topbar" data-luczor-topbar>
            <div
                class="luczor-topbar-brand"
                x-bind:inert="isMobile && mobileOpen"
                x-on:mouseenter="scheduleDesktopExpansion(true)"
                x-on:mouseleave="scheduleDesktopExpansion(false)"
            >
                <a href="{{ route('dashboard') }}" class="luczor-brand-link" aria-label="Luczor Dashboard">
                    <span class="luczor-brand-mark" aria-hidden="true">LZ</span>
                    <span class="luczor-brand-copy">
                        <span class="luczor-brand-name">Luczor</span>
                        <span class="luczor-brand-subtitle">{{ $layoutIsAdmin ? 'Admin Control Plane' : 'Cloud Terminal' }}</span>
                    </span>
                </a>
            </div>

            <button
                type="button"
                id="vertical-menu-btn"
                class="vertical-menu-btn luczor-mobile-menu-button"
                x-ref="mobileToggle"
                x-on:click="toggleMobile()"
                x-bind:aria-expanded="mobileOpen.toString()"
                aria-controls="app-sidebar"
                aria-label="Navigation öffnen oder schließen"
            >
                <span class="luczor-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span>
            </button>

            <div class="luczor-topbar-context" x-bind:inert="isMobile && mobileOpen">
                <div class="luczor-page-context">
                    <span class="luczor-page-eyebrow">{{ $layoutEyebrow }}</span>
                    <span class="luczor-page-title">{{ $layoutTitle }}</span>
                </div>

                <div class="luczor-topbar-actions">
                    @if ($layoutIsAdmin)
                        <a
                            href="{{ route('admin.page', 'settings') }}"
                            class="luczor-topbar-control {{ request()->routeIs('admin.page') && $currentAdminPage === 'settings' ? 'is-active' : '' }}"
                            aria-label="Server Settings"
                            title="Server Settings"
                        >
                            @include('layouts.partials.icon', ['name' => 'settings', 'class' => 'h-[18px] w-[18px]'])
                        </a>
                    @endif

                    <div class="luczor-profile-menu" x-on:click.outside="profileOpen = false">
                        <button
                            type="button"
                            class="luczor-profile-trigger"
                            x-ref="profileTrigger"
                            x-on:click="profileOpen = ! profileOpen"
                            x-bind:aria-expanded="profileOpen.toString()"
                            x-bind:aria-label="profileOpen ? 'Profilmenü schließen' : 'Profilmenü öffnen'"
                            aria-controls="luczor-profile-dropdown"
                        >
                            <span class="luczor-avatar">{{ $layoutInitials ?: 'LZ' }}</span>
                            <span class="luczor-profile-copy">
                                <span class="luczor-profile-name">{{ $layoutUser?->name ?? 'Luczor' }}</span>
                                <span class="luczor-profile-role">{{ $layoutRole }}</span>
                            </span>
                            @include('layouts.partials.icon', ['name' => 'chevron-down', 'class' => 'luczor-profile-chevron'])
                        </button>

                        <div
                            id="luczor-profile-dropdown"
                            class="luczor-profile-dropdown"
                            x-cloak
                            x-show="profileOpen"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 translate-y-1 scale-[0.98]"
                            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                            x-transition:leave-end="opacity-0 translate-y-1 scale-[0.98]"
                        >
                            <div class="luczor-profile-summary">
                                <span class="luczor-avatar luczor-avatar-large">{{ $layoutInitials ?: 'LZ' }}</span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-semibold text-slate-100">{{ $layoutUser?->name ?? 'Luczor' }}</span>
                                    <span class="mt-0.5 block truncate text-xs text-slate-500">{{ $layoutUser?->email }}</span>
                                </span>
                            </div>

                            <a href="{{ route('profile.show') }}" class="luczor-profile-action" x-on:click="closeTransientUi()">
                                @include('layouts.partials.icon', ['name' => 'user'])
                                <span>Profil verwalten</span>
                            </a>

                            @if ($layoutIsAdmin)
                                <a href="{{ route('admin.page', 'settings') }}" class="luczor-profile-action" x-on:click="closeTransientUi()">
                                    @include('layouts.partials.icon', ['name' => 'settings'])
                                    <span>Server Settings</span>
                                </a>
                            @endif

                            <div class="luczor-profile-divider"></div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="luczor-profile-action luczor-profile-action-danger">
                                    <svg class="luczor-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M10 17l5-5-5-5M15 12H3" />
                                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                                    </svg>
                                    <span>Abmelden</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <button
            type="button"
            class="luczor-mobile-sidebar-backdrop"
            x-cloak
            x-show="mobileOpen"
            x-transition.opacity
            x-on:click="closeMobile(true)"
            aria-label="Navigation schließen"
            tabindex="-1"
        ></button>

        <aside
            id="app-sidebar"
            class="luczor-sidebar vertical-menu"
            x-ref="sidebar"
            x-bind:inert="isMobile && ! mobileOpen"
            x-on:mouseenter="scheduleDesktopExpansion(true)"
            x-on:mouseleave="scheduleDesktopExpansion(false)"
            x-on:focusin="expandDesktopForFocus()"
            x-on:focusout="handleSidebarFocusOut($event)"
            aria-label="Hauptnavigation"
            data-luczor-sidebar
        >
            <nav class="luczor-sidebar-scroll" aria-label="Luczor Navigation">
                @foreach ($navigation as $group => $items)
                    <section class="luczor-nav-group" aria-labelledby="luczor-nav-group-{{ $loop->index }}">
                        <h2 id="luczor-nav-group-{{ $loop->index }}" class="luczor-nav-group-label">{{ $group }}</h2>
                        <div class="luczor-nav-list">
                            @foreach ($items as $item)
                                <a
                                    href="{{ $item['href'] }}"
                                    class="luczor-nav-link {{ $item['active'] ? 'is-active' : '' }}"
                                    title="{{ $item['label'] }}"
                                    @if ($item['active']) aria-current="page" @endif
                                    x-on:click="closeMobile()"
                                >
                                    <span class="luczor-nav-icon-wrap">
                                        @include('layouts.partials.icon', ['name' => $item['icon']])
                                    </span>
                                    <span class="luczor-nav-label">{{ $item['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </nav>

            <div class="luczor-sidebar-footer">
                <span class="luczor-sidebar-user-avatar">{{ $layoutInitials ?: 'LZ' }}</span>
                <span class="luczor-sidebar-user-copy">
                    <span class="luczor-sidebar-user-role">{{ $layoutRole }}</span>
                    <span class="luczor-sidebar-user-state">Sicher angemeldet</span>
                </span>
            </div>
        </aside>

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
