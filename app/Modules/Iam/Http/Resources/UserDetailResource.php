<?php

namespace App\Modules\Iam\Http\Resources;

use App\Modules\Iam\Entities\UserDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserDetail */
class UserDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}