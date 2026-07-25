<?php

namespace App\Modules\Authorization\Enums;

use Exception;
use Str;

enum PermissionCode: string
{
    case VIEW_USERS = 'VIEW_USERS';
    case VIEW_ROLES = 'VIEW_ROLES';
    case VIEW_DOCUMENTS = 'VIEW_DOCUMENTS';
    case CREATE_DOCUMENTS = 'CREATE_DOCUMENTS';
    case VIEW_PROJECTS = 'VIEW_PROJECTS';
    case CREATE_PROJECTS = 'CREATE_PROJECTS';
    case MANAGE_PROJECT_CONTEXT = 'MANAGE_PROJECT_CONTEXT';

    public function getLabel(): string
    {
        return match ($this) {
            self::VIEW_USERS => 'View Users',
            self::VIEW_ROLES => 'View Roles',
            self::VIEW_DOCUMENTS => 'View Documents',
            self::CREATE_DOCUMENTS => 'Create Documents',
            self::VIEW_PROJECTS => 'View Projects',
            self::CREATE_PROJECTS => 'Create Projects',
            self::MANAGE_PROJECT_CONTEXT => 'Manage Project Context',
            default => Str::title(str_replace('_', ' ', $this->name)),
        };
    }

    public function getResourceType(): ResourceType
    {
        $resourceTypes = ResourceType::cases();
        foreach ($resourceTypes as $resourceType) {
            $permissions = $resourceType->getPermissions();
            foreach ($permissions as $permission) {
                if ($permission === $this) {
                    return $resourceType;
                }
            }
        }

        throw new Exception("Permission {$this->value} has no resource type");
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::VIEW_USERS => 'Can view all users',
            self::VIEW_ROLES => 'Can view all roles',
            self::VIEW_DOCUMENTS => 'Can view SRS documents',
            self::CREATE_DOCUMENTS => 'Can create SRS documents from notes',
            self::VIEW_PROJECTS => 'Can view Synthesize projects',
            self::CREATE_PROJECTS => 'Can create Synthesize projects',
            self::MANAGE_PROJECT_CONTEXT => 'Can upload context files and manage intake for projects',
            default => 'No description available',
        };
    }
}
