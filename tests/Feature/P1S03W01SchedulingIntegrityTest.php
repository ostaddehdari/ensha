<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Centre;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\AppointmentBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class P1S03W01SchedulingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_manual_creation_rejects_the_service_break_window(): void
    {
        [$centre, $manager, $counselors, $clients, $topicId, $date] = $this->fixture(
            breakMinutes: 20,
            capacity: 1,
            requiresRoom: false,
            checkRooms: false,
        );
        $start = $date->copy()->setTime(10, 0);
        $this->bookSlot($centre, $manager, $counselors[0], $clients[0], $topicId, $start, $start->copy()->addHour(), 'phone');

        $this->actingAs($manager)->postJson(route('appointments.calendar.api.store'), [
            'appointment_date' => $date->toDateString(),
            'start_time' => '11:10',
            'duration_minutes' => 30,
            'counselor_id' => $counselors[0]->id,
            'topic_id' => $topicId,
            'mode' => 'phone',
            'client_id' => $clients[1]->id,
        ])->assertStatus(409)->assertJsonValidationErrors('slot_id');

        $this->assertSame(1, Appointment::count());
    }

    public function test_resize_and_move_use_room_and_exception_rules(): void
    {
        [$centre, $manager, $counselors, $clients, $topicId, $date, $roomId] = $this->fixture(
            breakMinutes: 0,
            capacity: 1,
            requiresRoom: true,
            checkRooms: true,
        );
        $firstStart = $date->copy()->setTime(9, 0);
        $secondStart = $date->copy()->setTime(11, 0);
        $first = $this->bookSlot($centre, $manager, $counselors[0], $clients[0], $topicId, $firstStart, $firstStart->copy()->addHour(), 'in_person', $roomId);
        $this->bookSlot($centre, $manager, $counselors[1], $clients[1], $topicId, $secondStart, $secondStart->copy()->addHour(), 'in_person', $roomId);

        $this->actingAs($manager)->patchJson(route('appointments.calendar.api.update', $first), [
            'start' => $firstStart->toIso8601String(),
            'end' => $firstStart->copy()->addHours(3)->toIso8601String(),
            'counselor_id' => $counselors[0]->id,
        ])->assertStatus(409)->assertJsonValidationErrors('slot_id');

        DB::table('schedule_exceptions')->insert([
            'centre_id' => $centre->id,
            'user_id' => $counselors[0]->id,
            'exception_date' => $date->toDateString(),
            'starts_at' => '14:00:00',
            'ends_at' => '15:30:00',
            'type' => 'unavailable',
            'created_by' => $manager->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($manager)->patchJson(route('appointments.calendar.api.update', $first), [
            'start' => $date->copy()->setTime(14, 0)->toIso8601String(),
            'end' => $date->copy()->setTime(15, 0)->toIso8601String(),
            'counselor_id' => $counselors[0]->id,
        ])->assertStatus(409)->assertJsonValidationErrors('slot_id');

        $this->assertSame($firstStart->toDateTimeString(), $first->fresh()->starts_at->toDateTimeString());
    }

    public function test_manual_group_slot_uses_topic_and_room_capacity(): void
    {
        [$centre, $manager, $counselors, $clients, $topicId, $date, $roomId] = $this->fixture(
            breakMinutes: 0,
            capacity: 2,
            requiresRoom: true,
            checkRooms: true,
        );
        $payload = [
            'appointment_date' => $date->toDateString(),
            'start_time' => '16:00',
            'duration_minutes' => 60,
            'counselor_id' => $counselors[0]->id,
            'topic_id' => $topicId,
            'mode' => 'in_person',
        ];

        $this->actingAs($manager)->postJson(route('appointments.calendar.api.store'), $payload + ['client_id' => $clients[0]->id])
            ->assertCreated();
        $this->actingAs($manager)->postJson(route('appointments.calendar.api.store'), $payload + ['client_id' => $clients[1]->id])
            ->assertCreated();

        $slot = AppointmentSlot::where('counselor_id', $counselors[0]->id)
            ->where('starts_at', $date->copy()->setTime(16, 0))->firstOrFail();
        $this->assertSame(2, (int) $slot->capacity);
        $this->assertSame(2, (int) $slot->booked_count);
        $this->assertSame('full', $slot->status);
        $this->assertSame($roomId, (int) $slot->room_id);
        $this->assertSame(2, Appointment::where('slot_id', $slot->id)->count());
    }

    private function fixture(
        int $breakMinutes,
        int $capacity,
        bool $requiresRoom,
        bool $checkRooms,
    ): array {
        config(['appointments.lock_store' => 'array']);
        DB::table('official_holidays')->delete();
        DB::table('centre_closures')->delete();
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        DB::table('centre_booking_policies')->updateOrInsert(
            ['centre_id' => $centre->id],
            ['check_rooms' => $checkRooms, 'allow_past_bookings' => false, 'created_at' => now(), 'updated_at' => now()],
        );
        $manager = $this->user('manager', $centre, '09126661001');
        $counselors = [
            $this->user('counselor', $centre, '09126661002'),
            $this->user('counselor', $centre, '09126661003'),
        ];
        $clientUsers = [
            $this->user('client', $centre, '09126661004'),
            $this->user('client', $centre, '09126661005'),
        ];
        $clients = [
            Client::create(['user_id' => $clientUsers[0]->id, 'centre_id' => $centre->id, 'client_code' => 'CL-P1W01-1', 'status' => 'active']),
            Client::create(['user_id' => $clientUsers[1]->id, 'centre_id' => $centre->id, 'client_code' => 'CL-P1W01-2', 'status' => 'active']),
        ];
        $categoryId = DB::table('service_categories')->insertGetId([
            'centre_id' => $centre->id, 'name' => 'P1-S03-W01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $topicId = DB::table('service_topics')->insertGetId([
            'category_id' => $categoryId,
            'name' => 'کنترل یکپارچه برنامه',
            'minimum_minutes' => 15,
            'session_minutes' => 60,
            'break_minutes' => $breakMinutes,
            'capacity' => $capacity,
            'requires_room' => $requiresRoom,
            'is_active' => true,
            'price' => 800000,
            'color' => '#2563eb',
            'allowed_modes' => json_encode(['in_person', 'phone']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $date = Carbon::now()->addDays(12)->startOfDay();
        foreach ($counselors as $counselor) {
            DB::table('counselor_topics')->insert([
                'user_id' => $counselor->id, 'topic_id' => $topicId, 'centre_id' => $centre->id, 'is_active' => true,
            ]);
            DB::table('counselor_shifts')->insert([
                'user_id' => $counselor->id,
                'centre_id' => $centre->id,
                'weekday' => $date->dayOfWeek,
                'starts_at' => '00:00:00',
                'ends_at' => '23:59:59',
                'hourly_pay' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $roomId = null;
        if ($requiresRoom) {
            $roomId = DB::table('centre_rooms')->insertGetId([
                'centre_id' => $centre->id,
                'name' => 'اتاق کنترل ظرفیت',
                'capacity' => $capacity,
                'room_type' => 'consulting',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('room_topics')->insert(['room_id' => $roomId, 'topic_id' => $topicId]);
        }

        return [$centre, $manager, $counselors, $clients, $topicId, $date, $roomId];
    }

    private function bookSlot(
        Centre $centre,
        User $manager,
        User $counselor,
        Client $client,
        int $topicId,
        Carbon $start,
        Carbon $end,
        string $mode,
        ?int $roomId = null,
    ): Appointment {
        $capacity = (int) DB::table('service_topics')->where('id', $topicId)->value('capacity');
        $slot = AppointmentSlot::create([
            'centre_id' => $centre->id,
            'topic_id' => $topicId,
            'counselor_id' => $counselor->id,
            'room_id' => $roomId,
            'slot_date' => $start->toDateString(),
            'starts_at' => $start,
            'ends_at' => $end,
            'mode' => $mode,
            'capacity' => $capacity,
            'booked_count' => 0,
            'status' => 'available',
        ]);

        return app(AppointmentBookingService::class)->book($slot->id, $client->id, null, $manager->id);
    }

    private function user(string $slug, Centre $centre, string $phone): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();
        $user = User::create([
            'first_name' => 'کاربر',
            'last_name' => $role->name,
            'name' => 'کاربر '.$role->name,
            'phone' => $phone,
            'password' => 'ValidPass123!',
            'role' => $slug,
            'role_id' => $role->id,
            'centre_id' => $centre->id,
            'is_active' => true,
            'status' => 'active',
            'must_change_password' => false,
        ]);
        $user->roleAssignments()->create(['role_id' => $role->id, 'centre_id' => $centre->id]);

        return $user;
    }
}
