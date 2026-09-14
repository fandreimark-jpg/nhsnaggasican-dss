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
            if (!$current || !$current->isActive() || !in_array($current->role, ['admin', 'adviser', 'principal'], true)) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors([
                    'email' => 'Your account has been disabled. Contact the administrator.',
                ]);
            }
            Auth::guard('web')->setUser($current);
        }

        return $next($request);
    }
}
