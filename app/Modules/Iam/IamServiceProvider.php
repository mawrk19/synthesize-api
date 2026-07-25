<?php

namespace App\Modules\Iam;

use App\Modules\Iam\Contracts\UserRepository;
use App\Modules\Iam\Repositories\EloquentUserRepository;
use Illuminate\Support\ServiceProvider;

class IamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UserRepository::class, EloquentUserRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
