@extends('layouts.app', ['title' => 'مدیریت کاربران'])

@section('content')
<div class="ensha-page-heading">
    <div>
        <span class="ensha-eyebrow">هویت و دسترسی</span>
        <h2>مدیریت کاربران</h2>
        <p>{{ auth()->user()->isSuperAdmin() || auth()->user()->hasPermission(App\Models\User::GLOBAL_CREDENTIAL_PERMISSION) ? 'مدیریت متمرکز اعتبارنامه کاربران همه مراکز' : 'مدیریت کاربران مرکز '.(auth()->user()->centre?->name ?? '') }}</p>
    </div>
    <div class="ensha-heading-actions">
        @can('viewDeleted', App\Models\User::class)
            <a class="ensha-secondary-btn" href="{{ route('users.trash') }}"><i class="ki-filled ki-trash"></i> سطل حذف @if($stats['deleted'])<span class="ensha-mini-count">{{ $stats['deleted'] }}</span>@endif</a>
        @endcan
        @can('export', App\Models\User::class)
            <a class="ensha-secondary-btn" href="{{ route('users.export', request()->query()) }}"><i class="ki-filled ki-file-down"></i> خروجی CSV</a>
        @endcan
        @can('create', App\Models\User::class)
            <a class="ensha-primary-btn" href="{{ route('users.create') }}"><i class="ki-filled ki-user-plus"></i> افزودن کاربر</a>
        @endcan
    </div>
</div>

<div class="ensha-compact-metrics five">
    <div class="ensha-compact-metric"><span class="icon blue"><i class="ki-filled ki-people"></i></span><div><small>کل کاربران</small><strong>{{ number_format($stats['total']) }}</strong></div></div>
    <div class="ensha-compact-metric"><span class="icon green"><i class="ki-filled ki-check-circle"></i></span><div><small>حساب فعال</small><strong>{{ number_format($stats['active']) }}</strong></div></div>
    <div class="ensha-compact-metric"><span class="icon amber"><i class="ki-filled ki-time"></i></span><div><small>غیرفعال</small><strong>{{ number_format($stats['inactive']) }}</strong></div></div>
    <div class="ensha-compact-metric"><span class="icon rose"><i class="ki-filled ki-shield-cross"></i></span><div><small>مسدود</small><strong>{{ number_format($stats['blocked']) }}</strong></div></div>
    <div class="ensha-compact-metric"><span class="icon violet"><i class="ki-filled ki-calendar-add"></i></span><div><small>عضو جدید ماه</small><strong>{{ number_format($stats['new_this_month']) }}</strong></div></div>
</div>

<section class="ensha-card">
    <form class="ensha-filter-bar" method="GET" action="{{ route('users.index') }}">
        <label class="ensha-search-field"><i class="ki-filled ki-magnifier"></i><input name="q" value="{{ request('q') }}" placeholder="کد ملی (جستجوی دقیق)، نام یا شماره تماس"></label>
        <select name="role_id" aria-label="فیلتر نقش">
            <option value="">همه نقش‌ها</option>
            @foreach($roles as $role)<option value="{{ $role->id }}" @selected((string) request('role_id') === (string) $role->id)>{{ $role->name }}</option>@endforeach
        </select>
        @if(auth()->user()->isSuperAdmin() || auth()->user()->hasPermission(App\Models\User::GLOBAL_CREDENTIAL_PERMISSION))
            <select name="centre_id" aria-label="فیلتر مرکز"><option value="">همه مراکز</option>@foreach($centres as $centre)<option value="{{ $centre->id }}" @selected((string) request('centre_id') === (string) $centre->id)>{{ $centre->name }}</option>@endforeach</select>
        @endif
        <select name="status" aria-label="فیلتر وضعیت">
            <option value="">همه وضعیت‌ها</option>
            <option value="active" @selected(request('status') === 'active')>فعال</option>
            <option value="inactive" @selected(request('status') === 'inactive')>غیرفعال</option>
            <option value="blocked" @selected(request('status') === 'blocked')>مسدود</option>
        </select>
        <select name="sort" aria-label="مرتب‌سازی">
            <option value="latest">جدیدترین</option>
            <option value="oldest" @selected(request('sort') === 'oldest')>قدیمی‌ترین</option>
            <option value="name" @selected(request('sort') === 'name')>نام خانوادگی</option>
        </select>
        <button class="ensha-secondary-btn" type="submit"><i class="ki-filled ki-filter"></i> اعمال</button>
        @if(request()->hasAny(['q','role_id','centre_id','status','sort']))<a class="ensha-clear-filter" href="{{ route('users.index') }}">پاک‌کردن</a>@endif
    </form>

    <div class="ensha-table-wrap">
        <table class="ensha-table ensha-data-table">
            <thead><tr><th>کد ملی · شناسه فرد</th><th>کاربر و تماس</th><th>نقش</th><th>مرکز</th><th>آخرین ورود</th><th>وضعیت</th><th class="text-center">عملیات</th></tr></thead>
            <tbody>
            @forelse($users as $user)
                <tr>
                    <td><a class="ensha-national-id-primary" dir="ltr" href="{{ route('users.show', $user) }}">{{ $user->national_id ?: 'ثبت نشده' }}</a></td>
                    <td><a class="ensha-table-user" href="{{ route('users.show', $user) }}"><span class="ensha-avatar small">{{ mb_substr($user->display_name, 0, 1) }}</span><span><strong>{{ $user->display_name }}</strong><small dir="ltr">{{ $user->phone }}</small></span></a></td>
                    <td><span class="ensha-role-chip role-{{ $user->role }}">{{ $user->role_label }}</span></td>
                    <td>{{ $user->centre?->name ?? 'سراسری' }}</td>
                    <td>{{ $user->last_login_at?->format('Y/m/d H:i') ?? 'هنوز وارد نشده' }}</td>
                    <td><span class="ensha-status {{ $user->status_tone }}"><span class="status-dot"></span>{{ $user->status_label }}</span></td>
                    <td>
                        <div class="ensha-row-actions">
                            <a href="{{ route('users.show', $user) }}" title="مشاهده"><i class="ki-filled ki-eye"></i></a>
                            @can('update', $user)<a href="{{ route('users.edit', $user) }}" title="ویرایش"><i class="ki-filled ki-pencil"></i></a>@endcan
                            @can('changeStatus', $user)
                                <form method="POST" action="{{ route('users.status', $user) }}" data-confirm="{{ $user->status === 'active' ? 'این حساب غیرفعال و نشست‌هایش پایان داده شود؟' : 'این حساب دوباره فعال شود؟' }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $user->status === 'active' ? 'inactive' : 'active' }}"><button type="submit" class="{{ $user->status === 'active' ? 'danger' : 'success' }}" title="{{ $user->status === 'active' ? 'غیرفعال‌کردن' : 'فعال‌کردن' }}"><i class="ki-filled {{ $user->status === 'active' ? 'ki-user-tick' : 'ki-user-edit' }}"></i></button></form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="ensha-empty-state"><i class="ki-filled ki-user-square"></i><strong>کاربری با این فیلتر پیدا نشد</strong><span>فیلترها را تغییر دهید یا یک حساب جدید بسازید.</span></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($users->hasPages())<div class="ensha-pagination">{{ $users->links() }}</div>@endif
</section>
@endsection
