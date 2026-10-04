<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CentreController extends Controller
{
    public function index(Request $request)
    {
        $this->check($request);
        $actor = $request->user();
        $centres = Centre::query()
            ->when(! $actor->isSuperAdmin(), fn ($query) => $query->whereKey($actor->centre_id))
            ->withCount(['users', 'branches', 'roleAssignments'])
            ->with(['roleAssignments' => fn ($assignment) => $assignment
                ->whereHas('role', fn ($role) => $role->where('slug', 'manager'))
                ->whereHas('user', fn ($user) => $user->where('is_active', true)->where('status', 'active'))
                ->with(['user', 'role', 'branch'])])
            ->orderBy('name')->paginate(20);

        return view('centres.index', compact('centres'));
    }

    public function show(Request $request, Centre $centre)
    {
        $this->check($request);
        $this->withinCentre($request, $centre);
        $centre->loadCount(['users', 'branches', 'roleAssignments']);
        $staff = User::query()
            ->whereHas('roleAssignments', fn (Builder $assignment) => $assignment
                ->where('centre_id', $centre->id)
                ->whereHas('role', fn (Builder $role) => $role->where('slug', '!=', 'client')))
            ->with(['staffProfile', 'roleAssignments' => fn ($assignment) => $assignment
                ->where('centre_id', $centre->id)->with(['role', 'branch'])])
            ->orderBy('last_name')->orderBy('first_name')->paginate(15);

        return view('centres.show', compact('centre', 'staff'));
    }

    public function create(Request $request)
    {
        $this->check($request, true);
        abort_unless($request->user()->isSuperAdmin(), 403);

        return view('centres.form', ['centre' => new Centre]);
    }

    public function edit(Request $request, Centre $centre)
    {
        $this->check($request, true);
        $this->withinCentre($request, $centre);

        return view('centres.form', compact('centre'));
    }

    public function store(Request $request)
    {
        $this->check($request, true);
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $this->validated($request);
        $centre = DB::transaction(function () use ($data) {
            $centre = Centre::create($data);
            $centre->branches()->create([
                'name' => 'شعبه اصلی', 'code' => 'MAIN', 'timezone' => $data['timezone'] ?? 'Asia/Tehran',
                'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'address' => $data['address'] ?? null,
                'is_default' => true, 'is_active' => true,
            ]);

            return $centre;
        });
        Audit::record('ایجاد مرکز', $request, 'info', ['centre_id' => $centre->id], $centre, 'centre.created');

        return redirect()->route('centres.show', $centre)->with('success', 'مرکز و شعبه اصلی ثبت شد.');
    }

    public function update(Request $request, Centre $centre)
    {
        $this->check($request, true);
        $actor = $request->user();
        $this->withinCentre($request, $centre);
        $data = $this->validated($request, $centre);
        if (! $actor->isSuperAdmin()) {
            unset($data['code'], $data['is_active']);
        }
        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $centre->is_active) {
            DB::transaction(function () use ($centre, $data) {
                $locked = Centre::query()->lockForUpdate()->findOrFail($centre->id);
                if ($locked->roleAssignments()->whereHas('user', fn ($user) => $user->where('is_active', true)->where('status', 'active'))->exists()) {
                    throw ValidationException::withMessages(['is_active' => 'برای غیرفعال کردن مرکز، ابتدا انتساب حساب‌های فعال آن را انتقال یا غیرفعال کنید.']);
                }
                $locked->update($data);
            });
        } else {
            $centre->update($data);
        }
        Audit::record('ویرایش مرکز', $request, 'info', ['centre_id' => $centre->id], $centre, 'centre.updated');

        return redirect()->route('centres.show', $centre)->with('success', 'مرکز ویرایش شد.');
    }

    private function validated(Request $request, ?Centre $centre = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('centres', 'code')->ignore($centre?->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:3000'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['code'] = strtoupper($data['code']);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function withinCentre(Request $request, Centre $centre): void
    {
        abort_unless($request->user()->isSuperAdmin() || $request->user()->centre_id === $centre->id, 403);
    }

    private function check(Request $request, bool $manage = false): void
    {
        abort_unless($request->user()->hasPermission($manage ? 'centres.manage' : 'centres.view'), 403);
    }
}
