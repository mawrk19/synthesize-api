<?php

namespace App\Modules\Iam\Http\Controllers;

use App\Http\Controllers\Controller;
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
}
