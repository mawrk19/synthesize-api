<?php

namespace App\Modules\Iam\Entities;

use Illuminate\Contracts\Support\Arrayable;

class User implements Arrayable
{
    public function __construct(
        public int|string $id,
        public string $email,
        public string $username,
        public string $first_name,
        public ?string $middle_name,
        public string $last_name,
        public string $password,
        public ?string $remember_token,
        public string $avatar_url,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'avatar_url' => $this->avatar_url,
        ];
    }
}
