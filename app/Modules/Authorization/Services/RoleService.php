<?php

namespace App\Modules\Authorization\Services;

use App\Modules\Authorization\Models\Role;
use Illuminate\Support\Collection;

class RoleService
{
    /** @return Collection<int, Role> */
    public function getAllRoles(): Collection
    {
        return Role::query()
            ->withCount('userRoles')
            ->get();
    }

    public function getRoleById(string $id): ?Role
    {
        return Role::query()->find($id);
    }

    public function getRoleByIdWithPermissions(string $id): ?Role
    {
        return Role::query()
            ->with('permissions')
            ->withCount('userRoles')
            ->find($id);
    }
}
