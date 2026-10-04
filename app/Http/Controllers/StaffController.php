<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class StaffController extends Controller {
 private function check(Request $r, bool $manage=false): void { abort_unless($r->user()->hasPermission($manage?'counselors.manage':'counselors.view'),403); }
 private function staff(Request $r, User $user): User { abort_unless($user->roleAssignments()->whereHas('role',fn($role)=>$role->where('slug','counselor'))->when(!$r->user()->isSuperAdmin(),fn($q)=>$q->where('centre_id',$r->user()->centre_id))->exists(),404); return $user; }
 public function index(Request $r) { $this->check($r); $q=User::query()->whereHas('roleAssignments',fn($a)=>$a->whereHas('role',fn($role)=>$role->where('slug','counselor'))->when(!$r->user()->isSuperAdmin(),fn($x)=>$x->where('centre_id',$r->user()->centre_id))); if ($r->filled('q')) { $s=addcslashes(trim((string)$r->input('q')),'%_\\');$q->where(fn($x)=>$x->where('name','like',"%{$s}%")->orWhere('phone','like',"%{$s}%")); } $staff=$q->with(['centre','assignedRole','staffProfile'])->orderBy('last_name')->paginate(20)->withQueryString(); return view('staff.index',compact('staff')); }
 public function edit(Request $r, User $user) { $this->check($r,true); $this->staff($r,$user); $user->load(['centre','staffProfile']); return view('staff.edit',compact('user')); }
 public function update(Request $r, User $user) { $this->check($r,true); $this->staff($r,$user); $data=$r->validate(['job_title'=>['nullable','string','max:120'],'employment_type'=>['required',Rule::in(['contract','employee','part_time','volunteer'])],'start_date'=>['nullable','date'],'end_date'=>['nullable','date','after_or_equal:start_date'],'license_number'=>['nullable','string','max:80'],'specialty'=>['nullable','string','max:180'],'internal_notes'=>['nullable','string','max:3000']]); $user->staffProfile()->updateOrCreate(['user_id'=>$user->id],$data); Audit::record('ویرایش پرونده همکاری',$r,'info',['user_id'=>$user->id],$user,'staff.updated'); return redirect()->route('staff.index')->with('success','اطلاعات همکاری ثبت شد.'); }
}
