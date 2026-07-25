<?php

namespace App\Modules\Projects\Enums;

enum IntakeSourceType: string
{
    case BrainDump = 'brain_dump';
    case Transcript = 'transcript';
    case Audio = 'audio';
}
