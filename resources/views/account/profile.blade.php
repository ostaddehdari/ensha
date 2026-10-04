@extends('layouts.app', ['title' => 'پروفایل من'])
@section('content')
<div class="ensha-page-heading"><div><span class="ensha-eyebrow">مشخصات من</span><h2>پروفایل من</h2><p>اطلاعات تماس و فیلدهای پروفایل خود را اینجا به‌روزرسانی کنید.</p></div><a class="ensha-secondary-btn" href="{{ route('account.password.edit') }}">تغییر رمز عبور</a></div>
<div class="ensha-self-profile-layout">
    <form method="POST" action="{{ route('profile.update') }}" class="ensha-card ensha-self-profile-form" enctype="multipart/form-data">@csrf @method('PUT')
        <div class="ensha-card-head"><div><span class="ensha-eyebrow">شناسه فرد</span><h3>اطلاعات هویتی</h3></div></div>
        <div class="ensha-self-profile-identity">
            <div><small>کد ملی · شناسه اصلی پرونده</small><strong dir="ltr">{{ $user->national_id }}</strong></div>
            <div><small>نام</small><strong>{{ $user->first_name }}</strong></div>
            <div><small>نام خانوادگی</small><strong>{{ $user->last_name }}</strong></div>
            <div><small>نقش کاربری</small><strong>{{ $user->role_label }}</strong></div>
            <div><small>مرکز</small><strong>{{ $user->centre?->name ?? 'سراسری' }}</strong></div>
        </div>
        <p class="field-hint ensha-self-profile-hint">برای اصلاح نام یا کد ملی، درخواست را به مدیر مرکز اعلام کنید.</p>
        <div class="ensha-self-profile-contact"><label>عکس پروفایل (JPEG، PNG یا WebP تا ۲ مگابایت)@if($user->avatar_url)<img src="{{ $user->avatar_url }}" alt="عکس پروفایل" width="80" height="80" style="border-radius:50%;object-fit:cover">@endif<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"></label>@error('avatar')<span class="ensha-self-profile-error">{{ $message }}</span>@enderror<label>شماره تماس و ورود <span class="required">*</span><input type="tel" name="phone" dir="ltr" inputmode="tel" maxlength="14" required value="{{ old('phone', $user->phone) }}"></label><small>اگر شماره تماس را تغییر دهید، رمز فعلی لازم است و نشست‌های دیگر شما پایان می‌یابند.</small><label>رمز فعلی، فقط برای تغییر شماره تماس<input type="password" name="current_password" autocomplete="current-password"></label>@error('phone')<span class="ensha-self-profile-error">{{ $message }}</span>@enderror @error('current_password')<span class="ensha-self-profile-error">{{ $message }}</span>@enderror</div>
        @if($profileFieldGroups->isNotEmpty())
            @include('users.partials.profile-fields')
        @else
            <div class="ensha-self-profile-empty">برای نقش شما هنوز فیلد تکمیلی تعریف نشده است.</div>
        @endif
        <div class="ensha-self-profile-actions"><button class="ensha-primary-btn" type="submit">ذخیره تغییرات پروفایل</button></div>
    </form>
</div>
@endsection
