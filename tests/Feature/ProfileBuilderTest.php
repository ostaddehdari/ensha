<?php
namespace Tests\Feature;
use App\Models\Centre;
use App\Models\ProfileField;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class ProfileBuilderTest extends TestCase {
 use RefreshDatabase;
 private function account(string $role, string $phone): User {
  $r=Role::where('slug',$role)->firstOrFail();
  $centreId=$r->scope==='global'?null:Centre::where('code','ENSHA-MAIN')->value('id');
  $user=User::create(['first_name'=>'کاربر','last_name'=>'نمونه','name'=>'کاربر نمونه','phone'=>$phone,'national_id'=>null,'password'=>'StrongPass123!','role'=>$role,'role_id'=>$r->id,'centre_id'=>$centreId,'is_active'=>true,'status'=>'active']);
  $user->roleAssignments()->create(['role_id'=>$r->id,'centre_id'=>$centreId]);
  return $user;
 }
 public function test_builder_requires_permission_and_reorder_cannot_move_another_role(): void {
  $admin=$this->account('super_admin','09128880001'); $secretary=$this->account('secretary','09128880002');
  $this->actingAs($secretary)->get(route('profile-fields.index'))->assertForbidden();
  $all=ProfileField::create(['role'=>'all','label'=>'مشترک','key'=>'extra','field_type'=>'text','sort_order'=>10,'is_active'=>true]);
  $client=ProfileField::create(['role'=>'client','label'=>'اختصاصی','key'=>'extra','field_type'=>'text','sort_order'=>10,'is_active'=>true]);
  $this->actingAs($admin)->putJson(route('profile-fields.reorder'),['role'=>'all','ids'=>[$client->id]])->assertUnprocessable();
  $this->actingAs($admin)->putJson(route('profile-fields.reorder'),['role'=>'all','ids'=>[$all->id]])->assertOk();
 }
 public function test_manager_sees_common_and_role_fields_when_creating_client(): void {
  $admin=$this->account('super_admin','09128880003');
  $common=ProfileField::create(['role'=>'all','label'=>'شغل','key'=>'occupation','field_type'=>'text','is_required'=>true,'is_active'=>true]);
  $client=ProfileField::create(['role'=>'client','label'=>'ارجاع','key'=>'referral','field_type'=>'select','options'=>['آشنا','وب'],'is_active'=>true]);
  $role=Role::where('slug','client')->firstOrFail();
  $data=['first_name'=>'مراجع','last_name'=>'جدید','phone'=>'09128880004','national_id'=>'2345678909','password'=>'StrongPass123!','password_confirmation'=>'StrongPass123!','role_id'=>$role->id,'centre_id'=>Centre::where('code','ENSHA-MAIN')->value('id'),'profile'=>[$common->id=>'پزشک',$client->id=>'وب']];
  $this->actingAs($admin)->post(route('users.store'),$data)->assertSessionHasNoErrors();
  $new=User::where('phone','09128880004')->firstOrFail();
  $this->assertDatabaseHas('profile_values',['user_id'=>$new->id,'profile_field_id'=>$common->id,'value'=>'پزشک']);
  $this->actingAs($admin)->post(route('users.store'),array_merge($data,['phone'=>'09128880005','national_id'=>'3456789017','profile'=>[$client->id=>'نامعتبر']]))->assertSessionHasErrors(['profile.'.$common->id,'profile.'.$client->id]);
 }
 public function test_deletion_retains_fields_with_answers(): void {
  $admin=$this->account('super_admin','09128880006'); $user=$this->account('client','09128880007');
  $field=ProfileField::create(['role'=>'all','label'=>'شغل','key'=>'occupation','field_type'=>'text','is_active'=>true]);
  $user->profileValues()->create(['profile_field_id'=>$field->id,'value'=>'پزشک']);
  $this->actingAs($admin)->delete(route('profile-fields.destroy',$field))->assertRedirect();
  $this->assertDatabaseHas('profile_fields',['id'=>$field->id,'is_active'=>false]);
  $this->assertDatabaseHas('profile_values',['user_id'=>$user->id,'profile_field_id'=>$field->id,'value'=>'پزشک']);
 }
}
