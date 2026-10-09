@extends('layouts.app', ['title'=>'قوانین سهم مشاور'])
@push('head')<link rel="stylesheet" href="{{ asset('css/stage10-settlements.css').'?v=0.22.0' }}">@endpush
@section('content')
<div class="ensha-page-heading"><div><span class="ensha-eyebrow">Stage 10</span><h2>قوانین نسخه‌دار سهم مرکز و مشاور</h2><p>هر جلسه هنگام تکمیل، سهم همان روز را قفل می‌کند؛ نسخه‌های جدید روی سوابق قبلی اثر ندارند.</p></div></div>
@if(auth()->user()->hasPermission('compensation_rules.manage'))
<section class="ensha-card settlement-section"><div class="settlement-head"><div><h3>نسخه جدید قانون</h3><p>قانون اختصاصی مشاور و موضوع بر قانون عمومی مرکز اولویت دارد.</p></div></div>
<form method="POST" action="{{ route('centres.compensation-rules.store',$centre) }}" class="settlement-form">@csrf
<label>عنوان<input name="name" value="{{ old('name') }}" required maxlength="150"></label>
<label>ذی‌نفع سهم<select name="beneficiary" required><option value="counselor">مشاور</option><option value="centre">مرکز</option></select></label>
<label>روش محاسبه<select name="calculation_type" required><option value="percentage">درصدی</option><option value="fixed">مبلغ ثابت</option></select></label>
<label>مقدار<input type="number" name="value" min="0.01" step="0.01" required><small>برای درصد، عدد ۰ تا ۱۰۰؛ برای ثابت، ریال</small></label>
<label>موضوع (اختیاری)<select name="topic_id"><option value="">همه موضوع‌ها</option>@foreach($topics as $topic)<option value="{{ $topic->id }}">{{ $topic->name }}</option>@endforeach</select></label>
<label>مشاور (اختیاری)<select name="counselor_id"><option value="">همه مشاوران</option>@foreach($counselors as $counselor)<option value="{{ $counselor->id }}">{{ $counselor->display_name }}</option>@endforeach</select></label>
<label>شروع اعتبار<input type="date" name="valid_from" value="{{ old('valid_from',now()->toDateString()) }}" required></label>
<label>پایان اعتبار<input type="date" name="valid_until"></label>
<label class="wide">یادداشت<textarea name="note" rows="2" maxlength="2000"></textarea></label>
<div class="wide"><button class="ensha-primary-btn">ثبت نسخه جدید</button></div>
</form></section>@endif
<section class="ensha-card settlement-section"><div class="settlement-head"><div><h3>نسخه‌های قوانین</h3><p>نسخه فعال مناسب، بر اساس تاریخ جلسه و دقیق‌ترین محدوده انتخاب می‌شود.</p></div></div><div class="overflow-x-auto"><table class="ensha-table"><thead><tr><th>عنوان</th><th>محدوده</th><th>نسخه</th><th>روش</th><th>اعتبار</th><th>وضعیت</th><th></th></tr></thead><tbody>
@forelse($rules as $rule)<tr><td>{{ $rule->name }}</td><td>{{ $rule->counselor?->display_name ?? 'همه مشاوران' }} · {{ $rule->topic?->name ?? 'همه موضوع‌ها' }}</td><td>{{ $rule->version }}</td><td>{{ $rule->calculation_type==='percentage' ? number_format($rule->value/100,2).'٪' : number_format($rule->value).' ریال' }} برای {{ $rule->beneficiary==='counselor'?'مشاور':'مرکز' }}</td><td><span data-jalali-date="{{ $rule->valid_from?->format('Y-m-d') }}">{{ $rule->valid_from?->format('Y-m-d') }}</span> تا <span data-jalali-date="{{ $rule->valid_until?->format('Y-m-d') }}">{{ $rule->valid_until?->format('Y-m-d') ?: 'نامحدود' }}</span></td><td><span class="settlement-badge {{ $rule->is_active?'approved':'cancelled' }}">{{ $rule->is_active?'فعال':'غیرفعال' }}</span></td><td>@if($rule->is_active && auth()->user()->hasPermission('compensation_rules.manage'))<form method="POST" action="{{ route('centres.compensation-rules.retire',[$centre,$rule]) }}">@csrf @method('PATCH')<button class="ensha-secondary-btn" onclick="return confirm('این قانون غیرفعال شود؟')">غیرفعال‌سازی</button></form>@endif</td></tr>@empty<tr><td colspan="7">قانونی ثبت نشده است.</td></tr>@endforelse
</tbody></table></div>{{ $rules->links() }}</section>
@endsection
@push('scripts')<script src="{{ asset('js/stage07-clinical.js').'?v=0.19.0' }}"></script>@endpush
