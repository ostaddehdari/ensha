<?php
namespace Tests\Feature;
use App\Models\ProfileField;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class ProfileDraftTest extends TestCase {
 use RefreshDatabase;
 private function admin(): User {
  $role=Role::where('slug','super_admin')->firstOrFail();
  return User::create(['first_name'=>'ادمین','last_name'=>'آزمایشی','name'=>'ادمین آزمایشی','phone'=>'09127778888','national_id'=>'1234567891','password'=>'StrongPass123!','role'=>'super_admin','role_id'=>$role->id,'is_active'=>true,'status'=>'active']);
 }
 public function test_single_save_persists_all_tabs_and_option_labels_with_values(): void {
  $admin=$this->admin();
  $response=$this->actingAs($admin)->get(route('profile-fields.index'));
  $response->assertOk();
  $forms=$response->viewData('formsPayload');
  $forms['all']['fields'][]=['id'=>null,'label'=>'منبع آشنایی','key'=>'source','field_type'=>'select','is_required'=>false,'is_active'=>true,'options'=>[['label'=>'از طریق سایت','value'=>'web'],['label'=>'معرفی دوستان','value'=>'friend']],'settings'=>['width'=>'full']];
  $forms['client']['fields'][]=['id'=>null,'label'=>'اطلاعات مراجع','key'=>'client_info','field_type'=>'text','is_required'=>false,'is_active'=>true,'options'=>[],'settings'=>[]];
  $this->actingAs($admin)->putJson(route('profile-fields.save-all'),['forms'=>$forms])->assertOk()->assertJsonPath('ok',true);
  $field=ProfileField::where('role','all')->where('key','source')->firstOrFail();
  $this->assertSame('web',$field->options[0]['value']);
  $this->assertSame('از طریق سایت',$field->options[0]['label']);
  $this->assertDatabaseHas('profile_fields',['role'=>'client','key'=>'client_info']);
 }
 public function test_invalid_option_rolls_back_changes_in_every_tab(): void {
  $admin=$this->admin();$forms=$this->actingAs($admin)->get(route('profile-fields.index'))->viewData('formsPayload');
  $forms['all']['fields'][]=['id'=>null,'label'=>'عنوان','key'=>'new_title','field_type'=>'text','is_required'=>false,'is_active'=>true,'options'=>[],'settings'=>[]];
  $forms['client']['fields'][]=['id'=>null,'label'=>'انتخاب','key'=>'pick','field_type'=>'radio','is_required'=>false,'is_active'=>true,'options'=>[['label'=>'اول','value'=>'duplicate'],['label'=>'دوم','value'=>'duplicate']],'settings'=>[]];
  $this->actingAs($admin)->putJson(route('profile-fields.save-all'),['forms'=>$forms])->assertUnprocessable();
  $this->assertDatabaseMissing('profile_fields',['role'=>'all','key'=>'new_title']);
 }
 public function test_stale_draft_is_rejected(): void {
  $admin=$this->admin();$forms=$this->actingAs($admin)->get(route('profile-fields.index'))->viewData('formsPayload');
  ProfileField::create(['role'=>'all','label'=>'تغییر هم‌زمان','key'=>'concurrent','field_type'=>'text','is_active'=>true]);
  $this->actingAs($admin)->putJson(route('profile-fields.save-all'),['forms'=>$forms])->assertUnprocessable();
  $this->assertDatabaseHas('profile_fields',['role'=>'all','key'=>'concurrent']);
 }
}
