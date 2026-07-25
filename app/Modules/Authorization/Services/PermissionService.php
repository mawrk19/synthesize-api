<?php

namespace App\Modules\Authorization\Services;

use App\Modules\Authorization\Models\Permission;
use Illuminate\Support\Collection;

class PermissionService
{
    /** @return Collection<int, Permission> */
    public function getAllPermissions(): Collection
    {
        return Permission::query()->get();
    }
}
