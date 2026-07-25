<?php

namespace App\Modules\Iam\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Iam\Actions\CreateUserAction;
use App\Modules\Iam\Http\Requests\StoreUserRequest;
use App\Modules\Iam\Http\Resources\UserDetailResource;
use App\Modules\Iam\Services\UserService;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function index(Request $request)
    {
        $users = $this->userService->getAllUsers();

        return UserDetailResource::collection($users);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function store(StoreUserRequest $request, CreateUserAction $action)
    {
        $user = $action->handle($request->validated());

        return (new UserDetailResource($user))
            ->response()
            ->setStatusCode(201);
    }
}
