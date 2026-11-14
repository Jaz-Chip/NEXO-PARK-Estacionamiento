<?php

return ['users' => [
    'administrador' => ['name' => 'Administrador demo', 'email' => env('DEMO_ADMIN_EMAIL', 'admin@estacionamiento.test'), 'password' => env('DEMO_ADMIN_PASSWORD')],
    'operador' => ['name' => 'Operador demo', 'email' => env('DEMO_OPERATOR_EMAIL', 'operador@estacionamiento.test'), 'password' => env('DEMO_OPERATOR_PASSWORD')],
    'supervisor' => ['name' => 'Supervisor demo', 'email' => env('DEMO_SUPERVISOR_EMAIL', 'supervisor@estacionamiento.test'), 'password' => env('DEMO_SUPERVISOR_PASSWORD')],
]];
