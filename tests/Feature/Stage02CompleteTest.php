<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\Client;
use App\Models\ConfidentialNote;
use App\Models\CounsellingCase;
use App\Models\CounsellingSession;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Stage02CompleteTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_manager_can_capture_intake_guardian_emergency_contact_and_consent(): void
    {
        [$centre, $manager, $client] = $this->fixture('09124441001');
        $this->actingAs($manager)->post(route('clients.intakes.store', $client), ['status' => 'complete', 'risk_level' => 'medium', 'presenting_concern' => 'درخواست مشاوره'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('clients.guardians.store', $client), ['full_name' => 'ولی آزمون', 'relationship' => 'پدر', 'phone' => '09120000000', 'has_legal_authority' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('clients.emergency-contacts.store', $client), ['full_name' => 'تماس آزمون', 'relationship' => 'برادر', 'phone' => '09121111111', 'priority' => 1])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('clients.consents.store', $client), ['consent_type' => 'treatment', 'document_version' => '1.0'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('client_intakes', ['client_id' => $client->id, 'status' => 'complete', 'risk_level' => 'medium']);
        $this->assertDatabaseHas('client_guardians', ['client_id' => $client->id, 'has_legal_authority' => true]);
        $this->assertDatabaseHas('emergency_contacts', ['client_id' => $client->id, 'priority' => 1]);
        $this->assertDatabaseHas('client_consents', ['client_id' => $client->id, 'consent_type' => 'treatment', 'is_granted' => true]);
    }

    public function test_finalized_note_is_locked_and_accepts_addendum(): void
    {
        [$centre, $manager, $client] = $this->fixture('09124442001');
        $case = $this->makeCase($client, $manager);
        $session = CounsellingSession::create(['case_id' => $case->id, 'counselor_id' => $manager->id, 'session_number' => 1, 'channel' => 'in_person', 'status' => 'completed', 'created_by' => $manager->id]);
        $note = ConfidentialNote::create(['case_id' => $case->id, 'session_id' => $session->id, 'author_id' => $manager->id, 'body' => 'متن محرمانه', 'status' => 'draft']);
        $this->actingAs($manager)->patch(route('cases.notes.finalize', [$case, $note]))->assertSessionHasNoErrors();
        $note->refresh();
        $this->assertSame('finalized', $note->status);
        $this->assertSame(hash('sha256', 'متن محرمانه'), $note->content_hash);
        $this->actingAs($manager)->put(route('cases.notes.update', [$case, $note]), ['body' => 'ویرایش غیرمجاز'])->assertStatus(409);
        $this->actingAs($manager)->post(route('cases.notes.addenda.store', [$case, $note]), ['body' => 'الحاقیه معتبر'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('note_addenda', ['confidential_note_id' => $note->id]);
    }

    public function test_private_file_is_not_written_to_public_disk(): void
    {
        Storage::fake('local');
        [$centre, $manager, $client] = $this->fixture('09124443001');
        $this->actingAs($manager)->post(route('clients.private-files.store', $client), ['classification' => 'identity', 'file' => UploadedFile::fake()->create('identity.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
        $file = $client->privateFiles()->firstOrFail();
        Storage::disk('local')->assertExists($file->path);
        $this->assertStringStartsWith('ensha-private/', $file->path);
        $this->assertSame('local', $file->disk);
    }

    public function test_duplicate_clients_can_be_merged_with_audit_record(): void
    {
        [$centre, $manager, $target] = $this->fixture('09124444001');
        $sourceUser = $this->makeUser('client', $centre, '09124444003');
        $source = Client::create(['user_id' => $sourceUser->id, 'centre_id' => $centre->id, 'client_code' => 'CL-99002', 'status' => 'active']);
        $case = $this->makeCase($source, $manager);
        $this->actingAs($manager)->post(route('clients.merge'), ['source_client_id' => $source->id, 'target_client_id' => $target->id, 'reason' => 'هویت مراجع پس از بررسی حضوری تکراری تأیید شد', 'confirmation' => 'MERGE'])->assertRedirect(route('clients.show', $target));
        $this->assertDatabaseHas('clients', ['id' => $source->id, 'status' => 'archived', 'merged_into_id' => $target->id]);
        $this->assertDatabaseHas('cases', ['id' => $case->id, 'client_id' => $target->id]);
        $this->assertDatabaseHas('client_merge_records', ['source_client_id' => $source->id, 'target_client_id' => $target->id, 'status' => 'completed']);
    }

    private function fixture(string $phone): array
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $manager = $this->makeUser('manager', $centre, $phone);
        $clientUser = $this->makeUser('client', $centre, substr($phone, 0, -1).'2');
        $client = Client::create(['user_id' => $clientUser->id, 'centre_id' => $centre->id, 'client_code' => 'CL-99001', 'status' => 'active']);
        return [$centre, $manager, $client];
    }

    private function makeCase(Client $client, User $creator): CounsellingCase
    {
        return CounsellingCase::create(['client_id' => $client->id, 'centre_id' => $client->centre_id, 'case_number' => 'CASE-99001', 'title' => 'پرونده آزمون', 'status' => 'open', 'priority' => 'normal', 'created_by' => $creator->id]);
    }

    private function makeUser(string $roleSlug, Centre $centre, string $phone): User
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user = User::create(['first_name' => 'کاربر', 'last_name' => $role->name, 'name' => 'کاربر '.$role->name, 'phone' => $phone, 'password' => 'ValidPass123!', 'role' => $roleSlug, 'role_id' => $role->id, 'centre_id' => $centre->id, 'is_active' => true, 'status' => 'active', 'must_change_password' => false]);
        $user->roleAssignments()->create(['role_id' => $role->id, 'centre_id' => $centre->id]);
        return $user;
    }
}
