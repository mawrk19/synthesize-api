<?php

namespace App\Modules\Iam\Services;

use App\Modules\Iam\Entities\User;
use App\Modules\Iam\Models\UserModel;

class TokenService
{
    public function generateToken(User $user): string
    {
        /** @var UserModel $model */
        $model = UserModel::query()->findOrFail($user->id);

        $expiresAt = now()->addMinutes((int) config('iam.token_expiration', 120));

        return $model->createToken('api', ['*'], $expiresAt)->plainTextToken;
    }
}
