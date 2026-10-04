<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\Client;
use App\Models\CounsellingCase;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Stage02CaseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_a_case_and_initial_status_history_is_recorded(): void
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $manager = $this->makeUser('manager', $centre, '09123330001');
        $clientUser = $this->makeUser('client', $centre, '09123330002');
        $client = Client::create(['user_id' => $clientUser->id, 'centre_id' => $centre->id, 'client_code' => 'CL-90001', 'status' => 'active']);

        $this->actingAs($manager)->post(route('cases.store'), ['client_id' => $client->id, 'title' => 'پرونده اولیه', 'status' => 'open', 'priority' => 'normal'])->assertRedirect();
        $case = CounsellingCase::firstOrFail();
        $this->assertDatabaseHas('case_status_histories', ['case_id' => $case->id, 'from_status' => null, 'to_status' => 'open']);
    }

    public function test_manager_can_assign_and_end_a_case_assignment(): void
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $manager = $this->makeUser('manager', $centre, '09123330003');
        $counselor = $this->makeUser('counselor', $centre, '09123330004');
        $clientUser = $this->makeUser('client', $centre, '09123330005');
        $client = Client::create(['user_id' => $clientUser->id, 'centre_id' => $centre->id, 'client_code' => 'CL-90002', 'status' => 'active']);
        $case = CounsellingCase::create(['client_id' => $client->id, 'centre_id' => $centre->id, 'case_number' => 'CASE-90001', 'title' => 'پرونده تخصیص', 'status' => 'open', 'priority' => 'normal', 'created_by' => $manager->id]);

        $this->actingAs($manager)->post(route('cases.assignments.store', $case), ['user_id' => $counselor->id, 'assignment_role' => 'counselor', 'is_primary' => '1'])->assertSessionHasNoErrors();
        $assignment = $case->assignments()->firstOrFail();
        $this->actingAs($manager)->delete(route('cases.assignments.destroy', [$case, $assignment]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('case_assignments', ['id' => $assignment->id, 'status' => 'ended']);
    }

    public function test_counselor_sees_only_assigned_cases(): void
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $counselor = $this->makeUser('counselor', $centre, '09123330006');
        $clientUser = $this->makeUser('client', $centre, '09123330007');
        $client = Client::create(['user_id' => $clientUser->id, 'centre_id' => $centre->id, 'client_code' => 'CL-90003', 'status' => 'active']);
        $case = CounsellingCase::create(['client_id' => $client->id, 'centre_id' => $centre->id, 'case_number' => 'CASE-90002', 'title' => 'پرونده محدود', 'status' => 'open', 'priority' => 'normal']);
        $this->actingAs($counselor)->get(route('cases.show', $case))->assertNotFound();
        $case->assignments()->create(['user_id' => $counselor->id, 'assignment_role' => 'counselor', 'status' => 'active']);
        $this->actingAs($counselor)->get(route('cases.show', $case))->assertOk()->assertSee('پرونده محدود');
    }

    private function makeUser(string $roleSlug, Centre $centre, string $phone): User
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user = User::create(['first_name' => 'کاربر', 'last_name' => $role->name, 'name' => 'کاربر '.$role->name, 'phone' => $phone, 'password' => 'ValidPass123!', 'role' => $roleSlug, 'role_id' => $role->id, 'centre_id' => $centre->id, 'is_active' => true, 'status' => 'active', 'must_change_password' => false]);
        $user->roleAssignments()->create(['role_id' => $role->id, 'centre_id' => $centre->id]);
        return $user;
    }
}
