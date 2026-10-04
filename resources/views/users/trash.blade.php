@extends('layouts.app', ['title' => 'کاربران حذف‌شده'])

@section('content')
<div class="ensha-page-heading">
    <div>
        <a class="ensha-back-crumb" href="{{ route('users.index') }}"><i class="ki-filled ki-arrow-right"></i> مدیریت کاربران</a>
        <h2>سطل حذف کاربران</h2>
        <p>حساب‌های این بخش امکان ورود ندارند؛ بازیابی، حساب را برای بررسی در وضعیت غیرفعال برمی‌گرداند.</p>
    </div>
</div>

<section class="ensha-card">
    <form class="ensha-filter-bar" method="GET" action="{{ route('users.trash') }}">
        <label class="ensha-search-field"><i class="ki-filled ki-magnifier"></i><input name="q" value="{{ request('q') }}" placeholder="نام، شماره تلفن یا کد ملی"></label>
        <select name="sort" aria-label="مرتب‌سازی"><option value="latest">آخرین حذف/تغییر</option><option value="oldest" @selected(request('sort') === 'oldest')>قدیمی‌ترین</option><option value="name" @selected(request('sort') === 'name')>نام خانوادگی</option></select>
        <button class="ensha-secondary-btn" type="submit"><i class="ki-filled ki-filter"></i> اعمال</button>
        @if(request()->hasAny(['q','sort']))<a class="ensha-clear-filter" href="{{ route('users.trash') }}">پاک‌کردن</a>@endif
    </form>

    <div class="ensha-table-wrap">
        <table class="ensha-table ensha-data-table">
            <thead><tr><th>کاربر</th><th>شماره تلفن</th><th>نقش پیشین</th><th>مرکز</th><th>زمان حذف</th><th class="text-center">عملیات</th></tr></thead>
            <tbody>
            @forelse($users as $user)
                <tr>
                    <td><span class="ensha-table-user"><span class="ensha-avatar small muted">{{ mb_substr($user->display_name, 0, 1) }}</span><span><strong>{{ $user->display_name }}</strong><small>شناسه #{{ $user->id }}</small></span></span></td>
                    <td><span class="ensha-ltr-value">{{ $user->phone }}</span><small>{{ $user->national_id }}</small></td>
                    <td><span class="ensha-role-chip role-{{ $user->role }}">{{ $user->role_label }}</span></td>
                    <td>{{ $user->centre?->name ?? 'سراسری' }}</td>
                    <td>{{ $user->deleted_at?->format('Y/m/d H:i') }}</td>
                    <td class="text-center">
                        @can('restore', $user)
                            <form class="inline-block" method="POST" action="{{ route('users.restore', $user->id) }}" data-confirm="حساب {{ $user->display_name }} بازیابی شود؟ حساب پس از بازیابی غیرفعال خواهد بود.">@csrf @method('PATCH')<button class="ensha-secondary-btn" type="submit"><i class="ki-filled ki-arrows-circle"></i> بازیابی امن</button></form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="ensha-empty-state"><i class="ki-filled ki-trash"></i><strong>سطل حذف خالی است</strong><span>هیچ حساب حذف‌شده‌ای در دامنه دسترسی شما وجود ندارد.</span></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($users->hasPages())<div class="ensha-pagination">{{ $users->links() }}</div>@endif
</section>
@endsection
