<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rate extends Model
{
    protected $fillable = ['vehicle_type_id', 'name', 'unit', 'fraction_minutes', 'amount', 'is_active'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'fraction_minutes' => 'integer', 'is_active' => 'boolean'];
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
