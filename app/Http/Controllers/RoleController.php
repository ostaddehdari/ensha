<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Role::class);
        $roles = Role::query()
            ->withCount(['users', 'permissions'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $permissionCount = Permission::active()->count();

        return view('roles.index', compact('roles', 'permissionCount'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Role::class);

        return view('roles.create', ['permissionGroups' => $this->permissionGroups($request->user())]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->guardPermissionAssignment($request->user(), $data['permission_ids'] ?? [], $data['scope']);
        $role = DB::transaction(function () use ($request, $data) {
            $role = Role::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'scope' => $data['scope'],
                'color' => $data['color'],
                'sort_order' => (Role::max('sort_order') ?? 0) + 10,
                'is_system' => false,
                'is_active' => $request->boolean('is_active', true),
            ]);
            $role->permissions()->sync($data['permission_ids'] ?? []);

            return $role;
        });

        Audit::record('ایجاد نقش سفارشی', $request, 'info', [
            'role_id' => $role->id,
            'slug' => $role->slug,
            'permission_count' => count($data['permission_ids'] ?? []),
        ], $role, 'role.created');

        return redirect()->route('roles.edit', $role)->with('success', 'نقش جدید و مجوزهای آن ایجاد شد.');
    }

    public function edit(Request $request, Role $role): View
    {
        $this->authorize('update', $role);
        $role->load('permissions');

        return view('roles.edit', [
            'role' => $role,
            'permissionGroups' => $this->permissionGroups($request->user()),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $data = $request->validated();
        $permissionIds = $data['permission_ids'] ?? [];
        $nextScope = ($role->is_system || $role->users()->exists()) ? $role->scope : $data['scope'];
        $this->guardPermissionAssignment($request->user(), $permissionIds, $nextScope);

        if ($role->slug === 'super_admin') {
            $permissionIds = Permission::active()->pluck('id')->all();
        }
        $nextActive = $role->slug === 'super_admin' ? true : $request->boolean('is_active');
        if (! $nextActive && $role->users()->exists()) {
            throw ValidationException::withMessages(['is_active' => 'تا زمانی که کاربری این نقش را دارد، امکان غیرفعال‌کردن آن وجود ندارد.']);
        }

        $beforePermissions = $role->permissions()->pluck('permissions.id')->all();
        $before = $role->only(['name', 'slug', 'description', 'scope', 'color', 'is_active']);

        DB::transaction(function () use ($role, $data, $permissionIds, $nextActive, $nextScope) {
            $role->update([
                'name' => $data['name'],
                'slug' => $role->slug,
                'description' => $data['description'] ?? null,
                'scope' => $nextScope,
                'color' => $data['color'],
                'is_active' => $nextActive,
            ]);
            $role->permissions()->sync($permissionIds);
        });

        Audit::record('ویرایش نقش و دسترسی‌ها', $request, 'warning', [
            'role_id' => $role->id,
            'before' => $before,
            'after' => $role->fresh()->only(array_keys($before)),
            'permissions_before' => $beforePermissions,
            'permissions_after' => array_map('intval', $permissionIds),
        ], $role, 'role.updated');

        return back()->with('success', 'نقش و ماتریس دسترسی‌ها به‌روزرسانی شد.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);
        $snapshot = $role->only(['id', 'name', 'slug']);
        Audit::record('حذف نقش سفارشی', $request, 'warning', $snapshot, $role, 'role.deleted');
        $role->delete();

        return redirect()->route('roles.index')->with('success', 'نقش سفارشی حذف شد.');
    }

    private function permissionGroups(User $actor)
    {
        $query = Permission::active();
        if (! $actor->isSuperAdmin()) {
            $query->whereIn('id', $actor->assignedRole?->permissions()->pluck('permissions.id') ?? []);
        }

        return $query
            ->orderBy('sort_order')
            ->get()
            ->groupBy('group_key');
    }

    private function guardPermissionAssignment(User $actor, array $permissionIds, string $scope): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if ($scope !== 'centre') {
            throw ValidationException::withMessages(['scope' => 'فقط ادمین سیستم می‌تواند نقش سراسری تعریف کند.']);
        }

        $allowedIds = $actor->assignedRole?->permissions()
            ->where('permissions.is_active', true)
            ->pluck('permissions.id')
            ->map(fn ($id) => (int) $id)
            ->all() ?? [];
        $requestedIds = array_map('intval', $permissionIds);

        if (array_diff($requestedIds, $allowedIds)) {
            throw ValidationException::withMessages([
                'permission_ids' => 'نمی‌توانید مجوزی فراتر از سطح دسترسی خودتان به نقش واگذار کنید.',
            ]);
        }
    }
}
