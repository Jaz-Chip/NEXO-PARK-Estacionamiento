<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Floor extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function sectors(): HasMany
    {
        return $this->hasMany(Sector::class);
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }
}
