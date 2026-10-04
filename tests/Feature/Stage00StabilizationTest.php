<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Stage00StabilizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_contiguous_slots_create_independent_shifts(): void
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $manager = $this->makeUser(Role::where('slug', 'manager')->firstOrFail(), $centre, '09120001001');
        $counselor = $this->makeUser(Role::where('slug', 'counselor')->firstOrFail(), $centre, '09120001002');

        $response = $this->actingAs($manager)->post(route('centres.operations.shift', $centre), [
            'user_id' => $counselor->id,
            'weekday' => 0,
            'slots' => ['08:00', '08:30', '11:00'],
            'hourly_pay' => 500000,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('counselor_shifts', ['user_id' => $counselor->id, 'starts_at' => '08:00', 'ends_at' => '09:00']);
        $this->assertDatabaseHas('counselor_shifts', ['user_id' => $counselor->id, 'starts_at' => '11:00', 'ends_at' => '11:30']);
        $this->assertDatabaseCount('counselor_shifts', 2);
    }

    public function test_custom_read_only_role_can_view_but_cannot_change_schedules(): void
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $role = Role::create([
            'name' => 'ناظر برنامه',
            'slug' => 'schedule_viewer',
            'scope' => 'centre',
            'color' => 'info',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->attach(Permission::where('slug', 'schedules.view')->firstOrFail());
        $viewer = $this->makeUser($role, $centre, '09120001003');
        $counselor = $this->makeUser(Role::where('slug', 'counselor')->firstOrFail(), $centre, '09120001004');

        $this->actingAs($viewer)->get(route('centres.work-hours', $centre))->assertOk();
        $this->actingAs($viewer)->post(route('centres.operations.shift', $centre), [
            'user_id' => $counselor->id,
            'weekday' => 0,
            'slots' => ['08:00'],
            'hourly_pay' => 0,
        ])->assertForbidden();
    }

    private function makeUser(Role $role, Centre $centre, string $phone): User
    {
        $user = User::create([
            'first_name' => 'کاربر',
            'last_name' => 'آزمایشی',
            'name' => 'کاربر آزمایشی',
            'phone' => $phone,
            'password' => 'ValidPass123!',
            'role' => $role->slug,
            'role_id' => $role->id,
            'centre_id' => $centre->id,
            'is_active' => true,
            'status' => 'active',
        ]);
        $user->roleAssignments()->create(['role_id' => $role->id, 'centre_id' => $centre->id]);

        return $user;
    }
}
