@extends('layouts.app',['title'=>'گزارش روزانه'])
@section('content')
@include('appointments.partials.nav')
<div class="ensha-page-heading"><div><span class="ensha-eyebrow">Stage 05</span><h2>گزارش روزانه منشی</h2><p>خلاصهٔ نوبت‌ها، تماس‌ها و صف پیامک. ارسال واقعی پیامک پس از اتصال Provider در Stage بعدی انجام می‌شود.</p></div></div>
<section class="ensha-card" style="padding:18px"><form class="ensha-filter-bar"><input class="kt-input" type="date" name="date" value="{{ $date }}"><button class="ensha-secondary-btn">نمایش</button></form><div class="grid md:grid-cols-3 gap-5 mt-5"><div><h3>نوبت‌ها</h3>@forelse($byStatus as $s=>$n)<p class="flex justify-between border-b py-2"><span>{{ $s }}</span><strong>{{ $n }}</strong></p>@empty<p>بدون داده</p>@endforelse</div><div><h3>تماس‌ها</h3>@forelse($calls as $s=>$n)<p class="flex justify-between border-b py-2"><span>{{ $s }}</span><strong>{{ $n }}</strong></p>@empty<p>بدون داده</p>@endforelse</div><div><h3>صف پیامک</h3>@forelse($sms as $s=>$n)<p class="flex justify-between border-b py-2"><span>{{ $s }}</span><strong>{{ $n }}</strong></p>@empty<p>بدون داده</p>@endforelse</div></div></section>
@endsection
