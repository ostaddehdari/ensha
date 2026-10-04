<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $activeUsersAtCentre = fn (?string $role = null) => User::query()
            ->where('is_active', true)
            ->where('status', 'active')
            ->whereHas('roleAssignments', fn ($assignment) => $assignment
                ->where('centre_id', $user->centre_id)
                ->whereHas('role', fn ($roleQuery) => $roleQuery
                    ->where('is_active', true)
                    ->when($role, fn ($query) => $query->where('slug', $role))))
            ->count();
        $metrics = match ($user->role) {
            'super_admin' => [
                ['label' => 'کاربران فعال', 'value' => User::where('is_active', true)->count(), 'icon' => 'ki-filled ki-people', 'tone' => 'blue'],
                ['label' => 'رویدادهای امروز', 'value' => AuditLog::whereDate('created_at', today())->count(), 'icon' => 'ki-filled ki-chart-line-up', 'tone' => 'emerald'],
                ['label' => 'وضعیت سرویس‌ها', 'value' => 'پایدار', 'icon' => 'ki-filled ki-shield-tick', 'tone' => 'amber'],
                ['label' => 'هشدارهای باز', 'value' => '۰', 'icon' => 'ki-filled ki-information-2', 'tone' => 'rose'],
            ],
            'manager' => [
                ['label' => 'کاربران مرکز', 'value' => $activeUsersAtCentre(), 'icon' => 'ki-filled ki-people', 'tone' => 'blue'],
                ['label' => 'مشاور فعال', 'value' => $activeUsersAtCentre('counselor'), 'icon' => 'ki-filled ki-profile-user', 'tone' => 'emerald'],
                ['label' => 'نوبت امروز', 'value' => '—', 'icon' => 'ki-filled ki-calendar-tick', 'tone' => 'amber'],
                ['label' => 'درآمد این ماه', 'value' => '—', 'icon' => 'ki-filled ki-dollar', 'tone' => 'violet'],
            ],
            'secretary' => [
                ['label' => 'نوبت‌های امروز', 'value' => '—', 'icon' => 'ki-filled ki-calendar-tick', 'tone' => 'blue'],
                ['label' => 'مراجعین فعال', 'value' => $activeUsersAtCentre('client'), 'icon' => 'ki-filled ki-people', 'tone' => 'emerald'],
                ['label' => 'در انتظار تأیید', 'value' => '—', 'icon' => 'ki-filled ki-time', 'tone' => 'amber'],
                ['label' => 'پرداخت امروز', 'value' => '—', 'icon' => 'ki-filled ki-wallet', 'tone' => 'violet'],
            ],
            'counselor' => [
                ['label' => 'نوبت‌های امروز', 'value' => '—', 'icon' => 'ki-filled ki-calendar-tick', 'tone' => 'blue'],
                ['label' => 'مراجعین من', 'value' => '—', 'icon' => 'ki-filled ki-people', 'tone' => 'emerald'],
                ['label' => 'ساعات قابل رزرو', 'value' => '—', 'icon' => 'ki-filled ki-time', 'tone' => 'amber'],
                ['label' => 'کارکرد ماه', 'value' => '—', 'icon' => 'ki-filled ki-chart-line-up', 'tone' => 'violet'],
            ],
            'test_manager' => [
                ['label' => 'تست‌های فعال', 'value' => '—', 'icon' => 'ki-filled ki-book-open', 'tone' => 'blue'],
                ['label' => 'ارسال‌های امروز', 'value' => '—', 'icon' => 'ki-filled ki-send', 'tone' => 'emerald'],
                ['label' => 'نتیجه آماده', 'value' => '—', 'icon' => 'ki-filled ki-chart-simple', 'tone' => 'amber'],
                ['label' => 'نیازمند بررسی', 'value' => '—', 'icon' => 'ki-filled ki-information-2', 'tone' => 'rose'],
            ],
            'client' => [
                ['label' => 'نوبت‌های آینده', 'value' => '—', 'icon' => 'ki-filled ki-calendar-tick', 'tone' => 'blue'],
                ['label' => 'آزمون‌های من', 'value' => '—', 'icon' => 'ki-filled ki-notepad-edit', 'tone' => 'emerald'],
                ['label' => 'پرونده من', 'value' => 'فعال', 'icon' => 'ki-filled ki-folder', 'tone' => 'amber'],
                ['label' => 'پرداخت‌های من', 'value' => '—', 'icon' => 'ki-filled ki-wallet', 'tone' => 'violet'],
            ],
            default => [
                ['label' => 'نقش فعال', 'value' => $user->role_label, 'icon' => 'ki-filled ki-security-user', 'tone' => 'blue'],
                ['label' => 'مجوزهای من', 'value' => $user->assignedRole?->permissions()->where('permissions.is_active', true)->count() ?? 0, 'icon' => 'ki-filled ki-shield-tick', 'tone' => 'emerald'],
                ['label' => 'مرکز', 'value' => $user->centre?->name ?? 'سراسری', 'icon' => 'ki-filled ki-geolocation', 'tone' => 'amber'],
                ['label' => 'وضعیت حساب', 'value' => $user->status_label, 'icon' => 'ki-filled ki-check-circle', 'tone' => 'violet'],
            ],
        };

        $permissionBySlug = collect(config('panels.permission_menus', []))->pluck('permission', 'slug');
        $quickModules = collect(config('panels.menus.'.$user->role, []))
            ->filter(fn ($item) => isset($item['label']))
            ->filter(function ($item) use ($user, $permissionBySlug) {
                $slug = $item['slug'] ?? null;

                return ! $slug || ! $permissionBySlug->has($slug) || $user->hasPermission($permissionBySlug->get($slug));
            })
            ->merge(collect(config('panels.permission_menus', []))->filter(fn ($item) => $user->hasPermission($item['permission'])))
            ->reject(fn ($item) => $user->isSuperAdmin() && in_array($item['slug'] ?? '', ['counselors', 'work-hours', 'fees', 'appointments'], true))
            ->unique('slug')
            ->take(6)
            ->values()
            ->all();

        $recentPeople = $user->isSuperAdmin() ? User::query()->with(['assignedRole', 'centre'])->latest()->limit(6)->get() : collect();

        $recentLogs = $user->role === 'super_admin'
            ? AuditLog::with('actor')->latest()->limit(8)->get()
            : collect();

        return view('dashboard.index', compact('user', 'metrics', 'recentLogs', 'recentPeople', 'quickModules'));
    }

    public function module(Request $request, string $module): View
    {
        $user = $request->user();
        $permissionBySlug = collect(config('panels.permission_menus', []))->pluck('permission', 'slug');
        $allowedMenus = collect(array_merge(
            config('panels.menus.common', []),
            config('panels.menus.'.$user->role, []),
            collect(config('panels.permission_menus', []))->filter(fn ($item) => $user->hasPermission($item['permission']))->all(),
        ))->filter(function ($item) use ($user, $permissionBySlug) {
            $slug = $item['slug'] ?? null;

            return ! $slug || ! $permissionBySlug->has($slug) || $user->hasPermission($permissionBySlug->get($slug));
        });
        $menu = $allowedMenus->first(fn ($item) => is_array($item) && ($item['slug'] ?? null) === $module);
        abort_unless($menu, 403, 'شما مجوز دسترسی به این بخش را ندارید.');
        $label = $menu['label'] ?? 'صفحه سامانه';

        return view('pages.placeholder', ['module' => $module, 'label' => $label]);
    }
}
