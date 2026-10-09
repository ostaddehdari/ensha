<?php

namespace Tests\Feature;

use App\Models\AppointmentSlot;
use App\Models\Centre;
use App\Models\Client;
use App\Models\PaymentTransaction;
use App\Models\Role;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\PaymentLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class Stage09PaymentLedgerTest extends TestCase
{
    use RefreshDatabase;
    protected $seed = true;

    public function test_partial_payment_refund_and_receipts_keep_appointment_balance_in_sync(): void
    {
        config(['appointments.lock_store' => 'array']);
        [$manager, $client, $slot] = $this->fixture();
        $appointment = app(AppointmentBookingService::class)->book($slot->id, $client->id, null, $manager->id);
        $ledger = app(PaymentLedgerService::class);
        $session = $ledger->openRegister($manager, 100000);
        $payment = $ledger->postPayment($appointment, $manager, 300000, 'cash', null, 'پرداخت اول', fake()->uuid());

        $this->assertNotNull($payment->receipt);
        $this->assertSame('partial', $appointment->fresh()->payment_status);
        $this->assertSame(300000, (int) $appointment->fresh()->paid_amount);
        $refund = $ledger->refund($payment, $manager, 100000, 'اصلاح دریافت');
        $this->assertSame(-100000, (int) $refund->signed_amount);
        $this->assertSame(200000, (int) $appointment->fresh()->paid_amount);
        $this->assertSame((int) $appointment->final_price - 200000, (int) $appointment->fresh()->balance_amount);
        $totals = $ledger->registerTotals($session);
        $this->assertSame(300000, $totals['cash_payments']);
        $this->assertSame(100000, $totals['cash_refunds']);
    }

    public function test_posted_transaction_cannot_be_updated_or_deleted(): void
    {
        config(['appointments.lock_store' => 'array']);
        [$manager, $client, $slot] = $this->fixture();
        $appointment = app(AppointmentBookingService::class)->book($slot->id, $client->id, null, $manager->id);
        $ledger = app(PaymentLedgerService::class);
        $ledger->openRegister($manager, 0);
        $payment = $ledger->postPayment($appointment, $manager, 100000, 'card');
        $this->expectException(LogicException::class);
        $payment->update(['note' => 'دستکاری']);
    }

    public function test_secretary_cannot_refund_payment(): void
    {
        config(['appointments.lock_store' => 'array']);
        [$manager, $client, $slot] = $this->fixture();
        $secretary = $this->makeUser('secretary', $slot->centre_id, '09128880909');
        $appointment = app(AppointmentBookingService::class)->book($slot->id, $client->id, null, $manager->id);
        $ledger = app(PaymentLedgerService::class);
        $ledger->openRegister($manager, 0);
        $payment = $ledger->postPayment($appointment, $manager, 100000, 'card');
        $this->actingAs($secretary)->post(route('finance.transactions.refund', $payment), ['amount' => 50000, 'note' => 'تست'])->assertForbidden();
    }

    private function fixture(): array
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $manager = $this->makeUser('manager', $centre->id, '09128880101');
        $counselor = $this->makeUser('counselor', $centre->id, '09128880102');
        $clientUser = $this->makeUser('client', $centre->id, '09128880103');
        $client = Client::create(['user_id' => $clientUser->id, 'centre_id' => $centre->id, 'client_code' => 'CL-S09', 'status' => 'active']);
        $category = DB::table('service_categories')->insertGetId(['centre_id' => $centre->id, 'name' => 'Stage09', 'created_at' => now(), 'updated_at' => now()]);
        $topic = DB::table('service_topics')->insertGetId(['category_id' => $category, 'name' => 'مالی', 'minimum_minutes' => 30, 'session_minutes' => 60, 'break_minutes' => 0, 'capacity' => 1, 'requires_room' => false, 'is_active' => true, 'price' => 500000, 'color' => '#4477aa', 'allowed_modes' => json_encode(['in_person']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('counselor_topics')->insert(['user_id' => $counselor->id, 'topic_id' => $topic, 'centre_id' => $centre->id, 'is_active' => true]);
        $start = now()->addHour()->startOfMinute();
        DB::table('counselor_shifts')->insert(['user_id' => $counselor->id, 'centre_id' => $centre->id, 'weekday' => $start->dayOfWeek, 'starts_at' => '00:00:00', 'ends_at' => '23:59:59', 'hourly_pay' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $slot = AppointmentSlot::create(['centre_id' => $centre->id, 'topic_id' => $topic, 'counselor_id' => $counselor->id, 'slot_date' => $start->toDateString(), 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(45), 'mode' => 'in_person', 'capacity' => 1, 'booked_count' => 0, 'status' => 'available']);
        return [$manager, $client, $slot];
    }

    private function makeUser(string $roleSlug, int $centreId, string $phone): User
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user = User::create(['first_name' => 'کاربر', 'last_name' => $role->name, 'name' => 'کاربر '.$role->name, 'phone' => $phone, 'password' => 'ValidPass123!', 'role' => $roleSlug, 'role_id' => $role->id, 'centre_id' => $centreId, 'is_active' => true, 'status' => 'active', 'must_change_password' => false]);
        $user->roleAssignments()->create(['role_id' => $role->id, 'centre_id' => $centreId]);
        return $user;
    }
}
