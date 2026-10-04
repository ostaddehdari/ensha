<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ثبت‌نام مراجعه‌کننده | انشا</title>
    <link rel="stylesheet" href="{{ asset('assets/vendors/keenicons/styles.bundle.css') }}"><link rel="stylesheet" href="{{ asset('assets/css/core.bundle.css') }}"><link rel="stylesheet" href="{{ asset('assets/css/styles.css') }}"><link rel="stylesheet" href="{{ asset('css/ensha.css') }}">
</head>
<body class="ensha-auth-body compact-auth"><main class="ensha-auth-layout single-auth"><section class="ensha-auth-card-wrap"><div class="ensha-auth-card register-card">
    <div class="ensha-brand auth-brand"><div class="ensha-brand-mark"><span>ا</span></div><div><strong>انشا</strong><small>ثبت‌نام مراجعه‌کننده</small></div></div>
    <div class="ensha-auth-card-head"><span class="ensha-kicker">ساخت پرونده اولیه</span><h2>ثبت‌نام مراجعه‌کننده</h2><p>شناسه ورود شما شماره تلفن است و کد ملی به پرونده متصل می‌شود.</p></div>
    @if($errors->any())<div class="ensha-alert danger"><i class="ki-filled ki-information-2"></i>{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('register.store') }}" class="ensha-form">@csrf
        <div class="ensha-two-col"><label>نام<input name="first_name" value="{{ old('first_name') }}" required></label><label>نام خانوادگی<input name="last_name" value="{{ old('last_name') }}" required></label></div>
        <div class="ensha-two-col"><label>کد ملی<input name="national_id" value="{{ old('national_id') }}" inputmode="numeric" required></label><label>شماره تلفن<input type="tel" name="phone" value="{{ old('phone') }}" placeholder="0912..." required></label></div>
        <div class="ensha-two-col"><label>رمز عبور<input type="password" name="password" minlength="10" autocomplete="new-password" required><span class="field-hint">حروف بزرگ و کوچک، عدد و نماد</span></label><label>تکرار رمز عبور<input type="password" name="password_confirmation" minlength="10" autocomplete="new-password" required></label></div>
        <button class="ensha-primary-btn full" type="submit">ساخت حساب و ورود <i class="ki-filled ki-arrow-left"></i></button>
    </form>
    <a class="ensha-back-link" href="{{ route('login') }}"><i class="ki-filled ki-arrow-right"></i> بازگشت به ورود</a>
</div></section></main><script src="{{ asset('js/ensha.js') }}"></script></body></html>
