<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CashRegisterSession;
use App\Models\PaymentTransaction;
use App\Services\PaymentLedgerService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinanceController extends Controller
{
    public function index(Request $request, PaymentLedgerService $ledger): View
    {
        abort_unless($request->user()->hasPermission('payments.view'), 403);
        $centreId = $this->centreId($request);
        $openSession = CashRegisterSession::with('cashier')->where('centre_id', $centreId)
            ->where('cashier_id', $request->user()->id)->where('status', 'open')->first();
        $registerTotals = $openSession ? $ledger->registerTotals($openSession) : null;
        $appointments = Appointment::with(['client.user', 'topic', 'counselor'])
            ->where('centre_id', $centreId)->where('balance_amount', '>', 0)
            ->whereNotIn('status', ['cancelled'])->orderByDesc('starts_at')->limit(40)->get();
        $transactions = PaymentTransaction::with(['receipt', 'appointment.client.user', 'creator', 'parent'])
            ->where('centre_id', $centreId)->orderByDesc('occurred_at')->limit(60)->get();
        $recentSessions = CashRegisterSession::with(['cashier', 'closedBy'])->where('centre_id', $centreId)
            ->orderByDesc('opened_at')->limit(15)->get();
        $summary = [
            'today_net' => (int) PaymentTransaction::where('centre_id', $centreId)->whereDate('occurred_at', today())->sum('signed_amount'),
            'today_cash' => (int) PaymentTransaction::where('centre_id', $centreId)->whereDate('occurred_at', today())->where('method', 'cash')->sum('signed_amount'),
            'outstanding' => (int) Appointment::where('centre_id', $centreId)->whereNotIn('status', ['cancelled'])->sum('balance_amount'),
            'open_registers' => CashRegisterSession::where('centre_id', $centreId)->where('status', 'open')->count(),
        ];
        return view('finance.cashier', compact('openSession', 'registerTotals', 'appointments', 'transactions', 'recentSessions', 'summary'));
    }

    public function openRegister(Request $request, PaymentLedgerService $ledger): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('cash_register.manage'), 403);
        $this->centreId($request);
        $data = $request->validate(['opening_cash_amount' => 'required|integer|min:0|max:999999999999', 'opening_note' => 'nullable|string|max:1000']);
        $session = $ledger->openRegister($request->user(), (int) $data['opening_cash_amount'], $data['opening_note'] ?? null);
        Audit::record('بازکردن صندوق روزانه', $request, 'warning', ['session' => $session->session_number, 'opening' => $session->opening_cash_amount], $session, 'cash_register.opened');
        return back()->with('success', 'صندوق روزانه باز شد.');
    }

    public function closeRegister(Request $request, CashRegisterSession $session, PaymentLedgerService $ledger): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('cash_register.manage'), 403);
        $data = $request->validate(['counted_cash_amount' => 'required|integer|min:0|max:999999999999', 'closing_note' => 'nullable|string|max:1000']);
        $closed = $ledger->closeRegister($session, $request->user(), (int) $data['counted_cash_amount'], $data['closing_note'] ?? null);
        Audit::record('بستن و تطبیق صندوق روزانه', $request, 'warning', ['session' => $closed->session_number, 'difference' => $closed->difference_amount], $closed, 'cash_register.closed');
        return back()->with('success', 'صندوق بسته و اختلاف آن ثبت شد.');
    }

    public function payment(Request $request, Appointment $appointment, PaymentLedgerService $ledger): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('payments.create'), 403);
        $data = $request->validate([
            'amount' => 'required|integer|min:1|max:999999999999',
            'method' => ['required', Rule::in(PaymentLedgerService::METHODS)],
            'reference_number' => 'nullable|string|max:100', 'note' => 'nullable|string|max:1000',
            'idempotency_key' => 'nullable|uuid',
        ]);
        $transaction = $ledger->postPayment($appointment, $request->user(), (int) $data['amount'], $data['method'], $data['reference_number'] ?? null, $data['note'] ?? null, $data['idempotency_key'] ?? null);
        Audit::record('ثبت دریافت نوبت', $request, 'warning', ['transaction' => $transaction->transaction_number, 'amount' => $transaction->amount, 'method' => $transaction->method], $transaction, 'payment.posted');
        return redirect()->route('finance.receipts.show', $transaction->receipt)->with('success', 'دریافت ثبت و رسید صادر شد.');
    }

    public function refund(Request $request, PaymentTransaction $transaction, PaymentLedgerService $ledger): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('payments.refund'), 403);
        $data = $request->validate(['amount' => 'required|integer|min:1|max:999999999999', 'note' => 'required|string|max:1000']);
        $refund = $ledger->refund($transaction, $request->user(), (int) $data['amount'], $data['note']);
        Audit::record('ثبت برگشت وجه', $request, 'warning', ['transaction' => $refund->transaction_number, 'parent' => $transaction->transaction_number, 'amount' => $refund->amount], $refund, 'payment.refunded');
        return redirect()->route('finance.receipts.show', $refund->receipt)->with('success', 'برگشت وجه ثبت و رسید صادر شد.');
    }

    public function void(Request $request, PaymentTransaction $transaction, PaymentLedgerService $ledger): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('payments.void'), 403);
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $void = $ledger->void($transaction, $request->user(), $data['reason']);
        Audit::record('ابطال تراکنش مالی', $request, 'warning', ['transaction' => $void->transaction_number, 'parent' => $transaction->transaction_number, 'amount' => $void->amount], $void, 'payment.voided');
        return redirect()->route('finance.receipts.show', $void->receipt)->with('success', 'سند ابطال ثبت و رسید صادر شد.');
    }

    private function centreId(Request $request): int
    {
        abort_if(! $request->user()->centre_id, 422, 'زمینه مرکز برای کاربر فعال نیست.');
        return (int) $request->user()->centre_id;
    }
}
