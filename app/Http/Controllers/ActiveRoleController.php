<?php
namespace App\Http\Controllers;
use App\Models\UserRoleCentre;
use App\Support\Audit;
use Illuminate\Http\Request;
class ActiveRoleController extends Controller {
 public function index(Request $request) {
  $assignments=UserRoleCentre::with(['role','centre'])->where('user_id',$request->user()->id)->get()->filter(fn($a)=>$a->role?->is_active && (!$a->centre_id || $a->centre?->is_active));
  return view('account.roles',compact('assignments'));
 }
 public function select(Request $request,UserRoleCentre $assignment) {
  abort_unless($assignment->user_id===$request->user()->id && $assignment->role?->is_active && (!$assignment->centre_id || $assignment->centre?->is_active),403);
  $request->session()->put('active_assignment_id',$assignment->id);
  $request->session()->regenerate();
  Audit::record('تغییر نقش فعال',$request,'info',['assignment_id'=>$assignment->id,'role_id'=>$assignment->role_id,'centre_id'=>$assignment->centre_id]);
  return redirect()->route('dashboard');
 }
}
