@extends('layouts.app', ['title' => 'کاتالوگ مجوزها'])

@section('content')
<div class="ensha-page-heading"><div><a class="ensha-back-crumb" href="{{ route('roles.index') }}"><i class="ki-filled ki-arrow-right"></i> نقش‌ها و دسترسی‌ها</a><h2>کاتالوگ مجوزهای سامانه</h2><p>این صفحه مرجع مجوزهای عملیاتی است؛ تخصیص آن‌ها از صفحه ویرایش هر نقش انجام می‌شود.</p></div></div>
<div class="ensha-catalog-grid">
    @foreach($permissions as $groupKey => $items)
    <section class="ensha-card ensha-catalog-card">
        <div class="ensha-card-head"><div><span class="ensha-eyebrow">{{ $groupKey }}</span><h3>{{ $items->first()->group_name }}</h3></div><span class="ensha-badge-soft">{{ $items->count() }} مجوز</span></div>
        <div class="ensha-catalog-list">
            @foreach($items as $permission)
            <div><span class="ensha-audit-icon info"><i class="ki-filled ki-shield-tick"></i></span><div><strong>{{ $permission->name }}</strong><p>{{ $permission->description }}</p><code>{{ $permission->slug }}</code><small>نقش‌ها: {{ $permission->roles->pluck('name')->join('، ') ?: 'تخصیص‌نیافته' }}</small></div></div>
            @endforeach
        </div>
    </section>
    @endforeach
</div>
@endsection
