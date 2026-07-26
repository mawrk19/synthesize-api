<?php

namespace App\Modules\Orchestration\Enums;

enum PipelineTaskStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Review = 'review';
    case Testing = 'testing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Blocked = 'blocked';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Blocked => true,
            default => false,
        };
    }
}
