@props(['layoutUser', 'layoutIsAdmin', 'layoutTitle', 'layoutEyebrow', 'layoutInitials', 'layoutRole'])
@php($currentAdminPage = request()->route('page'))
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
