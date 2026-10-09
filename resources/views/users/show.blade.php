@extends('layouts.app', ['title' => 'پروفایل کاربر'])

@section('content')
@if(auth()->user()->can('update',$user))<div style="margin:12px 0"><a class="ensha-primary-btn" href="{{ route('users.assignments.edit',$user) }}">مدیریت نقش‌ها و مراکز</a></div>@endif
<div class="ensha-page-heading">
    <div>
        <a class="ensha-back-crumb" href="{{ route('users.index') }}"><i class="ki-filled ki-arrow-right"></i> مدیریت کاربران</a>
        <h2>{{ $user->display_name }}</h2>
        <p>کد ملی <strong class="ensha-national-id-primary" dir="ltr">{{ $user->national_id }}</strong> · شناسه داخلی #{{ $user->id }} · ایجادشده در {{ $user->created_at?->format('Y/m/d H:i') }}</p>
    </div>
    <div class="ensha-heading-actions">
        @can('impersonate', $user)
            <form method="POST" action="{{ route('users.impersonate', $user) }}" data-confirm="برای بررسی پنل با هویت {{ $user->display_name }} وارد شوید؟ تمام این عملیات ثبت می‌شود.">@csrf<button class="ensha-secondary-btn" type="submit"><i class="ki-filled ki-user-square"></i> ورود موقت به پنل</button></form>
        @endcan
        @can('update', $user)<a class="ensha-secondary-btn" href="{{ route('users.edit', $user) }}"><i class="ki-filled ki-pencil"></i> ویرایش</a>@endcan
    </div>
</div>

