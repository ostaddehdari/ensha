@extends('layouts.app', ['title' => $label])

@section('content')
<div class="ensha-placeholder-page"><div class="ensha-placeholder-icon"><i class="ki-filled ki-construction"></i></div><span class="ensha-eyebrow">ماژول آماده اتصال</span><h2>{{ $label }}</h2><p>منوی این بخش در نسخه پایه ساخته شده است. منطق عملیاتی آن در مرحله بعد به دیتابیس مستقل مرکز متصل می‌شود.</p><div class="ensha-placeholder-meta"><span><i class="ki-filled ki-check-circle"></i> مسیر و سطح دسترسی آماده</span><span><i class="ki-filled ki-check-circle"></i> ظاهر Metronic RTL آماده</span><span><i class="ki-filled ki-time"></i> عملیات داخلی: مرحله بعد</span></div><a class="ensha-secondary-btn" href="{{ route('dashboard') }}"><i class="ki-filled ki-arrow-right"></i> بازگشت به داشبورد</a></div>
@endsection
