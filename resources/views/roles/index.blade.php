@extends('layouts.app', ['title' => 'نقش‌ها و دسترسی‌ها'])

@section('content')
<div class="ensha-page-heading">
    <div><span class="ensha-eyebrow">کنترل دسترسی مبتنی بر نقش</span><h2>نقش‌ها و دسترسی‌ها</h2><p>هر نقش مجموعه مشخصی از مجوزها دارد؛ تغییرات بلافاصله برای کاربران آن نقش اعمال می‌شود.</p></div>
    <div class="ensha-heading-actions"><a class="ensha-secondary-btn" href="{{ route('permissions.index') }}"><i class="ki-filled ki-shield-search"></i> کاتالوگ مجوزها</a>@can('create', App\Models\Role::class)<a class="ensha-primary-btn" href="{{ route('roles.create') }}"><i class="ki-filled ki-plus"></i> نقش سفارشی</a>@endcan</div>
</div>

<div class="ensha-access-overview">
    <div><span class="icon"><i class="ki-filled ki-security-user"></i></span><div><strong>{{ $roles->count() }}</strong><small>نقش تعریف‌شده</small></div></div>
    <div><span class="icon"><i class="ki-filled ki-shield-tick"></i></span><div><strong>{{ $permissionCount }}</strong><small>مجوز عملیاتی</small></div></div>
    <p><i class="ki-filled ki-information-2"></i> نقش‌های سیستمی قابل حذف نیستند، اما به‌جز ادمین سیستم می‌توانید ماتریس مجوزهای آن‌ها را تنظیم کنید.</p>
</div>

<div class="ensha-role-grid">
    @foreach($roles as $role)
    <article class="ensha-role-card">
        <div class="ensha-role-card-head"><span class="ensha-role-icon tone-{{ $role->color }}"><i class="ki-filled ki-security-user"></i></span><div><h3>{{ $role->name }}</h3><code>{{ $role->slug }}</code></div><span class="ensha-status {{ $role->is_active ? 'success' : 'danger' }}">{{ $role->is_active ? 'فعال' : 'غیرفعال' }}</span></div>
        <p>{{ $role->description ?: 'برای این نقش توضیحی ثبت نشده است.' }}</p>
        <div class="ensha-role-stats"><span><strong>{{ $role->users_count }}</strong> کاربر</span><span><strong>{{ $role->slug === 'super_admin' ? $permissionCount : $role->permissions_count }}</strong> مجوز</span><span>{{ $role->scope === 'global' ? 'سراسری' : 'مرکز‌محور' }}</span></div>
        <div class="ensha-role-card-footer"><span>{{ $role->is_system ? 'نقش سیستمی' : 'نقش سفارشی' }}</span>@can('update', $role)<a href="{{ route('roles.edit', $role) }}">تنظیم دسترسی‌ها <i class="ki-filled ki-arrow-left"></i></a>@endcan</div>
    </article>
    @endforeach
</div>
@endsection
