@php
    $groups = [];
    foreach (\App\Support\PermissionCatalog::navigation() as $module => $entries) {
        if ($module === 'dashboard') {
            continue;
        }

        $items = collect($entries)->filter(fn($item) => !empty($item['route']) && (($item['any_permission'] ?? false)
            ? auth()->user()->canAny($item['permissions'])
            : auth()->user()->can($item['permissions'][0])));

        // El boleto está disponible desde Operaciones; evitar enlaces repetidos.
        $items = $items->reject(fn($item) => $item['route'] === 'operations.tickets.index');

        if ($items->isNotEmpty()) {
            $groups[$module] = $items;
        }
    }

    $titles = [
        'gestion' => 'Gestión',
        'operaciones' => 'Operaciones',
        'reportes' => 'Reportes',
        'administracion' => 'Administración'
    ];

    $configResources = ['tipos-vehiculo', 'pisos', 'sectores', 'tarifas'];
@endphp

<nav id="permission-navigation" class="mt-1" aria-label="Navegación principal">
    <ul class="nav sidebar-menu flex-column" role="list">
        
        @can('dashboard.view')
            <li class="nav-item">
                <a href="{{ route('dashboard') }}" class="nav-link rail-home {{ request()->routeIs('dashboard') ? 'is-current' : '' }}">
                    <x-icon name="dashboard"/> 
                    <span>Monitor</span>
                </a>
            </li>
        @endcan

        @foreach($groups as $module => $items)
            <li class="rail-caption">{{ $titles[$module] ?? ucfirst($module) }}</li>

            @foreach($items as $item)
                @if($module !== 'gestion' || !in_array(explode('.', $item['route'])[1] ?? '', $configResources, true))
                    <li class="nav-item">
                        <a href="{{ route($item['route']) }}" class="nav-link rail-link {{ request()->routeIs($item['route']) ? 'is-current' : '' }}">
                            <span class="rail-dot"></span>
                            <span>{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endif
            @endforeach

            @if($module === 'gestion')
                @php
                    $setup = $items->filter(fn($item) => in_array(explode('.', $item['route'])[1] ?? '', $configResources, true));
                @endphp

                @if($setup->isNotEmpty())
                    <li class="nav-item">
                        <details class="rail-config" {{ $setup->contains(fn($i) => request()->routeIs($i['route'])) ? 'open' : '' }}>
                            <summary class="nav-link rail-link">
                                <x-icon name="parking"/> Configuración
                            </summary>
                            @foreach($setup as $item)
                                <a href="{{ route($item['route']) }}" class="nav-link rail-link rail-nested {{ request()->routeIs($item['route']) ? 'is-current' : '' }}">
                                    {{ $item['label'] }}
                                </a>
                            @endforeach
                        </details>
                    </li>
                @endif
            @endif

        @endforeach

    </ul>
</nav>