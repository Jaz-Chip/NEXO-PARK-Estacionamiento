<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Cuenta inactiva.'], 401);
            }

            return redirect()->route('login')->withErrors(['email' => 'La cuenta está desactivada.']);
        }

        return $next($request);
    }
}
