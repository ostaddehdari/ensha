<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Services\BulkPasswordResetService;
use App\Services\CredentialAdministratorProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class P1S07W01CredentialHotfixTest extends TestCase
{
    use RefreshDatabase;

    public function test_designated_user_gets_super_admin_manager_and_direct_credential_permission(): void
    {
        $administrator = $this->user('secretary', '09130134984', $this->mainCentre());

        $provisioned = app(CredentialAdministratorProvisioner::class)->provision('09130134984');

        $this->assertTrue($provisioned->isSuperAdmin());
        $this->assertTrue($provisioned->hasPermission(User::GLOBAL_CREDENTIAL_PERMISSION));
        $this->assertTrue($provisioned->hasPermission('users.update'));
        $this->assertDatabaseHas('user_role_centres', [
            'user_id' => $administrator->id,
            'role_id' => Role::where('slug', 'super_admin')->value('id'),
            'centre_id' => null,
        ]);
        $this->assertDatabaseHas('user_role_centres', [
            'user_id' => $administrator->id,
            'role_id' => Role::where('slug', 'manager')->value('id'),
            'centre_id' => $this->mainCentre()->id,
        ]);
    }

    public function test_super_admin_and_manager_roles_receive_global_credential_permission(): void
    {
        $permissionId = \App\Models\Permission::where('slug', User::GLOBAL_CREDENTIAL_PERMISSION)->value('id');

        foreach (['super_admin', 'manager'] as $roleSlug) {
            $role = Role::where('slug', $roleSlug)->firstOrFail();
            $this->assertDatabaseHas('permission_role', [
                'role_id' => $role->id,
                'permission_id' => $permissionId,
            ]);
        }

        $manager = $this->user('manager', '09121110001', $this->mainCentre());
        $this->assertTrue($manager->hasPermission(User::GLOBAL_CREDENTIAL_PERMISSION));
    }

    public function test_designated_user_may_keep_manager_assignments_for_multiple_centres(): void
    {
        $main = $this->mainCentre();
        $other = Centre::create(['name' => 'مرکز دوم مدیر', 'code' => 'ADMIN-SECOND', 'is_active' => true]);
        $administrator = $this->user('manager', '09130134984', $main);
        $administrator->roleAssignments()->create([
            'role_id' => Role::where('slug', 'manager')->value('id'),
            'centre_id' => $other->id,
        ]);

        $provisioned = app(CredentialAdministratorProvisioner::class)->provision('09130134984');
        $roleSlugs = $provisioned->roleAssignments()
            ->with('role:id,slug')
            ->get()
            ->pluck('role.slug')
            ->filter()
            ->intersect(['super_admin', 'manager'])
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['manager', 'super_admin'], $roleSlugs);
        $this->assertSame(3, $provisioned->roleAssignments()->count());
    }

    public function test_designated_super_admin_sees_accounts_in_all_centres_and_has_full_management_access(): void
    {
        $main = $this->mainCentre();
        $other = Centre::create(['name' => 'مرکز دوم', 'code' => 'SECOND', 'is_active' => true]);
        $administrator = $this->credentialAdministrator($main);
        $remote = $this->user('counselor', '09121112222', $other, 'مشاور مرکز دوم');
        $superAdmin = $this->user('super_admin', '09121113333', $main, 'مدیر کل');

        $this->actingAs($administrator)->get(route('users.index'))
            ->assertOk()
            ->assertSee('مشاور مرکز دوم')
            ->assertSee('مدیر کل');
        $this->actingAs($administrator)->get(route('users.show', $remote))->assertOk();
        $this->actingAs($administrator)->get(route('users.edit', $remote))->assertOk();
        $this->actingAs($administrator)->get(route('roles.index'))->assertOk();
        $this->actingAs($administrator)->get(route('permissions.index'))->assertOk();
    }

    public function test_credential_administrator_can_change_only_phone_globally_and_sessions_are_revoked(): void
    {
        $main = $this->mainCentre();
        $other = Centre::create(['name' => 'مرکز سوم', 'code' => 'THIRD', 'is_active' => true]);
        $administrator = $this->credentialAdministrator($main);
        $target = $this->user('manager', '09122223333', $other);
        $session = $this->userSession($target, 'phone-change-session');
        $revision = $target->auth_revision;

        $this->actingAs($administrator)->patch(route('users.phone', $target), [
            'phone' => '+98 912 345 6789',
        ])->assertSessionHasNoErrors();

        $target->refresh();
        $this->assertSame('09123456789', $target->phone);
        $this->assertGreaterThan($revision, $target->auth_revision);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertSame('phone_changed_by_credential_administrator', $session->fresh()->revoke_reason);
    }

    public function test_credential_administrator_can_reset_other_accounts_password_globally_but_not_own_password(): void
    {
        $main = $this->mainCentre();
        $administrator = $this->credentialAdministrator($main);
        $target = $this->user('super_admin', '09124445555', $main);
        $session = $this->userSession($target, 'password-reset-session');

        $this->actingAs($administrator)->patch(route('users.password', $target), [
            'password' => 'Temporary123!',
            'password_confirmation' => 'Temporary123!',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Temporary123!', $target->fresh()->password));
        $this->assertTrue($target->fresh()->must_change_password);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->actingAs($administrator)->patch(route('users.password', $administrator), [
            'password' => 'Temporary456!',
            'password_confirmation' => 'Temporary456!',
        ])->assertForbidden();
    }

    public function test_bulk_reset_changes_active_inactive_and_soft_deleted_accounts_and_revokes_every_session(): void
    {
        $main = $this->mainCentre();
        $active = $this->user('secretary', '09125556661', $main);
        $inactive = $this->user('counselor', '09125556662', $main);
        $inactive->update(['status' => 'inactive', 'is_active' => false]);
        $deleted = $this->user('client', '09125556663', $main);
        $deleted->delete();
        $this->userSession($active, 'active-session');
        $this->userSession($inactive, 'inactive-session');
        $this->userSession($deleted, 'deleted-session');
        $revisions = User::withTrashed()->pluck('auth_revision', 'id');

        $result = app(BulkPasswordResetService::class)->reset('BulkExample123!');

        $this->assertSame(3, $result['users']);
        $this->assertSame(3, $result['sessions']);
        foreach (User::withTrashed()->get() as $account) {
            $this->assertTrue(Hash::check('BulkExample123!', $account->password));
            $this->assertFalse($account->must_change_password);
            $this->assertNotNull($account->password_changed_at);
            $this->assertGreaterThan($revisions[$account->id], $account->auth_revision);
        }
        $this->assertSame(0, UserSession::query()->whereNull('revoked_at')->count());
    }

    public function test_manager_can_manage_phone_and_password_for_users_in_other_centres(): void
    {
        $main = $this->mainCentre();
        $other = Centre::create(['name' => 'مرکز چهارم', 'code' => 'FOURTH', 'is_active' => true]);
        $manager = $this->user('manager', '09126667771', $main);
        $target = $this->user('counselor', '09126667772', $other);

        $this->actingAs($manager)->patch(route('users.phone', $target), [
            'phone' => '09126667773',
        ])->assertSessionHasNoErrors();
        $this->assertSame('09126667773', $target->fresh()->phone);

        $this->actingAs($manager)->patch(route('users.password', $target), [
            'password' => 'ManagerReset123!',
            'password_confirmation' => 'ManagerReset123!',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('ManagerReset123!', $target->fresh()->password));
    }

    public function test_non_manager_roles_cannot_manage_credentials_outside_their_scope(): void
    {
        $main = $this->mainCentre();
        $other = Centre::create(['name' => 'مرکز پنجم', 'code' => 'FIFTH', 'is_active' => true]);
        $secretary = $this->user('secretary', '09127778881', $main);
        $target = $this->user('counselor', '09127778882', $other);

        $this->actingAs($secretary)->patch(route('users.phone', $target), [
            'phone' => '09127778883',
        ])->assertForbidden();
        $this->actingAs($secretary)->patch(route('users.password', $target), [
            'password' => 'Forbidden123!',
            'password_confirmation' => 'Forbidden123!',
        ])->assertForbidden();
        $this->assertSame('09127778882', $target->fresh()->phone);
        $this->assertFalse(Hash::check('Forbidden123!', $target->fresh()->password));
    }

    private function credentialAdministrator(Centre $centre): User
    {
        $this->user('secretary', '09130134984', $centre, 'مدیر اعتبارنامه');

        return app(CredentialAdministratorProvisioner::class)->provision('09130134984');
    }

    private function mainCentre(): Centre
    {
        return Centre::where('code', 'ENSHA-MAIN')->firstOrFail();
    }

    private function user(string $roleSlug, string $phone, Centre $centre, string $name = 'کاربر آزمایشی'): User
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        [$firstName, $lastName] = array_pad(explode(' ', $name, 2), 2, 'نمونه');
        $user = User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $name,
            'phone' => $phone,
            'password' => 'Original123!',
            'role' => $roleSlug,
            'role_id' => $role->id,
            'centre_id' => $role->scope === 'global' ? null : $centre->id,
            'is_active' => true,
            'status' => 'active',
            'must_change_password' => false,
            'auth_revision' => 1,
        ]);
        $user->roleAssignments()->create([
            'role_id' => $role->id,
            'centre_id' => $role->scope === 'global' ? null : $centre->id,
        ]);

        return $user;
    }

    private function userSession(User $user, string $seed): UserSession
    {
        return UserSession::create([
            'user_id' => $user->id,
            'session_hash' => hash('sha256', $seed),
            'device_name' => 'Chrome · Windows',
            'last_activity_at' => now(),
        ]);
    }
}
