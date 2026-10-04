<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\CentreBranch;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $this->check($request);
        $actor = $request->user();
        $centreId = $actor->isSuperAdmin() ? $request->integer('centre_id') : (int) $actor->centre_id;

        $query = User::query()->whereHas('roleAssignments', function (Builder $assignment) use ($centreId) {
            $assignment->whereNotNull('centre_id')
                ->when($centreId, fn (Builder $scope) => $scope->where('centre_id', $centreId))
                ->whereHas('role', fn (Builder $role) => $role->where('slug', '!=', 'client'));
        });

        if ($request->filled('q')) {
            $search = addcslashes(trim((string) $request->input('q')), '%_\\');
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('national_id', 'like', "%{$search}%")
                    ->orWhereHas('staffProfile', fn (Builder $profile) => $profile
                        ->where('employee_code', 'like', "%{$search}%")
                        ->orWhere('job_title', 'like', "%{$search}%"));
            });
        }

        $staff = $query->with([
            'staffProfile',
            'roleAssignments' => fn ($assignment) => $assignment
                ->when($centreId, fn ($scope) => $scope->where('centre_id', $centreId))
                ->with(['role', 'centre', 'branch']),
        ])->orderBy('last_name')->orderBy('first_name')->paginate(20)->withQueryString();

        $centres = $actor->isSuperAdmin()
            ? Centre::query()->where('is_active', true)->orderBy('name')->get()
            : collect();

        return view('staff.index', compact('staff', 'centres', 'centreId'));
    }

    public function edit(Request $request, User $user)
    {
        $this->check($request, true);
        $assignments = $this->staffAssignments($request, $user)->get();
        $user->load('staffProfile');
        $branches = CentreBranch::query()
            ->whereIn('centre_id', $assignments->pluck('centre_id')->filter()->unique())
            ->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get()->groupBy('centre_id');

        return view('staff.edit', compact('user', 'assignments', 'branches'));
    }

    public function update(Request $request, User $user)
    {
        $this->check($request, true);
        $assignments = $this->staffAssignments($request, $user)->get()->keyBy('id');
        $profileId = $user->staffProfile?->id;
        $data = $request->validate([
            'employee_code' => ['nullable', 'string', 'max:60', Rule::unique('staff_profiles', 'employee_code')->ignore($profileId)],
            'job_title' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'employment_type' => ['required', Rule::in(['contract', 'employee', 'part_time', 'volunteer'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'work_email' => ['nullable', 'email', 'max:190'],
            'extension' => ['nullable', 'string', 'max:20'],
            'license_number' => ['nullable', 'string', 'max:80'],
            'specialty' => ['nullable', 'string', 'max:180'],
            'internal_notes' => ['nullable', 'string', 'max:3000'],
            'assignment_branches' => ['nullable', 'array'],
            'assignment_branches.*' => ['nullable', 'integer', 'exists:centre_branches,id'],
        ]);

        foreach (($data['assignment_branches'] ?? []) as $assignmentId => $branchId) {
            $assignment = $assignments->get((int) $assignmentId);
            if (! $assignment) {
                throw ValidationException::withMessages(['assignment_branches' => 'یکی از انتساب‌های نقش خارج از دامنه مجاز است.']);
            }
            if ($branchId && ! CentreBranch::query()->whereKey($branchId)->where('centre_id', $assignment->centre_id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['assignment_branches' => 'شعبه انتخاب‌شده با مرکز نقش تطابق ندارد.']);
            }
        }

        $profileData = Arr::except($data, ['assignment_branches']);
        DB::transaction(function () use ($user, $profileData, $data, $assignments) {
            $user->staffProfile()->updateOrCreate(['user_id' => $user->id], $profileData);
            foreach (($data['assignment_branches'] ?? []) as $assignmentId => $branchId) {
                $assignments->get((int) $assignmentId)?->update(['branch_id' => $branchId ?: null]);
            }
        });

        Audit::record('ویرایش پرونده همکاری کارکنان', $request, 'info', [
            'user_id' => $user->id,
            'assignment_ids' => $assignments->keys()->all(),
        ], $user, 'staff.updated');

        return redirect()->route('staff.index')->with('success', 'اطلاعات همکاری و شعب کارکنان ثبت شد.');
    }

    private function staffAssignments(Request $request, User $user)
    {
        $actor = $request->user();
        $query = $user->roleAssignments()
            ->whereNotNull('centre_id')
            ->whereHas('role', fn (Builder $role) => $role->where('slug', '!=', 'client'))
            ->when(! $actor->isSuperAdmin(), fn ($scope) => $scope->where('centre_id', $actor->centre_id))
            ->with(['role', 'centre', 'branch']);
        abort_unless((clone $query)->exists(), 404);

        return $query;
    }

    private function check(Request $request, bool $manage = false): void
    {
        abort_unless($request->user()->hasPermission($manage ? 'staff.manage' : 'staff.view'), 403);
    }
}
