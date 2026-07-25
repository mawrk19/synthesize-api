<?php

namespace App\Modules\Authorization\Services;

use App\Modules\Authorization\Models\Permission;
use App\Modules\Authorization\Models\UserRole;
use Illuminate\Support\Collection;

class UserRoleService
{
    /** @return array<string> */
    public function getUserPermissionCodes(int|string $userId): array
    {
        return Permission::query()
            ->whereHas('roles.userRoles', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->pluck('code')
            ->unique()
            ->values()
            ->all();
    }

    /** @return Collection<int, int|string> */
    public function getUserIdsByRoleId(string $roleId): Collection
    {
        return UserRole::query()
            ->where('role_id', $roleId)
            ->pluck('user_id');
    }
}
