@extends('layouts.app', ['title' => 'ویرایش کاربر'])

@section('content')
<div class="ensha-page-heading"><div><a class="ensha-back-crumb" href="{{ route('users.show', $user) }}"><i class="ki-filled ki-arrow-right"></i> پروفایل کاربر</a><h2>ویرایش {{ $user->display_name }}</h2><p>تغییرات نقش و مرکز بلافاصله روی سطح دسترسی کاربر اثر می‌گذارد.</p></div></div>
<form method="POST" action="{{ route('users.update', $user) }}" class="ensha-record-layout">@csrf @method('PUT')
    <section class="ensha-card"><div class="ensha-card-head"><div><span class="ensha-eyebrow">ویرایش حساب</span><h3>اطلاعات هویتی و سازمانی</h3></div><span class="ensha-status {{ $user->status_tone }}">{{ $user->status_label }}</span></div>@include('users.partials.form')</section>
    <aside class="ensha-card ensha-side-help"><div class="ensha-help-icon"><i class="ki-filled ki-information-2"></i></div><h3>کنترل تغییرات</h3><p>برای حفظ قابلیت پیگیری، مقدار قبل و بعد فیلدهای حساس در تاریخچه امنیتی ذخیره می‌شود.</p><ul><li>نقش حساب فعلی خودتان قابل تغییر نیست.</li><li>آخرین ادمین فعال قابل تنزل نیست.</li><li>رمز عبور از صفحه جزئیات بازنشانی می‌شود.</li></ul><button class="ensha-primary-btn full" type="submit"><i class="ki-filled ki-check"></i> ذخیره تغییرات</button><a class="ensha-secondary-btn full" href="{{ route('users.show', $user) }}">انصراف</a></aside>
</form>
@push('scripts')<script src="{{ asset('js/profile-fields-user.js') }}" defer></script>@endpush
@endsection
