<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ورود | انشا</title>
    <link rel="stylesheet" href="{{ asset('assets/vendors/keenicons/styles.bundle.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/core.bundle.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/styles.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ensha.css') }}">
</head>
<body class="ensha-auth-body">
<div class="ensha-auth-orb orb-one"></div><div class="ensha-auth-orb orb-two"></div>
<main class="ensha-auth-layout">
    <section class="ensha-auth-intro">
        <div class="ensha-brand auth-brand"><div class="ensha-brand-mark"><span>ا</span></div><div><strong>انشا</strong><small>مرکز مشاوره و رشد</small></div></div>
        <div class="ensha-intro-copy"><span class="ensha-eyebrow">ENSHA · COUNSELLING OS</span><h1>یک فضای امن برای مدیریت<br><em>مسیر حال خوب</em></h1><p>ورود یکپارچهٔ مدیر، منشی، مشاور، مسئول تست و مراجعه‌کننده به زیرساخت مرکز مشاوره.</p></div>
        <div class="ensha-auth-points"><span><i class="ki-filled ki-shield-tick"></i> دسترسی نقش‌محور</span><span><i class="ki-filled ki-calendar-tick"></i> آماده برای نوبت‌دهی</span><span><i class="ki-filled ki-lock"></i> داده‌های مستقل مرکز</span></div>
    </section>
    <section class="ensha-auth-card-wrap">
        <div class="ensha-auth-card">
            <div class="ensha-auth-card-head"><span class="ensha-kicker">پنل امن کارکنان و مراجعین</span><h2>خوش آمدید</h2><p>برای ورود، شماره تلفن و رمز عبور خود را وارد کنید.</p></div>
            @if($errors->any())<div class="ensha-alert danger"><i class="ki-filled ki-information-2"></i>{{ $errors->first() }}</div>@endif
            @if(session('success'))<div class="ensha-alert success"><i class="ki-filled ki-check-circle"></i>{{ session('success') }}</div>@endif
            <form method="POST" action="{{ route('login.store') }}" class="ensha-form auth-form">@csrf
                <label>شماره تلفن<input type="tel" name="phone" value="{{ old('phone') }}" placeholder="مثلاً 09120000000" autocomplete="tel" required autofocus></label>
                <label>رمز عبور<div class="ensha-password"><input type="password" name="password" placeholder="رمز عبور خود را وارد کنید" autocomplete="current-password" required><button type="button" data-toggle-password>نمایش</button></div></label>
                <div class="ensha-form-row"><label class="ensha-check"><input type="checkbox" name="remember" value="1"> مرا به خاطر بسپار</label><span class="ensha-muted-link">بازنشانی رمز توسط مدیر مرکز</span></div>
                <button class="ensha-primary-btn full" type="submit"><span>ورود به سامانه</span><i class="ki-filled ki-arrow-left"></i></button>
            </form>
            <div class="ensha-auth-separator"><span>مراجعه‌کننده جدید هستید؟</span></div>
            <a class="ensha-secondary-btn full" href="{{ route('register') }}">ثبت‌نام با کد ملی و شماره تلفن</a>
            <div class="ensha-demo-note"><i class="ki-filled ki-information-2"></i><span>نسخه اولیه: اطلاعات ورود نمونه در راهنمای نصب درج شده است.</span></div>
        </div>
    </section>
</main>
<script src="{{ asset('js/ensha.js') }}"></script>
</body>
</html>
