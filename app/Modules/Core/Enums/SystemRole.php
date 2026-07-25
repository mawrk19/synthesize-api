<?php

namespace App\Modules\Core\Enums;

use App\Modules\Authorization\Enums\PermissionCode;
use Str;

enum SystemRole: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';

    public function getLabel(): string
    {
        return match ($this) {
            default => Str::title(str_replace('_', ' ', $this->name)),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => "Can access all features and manage all users and roles.",
            default => "No description available",
        };
    }

    public function getPermissionCodes()
    {
        return match ($this) {
            self::SUPER_ADMIN => PermissionCode::cases(),
            default => [],
        };
    }
}