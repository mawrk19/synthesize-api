<?php

namespace App\Modules\Orchestration\Enums;

enum PrStatus: string
{
    case Open = 'open';
    case Merged = 'merged';
    case Closed = 'closed';
}
