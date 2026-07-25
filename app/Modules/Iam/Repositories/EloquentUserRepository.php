<?php

namespace App\Modules\Iam\Repositories;

use App\Modules\Iam\Contracts\UserRepository;
use App\Modules\Iam\Entities\User;
use App\Modules\Iam\Models\UserModel;
use App\Modules\Iam\Utils\UserMapper;
use Illuminate\Database\Eloquent\Collection;

class EloquentUserRepository implements UserRepository
{
    public function findByIdentifier(string $identifier): ?User
    {
        $user = UserModel::query()
            ->where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        return UserMapper::fromEloquent($user);
    }

    public function findById(string $id): ?User
    {
        $user = UserModel::query()->find($id);

        return UserMapper::fromEloquent($user);
    }

    /** @return iterable<User> */
    public function findByIds(array $ids): iterable
    {
        /** @var Collection<int, UserModel> $users */
        $users = UserModel::query()->whereIn('id', $ids)->get();

        return $users->map(fn (UserModel $user) => UserMapper::fromEloquent($user));
    }

    /** @return iterable<User> */
    public function getAll(): iterable
    {
        $users = UserModel::query()->get();

        foreach ($users as $user) {
            yield UserMapper::fromEloquent($user);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): User
    {
        $user = UserModel::query()->create($attributes);

        return UserMapper::fromEloquent($user);
    }
}
