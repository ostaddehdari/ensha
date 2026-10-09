@extends('layouts.app',['title'=>'گزارش جلسه '.$appointment->appointment_number])
@push('head')<link rel="stylesheet" href="{{ asset('css/stage07-clinical.css').'?v=0.19.0' }}">@endpush
@section('content')
<div class="clinical-heading"><div><span class="ensha-eyebrow">گزارش محرمانه جلسه</span><h2>{{ $appointment->client?->user?->display_name }}</h2><p>{{ $appointment->topic_name_snapshot ?: $appointment->topic?->name }} · {{ $appointment->appointment_number }}</p></div><a class="ensha-secondary-btn" href="{{ route('counselor.workspace') }}"><i class="ki-filled ki-left"></i> فضای کاری مشاور</a></div>

<section class="clinical-card mb-4">
    <div class="session-summary"><div><span>زمان نوبت</span><strong data-jalali-datetime="{{ $appointment->starts_at->toIso8601String() }}">{{ $appointment->starts_at->format('Y-m-d H:i') }}</strong></div><div><span>شیوه ارائه</span><strong>{{ ['in_person'=>'حضوری','phone'=>'تلفنی','video'=>'ویدیویی'][$appointment->mode] ?? $appointment->mode }}</strong></div><div><span>وضعیت نوبت</span><strong>{{ $appointment->status_snapshot ?: $appointment->status }}</strong></div><div><span>وضعیت گزارش</span><strong>{{ $report?->status==='finalized'?'نهایی و قفل‌شده':($report?'پیش‌نویس':'ثبت نشده') }}</strong></div></div>
    @if($canManage)<div class="session-actions">
        @if(!in_array($appointment->status,['in_session','completed','cancelled','no_show'],true))<form method="post" action="{{ route('counselor.sessions.start',$appointment) }}">@csrf<button class="kt-btn kt-btn-primary"><i class="ki-filled ki-play"></i> شروع جلسه</button></form>@endif
        @if($appointment->status==='in_session')<form method="post" action="{{ route('counselor.sessions.complete',$appointment) }}">@csrf<button class="kt-btn kt-btn-success"><i class="ki-filled ki-check"></i> پایان جلسه</button></form>@endif
        <a class="kt-btn kt-btn-outline" href="{{ route('clients.show',$appointment->client) }}">پرونده مراجع</a>
    </div>@endif
</section>

