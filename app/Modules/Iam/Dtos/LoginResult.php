<?php

namespace App\Modules\Iam\Dtos;

use App\Modules\Iam\Entities\User;

class LoginResult
{
    public function __construct(
        public User $user,
        public string $token,
    ) {}
}
