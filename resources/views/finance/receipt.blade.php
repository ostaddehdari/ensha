@extends('layouts.app', ['title'=>'رسید '.$receipt->receipt_number])
@push('head')<link rel="stylesheet" href="{{ asset('css/stage09-finance.css').'?v=0.21.0' }}">@endpush
@section('content')
@php($s=$receipt->snapshot)
<div class="receipt-actions"><a class="ensha-secondary-btn" href="{{ route('appointments.show',$receipt->transaction->appointment) }}">بازگشت به نوبت</a><button class="ensha-primary-btn" onclick="window.print()">چاپ رسید</button></div>
<article class="finance-receipt">
  <header><div><span>مرکز مشاوره انشا</span><h1>رسید مالی</h1></div><div class="receipt-number"><small>شماره رسید</small><strong dir="ltr">{{ $receipt->receipt_number }}</strong></div></header>
  <div class="receipt-grid"><div><span>مراجع</span><strong>{{ $s['client'] ?? '—' }}</strong></div><div><span>کد مراجع</span><strong dir="ltr">{{ $s['client_code'] ?? '—' }}</strong></div><div><span>شماره نوبت</span><strong dir="ltr">{{ $s['appointment_number'] ?? '—' }}</strong></div><div><span>شماره تراکنش</span><strong dir="ltr">{{ $s['transaction_number'] ?? '—' }}</strong></div><div><span>موضوع</span><strong>{{ $s['topic'] ?? '—' }}</strong></div><div><span>مشاور</span><strong>{{ $s['counselor'] ?? '—' }}</strong></div><div><span>نوع سند</span><strong>{{ ['payment'=>'دریافت','refund'=>'برگشت وجه','void'=>'ابطال'][$s['kind'] ?? ''] ?? ($s['kind'] ?? '—') }}</strong></div><div><span>روش</span><strong>{{ ['cash'=>'نقدی','card'=>'کارت‌خوان','bank_transfer'=>'انتقال بانکی','online'=>'آنلاین','other'=>'سایر','legacy'=>'انتقال قدیمی'][$s['method'] ?? ''] ?? ($s['method'] ?? '—') }}</strong></div></div>
  <div class="receipt-amount"><span>مبلغ این سند</span><strong>{{ number_format($s['amount'] ?? 0) }} ریال</strong></div>
  <div class="receipt-totals"><div><span>مبلغ نهایی نوبت</span><strong>{{ number_format($s['final_price'] ?? 0) }}</strong></div><div><span>پرداخت پس از سند</span><strong>{{ number_format($s['paid_after'] ?? 0) }}</strong></div><div><span>مانده پس از سند</span><strong>{{ number_format($s['balance_after'] ?? 0) }}</strong></div></div>
  <footer><span>صادرکننده: {{ $s['actor'] ?? $receipt->issuer?->display_name ?? 'سیستم' }}</span><span data-jalali-datetime="{{ $receipt->issued_at?->toIso8601String() }}">{{ $receipt->issued_at?->format('Y-m-d H:i') }}</span><span>این رسید از دفتر تراکنش تغییرناپذیر سامانه صادر شده است.</span></footer>
</article>
@endsection
@push('scripts')<script src="{{ asset('js/stage07-clinical.js').'?v=0.19.0' }}"></script>@endpush
