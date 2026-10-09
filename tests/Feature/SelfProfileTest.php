<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\ProfileField;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelfProfileTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $phone, string $nationalId): User
    {
        $record = Role::where('slug', $role)->firstOrFail();

        $user = User::create([
            'first_name' => 'سارا', 'last_name' => 'آزمایشی', 'name' => 'سارا آزمایشی',
            'phone' => $phone, 'national_id' => $nationalId, 'password' => 'StrongPass123!',
            'role' => $role, 'role_id' => $record->id,
            'centre_id' => $record->scope === 'global' ? null : Centre::where('code', 'ENSHA-MAIN')->value('id'),
            'is_active' => true, 'status' => 'active', 'must_change_password' => false,
        ]);

        $user->roleAssignments()->create([
            'role_id' => $record->id,
            'centre_id' => $record->scope === 'global' ? null : $user->centre_id,
        ]);

        return $user;
    }

    public function test_user_edits_common_and_role_fields_but_not_identity_or_role(): void
    {
        $client = $this->user('client', '09128887771', '1234567891');
        $otherRole = Role::where('slug', 'manager')->firstOrFail();
        $common = ProfileField::create(['role' => 'all', 'label' => 'شغل', 'key' => 'occupation', 'field_type' => 'text', 'is_active' => true]);
        $specific = ProfileField::create(['role' => 'client', 'label' => 'آشنایی', 'key' => 'source', 'field_type' => 'select', 'options' => [['label' => 'وب‌سایت', 'value' => 'web'], ['label' => 'دوستان', 'value' => 'friend']], 'is_active' => true]);
        $this->actingAs($client)->get(route('profile.show'))->assertOk()->assertSee('کد ملی · شناسه اصلی پرونده')->assertSee('شغل');

        $this->actingAs($client)->put(route('profile.update'), [
            'phone' => $client->phone,
            'first_name' => 'نام غیرمجاز', 'last_name' => 'خانوادگی غیرمجاز',
            'national_id' => '2345678909', 'role_id' => $otherRole->id,
            'profile' => [$common->id => 'معلم', $specific->id => 'web'],
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.show'));

        $client->refresh();
        $this->assertSame('سارا', $client->first_name);
        $this->assertSame('آزمایشی', $client->last_name);
        $this->assertSame('1234567891', $client->national_id);
        $this->assertSame('client', $client->role);
        $this->assertDatabaseHas('profile_values', ['user_id' => $client->id, 'profile_field_id' => $specific->id, 'value' => 'web']);
    }

    public function test_changing_login_phone_requires_current_password_and_revokes_other_sessions(): void
    {
        $client = $this->user('client', '09128887772', '2345678909');
        $session = UserSession::create([
            'user_id' => $client->id, 'session_hash' => hash('sha256', 'other-session'),
            'last_activity_at' => now(),
        ]);
        $revision = $client->auth_revision;
        $this->actingAs($client)->put(route('profile.update'), ['phone' => '09128887773'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame('09128887772', $client->fresh()->phone);

        $this->actingAs($client)->put(route('profile.update'), [
            'phone' => '09128887773', 'current_password' => 'StrongPass123!',
        ])->assertSessionHasNoErrors();
        $this->assertSame('09128887773', $client->fresh()->phone);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertGreaterThan($revision, $client->fresh()->auth_revision);
    }

    public function test_admin_looks_up_person_by_exact_national_id(): void
    {
        $admin = $this->user('super_admin', '09128887774', '3456789017');
        $matching = $this->user('client', '09128887775', '4567890124');
        $other = $this->user('client', '09128887776', '5678901230');

        $this->actingAs($admin)->get(route('users.index', ['q' => '۴۵۶۷۸۹۰۱۲۴']))
            ->assertOk()->assertSee($matching->national_id)->assertDontSee($other->national_id);
    }
}
