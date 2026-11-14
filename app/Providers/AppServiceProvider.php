<?php

namespace App\Providers;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Spatie registers the normal permission checks. The administrator has
        // permanent access to the application's registered abilities.
        Gate::before(function (User $user, string $ability) {
            return $user->is_active && $user->isAdministrator()
                && array_key_exists($ability, PermissionCatalog::definitions()) ? true : null;
        });
    }
}
