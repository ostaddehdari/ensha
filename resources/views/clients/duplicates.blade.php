@extends('layouts.app', ['title'=>'تشخیص مراجع تکراری'])
@section('content')
<div class="ensha-page-heading"><div><span class="ensha-eyebrow">Stage 02</span><h2>تشخیص و ادغام کنترل‌شده مراجع</h2><p>فقط تطابق‌های هویتی معتبر نمایش داده می‌شوند. پرونده مبدأ پس از ادغام بایگانی می‌شود.</p></div><a class="ensha-secondary-btn" href="{{ route('clients.index') }}">بازگشت</a></div>
<div class="space-y-4">
@forelse($pairs as $pair)
    <section class="ensha-card">
        <div class="grid gap-4 lg:grid-cols-3"><div><strong>{{ $pair['left']->user?->display_name }}</strong><span class="block text-sm" dir="ltr">{{ $pair['left']->client_code }} · {{ $pair['left']->user?->phone }}</span></div><div><strong>{{ $pair['right']->user?->display_name }}</strong><span class="block text-sm" dir="ltr">{{ $pair['right']->client_code }} · {{ $pair['right']->user?->phone }}</span></div><div><strong>علت تطابق</strong><span class="block text-sm">{{ implode('، ', $pair['reasons']) }}</span></div></div>
        @if(auth()->user()->hasPermission('clients.merge'))
            <form method="POST" action="{{ route('clients.merge') }}" class="grid gap-3 mt-4 border-t border-border pt-4">@csrf<div class="grid grid-cols-2 gap-3"><select class="kt-input" name="source_client_id" required><option value="{{ $pair['left']->id }}">مبدأ: {{ $pair['left']->client_code }}</option><option value="{{ $pair['right']->id }}">مبدأ: {{ $pair['right']->client_code }}</option></select><select class="kt-input" name="target_client_id" required><option value="{{ $pair['right']->id }}">مقصد: {{ $pair['right']->client_code }}</option><option value="{{ $pair['left']->id }}">مقصد: {{ $pair['left']->client_code }}</option></select></div><textarea class="kt-input" name="reason" placeholder="دلیل مستند ادغام، حداقل ۱۰ حرف" required></textarea><input class="kt-input" name="confirmation" placeholder="برای تأیید دقیقاً MERGE بنویسید" required><button class="ensha-primary-btn">ادغام کنترل‌شده</button></form>
        @endif
    </section>
@empty<div class="ensha-card text-muted-foreground">هیچ پرونده تکراری بر اساس کد ملی، تلفن، یا نام و تاریخ تولد پیدا نشد.</div>@endforelse
</div>
@endsection
