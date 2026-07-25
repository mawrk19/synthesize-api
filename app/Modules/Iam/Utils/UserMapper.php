<?php

namespace App\Modules\Iam\Utils;

use App\Modules\Iam\Entities\User;
use App\Modules\Iam\Models\UserModel;

class UserMapper
{
    public static function fromEloquent(?UserModel $user): ?User
    {
        if (! $user) {
            return null;
        }

        return new User(
            $user->id,
            $user->email,
            $user->username,
            $user->first_name,
            $user->middle_name,
            $user->last_name,
            $user->password,
            $user->remember_token,
            "https://api.dicebear.com/6.x/initials/svg?seed={$user->first_name}%20{$user->last_name}",
        );
    }
}
