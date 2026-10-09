<?php

namespace Tests\Feature;

use App\Models\AppointmentSlot;
use App\Models\Centre;
use App\Models\Client;
use App\Models\Role;
use App\Models\SessionRecording;
use App\Models\User;
use App\Services\AppointmentBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Stage08SecureRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_counselor_records_with_consent_and_audio_is_encrypted_at_rest(): void
    {
        Storage::fake('local');
        config(['appointments.lock_store' => 'array', 'clinical_audio.disk' => 'local']);
        [$counselor, $manager, $client, $slot] = $this->fixture();
        $appointment = app(AppointmentBookingService::class)->book($slot->id, $client->id, null, $manager->id);
        $this->actingAs($counselor)->post(route('counselor.sessions.start', $appointment))->assertRedirect();

        $this->actingAs($counselor)->post(route('counselor.recordings.consent', $appointment), [
            'confirmed' => '1', 'capture_method' => 'verbal',
        ])->assertRedirect();
        $consentId = DB::table('client_consents')->where('client_id', $client->id)->where('consent_type', 'recording')->value('id');

        $initialized = $this->actingAs($counselor)->postJson(route('counselor.recordings.initialize', $appointment), [
            'consent_id' => $consentId, 'mime_type' => 'audio/webm',
        ])->assertCreated();
        $recording = SessionRecording::where('public_id', $initialized->json('recording_id'))->firstOrFail();
        $plain = 'fake-webm-audio-content-for-stage08';
        $this->actingAs($counselor)->post(route('counselor.recordings.chunk', [$appointment, $recording]), [
            'index' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.webm', $plain),
        ])->assertOk();
        $this->actingAs($counselor)->postJson(route('counselor.recordings.finalize', [$appointment, $recording]), [
            'chunk_count' => 1, 'duration_seconds' => 5,
        ])->assertOk();

        $recording->refresh();
        $this->assertSame('ready', $recording->status);
        $encrypted = Storage::disk('local')->get($recording->path);
        $this->assertStringStartsWith('ENSHAAUD1', $encrypted);
        $this->assertStringNotContainsString($plain, $encrypted);
        $this->assertSame(hash('sha256', $plain), $recording->plaintext_sha256);
        $this->actingAs($counselor)->get(route('counselor.recordings.stream', $recording))->assertOk();
    }

    public function test_other_counselor_and_secretary_cannot_access_recording(): void
    {
        config(['appointments.lock_store' => 'array']);
        [$counselor, $manager, $client, $slot] = $this->fixture();
        $other = $this->makeUser('counselor', $slot->centre_id, '09127770201');
        $secretary = $this->makeUser('secretary', $slot->centre_id, '09127770202');
        $appointment = app(AppointmentBookingService::class)->book($slot->id, $client->id, null, $manager->id);
        $sessionId = DB::table('counselling_sessions')->insertGetId([
            'appointment_id' => $appointment->id, 'case_id' => $this->makeCase($appointment, $manager),
            'counselor_id' => $counselor->id, 'session_number' => 1, 'status' => 'in_progress',
            'created_by' => $counselor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $recording = SessionRecording::create([
            'public_id' => fake()->uuid(), 'centre_id' => $slot->centre_id, 'appointment_id' => $appointment->id,
            'counselling_session_id' => $sessionId, 'client_id' => $client->id, 'counselor_id' => $counselor->id,
            'disk' => 'local', 'status' => 'ready', 'path' => 'missing.eaudio', 'created_by' => $counselor->id,
        ]);

        $this->actingAs($other)->get(route('counselor.recordings.stream', $recording))->assertNotFound();
        $this->actingAs($secretary)->get(route('counselor.recordings.stream', $recording))->assertForbidden();
    }

    private function fixture(): array
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $manager = $this->makeUser('manager', $centre->id, '09127770101');
        $counselor = $this->makeUser('counselor', $centre->id, '09127770102');
        $clientUser = $this->makeUser('client', $centre->id, '09127770103');
        $client = Client::create(['user_id' => $clientUser->id, 'centre_id' => $centre->id, 'client_code' => 'CL-S08', 'status' => 'active']);
        $category = DB::table('service_categories')->insertGetId(['centre_id' => $centre->id, 'name' => 'Stage08', 'created_at' => now(), 'updated_at' => now()]);
        $topic = DB::table('service_topics')->insertGetId([
            'category_id' => $category, 'name' => 'ضبط امن', 'minimum_minutes' => 30, 'session_minutes' => 60,
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

    private function makeCase($appointment, User $manager): int
    {
        $id = DB::table('cases')->insertGetId([
            'client_id' => $appointment->client_id, 'centre_id' => $appointment->centre_id,
            'case_number' => 'CASE-S08-'.fake()->unique()->randomNumber(), 'title' => 'پرونده تست ضبط',
            'status' => 'open', 'opened_at' => now()->toDateString(), 'created_by' => $manager->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $appointment->update(['case_id' => $id]);
        return $id;
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