<div class="report-layout">
    <main>
        @if($report?->isFinalized())
        <section class="report-section"><div class="report-lock"><strong>گزارش نهایی است.</strong> محتوای اصلی قفل شده و هر توضیح بعدی باید به‌صورت الحاقیه ثبت شود.</div><div class="mt-4"><div class="report-answer"><span>شرح جلسه</span><strong>{{ $report->summary ?: '—' }}</strong></div><div class="report-answer"><span>نتیجه و اتفاق جلسه</span><strong>{{ $report->outcome ?: '—' }}</strong></div><div class="report-answer"><span>پیشنهادها و برنامه ادامه</span><strong>{{ $report->recommendations ?: '—' }}</strong></div>@foreach($fields as $field)<div class="report-answer"><span>{{ $field['label'] }}</span><strong>@php($value=$answers[$field['key']]??null){{ $field['type']==='checkbox'?($value?'بله':'خیر'):($value?:'—') }}</strong></div>@endforeach</div><p class="text-xs text-muted-foreground mt-4" dir="ltr">SHA-256: {{ $report->content_hash }}</p></section>
        @else
        <form method="post" action="{{ route('counselor.reports.save',$appointment) }}" class="report-form">@csrf @method('PUT')
            <section class="report-section"><h3>گزارش متنی جلسه</h3><div class="report-form-grid"><label class="col-span-2">شرح آنچه در جلسه اتفاق افتاد<textarea class="kt-input" name="summary" maxlength="50000">{{ old('summary',$report?->summary) }}</textarea></label><label>نتیجه جلسه<textarea class="kt-input" name="outcome" maxlength="20000">{{ old('outcome',$report?->outcome) }}</textarea></label><label>پیشنهادها و ادامه مسیر<textarea class="kt-input" name="recommendations" maxlength="20000">{{ old('recommendations',$report?->recommendations) }}</textarea></label></div></section>
            <section class="report-section"><h3>{{ $template?->name ?: 'فرم سریع گزارش' }}</h3><div class="structured-fields">@forelse($fields as $field)@php($key=$field['key'];$value=old("answers.$key",$answers[$key]??null))
                @if($field['type']==='checkbox')<label class="structured-checkbox"><input type="hidden" name="answers[{{ $key }}]" value="0"><input type="checkbox" name="answers[{{ $key }}]" value="1" @checked((bool)$value)> {{ $field['label'] }}</label>
                @elseif($field['type']==='select')<label>{{ $field['label'] }}@if($field['required']) * @endif<select class="kt-select" name="answers[{{ $key }}]" @required($field['required'])><option value="">انتخاب کنید</option>@foreach($field['options'] as $option)<option value="{{ $option }}" @selected($value===$option)>{{ $option }}</option>@endforeach</select></label>
                @elseif($field['type']==='textarea')<label>{{ $field['label'] }}@if($field['required']) * @endif<textarea class="kt-input" name="answers[{{ $key }}]" @required($field['required'])>{{ $value }}</textarea></label>
                @else<label>{{ $field['label'] }}@if($field['required']) * @endif<input class="kt-input" type="{{ $field['type']==='number'?'number':'text' }}" name="answers[{{ $key }}]" value="{{ $value }}" @required($field['required'])></label>@endif
            @empty<p class="text-muted-foreground">برای این موضوع فرم تیک‌زدنی تعریف نشده؛ گزارش متنی قابل ثبت است.</p>@endforelse</div></section>
            <section class="report-section"><h3>پیگیری</h3><div class="report-form-grid"><label class="structured-checkbox"><input type="checkbox" name="follow_up_required" value="1" @checked(old('follow_up_required',$report?->follow_up_required))> نیازمند جلسه یا پیگیری بعدی</label><label>زمان پیشنهادی پیگیری<input class="kt-input" type="datetime-local" name="follow_up_at" value="{{ old('follow_up_at',$report?->follow_up_at?->format('Y-m-d\\TH:i')) }}"></label></div><div class="session-actions"><button class="kt-btn kt-btn-primary" type="submit"><i class="ki-filled ki-save-2"></i> ذخیره پیش‌نویس</button></div></section>
        </form>
        @if($report && $canManage)<form method="post" action="{{ route('counselor.reports.finalize',$appointment) }}" class="report-section mt-4">@csrf @method('PATCH')<h3>نهایی‌سازی</h3><p class="text-muted-foreground">پس از پایان جلسه و نهایی‌سازی، متن اصلی دیگر قابل ویرایش نیست.</p><button class="kt-btn kt-btn-success" @disabled($appointment->status!=='completed')><i class="ki-filled ki-lock"></i> نهایی و قفل‌کردن گزارش</button></form>@endif
        @endif

        @if($report?->isFinalized())<section class="report-section mt-4"><h3>الحاقیه‌ها</h3>@forelse($report->addenda as $addendum)<div class="report-addendum"><p>{{ $addendum->body }}</p><small>{{ $addendum->author?->display_name }} · <span data-jalali-datetime="{{ $addendum->created_at->toIso8601String() }}">{{ $addendum->created_at->format('Y-m-d H:i') }}</span></small></div>@empty<p class="text-muted-foreground">الحاقیه‌ای ثبت نشده است.</p>@endforelse @if($canManage)<form method="post" action="{{ route('counselor.reports.addendum',$appointment) }}" class="mt-4 grid gap-2">@csrf<textarea class="kt-input" name="body" required maxlength="20000" placeholder="متن الحاقیه"></textarea><button class="kt-btn kt-btn-outline">ثبت الحاقیه</button></form>@endif</section>@endif
    </main>
    <aside>
        <section class="privacy-card {{ $recordingConsent?'ok':'warn' }}"><strong><i class="ki-filled {{ $recordingConsent?'ki-shield-tick':'ki-information-2' }}"></i> رضایت ضبط جلسه</strong><p class="mt-2 text-sm">{{ $recordingConsent?'رضایت معتبر ضبط جلسه در پرونده ثبت شده است.':'رضایت معتبر ضبط جلسه ثبت نشده؛ ضبط صوت مجاز نیست.' }}</p><small>ضبط و تبدیل صوت در Stage 08 روی همین جلسه فعال خواهد شد.</small></section>
        <section class="privacy-card mt-3"><strong>حریم خصوصی</strong><p class="mt-2 text-sm">گزارش بالینی رمزگذاری می‌شود و منشی به محتوای آن دسترسی ندارد.</p></section>
    </aside>
</div>
@endsection
@push('scripts')<script src="{{ asset('js/stage07-clinical.js').'?v=0.19.0' }}"></script>@endpush
