<?php

namespace App\Modules\Core\Dtos;

use App\Modules\Iam\Entities\User;

class ContextDTO
{
    /**
     * @param  array<string>  $permission_codes
     */
    public function __construct(
        public User $currentUser,
        public array $permission_codes,
    ) {}
}
