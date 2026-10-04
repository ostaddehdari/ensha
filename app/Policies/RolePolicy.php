<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.view');
    }

    public function create(User $user): bool
    {
        return $this->canManageAccessControl($user) && $user->hasPermission('roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        if (! $this->canManageAccessControl($user) || ! $user->hasPermission('roles.update')) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return ! $role->is_system
            && $role->scope === 'centre'
            && $user->role_id !== $role->id;
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->canManageAccessControl($user)
            && $user->hasPermission('roles.delete')
            && ! $role->is_system
            && $role->scope === 'centre'
            && $user->role_id !== $role->id
            && $role->users()->doesntExist();
    }

    private function canManageAccessControl(User $user): bool
    {
        return $user->isSuperAdmin() || $user->assignedRole?->scope === 'global';
    }
}
