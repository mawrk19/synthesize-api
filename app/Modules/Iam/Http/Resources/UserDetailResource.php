<?php

namespace App\Modules\Iam\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserDetailResource extends JsonResource
{
    public function toArray($request)
    {
        return parent::toArray($request);
    }
}