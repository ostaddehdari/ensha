<?php
namespace App\Http\Controllers;
use App\Models\Centre;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleCentre;
use App\Support\Audit;
use App\Support\SessionRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class UserAssignmentController extends Controller {
 public function edit(Request $r, User $user) {
  $this->authorize('update',$user);
  return view('users.assignments',['user'=>$user,'assignments'=>$user->roleAssignments()->with(['role','centre'])->get(), 'roles'=>Role::active()->assignableBy($r->user())->orderBy('sort_order')->get(),'centres'=>$r->user()->isSuperAdmin()?Centre::where('is_active',true)->orderBy('name')->get():Centre::whereKey($r->user()->centre_id)->get()]);
 }
 public function store(Request $r,User $user) {
  $this->authorize('update',$user);
  $data=$r->validate(['role_id'=>['required','exists:roles,id'],'centre_id'=>['nullable','exists:centres,id']]);
  $role=Role::active()->findOrFail($data['role_id']);
  abort_unless(Role::active()->assignableBy($r->user())->whereKey($role->id)->exists(),403);
  $centreId=$role->scope==='global'?null:($r->user()->isSuperAdmin()?($data['centre_id']??null):$r->user()->centre_id);
  if($role->scope!=='global') abort_unless(Centre::whereKey($centreId)->where('is_active',true)->exists(),422);
  abort_if($user->roleAssignments()->where('role_id',$role->id)->where('centre_id',$centreId)->exists(),422,'این نقش برای این مرکز قبلاً ثبت شده است.');
  $user->roleAssignments()->create(['role_id'=>$role->id,'centre_id'=>$centreId]);
  SessionRegistry::invalidateAll($user,'role_added');
  Audit::record('افزودن نقش کاربر',$r,'warning',['target_user_id'=>$user->id,'role_id'=>$role->id,'centre_id'=>$centreId],$user);
  return back()->with('success','نقش اضافه شد.');
 }
 public function destroy(Request $r,User $user,UserRoleCentre $assignment) {
  $this->authorize('update',$user);
  abort_unless($assignment->user_id===$user->id,404);
  if($user->roleAssignments()->count()<=1 || ($user->role_id===$assignment->role_id && $user->centre_id===$assignment->centre_id)) throw ValidationException::withMessages(['role_id'=>'نقش پیش‌فرض را ابتدا از صفحه ویرایش کاربر تغییر دهید؛ حذف آخرین نقش مجاز نیست.']);
  $assignment->delete(); SessionRegistry::invalidateAll($user,'role_removed');
  Audit::record('حذف نقش کاربر',$r,'warning',['target_user_id'=>$user->id,'role_id'=>$assignment->role_id,'centre_id'=>$assignment->centre_id],$user);
  return back()->with('success','نقش حذف شد.');
 }
}
