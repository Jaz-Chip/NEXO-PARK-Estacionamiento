@props(['name' => 'dashboard'])
<svg {{ $attributes->class(['icon']) }} xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('dashboard')
            <rect x="3" y="3" width="7" height="7" rx="1"/>
            <rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="3" y="14" width="7" height="7" rx="1"/>
            <rect x="14" y="14" width="7" height="7" rx="1"/>
            @break
        @case('users')
            <circle cx="9" cy="7" r="3"/>
            <path d="M3 21v-2a6 6 0 0 1 12 0v2M16 4a3 3 0 0 1 0 6M21 21v-2a6 6 0 0 0-4-5"/>
            @break
        @case('card')
            <rect x="3" y="4" width="18" height="16" rx="2"/>
            <path d="M3 9h18M7 15h3M14 15h3"/>
            @break
        @case('car')
            <path d="m5 7 2-4h10l2 4M5 7l-2 4v7h18v-7l-2-4ZM3 11h18M6 18v3M18 18v3M6 14h2M16 14h2"/>
            @break
        @case('parking')
            <rect x="3" y="3" width="18" height="18" rx="3"/>
            <path d="M10 17V7h4a3 3 0 0 1 0 6h-4"/>
            @break
        @case('rate')
            <path d="M20 13 13 20 3 10V3h7ZM7 7h.01"/>
            @break
        @case('arrows')
            <path d="M3 7h18l-4-4M21 17H3l4 4M21 7l-4 4M3 17l4-4"/>
            @break
        @case('cash')
            <rect x="3" y="5" width="18" height="14" rx="2"/>
            <circle cx="12" cy="12" r="3"/>
            <path d="M6 12h.01M18 12h.01"/>
            @break
        @case('report')
            <path d="M4 3v18h17M8 16v-4M13 16V8M18 16V5"/>
            @break
        @case('check')
            <path d="m5 12 4 4L19 6"/>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9"/>
            <path d="M12 7v5l3 2"/>
            @break
    @endswitch
</svg>