<?php

namespace App\Support;

class PermissionCatalog
{
    public static function definitions(): array
    {
        $permissions = [];
        foreach (config('permissions.resources') as $resource => $settings) {
            foreach ($settings['actions'] ?? config('permissions.catalog_actions') as $action => $label) {
                $permissions[$resource.'.'.$action] = [
                    'module' => $settings['module'],
                    'name' => $settings['label'].' — '.$label,
                    'is_pending' => ($settings['pending'] ?? false) || in_array($action, $settings['pending_actions'] ?? [], true),
                ];
            }
        }

        return $permissions;
    }

    public static function defaults(string $role): array
    {
        if ($role === 'administrador') {
            return array_keys(self::definitions());
        }
        if (! in_array($role, ['operador', 'supervisor'], true)) {
            return [];
        }

        $keys = ['dashboard.view'];
        foreach (config('permissions.resources') as $resource => $settings) {
            if ($settings['module'] !== 'gestion') {
                continue;
            }
            $keys[] = $resource.'.view';
            if ($role === 'operador' && in_array($resource, ['clientes', 'abonados', 'vehiculos', 'espacios'], true)) {
                foreach (['create', 'update', 'status'] as $action) {
                    $keys[] = $resource.'.'.$action;
                }
            }
        }

        return $keys;
    }

    public static function navigation(): array
    {
        $groups = [];
        foreach (config('permissions.resources') as $resource => $settings) {
            if ($settings['module'] === 'reportes') {
                $groups['reportes'] = [[
                    'label' => 'Reportes y estadísticas', 'route' => 'reports.index',
                    'permissions' => ['ingresos.view', 'estadisticas.view', 'ocupacion.view'],
                    'any_permission' => true, 'pending' => false,
                ]];
                continue;
            }
            $groups[$settings['module']][] = [
                'label' => $settings['label'],
                'route' => $settings['route'] ?? ($settings['module'] === 'gestion' ? 'catalog.'.$resource.'.index' : null),
                'permissions' => array_map(fn ($action) => $resource.'.'.$action, array_keys($settings['actions'] ?? ['view' => 'Consultar'])),
                'pending' => $settings['pending'] ?? false,
            ];
        }

        return $groups;
    }
}
