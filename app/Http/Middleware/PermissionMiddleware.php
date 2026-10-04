<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $allowed = collect($permissions)->contains(fn (string $permission) => $user->hasPermission($permission));
        abort_unless($allowed, 403, 'شما مجوز دسترسی به این بخش را ندارید.');

        return $next($request);
    }
}
