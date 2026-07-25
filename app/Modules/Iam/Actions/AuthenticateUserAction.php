<?php

namespace App\Modules\Iam\Actions;

use App\Modules\Iam\Contracts\UserRepository;
use App\Modules\Iam\Dtos\LoginResult;
use App\Modules\Iam\Exceptions\InvalidCredentialException;
use App\Modules\Iam\Services\TokenService;
use Hash;

class AuthenticateUserAction
{
    public function __construct(
        private UserRepository $userRepository,
        private TokenService $tokenService,
    ) {}

    public function execute(string $identifier, string $password): LoginResult
    {
        $user = $this->userRepository->findByIdentifier($identifier);

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new InvalidCredentialException;
        }

        return new LoginResult(
            $user,
            $this->tokenService->generateToken($user)
        );
    }
}
