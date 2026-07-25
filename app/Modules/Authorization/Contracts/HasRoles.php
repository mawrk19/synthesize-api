<?php

namespace App\Modules\Authorization\Contracts;

interface HasRoles
{
    public function getKey(): int|string;

    public function getRolesCount(): int;

    /** @return iterable<\App\Modules\Authorization\Models\Role> */
    public function getRoles(): iterable;
}
