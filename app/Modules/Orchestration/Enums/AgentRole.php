<?php

namespace App\Modules\Orchestration\Enums;

enum AgentRole: string
{
    case Planner = 'planner';
    case Developer = 'developer';
    case Tester = 'tester';
    case Reviewer = 'reviewer';
}
