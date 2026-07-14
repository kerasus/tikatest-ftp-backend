<?php

namespace App\Http\Middleware;

use Closure;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RoleMiddleware
{
    use ApiResponse;

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $role
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $role)
    {
        // Check if the user is authenticated
        if (!Auth::check()) {
            return $this->respondUnauthorized();
        }

        // Get the authenticated user
        $user = Auth::user();

        // Check if the user has the required role
        if (!$user->hasRole($role)) {
            return $this->respondForbidden('شما دسترسی لازم برای انجام این عملیات را ندارید.');
        }

        return $next($request);
    }
}
