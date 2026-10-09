@extends('layouts.app',['title'=>'اتصالات خارجی'])
@section('content')
<div class="ensha-page-heading"><div><span class="ensha-eyebrow">{{ $centre->name }}</span><h2>اتصالات خارجی مرکز</h2><p>کلیدها رمزگذاری می‌شوند و هیچ‌گاه دوباره در صفحه نمایش داده نمی‌شوند.</p></div></div>
@foreach(['wordpress'=>'WordPress / Elementor','sms_kavenegar'=>'پیامک Kavenegar','whisper'=>'Whisper سازگار با OpenAI','zarinpal'=>'درگاه اختیاری زرین‌پال'] as $driver=>$label)
@php($item=$integrations->get($driver))
<section class="ensha-card report-card"><div class="report-card-head"><div><h3>{{ $label }}</h3><p>وضعیت: {{ $item?->is_active?'فعال':'غیرفعال' }} — آخرین بررسی: {{ $item?->last_status??'انجام نشده' }}</p></div></div>
<form method="POST" action="{{ route('centres.integrations.update',[$centre,$driver]) }}" class="report-create-run">@csrf @method('PUT')<label><input type="checkbox" name="is_active" value="1" @checked($item?->is_active)> فعال</label>
@if(in_array($driver,['whisper']))<label>Endpoint<input name="endpoint" type="url" value="{{ data_get($item?->settings,'endpoint') }}" placeholder="https://.../v1"></label><label>مدل<input name="model" value="{{ data_get($item?->settings,'model','whisper-1') }}"></label>@endif
@if($driver==='sms_kavenegar')<label>شماره فرستنده<input name="sender" value="{{ data_get($item?->settings,'sender') }}"></label>@endif
@if(in_array($driver,['wordpress','sms_kavenegar','whisper']))<label>کلید API جدید<input name="api_key" type="password" minlength="{{ $driver==='wordpress'?32:1 }}" autocomplete="new-password" placeholder="برای حفظ کلید فعلی خالی بگذارید"></label>@endif
@if($driver==='zarinpal')<label>Merchant ID جدید<input name="merchant_id" type="password" autocomplete="new-password"></label>@endif
<button class="ensha-primary-btn">ذخیره</button></form>
@if($item)<form method="POST" action="{{ route('centres.integrations.check',[$centre,$driver]) }}">@csrf<button class="ensha-secondary-btn">بررسی اتصال</button></form>@endif
</section>@endforeach
@endsection
