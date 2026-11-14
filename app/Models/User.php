<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'role_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected string $guard_name = 'web';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Compatibility for the existing factories/imports using role_id.
        // Authorization always uses Spatie's roles relation, never this column.
        static::created(function (User $user) {
            if ($user->role_id && $role = Role::find($user->role_id)) {
                $user->assignRole($role);
            }
        });
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function openedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'opened_by');
    }

    public function closedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'closed_by');
    }

    public function isAdministrator(): bool
    {
        $administrator = $this->roles->firstWhere('slug', 'administrador');

        return $administrator !== null && $this->hasRole($administrator, 'web');
    }

    public function getRoleLabelAttribute(): string
    {
        return $this->roles->map(fn (Role $role) => $role->display_name)->implode(', ') ?: 'Sin rol';
    }

    public function startRoute(): string
    {
        foreach (\App\Support\PermissionCatalog::navigation() as $items) {
            foreach ($items as $item) {
                if ($item['route'] && (($item['any_permission'] ?? false) ? $this->canAny($item['permissions']) : $this->can($item['permissions'][0]))) {
                    return $item['route'];
                }
            }
        }

        return 'access.none';
    }
}
