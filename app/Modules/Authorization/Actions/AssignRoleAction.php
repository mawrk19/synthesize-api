<?php

namespace App\Modules\Authorization\Actions;

use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Models\UserRole;

class AssignRoleAction
{
    public function handle(Role $role, int|string $user_id): UserRole
    {
        return UserRole::query()->updateOrCreate(
            [
                'role_id' => $role->id,
                'user_id' => $user_id,
            ],
            []
        );
    }
}
