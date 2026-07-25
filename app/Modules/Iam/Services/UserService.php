<?php

namespace App\Modules\Iam\Services;

use App\Modules\Authorization\Services\RoleHydratorService;
use App\Modules\Authorization\Services\UserRoleService;
use App\Modules\Iam\Contracts\UserRepository;
use App\Modules\Iam\Entities\UserDetail;

class UserService
{
    public function __construct(
        private UserRepository $userRepository,
        private RoleHydratorService $roleHydratorService,
        private UserRoleService $userRoleService,
    ) {}

    /** @return iterable<UserDetail> */
    public function getAllUsers(): iterable
    {
        $users = $this->userRepository->getAll();
        $userDetails = UserDetail::fromList($users);

        return $this->roleHydratorService->hydrateRolesCount($userDetails);
    }

    public function getUsersByRoleId(string $role_id): iterable
    {
        return once(function () use ($role_id) {
            $user_ids = $this->userRoleService->getUserIdsByRoleId($role_id);

            return $this->userRepository->findByIds($user_ids->all());
        });
    }
}
