@extends('layouts.app', ['title' => 'تغییر رمز عبور'])

@section('content')
<div class="ensha-password-page">
    <section class="ensha-card">
        <div class="ensha-security-hero"><span><i class="ki-filled ki-key-square"></i></span><div><span class="ensha-eyebrow">امنیت حساب</span><h2>تغییر رمز عبور</h2><p>{{ auth()->user()->must_change_password ? 'رمز فعلی موقت است؛ برای ادامه یک رمز شخصی و قوی انتخاب کنید.' : 'برای حفاظت از حساب، رمز عبور خود را به‌صورت دوره‌ای تغییر دهید.' }}</p></div></div>
        <form method="POST" action="{{ route('account.password.update') }}" class="ensha-form">@csrf @method('PUT')
            <label>رمز عبور فعلی<input type="password" name="current_password" autocomplete="current-password" required></label>
            <label>رمز عبور جدید<span class="ensha-input-action"><input type="password" name="password" data-password-input minlength="10" autocomplete="new-password" required><button type="button" data-generate-password>ساخت رمز قوی</button></span><span class="field-hint">حداقل ۱۰ کاراکتر و شامل حروف بزرگ و کوچک، عدد و نماد</span></label>
            <label>تکرار رمز عبور جدید<input type="password" name="password_confirmation" minlength="10" autocomplete="new-password" required></label>
            <button class="ensha-primary-btn full" type="submit"><i class="ki-filled ki-shield-tick"></i> ذخیره رمز جدید</button>
        </form>
    </section>
</div>
@endsection
