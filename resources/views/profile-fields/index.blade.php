@extends('layouts.app', ['title'=>'فرم‌ساز پروفایل'])
@section('content')
<div class="ensha-page-heading"><div><span class="ensha-eyebrow">طراحی پروفایل کاربران</span><h2>فرم‌ساز پروفایل</h2><p>تغییرات را انجام دهید و در پایان یک‌بار «ذخیره فرم» را بزنید.</p></div><div class="ensha-heading-actions"><span id="builder-dirty" class="ensha-builder-dirty" hidden>تغییرات ذخیره‌نشده</span><button type="button" class="ensha-secondary-btn" id="builder-preview-toggle">پیش‌نمایش</button><button type="button" class="ensha-primary-btn" id="builder-save">ذخیره فرم</button></div></div>
<nav class="ensha-builder-tabs" aria-label="انتخاب نقش">@foreach($tabs as $slug=>$title)<a class="{{ $role===$slug?'active':'' }}" href="{{ route('profile-fields.index',['role'=>$slug]) }}">{{ $title }}</a>@endforeach</nav>
<div class="ensha-builder" data-role="{{ $role }}" data-save-url="{{ route('profile-fields.save-all') }}">
    <aside class="ensha-builder-sidebar"><div class="ensha-builder-sidebar-head"><strong id="builder-panel-title">افزودن فیلد</strong><button type="button" id="builder-panel-back" hidden>فهرست فیلدها</button></div>
        <div id="builder-palette-panel"><p class="ensha-builder-hint">نوع فیلد را کلیک کنید یا روی فرم بکشید. افزودن فیلد تا زمان ذخیره نهایی روی سرور ثبت نمی‌شود.</p><input id="builder-search" class="kt-input" type="search" placeholder="جست‌وجوی نوع فیلد" aria-label="جست‌وجوی نوع فیلد"><div class="ensha-builder-palette" id="builder-palette">
        @foreach($types as $type=>$title)@php($icons=['text'=>'ki-text','textarea'=>'ki-paragraph','email'=>'ki-sms','tel'=>'ki-phone','url'=>'ki-link','number'=>'ki-hashtag','date'=>'ki-calendar','time'=>'ki-time','datetime'=>'ki-calendar-tick','select'=>'ki-down-square','radio'=>'ki-radio','checkbox_group'=>'ki-check-square','multi_select'=>'ki-category','boolean'=>'ki-toggle-on','heading'=>'ki-text-bold','paragraph'=>'ki-message-text','divider'=>'ki-minus-square'])<button type="button" class="ensha-builder-type" draggable="true" data-type="{{ $type }}" data-label="{{ $title }}"><i class="ki-filled {{ $icons[$type]??'ki-element-11' }}"></i><span>{{ $title }}</span></button>@endforeach
        </div></div>
        <div id="builder-settings-panel" class="ensha-builder-settings" hidden>
            <label>برچسب فیلد<input class="kt-input" data-property="label" maxlength="150"></label>
            <label>کلید فنی<input class="kt-input" data-property="key" maxlength="100" dir="ltr"><small>پس از ثبت پاسخ، کلید و نوع فیلد ثابت می‌مانند.</small></label>
            <label>نوع فیلد<select class="kt-input" data-property="field_type">@foreach($types as $type=>$title)<option value="{{ $type }}">{{ $title }}</option>@endforeach</select></label>
            <div id="builder-options-editor"><strong>گزینه‌ها</strong><p class="ensha-builder-hint">برای هر گزینه برچسب نمایشی و مقدار ذخیره‌شونده را وارد کنید.</p><div id="builder-option-rows"></div><button type="button" class="ensha-secondary-btn" id="builder-option-add">افزودن گزینه</button></div>
            <label>متن راهنما<textarea class="kt-input" data-setting="help" rows="2" maxlength="500"></textarea></label>
            <label>متن داخل فیلد<input class="kt-input" data-setting="placeholder" maxlength="200"></label>
            <label>مقدار اولیه<input class="kt-input" data-setting="default" maxlength="500"></label>
            <label>عرض فیلد<select class="kt-input" data-setting="width"><option value="full">تمام‌عرض</option><option value="half">نیم‌عرض</option></select></label>
            <label class="ensha-builder-check"><input type="checkbox" data-property="is_required"> پاسخ الزامی باشد</label>
            <label class="ensha-builder-check"><input type="checkbox" data-property="is_active"> فیلد فعال باشد</label>
        </div>
    </aside>
    <section class="ensha-builder-canvas"><div class="ensha-builder-canvas-top"><div><strong id="builder-form-title">فرم {{ $tabs[$role] }}</strong><small>فیلدها را بکشید و مرتب کنید؛ با کلیک روی هر فیلد تنظیمات باز می‌شود.</small></div><span class="ensha-builder-count" id="builder-count"></span></div>
        <div class="ensha-builder-core" id="builder-core" @if($role!=='all') hidden @endif><span>مشخصات هویتی ثابت</span><div><b>نام <em>*</em></b><b>نام خانوادگی <em>*</em></b><b>شماره تماس <em>*</em></b><b>کد ملی <em>*</em></b></div><small>این فیلدها برای ورود و شناسایی استفاده می‌شوند و قابل حذف نیستند.</small></div>
        <div class="ensha-builder-shared-note" id="builder-shared" @if($role==='all') hidden @endif>فیلدهای تب «همه» نیز در فرم این نقش نمایش داده می‌شوند. <a href="{{ route('profile-fields.index',['role'=>'all']) }}">ویرایش فیلدهای مشترک</a></div>
        <div id="builder-cards" class="ensha-builder-cards" data-empty="از فهرست سمت چپ یک فیلد اضافه کنید."></div><div class="ensha-builder-dropzone" id="builder-dropzone">+ فیلد را اینجا رها کنید</div><p id="builder-feedback" class="ensha-builder-feedback" aria-live="polite">در حال آماده‌سازی فرم‌ساز…</p>
    </section>
</div>
<script type="application/json" id="builder-initial">@json($formsPayload)</script>
@push('head')<link rel="stylesheet" href="{{ asset('css/profile-builder.css').'?v=0.6.1' }}">@endpush
@push('scripts')<script src="{{ asset('js/profile-builder.js').'?v=0.6.1' }}" defer></script>@endpush
@endsection
