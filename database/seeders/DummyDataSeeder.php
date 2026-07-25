<?php

namespace Database\Seeders;

use App\Modules\Authorization\Actions\AssignRoleAction;
use App\Modules\Authorization\Models\Role;
use App\Modules\Core\Enums\SystemRole;
use App\Modules\Iam\Models\UserModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DummyDataSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'first_name' => 'Mark',
                'last_name' => 'Acedo',
                'username' => 'mawrk19',
                'email' => 'gercee19@gmail.com',
                'password' => 'password123',
                'role_code' => SystemRole::SUPER_ADMIN->value,
            ],
        ];

        $assignRoleAction = app(AssignRoleAction::class);

        foreach ($users as $user) {
            $userModel = UserModel::query()->updateOrCreate(['username' => $user['username']], [
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'email' => $user['email'],
                'password' => Hash::make($user['password']),
            ]);

            $role = Role::query()->where('code', $user['role_code'])->first();

            if (! $role) {
                $this->command?->warn("Role {$user['role_code']} not found. Run PermissionSeeder and RoleSeeder first.");

                continue;
            }

            $assignRoleAction->handle($role, $userModel->id);
        }
    }
}
