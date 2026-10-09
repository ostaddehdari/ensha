@php
$methodLabels=['cash'=>'نقدی','card'=>'کارت‌خوان','bank_transfer'=>'انتقال بانکی','online'=>'آنلاین','other'=>'سایر','legacy'=>'انتقال از نسخه قبل'];
$kindLabels=['payment'=>'دریافت','refund'=>'برگشت وجه','void'=>'ابطال'];
@endphp
<section class="ensha-card finance-section">
  <div class="finance-section-head">
    <div><h3>پرداخت‌ها و رسیدها</h3><p>هر دریافت یا برگشت وجه به‌صورت سند مستقل و غیرقابل‌ویرایش ثبت می‌شود.</p></div>
    @if(auth()->user()->hasPermission('payments.view'))<a class="ensha-secondary-btn" href="{{ route('finance.cashier.index') }}">رفتن به صندوق</a>@endif
  </div>
  <div class="finance-balance-grid">
    <div><span>مبلغ نهایی</span><strong>{{ number_format($appointment->final_price) }} ریال</strong></div>
    <div><span>پرداخت‌شده</span><strong class="finance-positive">{{ number_format($appointment->paid_amount) }} ریال</strong></div>
    <div><span>مانده</span><strong class="{{ $appointment->balance_amount > 0 ? 'finance-negative' : 'finance-positive' }}">{{ number_format($appointment->balance_amount) }} ریال</strong></div>
    <div><span>وضعیت پرداخت</span><strong>{{ ['unpaid'=>'پرداخت‌نشده','partial'=>'پرداخت ناقص','paid'=>'تسویه‌شده','credit'=>'بستانکار'][$appointment->payment_status] ?? $appointment->payment_status }}</strong></div>
  </div>
  @if(auth()->user()->hasPermission('payments.create') && $appointment->balance_amount > 0)
    @if($openRegister)
      <form method="POST" action="{{ route('finance.payments.store',$appointment) }}" class="finance-form">@csrf
        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <label>مبلغ دریافت (ریال)<input type="number" name="amount" min="1" max="{{ $appointment->balance_amount }}" value="{{ $appointment->balance_amount }}" required></label>
        <label>روش پرداخت<select name="method" required><option value="cash">نقدی</option><option value="card">کارت‌خوان</option><option value="bank_transfer">انتقال بانکی</option><option value="online">آنلاین</option><option value="other">سایر</option></select></label>
        <label>شماره پیگیری<input name="reference_number" maxlength="100"></label>
        <label>توضیح<input name="note" maxlength="1000"></label>
        <button class="ensha-primary-btn">ثبت دریافت و صدور رسید</button>
      </form>
    @else
      <div class="finance-notice">برای ثبت دریافت، ابتدا <a href="{{ route('finance.cashier.index') }}">صندوق روزانه خود را باز کنید</a>.</div>
    @endif
  @endif
  <div class="overflow-x-auto">
    <table class="ensha-table finance-table"><thead><tr><th>زمان</th><th>سند</th><th>نوع</th><th>روش</th><th>مبلغ</th><th>عامل</th><th>رسید</th><th>عملیات</th></tr></thead><tbody>
    @forelse($appointment->paymentTransactions as $transaction)
      <tr><td data-jalali-datetime="{{ $transaction->occurred_at?->toIso8601String() }}">{{ $transaction->occurred_at?->format('Y-m-d H:i') }}</td><td dir="ltr">{{ $transaction->transaction_number }}</td><td>{{ $kindLabels[$transaction->kind] ?? $transaction->kind }}</td><td>{{ $methodLabels[$transaction->method] ?? $transaction->method }}</td><td class="{{ $transaction->signed_amount >= 0 ? 'finance-positive':'finance-negative' }}">{{ $transaction->signed_amount >= 0 ? '+' : '−' }}{{ number_format(abs($transaction->signed_amount)) }}</td><td>{{ $transaction->creator?->display_name ?: 'سیستم' }}</td><td>@if($transaction->receipt)<a href="{{ route('finance.receipts.show',$transaction->receipt) }}">{{ $transaction->receipt->receipt_number }}</a>@else—@endif</td><td>
        @if($transaction->kind==='payment' && auth()->user()->hasPermission('payments.refund'))
          <details><summary>برگشت/ابطال</summary><form method="POST" action="{{ route('finance.transactions.refund',$transaction) }}" class="finance-mini-form">@csrf<input type="number" name="amount" min="1" max="{{ $transaction->amount }}" placeholder="مبلغ" required><input name="note" placeholder="علت برگشت" required><button>برگشت</button></form>@if(auth()->user()->hasPermission('payments.void'))<form method="POST" action="{{ route('finance.transactions.void',$transaction) }}" class="finance-mini-form">@csrf<input name="reason" minlength="5" placeholder="علت ابطال" required><button class="danger">ابطال مانده</button></form>@endif</details>
        @else—@endif
      </td></tr>
    @empty<tr><td colspan="8">هنوز تراکنشی ثبت نشده است.</td></tr>@endforelse
    </tbody></table>
  </div>
</section>
