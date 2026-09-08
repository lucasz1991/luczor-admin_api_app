@props(['navigation', 'layoutInitials', 'layoutRole'])
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
