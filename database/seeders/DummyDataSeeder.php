<?php

namespace Database\Seeders;

use App\Modules\Authorization\Actions\AssignRoleAction;
use App\Modules\Authorization\Enums\ActionType;
use App\Modules\Authorization\Enums\ResourceType;
use App\Modules\Authorization\Models\Permission;
use App\Modules\Authorization\Models\Role;
use App\Modules\Core\Enums\SystemRole;
use App\Modules\Iam\Models\UserModel;
use Hash;
use Illuminate\Database\Seeder;

class DummyDataSeeder extends Seeder
{
    public function run(): void
    {
        $resourceTypes = ResourceType::cases();
        foreach ($resourceTypes as $resourceType) {
            $permissions = $resourceType->getPermissions();
            foreach ($permissions as $permissionCode) {
                Permission::query()->firstOrCreate(['code' => $permissionCode->value], [
                    'name' => $permissionCode->getLabel(),
                    'description' => $permissionCode->getDescription(),
                    'resource' => $resourceType->value,
                    'action' => ActionType::UNKNOWN->value,
                ]);
            }
        }

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

        $users = [
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'username' => 'superadmin',
                'email' => 'superadmin@example.com',
                'password' => 'password',
                'role_code' => SystemRole::SUPER_ADMIN->value,
            ],
        ];

        foreach ($users as $user) {
            $userModel = UserModel::query()->updateOrCreate(['username' => $user['username']], [
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'email' => $user['email'],
                'password' => Hash::make($user['password']),
            ]);

            $role = Role::query()->where('code', $user['role_code'])->first();
            $assignRoleAction = app(AssignRoleAction::class);
            $assignRoleAction->handle($role, $userModel->id);
        }
    }
}
