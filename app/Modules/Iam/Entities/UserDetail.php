<?php

namespace App\Modules\Iam\Entities;

use App\Modules\Authorization\Contracts\HasRoles;
use App\Modules\Authorization\Models\Role;
use App\Modules\Iam\Entities\User;

class UserDetail implements HasRoles
{
    private ?int $roles_count = null;

    /** @var iterable<Role> */
    private iterable $roles = [];

    public function __construct(
        private User $user,
    ) {}

    public function getKey(): int|string
    {
        return $this->user->id;
    }

    public function getRolesCount(): int
    {
        return $this->roles_count ?? 0;
    }

    /** @return iterable<Role> */
    public function getRoles(): iterable
    {
        return $this->roles;
    }

    public function setRolesCount(int $roles_count): self
    {
        $this->roles_count = $roles_count;

        return $this;
    }

    /** @param  array<Role>  $roles */
    public function setRoles(array $roles): self
    {
        $this->roles = $roles;
        $this->setRolesCount(count($roles));

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->user->toArray() + [
            'roles_count' => $this->getRolesCount(),
            'roles' => $this->getRoles(),
        ];
    }

    /**
     * @param  iterable<User>  $users
     * @return iterable<UserDetail>
     */
    public static function fromList(iterable $users): iterable
    {
        return collect($users)->map(fn ($user) => new UserDetail($user));
    }
}
