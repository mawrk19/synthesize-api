<?php

namespace App\Modules\Diagrams\Enums;

enum DiagramType: string
{
    case Sequence = 'sequence';
    case Erd = 'erd';
    case Flowchart = 'flowchart';
    case State = 'state';
}
