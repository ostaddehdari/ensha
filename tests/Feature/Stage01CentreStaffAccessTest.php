<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\CentreBranch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Stage01CentreStaffAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_centre_has_a_default_branch_and_stage_permissions(): void
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $branch = $centre->branches()->where('is_default', true)->firstOrFail();

        $this->assertTrue($branch->is_active);
        $this->assertSame('Asia/Tehran', $centre->timezone);
        $manager = Role::where('slug', 'manager')->firstOrFail();
        $this->assertTrue($manager->permissions()->where('slug', 'branches.manage')->exists());
        $this->assertTrue($manager->permissions()->where('slug', 'staff.manage')->exists());
    }

    public function test_manager_manages_branches_and_secretary_has_read_only_access(): void
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $manager = $this->makeUser('manager', $centre, '09121110001');
        $secretary = $this->makeUser('secretary', $centre, '09121110002');

        $this->actingAs($manager)->post(route('centres.branches.store', $centre), [
            'name' => 'شعبه شمال', 'code' => 'NORTH', 'timezone' => 'Asia/Tehran',
            'phone' => '02112345678', 'is_active' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('centre_branches', ['centre_id' => $centre->id, 'code' => 'NORTH', 'is_active' => true]);

        $this->actingAs($secretary)->get(route('centres.branches.index', $centre))->assertOk()->assertSee('شعبه شمال');
        $this->actingAs($secretary)->post(route('centres.branches.store', $centre), [
            'name' => 'غیرمجاز', 'code' => 'DENIED', 'is_active' => '1',
        ])->assertForbidden();
    }

    public function test_manager_updates_structured_centre_settings(): void
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $manager = $this->makeUser('manager', $centre, '09121110003');

        $this->actingAs($manager)->put(route('centres.settings.update', $centre), [
            'timezone' => 'Europe/London',
            'appointment_slot_minutes' => 15,
            'default_session_minutes' => 45,
            'week_starts_on' => 1,
            'working_day_start' => '09:00',
            'working_day_end' => '18:00',
        ])->assertSessionHasNoErrors();

        $centre->refresh();
        $this->assertSame('Europe/London', $centre->timezone);
        $this->assertSame(15, $centre->settings['appointment_slot_minutes']);
        $this->assertSame('18:00', $centre->settings['working_day_end']);
    }

    public function test_non_counselor_staff_profile_and_branch_are_managed(): void
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $manager = $this->makeUser('manager', $centre, '09121110004');
        $secretary = $this->makeUser('secretary', $centre, '09121110005');
        $branch = CentreBranch::create([
            'centre_id' => $centre->id, 'name' => 'شعبه غرب', 'code' => 'WEST',
            'is_active' => true, 'is_default' => false,
        ]);
        $assignment = $secretary->roleAssignments()->firstOrFail();

        $this->actingAs($manager)->put(route('staff.update', $secretary), [
            'employee_code' => 'EMP-1005',
            'job_title' => 'منشی ارشد',
            'department' => 'پذیرش',
            'employment_type' => 'employee',
            'work_email' => 'staff@example.test',
            'assignment_branches' => [$assignment->id => $branch->id],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('staff_profiles', ['user_id' => $secretary->id, 'employee_code' => 'EMP-1005', 'department' => 'پذیرش']);
        $this->assertDatabaseHas('user_role_centres', ['id' => $assignment->id, 'branch_id' => $branch->id]);
        $this->actingAs($manager)->get(route('staff.index'))->assertOk()->assertSee('منشی ارشد')->assertSee('شعبه غرب');
    }

    public function test_staff_cannot_be_assigned_to_a_branch_from_another_centre(): void
    {
        $main = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $other = Centre::create(['name' => 'مرکز دیگر', 'code' => 'OTHER', 'timezone' => 'Asia/Tehran', 'is_active' => true]);
        $otherBranch = CentreBranch::create(['centre_id' => $other->id, 'name' => 'اصلی', 'code' => 'MAIN', 'is_default' => true, 'is_active' => true]);
        $manager = $this->makeUser('manager', $main, '09121110006');
        $employee = $this->makeUser('secretary', $main, '09121110007');
        $assignment = $employee->roleAssignments()->firstOrFail();

        $this->actingAs($manager)->put(route('staff.update', $employee), [
            'employment_type' => 'contract',
            'assignment_branches' => [$assignment->id => $otherBranch->id],
        ])->assertSessionHasErrors('assignment_branches');
        $this->assertNull($assignment->fresh()->branch_id);
    }

    private function makeUser(string $roleSlug, Centre $centre, string $phone): User
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user = User::create([
            'first_name' => 'کاربر', 'last_name' => $role->name, 'name' => 'کاربر '.$role->name,
            'phone' => $phone, 'password' => 'ValidPass123!', 'role' => $roleSlug,
            'role_id' => $role->id, 'centre_id' => $centre->id,
            'is_active' => true, 'status' => 'active', 'must_change_password' => false,
        ]);
        $user->roleAssignments()->create(['role_id' => $role->id, 'centre_id' => $centre->id]);

        return $user;
    }
}
