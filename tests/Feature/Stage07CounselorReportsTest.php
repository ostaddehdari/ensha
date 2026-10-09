<?php

namespace Tests\Feature;

use App\Models\AppointmentSlot;
use App\Models\Centre;
use App\Models\Client;
use App\Models\Role;
use App\Models\SessionReportTemplate;
use App\Models\User;
use App\Services\AppointmentBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Stage07CounselorReportsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_counselor_can_complete_and_lock_structured_report_for_own_appointment(): void
    {
        config(['appointments.lock_store' => 'array']);
        [$counselor, $manager, $client, $slot] = $this->fixture();
        $appointment = app(AppointmentBookingService::class)->book($slot->id, $client->id, null, $manager->id);
        $template = SessionReportTemplate::effectiveFor($appointment->centre_id, $appointment->topic_id)->firstOrFail();
        $required = collect($template->fields)->firstWhere('key', 'session_result');

        $this->actingAs($counselor)->post(route('counselor.sessions.start', $appointment))->assertRedirect();
        $this->assertDatabaseHas('counselling_sessions', ['appointment_id' => $appointment->id, 'status' => 'in_progress']);

        $this->actingAs($counselor)->put(route('counselor.reports.save', $appointment), [
            'summary' => 'شرح محرمانه جلسه آزمایشی',
            'outcome' => 'نتیجه مناسب بود.',
            'answers' => [$required['key'] => $required['options'][0]],
            'follow_up_required' => true,
            'follow_up_at' => now()->addWeek()->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $this->assertDatabaseHas('session_reports', ['appointment_id' => $appointment->id, 'status' => 'draft']);

        $this->actingAs($counselor)->post(route('counselor.sessions.complete', $appointment))->assertRedirect();
        $this->actingAs($counselor)->patch(route('counselor.reports.finalize', $appointment))->assertRedirect();
        $this->assertDatabaseHas('session_reports', ['appointment_id' => $appointment->id, 'status' => 'finalized', 'finalized_by' => $counselor->id]);
        $this->assertNotNull(DB::table('session_reports')->where('appointment_id', $appointment->id)->value('content_hash'));

        $this->actingAs($counselor)->put(route('counselor.reports.save', $appointment), ['summary' => 'ویرایش غیرمجاز'])->assertStatus(409);
    }

    public function test_counselor_cannot_open_another_counselors_report(): void
    {
        config(['appointments.lock_store' => 'array']);
        [$counselor, $manager, $client, $slot] = $this->fixture();
        $other = $this->makeUser('counselor', $slot->centre_id, '09126660200');
        $appointment = app(AppointmentBookingService::class)->book($slot->id, $client->id, null, $manager->id);

        $this->actingAs($other)->get(route('counselor.reports.show', $appointment))->assertNotFound();
        $this->actingAs($counselor)->get(route('counselor.reports.show', $appointment))->assertOk();
    }

    private function fixture(): array
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $manager = $this->makeUser('manager', $centre->id, '09126660101');
        $counselor = $this->makeUser('counselor', $centre->id, '09126660102');
        $clientUser = $this->makeUser('client', $centre->id, '09126660103');
        $client = Client::create(['user_id' => $clientUser->id, 'centre_id' => $centre->id, 'client_code' => 'CL-S07', 'status' => 'active']);
        $category = DB::table('service_categories')->insertGetId(['centre_id' => $centre->id, 'name' => 'Stage07', 'created_at' => now(), 'updated_at' => now()]);
        $topic = DB::table('service_topics')->insertGetId([
            'category_id' => $category, 'name' => 'گزارش بالینی', 'minimum_minutes' => 30, 'session_minutes' => 60,
            'break_minutes' => 0, 'capacity' => 1, 'requires_room' => false, 'is_active' => true, 'price' => 500000,
            'color' => '#5577dd', 'allowed_modes' => json_encode(['in_person']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('counselor_topics')->insert(['user_id' => $counselor->id, 'topic_id' => $topic, 'centre_id' => $centre->id, 'is_active' => true]);
        $start = now()->addHour()->startOfMinute();
        DB::table('counselor_shifts')->insert([
            'user_id' => $counselor->id, 'centre_id' => $centre->id, 'weekday' => $start->dayOfWeek,
            'starts_at' => '00:00:00', 'ends_at' => '23:59:59', 'hourly_pay' => 0, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $slot = AppointmentSlot::create([
            'centre_id' => $centre->id, 'topic_id' => $topic, 'counselor_id' => $counselor->id,
            'slot_date' => $start->toDateString(), 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(45),
            'mode' => 'in_person', 'capacity' => 1, 'booked_count' => 0, 'status' => 'available',
        ]);
        return [$counselor, $manager, $client, $slot];
    }

    private function makeUser(string $roleSlug, int $centreId, string $phone): User
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user = User::create([
            'first_name' => 'کاربر', 'last_name' => $role->name, 'name' => 'کاربر '.$role->name,
            'phone' => $phone, 'password' => 'ValidPass123!', 'role' => $roleSlug, 'role_id' => $role->id,
            'centre_id' => $centreId, 'is_active' => true, 'status' => 'active', 'must_change_password' => false,
        ]);
        $user->roleAssignments()->create(['role_id' => $role->id, 'centre_id' => $centreId]);
        return $user;
    }
}
