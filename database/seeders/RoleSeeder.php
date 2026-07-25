<?php

namespace Database\Seeders;

use App\Modules\Authorization\Models\Permission;
use App\Modules\Authorization\Models\Role;
use App\Modules\Core\Enums\SystemRole;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = SystemRole::cases();
        foreach ($roles as $role) {
            $roleModal = Role::query()->firstOrCreate(['code' => $role->value], [
                'name' => $role->getLabel(),
                'description' => $role->getDescription(),
            ]);

            $permission_codes = $role->getPermissionCodes();
            $permission_ids = Permission::query()->whereIn('code', $permission_codes)->pluck('id');
            $roleModal->permissions()->sync($permission_ids);
        }
    }
}
