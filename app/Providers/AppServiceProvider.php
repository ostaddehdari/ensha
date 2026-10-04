<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Support\PhoneNormalizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // سرویس‌های نسخه پایه در همین‌جا قابل اضافه‌شدن هستند.
    }

    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(PhoneNormalizer::normalize((string) $request->input('phone')).'|'.$request->ip()),
        ]);
        RateLimiter::for('registration', fn (Request $request) => [
            Limit::perMinute(3)->by($request->ip()),
        ]);
        RateLimiter::for('sensitive', fn (Request $request) => [
            Limit::perMinute(10)->by(($request->user()?->id ?? 'guest').'|'.$request->ip()),
        ]);
        RateLimiter::for('exports', fn (Request $request) => [
            Limit::perMinute(3)->by(($request->user()?->id ?? 'guest').'|'.$request->ip()),
        ]);

        if (app()->environment('production')) {
            URL::forceRootUrl(rtrim((string) config('app.url'), '/'));
            URL::forceScheme(parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https');
        }
    }
}
