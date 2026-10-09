@extends('layouts.app',['title'=>'فضای کاری مشاور'])
@push('head')<link rel="stylesheet" href="{{ asset('css/stage07-clinical.css').'?v=0.19.0' }}">@endpush
@section('content')
<div class="clinical-heading"><div><span class="ensha-eyebrow">Stage 07</span><h2>جلسات و گزارش‌های من</h2><p>نوبت‌های امروز، جلسات آینده و سوابق مشاوره در یک صفحه.</p></div><a class="ensha-secondary-btn" href="{{ route('counselor.week') }}"><i class="ki-filled ki-calendar-8"></i> تقویم و مرخصی</a></div>

<div class="clinical-stats">
    <div class="clinical-stat"><span>نوبت امروز</span><strong>{{ number_format($stats['today']) }}</strong></div>
    <div class="clinical-stat"><span>نوبت‌های آینده</span><strong>{{ number_format($stats['upcoming']) }}</strong></div>
    <div class="clinical-stat"><span>جلسات انجام‌شده</span><strong>{{ number_format($stats['completed']) }}</strong></div>
    <div class="clinical-stat"><span>گزارش نهایی‌نشده</span><strong>{{ number_format($stats['reports_pending']) }}</strong></div>
</div>

<form method="get" class="clinical-card clinical-filter">
    <input class="kt-input" name="q" value="{{ request('q') }}" placeholder="نام، تلفن یا شماره نوبت">
    <input class="kt-input" name="topic_id" type="number" value="{{ request('topic_id') }}" placeholder="شناسه موضوع">
    <select class="kt-select" name="status"><option value="">همه وضعیت‌ها</option>@foreach(\App\Models\Appointment::STATUSES as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ ['pending'=>'در انتظار','confirmed'=>'تأییدشده','arrived'=>'مراجعه کرده','in_session'=>'در جلسه','completed'=>'تکمیل‌شده','cancelled'=>'لغوشده','no_show'=>'عدم مراجعه'][$status] ?? $status }}</option>@endforeach</select>
    <input class="kt-input" type="date" name="from" value="{{ request('from') }}">
    <input class="kt-input" type="date" name="to" value="{{ request('to') }}">
    <button class="kt-btn kt-btn-primary" name="history" value="1">جستجو در سوابق</button>
</form>

@if($history)
<section class="clinical-card"><div class="clinical-card-head"><h3>سوابق کامل مشاوره</h3><a href="{{ route('counselor.workspace') }}">بازگشت به نمای امروز</a></div><div class="clinical-table-wrap"><table class="clinical-table"><thead><tr><th>تاریخ</th><th>مراجع</th><th>موضوع</th><th>وضعیت نوبت</th><th>گزارش</th><th></th></tr></thead><tbody>@forelse($history as $appointment)<tr><td data-jalali-datetime="{{ $appointment->starts_at->toIso8601String() }}">{{ $appointment->starts_at->format('Y-m-d H:i') }}</td><td>{{ $appointment->client?->user?->display_name }}</td><td>{{ $appointment->topic_name_snapshot ?: $appointment->topic?->name }}</td><td>{{ $appointment->status_snapshot ?: $appointment->status }}</td><td><span class="clinical-status {{ $appointment->sessionReport?->status==='finalized'?'finalized':'pending' }}">{{ $appointment->sessionReport?->status==='finalized'?'نهایی':'نیازمند تکمیل' }}</span></td><td><a class="kt-btn kt-btn-sm kt-btn-outline" href="{{ route('counselor.reports.show',$appointment) }}">بازکردن</a></td></tr>@empty<tr><td colspan="6">موردی پیدا نشد.</td></tr>@endforelse</tbody></table></div><div class="mt-4">{{ $history->links() }}</div></section>
@else
<div class="clinical-grid">
    <section class="clinical-card"><div class="clinical-card-head"><h3>امروز</h3><span class="count-badge">{{ $today->count() }} نوبت</span></div><div class="clinical-list">@forelse($today as $appointment)@include('counselor.partials.appointment-row',['appointment'=>$appointment])@empty<p class="text-muted-foreground">برای امروز نوبتی ندارید.</p>@endforelse</div></section>
    <section class="clinical-card"><div class="clinical-card-head"><h3>۱۴ روز آینده</h3><span class="count-badge">{{ $upcoming->count() }} مورد نمایشی</span></div><div class="clinical-list">@forelse($upcoming as $appointment)@include('counselor.partials.appointment-row',['appointment'=>$appointment])@empty<p class="text-muted-foreground">نوبت آینده‌ای ثبت نشده است.</p>@endforelse</div></section>
</div>
<section class="clinical-card mt-4"><div class="clinical-card-head"><h3>آخرین مشاوره‌ها</h3><a href="{{ route('counselor.workspace',['history'=>1]) }}">مشاهده همه سوابق</a></div><div class="clinical-list">@forelse($recent as $appointment)@include('counselor.partials.appointment-row',['appointment'=>$appointment])@empty<p class="text-muted-foreground">سابقه‌ای وجود ندارد.</p>@endforelse</div></section>
@endif
@endsection
@push('scripts')<script src="{{ asset('js/stage07-clinical.js').'?v=0.19.0' }}"></script>@endpush
