@extends('layouts.app', ['title' => 'مرخصی مشاوران'])

@section('content')
    @include('centres.partials.operation-tabs', ['centre' => $centre])
    <div class="ensha-page-heading"><div><h2>مرخصی مشاوران</h2><p>مرخصی ساعتی، یک‌روزه و چندروزه بدون تغییر برنامه هفتگی</p></div></div>
    @if(auth()->user()->hasPermission('schedules.manage'))
        <section class="ensha-card" style="padding:22px"><h3>مرخصی جدید</h3><form method="POST" action="{{ route('centres.operations.leave', $centre) }}" class="ensha-form-grid">@csrf<label>مشاور<select name="user_id" required>@foreach($counselors as $counselor)<option value="{{ $counselor->id }}">{{ $counselor->display_name }}</option>@endforeach</select></label><label>شروع<input type="datetime-local" name="starts_at" required></label><label>پایان<input type="datetime-local" name="ends_at" required></label><label>علت<input name="reason"></label><button class="ensha-primary-btn">ثبت مرخصی</button></form></section>
    @endif
    <section class="ensha-card" style="padding:22px"><h3>سابقه مرخصی</h3><table class="ensha-table"><thead><tr><th>مشاور</th><th>شروع</th><th>پایان</th><th>علت</th></tr></thead><tbody>@forelse($leaves as $leave)<tr><td>{{ $leave->first_name }} {{ $leave->last_name }}</td><td>{{ $leave->starts_at }}</td><td>{{ $leave->ends_at }}</td><td>{{ $leave->reason }}</td></tr>@empty<tr><td colspan="4">مرخصی ثبت نشده است.</td></tr>@endforelse</tbody></table></section>
@endsection
