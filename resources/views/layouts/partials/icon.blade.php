@php
    $iconName = $name ?? 'circle';
    $iconClass = $class ?? 'luczor-nav-icon';
@endphp

<svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($iconName)
        @case('home')
            <path d="m3 10 9-7 9 7" />
            <path d="M5 9v11h14V9" />
            <path d="M9 20v-6h6v6" />
            @break
        @case('cloud')
            <path d="M17.5 19H7a5 5 0 1 1 1.35-9.81A6.5 6.5 0 0 1 20 13a4 4 0 0 1-2.5 6Z" />
            @break
        @case('cpu')
            <rect x="6" y="6" width="12" height="12" rx="2" />
            <rect x="9" y="9" width="6" height="6" rx="1" />
            <path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4" />
            @break
        @case('activity')
            <path d="M3 12h4l2.5-7 5 14 2.5-7h4" />
            @break
        @case('prompt')
            <rect x="3" y="4" width="18" height="16" rx="2" />
            <path d="m7 9 3 3-3 3M13 15h4" />
            @break
        @case('flask')
            <path d="M9 3h6M10 3v6l-5 8.5A2.3 2.3 0 0 0 7 21h10a2.3 2.3 0 0 0 2-3.5L14 9V3" />
            <path d="M7.5 15h9" />
            @break
        @case('workflow')
            <rect x="3" y="3" width="6" height="6" rx="1.5" />
            <rect x="15" y="15" width="6" height="6" rx="1.5" />
            <path d="M9 6h3a3 3 0 0 1 3 3v6M12 18h3" />
            @break
        @case('agents')
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
            @break
        @case('monitor')
            <rect x="3" y="4" width="18" height="13" rx="2" />
            <path d="M8 21h8M12 17v4" />
            @break
        @case('key')
            <circle cx="8" cy="15" r="4" />
            <path d="m11 12 9-9M16 7l3 3M14 9l2 2" />
            @break
        @case('archive')
            <path d="M4 7h16v14H4z" />
            <path d="M3 3h18v4H3zM9 11h6" />
            @break
        @case('settings')
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56V21h-4v-.08A1.7 1.7 0 0 0 8.97 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.52-1.03H3v-4h.08A1.7 1.7 0 0 0 4.6 8.97a1.7 1.7 0 0 0-.34-1.88l-.06-.06L7.03 4.2l.06.06A1.7 1.7 0 0 0 8.97 4.6 1.7 1.7 0 0 0 10 3.08V3h4v.08a1.7 1.7 0 0 0 1.03 1.52 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06a1.7 1.7 0 0 0-.34 1.88A1.7 1.7 0 0 0 20.92 10H21v4h-.08A1.7 1.7 0 0 0 19.4 15Z" />
            @break
        @case('terminal')
            <rect x="3" y="4" width="18" height="16" rx="2" />
            <path d="m7 9 3 3-3 3M13 15h4" />
            @break
        @case('smartphone')
            <rect x="7" y="2" width="10" height="20" rx="2" />
            <path d="M11 18h2" />
            @break
        @case('folder')
            <path d="M3 6a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" />
            @break
        @case('github')
            <path d="M15 22v-4a4.8 4.8 0 0 0-1-3.5c3.3-.37 6.8-1.62 6.8-7.4A5.8 5.8 0 0 0 19.3 3a5.4 5.4 0 0 0-.15-4S18-1.37 15 1.5a14 14 0 0 0-6 0C6-1.37 4.85-1 4.85-1A5.4 5.4 0 0 0 4.7 3a5.8 5.8 0 0 0-1.5 4.1c0 5.77 3.5 7 6.8 7.4A4.8 4.8 0 0 0 9 18v4" transform="translate(0 1) scale(.92)" />
            @break
        @case('link')
            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
            @break
        @case('user')
            <circle cx="12" cy="8" r="4" />
            <path d="M4 21a8 8 0 0 1 16 0" />
            @break
        @case('chevron-down')
            <path d="m7 10 5 5 5-5" />
            @break
        @default
            <circle cx="12" cy="12" r="8" />
    @endswitch
</svg>
