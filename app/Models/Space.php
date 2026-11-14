<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Space extends Model
{
    public function scopeOperational($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('floor_id')->orWhereHas('floor', fn ($f) => $f->where('is_active', true)))
            ->where(fn ($q) => $q->whereNull('sector_id')->orWhereHas('sector', fn ($s) => $s->where('is_active', true)
                ->whereColumn('sectors.floor_id', 'spaces.floor_id')));
    }

    protected $fillable = ['code', 'vehicle_type_id', 'floor_id', 'sector_id', 'status', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
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
