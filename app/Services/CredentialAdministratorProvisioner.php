<?php

namespace App\Services;

use App\Models\Centre;
use App\Models\CentreBranch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class CredentialAdministratorProvisioner
{
    public function provision(string $phone): User
    {
        $phone = PhoneNormalizer::normalize($phone);
        $user = User::query()->where('phone', $phone)->first();
        if (! $user) {
            throw (new ModelNotFoundException)->setModel(User::class, [$phone]);
        }

        $originalCentreId = $user->centre_id;
        $user = DB::transaction(function () use ($user, $originalCentreId): User {
            $permission = Permission::query()
                ->where('slug', User::GLOBAL_CREDENTIAL_PERMISSION)
                ->where('is_active', true)
                ->firstOrFail();
            $superAdminRole = Role::query()->where('slug', 'super_admin')->where('is_active', true)->firstOrFail();
            $managerRole = Role::query()->where('slug', 'manager')->where('is_active', true)->firstOrFail();
            $managerCentre = Centre::query()
                ->where('is_active', true)
                ->when($originalCentreId, fn ($query) => $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$originalCentreId]))
                ->orderByRaw("CASE WHEN code = 'ENSHA-MAIN' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->firstOrFail();
            $branchId = CentreBranch::query()
                ->where('centre_id', $managerCentre->id)
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');

            $user->directPermissions()->syncWithoutDetaching([$permission->id]);
            $user->roleAssignments()->firstOrCreate([
                'role_id' => $superAdminRole->id,
                'centre_id' => null,
            ], ['branch_id' => null]);
            $user->roleAssignments()->firstOrCreate([
                'role_id' => $managerRole->id,
                'centre_id' => $managerCentre->id,
            ], ['branch_id' => $branchId]);
            $user->forceFill([
                'role' => 'super_admin',
                'role_id' => $superAdminRole->id,
                'centre_id' => null,
            ])->save();

            return $user->fresh();
        });

        Audit::record('اعطای نقش مدیر کل و مدیر مرکز به مدیر اعتبارنامه', null, 'warning', [
            'target_user_id' => $user->id,
            'target_phone' => $user->phone,
            'roles' => ['super_admin', 'manager'],
        ], $user, 'user.credential_administrator_provisioned');

        return $user;
    }
}
