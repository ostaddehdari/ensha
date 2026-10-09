<div class="clinical-appointment">
    <div class="clinical-time">{{ $appointment->starts_at->format('H:i') }}<small data-jalali-datetime="{{ $appointment->starts_at->toIso8601String() }}">{{ $appointment->starts_at->format('Y-m-d') }}</small></div>
    <div class="clinical-client"><strong>{{ $appointment->client?->user?->display_name ?: 'مراجع بدون نام' }}</strong><span>{{ $appointment->topic_name_snapshot ?: $appointment->topic?->name }} · {{ ['in_person'=>'حضوری','phone'=>'تلفنی','video'=>'ویدیویی'][$appointment->mode] ?? $appointment->mode }}</span></div>
    <a class="kt-btn kt-btn-sm {{ $appointment->sessionReport?->status==='finalized'?'kt-btn-outline':'kt-btn-primary' }}" href="{{ route('counselor.reports.show',$appointment) }}">{{ $appointment->sessionReport?->status==='finalized'?'مشاهده گزارش':'ورود و ثبت گزارش' }}</a>
</div>
