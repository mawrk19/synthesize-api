<?php

namespace App\Modules\Core\Http\Resources;

use App\Modules\Iam\Http\Resources\UserResource;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Core\Dtos\ContextDTO */
class ContextResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'current_user' => new UserResource($this->currentUser),
            'permission_codes' => $this->permission_codes,
        ];
    }
}