<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\CentreBranch;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CentreBranchController extends Controller
{
    public function index(Request $request, Centre $centre)
    {
        $this->authorizeCentre($request, $centre, 'branches.view');
        $branches = $centre->branches()->withCount('assignments')->orderByDesc('is_default')->orderBy('name')->get();

        return view('centres.branches', compact('centre', 'branches'));
    }

    public function store(Request $request, Centre $centre)
    {
        $this->authorizeCentre($request, $centre, 'branches.manage');
        $data = $this->validated($request, $centre);
        if ((! $centre->branches()->exists() || ($data['is_default'] ?? false)) && ! ($data['is_active'] ?? false)) {
            throw ValidationException::withMessages(['is_active' => 'شعبه پیش‌فرض باید فعال باشد.']);
        }
        $branch = DB::transaction(function () use ($centre, $data) {
            $makeDefault = (bool) ($data['is_default'] ?? false) || ! $centre->branches()->exists();
            if ($makeDefault) {
                $centre->branches()->update(['is_default' => false]);
            }
            $data['is_default'] = $makeDefault;

            return $centre->branches()->create($data);
        });
        Audit::record('ایجاد شعبه مرکز', $request, 'info', ['centre_id' => $centre->id, 'branch_id' => $branch->id], $branch, 'branch.created');

        return back()->with('success', 'شعبه ثبت شد.');
    }

    public function update(Request $request, Centre $centre, CentreBranch $branch)
    {
        $this->authorizeCentre($request, $centre, 'branches.manage');
        abort_unless($branch->centre_id === $centre->id, 404);
        $data = $this->validated($request, $centre, $branch);
        if (($branch->is_default || ($data['is_default'] ?? false)) && ! ($data['is_active'] ?? false)) {
            throw ValidationException::withMessages(['is_active' => 'شعبه پیش‌فرض را نمی‌توان غیرفعال کرد.']);
        }
        DB::transaction(function () use ($centre, $branch, $data) {
            if ($data['is_default'] ?? false) {
                $centre->branches()->where('id', '!=', $branch->id)->update(['is_default' => false]);
            } elseif ($branch->is_default) {
                $data['is_default'] = true;
            }
            $branch->update($data);
        });
        Audit::record('ویرایش شعبه مرکز', $request, 'info', ['centre_id' => $centre->id, 'branch_id' => $branch->id], $branch, 'branch.updated');

        return back()->with('success', 'شعبه به‌روزرسانی شد.');
    }

    private function validated(Request $request, Centre $centre, ?CentreBranch $branch = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('centre_branches', 'code')->where('centre_id', $centre->id)->ignore($branch?->id)],
            'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['code'] = strtoupper($data['code']);
        $data['is_default'] = $request->boolean('is_default');
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function authorizeCentre(Request $request, Centre $centre, string $permission): void
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission($permission), 403);
        abort_unless($actor->isSuperAdmin() || $actor->centre_id === $centre->id, 403);
    }
}
