<?php

namespace App\Modules\Iam\Http\Resources;

use App\Modules\Iam\Entities\User;
use App\Modules\Iam\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User|UserModel */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof User) {
            return $this->resource->toArray();
        }

        return [
            'id' => $this->id,
            'email' => $this->email,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'avatar_url' => 'https://api.dicebear.com/10.x/dylan/svg?seed='.urlencode((string) $this->id),
        ];
    }
}
