<?php

namespace App\Modules\Iam\Contracts;

use App\Modules\Iam\Entities\User;

interface UserRepository
{
    public function findByIdentifier(string $identifier): ?User;

    public function findById(string $id): ?User;

    /** @return iterable<User> */
    public function findByIds(array $ids): iterable;

    /** @return iterable<User> */
    public function getAll(): iterable;
}
