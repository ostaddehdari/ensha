<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CashRegisterSession;
use App\Models\FinancialSequence;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentLedgerService
{
    public const METHODS = ['cash', 'card', 'bank_transfer', 'online', 'other'];

    public function openRegister(User $actor, int $openingAmount, ?string $note = null): CashRegisterSession
    {
        return DB::transaction(function () use ($actor, $openingAmount, $note) {
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            if (CashRegisterSession::where('cashier_id', $actor->id)->where('status', 'open')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['cash_register' => 'برای شما یک صندوق باز وجود دارد.']);
            }
            $session = CashRegisterSession::create([
                'public_id' => (string) Str::uuid(),
                'session_number' => $this->nextNumber((int) $actor->centre_id, 'cash_register', 'CS'),
                'centre_id' => $actor->centre_id,
                'branch_id' => DB::table('user_role_centres')->where('user_id', $actor->id)
                    ->where('centre_id', $actor->centre_id)->where('role_id', $actor->role_id)->value('branch_id'),
                'cashier_id' => $actor->id, 'opened_by' => $actor->id, 'opened_at' => now(),
                'opening_cash_amount' => $openingAmount, 'expected_cash_amount' => $openingAmount,
                'status' => 'open', 'opening_note' => $note,
            ]);
            return $session->fresh(['cashier']);
        }, 3);
    }

    public function closeRegister(CashRegisterSession $session, User $actor, int $countedAmount, ?string $note = null): CashRegisterSession
    {
        return DB::transaction(function () use ($session, $actor, $countedAmount, $note) {
            $session = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->assertCentre($session->centre_id, $actor);
            if ($session->status !== 'open') throw ValidationException::withMessages(['cash_register' => 'این صندوق قبلاً بسته شده است.']);
            $totals = $this->registerTotals($session);
            $expected = (int) $session->opening_cash_amount + $totals['cash_net'];
            $session->update([
                'cash_payments_amount' => $totals['cash_payments'], 'cash_refunds_amount' => $totals['cash_refunds'],
                'non_cash_net_amount' => $totals['non_cash_net'], 'expected_cash_amount' => $expected,
                'counted_cash_amount' => $countedAmount, 'difference_amount' => $countedAmount - $expected,
                'status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now(), 'closing_note' => $note,
            ]);
            return $session->fresh(['cashier', 'closedBy']);
        }, 3);
    }

    public function postPayment(Appointment $appointment, User $actor, int $amount, string $method, ?string $reference = null, ?string $note = null, ?string $idempotencyKey = null): PaymentTransaction
    {
        return DB::transaction(function () use ($appointment, $actor, $amount, $method, $reference, $note, $idempotencyKey) {
            if ($idempotencyKey) {
                $existing = PaymentTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    $this->assertCentre($existing->centre_id, $actor);
                    if ((int) $existing->appointment_id !== (int) $appointment->id || (int) $existing->created_by !== (int) $actor->id) {
                        throw ValidationException::withMessages(['idempotency_key' => 'کلید تکرار برای عملیات دیگری استفاده شده است.']);
                    }
                    return $existing->load(['receipt', 'appointment', 'client.user', 'creator']);
                }
            }
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            $this->assertCentre($appointment->centre_id, $actor);
            $this->validateMethod($method);
            if ($amount < 1) throw ValidationException::withMessages(['amount' => 'مبلغ دریافت باید بیشتر از صفر باشد.']);
            $this->recalculateAppointment($appointment);
            $appointment->refresh();
            if ($amount > (int) $appointment->balance_amount) {
                throw ValidationException::withMessages(['amount' => 'مبلغ دریافت از مانده نوبت بیشتر است.']);
            }
            $session = $this->openSession($actor, true);
            return $this->createTransaction($appointment, $actor, $session, 'payment', $method, $amount, $amount, null, $reference, $note, $idempotencyKey);
        }, 3);
    }

    public function refund(PaymentTransaction $payment, User $actor, int $amount, ?string $note = null): PaymentTransaction
    {
        return DB::transaction(function () use ($payment, $actor, $amount, $note) {
            $payment = PaymentTransaction::with('appointment')->lockForUpdate()->findOrFail($payment->id);
            $this->assertCentre($payment->centre_id, $actor);
            if ($payment->kind !== 'payment' || $payment->status !== 'posted') {
                throw ValidationException::withMessages(['transaction' => 'فقط تراکنش دریافت قابل برگشت است.']);
            }
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($payment->appointment_id);
            $remaining = $this->reversibleAmount($payment);
            if ($amount < 1 || $amount > $remaining) {
                throw ValidationException::withMessages(['amount' => 'مبلغ برگشت از مانده قابل برگشت این تراکنش بیشتر است.']);
            }
            $session = $this->openSession($actor, true);
            return $this->createTransaction($appointment, $actor, $session, 'refund', $payment->method, $amount, -$amount, $payment, $payment->reference_number, $note);
        }, 3);
    }

    public function void(PaymentTransaction $payment, User $actor, string $reason): PaymentTransaction
    {
        return DB::transaction(function () use ($payment, $actor, $reason) {
            $payment = PaymentTransaction::with('appointment')->lockForUpdate()->findOrFail($payment->id);
            $this->assertCentre($payment->centre_id, $actor);
            if ($payment->kind !== 'payment' || $payment->status !== 'posted') {
                throw ValidationException::withMessages(['transaction' => 'فقط تراکنش دریافت قابل ابطال است.']);
            }
            $remaining = $this->reversibleAmount($payment);
            if ($remaining < 1) throw ValidationException::withMessages(['transaction' => 'مانده‌ای برای ابطال این تراکنش وجود ندارد.']);
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($payment->appointment_id);
            $session = $this->openSession($actor, true);
            return $this->createTransaction($appointment, $actor, $session, 'void', $payment->method, $remaining, -$remaining, $payment, $payment->reference_number, $reason);
        }, 3);
    }

    public function recalculateAppointment(Appointment $appointment): Appointment
    {
        $paid = (int) PaymentTransaction::where('appointment_id', $appointment->id)->where('status', 'posted')->sum('signed_amount');
        $final = (int) $appointment->final_price;
        $status = $paid <= 0 ? 'unpaid' : ($paid < $final ? 'partial' : ($paid === $final ? 'paid' : 'credit'));
        DB::table('appointments')->where('id', $appointment->id)->update([
            'paid_amount' => max(0, $paid), 'balance_amount' => max(0, $final - $paid),
            'payment_status' => $status, 'financial_updated_at' => now(),
        ]);
        return $appointment->refresh();
    }

    public function registerTotals(CashRegisterSession $session): array
    {
        $query = PaymentTransaction::where('cash_register_session_id', $session->id)->where('status', 'posted');
        $cashPayments = (int) (clone $query)->where('method', 'cash')->where('signed_amount', '>', 0)->sum('signed_amount');
        $cashRefunds = abs((int) (clone $query)->where('method', 'cash')->where('signed_amount', '<', 0)->sum('signed_amount'));
        return [
            'cash_payments' => $cashPayments, 'cash_refunds' => $cashRefunds,
            'cash_net' => $cashPayments - $cashRefunds,
            'non_cash_net' => (int) (clone $query)->where('method', '!=', 'cash')->sum('signed_amount'),
            'transaction_count' => (int) (clone $query)->count(),
        ];
    }

    private function createTransaction(Appointment $appointment, User $actor, CashRegisterSession $session, string $kind, string $method, int $amount, int $signedAmount, ?PaymentTransaction $parent = null, ?string $reference = null, ?string $note = null, ?string $idempotencyKey = null): PaymentTransaction
    {
        $transaction = PaymentTransaction::create([
            'public_id' => (string) Str::uuid(),
            'transaction_number' => $this->nextNumber((int) $appointment->centre_id, 'transaction', 'TRX'),
            'centre_id' => $appointment->centre_id, 'branch_id' => $appointment->branch_id,
            'appointment_id' => $appointment->id, 'client_id' => $appointment->client_id,
            'cash_register_session_id' => $session->id, 'parent_transaction_id' => $parent?->id,
            'kind' => $kind, 'method' => $method, 'amount' => $amount, 'signed_amount' => $signedAmount,
            'currency' => $appointment->currency ?: 'IRR', 'status' => 'posted',
            'reference_number' => $reference, 'idempotency_key' => $idempotencyKey,
            'note' => $note, 'occurred_at' => now(), 'created_by' => $actor->id,
        ]);
        $this->recalculateAppointment($appointment);
        $appointment->load(['client.user', 'topic', 'counselor']);
        PaymentReceipt::create([
            'public_id' => (string) Str::uuid(),
            'receipt_number' => $this->nextNumber((int) $appointment->centre_id, 'receipt', 'RC'),
            'transaction_id' => $transaction->id,
            'snapshot' => [
                'transaction_number' => $transaction->transaction_number, 'kind' => $kind,
                'appointment_number' => $appointment->appointment_number,
                'client' => $appointment->client?->user?->display_name,
                'client_code' => $appointment->client?->client_code,
                'topic' => $appointment->topic_name_snapshot ?: $appointment->topic?->name,
                'counselor' => $appointment->counselor?->display_name,
                'base_price' => (int) $appointment->base_price, 'final_price' => (int) $appointment->final_price,
                'amount' => $amount, 'signed_amount' => $signedAmount,
                'paid_after' => (int) $appointment->paid_amount, 'balance_after' => (int) $appointment->balance_amount,
                'method' => $method, 'reference_number' => $reference,
                'actor' => $actor->display_name, 'occurred_at' => now()->toIso8601String(),
            ],
            'issued_at' => now(), 'issued_by' => $actor->id,
        ]);
        return $transaction->fresh(['receipt', 'appointment', 'client.user', 'creator']);
    }

    private function nextNumber(int $centreId, string $key, string $prefix): string
    {
        $year = (int) now()->format('Y');
        FinancialSequence::insertOrIgnore([
            'centre_id' => $centreId, 'sequence_key' => $key, 'sequence_year' => $year,
            'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sequence = FinancialSequence::where('centre_id', $centreId)->where('sequence_key', $key)
            ->where('sequence_year', $year)->lockForUpdate()->firstOrFail();
        $next = (int) $sequence->last_number + 1;
        $sequence->update(['last_number' => $next]);
        return sprintf('%s-%d-%04d-%06d', $prefix, $centreId, $year, $next);
    }

    private function openSession(User $actor, bool $required): ?CashRegisterSession
    {
        $session = CashRegisterSession::where('cashier_id', $actor->id)->where('status', 'open')->lockForUpdate()->first();
        if ($required && ! $session) throw ValidationException::withMessages(['cash_register' => 'پیش از ثبت عملیات مالی، صندوق روزانه خود را باز کنید.']);
        return $session;
    }

    private function reversibleAmount(PaymentTransaction $payment): int
    {
        $reversed = abs((int) PaymentTransaction::where('parent_transaction_id', $payment->id)->where('status', 'posted')->sum('signed_amount'));
        return max(0, (int) $payment->amount - $reversed);
    }

    private function validateMethod(string $method): void
    {
        if (! in_array($method, self::METHODS, true)) throw ValidationException::withMessages(['method' => 'روش پرداخت معتبر نیست.']);
    }

    private function assertCentre(int $centreId, User $actor): void
    {
        if (! $actor->isSuperAdmin() && (int) $actor->centre_id !== $centreId) abort(403);
        if ($actor->isSuperAdmin() && ! $actor->centre_id) throw ValidationException::withMessages(['centre' => 'برای عملیات مالی، ابتدا در زمینه یک مرکز وارد شوید.']);
    }
}
