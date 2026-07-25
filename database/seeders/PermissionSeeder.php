<?php

namespace Database\Seeders;

use App\Modules\Authorization\Enums\ActionType;
use App\Modules\Authorization\Enums\PermissionCode;
use App\Modules\Authorization\Enums\ResourceType;
use App\Modules\Authorization\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
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

        foreach (PermissionCode::cases() as $permissionCode) {
            $permissionCode->getResourceType();
        }
    }
}
