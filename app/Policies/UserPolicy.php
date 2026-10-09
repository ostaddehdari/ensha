<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('users.view') || $this->managesCredentialsGlobally($actor);
    }

    public function view(User $actor, User $target): bool
    {
        return $this->managesCredentialsGlobally($actor)
            || ($actor->hasPermission('users.view') && $this->withinScope($actor, $target));
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('users.create');
    }

    public function export(User $actor): bool
    {
        return $actor->hasPermission('users.export');
    }

    public function viewDeleted(User $actor): bool
    {
        return $actor->hasPermission('users.restore');
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->hasPermission('users.update') && $this->canManage($actor, $target);
    }

    public function updatePhone(User $actor, User $target): bool
    {
        return $this->managesCredentialsGlobally($actor)
            || ($actor->hasPermission('users.update') && $this->canManage($actor, $target));
    }

    public function changeStatus(User $actor, User $target): bool
    {
        return $actor->hasPermission('users.change_status')
            && $actor->isNot($target)
            && $this->canManage($actor, $target);
    }

    public function resetPassword(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        return $this->managesCredentialsGlobally($actor)
            || ($actor->hasPermission('users.reset_password') && $this->canManage($actor, $target));
    }

    public function revokeSessions(User $actor, User $target): bool
    {
        return $actor->hasPermission('users.revoke_sessions')
            && $actor->isNot($target)
            && $this->canManage($actor, $target);
    }

    public function impersonate(User $actor, User $target): bool
    {
        return $actor->hasPermission('users.impersonate')
            && $actor->isSuperAdmin()
            && $actor->isNot($target)
            && ! $target->isSuperAdmin()
            && ! $target->roleAssignments()->whereHas('role',fn ($role)=>$role->where('slug','super_admin'))->exists()
            && $target->isActive()
            && (bool) $target->assignedRole?->is_active;
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->hasPermission('users.delete')
            && $actor->isNot($target)
            && $this->canManage($actor, $target);
    }

    public function restore(User $actor, User $target): bool
    {
        return $target->trashed()
            && $actor->hasPermission('users.restore')
            && $this->canManage($actor, $target);
    }

    private function withinScope(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin()
            || ($actor->centre_id && $actor->centre_id === $target->centre_id && ! $target->isSuperAdmin());
    }

    private function canManage(User $actor, User $target): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        if (! $this->withinScope($actor, $target)
            || in_array($target->role, ['super_admin', 'manager'], true)
            || ! $target->role_id) {
            return false;
        }

        return Role::query()
            ->active()
            ->assignableBy($actor)
            ->whereKey($target->role_id)
            ->exists();
    }

    private function managesCredentialsGlobally(User $actor): bool
    {
        return $actor->hasPermission(User::GLOBAL_CREDENTIAL_PERMISSION);
    }
}
