<?php

namespace App\Modules\Projects\Enums;

enum IntakeStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Processing = 'processing';
    case Structured = 'structured';
    case Completed = 'completed';
    case Failed = 'failed';
}
