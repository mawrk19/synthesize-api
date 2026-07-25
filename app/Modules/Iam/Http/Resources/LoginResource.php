<?php

namespace App\Modules\Iam\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoginResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'user' => new UserResource($this->resource['user']),
            'token' => $this->resource['token'],
        ];
    }
}
