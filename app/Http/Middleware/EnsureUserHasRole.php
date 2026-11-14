<?php

namespace App\Http\Middleware;

use Spatie\Permission\Middleware\RoleMiddleware;

// Retained as a compatibility alias; new routes authorize using can:permission.
class EnsureUserHasRole extends RoleMiddleware
{
}
