@extends('layouts.app', ['title' => 'تعطیلات مرکز'])

@section('content')
    @include('centres.partials.operation-tabs', ['centre' => $centre])
    <div class="ensha-page-heading"><div><h2>تعطیلات مرکز</h2><p>تعطیلات رسمی از جدول نسخه‌دار و تعطیلات اختصاصی مرکز</p></div></div>

    <section class="ensha-card" style="padding:22px">
        <h3>تعطیلات رسمی {{ $officialYear ? 'سال '.$officialYear : '' }}</h3>
        <table class="ensha-table"><thead><tr><th>تاریخ شمسی</th><th>عنوان</th><th>تاریخ قمری</th></tr></thead><tbody>
        @forelse($official as $holiday)
            <tr><td>{{ $holiday->jalali_date }}</td><td>{{ $holiday->title }}</td><td>{{ $holiday->lunar_label ?: '—' }}</td></tr>
        @empty
            <tr><td colspan="3">داده تعطیلات رسمی هنوز بارگذاری نشده است.</td></tr>
        @endforelse
        </tbody></table>
    </section>

    <section class="ensha-card" style="padding:22px">
        <h3>تعطیلات اختصاصی مرکز</h3>
        @if(auth()->user()->hasPermission('schedules.manage'))
            <form method="POST" action="{{ route('centres.operations.closure', $centre) }}" class="ensha-form-grid">@csrf<label>از تاریخ<input type="date" name="starts_on" required></label><label>تا تاریخ<input type="date" name="ends_on" required></label><label>علت<input name="reason" required></label><button class="ensha-primary-btn">ثبت تعطیلی</button></form>
        @endif
        @forelse($closures as $closure)<p>{{ $closure->starts_on }} تا {{ $closure->ends_on }} · {{ $closure->reason }}</p>@empty<p>تعطیلی اختصاصی ثبت نشده است.</p>@endforelse
    </section>
@endsection
