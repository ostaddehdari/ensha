<?php

namespace Tests\Feature;

use App\Models\AppointmentSlot;
use App\Models\Centre;
use App\Models\CentreBranch;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\JalaliDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class P1S03W02CalendarExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_calendar_exposes_branch_mode_timezone_and_inline_edit_controls(): void
    {
        [$centre, $manager, $branch] = $this->fixture();

        $this->actingAs($manager)->get(route('appointments.calendar'))
            ->assertOk()
            ->assertSee('name="branch_id"', false)
            ->assertSee('name="mode"', false)
            ->assertSee('data-edit-drawer', false)
            ->assertSee($branch->effectiveTimezone());

        $javascript = file_get_contents(public_path('js/stage06-scheduler.js'));
        $this->assertStringContainsString('openEdit(args.e.data.id)', $javascript);
        $this->assertStringContainsString('branch_id:branch', $javascript);
        $this->assertStringContainsString('تاریخ شمسی', $javascript);
    }

    public function test_branch_filter_returns_local_time_and_jalali_metadata(): void
    {
        [$centre, $manager, $branch, $counselor, $client, $topicId, $localDate] = $this->fixture();
        $start = Carbon::createFromFormat('!Y-m-d H:i', $localDate.' 10:00', $branch->effectiveTimezone())
            ->setTimezone(config('app.timezone'));
        $slot = AppointmentSlot::create([
            'centre_id' => $centre->id, 'branch_id' => $branch->id, 'topic_id' => $topicId,
            'counselor_id' => $counselor->id, 'slot_date' => $localDate, 'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(), 'mode' => 'phone', 'capacity' => 1,
            'booked_count' => 0, 'status' => 'available',
        ]);
        $appointment = app(AppointmentBookingService::class)->book($slot->id, $client->id, null, $manager->id);

        $response = $this->actingAs($manager)->getJson(route('appointments.calendar.api.events', [
            'start' => $localDate, 'end' => Carbon::parse($localDate)->addDay()->toDateString(),
            'branch_id' => $branch->id,
        ]))->assertOk()->assertJsonCount(1);

        $response->assertJsonPath('0.id', $appointment->id)
            ->assertJsonPath('0.start', $localDate.'T10:00:00')
            ->assertJsonPath('0.timezone', 'Europe/London')
            ->assertJsonPath('0.branchId', $branch->id)
            ->assertJsonPath('0.jalaliDate', app(JalaliDate::class)->format(Carbon::parse($localDate)));
    }

    public function test_manual_booking_persists_branch_and_converts_branch_local_time(): void
    {
        [$centre, $manager, $branch, $counselor, $client, $topicId, $localDate] = $this->fixture();

        $response = $this->actingAs($manager)->postJson(route('appointments.calendar.api.store'), [
            'appointment_date' => $localDate,
            'start_time' => '14:00',
            'duration_minutes' => 45,
            'branch_id' => $branch->id,
            'counselor_id' => $counselor->id,
            'topic_id' => $topicId,
            'mode' => 'phone',
            'client_id' => $client->id,
        ])->assertCreated();

        $expected = Carbon::createFromFormat('!Y-m-d H:i', $localDate.' 14:00', 'Europe/London')
            ->setTimezone(config('app.timezone'));
        $appointmentId = $response->json('id');
        $this->assertDatabaseHas('appointments', ['id' => $appointmentId, 'branch_id' => $branch->id]);
        $this->assertSame($expected->toDateTimeString(), DB::table('appointments')->where('id', $appointmentId)->value('starts_at'));
        $response->assertJsonPath('start', $localDate.'T14:00:00')->assertJsonPath('timezone', 'Europe/London');
    }

    private function fixture(): array
    {
        config(['appointments.lock_store' => 'array']);
        DB::table('official_holidays')->delete();
        DB::table('centre_closures')->delete();
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $centre->update(['timezone' => 'Asia/Tehran']);
        $branch = CentreBranch::create([
            'centre_id' => $centre->id, 'name' => 'شعبه لندن', 'code' => 'LONDON',
            'timezone' => 'Europe/London', 'is_default' => false, 'is_active' => true,
        ]);
        $manager = $this->user('manager', $centre, '09127772001');
        $counselor = $this->user('counselor', $centre, '09127772002');
        $clientUser = $this->user('client', $centre, '09127772003');
        $client = Client::create(['user_id' => $clientUser->id, 'centre_id' => $centre->id, 'client_code' => 'CL-P1W02', 'status' => 'active']);
        $categoryId = DB::table('service_categories')->insertGetId(['centre_id' => $centre->id, 'name' => 'P1-S03-W02', 'created_at' => now(), 'updated_at' => now()]);
        $topicId = DB::table('service_topics')->insertGetId([
            'category_id' => $categoryId, 'name' => 'جلسه منطقه زمانی', 'minimum_minutes' => 15,
            'session_minutes' => 60, 'break_minutes' => 0, 'capacity' => 1, 'requires_room' => false,
            'is_active' => true, 'price' => 900000, 'color' => '#2563eb',
            'allowed_modes' => json_encode(['phone']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('counselor_topics')->insert(['user_id' => $counselor->id, 'topic_id' => $topicId, 'centre_id' => $centre->id, 'is_active' => true]);
        $localDate = Carbon::now('Europe/London')->addDays(15)->toDateString();
        $weekday = Carbon::parse($localDate, 'Europe/London')->dayOfWeek;
        DB::table('counselor_shifts')->insert([
            'user_id' => $counselor->id, 'centre_id' => $centre->id, 'branch_id' => $branch->id,
            'weekday' => $weekday, 'starts_at' => '00:00:00', 'ends_at' => '23:59:59',
            'hourly_pay' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$centre, $manager, $branch, $counselor, $client, $topicId, $localDate];
    }

    private function user(string $slug, Centre $centre, string $phone): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();
        $user = User::create([
            'first_name' => 'کاربر', 'last_name' => $role->name, 'name' => 'کاربر '.$role->name,
            'phone' => $phone, 'password' => 'ValidPass123!', 'role' => $slug, 'role_id' => $role->id,
            'centre_id' => $centre->id, 'is_active' => true, 'status' => 'active', 'must_change_password' => false,
        ]);
        $user->roleAssignments()->create(['role_id' => $role->id, 'centre_id' => $centre->id, 'branch_id' => null]);

        return $user;
    }
}
