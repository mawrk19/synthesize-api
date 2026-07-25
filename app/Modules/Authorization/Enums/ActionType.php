<?php

namespace App\Modules\Authorization\Enums;

enum ActionType: string
{
    case VIEW = 'VIEW';
    case CREATE = 'CREATE';
    case UPDATE = 'UPDATE';
    case DELETE = 'DELETE';
    case UNKNOWN = 'UNKNOWN';
}