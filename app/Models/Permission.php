<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected $fillable = ['key', 'name', 'label', 'module', 'is_pending', 'guard_name'];

    protected function casts(): array
    {
        return ['is_pending' => 'boolean'];
    }
}
