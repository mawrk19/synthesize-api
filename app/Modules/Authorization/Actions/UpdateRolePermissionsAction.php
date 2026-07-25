<?php

namespace App\Modules\Authorization\Actions;

use App\Modules\Authorization\Models\Permission;
use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Services\RoleService;

class UpdateRolePermissionsAction
{
    public function __construct(
        private RoleService $roleService,
    ) {}

    public function handle(Role $role, array $permission_codes): ?Role
    {
        $permissions = Permission::query()->whereIn('code', $permission_codes)->pluck('id');
        $role->permissions()->sync($permissions);

        return $this->roleService->getRoleByIdWithPermissions($role->id);
    }
}
