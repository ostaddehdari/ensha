@extends('layouts.app', ['title'=>request()->routeIs('dashboard') ? 'داشبورد منشی' : 'اسکجول منشی'])
@push('head')
<link rel="stylesheet" href="{{ asset('css/secretary-calendar.css').'?v=0.24.2' }}">
<link rel="stylesheet" href="{{ asset('css/stage06.css').'?v=0.24.2' }}">
@endpush
@section('content')
@include('appointments.partials.nav')
<div class="ensha-page-heading">
  <div><span class="ensha-eyebrow">مرحله اول · W02</span><h2>{{ request()->routeIs('dashboard') ? 'داشبورد منشی و تقویم نوبت‌ها' : 'اسکجول منشی' }}</h2><p>تقویم شمسی شعبه‌ها؛ ثبت، ویرایش و جابه‌جایی با کنترل منطقه زمانی و تأیید سرور.</p></div>
  <a href="{{ route('attendance.index') }}" class="ensha-secondary-btn">حضور و غیاب</a>
</div>
<section class="ensha-card" id="stage06-scheduler"
  data-events="{{ route('appointments.calendar.api.events') }}"
  data-resources="{{ route('appointments.calendar.api.resources') }}"
  data-slots="{{ route('appointments.calendar.api.available-slots') }}"
  data-quote="{{ route('appointments.calendar.api.quote') }}"
  data-save="{{ route('appointments.calendar.api.store') }}"
  data-move="{{ url('/appointments/calendar/api/events') }}"
  data-clients="{{ route('stage06.clients.search') }}"
  data-create-client="{{ route('stage06.clients.quick') }}"
  data-csrf="{{ csrf_token() }}"
  data-manage="{{ auth()->user()->hasPermission('appointments.manage') ? '1':'0' }}"
  data-timezone="{{ $calendarTimezone }}"
  data-default-branch="{{ $defaultBranchId }}">
  <div class="secretary-toolbar">
    <div class="secretary-toolbar-group"><button type="button" data-prev aria-label="روز قبل">‹</button><button type="button" data-today>امروز</button><button type="button" data-next aria-label="روز بعد">›</button><strong data-title></strong><small data-timezone-label></small></div>
    <div class="secretary-toolbar-group"><select data-view aria-label="نمای تقویم"><option value="day">روز / ستون مشاوران</option><option value="week">هفته</option><option value="month">ماه واقعی</option></select></div>
  </div>
  <div class="stage12-calendar-filters" aria-label="فیلترهای تقویم">
    <select name="branch_id" data-calendar-filter><option value="">همه شعبه‌ها</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" data-timezone="{{ $branch->effectiveTimezone() }}">{{ $branch->name }}</option>@endforeach</select>
    <select name="counselor_id" data-calendar-filter><option value="">همه مشاوران</option>@foreach($counselors as $c)<option value="{{ $c->id }}">{{ $c->display_name }}</option>@endforeach</select>
    <select name="topic_id" data-calendar-filter><option value="">همه موضوعات</option>@foreach($topics as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>
    <select name="status" data-calendar-filter><option value="">همه وضعیت‌ها</option>@foreach($statuses as $s)<option value="{{ $s->slug }}">{{ $s->name }}</option>@endforeach</select>
    <select name="mode" data-calendar-filter><option value="">همه شیوه‌ها</option><option value="in_person">حضوری</option><option value="phone">تلفنی</option><option value="video">ویدیویی</option></select>
    <button type="button" class="ensha-secondary-btn" data-clear-filters>پاک‌کردن فیلترها</button>
  </div>
  <div class="stage06-calendar-layout">
    <aside class="stage06-mini" aria-label="تقویم شمسی"><div class="stage06-mini-head"><button type="button" data-month-prev aria-label="ماه قبل">›</button><strong data-month-title></strong><button type="button" data-month-next aria-label="ماه بعد">‹</button></div><div class="stage06-mini-grid" data-month-grid></div><p>برای انتخاب روز کلیک کنید.</p></aside>
    <div class="stage06-calendar-main"><p data-error role="alert"></p><p data-loading role="status">در حال دریافت برنامه…</p><div id="stage06-daypilot"></div><div id="stage06-month" hidden></div></div>
  </div>
</section>

<aside class="stage06-drawer" data-drawer hidden><div class="stage06-backdrop" data-close></div><div class="stage06-panel" role="dialog" aria-label="ثبت نوبت"><div class="secretary-modal-title"><strong>رزرو نوبت</strong><button type="button" data-close>×</button></div>
<form data-booking>
<label>شعبه<select name="branch_id" data-branch required><option value="">انتخاب شعبه</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" data-timezone="{{ $branch->effectiveTimezone() }}">{{ $branch->name }}</option>@endforeach</select></label>
<label>مشاور<select name="counselor_id" data-counselor required><option value="">انتخاب مشاور</option>@foreach($counselors as $c)<option value="{{ $c->id }}">{{ $c->display_name }}</option>@endforeach</select></label>
<label>موضوع<select name="topic_id" data-topic required><option value="">انتخاب موضوع</option>@foreach($topics as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></label>
<input type="hidden" name="mode" value="in_person">
<label>مراجع موجود <input data-search placeholder="جستجو کنید، سپس روی نتیجه بزنید" autocomplete="off"><small>برای مراجع جدید، مشخصات را در بخش زیر وارد کنید.</small></label><div data-results></div><input type="hidden" name="client_id"><p data-client-label></p>
<details open><summary>ثبت مراجع جدید همراه نوبت</summary><p>نام و نام خانوادگی را وارد کنید؛ با «ثبت نهایی نوبت»، مراجع و نوبت با هم ثبت می‌شوند.</p><div class="stage06-form-grid"><input name="first_name" placeholder="نام"><input name="last_name" placeholder="نام خانوادگی"><input name="phone" placeholder="تلفن"><input name="national_id" placeholder="کد ملی"></div></details>
<input name="appointment_date" type="hidden" required><input name="start_time" type="hidden" required>
<p class="stage06-picked" data-create-date></p>
<label>مدت مشاوره (دقیقه)<input name="duration_minutes" type="number" min="15" max="240" step="5" value="45" required></label>
<label>تخفیف<select name="discount_id"><option value="">بدون تخفیف</option>@foreach($discounts as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label>
<div class="stage06-pricing" data-pricing>مبلغ نهایی هنگام ثبت بر اساس مدت واردشده محاسبه می‌شود.</div>
<div class="stage06-pricing">پس از ثبت نوبت، دریافت وجه و صدور رسید از صفحه صندوق انجام می‌شود.</div><label>توضیح<textarea name="notes" maxlength="2000"></textarea></label>
<button class="ensha-primary-btn" type="submit">ثبت نهایی نوبت</button><p data-drawer-error role="alert"></p></form></div></aside>

<aside class="stage06-drawer" data-edit-drawer hidden><div class="stage06-backdrop" data-edit-close></div><div class="stage06-panel" role="dialog" aria-label="ویرایش نوبت"><div class="secretary-modal-title"><strong>ویرایش نوبت</strong><button type="button" data-edit-close>×</button></div>
<form data-edit-booking>
  <p class="stage06-picked" data-edit-summary></p>
  <label>شعبه<select name="branch_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}" data-timezone="{{ $branch->effectiveTimezone() }}">{{ $branch->name }}</option>@endforeach</select></label>
  <label>مشاور<select name="counselor_id" required>@foreach($counselors as $c)<option value="{{ $c->id }}">{{ $c->display_name }}</option>@endforeach</select></label>
  <label>تاریخ میلادی<input name="appointment_date" type="date" required><small data-edit-jalali></small></label>
  <label>ساعت شروع<input name="start_time" type="time" step="300" required></label>
  <label>مدت مشاوره (دقیقه)<input name="duration_minutes" type="number" min="15" max="240" step="5" required></label>
  <div class="stage06-modal-actions"><a class="ensha-secondary-btn" data-edit-details href="#">جزئیات کامل</a><button class="ensha-primary-btn" type="submit">ذخیره تغییرات</button></div>
  <p data-edit-error role="alert"></p>
</form></div></aside>
@endsection
@push('scripts')<script src="{{ asset('vendor/daypilot/daypilot-javascript.min.js') }}"></script><script src="{{ asset('js/stage06-scheduler.js').'?v=0.24.2' }}"></script>@endpush
