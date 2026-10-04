<?php
namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\UserRoleCentre;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyActiveRole {
 public function handle(Request $request, Closure $next): Response {
  $user=$request->user();
  if ($user && $request->hasSession()) {
   $id=$request->session()->get('active_assignment_id');
   if ($id) {
    $assignment=UserRoleCentre::query()->with('role')->where('user_id',$user->id)->find($id);
    if (!$assignment || !$assignment->role?->is_active || ($assignment->centre_id && ! $assignment->centre?->is_active)) {
     $request->session()->forget('active_assignment_id');
     if ($request->routeIs('impersonation.stop','logout')) return $next($request);
     return redirect()->route('account.roles')->with('warning','دسترسی نقش قبلی لغو شده است؛ نقش دیگری انتخاب کنید.');
    }
    $user->setAttribute('role_id',$assignment->role_id);
    $user->setAttribute('role',$assignment->role->slug);
    $user->setAttribute('centre_id',$assignment->centre_id);
    foreach (['role_id','role','centre_id'] as $key) $user->syncOriginalAttribute($key);
    $user->setRelation('assignedRole',$assignment->role);
   } else {
    $primary=UserRoleCentre::where('user_id',$user->id)->where('role_id',$user->role_id)->where('centre_id',$user->centre_id)->exists();
    if ((!$primary || !$user->assignedRole?->is_active) && ! $request->routeIs('account.roles','account.roles.select','logout','impersonation.stop')) return redirect()->route('account.roles')->with('warning','لطفاً نقش فعال خود را انتخاب کنید.');
   }
  }
  return $next($request);
 }
}
