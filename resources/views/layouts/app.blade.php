<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Inicio') · NEXO PARK</title>
    <!-- AdminLTE 4: plantilla MIT; respaldo visual local si no hay conexión. -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.10.0/dist/css/adminlte.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="variant-dark" data-bs-theme="dark">
    <div class="app-wrapper console-shell">
        
        <!-- Barra Lateral (Sidebar) -->
        <aside class="app-sidebar shadow console-rail" id="nexo-sidebar" aria-label="Menú principal">
            <div class="sidebar-brand">
                <a href="{{ route(auth()->user()->startRoute()) }}" class="console-brand">
                    <span class="console-brand-mark">NP</span>
                    <span>
                        <strong>NEXO PARK</strong>
                        <small>Consola operativa</small>
                    </span>
                </a>
            </div>

            <div class="console-rail-sub">MENÚ DE MÓDULOS</div>

            <div id="sidebar-menu" class="sidebar-wrapper console-rail-scroll">
                @include('layouts.partials.navigation')
            </div>

            <div class="console-footer">
                SISTEMA DISPONIBLE <span class="live-pulse"></span>
            </div>
        </aside>

        <!-- Contenido Principal -->
        <div class="app-main console-main">
            
            <!-- Barra Superior (Topbar) -->
            <header class="app-header navbar console-topbar">
                <div class="console-top-left">
                    <button type="button" class="btn console-menu-button" data-shell-toggle aria-expanded="false" aria-controls="nexo-sidebar" aria-label="Mostrar u ocultar menú">
                        ☰
                    </button>
                    <div>
                        <span class="console-top-label">ESTACIONAMIENTO / NEXO PARK</span>
                        <strong>Centro de control</strong>
                    </div>
                </div>

                <div class="console-user">
                    <div class="identity">
                        <strong>{{ auth()->user()->name }}</strong>
                        <small>{{ auth()->user()->role_label }}</small>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn btn-outline-secondary" type="submit">Salir</button>
                    </form>
                </div>
            </header>

            <!-- Área de Trabajo -->
            <div class="app-content console-work">
                <div class="console-page-head">
                    <div>
                        <p class="console-kicker">ÁREA DE TRABAJO</p>
                        <h1>@yield('title', 'Inicio')</h1>
                        <p>@yield('description', 'Control del estacionamiento')</p>
                    </div>
                    <span class="console-chips">● Sesión activa</span>
                </div>

                <main class="console-content">
                    @if(session('status'))
                        <div class="alert alert-success" role="status">{{ session('status') }}</div>
                    @endif

                    @if($errors->any())
                        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
                    @endif

                    @yield('content')
                </main>
            </div>

            <!-- Pie de página -->
            <footer class="app-footer console-bottom">
                NEXO PARK <span>Estacionamiento · Operación y reportes</span>
            </footer>

        </div>
    </div>

    <!-- Botón de capa flotante (Overlay) para móviles -->
    <button class="shell-overlay" data-shell-close type="button" aria-label="Cerrar menú"></button>
</body>
</html>