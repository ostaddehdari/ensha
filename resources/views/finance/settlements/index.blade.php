@extends('layouts.app', ['title'=>'تسویه مشاوران'])
@push('head')<link rel="stylesheet" href="{{ asset('css/stage10-settlements.css').'?v=0.22.0' }}">@endpush
@section('content')
<div class="ensha-page-heading"><div><span class="ensha-eyebrow">Stage 10</span><h2>{{ auth()->user()->assignedRole?->slug==='counselor'?'تسویه‌های من':'تسویه مشاوران' }}</h2><p>محاسبه سهم بر اساس جلسات تکمیل‌شده و وجوه وصول‌شده، با حفظ Snapshot تاریخی.</p></div></div>
<div class="settlement-summary"><div><span>پیش‌نویس‌ها</span><strong>{{ number_format($summary['draft']) }}</strong></div><div><span>آماده پرداخت</span><strong>{{ number_format($summary['approved_payable']) }}</strong><small>ریال</small></div><div><span>پرداخت‌شده</span><strong>{{ number_format($summary['paid']) }}</strong><small>ریال</small></div></div>
@if(auth()->user()->hasPermission('settlements.manage'))<section class="ensha-card settlement-section"><div class="settlement-head"><div><h3>ساخت پیش‌نویس تسویه</h3><p>فقط سهم وصول‌شده‌ای که قبلاً در تسویه فعال نیامده محاسبه می‌شود.</p></div></div><form method="POST" action="{{ route('settlements.store') }}" class="settlement-form">@csrf
<label>مشاور<select name="counselor_id" required><option value="">انتخاب مشاور</option>@foreach($counselors as $counselor)<option value="{{ $counselor->id }}">{{ $counselor->display_name }}</option>@endforeach</select></label>
<label>از تاریخ<input type="date" name="period_start" required></label><label>تا تاریخ<input type="date" name="period_end" required></label>
<label>کسورات (ریال)<input type="number" name="deduction_amount" min="0" value="0"></label><label>عنوان کسورات<input name="deduction_title" maxlength="150"></label>
<label>پاداش (ریال)<input type="number" name="bonus_amount" min="0" value="0"></label><label>عنوان پاداش<input name="bonus_title" maxlength="150"></label>
<label class="wide">یادداشت<textarea name="note" rows="2"></textarea></label><div class="wide"><button class="ensha-primary-btn">محاسبه و ساخت پیش‌نویس</button></div>
</form></section>@endif
<section class="ensha-card settlement-section"><div class="settlement-head"><div><h3>دوره‌های تسویه</h3></div></div><div class="overflow-x-auto"><table class="ensha-table"><thead><tr><th>شماره</th><th>مشاور</th><th>دوره</th><th>سهم مشاور</th><th>قابل پرداخت</th><th>وضعیت</th><th></th></tr></thead><tbody>
@forelse($settlements as $settlement)<tr><td dir="ltr">{{ $settlement->settlement_number }}</td><td>{{ $settlement->counselor?->display_name }}</td><td><span data-jalali-date="{{ $settlement->period_start?->format('Y-m-d') }}">{{ $settlement->period_start?->format('Y-m-d') }}</span> تا <span data-jalali-date="{{ $settlement->period_end?->format('Y-m-d') }}">{{ $settlement->period_end?->format('Y-m-d') }}</span></td><td>{{ number_format($settlement->counselor_share_amount) }}</td><td>{{ number_format($settlement->payable_amount) }}</td><td><span class="settlement-badge {{ $settlement->status }}">{{ ['draft'=>'پیش‌نویس','approved'=>'تأییدشده','paid'=>'پرداخت‌شده','cancelled'=>'لغوشده'][$settlement->status] }}</span></td><td><a href="{{ route('settlements.show',$settlement) }}">جزئیات</a></td></tr>@empty<tr><td colspan="7">تسویه‌ای ثبت نشده است.</td></tr>@endforelse
</tbody></table></div>{{ $settlements->links() }}</section>
@endsection
@push('scripts')<script src="{{ asset('js/stage07-clinical.js').'?v=0.19.0' }}"></script>@endpush
