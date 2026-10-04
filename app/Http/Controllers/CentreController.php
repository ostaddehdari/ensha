<?php
namespace App\Http\Controllers;
use App\Models\Centre;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class CentreController extends Controller {
 private function check(Request $request, bool $manage = false): void { abort_unless($request->user()->hasPermission($manage ? 'centres.manage' : 'centres.view'), 403); }
 public function index(Request $request) {
  $this->check($request); $actor=$request->user();
  $centres=Centre::query()->when(!$actor->isSuperAdmin(), fn($q)=>$q->whereKey($actor->centre_id))->withCount(['users','users as staff_count'=>fn($q)=>$q->where('role','!=','client')])->with(['users' => fn($q) => $q->whereHas('roleAssignments',fn($a)=>$a->whereHas('role',fn($role)=>$role->where('slug','manager')))->where('is_active',true)->where('status','active')->with(['assignedRole','roleAssignments.role'])])->orderBy('name')->paginate(20);
  return view('centres.index', compact('centres'));
 }
 public function show(Request $request, Centre $centre) {
  $this->check($request); abort_unless($request->user()->isSuperAdmin() || $request->user()->centre_id === $centre->id,403);
  $centre->loadCount('users'); $staff=$centre->users()->where('role','!=','client')->with('assignedRole')->orderBy('last_name')->paginate(15);
  return view('centres.show', compact('centre','staff'));
 }
 public function create(Request $request) { $this->check($request,true); abort_unless($request->user()->isSuperAdmin(),403); return view('centres.form',['centre'=>new Centre]); }
 public function edit(Request $request, Centre $centre) { $this->check($request,true); abort_unless($request->user()->isSuperAdmin() || $request->user()->centre_id === $centre->id,403); return view('centres.form',compact('centre')); }
 public function store(Request $request) { $this->check($request,true); abort_unless($request->user()->isSuperAdmin(),403); $data=$this->validated($request); $centre=Centre::create($data); Audit::record('ایجاد مرکز',$request,'info',['centre_id'=>$centre->id],$centre,'centre.created'); return redirect()->route('centres.show',$centre)->with('success','مرکز ثبت شد.'); }
 public function update(Request $request, Centre $centre) {
  $this->check($request,true); $actor=$request->user(); abort_unless($actor->isSuperAdmin() || $actor->centre_id === $centre->id,403);
  $data=$this->validated($request,$centre); if (!$actor->isSuperAdmin()) { unset($data['code'],$data['is_active']); }
  if (array_key_exists('is_active',$data) && !$data['is_active'] && $centre->is_active) {
   DB::transaction(function() use ($centre,$data) { $centre=Centre::query()->lockForUpdate()->findOrFail($centre->id); if ($centre->users()->where('is_active',true)->where('status','active')->exists()) throw ValidationException::withMessages(['is_active'=>'برای غیرفعال کردن مرکز، ابتدا حساب‌های فعال آن را انتقال یا غیرفعال کنید.']); $centre->update($data); });
  } else $centre->update($data);
  Audit::record('ویرایش مرکز',$request,'info',['centre_id'=>$centre->id],$centre,'centre.updated'); return redirect()->route('centres.show',$centre)->with('success','مرکز ویرایش شد.');
 }
 private function validated(Request $request, ?Centre $centre=null): array {
  $data=$request->validate(['name'=>['required','string','max:160'],'code'=>['required','string','max:50','regex:/^[A-Za-z0-9_-]+$/',Rule::unique('centres','code')->ignore($centre?->id)],'phone'=>['nullable','string','max:20'],'email'=>['nullable','email','max:190'],'address'=>['nullable','string','max:500'],'description'=>['nullable','string','max:3000'],'is_active'=>['sometimes','boolean']]);
  $data['is_active']=$request->boolean('is_active'); return $data;
 }
}
