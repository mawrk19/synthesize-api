<?php

namespace App\Modules\Authorization\Services;

use App\Modules\Authorization\Contracts\HasRoles;
use App\Modules\Authorization\Models\UserRole;
use Illuminate\Support\Collection;

class RoleHydratorService
{
    /**
     * @param  iterable<HasRoles>  $userDetails
     * @return Collection<int, HasRoles>
     */
    public function hydrateRolesCount(iterable $userDetails): Collection
    {
        $userDetails = collect($userDetails);
        $userIds = $userDetails->map(fn (HasRoles $detail) => $detail->getKey())->all();

        $roleCounts = UserRole::query()
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, count(*) as roles_count')
            ->groupBy('user_id')
            ->pluck('roles_count', 'user_id');

        return $userDetails->map(function (HasRoles $detail) use ($roleCounts) {
            if (method_exists($detail, 'setRolesCount')) {
                $detail->setRolesCount((int) ($roleCounts[$detail->getKey()] ?? 0));
            }

            return $detail;
        });
    }
}
