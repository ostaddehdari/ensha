<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Centre;
use App\Models\CentreBranch;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\ProfileForm;
use App\Support\PhoneNormalizer;
use App\Models\ProfileField;
use App\Rules\IranianMobile;
use App\Support\SessionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);
        $actor = $request->user();
        $baseQuery = $this->visibleUserQuery($actor, true);

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->where('status', 'active')->count(),
            'inactive' => (clone $baseQuery)->where('status', 'inactive')->count(),
            'blocked' => (clone $baseQuery)->where('status', 'blocked')->count(),
            'deleted' => User::onlyTrashed()->visibleTo($actor)->count(),
            'new_this_month' => (clone $baseQuery)->where('created_at', '>=', now()->startOfMonth())->count(),
        ];

        $users = $this->filteredQuery($request, $actor, false, true)
            ->paginate(20)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'roles' => Role::active()->orderBy('sort_order')->get(),
            'centres' => $this->hasGlobalCredentialScope($actor)
                ? Centre::query()->where('is_active', true)->orderBy('name')->get()
                : Centre::query()->whereKey($actor->centre_id)->get(),
            'stats' => $stats,
        ]);
    }

    public function trash(Request $request): View
    {
        $this->authorize('viewDeleted', User::class);
        $actor = $request->user();
        $users = $this->filteredQuery($request, $actor, true)
            ->paginate(20)
            ->withQueryString();

        return view('users.trash', compact('users'));
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export', User::class);
        $actor = $request->user();
        $users = $this->filteredQuery($request, $actor)->lazy(500);
        $filename = 'ensha-users-'.now()->format('Y-m-d-His').'.csv';

        Audit::record('دریافت خروجی کاربران', $request, 'info', [
            'filters' => $request->only(['q', 'role_id', 'centre_id', 'status', 'sort']),
        ], null, 'users.exported');

        return response()->streamDownload(function () use ($users) {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['کد ملی', 'نام', 'نام خانوادگی', 'شماره تلفن', 'نقش', 'مرکز', 'وضعیت', 'آخرین ورود', 'تاریخ عضویت', 'شناسه داخلی']);

            foreach ($users as $user) {
                $row = [
                    $user->national_id,
                    $user->first_name,
                    $user->last_name,
                    $user->phone,
                    $user->role_label,
                    $user->centre?->name ?? 'سراسری',
                    $user->status_label,
                    $user->last_login_at?->format('Y/m/d H:i') ?? '',
                    $user->created_at?->format('Y/m/d H:i') ?? '',
                    $user->id,
                ];
                fputcsv($output, array_map(fn (mixed $value): string => $this->safeCsvValue($value), $row));
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', User::class);

        return view('users.create', $this->formOptions($request->user()));
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $actor = $request->user();
        $data = $request->validated();
        $role = Role::active()->findOrFail($data['role_id']);
        $profileValues = ProfileForm::validate($request, $role);
        $this->ensureRoleIsAssignable($actor, $role);
        $centreId = $this->resolveCentreId($actor, $role, $data['centre_id'] ?? null);
        $isActive = $request->boolean('is_active', true);

        $user = DB::transaction(function () use ($actor, $data, $role, $centreId, $isActive, $profileValues) {
            $created = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'phone' => $data['phone'],
            'national_id' => $data['national_id'],
            'password' => $data['password'],
            'role' => $role->slug,
            'role_id' => $role->id,
            'centre_id' => $centreId,
            'is_active' => $isActive,
            'status' => $isActive ? 'active' : 'inactive',
            'status_changed_at' => now(),
            'status_changed_by' => $actor->id,
            'must_change_password' => true,
            'created_by' => $actor->id,
        ]);
            $created->roleAssignments()->create([
                'role_id'=>$role->id,
                'centre_id'=>$centreId,
                'branch_id'=>$centreId ? CentreBranch::where('centre_id',$centreId)->where('is_default',true)->value('id') : null,
            ]);
            ProfileForm::save($created, $role, $profileValues);
            return $created;
        });

        Audit::record(
            'ایجاد حساب کاربری',
            $request,
            'info',
            ['target_user_id' => $user->id, 'role' => $role->slug, 'centre_id' => $centreId, 'status' => $user->status],
            $user,
            'user.created',
        );

        return redirect()->route('users.show', $user)
            ->with('success', 'حساب کاربری ساخته شد. کاربر در ورود اول باید رمز موقت را تغییر دهد.');
    }

    public function show(Request $request, User $user): View
    {
        $this->authorize('view', $user);
        $user->load(['assignedRole.permissions', 'centre', 'creator', 'statusChanger', 'profileValues.field']);
        $logs = $user->subjectAuditLogs()->with('actor')->latest()->paginate(10, ['*'], 'logs_page');
        $canViewSessions = $request->user()->is($user) || $request->user()->can('revokeSessions', $user);
        $sessions = $canViewSessions
            ? $user->sessions()->latest('last_activity_at')->paginate(8, ['*'], 'sessions_page')
            : null;
        $currentSessionHash = $request->user()->is($user) ? SessionRegistry::hash($request) : null;

        return view('users.show', compact('user', 'logs', 'sessions', 'canViewSessions', 'currentSessionHash'));
    }

    public function edit(Request $request, User $user): View
    {
        $this->authorize('update', $user);
        $user->load(['assignedRole', 'centre', 'profileValues']);

        return view('users.edit', ['user' => $user, ...$this->formOptions($request->user())]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $data = $request->validated();
        $role = Role::active()->findOrFail($data['role_id']);
        $profileValues = ProfileForm::validate($request, $role);
        $this->ensureRoleIsAssignable($actor, $role, $user);

        if ($actor->is($user) && $user->role_id !== $role->id) {
            throw ValidationException::withMessages(['role_id' => 'برای جلوگیری از قطع دسترسی، نمی‌توانید نقش حساب فعلی خود را تغییر دهید.']);
        }

        $centreId = $this->resolveCentreId($actor, $role, $data['centre_id'] ?? null);
        $before = Arr::only($user->getAttributes(), ['first_name', 'last_name', 'phone', 'national_id', 'role', 'role_id', 'centre_id']);
        $accessChanged = (int) $user->role_id !== (int) $role->id || (int) $user->centre_id !== (int) $centreId;

        DB::transaction(function () use ($user, $data, $role, $centreId, $accessChanged, $profileValues) {
            $this->guardLastSuperAdmin($user, $role->slug !== 'super_admin');
            $user->fill([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'phone' => $data['phone'],
                'national_id' => $data['national_id'],
                'role' => $role->slug,
                'role_id' => $role->id,
                'centre_id' => $centreId,
            ])->save();
            if ($accessChanged) {
                $user->roleAssignments()->firstOrCreate(
                    ['role_id'=>$role->id,'centre_id'=>$centreId],
                    ['branch_id'=>$centreId ? CentreBranch::where('centre_id',$centreId)->where('is_default',true)->value('id') : null],
                );
            }
            ProfileForm::save($user, $role, $profileValues);

            if ($accessChanged) {
                SessionRegistry::invalidateAll($user, 'access_changed');
            }
        });

        $after = Arr::only($user->fresh()->getAttributes(), array_keys($before));
        Audit::record(
            'ویرایش حساب کاربری',
            $request,
            $accessChanged ? 'warning' : 'info',
            ['target_user_id' => $user->id, 'before' => $before, 'after' => $after, 'sessions_revoked' => $accessChanged],
            $user,
            'user.updated',
        );

        return redirect()->route('users.show', $user)->with('success', $accessChanged
            ? 'مشخصات و سطح دسترسی به‌روزرسانی شد؛ نشست‌های قبلی کاربر پایان یافت.'
            : 'مشخصات کاربر به‌روزرسانی شد.');
    }

    public function changeStatus(Request $request, User $user): RedirectResponse
    {
        $this->authorize('changeStatus', $user);
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive', 'blocked'])],
            'status_reason' => ['nullable', 'string', 'max:500', Rule::requiredIf($request->input('status') === 'blocked')],
        ], [], ['status' => 'وضعیت حساب', 'status_reason' => 'دلیل تغییر وضعیت']);

        $nextStatus = $data['status'];
        $previous = ['status' => $user->status, 'reason' => $user->status_reason];
        $revokedCount = 0;

        DB::transaction(function () use ($request, $user, $data, $nextStatus, &$revokedCount) {
            $this->guardLastSuperAdmin($user, $nextStatus !== 'active');
            $user->update([
                'status' => $nextStatus,
                'is_active' => $nextStatus === 'active',
                'status_reason' => $nextStatus === 'active' ? null : ($data['status_reason'] ?? null),
                'status_changed_at' => now(),
                'status_changed_by' => $request->user()->id,
            ]);

            if ($nextStatus !== 'active') {
                $revokedCount = SessionRegistry::invalidateAll($user, 'status_'.$nextStatus);
            }
        });

        $labels = ['active' => 'فعال', 'inactive' => 'غیرفعال', 'blocked' => 'مسدود'];
        Audit::record(
            'تغییر وضعیت حساب به '.$labels[$nextStatus],
            $request,
            $nextStatus === 'active' ? 'info' : 'warning',
            ['target_user_id' => $user->id, 'before' => $previous, 'after' => $data, 'revoked_sessions' => $revokedCount],
            $user,
            'user.status_changed',
        );

        return back()->with('success', 'وضعیت حساب به «'.$labels[$nextStatus].'» تغییر کرد.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->authorize('resetPassword', $user);
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()->symbols()],
        ], [], ['password' => 'رمز عبور موقت']);

        $revokedCount = DB::transaction(function () use ($user, $data) {
            $user->forceFill([
                'password' => $data['password'],
                'must_change_password' => true,
                'password_changed_at' => null,
            ])->save();

            return SessionRegistry::invalidateAll($user, 'password_reset');
        });

        Audit::record(
            'بازنشانی رمز عبور کاربر',
            $request,
            'warning',
            ['target_user_id' => $user->id, 'forced_change' => true, 'revoked_sessions' => $revokedCount],
            $user,
            'user.password_reset',
        );

        return back()->with('success', 'رمز موقت ثبت و همه نشست‌های قبلی کاربر پایان داده شد.');
    }

    public function updatePhone(Request $request, User $user): RedirectResponse
    {
        $this->authorize('updatePhone', $user);
        $request->merge(['phone' => PhoneNormalizer::normalize((string) $request->input('phone'))]);
        $data = $request->validate([
            'phone' => ['required', new IranianMobile, Rule::unique('users', 'phone')->ignore($user->id)],
        ], [], ['phone' => 'شماره تلفن']);

        $before = $user->phone;
        if ($before === $data['phone']) {
            return back()->with('success', 'شماره تلفن تغییری نکرد.');
        }

        $revokedCount = DB::transaction(function () use ($user, $data) {
            $user->forceFill(['phone' => $data['phone']])->save();

            return SessionRegistry::invalidateAll($user, 'phone_changed_by_credential_administrator');
        });

        Audit::record(
            'تغییر شماره تلفن کاربر توسط مدیر اعتبارنامه',
            $request,
            'warning',
            ['target_user_id' => $user->id, 'before' => $before, 'after' => $data['phone'], 'revoked_sessions' => $revokedCount],
            $user,
            'user.phone_updated_by_credential_administrator',
        );

        return back()->with('success', 'شماره تلفن تغییر کرد و همه نشست‌های قبلی کاربر پایان یافت.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        DB::transaction(function () use ($request, $user) {
            $this->guardLastSuperAdmin($user, true);
            SessionRegistry::invalidateAll($user, 'account_deleted');
            $user->forceFill([
                'status' => 'inactive',
                'is_active' => false,
                'status_reason' => 'حذف نرم حساب',
                'status_changed_at' => now(),
                'status_changed_by' => $request->user()->id,
            ])->saveQuietly();
            $user->delete();
        });

        Audit::record(
            'حذف نرم حساب کاربری',
            $request,
            'warning',
            ['target_user_id' => $user->id, 'phone' => $user->phone, 'role' => $user->role],
            $user,
            'user.deleted',
        );

        return redirect()->route('users.index')->with('success', 'حساب کاربر حذف شد و از بخش سطل حذف قابل بازیابی است.');
    }

    public function restore(Request $request, int $user): RedirectResponse
    {
        $target = User::onlyTrashed()->visibleTo($request->user())->findOrFail($user);
        $this->authorize('restore', $target);

        DB::transaction(function () use ($request, $target) {
            $target->restore();
            $target->forceFill([
                'status' => 'inactive',
                'is_active' => false,
                'status_reason' => 'حساب بازیابی شد؛ برای ورود باید فعال شود.',
                'status_changed_at' => now(),
                'status_changed_by' => $request->user()->id,
            ])->save();
        });

        Audit::record(
            'بازیابی حساب کاربری',
            $request,
            'warning',
            ['target_user_id' => $target->id, 'restored_as' => 'inactive'],
            $target,
            'user.restored',
        );

        return redirect()->route('users.show', $target)
            ->with('success', 'حساب بازیابی شد و برای بررسی امنیتی در وضعیت غیرفعال قرار گرفت.');
    }

    private function filteredQuery(
        Request $request,
        User $actor,
        bool $onlyTrashed = false,
        bool $allowGlobalCredentialDirectory = false,
    ): Builder
    {
        $query = $onlyTrashed ? User::onlyTrashed() : User::query();

        return $this->visibleUserQuery($actor, $allowGlobalCredentialDirectory, $query)
            ->with(['assignedRole', 'centre'])
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $normalized = PhoneNormalizer::normalizeDigits(trim((string) $request->input('q')));
                if (preg_match('/^[0-9]{10}$/', $normalized)) {
                    $query->where('national_id', $normalized);
                    return;
                }
                $term = addcslashes($normalized, '%_\\');
                $query->where(function (Builder $search) use ($term) {
                    $search->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('national_id', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('role_id'), fn (Builder $query) => $query->where('role_id', $request->integer('role_id')))
            ->when($request->filled('centre_id') && $this->hasGlobalCredentialScope($actor), fn (Builder $query) => $query->where('centre_id', $request->integer('centre_id')))
            ->when(in_array($request->input('status'), ['active', 'inactive', 'blocked'], true), fn (Builder $query) => $query->where('status', $request->input('status')))
            ->when(
                $request->input('sort') === 'oldest',
                fn (Builder $query) => $query->oldest(),
                fn (Builder $query) => $request->input('sort') === 'name'
                    ? $query->orderBy('last_name')->orderBy('first_name')
                    : $query->latest(),
            );
    }

    private function formOptions(User $actor): array
    {
        return [
            'roles' => Role::active()->assignableBy($actor)->orderBy('sort_order')->get(),
            'profileFieldGroups' => ProfileField::where('is_active', true)->orderBy('sort_order')->get()->groupBy('role'),
            'centres' => $actor->isSuperAdmin()
                ? Centre::query()->where('is_active', true)->orderBy('name')->get()
                : Centre::query()->whereKey($actor->centre_id)->get(),
        ];
    }

    private function ensureRoleIsAssignable(User $actor, Role $role, ?User $target = null): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $allowed = Role::query()->active()->assignableBy($actor)->whereKey($role->id)->exists();
        if (! $allowed || ($target && in_array($target->role, ['super_admin', 'manager'], true))) {
            throw ValidationException::withMessages(['role_id' => 'اجازه تخصیص این نقش را ندارید.']);
        }
    }

    private function resolveCentreId(User $actor, Role $role, mixed $requestedCentreId): ?int
    {
        if ($role->scope === 'global') {
            return null;
        }

        $centreId = $actor->isSuperAdmin() ? (int) $requestedCentreId : (int) $actor->centre_id;
        if (! $centreId || ! Centre::query()->whereKey($centreId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['centre_id' => 'برای نقش مرکز‌محور، انتخاب مرکز فعال الزامی است.']);
        }

        return $centreId;
    }

    private function guardLastSuperAdmin(User $user, bool $removingAccess): void
    {
        if (! $removingAccess || ! $user->isSuperAdmin() || ! $user->isActive()) {
            return;
        }

        // قفل نقش، تغییر هم‌زمان دو ادمین آخر را سریالی می‌کند.
        $superAdminRoleId = Role::query()
            ->where('slug', 'super_admin')
            ->lockForUpdate()
            ->value('id');
        $otherAdminExists = User::query()
            ->where('id', '!=', $user->id)
            ->where('status', 'active')
            ->where('is_active', true)
            ->where(function (Builder $query) use ($superAdminRoleId) {
                $query->where('role_id', $superAdminRoleId)->orWhere('role', 'super_admin');
            })
            ->exists();

        if (! $otherAdminExists) {
            throw ValidationException::withMessages(['user' => 'آخرین ادمین فعال سامانه را نمی‌توان حذف، غیرفعال، مسدود یا تنزل نقش داد.']);
        }
    }

    private function safeCsvValue(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }

    private function hasGlobalCredentialScope(User $actor): bool
    {
        return $actor->isSuperAdmin() || $actor->hasPermission(User::GLOBAL_CREDENTIAL_PERMISSION);
    }

    private function visibleUserQuery(
        User $actor,
        bool $allowGlobalCredentialDirectory,
        ?Builder $query = null,
    ): Builder {
        $query ??= User::query();

        return $allowGlobalCredentialDirectory && $this->hasGlobalCredentialScope($actor)
            ? $query
            : $query->visibleTo($actor);
    }
}
