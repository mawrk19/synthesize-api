<?php

namespace App\Modules\Core\Services;

use App\Modules\Authorization\Services\UserRoleService;
use App\Modules\Core\Dtos\ContextDTO;
use App\Modules\Iam\Contracts\UserRepository;
use Auth;

class ContextService
{
    public function __construct(
        private readonly UserRoleService $userRoleService,
        private readonly UserRepository $userRepository,
    ) {}

    public function getContext(): ContextDTO
    {
        $user_id = Auth::id();

        return new ContextDTO(
            currentUser: $this->userRepository->findById((string) $user_id),
            permission_codes: $this->userRoleService->getUserPermissionCodes($user_id),
        );
    }
}
