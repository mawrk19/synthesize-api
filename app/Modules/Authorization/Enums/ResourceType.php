<?php

namespace App\Modules\Authorization\Enums;

enum ResourceType: string
{
    case FILINGS = 'FILINGS';
    case FORMS = 'FORMS';
    case USERS = 'USERS';
    case ROLES = 'ROLES';
    case ORG_UNITS = 'ORG_UNITS';
    case EMPLOYEES = 'EMPLOYEES';
    case POSITIONS = 'POSITIONS';
    case DOCUMENTS = 'DOCUMENTS';
    case PROJECTS = 'PROJECTS';

    /** @return PermissionCode[] */
    public function getPermissions(): array
    {
        return match ($this) {
            self::USERS => [
                PermissionCode::VIEW_USERS,
                PermissionCode::CREATE_USERS,
            ],

            self::ROLES => [
                PermissionCode::VIEW_ROLES,
            ],

            self::DOCUMENTS => [
                PermissionCode::VIEW_DOCUMENTS,
                PermissionCode::CREATE_DOCUMENTS,
            ],

            self::PROJECTS => [
                PermissionCode::VIEW_PROJECTS,
                PermissionCode::CREATE_PROJECTS,
                PermissionCode::MANAGE_PROJECT_CONTEXT,
                PermissionCode::MANAGE_PROJECT_PIPELINE,
            ],

            default => [],
        };
    }
}