<div class="ensha-profile-grid">
    <aside class="ensha-card ensha-user-summary">
        <span class="ensha-profile-avatar">{{ mb_substr($user->display_name, 0, 1) }}</span>
        <h3>{{ $user->display_name }}</h3>
        <span class="ensha-role-chip role-{{ $user->role }}">{{ $user->role_label }}</span>
        <span class="ensha-status {{ $user->status_tone }}"><span class="status-dot"></span>{{ $user->status_label }}</span>
        @if($user->status_reason)<p class="ensha-status-reason">{{ $user->status_reason }}</p>@endif
        <div class="ensha-summary-list">
            <div><i class="ki-filled ki-phone"></i><span>شماره تلفن</span><strong dir="ltr">{{ $user->phone }}</strong></div>
            <div><i class="ki-filled ki-profile-circle"></i><span>کد ملی</span><strong dir="ltr">{{ $user->national_id }}</strong></div>
            <div><i class="ki-filled ki-geolocation"></i><span>مرکز</span><strong>{{ $user->centre?->name ?? 'دسترسی سراسری' }}</strong></div>
            <div><i class="ki-filled ki-calendar"></i><span>آخرین ورود</span><strong>{{ $user->last_login_at?->format('Y/m/d H:i') ?? 'ثبت نشده' }}</strong></div>
            <div><i class="ki-filled ki-user"></i><span>ایجادکننده</span><strong>{{ $user->creator?->display_name ?? 'سامانه' }}</strong></div>
            <div><i class="ki-filled ki-shield-tick"></i><span>تغییر وضعیت</span><strong>{{ $user->status_changed_at?->format('Y/m/d H:i') ?? 'ثبت نشده' }}</strong></div>
        </div>
    </aside>

    <div class="ensha-profile-main">
        @if($user->profileValues->filter(fn($entry)=>$entry->field?->is_active && in_array($entry->field->role,['all',$user->role],true) && $entry->value!==null && $entry->value!=='')->isNotEmpty())
        <section class="ensha-card"><div class="ensha-card-head"><div><span class="ensha-eyebrow">پروفایل تکمیلی</span><h3>اطلاعات فرم‌ساز</h3></div></div><div class="ensha-summary-list" style="padding:20px">@foreach($user->profileValues as $entry)@if($entry->field?->is_active && in_array($entry->field->role,['all',$user->role],true) && $entry->value!==null && $entry->value!=='')<div><span>{{ $entry->field->label }}</span><strong>{{ in_array($entry->field->field_type,['checkbox_group','multi_select']) ? implode('، ',array_map(fn($value)=>\App\Support\ProfileOptions::label($entry->field->options,(string)$value),is_array(json_decode($entry->value,true)) ? json_decode($entry->value,true) : [])) : (in_array($entry->field->field_type,['radio','select']) ? \App\Support\ProfileOptions::label($entry->field->options,$entry->value) : $entry->value) }}</strong></div>@endif @endforeach</div></section>
        @endif
        <section class="ensha-card">
            <div class="ensha-card-head"><div><span class="ensha-eyebrow">سطح دسترسی</span><h3>مجوزهای نقش {{ $user->role_label }}</h3></div><span class="ensha-badge-soft">{{ $user->isSuperAdmin() ? 'دسترسی کامل' : $user->assignedRole?->permissions->count().' مجوز' }}</span></div>
            @if($user->isSuperAdmin())
                <div class="ensha-info-strip"><i class="ki-filled ki-shield-tick"></i><div><strong>ادمین سیستم</strong><p>این نقش به‌صورت ذاتی به همه بخش‌های سامانه دسترسی دارد.</p></div></div>
            @else
                <div class="ensha-permission-chips">@forelse($user->assignedRole?->permissions ?? [] as $permission)<span title="{{ $permission->description }}"><i class="ki-filled ki-check-circle"></i>{{ $permission->name }}</span>@empty<span class="empty">هیچ مجوز فعالی برای این نقش تعریف نشده است.</span>@endforelse</div>
            @endif
        </section>

        @can('changeStatus', $user)
        <details class="ensha-card ensha-collapsible">
            <summary><span><i class="ki-filled ki-shield-search"></i><strong>وضعیت و کنترل حساب</strong><small>فعال‌سازی، توقف موقت یا مسدودسازی امنیتی</small></span><i class="ki-filled ki-down"></i></summary>
            <form method="POST" action="{{ route('users.status', $user) }}" class="ensha-status-form" data-confirm="وضعیت این حساب تغییر کند؟ نشست‌های کاربر در حالت غیرفعال یا مسدود پایان می‌یابد.">@csrf @method('PATCH')
                <label>وضعیت جدید
                    <select name="status" required>
                        <option value="active" @selected($user->status === 'active')>فعال</option>
                        <option value="inactive" @selected($user->status === 'inactive')>غیرفعال موقت</option>
                        <option value="blocked" @selected($user->status === 'blocked')>مسدود امنیتی</option>
                    </select>
                </label>
                <label class="grow">دلیل تغییر وضعیت
                    <input name="status_reason" maxlength="500" value="{{ old('status_reason', $user->status_reason) }}" placeholder="برای وضعیت مسدود، ثبت دلیل الزامی است">
                </label>
                <button class="ensha-primary-btn" type="submit"><i class="ki-filled ki-check"></i> اعمال وضعیت</button>
            </form>
        </details>
        @endcan

        @can('resetPassword', $user)
        <details class="ensha-card ensha-collapsible">
            <summary><span><i class="ki-filled ki-key-square"></i><strong>بازنشانی رمز عبور</strong><small>تنظیم رمز موقت، خروج از همه دستگاه‌ها و اجبار به تغییر رمز</small></span><i class="ki-filled ki-down"></i></summary>
            <form method="POST" action="{{ route('users.password', $user) }}" class="ensha-inline-security-form" data-confirm="رمز عبور این کاربر بازنشانی و همه نشست‌هایش پایان داده شود؟">@csrf @method('PATCH')
                <label>رمز موقت <span class="ensha-input-action"><input type="password" name="password" data-password-input minlength="10" autocomplete="new-password" required><button type="button" data-generate-password>ساخت رمز قوی</button></span></label>
                <label>تکرار رمز موقت <input type="password" name="password_confirmation" minlength="10" autocomplete="new-password" required></label>
                <button class="ensha-danger-btn" type="submit"><i class="ki-filled ki-key"></i> ثبت رمز موقت</button>
            </form>
        </details>
        @endcan

        @can('updatePhone', $user)
        <details class="ensha-card ensha-collapsible">
            <summary><span><i class="ki-filled ki-phone"></i><strong>تغییر شماره تلفن ورود</strong><small>تغییر فقط شماره تلفن و خروج کاربر از همه دستگاه‌ها</small></span><i class="ki-filled ki-down"></i></summary>
            <form method="POST" action="{{ route('users.phone', $user) }}" class="ensha-inline-security-form" data-confirm="شماره تلفن ورود این کاربر تغییر کند؟ همه نشست‌های قبلی او پایان می‌یابد.">@csrf @method('PATCH')
                <label>شماره تلفن جدید <input type="tel" name="phone" value="{{ old('phone', $user->phone) }}" dir="ltr" inputmode="tel" maxlength="14" required></label>
                <button class="ensha-danger-btn" type="submit"><i class="ki-filled ki-check"></i> ثبت شماره جدید</button>
            </form>
        </details>
        @endcan

        @if($canViewSessions)
        <section class="ensha-card">
            <div class="ensha-card-head">
                <div><span class="ensha-eyebrow">امنیت حساب</span><h3>نشست‌ها و دستگاه‌های اخیر</h3></div>
                @can('revokeSessions', $user)
                    <form method="POST" action="{{ route('users.sessions.revoke-all', $user) }}" data-confirm="همه نشست‌های کاربر پایان داده شود؟">@csrf @method('PATCH')<button class="ensha-secondary-btn" type="submit"><i class="ki-filled ki-disconnect"></i> خروج از همه دستگاه‌ها</button></form>
                @endcan
            </div>
            <div class="ensha-session-list">
                @forelse($sessions as $session)
                    <div class="ensha-session-item">
                        <span class="ensha-session-icon"><i class="ki-filled ki-monitor-mobile"></i></span>
                        <div class="grow">
                            <strong>{{ $session->device_name ?: 'دستگاه ناشناس' }} @if($currentSessionHash && hash_equals($currentSessionHash, $session->session_hash))<span class="ensha-current-session">همین دستگاه</span>@endif</strong>
                            <small><span dir="ltr">{{ $session->ip_address ?: 'IP نامشخص' }}</span> · آخرین فعالیت {{ $session->last_activity_at?->format('Y/m/d H:i') ?? 'ثبت نشده' }}</small>
                        </div>
                        <span class="ensha-status {{ $session->revoked_at ? 'warning' : 'success' }}">{{ $session->revoked_at ? 'پایان‌یافته' : 'فعال' }}</span>
                        @can('revokeSessions', $user)
                            @if(!$session->revoked_at)<form method="POST" action="{{ route('users.sessions.revoke', [$user, $session]) }}" data-confirm="این نشست پایان داده شود؟">@csrf @method('PATCH')<button class="ensha-icon-danger" type="submit" title="پایان نشست"><i class="ki-filled ki-cross"></i></button></form>@endif
                        @endcan
                    </div>
                @empty
                    <div class="ensha-empty-state compact"><i class="ki-filled ki-monitor-mobile"></i><strong>نشست ثبت‌شده‌ای وجود ندارد</strong><span>پس از ورود بعدی کاربر، دستگاه و زمان فعالیت اینجا ثبت می‌شود.</span></div>
                @endforelse
            </div>
            @if($sessions->hasPages())<div class="ensha-pagination">{{ $sessions->appends(request()->except('sessions_page'))->links() }}</div>@endif
        </section>
        @endif

        <section class="ensha-card">
            <div class="ensha-card-head"><div><span class="ensha-eyebrow">ردپای امنیتی</span><h3>تاریخچه عملیات این حساب</h3></div><i class="ki-filled ki-notepad ensha-muted-icon"></i></div>
            <div class="ensha-audit-list">
                @forelse($logs as $log)
                    <div class="ensha-audit-item"><span class="ensha-audit-icon {{ $log->level }}"><i class="ki-filled {{ $log->level === 'warning' ? 'ki-information-2' : 'ki-check-circle' }}"></i></span><div><strong>{{ $log->event }}</strong><small>توسط {{ $log->actor?->display_name ?? 'سامانه' }} · {{ $log->created_at?->format('Y/m/d H:i') }}</small></div><code>{{ $log->action ?: 'event' }}</code></div>
                @empty
                    <div class="ensha-empty-state compact"><i class="ki-filled ki-notepad"></i><strong>رویدادی ثبت نشده است</strong></div>
                @endforelse
            </div>
            @if($logs->hasPages())<div class="ensha-pagination">{{ $logs->appends(request()->except('logs_page'))->links() }}</div>@endif
        </section>

        @can('delete', $user)
        <section class="ensha-danger-zone"><div><strong>حذف حساب کاربری</strong><p>حساب به‌صورت نرم حذف می‌شود، همه نشست‌ها پایان می‌یابد و سوابق امنیتی باقی می‌ماند.</p></div><form method="POST" action="{{ route('users.destroy', $user) }}" data-confirm="حساب {{ $user->display_name }} حذف شود؟ دسترسی او فوراً قطع می‌شود.">@csrf @method('DELETE')<button class="ensha-danger-btn" type="submit"><i class="ki-filled ki-trash"></i> حذف حساب</button></form></section>
        @endcan
    </div>
</div>
@endsection
