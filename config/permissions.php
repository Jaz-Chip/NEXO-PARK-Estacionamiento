<?php

// The keys are stable API identifiers. Assignments live in role_permission.
return [
    'modules' => [
        'dashboard' => 'Dashboard',
        'gestion' => 'Gestión',
        'operaciones' => 'Operaciones',
        'reportes' => 'Reportes',
        'administracion' => 'Administración',
    ],
    'resources' => [
        'dashboard' => ['label' => 'Indicadores generales', 'module' => 'dashboard', 'route' => 'dashboard', 'actions' => ['view' => 'Consultar']],
        'clientes' => ['label' => 'Clientes', 'module' => 'gestion'],
        'abonados' => ['label' => 'Abonados', 'module' => 'gestion'],
        'vehiculos' => ['label' => 'Vehículos', 'module' => 'gestion'],
        'tipos-vehiculo' => ['label' => 'Tipos de vehículos', 'module' => 'gestion'],
        'pisos' => ['label' => 'Pisos', 'module' => 'gestion'],
        'sectores' => ['label' => 'Sectores', 'module' => 'gestion'],
        'espacios' => ['label' => 'Espacios', 'module' => 'gestion'],
        'tarifas' => ['label' => 'Tarifas', 'module' => 'gestion'],
        'entradas' => ['label' => 'Entradas', 'module' => 'operaciones', 'route' => 'operations.entradas.index', 'actions' => ['view' => 'Consultar', 'create' => 'Registrar entrada']],
        'salidas' => ['label' => 'Salidas', 'module' => 'operaciones', 'route' => 'operations.salidas.index', 'actions' => ['view' => 'Consultar', 'execute' => 'Registrar salida']],
        'tickets' => ['label' => 'Tickets', 'module' => 'operaciones', 'route' => 'operations.tickets.index', 'pending_actions' => ['cancel'], 'actions' => ['view' => 'Consultar', 'reprint' => 'Reimprimir', 'cancel' => 'Anular']],
        'caja' => ['label' => 'Caja y pagos', 'module' => 'operaciones', 'route' => 'operations.caja.index', 'pending_actions' => ['close'], 'actions' => ['view' => 'Consultar', 'charge' => 'Cobrar', 'close' => 'Cerrar caja']],
        'estadisticas' => ['label' => 'Estadísticas', 'module' => 'reportes', 'pending' => false, 'actions' => ['view' => 'Consultar']],
        'ingresos' => ['label' => 'Ingresos', 'module' => 'reportes', 'pending' => false, 'actions' => ['view' => 'Consultar']],
        'ocupacion' => ['label' => 'Ocupación', 'module' => 'reportes', 'pending' => false, 'actions' => ['view' => 'Consultar']],
        'exportaciones' => ['label' => 'Exportaciones PDF y Excel', 'module' => 'reportes', 'pending' => false, 'actions' => ['pdf' => 'Exportar PDF', 'excel' => 'Exportar Excel']],
        'usuarios' => ['label' => 'Usuarios', 'module' => 'administracion', 'route' => 'admin.users.index', 'actions' => ['view' => 'Consultar', 'status' => 'Activar / desactivar']],
        'roles' => ['label' => 'Roles y permisos', 'module' => 'administracion', 'route' => 'admin.roles.index', 'actions' => ['view' => 'Consultar', 'manage' => 'Configurar permisos']],
    ],
    'catalog_actions' => ['view' => 'Consultar', 'create' => 'Registrar', 'update' => 'Editar', 'status' => 'Activar / desactivar'],
];
