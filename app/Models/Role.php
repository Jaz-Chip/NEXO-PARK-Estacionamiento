<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = ['name', 'slug', 'guard_name'];

    public function isCustom(): bool
    {
        return ! in_array($this->slug, ['administrador', 'operador', 'supervisor'], true);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->slug === 'operador' ? 'Operador / Cajero' : $this->name;
    }
}
