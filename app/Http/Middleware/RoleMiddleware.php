<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * RoleMiddleware
 *
 * Checks if the authenticated user has the required role
 * before allowing access to a route.
 *
 * Usage in routes:
 * Route::middleware(['auth', 'role:admin'])->group(...)
 * Route::middleware(['auth', 'role:adviser'])->group(...)
 * Route::middleware(['auth', 'role:adviser,subject_teacher'])->group(...)  // any of the listed roles
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        // Redirect to login if not authenticated
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        // Block access if role does not match
        // Returns 403 Forbidden instead of redirecting
        if (!in_array(auth()->user()->role, $roles, true)) {
            abort(403, 'Unauthorized.');
        }

        // User is authenticated and has correct role — allow request
        return $next($request);
    }
}