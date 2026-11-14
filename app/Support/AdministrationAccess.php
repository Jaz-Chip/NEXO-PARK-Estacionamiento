<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

class AdministrationAccess
{
    public static function canGrant(User $actor, iterable $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $actor->can($permission)) {
                return false;
            }
        }

        return true;
    }

    public static function canAssignRole(User $actor, Role $role): bool
    {
        return $role->guard_name === 'web' && ($role->slug === 'administrador'
            ? $actor->isAdministrator()
            : self::canGrant($actor, $role->permissions->pluck('name')));
    }

    public static function canConfigureRole(User $actor, Role $role): bool
    {
        return $actor->can('roles.manage') && $role->slug !== 'administrador' && self::canAssignRole($actor, $role);
    }

    public static function canManageUser(User $actor, User $target): bool
    {
        return ! $target->isAdministrator() && $target->id !== $actor->id
            && self::canGrant($actor, $target->getAllPermissions()->pluck('name'));
    }
}
