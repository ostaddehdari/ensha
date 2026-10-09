<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_open_users_roles_and_permissions(): void
    {
        $admin = $this->makeUser('super_admin', '09121111111', '1234567891');

        $this->actingAs($admin)->get(route('users.index'))->assertOk();
        $this->actingAs($admin)->get(route('roles.index'))->assertOk();
        $this->actingAs($admin)->get(route('permissions.index'))->assertOk();
    }

    public function test_user_without_permission_cannot_open_access_control_pages(): void
    {
        $secretary = $this->makeUser('secretary', '09121111112', '1112223339');

        $this->actingAs($secretary)->get(route('users.index'))->assertForbidden();
        $this->actingAs($secretary)->get(route('roles.index'))->assertForbidden();
        $this->actingAs($secretary)->get(route('permissions.index'))->assertForbidden();
    }

    public function test_manager_sees_global_user_directory_for_credential_management(): void
    {
        $main = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $other = Centre::create(['name' => 'مرکز دوم', 'code' => 'SECOND', 'is_active' => true]);
        $manager = $this->makeUser('manager', '09122222222', '1112223339', $main);
        $this->makeUser('counselor', '09123333333', '2345678909', $main, 'کاربر مرکز اول');
        $this->makeUser('counselor', '09124444444', '3456789017', $other, 'کاربر مرکز دوم');

        $response = $this->actingAs($manager)->get(route('users.index'));

        $response->assertOk()->assertSee('کاربر مرکز اول')->assertSee('کاربر مرکز دوم');
    }

    public function test_manager_creates_staff_only_in_own_centre(): void
    {
        $main = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $other = Centre::create(['name' => 'مرکز دوم', 'code' => 'SECOND', 'is_active' => true]);
        $manager = $this->makeUser('manager', '09122222223', '1112223339', $main);
        $counselorRole = Role::where('slug', 'counselor')->firstOrFail();

        $response = $this->actingAs($manager)->post(route('users.store'), [
            'first_name' => 'مشاور',
            'last_name' => 'جدید',
            'phone' => '09125555551',
            'national_id' => '2345678909',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => $counselorRole->id,
            'centre_id' => $other->id,
            'is_active' => '1',
        ]);

        $response->assertSessionHasNoErrors();
        $created = User::where('phone', '09125555551')->firstOrFail();
        $this->assertSame($main->id, $created->centre_id);
        $this->assertTrue($created->must_change_password);
    }

    public function test_manager_cannot_edit_a_manager_account(): void
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $actor = $this->makeUser('manager', '09125555555', '4567890124', $centre);
        $target = $this->makeUser('manager', '09126666666', '5678901230', $centre);

        $this->actingAs($actor)->put(route('users.update', $target), [])->assertForbidden();
    }

    public function test_manager_cannot_assign_a_privileged_custom_role(): void
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $manager = $this->makeUser('manager', '09125555556', '4567890124', $centre);
        $role = Role::create([
            'name' => 'نقش پرقدرت',
            'slug' => 'privileged_custom',
            'scope' => 'centre',
            'color' => 'danger',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->attach(Permission::where('slug', 'settings.manage')->firstOrFail());

        $response = $this->actingAs($manager)->post(route('users.store'), [
            'first_name' => 'کاربر',
            'last_name' => 'آزمایشی',
            'phone' => '09125555557',
            'national_id' => '2345678909',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => $role->id,
            'centre_id' => $centre->id,
            'is_active' => '1',
        ]);

        $response->assertSessionHasErrors('role_id');
        $this->assertDatabaseMissing('users', ['phone' => '09125555557']);

        $privilegedUser = $this->makeUser('secretary', '09125555558', '3456789017', $centre);
        $privilegedUser->update(['role' => $role->slug, 'role_id' => $role->id]);
        $this->actingAs($manager)
            ->patch(route('users.status', $privilegedUser), ['status' => 'inactive'])
            ->assertForbidden();
    }

    public function test_password_reset_forces_change_and_invalidates_sessions(): void
    {
        $admin = $this->makeUser('super_admin', '09127777777', '1234567891');
        $target = $this->makeUser('secretary', '09128888888', '1112223339');
        $session = UserSession::create([
            'user_id' => $target->id,
            'session_hash' => hash('sha256', 'old-session'),
            'device_name' => 'Chrome · Windows',
            'last_activity_at' => now(),
        ]);
        $revision = $target->auth_revision;

        $response = $this->actingAs($admin)->patch(route('users.password', $target), [
            'password' => 'TempPass123!',
            'password_confirmation' => 'TempPass123!',
        ]);

        $response->assertSessionHasNoErrors();
        $target->refresh();
        $this->assertTrue($target->must_change_password);
        $this->assertTrue(Hash::check('TempPass123!', $target->password));
        $this->assertGreaterThan($revision, $target->auth_revision);
        $this->assertNotNull($session->fresh()->revoked_at);
    }

    public function test_temporary_password_blocks_dashboard_until_changed(): void
    {
        $user = $this->makeUser('secretary', '09121010101', '2345678909');
        $user->update(['must_change_password' => true]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('account.password.edit'));
    }

    public function test_blocked_user_cannot_log_in(): void
    {
        $user = $this->makeUser('secretary', '09121010102', '2345678909');
        $user->update(['status' => 'blocked', 'is_active' => false]);

        $this->post(route('login.store'), [
            'phone' => $user->phone,
            'password' => 'ValidPass123!',
        ])->assertSessionHasErrors('phone');

        $this->assertGuest();
    }

    public function test_self_status_and_role_changes_are_rejected(): void
    {
        $admin = $this->makeUser('super_admin', '09121010103', '1234567891');
        $managerRole = Role::where('slug', 'manager')->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('users.status', $admin), ['status' => 'inactive'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('users.update', $admin), [
                'first_name' => $admin->first_name,
                'last_name' => $admin->last_name,
                'phone' => $admin->phone,
                'national_id' => $admin->national_id,
                'role_id' => $managerRole->id,
            ])
            ->assertSessionHasErrors('role_id');
    }

    public function test_deleted_user_can_be_restored_only_as_inactive(): void
    {
        $admin = $this->makeUser('super_admin', '09121010104', '1234567891');
        $target = $this->makeUser('secretary', '09121010105', '1112223339');

        $this->actingAs($admin)->delete(route('users.destroy', $target))->assertRedirect(route('users.index'));
        $this->assertSoftDeleted('users', ['id' => $target->id]);

        $this->actingAs($admin)
            ->patch(route('users.restore', $target->id))
            ->assertRedirect(route('users.show', $target->id));

        $target->refresh();
        $this->assertFalse($target->is_active);
        $this->assertSame('inactive', $target->status);
        $this->assertNull($target->deleted_at);
    }

    public function test_custom_role_is_created_with_selected_permissions(): void
    {
        $admin = $this->makeUser('super_admin', '09129999999', '1234567891');
        $permissionIds = Permission::whereIn('slug', ['users.view', 'users.create'])->pluck('id')->all();

        $response = $this->actingAs($admin)->post(route('roles.store'), [
            'name' => 'هماهنگ‌کننده مرکز',
            'slug' => 'centre_coordinator',
            'description' => 'نقش آزمایشی',
            'scope' => 'centre',
            'color' => 'violet',
            'is_active' => '1',
            'permission_ids' => $permissionIds,
        ]);

        $response->assertSessionHasNoErrors();
        $role = Role::where('slug', 'centre_coordinator')->firstOrFail();
        $this->assertEqualsCanonicalizing($permissionIds, $role->permissions()->pluck('permissions.id')->all());
    }

    public function test_system_role_cannot_be_deleted(): void
    {
        $admin = $this->makeUser('super_admin', '09129999998', '1234567891');
        $managerRole = Role::where('slug', 'manager')->firstOrFail();

        $this->actingAs($admin)->delete(route('roles.destroy', $managerRole))->assertForbidden();
        $this->assertDatabaseHas('roles', ['id' => $managerRole->id]);
    }

    public function test_super_admin_can_start_and_stop_impersonation(): void
    {
        $admin = $this->makeUser('super_admin', '09129999997', '1234567891');
        $target = $this->makeUser('secretary', '09129999996', '1112223339');

        $this->actingAs($admin)
            ->post(route('users.impersonate', $target))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($target);
        $this->assertSame($admin->id, session('impersonator_id'));

        $this->post(route('impersonation.stop'))->assertRedirect(route('users.show', $target));
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('impersonator_id'));
    }

    public function test_super_admin_cannot_impersonate_another_super_admin(): void
    {
        $actor = $this->makeUser('super_admin', '09129999995', '1234567891');
        $target = $this->makeUser('super_admin', '09129999994', '1112223339');

        $this->actingAs($actor)->post(route('users.impersonate', $target))->assertForbidden();
    }

    public function test_manager_export_is_limited_to_own_centre(): void
    {
        $main = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $other = Centre::create(['name' => 'مرکز دوم', 'code' => 'SECOND-EXPORT', 'is_active' => true]);
        $manager = $this->makeUser('manager', '09129999993', '1234567891', $main);
        $allowed = $this->makeUser('secretary', '09129999992', '1112223339', $main, 'کاربر مجاز');
        $allowed->update(['first_name' => '=2+2', 'name' => '=2+2 مجاز']);
        $this->makeUser('secretary', '09129999991', '2345678909', $other, 'کاربر غیرمجاز');

        $response = $this->actingAs($manager)->get(route('users.export'));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('09129999992', $content);
        $this->assertStringNotContainsString('09129999991', $content);
        $this->assertStringContainsString("'=2+2", $content);
        $this->assertStringNotContainsString(',=2+2,', $content);
    }

    public function test_administrator_can_revoke_one_user_session(): void
    {
        $admin = $this->makeUser('super_admin', '09129999990', '1234567891');
        $target = $this->makeUser('secretary', '09129999989', '1112223339');
        $session = UserSession::create([
            'user_id' => $target->id,
            'session_hash' => hash('sha256', 'session-to-revoke'),
            'device_name' => 'Firefox · Linux',
            'last_activity_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patch(route('users.sessions.revoke', [$target, $session]))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($session->fresh()->revoked_at);
    }

    public function test_management_pages_render_with_complete_access_control_data(): void
    {
        $admin = $this->makeUser('super_admin', '09129999988', '1234567891');
        $target = $this->makeUser('secretary', '09129999987', '1112223339', name: 'کاربر نمایشی');
        $deleted = $this->makeUser('client', '09129999986', '2345678909', name: 'کاربر حذف شده');
        $deleted->delete();
        UserSession::create([
            'user_id' => $target->id,
            'session_hash' => hash('sha256', 'render-session'),
            'device_name' => 'Chrome · Windows',
            'last_activity_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('users.create'))->assertOk()->assertSee('ساخت حساب امن');
        $this->actingAs($admin)->get(route('users.edit', $target))->assertOk()->assertSee('کنترل تغییرات');
        $this->actingAs($admin)->get(route('users.show', $target))->assertOk()->assertSee('نشست‌ها و دستگاه‌های اخیر');
        $this->actingAs($admin)->get(route('users.trash'))->assertOk()->assertSee('کاربر حذف شده');
        $this->actingAs($admin)->get(route('roles.create'))->assertOk()->assertSee('ماتریس مجوزها');
        $this->actingAs($admin)->get(route('roles.edit', Role::where('slug', 'manager')->firstOrFail()))->assertOk()->assertSee('مدیر مرکز مشاوره');
    }

    public function test_session_device_details_are_hidden_without_session_permission(): void
    {
        $centre = Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
        $viewerRole = Role::create([
            'name' => 'ناظر کاربران',
            'slug' => 'user_viewer',
            'scope' => 'centre',
            'color' => 'info',
            'is_active' => true,
            'is_system' => false,
        ]);
        $viewerRole->permissions()->attach(Permission::where('slug', 'users.view')->firstOrFail());
        $viewer = $this->makeUser('secretary', '09129999985', '3456789017', $centre);
        $viewer->update(['role' => $viewerRole->slug, 'role_id' => $viewerRole->id]);
        $viewer->roleAssignments()->update(['role_id' => $viewerRole->id]);
        $target = $this->makeUser('client', '09129999984', '4567890124', $centre);
        UserSession::create([
            'user_id' => $target->id,
            'session_hash' => hash('sha256', 'private-session'),
            'ip_address' => '192.0.2.15',
            'device_name' => 'Firefox · Linux',
            'last_activity_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->get(route('users.show', $target))
            ->assertOk()
            ->assertDontSee('نشست‌ها و دستگاه‌های اخیر')
            ->assertDontSee('192.0.2.15');
    }

    private function makeUser(string $roleSlug, string $phone, string $nationalId, ?Centre $centre = null, string $name = 'کاربر آزمایشی'): User
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $centre ??= Centre::where('code', 'ENSHA-MAIN')->first();
        [$firstName, $lastName] = array_pad(explode(' ', $name, 2), 2, 'نمونه');

        $user = User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $name,
            'phone' => $phone,
            'national_id' => $nationalId,
            'password' => 'ValidPass123!',
            'role' => $roleSlug,
            'role_id' => $role->id,
            'centre_id' => $role->scope === 'global' ? null : $centre?->id,
            'is_active' => true,
            'status' => 'active',
            'status_changed_at' => now(),
            'must_change_password' => false,
            'auth_revision' => 1,
        ]);

        $user->roleAssignments()->create([
            'role_id' => $role->id,
            'centre_id' => $role->scope === 'global' ? null : $centre?->id,
        ]);

        return $user;
    }
}
