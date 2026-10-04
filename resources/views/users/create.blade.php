@extends('layouts.app', ['title' => 'افزودن کاربر'])

@section('content')
<div class="ensha-page-heading"><div><a class="ensha-back-crumb" href="{{ route('users.index') }}"><i class="ki-filled ki-arrow-right"></i> کاربران</a><h2>افزودن کاربر جدید</h2><p>حساب جدید با شماره تلفن وارد می‌شود و در ورود اول باید رمز موقت را تغییر دهد.</p></div></div>
<form method="POST" action="{{ route('users.store') }}" class="ensha-record-layout">@csrf
    <section class="ensha-card"><div class="ensha-card-head"><div><span class="ensha-eyebrow">مشخصات پایه</span><h3>اطلاعات هویتی و سازمانی</h3></div><i class="ki-filled ki-profile-circle ensha-muted-icon"></i></div>@include('users.partials.form')</section>
    <aside class="ensha-card ensha-side-help"><div class="ensha-help-icon"><i class="ki-filled ki-shield-tick"></i></div><h3>ساخت حساب امن</h3><p>شماره تلفن، شناسه ورود کاربر است. کد ملی با الگوریتم رسمی کنترل می‌شود و رمز اولیه فقط تا اولین ورود معتبر می‌ماند.</p><ul><li>مجوزها از نقش انتخابی اعمال می‌شوند.</li><li>مدیر فقط کاربران مرکز خودش را می‌سازد.</li><li>تمام عملیات در گزارش امنیتی ثبت می‌شود.</li></ul><button class="ensha-primary-btn full" type="submit"><i class="ki-filled ki-check"></i> ایجاد حساب</button><a class="ensha-secondary-btn full" href="{{ route('users.index') }}">انصراف</a></aside>
</form>
@push('scripts')<script src="{{ asset('js/profile-fields-user.js') }}" defer></script>@endpush
@endsection
