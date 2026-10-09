<?php

use App\Http\Middleware\ApplyActiveRole;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\TrackUserSession;
use App\Http\Middleware\VerifyWordPressBridgeRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            ApplyActiveRole::class,
            EnsureUserIsActive::class,
            TrackUserSession::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'password.changed' => EnsurePasswordIsChanged::class,
            'wordpress.bridge' => VerifyWordPressBridgeRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // خطاهای تولیدی توسط handler پیش‌فرض لاراول ثبت می‌شوند.
    })->create();
