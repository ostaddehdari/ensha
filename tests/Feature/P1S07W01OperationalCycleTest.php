<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\AppointmentWaitlist;
use App\Models\Centre;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\AppointmentBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class P1S07W01OperationalCycleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_secretary_accepts_pending_client_and_counselor_completes_session(): void
    {
        config(['appointments.lock_store' => 'array']);
        [$centre, $secretary, $counselor, $clients, $topic, $start] = $this->fixture();
        $slot = $this->slot($centre->id, $topic, $counselor->id, $start);
        $appointment = app(AppointmentBookingService::class)->book($slot->id, $clients[0]->id, null, $secretary->id);

        $this->actingAs($secretary)->post(route('operations.check-in', $appointment))->assertRedirect();
        $appointment->refresh();
        $this->assertSame('arrived', $appointment->status);
        $this->assertNotNull($appointment->checked_in_at);
        $this->assertSame($secretary->id, $appointment->checked_in_by);
        $this->assertDatabaseHas('appointment_status_histories', [
            'appointment_id' => $appointment->id, 'from_status' => 'pending', 'to_status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('appointment_status_histories', [
            'appointment_id' => $appointment->id, 'from_status' => 'confirmed', 'to_status' => 'arrived',
        ]);

        $this->actingAs($secretary)->get(route('operations.index', ['date' => $start->toDateString()]))
            ->assertOk()->assertSee('صف پذیرش‌شده‌ها')->assertSee('لغو / جابه‌جایی');

        $this->actingAs($counselor)->post(route('counselor.sessions.start', $appointment))->assertRedirect();
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'in_session']);
        $this->assertDatabaseHas('counselling_sessions', ['appointment_id' => $appointment->id, 'status' => 'in_progress']);

        $this->actingAs($counselor)->post(route('counselor.sessions.complete', $appointment))->assertRedirect();
        $appointment->refresh();
        $this->assertSame('completed', $appointment->status);
        $this->assertNotNull($appointment->session_started_at);
        $this->assertNotNull($appointment->session_ended_at);
        $this->assertDatabaseHas('counselling_sessions', ['appointment_id' => $appointment->id, 'status' => 'completed']);
    }

    public function test_no_show_and_cancel_release_capacity_with_auditable_history(): void
    {
        config(['appointments.lock_store' => 'array']);
        [$centre, $secretary, $counselor, $clients, $topic, $start] = $this->fixture();
        $noShowSlot = $this->slot($centre->id, $topic, $counselor->id, $start);
        $cancelSlot = $this->slot($centre->id, $topic, $counselor->id, $start->copy()->addHours(2));
        $noShow = app(AppointmentBookingService::class)->book($noShowSlot->id, $clients[0]->id, null, $secretary->id);
        $cancelled = app(AppointmentBookingService::class)->book($cancelSlot->id, $clients[1]->id, null, $secretary->id);

        $this->actingAs($secretary)->post(route('operations.no-show', $noShow), ['reason' => 'عدم حضور در زمان مقرر'])->assertRedirect();
        $this->assertDatabaseHas('appointments', ['id' => $noShow->id, 'status' => 'no_show']);
        $this->assertNotNull($noShow->fresh()->no_show_at);
        $this->assertSame('available', $noShowSlot->fresh()->status);
        $this->assertSame(0, $noShowSlot->fresh()->booked_count);

        $this->actingAs($secretary)->post(route('operations.cancel', $cancelled), ['reason' => 'درخواست مراجع'])->assertRedirect();
        $this->assertDatabaseHas('appointments', [
            'id' => $cancelled->id, 'status' => 'cancelled', 'cancellation_reason' => 'درخواست مراجع',
        ]);
        $this->assertSame('available', $cancelSlot->fresh()->status);
        $this->assertSame(0, $cancelSlot->fresh()->booked_count);
        $this->assertDatabaseHas('appointment_status_histories', [
            'appointment_id' => $cancelled->id, 'to_status' => 'cancelled', 'reason' => 'درخواست مراجع',
        ]);
    }

    public function test_reschedule_and_waitlist_promotion_update_slots_once(): void
    {
        config(['appointments.lock_store' => 'array']);
        [$centre, $secretary, $counselor, $clients, $topic, $start] = $this->fixture();
        $source = $this->slot($centre->id, $topic, $counselor->id, $start);
        $target = $this->slot($centre->id, $topic, $counselor->id, $start->copy()->addHours(2));
        $waitlistTarget = $this->slot($centre->id, $topic, $counselor->id, $start->copy()->addHours(4));
        $appointment = app(AppointmentBookingService::class)->book($source->id, $clients[0]->id, null, $secretary->id);

        $this->actingAs($secretary)->post(route('operations.reschedule', $appointment), [
            'slot_id' => $target->id, 'reason' => 'هماهنگی مجدد با مراجع',
        ])->assertRedirect(route('appointments.show', $appointment));
        $appointment->refresh();
        $this->assertSame($target->id, $appointment->slot_id);
        $this->assertSame(0, $source->fresh()->booked_count);
        $this->assertSame('available', $source->fresh()->status);
        $this->assertSame(1, $target->fresh()->booked_count);
        $this->assertSame('full', $target->fresh()->status);
        $this->assertDatabaseHas('appointment_status_histories', [
            'appointment_id' => $appointment->id, 'reason' => 'هماهنگی مجدد با مراجع',
        ]);

        $this->actingAs($secretary)->post(route('operations.waitlist.store'), [
            'client_id' => $clients[1]->id, 'topic_id' => $topic, 'priority' => 5,
            'desired_from' => $waitlistTarget->starts_at->toDateTimeString(), 'notes' => 'پذیرش در اولین زمان آزاد',
        ])->assertRedirect();
        $waitlist = AppointmentWaitlist::where('client_id', $clients[1]->id)->firstOrFail();
        $this->actingAs($secretary)->post(route('operations.waitlist.promote', $waitlist), [
            'slot_id' => $waitlistTarget->id,
        ])->assertRedirect();
        $waitlist->refresh();
        $this->assertSame('promoted', $waitlist->status);
        $this->assertNotNull($waitlist->promoted_at);
        $this->assertNotNull($waitlist->promoted_appointment_id);
        $this->assertSame(1, $waitlistTarget->fresh()->booked_count);
        $this->assertDatabaseHas('appointments', [
            'id' => $waitlist->promoted_appointment_id, 'client_id' => $clients[1]->id, 'slot_id' => $waitlistTarget->id,
        ]);
    }

    private function fixture(): array
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $secretary = $this->user('secretary', $centre->id, '09124447101');
        $counselor = $this->user('counselor', $centre->id, '09124447102');
        $clientUsers = [
            $this->user('client', $centre->id, '09124447103'),
            $this->user('client', $centre->id, '09124447104'),
        ];
        $clients = collect($clientUsers)->map(fn (User $user, int $index) => Client::create([
            'user_id' => $user->id, 'centre_id' => $centre->id, 'client_code' => 'CL-S07W01-'.($index + 1),
            'status' => 'active', 'profile_state' => 'complete',
        ]))->all();
        $category = DB::table('service_categories')->insertGetId([
            'centre_id' => $centre->id, 'name' => 'P1-S07-W01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $topic = DB::table('service_topics')->insertGetId([
            'category_id' => $category, 'name' => 'چرخه عملیاتی', 'minimum_minutes' => 30, 'session_minutes' => 60,
            'break_minutes' => 0, 'capacity' => 1, 'requires_room' => false, 'is_active' => true, 'price' => 500000,
            'color' => '#2563eb', 'allowed_modes' => json_encode(['in_person']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('counselor_topics')->insert([
            'user_id' => $counselor->id, 'topic_id' => $topic, 'centre_id' => $centre->id, 'is_active' => true,
        ]);
        $start = now()->addDay()->startOfDay()->addHours(9);
        DB::table('counselor_shifts')->insert([
            'user_id' => $counselor->id, 'centre_id' => $centre->id, 'weekday' => $start->dayOfWeek,
            'starts_at' => '00:00:00', 'ends_at' => '23:59:59', 'hourly_pay' => 0, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('client_record_settings')->where('centre_id', $centre->id)->update(['complete_required_on' => 'never']);

        return [$centre, $secretary, $counselor, $clients, $topic, $start];
    }

    private function slot(int $centreId, int $topicId, int $counselorId, Carbon $start): AppointmentSlot
    {
        return AppointmentSlot::create([
            'centre_id' => $centreId, 'topic_id' => $topicId, 'counselor_id' => $counselorId,
            'slot_date' => $start->toDateString(), 'starts_at' => $start, 'ends_at' => $start->copy()->addHour(),
            'mode' => 'in_person', 'capacity' => 1, 'booked_count' => 0, 'status' => 'available',
        ]);
    }

    private function user(string $roleSlug, int $centreId, string $phone): User
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user = User::create([
            'first_name' => 'آزمون', 'last_name' => $role->name, 'name' => 'آزمون '.$role->name,
            'phone' => $phone, 'password' => 'ValidPass123!', 'role' => $roleSlug, 'role_id' => $role->id,
            'centre_id' => $centreId, 'is_active' => true, 'status' => 'active', 'must_change_password' => false,
        ]);
        $user->roleAssignments()->create(['role_id' => $role->id, 'centre_id' => $centreId]);

        return $user;
    }
}
