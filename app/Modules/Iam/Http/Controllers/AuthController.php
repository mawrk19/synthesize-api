<?php

namespace App\Modules\Iam\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Iam\Actions\AuthenticateUserAction;
use App\Modules\Iam\Http\Requests\LoginRequest;
use App\Modules\Iam\Http\Resources\LoginResource;
use App\Modules\Iam\Http\Resources\UserResource;
use Auth;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /**
     * Get the current authenticated user
     */
    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function currentUser(Request $request)
    {
        return new UserResource(Auth::user());
    }

    public function login(LoginRequest $request, AuthenticateUserAction $action)
    {
        $result = $action->execute($request->identifier, $request->password);

        return new LoginResource([
            'user' => $result->user,
            'token' => $result->token,
        ]);
    }
}
