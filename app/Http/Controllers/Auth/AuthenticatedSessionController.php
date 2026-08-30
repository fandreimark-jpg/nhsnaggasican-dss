<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use App\Helpers\LogActivity;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     * Authenticates the user, logs the login action,
     * then redirects based on role.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        // Authenticate credentials — throws exception if invalid
        $request->authenticate();

        // Regenerate session ID to prevent session fixation attacks
        $request->session()->regenerate();

        $user = auth()->user();

        // Log login action for ALL users — admin and adviser
        LogActivity::log(
            action:      'login',
            description: $user->name . ' logged in',
            tableName:   'users',
            recordId:    $user->id
        );

        // Redirect to the correct dashboard based on role — centralized on
        // the User model so a new role only needs to be added in one place.
        return redirect()->route($user->dashboardRouteName());
    }

    /**
     * Destroy an authenticated session (logout).
     * Invalidates session and regenerates CSRF token.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        // Invalidate the session — clears all session data
        $request->session()->invalidate();

        // Regenerate CSRF token for security
        $request->session()->regenerateToken();

        return redirect('/');
    }
}