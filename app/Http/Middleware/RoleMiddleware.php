<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; 
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next, $role)
    {
        abort_unless(Auth::check(), 401);
        $allowed = $role === 'admin' ? ['admin', 'superadmin'] : [$role];
        abort_unless(in_array(Auth::user()->role, $allowed, true), 403);

        return $next($request);
    }
}
