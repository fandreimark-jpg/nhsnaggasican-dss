<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if ($user) {
            $current = $user->fresh();
            if (!$current || !$current->isActive() || !in_array($current->role, \App\Models\User::ROLES, true)) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                // A fetch() save must get a real failure, not a redirect it
                // would follow to the login page and read as "200 OK"
                // (final audit, 2026-09-28: a disabled account's open Class
                // Record tab reported "Saved" while nothing was written).
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Your account has been disabled or its role no longer allows this. Nothing was saved. Contact the administrator.'], 401);
                }

                return redirect()->route('login')->withErrors([
                    'email' => 'Your account has been disabled. Contact the administrator.',
                ]);
            }
            Auth::guard('web')->setUser($current);
        }

        return $next($request);
    }
}
