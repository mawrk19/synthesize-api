<?php

namespace App\Modules\Authorization\Http\Resources;

use App\Modules\Authorization\Models\Role;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    public function toArray($request)
    {
        return parent::toArray($request) + [
            'user_roles_count' => $this->whenCounted('userRoles'),
            'permissions' => $this->whenLoaded('permissions', function () {
                return JsonResource::collection($this->permissions);
            }),
        ];
    }
}
