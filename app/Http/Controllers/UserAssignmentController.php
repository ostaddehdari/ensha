<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\CentreBranch;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleCentre;
use App\Support\Audit;
use App\Support\SessionRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserAssignmentController extends Controller
{
    public function edit(Request $request, User $user)
    {
        $this->authorize('update', $user);
        $actor = $request->user();
        $centres = $actor->isSuperAdmin()
            ? Centre::query()->where('is_active', true)->orderBy('name')->get()
            : Centre::query()->whereKey($actor->centre_id)->get();
        $branches = CentreBranch::query()->whereIn('centre_id', $centres->pluck('id'))
            ->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();

        return view('users.assignments', [
            'user' => $user,
            'assignments' => $user->roleAssignments()->with(['role', 'centre', 'branch'])->get(),
            'roles' => Role::active()->assignableBy($actor)->orderBy('sort_order')->get(),
            'centres' => $centres,
            'branches' => $branches,
        ]);
    }

    public function store(Request $request, User $user)
    {
        $this->authorize('update', $user);
        $data = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
            'centre_id' => ['nullable', 'exists:centres,id'],
            'branch_id' => ['nullable', 'exists:centre_branches,id'],
        ]);
        $actor = $request->user();
        $role = Role::active()->findOrFail($data['role_id']);
        abort_unless(Role::active()->assignableBy($actor)->whereKey($role->id)->exists(), 403);
        $centreId = $role->scope === 'global' ? null : ($actor->isSuperAdmin() ? ($data['centre_id'] ?? null) : $actor->centre_id);
        if ($role->scope !== 'global') {
            abort_unless(Centre::query()->whereKey($centreId)->where('is_active', true)->exists(), 422);
        }
        $branchId = null;
        if ($centreId) {
            $branchId = $data['branch_id'] ?? CentreBranch::query()->where('centre_id', $centreId)->where('is_default', true)->value('id');
            abort_unless(! $branchId || CentreBranch::query()->whereKey($branchId)->where('centre_id', $centreId)->where('is_active', true)->exists(), 422);
        }
        abort_if($user->roleAssignments()->where('role_id', $role->id)->where('centre_id', $centreId)->exists(), 422, 'این نقش برای این مرکز قبلاً ثبت شده است.');

        $assignment = $user->roleAssignments()->create(['role_id' => $role->id, 'centre_id' => $centreId, 'branch_id' => $branchId]);
        SessionRegistry::invalidateAll($user, 'role_added');
        Audit::record('افزودن نقش کاربر', $request, 'warning', [
            'target_user_id' => $user->id, 'role_id' => $role->id, 'centre_id' => $centreId, 'branch_id' => $branchId,
        ], $user, 'user.assignment.created');

        return back()->with('success', 'نقش و شعبه اضافه شد.');
    }

    public function destroy(Request $request, User $user, UserRoleCentre $assignment)
    {
        $this->authorize('update', $user);
        abort_unless($assignment->user_id === $user->id, 404);
        if ($user->roleAssignments()->count() <= 1 || ($user->role_id === $assignment->role_id && $user->centre_id === $assignment->centre_id)) {
            throw ValidationException::withMessages(['role_id' => 'نقش پیش‌فرض را ابتدا از صفحه ویرایش کاربر تغییر دهید؛ حذف آخرین نقش مجاز نیست.']);
        }
        $snapshot = $assignment->only(['role_id', 'centre_id', 'branch_id']);
        $assignment->delete();
        SessionRegistry::invalidateAll($user, 'role_removed');
        Audit::record('حذف نقش کاربر', $request, 'warning', ['target_user_id' => $user->id, ...$snapshot], $user, 'user.assignment.deleted');

        return back()->with('success', 'نقش حذف شد.');
    }
}
