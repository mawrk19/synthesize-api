<?php

namespace App\Modules\Orchestration\Enums;

enum PipelineRunStatus: string
{
    case Planning = 'planning';
    case AwaitingApproval = 'awaiting_approval';
    case Executing = 'executing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Planning, self::Executing => true,
            default => false,
        };
    }
}
