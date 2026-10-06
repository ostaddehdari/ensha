@extends('layouts.app', ['title'=>request()->routeIs('dashboard') ? 'داشبورد منشی' : 'اسکجول منشی'])
@push('head')<link rel="stylesheet" href="{{ asset('css/secretary-calendar.css').'?v=0.18.1' }}"><link rel="stylesheet" href="{{ asset('css/stage06.css').'?v=0.18.1' }}">@endpush
@section('content')
@include('appointments.partials.nav')
<div class="ensha-page-heading"><div><span class="ensha-eyebrow">Stage 06</span><h2>{{ request()->routeIs('dashboard') ? 'داشبورد منشی و تقویم نوبت‌ها' : 'اسکجول منشی' }}</h2><p>برنامهٔ روزانهٔ چندمشاوره و نمایش هفتگی؛ ثبت و جابه‌جایی با تأیید سرور.</p></div><a href="{{ route('attendance.index') }}" class="ensha-secondary-btn">حضور و غیاب</a></div>
<section class="ensha-card" id="stage06-scheduler" data-events="{{ route('appointments.calendar.api.events') }}" data-resources="{{ route('appointments.calendar.api.resources') }}" data-slots="{{ route('appointments.calendar.api.available-slots') }}" data-quote="{{ route('appointments.calendar.api.quote') }}" data-save="{{ route('appointments.calendar.api.store') }}" data-move="{{ url('/appointments/calendar/api/events') }}" data-clients="{{ route('stage06.clients.search') }}" data-create-client="{{ route('stage06.clients.quick') }}" data-csrf="{{ csrf_token() }}" data-manage="{{ auth()->user()->hasPermission('appointments.manage') ? '1':'0' }}">
  <div class="secretary-toolbar"><div class="secretary-toolbar-group"><button type="button" data-prev aria-label="روز قبل">‹</button><button type="button" data-today>امروز</button><button type="button" data-next aria-label="روز بعد">›</button><strong data-title></strong></div>
    <div class="secretary-toolbar-group"><select data-view aria-label="نمای تقویم"><option value="day">روز / ستون مشاوران</option><option value="week">هفته</option></select></div></div>
  <div class="stage06-calendar-layout"><aside class="stage06-mini" aria-label="تقویم شمسی"><div class="stage06-mini-head"><button type="button" data-month-prev aria-label="ماه قبل">›</button><strong data-month-title></strong><button type="button" data-month-next aria-label="ماه بعد">‹</button></div><div class="stage06-mini-grid" data-month-grid></div><p>برای انتخاب روز کلیک کنید.</p></aside>
    <div class="stage06-calendar-main"><p data-error role="alert"></p><p data-loading role="status">در حال دریافت برنامه…</p><div id="stage06-daypilot" style="min-height:600px"></div></div></div>
</section>
<aside class="stage06-drawer" data-drawer hidden><div class="stage06-backdrop" data-close></div><div class="stage06-panel" role="dialog" aria-label="ثبت نوبت"><div class="secretary-modal-title"><strong>رزرو نوبت</strong><button type="button" data-close>×</button></div>
<form data-booking><p class="stage06-picked" data-picked>برای ثبت نوبت، زمان را از تقویم انتخاب کنید.</p>
<label>مشاور<select data-counselor><option value="">همه مشاوران</option>@foreach($counselors as $c)<option value="{{ $c->id }}">{{ $c->display_name }}</option>@endforeach</select></label>
<label>موضوع<select data-topic><option value="">همه موضوعات</option>@foreach($topics as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></label>
<label>شیوه ارائه<select data-mode><option value="">همه شیوه‌ها</option><option value="in_person">حضوری</option><option value="video">آنلاین</option><option value="phone">تلفنی</option></select></label>
<label>وضعیت قابل مشاهده<select data-status><option value="">همه وضعیت‌ها</option>@foreach($statuses as $s)<option value="{{ $s->slug }}">{{ $s->name }}</option>@endforeach</select></label>
<label>مراجع <input data-search placeholder="نام، موبایل، کد ملی یا کد پرونده" autocomplete="off"></label><div data-results></div><input type="hidden" name="client_id" required><p data-client-label></p>
<details><summary>+ ثبت مراجع جدید</summary><div class="stage06-form-grid"><input name="first_name" placeholder="نام"><input name="last_name" placeholder="نام خانوادگی"><input name="phone" placeholder="تلفن"><input name="national_id" placeholder="کد ملی"></div><button type="button" data-client-create>ایجاد مراجع</button></details>
<label>زمان و مشاور<select name="slot_id" data-slot required><option value="">انتخاب کنید</option></select></label>
<label>تخفیف<select name="discount_id"><option value="">بدون تخفیف</option>@foreach($discounts as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label>
<div class="stage06-pricing" data-pricing>مدت و قیمت پس از انتخاب زمان نمایش داده می‌شود.</div>
<label>مبلغ پرداختی<input name="paid_amount" type="number" min="0" value="0"></label><label>یادداشت پرداخت<input name="payment_note"></label><label>توضیح<textarea name="notes" maxlength="2000"></textarea></label>
<button class="ensha-primary-btn" type="submit">ثبت نهایی نوبت</button><p data-drawer-error role="alert"></p></form></div></aside>
@endsection
@push('scripts')<script src="{{ asset('vendor/daypilot/daypilot-javascript.min.js') }}"></script><script src="{{ asset('js/stage06-scheduler.js').'?v=0.18.1' }}"></script>@endpush
