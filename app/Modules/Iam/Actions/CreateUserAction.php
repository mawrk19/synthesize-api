<?php

namespace App\Modules\Iam\Actions;

use App\Modules\Authorization\Actions\AssignRoleAction;
use App\Modules\Authorization\Models\Role;
use App\Modules\Iam\Contracts\UserRepository;
use App\Modules\Iam\Entities\UserDetail;

class CreateUserAction
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AssignRoleAction $assignRoleAction,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function handle(array $data): UserDetail
    {
        $user = $this->userRepository->create([
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        if (! empty($data['role_id'])) {
            $role = Role::query()->find($data['role_id']);
            if ($role) {
                $this->assignRoleAction->handle($role, $user->id);
            }
        }

        $detail = new UserDetail($user);
        $detail->setRolesCount(! empty($data['role_id']) ? 1 : 0);

        return $detail;
    }
}
