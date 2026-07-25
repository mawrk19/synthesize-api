<?php

namespace App\Modules\Authorization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authorization\Actions\AssignRoleAction;
use App\Modules\Authorization\Actions\UpdateRolePermissionsAction;
use App\Modules\Authorization\Http\Requests\AddUserToRoleRequest;
use App\Modules\Authorization\Http\Requests\UpdateRolePermissionsRequest;
use App\Modules\Authorization\Http\Resources\RoleResource;
use App\Modules\Authorization\Services\RoleService;
use App\Modules\Iam\Http\Resources\UserResource;
use App\Modules\Iam\Services\UserService;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleController extends Controller
{
    public function __construct(
        private RoleService $roleService,
        private UserService $userService,
    ) {}

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function index()
    {
        return RoleResource::collection($this->roleService->getAllRoles());
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function show(string $id)
    {
        $role = $this->roleService->getRoleByIdWithPermissions($id);
        if (! $role) {
            abort(404, 'Role not found');
        }

        return new RoleResource($role);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function users(string $id)
    {
        return UserResource::collection($this->userService->getUsersByRoleId($id));
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function addUser(string $id, AddUserToRoleRequest $request, AssignRoleAction $assignRoleAction)
    {
        $role = $this->roleService->getRoleById($id);
        if (! $role) {
            abort(404, 'Role not found');
        }

        $userRole = $assignRoleAction->handle($role, $request->string('user_id'));

        return new JsonResource($userRole);
    }

    public function updatePermissions(string $id, UpdateRolePermissionsRequest $request, RoleService $roleService, UpdateRolePermissionsAction $updateRolePermissionsAction)
    {
        $role = $roleService->getRoleById($id);
        if (! $role) {
            abort(404, 'Role not found');
        }

        $role = $updateRolePermissionsAction->handle($role, $request->array('permission_codes'));

        return new RoleResource($role);
    }
}
