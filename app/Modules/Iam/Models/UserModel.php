<?php

namespace App\Modules\Iam\Models;

use App\Modules\Iam\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[UseFactory(UserFactory::class)]
class UserModel extends Authenticatable
{
    /** @use HasFactory<\App\Modules\Iam\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'username',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
