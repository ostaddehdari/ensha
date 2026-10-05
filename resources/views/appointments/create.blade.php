@extends('layouts.app', ['title'=>'ثبت نوبت'])
@section('content')
@include('appointments.partials.nav')
<div class="ensha-page-heading"><div><span class="ensha-eyebrow">رزرو ایمن</span><h2>ثبت نوبت</h2><p>ثبت نهایی با Redis lock، تراکنش پایگاه داده و قفل ردیف انجام می‌شود.</p></div></div>
<section class="ensha-card" style="padding:22px"><form method="POST" action="{{ route('appointments.store') }}" class="grid gap-4 md:grid-cols-2">@csrf
<label class="kt-form-label">مراجع<select class="kt-input mt-1" name="client_id" required><option value="">انتخاب مراجع</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected((int)old('client_id')===$client->id)>{{ $client->client_code }} — {{ $client->user?->display_name }}</option>@endforeach</select></label>
<label class="kt-form-label">اسلات آزاد<select class="kt-input mt-1" name="slot_id" required><option value="">انتخاب زمان</option>@foreach($slots as $slot)<option value="{{ $slot->id }}" @selected((int)old('slot_id')===$slot->id)>{{ $slot->starts_at->format('Y-m-d H:i') }} — {{ $slot->topic?->name }} — {{ $slot->counselor?->display_name }} — ظرفیت {{ $slot->capacity-$slot->booked_count }}</option>@endforeach</select></label>
<label class="kt-form-label md:col-span-2">شناسه پرونده مشاوره (اختیاری)<input class="kt-input mt-1" type="number" min="1" name="case_id" value="{{ old('case_id') }}"></label>
<label class="kt-form-label md:col-span-2">یادداشت اداری<textarea class="kt-input mt-1" name="notes" rows="3">{{ old('notes') }}</textarea></label>
<div class="md:col-span-2"><button class="ensha-primary-btn">رزرو نهایی</button></div></form></section>
@endsection
