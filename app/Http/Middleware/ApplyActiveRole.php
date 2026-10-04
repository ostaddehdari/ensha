<?php

namespace App\Http\Middleware;

use App\Models\UserRoleCentre;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyActiveRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $request->hasSession()) {
            return $next($request);
        }

        $assignmentId = $request->session()->get('active_assignment_id');
        $assignment = UserRoleCentre::query()->with(['role', 'centre', 'branch'])
            ->where('user_id', $user->id)
            ->when(
                $assignmentId,
                fn ($query) => $query->whereKey($assignmentId),
                fn ($query) => $query->where('role_id', $user->role_id)->where('centre_id', $user->centre_id),
            )->first();

        $invalid = ! $assignment
            || ! $assignment->role?->is_active
            || ($assignment->centre_id && ! $assignment->centre?->is_active)
            || ($assignment->branch_id && ! $assignment->branch?->is_active);

        if ($invalid) {
            $request->session()->forget('active_assignment_id');
            if ($request->routeIs('impersonation.stop', 'logout')) {
                return $next($request);
            }
            if (! $request->routeIs('account.roles', 'account.roles.select')) {
                return redirect()->route('account.roles')->with('warning', 'دسترسی نقش یا شعبه قبلی لغو شده است؛ نقش دیگری انتخاب کنید.');
            }

            return $next($request);
        }

        foreach (['role_id' => $assignment->role_id, 'role' => $assignment->role->slug, 'centre_id' => $assignment->centre_id, 'branch_id' => $assignment->branch_id] as $key => $value) {
            $user->setAttribute($key, $value);
            $user->syncOriginalAttribute($key);
        }
        $user->setRelation('assignedRole', $assignment->role);
        $user->setRelation('activeAssignment', $assignment);

        return $next($request);
    }
}
