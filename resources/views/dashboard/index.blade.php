@extends('layouts.app', ['title' => 'داشبورد '.$user->role_label])

@section('content')
<div class="ensha-welcome-row">
    <div><span class="ensha-eyebrow">{{ now()->format('Y/m/d') }} · نسخه پایه</span><h2>سلام {{ $user->first_name }}، آماده‌ایم.</h2><p>این نمای کلی عملیات مرتبط با نقش «{{ $user->role_label }}» را نشان می‌دهد.</p></div>
    <div class="ensha-quick-actions"><button class="ensha-secondary-btn" data-kt-drawer-toggle="#notifications_drawer" type="button"><i class="ki-filled ki-notification-on"></i> اعلان‌ها <span class="ensha-mini-count">۳</span></button><a href="{{ route('profile.show') }}" class="ensha-primary-btn"><i class="ki-filled ki-profile-circle"></i> پروفایل من</a></div>
</div>

@if($user->role === 'manager' && $user->centre_id && $user->hasPermission('users.create'))
<div class="ensha-quick-actions" style="margin-bottom:20px"><a class="ensha-primary-btn" href="{{ route('users.create', ['role' => 'secretary']) }}">افزودن منشی</a><a class="ensha-secondary-btn" href="{{ route('users.create', ['role' => 'counselor']) }}">افزودن مشاور</a><a class="ensha-secondary-btn" href="{{ route('centres.topics', $user->centre_id) }}">موضوعات و تعرفه مرکز</a></div>
@endif
<div class="ensha-metrics-grid">
    @foreach($metrics as $metric)
        <div class="ensha-metric-card tone-{{ $metric['tone'] }}"><div class="ensha-metric-icon"><i class="{{ $metric['icon'] }}"></i></div><div class="ensha-metric-copy"><span>{{ $metric['label'] }}</span><strong>{{ $metric['value'] }}</strong><small><i class="ki-filled ki-arrow-up"></i> وضعیت اولیه سامانه</small></div></div>
    @endforeach
</div>

<div class="ensha-dashboard-grid">
    <section class="ensha-card ensha-card-large">
        <div class="ensha-card-head"><div><span class="ensha-eyebrow">نمایش سریع</span><h3>عملیات کلیدی</h3></div><span class="ensha-live-pill"><span></span> زنده</span></div>
        <div class="ensha-module-grid">
            @foreach($quickModules as $item)
                @php
                    $quickHref = match($item['slug'] ?? '') {
                        'users' => route('users.index'),
                        'counselors' => route('staff.index'),
                        'profile' => route('profile.show'),
                        'centres' => route('centres.index'),
                        'roles' => route('roles.index'),
                        'permissions' => route('permissions.index'),
                        'profile-fields' => route('profile-fields.index'),
                        default => route('module', ['module' => $item['slug']]),
                    };
                @endphp
                <a href="{{ $quickHref }}" class="ensha-module-card"><span class="ensha-module-icon"><i class="{{ $item['icon'] }}"></i></span><span><strong>{{ $item['label'] }}</strong><small>مشاهده بخش</small></span><i class="ki-filled ki-arrow-left ensha-arrow"></i></a>
            @endforeach
        </div>
    </section>

    <section class="ensha-card">
        <div class="ensha-card-head"><div><span class="ensha-eyebrow">آماده توسعه</span><h3>وضعیت زیرساخت</h3></div><i class="ki-filled ki-setting-2 ensha-muted-icon"></i></div>
        <div class="ensha-health-list"><div><span class="health-dot green"></span><span>هویت و ورود نقش‌محور</span><strong>فعال</strong></div><div><span class="health-dot green"></span><span>دیتابیس مستقل مرکز</span><strong>فعال</strong></div><div><span class="health-dot amber"></span><span>چت و اعلان بلادرنگ</span><strong>نمایشی</strong></div><div><span class="health-dot amber"></span><span>نوبت‌دهی و تقویم</span><strong>مرحله بعد</strong></div></div>
        <div class="ensha-progress"><div><span>آمادگی نسخه پایه</span><strong>۶۵٪</strong></div><div class="progress-track"><span style="width:65%"></span></div></div>
    </section>
</div>

@if($user->isSuperAdmin())
<section class="ensha-card">
    <div class="ensha-card-head"><div><span class="ensha-eyebrow">شناسایی بر پایه کد ملی</span><h3>جست‌وجوی فرد</h3></div><a class="ensha-text-link" href="{{ route('users.index') }}">همه افراد</a></div>
    <form method="GET" action="{{ route('users.index') }}" class="ensha-admin-person-search"><label>کد ملی فرد<input name="q" maxlength="10" inputmode="numeric" pattern="[0-9۰-۹٠-٩]{10}" dir="ltr" placeholder="کد ملی ۱۰ رقمی" required></label><button type="submit" class="ensha-primary-btn">جست‌وجوی پرونده</button></form>
    <div class="ensha-card-head"><div><h3>افراد تازه‌ثبت‌شده</h3></div></div><div class="ensha-table-wrap"><table class="ensha-table"><thead><tr><th>کد ملی</th><th>نام</th><th>نقش</th><th>مرکز</th></tr></thead><tbody>@forelse($recentPeople as $person)<tr><td><a href="{{ route('users.show', $person) }}" class="ensha-national-id-primary" dir="ltr">{{ $person->national_id ?: 'ثبت نشده' }}</a></td><td>{{ $person->display_name }}</td><td>{{ $person->role_label }}</td><td>{{ $person->centre?->name ?? 'سراسری' }}</td></tr>@empty<tr><td colspan="4">فردی ثبت نشده است.</td></tr>@endforelse</tbody></table></div>
</section>
<section class="ensha-card ensha-log-card">
    <div class="ensha-card-head"><div><span class="ensha-eyebrow">پایش سیستم</span><h3>آخرین رویدادهای سامانه</h3></div><a class="ensha-text-link" href="{{ route('module', ['module' => 'audit-logs']) }}">مشاهده همه <i class="ki-filled ki-arrow-left"></i></a></div>
    <div class="ensha-table-wrap"><table class="ensha-table"><thead><tr><th>رویداد</th><th>کاربر</th><th>سطح</th><th>زمان</th></tr></thead><tbody>
        @forelse($recentLogs as $log)<tr><td><strong>{{ $log->event }}</strong><small>{{ $log->route ?: 'سامانه' }}</small></td><td>{{ $log->actor?->display_name ?? 'سیستم' }}</td><td><span class="ensha-status {{ $log->level === 'warning' ? 'warning' : 'success' }}">{{ $log->level === 'warning' ? 'هشدار' : 'اطلاعات' }}</span></td><td>{{ $log->created_at?->format('Y/m/d H:i') }}</td></tr>@empty<tr><td colspan="4"><div class="ensha-table-empty">هنوز رویدادی ثبت نشده است؛ با ورودهای بعدی اینجا به‌روزرسانی می‌شود.</div></td></tr>@endforelse
    </tbody></table></div>
</section>
@else
<section class="ensha-card ensha-info-banner"><div class="ensha-info-icon"><i class="ki-filled ki-information-2"></i></div><div><strong>این نسخه، اسکلت عملیاتی پنل شماست</strong><p>منوها آماده‌اند و صفحات داخلی در مرحله بعد به نوبت‌دهی، پرونده، آزمون و گزارش متصل خواهند شد.</p></div><span class="ensha-badge-soft">نسخه پایه</span></section>
@endif
@endsection
