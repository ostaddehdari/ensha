<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('impersonator_id') && $request->user()?->must_change_password
            && ! $request->routeIs('account.password.*')
            && ! $request->routeIs('logout')) {
            return redirect()->route('account.password.edit')
                ->with('warning', 'برای ادامه، رمز عبور موقت خود را تغییر دهید.');
        }

        return $next($request);
    }
}
